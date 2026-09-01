<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PendingTransfer;
use App\Models\TableSession;
use App\Support\BranchContext;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerIdentityService
{
    public function __construct(protected LoyaltyService $loyalty) {}

    /**
     * @return array{customer:Customer,created:bool}
     */
    public function resolveOrCreate(
        string $phone,
        ?string $name,
        ?int $defaultBranchId,
        string $source,
    ): array {
        $normalizedPhone = PhoneNumber::normalize($phone);
        if (! PhoneNumber::isValid($normalizedPhone)) {
            throw ValidationException::withMessages([
                'customer_phone' => 'أدخل رقم جوال صحيحاً من 7 إلى 15 رقماً.',
            ]);
        }

        return DB::transaction(function () use ($normalizedPhone, $name, $defaultBranchId, $source) {
            $customer = Customer::findByPhone($normalizedPhone, withTrashed: true);
            $created = false;

            if ($customer?->trashed()) {
                $customer->restore();
            }

            if ($customer?->isBlocked()) {
                throw ValidationException::withMessages([
                    'customer_phone' => 'هذا الرقم يحتاج مراجعة موظف المطعم قبل ربط الطلب به.',
                ]);
            }

            if (! $customer) {
                $name = trim((string) $name);
                if ($name === '') {
                    // QR ordering asks only for an optional phone. The database
                    // still needs a readable internal label, so create a neutral
                    // one that staff can replace later from the customer file.
                    $name = 'زبون '.substr($normalizedPhone, -4);
                }

                [$customer] = Customer::createFromCashier(
                    name: $name,
                    phone: $normalizedPhone,
                    defaultBranchId: $defaultBranchId,
                );
                $created = true;
            } else {
                $this->loyalty->ensureForCustomer($customer);

                if (! $customer->default_branch_id && $defaultBranchId) {
                    $customer->update(['default_branch_id' => $defaultBranchId]);
                }
            }

            ActivityLog::log(
                $created ? 'customer.created' : 'customer.matched',
                ($created ? 'تسجيل' : 'ربط').' الزبون '.$customer->name.' من '.$source,
                $customer,
                ['source' => $source, 'phone' => $customer->phone],
            );

            return ['customer' => $customer->fresh('loyaltyCustomer'), 'created' => $created];
        });
    }

    /**
     * A customer may identify after the first round was already sent by a
     * waiter. Backfilling the visit keeps every order and its eventual invoice
     * in one history instead of linking only the next round.
     */
    public function linkSession(
        TableSession $session,
        Customer $customer,
    ): TableSession {
        return BranchContext::forBranch($session->branch_id, function () use ($session, $customer) {
            return DB::transaction(function () use ($session, $customer) {
                $session = TableSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                $session->update([
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'customer_phone' => $customer->phone,
                ]);

                Order::query()
                    ->where('table_session_id', $session->id)
                    ->whereNull('customer_id')
                    ->update([
                        'customer_id' => $customer->id,
                        'customer_name' => $customer->name,
                        'customer_phone' => $customer->phone,
                    ]);

                Invoice::query()
                    ->where('table_session_id', $session->id)
                    ->whereNull('customer_id')
                    ->update([
                        'customer_id' => $customer->id,
                        'customer_name' => $customer->name,
                        'customer_phone' => $customer->phone,
                    ]);

                PendingTransfer::query()
                    ->where('table_session_id', $session->id)
                    ->whereNull('customer_id')
                    ->update(['customer_id' => $customer->id]);

                return $session->fresh('customer');
            });
        });
    }
}
