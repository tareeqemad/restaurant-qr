<?php

namespace App\Http\Controllers\Admin;

use App\Events\TableStatusChanged;
use App\Helpers\SafeBroadcast;
use App\Http\Controllers\Controller;
use App\Models\Lookup;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\Table;
use App\Models\TableSession;
use App\Services\BillingService;
use App\Services\TableSessionTransferService;
use App\Support\AdminShell;
use App\Support\BranchContext;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TableController extends Controller
{
    // The tables URL is served by TablesBoardController@show (Inertia/Vue).
    // This controller keeps
    // the classic CRUD forms + the table actions the board posts to.

    public function create()
    {
        $this->authorize('create', Table::class);

        return AdminShell::render('Admin/Tables/Form', $this->tableFormData(new Table([
            'capacity' => 4,
            'status' => 'available',
            'active' => true,
        ])));
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

        return AdminShell::render('Admin/Tables/Form', $this->tableFormData($table));
    }

    public function update(Request $request, Table $table, BillingService $billing)
    {
        $this->authorize('update', $table);
        $result = $this->applyUpdate($request, $table, $billing);

        return redirect()->route('admin.tables.index')->with('success', $result['message']);
    }

    /**
     * Quick-edit (the board's pencil sheet) — SAME pipeline as update():
     * same validation, renumber notice, ghost-session sweep, broadcast.
     * Only the response shape differs (JSON, no redirect) so the board
     * can stay put mid-rush. The renumber notice rides as `info`.
     */
    public function quickUpdate(Request $request, Table $table, BillingService $billing)
    {
        $this->authorize('update', $table);
        $result = $this->applyUpdate($request, $table, $billing);

        return response()->json([
            'ok' => true,
            'message' => $result['message'],
            'info' => $result['info'],
            'released' => $result['released'],
        ]);
    }

    /**
     * The shared update body. Returns the renumber notice (or null) so
     * JSON callers can surface it without touching the session flash.
     */
    protected function applyUpdate(Request $request, Table $table, BillingService $billing): array
    {
        $previousStatus = $table->status;
        $previousNumber = $table->number;
        $data = $this->valid($request, $table->id);
        $releaseRequested = (bool) ($data['release_active_session'] ?? false);
        unset($data['release_active_session']);

        $result = DB::transaction(function () use (
            $table,
            $data,
            $releaseRequested,
            $billing,
            $previousNumber
        ): array {
            $table->update($data);
            $statusChanged = $table->wasChanged('status');

            // Renumber detection: if the displayed number changed and the
            // table has historical orders or invoices, leave a notice so
            // the manager understands the snapshot system keeps history
            // accurate. (No data action needed — snapshots already protect
            // every past record; this is purely an information message.)
            $info = null;
            if ($previousNumber !== $table->number) {
                $hasHistory = TableSession::where('table_id', $table->id)->exists()
                           || Order::where('table_id', $table->id)->exists();
                if ($hasHistory) {
                    $info = "تم تغيير رقم الطاولة من «{$previousNumber}» إلى «{$table->number}». "
                        .'السجلات السابقة تحتفظ بالرقم الأصلي للحفاظ على دقة الإيصالات والتقارير.';
                    session()->flash('info', $info);
                }
            }

            $released = false;
            if ($releaseRequested) {
                $released = $this->releaseStaleSession($table, $billing);
            }

            return compact('info', 'released', 'statusChanged');
        });

        // BillingService broadcasts the session close, but a plain metadata /
        // status edit still needs the regular board refresh event.
        if ($result['statusChanged'] && ! $result['released']) {
            SafeBroadcast::dispatch(new TableStatusChanged($table->refresh(), $previousStatus));
        }

        return [
            'message' => $result['released']
                ? "تم حفظ طاولة {$table->number} وإغلاق الجلسة الراكدة؛ الطاولة متاحة وجاهزة الآن."
                : "تم تحديث طاولة {$table->number}.",
            'info' => $result['info'],
            'released' => $result['released'],
        ];
    }

    /**
     * Explicitly release a stale operational session from the edit sheet.
     *
     * A table's configured `status` and its live session are separate facts.
     * The old form changed the former while the board correctly kept reading
     * the latter, producing a successful-looking save that changed nothing.
     * This path is explicit, stale-only and uses BillingService's financial
     * guard, so a crafted request can never discard an unpaid visit.
     */
    protected function releaseStaleSession(Table $table, BillingService $billing): bool
    {
        if ($table->status !== 'available') {
            throw ValidationException::withMessages([
                'release_active_session' => 'اختر حالة «متاحة» حتى يتم تحرير الطاولة.',
            ]);
        }

        $session = $table->activeSession()->first();
        if (! $session) {
            return false;
        }

        $lastActivity = $session->last_activity_at ?? $session->opened_at;
        $minimumIdle = max(1, (int) config('restaurant.order.session_attention_minutes', 75));
        if (! $lastActivity || $lastActivity->gt(now()->subMinutes($minimumIdle))) {
            throw ValidationException::withMessages([
                'release_active_session' => 'الجلسة ما زالت حديثة ونشطة؛ افتح مساحة الطاولة وتأكد من مغادرة الزبون أولاً.',
            ]);
        }

        if (! $billing->isZeroExposure($session)) {
            throw ValidationException::withMessages([
                'release_active_session' => 'لا يمكن تحرير الطاولة لأن الجلسة عليها مستحقات. افتح التحصيل وأغلق الحساب أولاً.',
            ]);
        }

        try {
            $billing->closeZeroExposureSession(
                $session,
                (int) auth()->id(),
                'تحرير صريح من تعديل الطاولة'
            );
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages([
                'release_active_session' => $e->getMessage(),
            ]);
        }

        // The manager explicitly chose “available and ready”, so this one
        // action also acknowledges the cleaning step. The ordinary close
        // button still leaves the table in “needs cleaning” for floor staff.
        $table->refresh()->update([
            'status' => 'available',
            'needs_cleaning_since' => null,
        ]);
        SafeBroadcast::dispatch(new TableStatusChanged($table->refresh(), 'available'));

        return true;
    }

    /**
     * Force-close the table's active session — used by the "إغلاق الجلسة"
     * button on the table card when a guest never closed out.
     *
     * Guard = the ZERO-EXPOSURE contract (BillingService::isZeroExposure),
     * shared with the tables-board render condition and the
     * `table-sessions:close-idle` sweep: closable when no orders exist,
     * everything is cancelled/zero-total, or the invoice is fully paid.
     * The old orders()->count() guard wrongly rejected sessions whose
     * orders were ALL cancelled — the board showed the button but the
     * click always failed. Sessions with unpaid money still need the
     * cashier flow.
     */
    public function closeSession(Request $request, Table $table, BillingService $billing)
    {
        $this->authorize('update', $table);

        $data = $request->validate([
            'mark_ready' => ['sometimes', 'boolean'],
        ]);

        $session = $table->activeSession;
        if (! $session) {
            return back()->with('error', 'لا توجد جلسة نشطة على هذه الطاولة.');
        }

        if (! $billing->isZeroExposure($session)) {
            return back()->with('error', 'الجلسة عليها طلبات غير مسدّدة — أغلقها من شاشة الكاشير أو ألغِ الطلبات أولاً.');
        }

        try {
            // Same close path as the cron sweep: frees the table and
            // broadcasts TableStatusChanged so the boards refresh.
            $billing->closeZeroExposureSession($session, (int) auth()->id(), 'إغلاق يدوي من لوحة الطاولات');

            if ((bool) ($data['mark_ready'] ?? false)) {
                $table->refresh()->update([
                    'status' => 'available',
                    'needs_cleaning_since' => null,
                ]);
                SafeBroadcast::dispatch(new TableStatusChanged($table->refresh(), 'available'));
            }
        } catch (\RuntimeException $e) {
            // Domain guard messages are written for the UI (Arabic) — safe to show.
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'تعذّر إغلاق الجلسة — حاول مجدداً أو راجع السجلات.');
        }

        return back()->with(
            'success',
            (bool) ($data['mark_ready'] ?? false)
                ? "تم تحرير طاولة {$table->number}؛ أُغلقت الجلسة وأصبحت متاحة وجاهزة."
                : "تم إغلاق الجلسة الراكدة لطاولة {$table->number}."
        );
    }

    /**
     * "Cleaned" — clears the bussing debt left when the party's session closed.
     *
     * Gated on `view`, NOT `update`: wiping a table down is floor work every
     * waiter does, while TablePolicy::update is admin|manager (it guards
     * renaming/reconfiguring the table). Using `update` here would 403 exactly
     * the people the button exists for.
     */
    public function markClean(Table $table)
    {
        $this->authorize('view', $table);

        if (! $table->needsCleaning()) {
            return back();
        }

        $table->update(['needs_cleaning_since' => null]);

        // Same event the boards already listen to — the tile clears everywhere
        // without inventing a second channel for it.
        SafeBroadcast::dispatch(new TableStatusChanged($table->refresh(), $table->status));

        return back()->with('success', "تم تنظيف طاولة {$table->number}.");
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

        // Safety net: refuse to delete a table that's still mid-service.
        // Even with the snapshot system in place, an active session means
        // a real customer is being served right now — yanking the table
        // out from under them would orphan the cashier UI and confuse
        // the waiter. The manager must close the session first.
        if ($table->activeSession) {
            return back()->with('error',
                "طاولة {$table->number} عليها جلسة نشطة حالياً — أغلق الجلسة من شاشة الكاشير أولاً ثم احذف.");
        }

        // Historical data is safe to keep: snapshots on orders/invoices/
        // sessions preserve the table number for receipts and reports,
        // and `table_id` FK on those rows is nullable + nullOnDelete, so
        // a soft-delete here doesn't break any historical lookups.
        $previousStatus = $table->status;
        $table->delete();
        SafeBroadcast::dispatch(new TableStatusChanged($table, $previousStatus));

        return back()->with('success',
            "تم حذف الطاولة. السجلات التاريخية (الفواتير والطلبات) تحتفظ بالرقم الأصلي «{$table->number}».");
    }

    public function qr(Table $table)
    {
        $this->authorize('view', $table);

        $renderer = new ImageRenderer(
            // Four modules are the minimum safe QR quiet zone.  Keeping it
            // inside the generated SVG means downloads and print layouts
            // remain scannable even when surrounding CSS changes.
            new RendererStyle(320, 4),
            new SvgImageBackEnd
        );
        $writer = new Writer($renderer);
        $svg = $writer->writeString($table->qrUrl());

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }

    public function qrPrint(Table $table)
    {
        $this->authorize('view', $table);
        $table->loadMissing(['branch', 'zone']);
        $qrUrl = $table->qrUrl();

        $renderer = new ImageRenderer(
            new RendererStyle(512, 4),
            new SvgImageBackEnd
        );
        $writer = new Writer($renderer);
        $svg = $writer->writeString($qrUrl);

        return view('admin.tables.qr-print', [
            'table' => $table,
            'svg' => $svg,
            'qrUrl' => $qrUrl,
        ]);
    }

    protected function valid(Request $request, ?int $id = null): array
    {
        // Number is unique within the active branch — same display
        // number is fine across branches (composite uniq at the DB).
        $branchId = BranchContext::current();

        return $request->validate([
            'number' => [
                'required', 'string', 'max:16',
                Rule::unique('tables')
                    ->where(fn ($q) => $q->where('branch_id', $branchId)->whereNull('deleted_at'))
                    ->ignore($id),
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'zone_lookup_id' => [
                'nullable',
                Rule::exists('lookups', 'id')->where(fn ($q) => $q->where('group', 'zones')->where('is_active', true)
                ),
            ],
            'status' => ['required', Rule::in(['available', 'occupied', 'reserved', 'out_of_service'])],
            'active' => ['sometimes', 'boolean'],
            'release_active_session' => ['sometimes', 'boolean'],
        ], [
            'number.unique' => 'رقم الطاولة مستخدم بالفعل في هذا الفرع.',
        ], [
            'zone_lookup_id' => 'المنطقة',
            'number' => 'رقم الطاولة',
        ]);
    }

    protected function tableFormData(Table $table): array
    {
        $user = auth()->user();

        return [
            'table' => [
                'id' => $table->id,
                'number' => $table->number ?? '',
                'name' => $table->name ?? '',
                'capacity' => $table->capacity ?? 4,
                'zoneId' => $table->zone_lookup_id,
                'status' => $table->status ?: 'available',
                'active' => $table->exists ? (bool) $table->active : true,
            ],
            'zones' => Lookup::for('zones')->map(fn ($zone) => [
                'id' => $zone->id,
                'label' => $zone->label,
            ])->values(),
            'canManageZones' => (bool) $user?->can('viewAny', Lookup::class),
            'urls' => [
                'index' => route('admin.tables.index'),
                'submit' => $table->exists
                    ? route('admin.tables.update', $table)
                    : route('admin.tables.store'),
                'zones' => route('admin.lookups.index', ['group' => 'zones']),
            ],
        ];
    }
}
