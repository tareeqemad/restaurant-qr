<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class CustomerSubmissionService
{
    public static function scope($session, ?string $deviceHash): string
    {
        return hash('sha256', implode('|', [$session->branch_id, $session->id, $deviceHash ?? '']));
    }

    public function run(string $scope, string $token, array $payload, callable $command): array
    {
        $key = hash('sha256', $scope.'|'.$token);
        $payloadHash = self::fingerprint($payload);

        return DB::transaction(function () use ($key, $payloadHash, $command) {
            // The unique claim waits for another transaction. A crash or rejection
            // rolls it back together with the order; a claim alone is never a receipt.
            $claimed = DB::table('customer_submission_receipts')->insertOrIgnore([
                'token_hash' => $key, 'payload_hash' => $payloadHash, 'created_at' => now(),
            ]);
            if (! $claimed) {
                $receipt = DB::table('customer_submission_receipts')->where('token_hash', $key)->lockForUpdate()->first();
                if (! $receipt?->response_json || ! hash_equals($receipt->payload_hash, $payloadHash)) {
                    throw new \DomainException('هذا الإرسال مرتبط بنسخة أخرى. تحقّق من طلبات الطاولة قبل المتابعة.');
                }

                return [...json_decode($receipt->response_json, true, 512, JSON_THROW_ON_ERROR), 'replayed' => true];
            }
            $result = $command();
            if (empty($result['order_id'])) {
                throw new \LogicException('A customer receipt requires a saved order.');
            }
            DB::table('customer_submission_receipts')->where('token_hash', $key)->update([
                'response_json' => json_encode($result, JSON_THROW_ON_ERROR),
            ]);

            return [...$result, 'replayed' => false];
        }, 3);
    }

    public static function fingerprint(array $payload): string
    {
        $sort = function (array &$values) use (&$sort): void {
            ksort($values);
            foreach ($values as &$value) {
                if (is_array($value)) {
                    $sort($value);
                }
            }
        };
        $sort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** Prices remain server-owned; this identifies the requested configuration. */
    public static function cartSnapshot(array $cart): array
    {
        return collect($cart)->map(function ($row) {
            $ids = fn ($values) => collect($values)->map(fn ($id) => (int) $id)->sort()->values()->all();

            return [
                'id' => (string) ($row['id'] ?? ''),
                'menu_item_id' => (int) ($row['menu_item_id'] ?? 0),
                'quantity' => (float) ($row['quantity'] ?? 0),
                'modifier_ids' => $ids($row['modifier_ids'] ?? []),
                'excluded_ingredient_ids' => $ids($row['excluded_ingredient_ids'] ?? []),
                'notes' => trim((string) ($row['notes'] ?? '')),
            ];
        })->sortBy('id')->values()->all();
    }
}
