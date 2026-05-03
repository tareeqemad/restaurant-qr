<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inter-branch stock transfers.
 *
 * Two-table design:
 *   - `branch_transfers` — header (who, when, status, branches involved)
 *   - `branch_transfer_items` — lines (which ingredient, qty, source/dest locations)
 *
 * Lifecycle:
 *   draft → in_transit → received        (happy path)
 *                  ↘  → cancelled        (sender or admin cancels mid-flight)
 *
 * On `send()`:
 *   - Validate source has enough stock
 *   - Create OUT inventory_movements at source location for each line
 *   - Status → in_transit
 *
 * On `receive()` (at destination branch):
 *   - Create IN inventory_movements at destination location for each line
 *   - Status → received
 *
 * On `cancel()` (only valid before receive):
 *   - If already in_transit, reverse the OUT movements (RETURN type)
 *   - Status → cancelled
 *
 * Why two-leg movements (out + in) instead of a single "transfer" type:
 *   - Reuses the existing inventory ledger + valuation reports as-is
 *   - Each branch's history shows the half it cares about
 *   - Cancellation can reverse just the OUT leg cleanly
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 32)->unique();           // BT-YYYYMMDD-NNNN

            $table->foreignId('from_branch_id')->constrained('branches');
            $table->foreignId('to_branch_id')->constrained('branches');

            $table->string('status', 20)->default('draft')
                ->comment('draft, in_transit, received, cancelled');

            // Audit: who created / sent / received / cancelled
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('sent_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('received_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('notes')->nullable();
            $table->text('cancel_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['from_branch_id', 'status']);
            $table->index(['to_branch_id', 'status']);
            $table->index('status');
        });

        Schema::create('branch_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_transfer_id')->constrained()->cascadeOnDelete();

            $table->foreignId('ingredient_id')->constrained();
            // Qty is in the ingredient's BASE unit — no per-line unit conversion
            // is needed because both ends share the same ingredient master.
            $table->decimal('quantity_base', 15, 4);

            // Source storage location at the FROM branch (where stock leaves)
            $table->foreignId('from_location_id')->nullable()
                ->constrained('storage_locations')->nullOnDelete();
            // Destination storage location at the TO branch (where stock lands)
            $table->foreignId('to_location_id')->nullable()
                ->constrained('storage_locations')->nullOnDelete();

            // Captured at send-time — locks the basis used for COGS valuation
            // on both ends. The receiving branch credits stock at this same
            // cost (no weighted-average disruption from a "pretend purchase").
            $table->decimal('unit_cost', 12, 4)->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['branch_transfer_id', 'ingredient_id'], 'bti_transfer_ing_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_transfer_items');
        Schema::dropIfExists('branch_transfers');
    }
};
