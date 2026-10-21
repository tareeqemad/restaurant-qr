<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use App\Services\InvoicePdfRenderer;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePdfRendererTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        BranchContext::clear();
        parent::tearDown();
    }

    public function test_arabic_receipt_with_combining_marks_regular_and_bold_text_renders_and_caches(): void
    {
        $branch = Branch::create(['code' => 'pdf-arabic', 'name' => 'فرع السّرو', 'is_active' => true]);
        BranchContext::set($branch->id);
        $issuer = User::create(['username' => 'receipt-pdf', 'name' => 'مُحَمَّد أحمد', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active']);
        $table = Table::create(['number' => '12', 'capacity' => 4, 'status' => 'occupied', 'active' => true]);
        $session = TableSession::create(['table_id' => $table->id, 'status' => 'active', 'cover_count' => 2]);
        $category = Category::create(['name' => 'الأطباق', 'slug' => 'pdf-dishes', 'active' => true]);
        $menu = MenuItem::create(['category_id' => $category->id, 'sku' => 'PDF-MEAL', 'slug' => 'pdf-meal', 'name' => 'دجاج مُحمَّر', 'price' => 24.5, 'is_available' => true]);
        $order = Order::create(['table_id' => $table->id, 'table_session_id' => $session->id, 'status' => 'delivered', 'order_type' => 'dine_in', 'subtotal' => 49, 'total' => 49]);
        $order->items()->create(['menu_item_id' => $menu->id, 'name_snapshot' => 'دجاج مُحمَّر باللّيمون', 'quantity' => 2, 'unit_price' => 24.5, 'subtotal' => 49, 'status' => 'served']);
        $invoice = Invoice::create(['table_session_id' => $session->id, 'issued_by_user_id' => $issuer->id,
            'number' => 'INV-AR-0012', 'customer_name' => 'السّيّد عُمَر', 'subtotal' => 49, 'total' => 49,
            'paid_total' => 0, 'balance' => 49, 'status' => 'issued', 'issued_at' => now()]);
        Setting::put('site_name', 'مطعم دار السرو', 'general');
        Setting::put('receipt_footer', 'شكراً لزيارتكم — أهلاً وسهلاً', 'billing');
        Setting::put('currency_symbol', '₪', 'billing');

        $renderer = app(InvoicePdfRenderer::class);
        $pdf = $renderer->render($invoice->fresh());
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/FontFile2', $pdf, 'Arabic fonts must be embedded in the delivered PDF.');
        $this->assertStringContainsString('/ToUnicode', $pdf, 'Embedded fonts need a Unicode map.');
        $this->assertSame($pdf, $renderer->render($invoice->fresh()), 'Repeated downloads reuse the same valid binary.');
        // Opt-in artifact for visual QA; ordinary CI runs write no document.
        if ($path = getenv('RECEIPT_PDF_QA_OUTPUT')) {
            file_put_contents($path, $pdf);
        }
    }
}
