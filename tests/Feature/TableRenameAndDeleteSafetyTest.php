<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrderService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Historical integrity when an admin renames or deletes a table.
 *
 * Pre-fix risks the snapshot system addresses:
 *   - Rename "5" → "5A": old receipts/reports silently display "5A"
 *     because they read `$invoice->tableSession->table->number` live.
 *   - Delete table with any session ever existed: SQL 1451 FK error
 *     because `table_sessions.table_id` had RESTRICT constraint.
 *   - Re-use of freed number on a new table: confused historical
 *     reports start pointing at the new table's identity.
 */
class TableRenameAndDeleteSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $admin;
    protected Table $table;
    protected MenuItem $burger;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('tax_enabled', false, 'billing', 'bool');
        Setting::put('service_enabled', false, 'billing', 'bool');

        $this->branch = Branch::create(['code' => 't', 'name' => 'T', 'is_active' => true]);
        BranchContext::set($this->branch->id);
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Admin', 'is_system' => true]);

        $this->admin = User::create([
            'name' => 'A', 'username' => 'admin_t', 'password' => bcrypt('x'),
            'role' => 'admin', 'status' => 'active',
            'primary_branch_id' => $this->branch->id,
        ]);
        $this->admin->branches()->attach($this->branch->id, ['is_primary' => true]);

        $g = Unit::firstOrCreate(['code' => 'g'], ['name' => 'g', 'unit_type' => 'weight', 'factor_to_base' => 1, 'is_base' => true]);
        $storage = StorageLocation::create(['branch_id' => $this->branch->id, 'code' => 'k', 'name' => 'K', 'is_default' => true, 'active' => true]);
        $kitchen = Station::create(['code' => 'kitchen', 'name' => 'Kitchen', 'storage_location_id' => $storage->id, 'active' => true]);
        $cat = Category::create(['slug' => 'm', 'name' => 'M', 'default_station_id' => $kitchen->id, 'active' => true]);
        $this->burger = MenuItem::create([
            'category_id' => $cat->id, 'station_id' => $kitchen->id,
            'sku' => 'B', 'slug' => 'b', 'name' => 'Burger',
            'price' => 30, 'is_available' => true,
        ]);

        $this->table = Table::create(['number' => '5', 'capacity' => 4, 'status' => 'available', 'active' => true]);
    }

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    // ────────────────────────────────────────────────────────────────
    // Rename: history must keep the ORIGINAL number
    // ────────────────────────────────────────────────────────────────

    public function test_rename_preserves_original_number_on_historical_records(): void
    {
        // Customer sits at table 5, orders, gets a session + order + invoice.
        $session = $this->openSession();
        $order = $this->placeOrder($session, qty: 2);
        $invoice = $this->issueInvoice($session, $order);

        // Sanity: snapshots captured the original "5".
        $this->assertSame('5', $session->refresh()->table_number_snapshot);
        $this->assertSame('5', $order->refresh()->table_number_snapshot);
        $this->assertSame('5', $invoice->refresh()->table_number_snapshot);

        // Manager renames the table to "5A".
        $this->table->update(['number' => '5A']);

        // CRITICAL: historical records still report "5", not "5A".
        $this->assertSame('5', $invoice->fresh()->tableLabel(),
            'Receipt printed yesterday MUST still show table 5 even after the rename.');
        $this->assertSame('5', $order->fresh()->tableLabel(),
            'Old order listings keep the original number.');
        $this->assertSame('5', $session->fresh()->tableLabel(),
            'Session detail retains the original number.');

        // NEW records created after the rename DO get the new number.
        $newSession = $this->openSession();
        $this->assertSame('5A', $newSession->table_number_snapshot,
            'Sessions opened after the rename pick up the new number.');
    }

    public function test_quick_edit_payload_uses_the_normal_guarded_update_flow(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.tables.update', $this->table), [
                'number' => '5A',
                'name' => 'قرب الشباك',
                'capacity' => 6,
                'zone_lookup_id' => null,
                'status' => 'reserved',
                'active' => 1,
            ])
            ->assertRedirect(route('admin.tables.index'));

        $this->table->refresh();
        $this->assertSame('5A', $this->table->number);
        $this->assertSame('قرب الشباك', $this->table->name);
        $this->assertSame(6, $this->table->capacity);
        $this->assertSame('reserved', $this->table->status);
    }

    // ────────────────────────────────────────────────────────────────
    // Delete: historical FK soft-nulls but snapshots preserve display
    // ────────────────────────────────────────────────────────────────

    public function test_delete_table_keeps_old_invoice_displaying_original_number(): void
    {
        $session = $this->openSession();
        $order = $this->placeOrder($session, qty: 1);
        $invoice = $this->issueInvoice($session, $order);

        // Close the session first (required by the new guard).
        $session->update(['status' => 'closed', 'closed_at' => now()]);
        $this->table->update(['status' => 'available']);

        // Acting as admin, hit the destroy endpoint.
        $this->actingAs($this->admin)
            ->delete(route('admin.tables.destroy', $this->table))
            ->assertRedirect();

        // Soft-deleted: row gone from default scope, snapshot intact.
        $this->assertSoftDeleted('tables', ['id' => $this->table->id]);
        $this->assertSame('5', $invoice->fresh()->tableLabel(),
            'After the table is deleted, the invoice still shows the original "5".');
    }

    public function test_destroy_refuses_when_active_session_exists(): void
    {
        $this->openSession();   // leaves table in occupied + active session

        $this->actingAs($this->admin)
            ->delete(route('admin.tables.destroy', $this->table))
            ->assertSessionHas('error');

        $this->table->refresh();
        $this->assertNull($this->table->deleted_at,
            'The active-session guard MUST block the delete (deleted_at stays null).');
    }

    // ────────────────────────────────────────────────────────────────
    // Reuse number: no history bleeds into the new table's identity
    // ────────────────────────────────────────────────────────────────

    public function test_reusing_a_freed_number_does_not_leak_into_old_records(): void
    {
        // Old session on table 5 → invoice carries snapshot "5".
        $session = $this->openSession();
        $order = $this->placeOrder($session, qty: 1);
        $invoice = $this->issueInvoice($session, $order);

        // Rename old table 5 → "5-archived", then create new table 5.
        $this->table->update(['number' => '5-archived']);
        $newTable = Table::create(['number' => '5', 'capacity' => 2, 'status' => 'available', 'active' => true]);
        $newSession = TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $newTable->id,
            'token' => 'reuse-'.uniqid(), 'status' => 'active',
            'opened_at' => now(), 'cover_count' => 2,
        ]);

        // The OLD invoice still reads "5" — from the snapshot, not the
        // re-purposed "5" on the new table. This is what stops history
        // from silently aliasing across two different physical tables.
        $this->assertSame('5', $invoice->fresh()->tableLabel());
        $this->assertSame('5', $newSession->table_number_snapshot,
            'New session correctly picks up the reused number from its own table.');
    }

    // ─── helpers ──────────────────────────────────────────────────────

    protected function openSession(): TableSession
    {
        return TableSession::create([
            'branch_id' => $this->branch->id, 'table_id' => $this->table->id,
            'token' => 'tn-'.uniqid(), 'status' => 'active',
            'opened_at' => now(), 'cover_count' => 2,
        ]);
    }

    protected function placeOrder(TableSession $session, int $qty): Order
    {
        return app(OrderService::class)->createFromCart($session, [[
            'menu_item_id' => $this->burger->id,
            'quantity' => $qty, 'modifier_ids' => [],
        ]], createdByUserId: $this->admin->id);
    }

    protected function issueInvoice(TableSession $session, Order $order): Invoice
    {
        return Invoice::create([
            'branch_id'         => $this->branch->id,
            'table_session_id'  => $session->id,
            'issued_by_user_id' => $this->admin->id,
            'subtotal'          => (float) $order->subtotal,
            'discount_total'    => 0, 'tax_total' => 0,
            'service_total'     => 0, 'delivery_fee' => 0, 'tip' => 0,
            'total'             => (float) $order->subtotal,
            'balance'           => (float) $order->subtotal,
            'status'            => 'issued',
            'issued_at'         => now(),
        ]);
    }
}
