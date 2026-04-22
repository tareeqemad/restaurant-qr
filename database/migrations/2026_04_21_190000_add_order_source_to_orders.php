<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tag each order with its acquisition channel:
 *   - dine_in     → QR-scanned by customer on a table (existing flow)
 *   - talabat     → phoned in via Talabat
 *   - careem      → phoned in via Careem Food
 *   - uber_eats   → Uber Eats
 *   - phone       → direct phone call
 *   - other       → walk-in takeaway, corporate, etc.
 *
 * external_reference stores the platform's own order number so staff
 * can cross-reference when customers call back.
 *
 * platform_commission_pct is the % fee the platform deducts — used
 * for "net revenue after commissions" reporting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('order_source', 20)->default('dine_in')->index()->after('table_id');
            $table->string('external_reference', 80)->nullable()->after('order_source');
            $table->decimal('platform_commission_pct', 5, 2)->default(0)->after('external_reference')
                  ->comment('Commission % charged by platform (e.g. 25 for 25% Talabat cut)');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['order_source']);
            $table->dropColumn(['order_source', 'external_reference', 'platform_commission_pct']);
        });
    }
};
