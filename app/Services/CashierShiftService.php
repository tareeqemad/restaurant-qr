<?php

namespace App\Services;

use App\Exceptions\CashierPaymentConflict;
use App\Helpers\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Cash custody, separate from attendance and the general ledger. */
class CashierShiftService
{
    public function open(int $userId, int $branchId, float $openingCash): array
    {
        return DB::transaction(function () use ($userId, $branchId, $openingCash) {
            // The user row serializes concurrent open attempts, including an empty shift table.
            DB::table('users')->where('id', $userId)->lockForUpdate()->firstOrFail();
            if (DB::table('cashier_shifts')->where('active_user_id', $userId)->exists()) {
                throw ValidationException::withMessages(['shift' => 'لديك وردية مفتوحة. أغلقها قبل افتتاح عهدة جديدة.']);
            }
            if ($openingCash < 0) {
                throw ValidationException::withMessages(['opening_cash' => 'رصيد الافتتاح لا يمكن أن يكون سالباً.']);
            }
            $id = DB::table('cashier_shifts')->insertGetId([
                'user_id' => $userId, 'active_user_id' => $userId, 'branch_id' => $branchId,
                'opening_cash' => Money::round($openingCash), 'opened_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->changedAfterCommit($userId);

            return $this->describe(DB::table('cashier_shifts')->find($id));
        });
    }

    /** Must be called inside the financial writer's transaction. Close takes the same row lock. */
    public function forPayment(int $userId, int $branchId, bool $cashRequired): ?int
    {
        $shift = DB::table('cashier_shifts')->where('active_user_id', $userId)->lockForUpdate()->first();
        // Authorized cross-branch debt collection still belongs to the employee's
        // actual drawer. The original document/GL retain their own branch.
        if (! $shift) {
            if ($cashRequired) {
                throw ValidationException::withMessages(['shift' => 'افتح ورديتك النقدية قبل استلام أو صرف النقد.']);
            }

            return null;
        }

        return (int) $shift->id;
    }

    public function record(int $userId, int $branchId, float $amount, string $kind, string $eventKey, ?string $reference = null): void
    {
        if (abs($amount) < 0.005) {
            return;
        }
        $shiftId = $this->forPayment($userId, $branchId, true);
        DB::table('cashier_shift_movements')->insert([
            'cashier_shift_id' => $shiftId, 'source_branch_id' => $branchId, 'event_key' => $eventKey, 'kind' => $kind,
            'amount' => Money::round($amount), 'reference' => $reference, 'occurred_at' => now(),
        ]);
        $this->changedAfterCommit($userId);
    }

    public function close(int $shiftId, int $userId, float $countedCash, ?string $notes): array
    {
        return DB::transaction(function () use ($shiftId, $userId, $countedCash, $notes) {
            $shift = DB::table('cashier_shifts')->where('id', $shiftId)->where('user_id', $userId)->lockForUpdate()->first();
            abort_unless($shift, 404);
            if ($shift->closed_at) {
                return $this->describe($shift);
            }
            $expected = Money::round((float) $shift->opening_cash + (float) DB::table('cashier_shift_movements')->where('cashier_shift_id', $shiftId)->sum('amount'));
            $variance = Money::round($countedCash - $expected);
            if (abs($variance) >= .01 && ! trim((string) $notes)) {
                throw ValidationException::withMessages(['notes' => 'دوّن سبب الفرق أو ما يحتاج مراجعة قبل إغلاق العهدة.']);
            }
            DB::table('cashier_shifts')->where('id', $shiftId)->update([
                'active_user_id' => null, 'closed_at' => now(), 'expected_cash' => $expected,
                'counted_cash' => Money::round($countedCash), 'variance' => $variance,
                'closing_notes' => trim((string) $notes) ?: null, 'updated_at' => now(),
            ]);
            $this->changedAfterCommit($userId);

            return $this->describe(DB::table('cashier_shifts')->find($shiftId));
        });
    }

    public function manualMovement(int $shiftId, int $userId, int $branchId, array $data): array
    {
        return DB::transaction(function () use ($shiftId, $userId, $branchId, $data) {
            $shift = DB::table('cashier_shifts')->where('id', $shiftId)->where('user_id', $userId)->where('branch_id', $branchId)->lockForUpdate()->first();
            abort_unless($shift, 404);
            $amount = Money::round((float) $data['amount']) * ($data['direction'] === 'out' ? -1 : 1);
            $event = 'manual:'.$shiftId.':'.$userId.':'.hash('sha256', $data['token']);
            $hash = hash('sha256', json_encode([$amount, trim($data['reason']), trim($data['reference'])], JSON_THROW_ON_ERROR));
            $existing = DB::table('cashier_shift_movements')->where('event_key', $event)->first();
            if ($existing) {
                if (! hash_equals((string) $existing->payload_hash, $hash)) {
                    throw new CashierPaymentConflict('رمز حركة العهدة محفوظ بقيم أخرى. راجع سجل الحركة الأصلية.');
                }

                return ['submission_state' => 'committed', 'operation_token' => $data['token'], 'movement_id' => $existing->id, 'replayed' => true, 'shift' => $this->describe($shift)];
            }
            if ($shift->closed_at) {
                throw ValidationException::withMessages(['shift' => 'الوردية مغلقة؛ لم تُسجّل حركة نقد جديدة.']);
            }
            $id = DB::table('cashier_shift_movements')->insertGetId([
                'cashier_shift_id' => $shiftId, 'source_branch_id' => $branchId, 'event_key' => $event,
                'kind' => $amount < 0 ? 'custody_out' : 'custody_in', 'amount' => $amount,
                'reference' => trim($data['reference']), 'reason' => trim($data['reason']),
                'payload_hash' => $hash, 'occurred_at' => now(),
            ]);
            $this->changedAfterCommit($userId);

            return ['submission_state' => 'committed', 'operation_token' => $data['token'], 'movement_id' => $id, 'replayed' => false, 'shift' => $this->describe($shift)];
        }, 3);
    }

    public function workspace(int $userId): array
    {
        $active = $this->readQuery()->where('active_user_id', $userId)->first();
        $recent = $this->readQuery()->where('cashier_shifts.user_id', $userId)->whereNotNull('closed_at')->orderByDesc('cashier_shifts.id')->limit(5)->get();

        return ['active' => $active ? $this->describe($active) : null, 'recent' => $recent->map(fn ($row) => $this->describe($row))->all()];
    }

    public function describe(object $shift): array
    {
        $totals = isset($shift->incoming) ? $shift : DB::table('cashier_shift_movements')->where('cashier_shift_id', $shift->id)
            ->selectRaw('COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END),0) as incoming, COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END),0) as outgoing')->first();

        return [
            'id' => $shift->id, 'branch_id' => $shift->branch_id,
            'branch' => $shift->branch_name ?? DB::table('branches')->where('id', $shift->branch_id)->value('name'),
            'employee' => $shift->employee_name ?? null,
            'opened_at' => $shift->opened_at, 'closed_at' => $shift->closed_at,
            'opening_cash' => (float) $shift->opening_cash,
            'cash_in' => (float) $totals->incoming, 'cash_out' => (float) $totals->outgoing,
            'expected_cash' => $shift->closed_at ? (float) $shift->expected_cash : Money::round((float) $shift->opening_cash + $totals->incoming - $totals->outgoing),
            'counted_cash' => $shift->counted_cash !== null ? (float) $shift->counted_cash : null,
            'variance' => $shift->variance !== null ? (float) $shift->variance : null,
            'notes' => $shift->closing_notes,
        ];
    }

    public function readQuery(): Builder
    {
        return DB::table('cashier_shifts')
            ->leftJoin('branches', 'branches.id', '=', 'cashier_shifts.branch_id')
            ->leftJoin('users', 'users.id', '=', 'cashier_shifts.user_id')
            ->select('cashier_shifts.*', 'branches.name as branch_name', 'users.name as employee_name')
            ->selectSub(fn ($query) => $query->from('cashier_shift_movements')->whereColumn('cashier_shift_id', 'cashier_shifts.id')->where('amount', '>', 0)->selectRaw('COALESCE(SUM(amount),0)'), 'incoming')
            ->selectSub(fn ($query) => $query->from('cashier_shift_movements')->whereColumn('cashier_shift_id', 'cashier_shifts.id')->where('amount', '<', 0)->selectRaw('COALESCE(SUM(-amount),0)'), 'outgoing');
    }

    private function changedAfterCommit(int $userId): void
    {
        DB::afterCommit(function () use ($userId) {
            try {
                Cache::forever('cashier-custody:'.$userId, (string) Str::uuid());
            } catch (\Throwable $exception) {
                report($exception); // A notification failure must not misreport a committed cash movement.
            }
        });
    }
}
