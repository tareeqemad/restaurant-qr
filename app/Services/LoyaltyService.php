<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\LoyaltyCustomer;
use App\Models\LoyaltyTransaction;
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
        $phone = $this->normalizePhone($phone);

        $customer = LoyaltyCustomer::where('phone', $phone)->first();
        if ($customer) {
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

    /**
     * Award points based on the invoice's paid total.
     * Called from BillingService::addPayment when invoice becomes fully paid
     * (or partially — we award on every successful payment).
     *
     * Returns the transaction (or null if nothing to award).
     */
    public function awardPoints(LoyaltyCustomer $customer, Invoice $invoice, float $paymentAmount, ?int $userId = null): ?LoyaltyTransaction
    {
        if ($paymentAmount <= 0) return null;

        return DB::transaction(function () use ($customer, $invoice, $paymentAmount, $userId) {
            $customer = $customer->fresh()->lockForUpdate();

            $earnRate = $customer->earnRate();
            $earned = (int) floor($paymentAmount * $earnRate);
            if ($earned <= 0) return null;

            $tx = LoyaltyTransaction::create([
                'loyalty_customer_id' => $customer->id,
                'invoice_id'          => $invoice->id,
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

    /** Simple phone normalization — strip spaces, hyphens, leading zeros */
    protected function normalizePhone(string $phone): string
    {
        $stripped = preg_replace('/[^\d+]/', '', $phone);
        // Keep "+" prefix; remove leading zero in local format
        if (str_starts_with($stripped, '+')) return $stripped;
        return ltrim($stripped, '0');
    }
}
