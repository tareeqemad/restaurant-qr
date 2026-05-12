<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Helpers\Money;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Lookup;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cashier-driven, ad-hoc discounts on a single Order or a whole TableSession
 * (which fans out one OrderDiscount per non-cancelled order, prorated for
 * fixed amounts so the receipt math always reconciles).
 *
 * The catalog of reusable codes (`discounts` table) is intentionally NOT
 * touched here — those flow through a different path (portal coupon entry,
 * promo seeders). This service exists to support face-to-face, "give the
 * regular customer 10% off" decisions made at the till, with a real audit
 * trail (who, why, how much).
 *
 * Recompute strategy: applying or removing a discount cascades through
 * `OrderService::recalculateTotals()` for every affected order, then re-syncs
 * the parent invoice's snapshot if one is already issued (still mutable
 * unless paid/cancelled). Tax and service charge run AFTER the discount, so
 * customers don't pay tax on money they didn't spend.
 */
class OrderDiscountService
{
    public function __construct(protected OrderService $orders) {}

    /**
     * Apply a discount to a single order. Used for portal/standalone orders
     * (one order = one invoice) and from the order-detail surface.
     */
    public function applyToOrder(Order $order, array $data, User $user): OrderDiscount
    {
        return DB::transaction(function () use ($order, $data, $user) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->guardOrderMutable($order);

            $payload = $this->validateAndPrepare($data, $user, (float) $order->subtotal);

            $discount = OrderDiscount::create([
                // Branch from the order — discount belongs to wherever the
                // order was placed, regardless of where the cashier is now.
                'branch_id'          => $order->branch_id,
                'order_id'           => $order->id,
                'discount_id'        => null,
                'name_snapshot'      => $payload['name'],
                'type'               => $payload['type'],
                'value'              => $payload['value'],
                'amount'             => $payload['amount'],
                'category_lookup_id' => $payload['category_lookup_id'],
                'reason'             => $payload['reason'],
                'applied_by_user_id' => $user->id,
            ]);

            $this->orders->recalculateTotals($order);
            $this->syncInvoiceFromOrder($order->refresh());

            ActivityLog::log(
                'order.discount_applied',
                "خصم {$payload['name']} على الطلب {$order->number} بقيمة ".
                Money::format($payload['amount']),
                $discount,
                [
                    'order_id'           => $order->id,
                    'type'               => $payload['type'],
                    'value'              => $payload['value'],
                    'amount'             => $payload['amount'],
                    'category_lookup_id' => $payload['category_lookup_id'],
                    'reason'             => $payload['reason'],
                ]
            );

            return $discount->refresh();
        });
    }

    /**
     * Apply a discount to every non-cancelled order inside a session.
     * - Percent discounts apply the same rate to each order — math is clean
     *   regardless of split.
     * - Fixed-amount discounts are prorated by subtotal so the receipt total
     *   matches what the cashier promised the diner. Last order absorbs the
     *   rounding remainder (≤ 0.01) to guarantee the sum reconciles exactly.
     */
    public function applyToSession(TableSession $session, array $data, User $user): array
    {
        return DB::transaction(function () use ($session, $data, $user) {
            $session->loadMissing('orders');

            $billable = $session->orders
                ->where('status', '!=', OrderStatus::Cancelled->value)
                ->filter(fn (Order $o) => (float) $o->subtotal > 0)
                ->values();

            if ($billable->isEmpty()) {
                throw ValidationException::withMessages([
                    'value' => 'لا توجد طلبات قابلة للخصم في هذه الجلسة.',
                ]);
            }

            $sessionSubtotal = (float) $billable->sum('subtotal');
            $payload = $this->validateAndPrepare($data, $user, $sessionSubtotal);

            $created = [];

            if ($payload['type'] === 'percent') {
                foreach ($billable as $order) {
                    $amount = Money::round((float) $order->subtotal * (float) $payload['value'] / 100);
                    $created[] = $this->writeAndRecalc($order, $payload, $amount, $user);
                }
            } else {
                // Fixed: prorate by subtotal. Walk all-but-last with rounded
                // shares; final order takes whatever's left so the sum is
                // exact to the cent.
                $remaining = (float) $payload['amount'];
                $count = $billable->count();
                foreach ($billable as $i => $order) {
                    if ($i === $count - 1) {
                        $share = Money::round($remaining);
                    } else {
                        $share = Money::round((float) $payload['amount'] * ((float) $order->subtotal / $sessionSubtotal));
                        $remaining -= $share;
                    }
                    $created[] = $this->writeAndRecalc($order, $payload, $share, $user);
                }
            }

            $invoice = $session->invoice()->where('status', '!=', 'cancelled')->latest()->first();
            if ($invoice) {
                $this->syncInvoiceFromSession($invoice->refresh(), $session);
            }

            ActivityLog::log(
                'session.discount_applied',
                "خصم {$payload['name']} على جلسة الطاولة {$session->table?->number} بقيمة ".
                Money::format($payload['amount']),
                $session,
                [
                    'session_id'         => $session->id,
                    'type'               => $payload['type'],
                    'value'              => $payload['value'],
                    'amount'             => $payload['amount'],
                    'category_lookup_id' => $payload['category_lookup_id'],
                    'reason'             => $payload['reason'],
                    'orders'             => $billable->pluck('number')->all(),
                ]
            );

            return $created;
        });
    }

    /**
     * Pull a discount back. Allowed only while the parent invoice (if any)
     * is still mutable — once it's paid or cancelled, the right move is a
     * refund, not a quiet rewrite of history.
     */
    public function remove(OrderDiscount $discount, User $user): void
    {
        DB::transaction(function () use ($discount, $user) {
            $order = $discount->order()->lockForUpdate()->firstOrFail();
            $this->guardOrderMutable($order);

            $snapshot = [
                'name'   => $discount->name_snapshot,
                'amount' => (float) $discount->amount,
                'order'  => $order->number,
            ];

            $discount->delete();

            $this->orders->recalculateTotals($order);
            $this->syncInvoiceFromOrder($order->refresh());

            ActivityLog::log(
                'order.discount_removed',
                "إزالة خصم {$snapshot['name']} عن الطلب {$snapshot['order']} (".
                Money::format($snapshot['amount']).')',
                $order,
                ['order_id' => $order->id, 'removed_by' => $user->id] + $snapshot
            );
        });
    }

    /**
     * Effective caps for this user. Returning null means "no cap" — both
     * owner-level roles and any role not in the config are uncapped.
     * (Roles missing from config land here; tighten the config rather than
     * relying on this fallback.)
     */
    public function userCap(User $user): ?array
    {
        if ($user->isOwnerLevel()) {
            return null;
        }
        $configCaps = config('restaurant.discounts.caps', []);
        $perRoleConfig = $configCaps[$user->role] ?? null;
        if (! $perRoleConfig) {
            return null;
        }
        // Settings override config so admins can tune the caps from
        // /admin/settings without a redeploy. Falls back to the config
        // default if a key was never written (or was deleted).
        return [
            'percent' => (float) \App\Models\Setting::get(
                'discount_cap_'.$user->role.'_pct',
                $perRoleConfig['percent'] ?? 0
            ),
            'fixed'   => (float) \App\Models\Setting::get(
                'discount_cap_'.$user->role.'_fixed',
                $perRoleConfig['fixed'] ?? 0
            ),
        ];
    }

    // ─── internals ───────────────────────────────────────────────────────

    protected function writeAndRecalc(Order $order, array $payload, float $amount, User $user): OrderDiscount
    {
        $discount = OrderDiscount::create([
            // Branch from the order (see top create() for rationale).
            'branch_id'          => $order->branch_id,
            'order_id'           => $order->id,
            'discount_id'        => null,
            'name_snapshot'      => $payload['name'],
            'type'               => $payload['type'],
            'value'              => $payload['value'],
            'amount'             => $amount,
            'category_lookup_id' => $payload['category_lookup_id'],
            'reason'             => $payload['reason'],
            'applied_by_user_id' => $user->id,
        ]);
        $this->orders->recalculateTotals($order);
        return $discount->refresh();
    }

    protected function validateAndPrepare(array $data, User $user, float $subtotal): array
    {
        $type = $data['type'] ?? null;
        if (! in_array($type, ['percent', 'fixed'], true)) {
            throw ValidationException::withMessages(['type' => 'نوع الخصم غير صحيح.']);
        }

        $value = (float) ($data['value'] ?? 0);
        if ($value <= 0) {
            throw ValidationException::withMessages(['value' => 'قيمة الخصم يجب أن تكون أكبر من صفر.']);
        }

        if ($subtotal <= 0) {
            throw ValidationException::withMessages(['value' => 'لا يمكن تطبيق خصم على فاتورة بقيمة صفر.']);
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'سبب الخصم إلزامي.']);
        }
        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'سبب الخصم طويل جداً.']);
        }

        // Resolve `category_lookup_id` against the `discount_categories`
        // lookup group. Anything stale (deleted/deactivated) falls back to
        // the row coded `other`, or null if even that doesn't exist —
        // keeping the discount reportable instead of refusing to save.
        $categoryLookupId = (int) ($data['category_lookup_id'] ?? 0) ?: null;
        $valid = Lookup::for('discount_categories');
        if ($categoryLookupId !== null && ! $valid->firstWhere('id', $categoryLookupId)) {
            $categoryLookupId = null;
        }
        if ($categoryLookupId === null) {
            $fallback = $valid->firstWhere('code', 'other');
            $categoryLookupId = $fallback?->id;
        }

        if ($type === 'percent' && $value > 100) {
            throw ValidationException::withMessages(['value' => 'النسبة لا يمكن أن تتجاوز 100%.']);
        }

        $amount = $type === 'percent'
            ? Money::round($subtotal * $value / 100)
            : Money::round(min($value, $subtotal));

        if ($amount <= 0) {
            throw ValidationException::withMessages(['value' => 'قيمة الخصم المحسوبة صفر.']);
        }

        $cap = $this->userCap($user);
        if ($cap !== null) {
            if ($type === 'percent' && $value > $cap['percent']) {
                throw ValidationException::withMessages([
                    'value' => "الحد الأقصى للخصم لدورك هو {$cap['percent']}%. للمبالغ الأكبر اطلب موافقة المدير.",
                ]);
            }
            if ($type === 'fixed' && $value > $cap['fixed']) {
                throw ValidationException::withMessages([
                    'value' => 'الحد الأقصى للخصم لدورك هو '.Money::format($cap['fixed']).
                        '. للمبالغ الأكبر اطلب موافقة المدير.',
                ]);
            }
        }

        $name = trim((string) ($data['name'] ?? '')) !== ''
            ? trim($data['name'])
            : ($type === 'percent' ? "خصم {$value}%" : 'خصم مبلغ');

        return [
            'type'               => $type,
            'value'              => $value,
            'amount'             => $amount,
            'name'               => $name,
            'category_lookup_id' => $categoryLookupId,
            'reason'             => $reason,
        ];
    }

    /**
     * An order is mutable as long as it isn't cancelled and (if it has an
     * invoice) the invoice isn't already closed. Once money has changed
     * hands, discount changes have to go through the refund path.
     */
    protected function guardOrderMutable(Order $order): void
    {
        if ($order->status === OrderStatus::Cancelled->value) {
            throw ValidationException::withMessages(['value' => 'الطلب ملغي — لا يقبل خصومات.']);
        }
        $invoice = $order->invoice;
        if ($invoice && in_array($invoice->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)) {
            throw ValidationException::withMessages([
                'value' => 'الفاتورة مغلقة. لتعديل الخصم اعمل استرداد ثم أعد الإصدار.',
            ]);
        }
    }

    /**
     * Re-sync the parent invoice (if any) for a single order. Used after
     * applyToOrder / remove. For session-scoped applies the session-level
     * sync below covers all orders in one shot.
     */
    protected function syncInvoiceFromOrder(Order $order): void
    {
        $invoice = $order->invoice;
        if (! $invoice) return;
        if (in_array($invoice->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)) return;

        if ($invoice->table_session_id) {
            $session = $invoice->tableSession()->with('orders')->first();
            if ($session) {
                $this->syncInvoiceFromSession($invoice, $session);
            }
            return;
        }

        // Standalone (portal) invoice — single-order. Pull straight from it.
        $this->writeInvoiceTotals($invoice, [
            'subtotal'       => (float) $order->subtotal,
            'discount_total' => (float) $order->discount_total,
            'tax_total'      => (float) $order->tax_total,
            'service_total'  => (float) $order->service_total,
            'delivery_fee'   => (float) $order->delivery_fee,
            'tip'            => (float) $order->tip,
            'total'          => (float) $order->total,
        ]);
    }

    protected function syncInvoiceFromSession(Invoice $invoice, TableSession $session): void
    {
        if (in_array($invoice->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)) return;

        $orders = $session->orders()
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->get();

        $this->writeInvoiceTotals($invoice, [
            'subtotal'       => (float) $orders->sum('subtotal'),
            'discount_total' => (float) $orders->sum('discount_total'),
            'tax_total'      => (float) $orders->sum('tax_total'),
            'service_total'  => (float) $orders->sum('service_total'),
            'delivery_fee'   => (float) $orders->sum('delivery_fee'),
            'tip'            => (float) $orders->sum('tip'),
            'total'          => (float) $orders->sum('total'),
        ]);
    }

    protected function writeInvoiceTotals(Invoice $invoice, array $totals): void
    {
        $paidTotal = (float) $invoice->payments()->sum('amount');
        $newTotal  = Money::round($totals['total']);
        $balance   = max(0, $newTotal - $paidTotal);

        // If a partial payment was made, recompute the running status so a
        // discount that brings balance to 0 closes the invoice properly.
        $status = match (true) {
            $balance <= 0.001 && $paidTotal > 0 => 'paid',
            $paidTotal > 0                      => 'partially_paid',
            default                             => 'issued',
        };

        $invoice->update([
            'subtotal'       => Money::round($totals['subtotal']),
            'discount_total' => Money::round($totals['discount_total']),
            'tax_total'      => Money::round($totals['tax_total']),
            'service_total'  => Money::round($totals['service_total']),
            'delivery_fee'   => Money::round($totals['delivery_fee']),
            'tip'            => Money::round($totals['tip']),
            'total'          => $newTotal,
            'balance'        => Money::round($balance),
            'status'         => $status,
            'paid_at'        => $status === 'paid' ? ($invoice->paid_at ?? now()) : null,
        ]);
    }
}
