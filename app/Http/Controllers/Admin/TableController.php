<?php

namespace App\Http\Controllers\Admin;

use App\Events\TableStatusChanged;
use App\Helpers\SafeBroadcast;
use App\Http\Controllers\Controller;
use App\Models\Scopes\BranchScope;
use App\Models\Table;
use App\Services\TableSessionTransferService;
use App\Support\BranchContext;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TableController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Table::class);
        $tables = Table::with('activeSession')->orderBy('number')->paginate(30);
        $total    = Table::count();
        $occupied = Table::has('activeSession')->count();
        $stats = [
            'total'    => $total,
            'occupied' => $occupied,
            'free'     => max(0, $total - $occupied),
            'capacity' => (int) Table::sum('capacity'),
        ];
        return view('admin.tables.index', compact('tables', 'stats'));
    }

    public function create()
    {
        $this->authorize('create', Table::class);
        return view('admin.tables.create', [
            'zones' => \App\Models\Lookup::for('zones'),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Table::class);
        $data = $this->valid($request);
        $table = Table::create($data);
        // New table → tables board should refresh (treat it as a "was-nothing → now-status" change)
        SafeBroadcast::dispatch(new TableStatusChanged($table, ''));
        return redirect()->route('admin.tables.index')->with('success', 'تم إنشاء الطاولة');
    }

    public function edit(Table $table)
    {
        $this->authorize('update', $table);
        return view('admin.tables.edit', [
            'table' => $table,
            'zones' => \App\Models\Lookup::for('zones'),
        ]);
    }

    public function update(Request $request, Table $table)
    {
        $this->authorize('update', $table);
        $previousStatus = $table->status;
        $table->update($this->valid($request, $table->id));

        // If the admin marks the table available but a stale (orderless)
        // session is still hanging on it, close that session in the same
        // breath. Without this the customer-side scan keeps joining the
        // ghost session forever — the very confusion the user reported.
        if ($table->status === 'available' && $previousStatus !== 'available') {
            $session = $table->activeSession;
            if ($session && $session->orders()->count() === 0) {
                $session->update([
                    'status'    => 'closed',
                    'closed_at' => now(),
                ]);
            }
        }

        if ($table->wasChanged('status')) {
            SafeBroadcast::dispatch(new TableStatusChanged($table->refresh(), $previousStatus));
        }
        return redirect()->route('admin.tables.index')->with('success', 'تم التحديث');
    }

    /**
     * Force-close the table's active session — used by the "إغلاق الجلسة"
     * button on the table card when a guest never closed out (24h+ idle).
     * Refuses to close sessions that still have orders, since those need
     * the cashier flow to settle first.
     */
    public function closeSession(Table $table)
    {
        $this->authorize('update', $table);

        $session = $table->activeSession;
        if (! $session) {
            return back()->with('error', 'لا توجد جلسة نشطة على هذه الطاولة.');
        }

        if ($session->orders()->count() > 0) {
            return back()->with('error', 'الجلسة تحتوي على طلبات — أغلقها من شاشة الكاشير أو ألغِ الطلبات أولاً.');
        }

        $session->update([
            'status'    => 'closed',
            'closed_at' => now(),
        ]);

        if ($table->status === 'occupied') {
            $previousStatus = $table->status;
            $table->update(['status' => 'available']);
            SafeBroadcast::dispatch(new TableStatusChanged($table->refresh(), $previousStatus));
        }

        return back()->with('success', "تم إغلاق الجلسة الراكدة لطاولة {$table->number}.");
    }

    public function transferSession(Request $request, Table $table, TableSessionTransferService $transfers)
    {
        $this->authorize('transfer', $table);

        $data = $request->validate([
            'target_table_id' => ['required', 'integer', Rule::exists('tables', 'id')],
        ], [], [
            'target_table_id' => 'الطاولة الجديدة',
        ]);

        $target = Table::withoutGlobalScope(BranchScope::class)->whereKey($data['target_table_id'])->firstOrFail();
        $this->authorize('transfer', $target);

        try {
            $transfers->transfer($table, $target, (int) $request->user()->id);

            return back()->with('success', "تم نقل الجلسة من طاولة {$table->number} إلى طاولة {$target->number}.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Table $table)
    {
        $this->authorize('delete', $table);
        $previousStatus = $table->status;
        $table->delete();
        // Deleted → broadcast with a sentinel status so the board refreshes and drops the card
        SafeBroadcast::dispatch(new TableStatusChanged($table, $previousStatus));
        return back()->with('success', 'تم الحذف');
    }

    public function qr(Table $table)
    {
        $renderer = new ImageRenderer(
            new RendererStyle(300, 2),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $svg = $writer->writeString($table->qrUrl());
        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }

    public function qrPrint(Table $table)
    {
        $table->loadMissing(['branch', 'zone']);

        $renderer = new ImageRenderer(
            new RendererStyle(400, 2),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $svg = $writer->writeString($table->qrUrl());
        return view('admin.tables.qr-print', ['table' => $table, 'svg' => $svg]);
    }

    protected function valid(Request $request, ?int $id = null): array
    {
        // Number is unique within the active branch — same display
        // number is fine across branches (composite uniq at the DB).
        $branchId = BranchContext::current();

        return $request->validate([
            'number'   => [
                'required', 'string', 'max:16',
                \Illuminate\Validation\Rule::unique('tables')
                    ->where(fn ($q) => $q->where('branch_id', $branchId)->whereNull('deleted_at'))
                    ->ignore($id),
            ],
            'name'           => ['nullable', 'string', 'max:255'],
            'capacity'       => ['required', 'integer', 'min:1', 'max:50'],
            'zone_lookup_id' => [
                'nullable',
                \Illuminate\Validation\Rule::exists('lookups', 'id')->where(fn ($q) =>
                    $q->where('group', 'zones')->where('is_active', true)
                ),
            ],
            'status'         => ['required', \Illuminate\Validation\Rule::in(['available','occupied','reserved','out_of_service'])],
            'active'         => ['sometimes', 'boolean'],
        ], [
            'number.unique' => 'رقم الطاولة مستخدم بالفعل في هذا الفرع.',
        ], [
            'zone_lookup_id' => 'المنطقة',
            'number'         => 'رقم الطاولة',
        ]);
    }
}
