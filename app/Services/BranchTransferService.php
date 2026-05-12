<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\BranchTransferItem;
use App\Models\Ingredient;
use App\Models\IngredientStock;
use App\Models\InventoryMovement;
use App\Models\StorageLocation;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inter-Branch Stock Transfers.
 *
 * Sender's flow (from-branch staff):
 *   1. create() — draft with line items
 *   2. send()   — locks stock at source, creates OUT movements
 *
 * Receiver's flow (to-branch staff):
 *   3. receive() — creates IN movements at destination, marks complete
 *
 * Cancel paths:
 *   - cancel() while draft — just marks header (no stock impact)
 *   - cancel() while in_transit — REVERSES the OUT movements as RETURN
 *     so the sending branch gets its stock back
 *
 * Why two-leg movements (out + in) instead of a single transfer type:
 *   The existing inventory ledger + valuation reports already understand
 *   `in/out/waste/return/adjustment`. Treating a transfer as one OUT
 *   leg + one IN leg means every existing report keeps working without
 *   special-casing the transfer type. Each branch's history shows only
 *   the half it cares about.
 */
class BranchTransferService
{
    public function __construct(protected InventoryService $inventory) {}

    /**
     * Create a draft transfer with line items.
     *
     * @param  array{from_branch_id:int, to_branch_id:int, notes?:?string} $header
     * @param  array<int, array{ingredient_id:int, quantity_base:float, from_location_id?:?int, to_location_id?:?int, notes?:?string}> $lines
     */
    public function create(array $header, array $lines, ?int $userId = null): BranchTransfer
    {
        if ((int) $header['from_branch_id'] === (int) $header['to_branch_id']) {
            throw ValidationException::withMessages(['branches' => 'لا يمكن النقل لنفس الفرع.']);
        }
        if (empty($lines)) {
            throw ValidationException::withMessages(['lines' => 'أضف بنداً واحداً على الأقل.']);
        }

        return DB::transaction(function () use ($header, $lines, $userId) {
            $transfer = BranchTransfer::create([
                'number'             => BranchTransfer::generateNumber(),
                'from_branch_id'     => (int) $header['from_branch_id'],
                'to_branch_id'       => (int) $header['to_branch_id'],
                'status'             => 'draft',
                'created_by_user_id' => $userId,
                'notes'              => $header['notes'] ?? null,
            ]);

            foreach ($lines as $line) {
                $qty = (float) ($line['quantity_base'] ?? 0);
                if ($qty <= 0) continue;

                // Snapshot the unit cost at draft time. Source branch's
                // weighted-average is used as the basis; receiving branch
                // credits stock at this same cost (no fake markup).
                $ing = Ingredient::find($line['ingredient_id']);
                $unitCost = $ing
                    ? $ing->costAtBranch((int) $header['from_branch_id'])
                    : 0;

                BranchTransferItem::create([
                    'branch_transfer_id' => $transfer->id,
                    'ingredient_id'      => $line['ingredient_id'],
                    'quantity_base'      => $qty,
                    'from_location_id'   => $line['from_location_id'] ?? null,
                    'to_location_id'     => $line['to_location_id']   ?? null,
                    'unit_cost'          => $unitCost,
                    'notes'              => $line['notes'] ?? null,
                ]);
            }

            ActivityLog::log('branch_transfer.created',
                "إنشاء تحويل {$transfer->number} ({$transfer->fromBranch->name} → {$transfer->toBranch->name})",
                $transfer
            );

            return $transfer->fresh('items.ingredient', 'fromBranch', 'toBranch');
        });
    }

    /**
     * Mark draft → in_transit. Validates source has enough stock at the
     * specified locations, then writes OUT movements at the source side.
     */
    public function send(BranchTransfer $transfer, ?int $userId = null): BranchTransfer
    {
        if (! $transfer->isDraft()) {
            throw ValidationException::withMessages(['status' => 'يمكن إرسال المسودات فقط.']);
        }
        if ($transfer->items->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'لا توجد بنود للإرسال.']);
        }

        return DB::transaction(function () use ($transfer, $userId) {
            $items = $transfer->items()->with('ingredient')->get();

            // Pre-flight: validate every line's source has enough stock.
            // Done before any writes so a partial-send is impossible.
            foreach ($items as $item) {
                $available = $this->stockAtSource($item, $transfer->from_branch_id);
                if ($available + 0.0001 < (float) $item->quantity_base) {
                    throw ValidationException::withMessages([
                        'stock' => "المخزون غير كافٍ للمكوّن «{$item->ingredient?->name}» في فرع المصدر "
                            . "({$available} متاح، مطلوب {$item->quantity_base})."
                    ]);
                }
            }

            // All lines validated — write OUT movements pinned to the source branch.
            BranchContext::forBranch($transfer->from_branch_id, function () use ($transfer, $items, $userId) {
                foreach ($items as $item) {
                    $this->inventory->deductWithBatchTrace(
                        ingredient:        $item->ingredient,
                        qtyBase:           (float) $item->quantity_base,
                        fallbackUnitCost:  (float) $item->unit_cost,
                        reference:         $item,
                        reason:            "تحويل بين الفروع — {$transfer->number} → {$transfer->toBranch->name}",
                        userId:            $userId,
                        type:              'out',
                        storageLocationId: $item->from_location_id,
                    );
                }
            });

            $transfer->update([
                'status'           => 'in_transit',
                'sent_by_user_id'  => $userId,
                'sent_at'          => now(),
            ]);

            ActivityLog::log('branch_transfer.sent',
                "إرسال تحويل {$transfer->number} ({$items->count()} بند)",
                $transfer
            );

            $this->notifyReceiverBranch($transfer);

            return $transfer->fresh('items.ingredient', 'fromBranch', 'toBranch');
        });
    }

    /**
     * Mark in_transit → received. Writes IN movements at the destination
     * branch's storage locations.
     */
    public function receive(BranchTransfer $transfer, ?int $userId = null): BranchTransfer
    {
        if (! $transfer->isInTransit()) {
            throw ValidationException::withMessages(['status' => 'يمكن استلام التحويلات في الطريق فقط.']);
        }

        return DB::transaction(function () use ($transfer, $userId) {
            $items = $transfer->items()->with('ingredient')->get();

            // Resolve destination location: per-line override → branch default
            // → branch's first active location. Without one, the IN movement
            // would land with NULL storage_location_id and the per-branch
            // stock view (joined through ingredient_stock) would never see it.
            $fallbackLocId = StorageLocation::where('branch_id', $transfer->to_branch_id)
                ->where('active', true)
                ->orderByDesc('is_default')
                ->orderBy('display_order')
                ->value('id');

            if (! $fallbackLocId && $items->contains(fn ($i) => ! $i->to_location_id)) {
                throw ValidationException::withMessages([
                    'destination' => "فرع الوجهة «{$transfer->toBranch->name}» لا يملك أي موقع تخزين. أنشئ موقعاً واحداً قبل استلام التحويل.",
                ]);
            }

            BranchContext::forBranch($transfer->to_branch_id, function () use ($transfer, $items, $userId, $fallbackLocId) {
                foreach ($items as $item) {
                    $locId = $item->to_location_id ?: $fallbackLocId;

                    // Persist the resolved location on the line so the show
                    // page reflects where stock actually landed.
                    if (! $item->to_location_id && $locId) {
                        $item->update(['to_location_id' => $locId]);
                    }

                    $this->inventory->recordMovement(
                        ingredient:        $item->ingredient,
                        type:              'in',
                        qtyBase:           (float) $item->quantity_base,
                        unitCost:          (float) $item->unit_cost,
                        reference:         $item,
                        reason:            "تحويل بين الفروع — {$transfer->number} ← {$transfer->fromBranch->name}",
                        userId:            $userId,
                        storageLocationId: $locId,
                    );
                }
            });

            $transfer->update([
                'status'              => 'received',
                'received_by_user_id' => $userId,
                'received_at'         => now(),
            ]);

            ActivityLog::log('branch_transfer.received',
                "استلام تحويل {$transfer->number}",
                $transfer
            );

            $this->notifySenderBranch($transfer);

            return $transfer->fresh('items.ingredient', 'fromBranch', 'toBranch');
        });
    }

    /**
     * Cancel — reversibility depends on current state:
     *   - draft     → just mark cancelled
     *   - in_transit → reverse the OUT movements as RETURN (sender gets stock back)
     *   - received  → not allowed (use a return-transfer instead)
     */
    public function cancel(BranchTransfer $transfer, string $reason, ?int $userId = null): BranchTransfer
    {
        if ($transfer->isReceived() || $transfer->isCancelled()) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن إلغاء تحويل مستلم أو ملغي مسبقاً.',
            ]);
        }

        return DB::transaction(function () use ($transfer, $reason, $userId) {
            $needsReversal = $transfer->isInTransit();

            if ($needsReversal) {
                $items = $transfer->items()->with('ingredient')->get();
                BranchContext::forBranch($transfer->from_branch_id, function () use ($transfer, $items, $userId) {
                    foreach ($items as $item) {
                        $this->inventory->recordMovement(
                            ingredient:        $item->ingredient,
                            type:              'return',
                            qtyBase:           (float) $item->quantity_base,
                            unitCost:          (float) $item->unit_cost,
                            reference:         $item,
                            reason:            "إلغاء تحويل {$transfer->number} — إعادة المخزون للمصدر",
                            userId:            $userId,
                            storageLocationId: $item->from_location_id,
                        );
                    }
                });
            }

            $transfer->update([
                'status'        => 'cancelled',
                'cancelled_at'  => now(),
                'cancel_reason' => $reason,
            ]);

            ActivityLog::log('branch_transfer.cancelled',
                "إلغاء تحويل {$transfer->number}: {$reason}",
                $transfer
            );

            return $transfer->fresh('items.ingredient', 'fromBranch', 'toBranch');
        });
    }

    /** Stock available for an item at the source branch (filtered by from_location_id when set). */
    protected function stockAtSource(BranchTransferItem $item, int $branchId): float
    {
        $query = IngredientStock::query()
            ->join('storage_locations', 'ingredient_stock.storage_location_id', '=', 'storage_locations.id')
            ->where('ingredient_stock.ingredient_id', $item->ingredient_id)
            ->where('storage_locations.branch_id', $branchId);

        if ($item->from_location_id) {
            $query->where('ingredient_stock.storage_location_id', $item->from_location_id);
        }

        return (float) $query->sum('ingredient_stock.quantity');
    }

    /**
     * Notify staff at the receiving branch that a transfer is in the air.
     * Best-effort — a notification failure must NEVER block the transfer.
     */
    protected function notifyReceiverBranch(BranchTransfer $transfer): void
    {
        try {
            app(NotifyService::class)->branchTransferSent($transfer);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Close the loop with the sender once the receiving branch confirms.
     */
    protected function notifySenderBranch(BranchTransfer $transfer): void
    {
        try {
            app(NotifyService::class)->branchTransferReceived($transfer);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
