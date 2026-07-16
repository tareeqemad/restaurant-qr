<?php

namespace App\Services;

use App\Events\TableStatusChanged;
use App\Helpers\SafeBroadcast;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;

class TableSessionTransferService
{
    public function transfer(Table $sourceTable, Table $targetTable, int $userId): TableSession
    {
        if ($sourceTable->is($targetTable)) {
            throw new \RuntimeException('اختر طاولة مختلفة لنقل الجلسة.');
        }

        if ((int) $sourceTable->branch_id !== (int) $targetTable->branch_id) {
            throw new \RuntimeException('لا يمكن نقل الجلسة بين فرعين مختلفين.');
        }

        [$session, $source, $target, $sourcePreviousStatus, $targetPreviousStatus] = DB::transaction(function () use ($sourceTable, $targetTable, $userId) {
            $source = Table::withoutGlobalScope(BranchScope::class)
                ->whereKey($sourceTable->id)
                ->lockForUpdate()
                ->firstOrFail();
            $target = Table::withoutGlobalScope(BranchScope::class)
                ->whereKey($targetTable->id)
                ->lockForUpdate()
                ->firstOrFail();

            $session = TableSession::withoutGlobalScope(BranchScope::class)
                ->where('table_id', $source->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw new \RuntimeException('لا توجد جلسة نشطة على هذه الطاولة.');
            }

            if ($session->invoice()->withoutGlobalScope(BranchScope::class)->where('status', '!=', 'cancelled')->exists()) {
                throw new \RuntimeException('لا يمكن نقل الجلسة بعد إصدار الفاتورة. ألغِ الفاتورة أولاً إذا كان النقل ضرورياً.');
            }

            $targetHasSession = TableSession::withoutGlobalScope(BranchScope::class)
                ->where('table_id', $target->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->exists();

            if ($targetHasSession || $target->status !== 'available') {
                throw new \RuntimeException('الطاولة الجديدة ليست متاحة.');
            }

            if (! $target->active) {
                throw new \RuntimeException('الطاولة الجديدة غير مفعلة.');
            }

            $sourcePreviousStatus = $source->status;
            $targetPreviousStatus = $target->status;

            $session->update([
                'table_id' => $target->id,
                'last_activity_at' => now(),
            ]);

            Order::withoutGlobalScope(BranchScope::class)->where('table_session_id', $session->id)->update([
                'table_id' => $target->id,
                'updated_at' => now(),
            ]);

            // Bussing across a move. Transfer is rejected once an invoice
            // exists, so the party still has live orders — they have been
            // eating at $source and physically got up. It is the strongest
            // dirty-table case there is, and without this the board would
            // advertise a table of used plates as clean and seatable.
            $source->update([
                'status' => 'available',
                'needs_cleaning_since' => now(),
            ]);

            // ...and $target inherits a party, so any bussing debt on it is
            // settled. TableSession's `created` valve can't do this for us:
            // a transfer UPDATEs table_id, it doesn't create a session.
            $target->update([
                'status' => 'occupied',
                'needs_cleaning_since' => null,
            ]);

            ActivityLog::log(
                'table_session.transferred',
                "نقل جلسة من طاولة {$source->number} إلى طاولة {$target->number}",
                $session,
                [
                    'source_table_id' => $source->id,
                    'source_table_number' => $source->number,
                    'target_table_id' => $target->id,
                    'target_table_number' => $target->number,
                    'transferred_by_user_id' => $userId,
                ]
            );

            return [
                $session->refresh(),
                $source->refresh(),
                $target->refresh(),
                $sourcePreviousStatus,
                $targetPreviousStatus,
            ];
        });

        SafeBroadcast::dispatch(new TableStatusChanged($source, $sourcePreviousStatus));
        SafeBroadcast::dispatch(new TableStatusChanged($target, $targetPreviousStatus));

        return $session;
    }
}
