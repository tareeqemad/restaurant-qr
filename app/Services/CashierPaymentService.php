<?php

namespace App\Services;

use App\Exceptions\CashierPaymentConflict;
use App\Helpers\Money;
use App\Models\Invoice;
use App\Models\Payment;
use Closure;
use Illuminate\Support\Facades\DB;

/** Payment and its durable acknowledgment share one transaction, including nested ledger writes. */
class CashierPaymentService
{
    public function execute(Invoice $invoice, int $userId, array $payload, Closure $command): array
    {
        $key = $this->key($invoice, $userId, $payload['token']);
        $hash = $this->payloadHash($payload);

        return DB::transaction(function () use ($invoice, $userId, $key, $hash, $command, $payload) {
            $claimed = DB::table('cashier_payment_receipts')->insertOrIgnore([
                'token_hash' => $key, 'branch_id' => $invoice->branch_id, 'user_id' => $userId,
                'invoice_id' => $invoice->id, 'payload_hash' => $hash,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! $claimed) {
                // The unique insert waits for the competing transaction to commit or roll back.
                $receipt = DB::table('cashier_payment_receipts')->where('token_hash', $key)->first();
                if (! hash_equals($receipt->payload_hash, $hash)) {
                    throw new CashierPaymentConflict('هذه العملية محفوظة بقيم مختلفة. تحقّق من نتيجتها قبل بدء دفعة جديدة.');
                }
                $result = json_decode($receipt->response, true, 512, JSON_THROW_ON_ERROR);
                $result['data']['replayed'] = true;
                $result['data']['payment_status'] = Payment::withoutGlobalScopes()->whereKey($result['data']['payment_id'])->value('status');

                return $result;
            }

            $result = $command();
            $result['data']['submission_state'] = 'committed';
            $result['data']['operation_token'] = $payload['token'];
            $result['data']['replayed'] = false;
            DB::table('cashier_payment_receipts')->where('token_hash', $key)->update([
                'response' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);

            return $result;
        }, 3);
    }

    public function verify(Invoice $invoice, int $userId, string $token, bool $lock = false): array
    {
        $receipt = DB::table('cashier_payment_receipts')->where('token_hash', $this->key($invoice, $userId, $token))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (! $receipt || ! $receipt->response) {
            // Absence may also mean the original request is still running; it is not permission
            // to create a new token. Retrying the SAME operation remains protected by the key.
            return ['submission_state' => 'unconfirmed'];
        }
        $result = json_decode($receipt->response, true, 512, JSON_THROW_ON_ERROR);
        $data = $result['data'];
        $data['payment_status'] = Payment::withoutGlobalScopes()->whereKey($data['payment_id'])->value('status');

        return $data;
    }

    private function key(Invoice $invoice, int $userId, string $token): string
    {
        return hash('sha256', implode(':', ['cashier-payment', $invoice->branch_id, $userId, $invoice->id, $token]));
    }

    private function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode([
            'split_id' => isset($payload['split_id']) ? (int) $payload['split_id'] : null,
            'amount' => Money::round((float) $payload['amount']),
            'method' => $payload['method'],
            'reference' => trim((string) ($payload['reference'] ?? '')),
            'notes' => trim((string) ($payload['notes'] ?? '')),
            'tendered_amount' => isset($payload['tendered_amount']) ? Money::round((float) $payload['tendered_amount']) : null,
            'save_change_as_advance' => (bool) ($payload['save_change_as_advance'] ?? false),
        ], JSON_THROW_ON_ERROR));
    }
}
