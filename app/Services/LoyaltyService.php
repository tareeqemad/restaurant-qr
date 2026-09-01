<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoyaltyCustomer;
use App\Models\LoyaltyTransaction;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Loyalty program: earn points on paid invoices, redeem for discounts.
 *
 * Tier logic (based on lifetime_points, never demoted):
 *   Bronze : < 1000
 *   Silver : 1000 - 4999  → 1.5× earn rate
 *   Gold   : 5000+        → 2× earn rate
 *
 * Point values:
 *   Earn  : 10 pts per 1 JOD (Bronze) / 15 (Silver) / 20 (Gold)
 *   Redeem: 100 pts = 1 JOD discount
 */
class LoyaltyService
{
    public const REDEMPTION_RATE   = 100;  // pts per 1 currency unit discount
    public const BRONZE_CEILING    = 1000;
    public const SILVER_CEILING    = 5000;

    /** Find a customer by phone, or create a new (Bronze) record. */
    public function findOrCreate(string $phone, ?string $name = null, ?string $email = null): LoyaltyCustomer
    {
        $phone = PhoneNumber::normalize($phone);

        $matches = LoyaltyCustomer::withTrashed()
            ->whereIn('phone', PhoneNumber::lookupVariants($phone))
            ->get();
        $customer = $matches->firstWhere('phone', $phone) ?? $matches->first();
        if ($customer) {
            if ($customer->trashed()) $customer->restore();
            if ($customer->phone !== $phone && ! LoyaltyCustomer::where('phone', $phone)->exists()) {
                $customer->update(['phone' => $phone]);
            }
            if ($name && !$customer->name)   $customer->update(['name' => $name]);
            if ($email && !$customer->email) $customer->update(['email' => $email]);
            return $customer;
        }

        return LoyaltyCustomer::create([
            'phone'           => $phone,
            'name'            => $name,
            'email'           => $email,
            'tier'            => 'bronze',
            'points_balance'  => 0,
            'lifetime_points' => 0,
        ]);
    }

    public function ensureForCustomer(Customer $customer): LoyaltyCustomer
    {
        $profile = $customer->loyaltyCustomer;
        if (! $profile) {
            $profile = $this->findOrCreate($customer->phone, $customer->name, $customer->email);
            $customer->update(['loyalty_customer_id' => $profile->id]);
        }

        return $profile;
    }

    /**
     * Award points once when the invoice becomes fully paid. Subsequent
     * refunds, payment voids, and re-payments reconcile that same invoice
     * ledger entry instead of earning duplicate points.
     *
     * Returns the transaction (or null if nothing to award).
     */
    public function awardPoints(LoyaltyCustomer $customer, Invoice $invoice, float $paymentAmount, ?int $userId = null): ?LoyaltyTransaction
    {
        if ($paymentAmount <= 0) return null;

        return DB::transaction(function () use ($customer, $invoice, $paymentAmount, $userId) {
            $existing = LoyaltyTransaction::query()
                ->where('loyalty_customer_id', $customer->id)
                ->where('invoice_id', $invoice->id)
                ->where('type', 'earn')
                ->first();
            if ($existing) return $this->syncInvoicePoints($invoice, $userId);

            $customer = LoyaltyCustomer::query()
                ->whereKey($customer->id)
                ->lockForUpdate()
                ->firstOrFail();

            $earnRate = $customer->earnRate();
            $earned = (int) floor($paymentAmount * $earnRate);
            if ($earned <= 0) return null;

            $tx = LoyaltyTransaction::create([
                'loyalty_customer_id' => $customer->id,
                'invoice_id'          => $invoice->id,
                'order_id'            => $invoice->order_id,
                'type'                => 'earn',
                'points'              => $earned,
                'cash_value'          => $paymentAmount,
                'reason'              => "شراء بقيمة " . number_format($paymentAmount, 2),
                'user_id'             => $userId,
            ]);

            $customer->increment('points_balance', $earned);
            $customer->increment('lifetime_points', $earned);
            $customer->increment('total_spent', $paymentAmount);
            $customer->increment('total_visits');
            $customer->update([
                'last_visit_at' => now(),
                'tier'          => $this->computeTier($customer->fresh()->lifetime_points),
            ]);

            Invoice::whereKey($invoice->id)->update([
                'loyalty_customer_id'   => $customer->id,
                'loyalty_points_earned' => DB::raw('COALESCE(loyalty_points_earned, 0) + '.$earned),
            ]);

            ActivityLog::log(
                'loyalty.earned',
                "ربح {$earned} نقطة للعميل {$customer->phone}",
                $tx
            );

            return $tx;
        });
    }

    public function awardPaidInvoice(Invoice $invoice, ?int $userId = null): ?LoyaltyTransaction
    {
        $invoice->loadMissing('customer.loyaltyCustomer');
        if (! $invoice->customer || $invoice->status !== 'paid') {
            return null;
        }

        $profile = $this->ensureForCustomer($invoice->customer);

        return $this->awardPoints($profile, $invoice, min($invoice->netPaid(), $invoice->adjustedTotal()), $userId);
    }

    public function reverseForRefund(Invoice $invoice, float $refundAmount, ?int $userId = null): ?LoyaltyTransaction
    {
        return $refundAmount > 0 ? $this->syncInvoicePoints($invoice, $userId) : null;
    }

    public function reverseForPaymentVoid(Invoice $invoice, float $voidAmount, ?int $userId = null): ?LoyaltyTransaction
    {
        return $voidAmount > 0 ? $this->syncInvoicePoints($invoice, $userId) : null;
    }

    protected function syncInvoicePoints(Invoice $invoice, ?int $userId): ?LoyaltyTransaction
    {
        return DB::transaction(function () use ($invoice, $userId) {
            $invoice = Invoice::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            $earn = LoyaltyTransaction::query()
                ->where('invoice_id', $invoice->id)
                ->where('type', 'earn')
                ->lockForUpdate()
                ->first();
            if (! $earn) {
                return null;
            }

            $profile = LoyaltyCustomer::query()->whereKey($earn->loyalty_customer_id)->lockForUpdate()->firstOrFail();
            $adjustments = LoyaltyTransaction::query()
                ->where('invoice_id', $invoice->id)
                ->where('type', 'adjust')
                ->where(function ($query) {
                    $query->where('reason', 'like', 'مزامنة نقاط الفاتورة%')
                        ->orWhere('reason', 'like', 'عكس نقاط استرداد%')
                        ->orWhere('reason', 'like', 'عكس نقاط دفعة ملغاة%');
                })
                ->get();

            $currentPoints = (int) $earn->points + (int) $adjustments->sum('points');
            $earnedCash = max(0.01, (float) $earn->cash_value);
            $desiredCash = min($earnedCash, $invoice->netPaid(), $invoice->adjustedTotal());
            $desiredPoints = min((int) $earn->points, (int) floor(($desiredCash / $earnedCash) * (int) $earn->points));
            $pointsDelta = $desiredPoints - $currentPoints;

            $currentCash = $earnedCash + (float) $adjustments->sum(
                fn (LoyaltyTransaction $tx) => $tx->points < 0 ? -(float) $tx->cash_value : (float) $tx->cash_value,
            );
            $cashDelta = round($desiredCash - $currentCash, 4);
            if ($pointsDelta === 0 && abs($cashDelta) < 0.0001) {
                return null;
            }

            if ($pointsDelta < 0) {
                $pointsDelta = -min(abs($pointsDelta), (int) $profile->points_balance);
            }
            $tx = LoyaltyTransaction::create([
                'loyalty_customer_id' => $profile->id,
                'invoice_id' => $invoice->id,
                'order_id' => $invoice->order_id,
                'type' => 'adjust',
                'points' => $pointsDelta,
                'cash_value' => abs($cashDelta),
                'reason' => 'مزامنة نقاط الفاتورة '.$invoice->number.' مع صافي التحصيل',
                'user_id' => $userId,
            ]);

            if ($pointsDelta > 0) {
                $profile->increment('points_balance', $pointsDelta);
            } elseif ($pointsDelta < 0) {
                $profile->decrement('points_balance', abs($pointsDelta));
            }
            if ($cashDelta > 0) {
                $profile->increment('total_spent', $cashDelta);
            } elseif ($cashDelta < 0) {
                $profile->decrement('total_spent', min((float) $profile->total_spent, abs($cashDelta)));
            }

            $invoice->update(['loyalty_points_earned' => max(0, $desiredPoints)]);

            return $tx;
        });
    }

    /**
     * Redeem points for a discount. Returns the transaction and cash discount value.
     * Use this BEFORE the cashier processes payment so the invoice total is reduced.
     */
    public function redeemPoints(LoyaltyCustomer $customer, int $points, Invoice $invoice, ?int $userId = null): LoyaltyTransaction
    {
        if ($points <= 0) {
            throw ValidationException::withMessages(['points' => 'عدد النقاط يجب أن يكون أكبر من صفر.']);
        }
        if ($points > $customer->points_balance) {
            throw ValidationException::withMessages([
                'points' => "الرصيد الحالي {$customer->points_balance} نقطة — لا يمكن استبدال {$points}.",
            ]);
        }

        // GUARD (defensive — this service is currently dormant/unwired):
        // redeemPoints mutates the invoice totals directly with NO ledger
        // reversal/repost. Applying it to an already-journaled invoice would
        // silently desync A/R (1100) and revenue (4000) from the invoice table.
        // Block it — redemption must happen before issuance, or be routed through
        // OrderDiscountService (which reverses + reposts the journal entry).
        if ($invoice->journalEntries()->exists()) {
            throw ValidationException::withMessages([
                'invoice' => 'لا يمكن استبدال النقاط على فاتورة مُرحَّلة محاسبياً. نفّذ الاستبدال قبل إصدار الفاتورة أو عبر مسار الخصم الذي يعكس القيد ويعيده.',
            ]);
        }

        return DB::transaction(function () use ($customer, $points, $invoice, $userId) {
            $customer = $customer->fresh()->lockForUpdate();
            $invoice  = $invoice->fresh()->lockForUpdate();

            $cashValue = $points / self::REDEMPTION_RATE;

            $tx = LoyaltyTransaction::create([
                'loyalty_customer_id' => $customer->id,
                'invoice_id'          => $invoice->id,
                'type'                => 'redeem',
                'points'              => -$points,   // negative
                'cash_value'          => $cashValue,
                'reason'              => "استبدال {$points} نقطة = " . number_format($cashValue, 2),
                'user_id'             => $userId,
            ]);

            $customer->decrement('points_balance', $points);

            // Apply discount to invoice
            $invoice->update([
                'loyalty_customer_id'    => $customer->id,
                'loyalty_points_redeemed'=> (int) $invoice->loyalty_points_redeemed + $points,
                'loyalty_discount'       => (float) $invoice->loyalty_discount + $cashValue,
                'discount_total'         => (float) $invoice->discount_total + $cashValue,
                'total'                  => max(0, (float) $invoice->total - $cashValue),
                'balance'                => max(0, (float) $invoice->balance - $cashValue),
            ]);

            ActivityLog::log(
                'loyalty.redeemed',
                "استبدال {$points} نقطة للعميل {$customer->phone}: خصم " . number_format($cashValue, 2),
                $tx
            );

            return $tx;
        });
    }

    /**
     * Manual adjustment (staff correction or goodwill bonus).
     * Positive points = gift, negative = correction.
     */
    public function adjust(LoyaltyCustomer $customer, int $points, string $reason, string $type = 'adjust', ?int $userId = null): LoyaltyTransaction
    {
        if ($points === 0) {
            throw ValidationException::withMessages(['points' => 'عدد النقاط لا يمكن أن يكون صفراً.']);
        }
        if ($points < 0 && abs($points) > $customer->points_balance) {
            throw ValidationException::withMessages([
                'points' => "لا يمكن خصم {$points} — الرصيد الحالي {$customer->points_balance}.",
            ]);
        }

        return DB::transaction(function () use ($customer, $points, $reason, $type, $userId) {
            $tx = LoyaltyTransaction::create([
                'loyalty_customer_id' => $customer->id,
                'type'                => $type,
                'points'              => $points,
                'reason'              => $reason,
                'user_id'             => $userId,
            ]);

            $customer->increment('points_balance', $points);
            if ($points > 0) {
                $customer->increment('lifetime_points', $points);
                $customer->update(['tier' => $this->computeTier($customer->fresh()->lifetime_points)]);
            }

            ActivityLog::log('loyalty.'.$type, "{$type} {$points} نقطة: {$reason}", $tx);

            return $tx;
        });
    }

    public function computeTier(int $lifetimePoints): string
    {
        if ($lifetimePoints >= self::SILVER_CEILING) return 'gold';
        if ($lifetimePoints >= self::BRONZE_CEILING) return 'silver';
        return 'bronze';
    }

}
