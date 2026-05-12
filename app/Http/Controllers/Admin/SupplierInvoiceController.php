<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\SupplierInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SupplierInvoiceController extends Controller
{
    public function __construct(protected SupplierInvoiceService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', SupplierInvoice::class);

        $q = SupplierInvoice::with(['supplier', 'purchaseOrder'])->latest('invoice_date');
        if ($s = $request->get('status'))   $q->where('status', $s);
        if ($s = $request->get('search'))   $q->where('number', 'like', "%$s%");
        if ($sid = $request->get('supplier_id')) $q->where('supplier_id', $sid);
        if ($d = $request->get('from'))     $q->whereDate('invoice_date', '>=', $d);
        if ($d = $request->get('to'))       $q->whereDate('invoice_date', '<=', $d);
        if ($request->filled('overdue'))    $q->where('status', '!=', 'paid')
                                              ->where('status', '!=', 'cancelled')
                                              ->whereDate('due_date', '<', now());

        $invoices = $q->paginate(20)->withQueryString();

        $stats = [
            'total_ap'     => (float) SupplierInvoice::whereNotIn('status', ['cancelled'])->sum('balance'),
            'overdue'      => SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])
                                              ->whereDate('due_date', '<', now())->count(),
            'due_this_week'=> SupplierInvoice::whereNotIn('status', ['paid', 'cancelled'])
                                              ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
                                              ->count(),
            'this_month'   => (float) SupplierInvoice::whereMonth('invoice_date', now()->month)
                                              ->whereYear('invoice_date', now()->year)
                                              ->where('status', '!=', 'cancelled')
                                              ->sum('total'),
        ];

        return view('admin.supplier-invoices.index', [
            'invoices'  => $invoices,
            'stats'     => $stats,
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', SupplierInvoice::class);
        $po = $request->filled('po') ? PurchaseOrder::find($request->get('po')) : null;
        // Eager-load receiptItems so the invoice form can show the ACTUAL
        // received price (which the cashier may have edited at receipt
        // time) instead of the original PO ordered price. Without this
        // the form silently fell back to `items.unit_price` (= ordered)
        // and the manager would re-record the supplier invoice at the
        // wrong price, breaking weighted-average + variance reports.
        $po?->load([
            'supplier',
            'items.ingredient.baseUnit',
            'items.unit',
            'items.receiptItems',  // latest is the source of truth
        ]);
        return view('admin.supplier-invoices.create', [
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(),
            'pos'       => PurchaseOrder::with('supplier')
                                         ->whereIn('status', ['received', 'partially_received'])
                                         ->latest()->limit(50)->get(),
            'po'        => $po,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', SupplierInvoice::class);

        $data = $request->validate([
            'number'            => ['required', 'string', 'max:60'],
            'supplier_id'       => ['required', 'exists:suppliers,id'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'subtotal'          => ['required', 'numeric', 'min:0'],
            'tax_total'         => ['nullable', 'numeric', 'min:0'],
            'total'             => ['required', 'numeric', 'min:0.01'],
            'invoice_date'      => ['required', 'date'],
            'due_date'          => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'notes'             => ['nullable', 'string', 'max:2000'],
            'attachment'        => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'lines'                         => ['nullable', 'array'],
            'lines.*.purchase_order_item_id'=> ['nullable', 'exists:purchase_order_items,id'],
            'lines.*.ingredient_id'         => ['nullable', 'exists:ingredients,id'],
            'lines.*.unit_id'               => ['nullable', 'exists:units,id'],
            'lines.*.description'           => ['required_with:lines', 'string', 'max:255'],
            'lines.*.quantity'              => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.unit_price'            => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.tax_total'             => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes'                 => ['nullable', 'string', 'max:500'],
        ]);

        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('supplier-invoices', 'public');
        }

        try {
            $inv = $this->service->create($data, auth()->id());
            return redirect()
                ->route('admin.supplier-invoices.show', $inv)
                ->with('success', "تم تسجيل فاتورة المورد {$inv->number}");
        } catch (\Throwable $e) {
            if (!empty($data['attachment_path'])) Storage::disk('public')->delete($data['attachment_path']);
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(SupplierInvoice $supplierInvoice)
    {
        $this->authorize('view', $supplierInvoice);
        $supplierInvoice->load([
            'supplier',
            'purchaseOrder',
            'items.ingredient.baseUnit',
            'items.unit',
            'items.purchaseOrderItem.ingredient.baseUnit',
            'payments.payer',
            'creator',
        ]);
        return view('admin.supplier-invoices.show', ['invoice' => $supplierInvoice]);
    }

    public function pay(Request $request, SupplierInvoice $supplierInvoice)
    {
        $this->authorize('pay', $supplierInvoice);

        $data = $request->validate([
            'amount'    => ['required', 'numeric', 'min:0.01'],
            'method'    => ['required', 'in:cash,bank_transfer,cheque,card,credit_note,other'],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_on'   => ['required', 'date'],
            'notes'     => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->recordPayment($supplierInvoice, $data, auth()->id());
            return back()->with('success', 'تم تسجيل الدفعة وتحديث رصيد الفاتورة.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, SupplierInvoice $supplierInvoice)
    {
        $this->authorize('cancel', $supplierInvoice);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        try {
            $this->service->cancel($supplierInvoice, $data['reason'], auth()->id());
            return back()->with('success', 'تم إلغاء الفاتورة.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(SupplierInvoice $supplierInvoice)
    {
        $this->authorize('delete', $supplierInvoice);
        if ($supplierInvoice->attachment_path) {
            Storage::disk('public')->delete($supplierInvoice->attachment_path);
        }
        $supplierInvoice->delete();
        return redirect()->route('admin.supplier-invoices.index')->with('success', 'تم حذف الفاتورة');
    }
}
