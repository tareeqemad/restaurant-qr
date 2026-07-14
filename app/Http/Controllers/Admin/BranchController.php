<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Services\MenuDuplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Branches admin — Super Admin only (gated by BranchPolicy).
 *
 * Branches are a global resource: they live outside the per-branch
 * BranchScope so this controller sees every branch regardless of the
 * current user's active branch. The policy ensures no one but the
 * Super Admin reaches these methods.
 */
class BranchController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Branch::class);

        $query = Branch::query()->withCount(['users', 'roles']);

        if ($s = $request->get('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('name_en', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%")
                    ->orWhere('city', 'like', "%{$s}%");
            });
        }

        if (($status = $request->get('status')) !== null && $status !== '') {
            $query->where('is_active', $status === 'active');
        }

        $branches = $query->orderBy('display_order')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // Per-branch menu counts — drives the "نسخ القائمة من" button
        // (only shown when target is empty + at least one source has data).
        // We can't use withCount on Category/MenuItem because of the
        // BelongsToBranch global scope; subqueries by branch_id are simpler
        // and clearer than scope-stripping a relationship.
        $branchIds = $branches->pluck('id');
        $categoryCounts = $branchIds->isEmpty()
            ? collect()
            : Category::withoutGlobalScopes()->whereIn('branch_id', $branchIds)
                ->selectRaw('branch_id, COUNT(*) as c')->groupBy('branch_id')
                ->pluck('c', 'branch_id');
        $itemCounts = $branchIds->isEmpty()
            ? collect()
            : MenuItem::withoutGlobalScopes()->whereIn('branch_id', $branchIds)
                ->selectRaw('branch_id, COUNT(*) as c')->groupBy('branch_id')
                ->pluck('c', 'branch_id');

        $branches->each(function ($b) use ($categoryCounts, $itemCounts) {
            $b->menu_categories_count = (int) ($categoryCounts[$b->id] ?? 0);
            $b->menu_items_count      = (int) ($itemCounts[$b->id] ?? 0);
        });

        // For the duplication modal: sources = branches that have items.
        $sourceBranches = Branch::query()
            ->whereIn('id', $itemCounts->keys())
            ->orderBy('display_order')->get();

        $stats = [
            'total'    => Branch::count(),
            'active'   => Branch::where('is_active', true)->count(),
            'inactive' => Branch::where('is_active', false)->count(),
            'staff'    => DB::table('branch_user')->distinct()->count('user_id'),
        ];

        return view('admin.branches.index', compact('branches', 'stats', 'sourceBranches'));
    }

    /**
     * Copy the entire menu from another branch into this one. Target must be
     * the {branch} route param (the empty branch); source comes from the
     * `source_id` form field. The MenuDuplicationService enforces the
     * "target must be empty" + "source != target" guards and runs the whole
     * copy in a transaction.
     */
    public function duplicateMenu(Request $request, Branch $branch, MenuDuplicationService $service)
    {
        $this->authorize('update', $branch);

        $sourceId = (int) $request->validate([
            'source_id' => ['required', 'integer', Rule::exists('branches', 'id')],
        ])['source_id'];

        $source = Branch::findOrFail($sourceId);

        try {
            $stats = $service->duplicate($source, $branch);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLog::log(
            'branch.menu_duplicated',
            "نُسخت القائمة من «{$source->name}» إلى «{$branch->name}» — {$stats['categories']} تصنيف، {$stats['items']} صنف.",
            $branch
        );

        $msg = "تم نسخ القائمة من «{$source->name}» — {$stats['categories']} تصنيف و {$stats['items']} صنف.";
        if ($stats['items_without_station'] > 0) {
            $msg .= " (تنبيه: {$stats['items_without_station']} صنف بدون محطة — حدّد المحطات في فرع «{$branch->name}» أولاً ثم استورد.)";
        }
        return back()->with('success', $msg);
    }

    public function create()
    {
        $this->authorize('create', Branch::class);

        return view('admin.branches.create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Branch::class);

        $data = $this->validateData($request);

        // Branch + its day-zero defaults are one atomic unit: a branch that
        // exists without a storage location dead-ends the first ingredient
        // form (location is required), and without a station no menu item
        // can ever reach a KDS screen.
        $branch = DB::transaction(function () use ($data) {
            $branch = Branch::create($data);
            $this->provisionDefaults($branch);
            return $branch;
        });

        ActivityLog::log(
            'branch.created',
            "إنشاء فرع جديد: {$branch->name}",
            $branch
        );

        return redirect()
            ->route('admin.branches.index')
            ->with('success', "تم إنشاء فرع «{$branch->name}» مع مخزن رئيسي ومحطة مطبخ افتراضيَّين — عدِّلهما من شاشتي المواقع والمحطات.");
    }

    /**
     * Seed the minimum operational skeleton for a fresh branch: one default
     * storage location («المخزن الرئيسي») and one kitchen station («المطبخ»).
     * Guarded per-resource so the method is idempotent — a branch that
     * already has ANY location/station (e.g. created by an import or a
     * seeder) is left untouched.
     */
    protected function provisionDefaults(Branch $branch): void
    {
        // withoutGlobalScopes: bypass BranchScope (the admin may be "in"
        // another branch while creating this one) AND the soft-delete scope
        // on locations — a trashed row still occupies its globally-unique code.
        $hasLocation = StorageLocation::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->exists();

        $location = null;
        if (! $hasLocation) {
            // storage_locations.code is unique GLOBALLY (legacy), so derive
            // it from the branch code; fall back to the id on the off chance
            // a trashed location from a deleted branch still holds it.
            $code = 'main-' . $branch->code;
            if (StorageLocation::withoutGlobalScopes()->where('code', $code)->exists()) {
                $code = 'main-' . $branch->id;
            }

            $location = StorageLocation::create([
                'branch_id'     => $branch->id,
                'code'          => $code,
                'name'          => 'المخزن الرئيسي',
                'icon'          => 'bi-box-seam',
                'is_default'    => true,
                'active'        => true,
                'display_order' => 0,
            ]);
        }

        $hasStation = Station::withoutGlobalScopes()
            ->where('branch_id', $branch->id)
            ->exists();

        if (! $hasStation) {
            // Field values mirror StationSeeder's kitchen row so a
            // provisioned branch looks identical to a seeded one.
            Station::create([
                'branch_id'           => $branch->id,
                'code'                => 'kitchen',
                'name'                => 'المطبخ',
                'name_en'             => 'Kitchen',
                'color'               => '#ef4444',
                'icon'                => 'ri-fire-fill',
                'storage_location_id' => $location?->id,
                'display_order'       => 1,
                'active'              => true,
            ]);
        }
    }

    public function edit(Branch $branch)
    {
        $this->authorize('update', $branch);

        return view('admin.branches.edit', compact('branch'));
    }

    public function update(Request $request, Branch $branch)
    {
        $this->authorize('update', $branch);

        $data = $this->validateData($request, $branch->id, $branch);
        $branch->update($data);

        ActivityLog::log(
            'branch.updated',
            "تعديل بيانات فرع «{$branch->name}»",
            $branch
        );

        return redirect()
            ->route('admin.branches.index')
            ->with('success', "تم تحديث بيانات فرع «{$branch->name}»");
    }

    public function destroy(Branch $branch)
    {
        $this->authorize('delete', $branch);

        // Defense in depth: branches with users/data should not be deleted
        // silently — the user must clear assignments first or use disable.
        if ($branch->users()->exists()) {
            return back()->with('error',
                "لا يمكن حذف فرع به مستخدمون مُعيَّنون. أزل التعيينات أولاً أو عطِّل الفرع بدلاً من حذفه."
            );
        }

        $name = $branch->name;
        $branch->delete();

        ActivityLog::log('branch.deleted', "حذف فرع «{$name}»");

        return redirect()
            ->route('admin.branches.index')
            ->with('success', "تم حذف فرع «{$name}»");
    }

    public function toggleStatus(Branch $branch)
    {
        $this->authorize('update', $branch);

        $branch->update(['is_active' => ! $branch->is_active]);

        ActivityLog::log(
            'branch.status_changed',
            "تغيير حالة فرع «{$branch->name}» إلى " . ($branch->is_active ? 'مفعّل' : 'معطَّل'),
            $branch
        );

        return back()->with('success',
            $branch->is_active ? "تم تفعيل فرع «{$branch->name}»" : "تم تعطيل فرع «{$branch->name}»"
        );
    }

    /**
     * Inline validation helper — matches the pattern used by RoleController
     * and UserController. Slugifies the code so admins can paste any input.
     */
    protected function validateData(Request $request, ?int $id = null, ?Branch $branch = null): array
    {
        $data = $request->validate([
            'code'          => ['required', 'string', 'max:32', Rule::unique('branches')->ignore($id)],
            'name'          => ['required', 'string', 'max:255'],
            'name_en'       => ['nullable', 'string', 'max:255'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'email'         => ['nullable', 'email', 'max:255'],
            'city'          => ['nullable', 'string', 'max:255'],
            'address'       => ['nullable', 'string', 'max:1000'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'is_active'     => ['nullable', 'boolean'],
            'customer_tax_display' => ['nullable', 'in:inherit,exclusive,inclusive'],
        ]);

        $data['code']      = Str::slug($data['code'], '-');
        $data['is_active'] = $request->boolean('is_active');
        $settings = $branch?->settings ?? [];
        $taxDisplay = $data['customer_tax_display'] ?? 'inherit';
        unset($data['customer_tax_display']);

        if ($taxDisplay === 'inherit' || $taxDisplay === '') {
            unset($settings['customer_tax_display']);
        } else {
            $settings['customer_tax_display'] = $taxDisplay;
        }

        $data['settings'] = $settings ?: null;

        return $data;
    }
}
