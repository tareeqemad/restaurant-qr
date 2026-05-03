<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Many-to-many: supplier ↔ branches it serves.
 *
 * Why a pivot instead of a column on Supplier:
 *   - A national supplier can serve all branches; a regional vendor
 *     might only deliver to one or two. The relationship is genuinely
 *     many-to-many.
 *   - Without this, every branch sees every supplier in dropdowns,
 *     which is noisy + leads to wrong supplier picks at smaller branches.
 *
 * Default-on-create behavior: when a supplier is added with no explicit
 * branch list, we pin it to the active branch so the supplier doesn't
 * "leak" globally by accident. The owner-level "supplier serves every
 * branch" mode is opt-in via the UI checkbox.
 *
 * Backfill: every existing supplier gets attached to every active branch
 * so behavior stays unchanged for existing installs. Branch admins can
 * tighten the list later if they want.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_supplier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'supplier_id']);
            $table->index('supplier_id');
        });

        // Backfill: attach every existing supplier to every active branch
        // so the supplier dropdowns keep showing everything until an owner
        // explicitly tightens a supplier's branch list.
        $branchIds = DB::table('branches')->where('is_active', true)->pluck('id');
        $supplierIds = DB::table('suppliers')->whereNull('deleted_at')->pluck('id');

        $rows = [];
        $now = now();
        foreach ($supplierIds as $supId) {
            foreach ($branchIds as $branchId) {
                $rows[] = [
                    'branch_id'  => $branchId,
                    'supplier_id'=> $supId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        if (! empty($rows)) {
            // Chunk to avoid huge insert payloads if a system has many suppliers
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('branch_supplier')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_supplier');
    }
};
