<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Helpers\Money;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\JournalEntry;
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

            $existingDiscount = (float) OrderDiscount::where('order_id', $order->id)->sum('amount');
            $payload = $this->validateAndPrepare($data, $user, (float) $order->subtotal, $existingDiscount);

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

            // Block discount changes once the session invoice is closed.
            // applyToSession never went through guardOrderMutable, so without
            // this the order rows get mutated (recalculateTotals) and only
            // syncInvoiceFromSession later no-ops on the closed invoice —
            // desyncing order totals from a paid invoice with no ledger repost.
            $sessionInvoice = $session->invoice()->where('status', '!=', 'cancelled')->latest()->first();
            if ($sessionInvoice && in_array($sessionInvoice->status, ['paid', 'unpaid_writeoff'], true)) {
                throw ValidationException::withMessages([
                    'value' => 'الفاتورة مغلقة. لتعديل الخصم اعمل استرداد ثم أعد الإصدار.',
                ]);
            }

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
            $existingDiscount = (float) OrderDiscount::whereIn('order_id', $billable->pluck('id'))->sum('amount');
            $payload = $this->validateAndPrepare($data, $user, $sessionSubtotal, $existingDiscount);

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

    protected function validateAndPrepare(array $data, User $user, float $subtotal, float $existingDiscount = 0): array
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

            // Cumulative ceiling: discounts already on this target plus the new
            // one may not exceed the MOST a single allowed discount could reach —
            // max(percent-ceiling, fixed-ceiling). Without this a capped cashier
            // could stack many within-cap discounts to comp the entire bill.
            $ceiling = max(
                Money::round($subtotal * (float) $cap['percent'] / 100),
                (float) $cap['fixed'],
            );
            if (($existingDiscount + $amount) - $ceiling > 0.01) {
                throw ValidationException::withMessages([
                    'value' => 'إجمالي الخصومات على هذا الطلب يتجاوز حد دورك ('.Money::format($ceiling).
                        '). أزل خصماً سابقاً أو اطلب موافقة المدير.',
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
        $invoice = $this->invoiceFor($order);
        if ($invoice && in_array($invoice->status, ['paid', 'cancelled', 'unpaid_writeoff'], true)) {
            throw ValidationException::withMessages([
                'value' => 'الفاتورة مغلقة. لتعديل الخصم اعمل استرداد ثم أعد الإصدار.',
            ]);
        }
    }

    /**
     * Resolve the invoice governing this order. Standalone/portal orders own
     * their invoice via order_id, so $order->invoice works. Dine-in SESSION
     * orders do NOT — the session invoice is keyed on table_session_id with
     * order_id left null, so $order->invoice is ALWAYS null for them. Without
     * this fallback the paid-invoice guard silently no-ops on every dine-in
     * order, letting a discount be applied/removed on a paid, closed invoice.
     */
    protected function invoiceFor(Order $order): ?Invoice
    {
        if ($order->invoice) {
            return $order->invoice;
        }
        if ($order->table_session_id) {
            return Invoice::where('table_session_id', $order->table_session_id)
                ->where('status', '!=', 'cancelled')
                ->latest()
                ->first();
        }
        return null;
    }

    /**
     * Re-sync the parent invoice (if any) for a single order. Used after
     * applyToOrder / remove. For session-scoped applies the session-level
     * sync below covers all orders in one shot.
     */
    protected function syncInvoiceFromOrder(Order $order): void
    {
        $invoice = $this->invoiceFor($order);
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
        // Net of refunds — matches BillingService::addPayment and
        // Invoice::netPaid(). Using GROSS payments here would let a discount
        // applied AFTER a partial refund close the invoice as 'paid' (balance
        // 0) while money is still owed, silently writing off the remainder.
        $paidTotal = (float) $invoice->payments()->sum('amount');
        $netPaid   = max(0, $paidTotal - (float) $invoice->refunded_total);
        $newTotal  = Money::round($totals['total']);
        $balance   = max(0, $newTotal - $netPaid);

        // If a (net) payment was made, recompute the running status so a
        // discount that brings balance to 0 closes the invoice properly.
        $status = match (true) {
            $balance <= 0.001 && $netPaid > 0 => 'paid',
            $netPaid > 0                      => 'partially_paid',
            default                           => 'issued',
        };

        // Split-invoice guard (G5): if the customer chose to split the
        // bill before the discount was applied, the split amounts no
        // longer reconcile with the new total. We REFUSE the change
        // when any split has already been paid (touching those is a
        // refund-territory operation), and PRORATE the unpaid ones so
        // the cashier can still close out the new total.
        $splits = $invoice->splits()->get();
        if ($splits->isNotEmpty()) {
            $paidSplitsTotal = (float) $splits->where('paid', true)->sum('amount');
            if ($paidSplitsTotal > 0 && abs($paidSplitsTotal - (float) $totals['total']) > 0.01
                && $paidSplitsTotal > (float) $totals['total']) {
                // Paid splits already exceed the new total — refusing
                // would force the cashier to undo the discount. Better
                // path: keep splits paid, alert the manager.
                \Log::warning('writeInvoiceTotals.split_overpaid', [
                    'invoice_id'  => $invoice->id,
                    'old_total'   => (float) $invoice->total,
                    'new_total'   => (float) $totals['total'],
                    'paid_splits' => $paidSplitsTotal,
                ]);
            } elseif ($splits->where('paid', false)->isNotEmpty()) {
                // Prorate the UNPAID splits so the sum reaches the new total.
                $remaining = max(0, (float) $totals['total'] - $paidSplitsTotal);
                $unpaid = $splits->where('paid', false)->values();
                $unpaidSum = (float) $unpaid->sum('amount');
                if ($unpaidSum > 0) {
                    $ratio = $remaining / $unpaidSum;
                    foreach ($unpaid as $i => $split) {
                        // Last split absorbs any rounding drift so the
                        // sum of shares matches the new total exactly.
                        $isLast = $i === ($unpaid->count() - 1);
                        $newAmount = $isLast
                            ? round($remaining - (float) $unpaid->take($i)->sum(fn ($s) => $s->fresh()->amount), 2)
                            : Money::round((float) $split->amount * $ratio);
                        $split->update(['amount' => $newAmount]);
                    }
                }
            }
        }

        // Remember the pre-update issuance state. If the invoice has
        // already been posted to the books, we MUST refire the
        // accounting entry — totals just shifted and the original
        // journal lines are now stale. Without this, a cashier-applied
        // 10% off would silently leave A/R inflated by the discount.
        //
        // We detect "already posted" by checking for any non-reversal
        // journal entry sourced from this invoice. We DON'T re-fire if
        // the invoice was never issued — applying a discount during
        // cart-building is normal and the first `recordInvoiceIssued`
        // call will pick up the right amounts naturally.
        $activePosting = $this->latestUnreversedInvoicePosting($invoice);

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

        // Repost accounting if the original entry exists. We reverse
        // (to neutralise stale amounts) then call `recordInvoiceIssued`
        // again with a fresh event_type so the idempotency guard in
        // AccountingService::post doesn't no-op us. The reverse leaves
        // an audit trail in `journal_entries` and the new entry shows
        // the corrected figures.
        if ($activePosting) {
            $accounting = app(\App\Services\Accounting\AccountingService::class);
            $accounting->reverseEntry(
                original: $activePosting,
                eventType: $activePosting->event_type === 'invoice_issued'
                    ? 'invoice_discount_repost_reversal'
                    : 'invoice_discount_repost_reversal_'.$activePosting->id,
                postedOn: now(),
                description: "عكس قيد فاتورة {$invoice->number} بسبب تحديث الخصم",
            );
            // Re-issue under a distinct event_type so it doesn't collide
            // with the original (now-reversed) entry's uniqueness key.
            $accounting->repostInvoiceWithDiscount($invoice);
        }
    }

    protected function latestUnreversedInvoicePosting(Invoice $invoice): ?JournalEntry
    {
        // Single source of truth lives in AccountingService so the discount
        // repost path and the invoice-cancel path can never disagree on which
        // entry is "live".
        return app(\App\Services\Accounting\AccountingService::class)
            ->latestUnreversedInvoicePosting($invoice);
    }
}
