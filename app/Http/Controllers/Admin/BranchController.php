<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\BusinessOwner;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Station;
use App\Models\StorageLocation;
use App\Services\MenuDuplicationService;
use App\Support\AdminShell;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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

        $query = Branch::query()
            ->with([
                'legalProfile',
                'owners' => fn ($owners) => $owners->orderByDesc('branch_ownerships.is_primary')->orderBy('business_owners.name'),
            ])
            ->withCount(['users', 'roles', 'owners']);

        if ($s = $request->get('search')) {
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('code', 'like', "%{$s}%")
                    ->orWhere('city', 'like', "%{$s}%")
                    ->orWhereHas('owners', fn ($owners) => $owners->where('business_owners.name', 'like', "%{$s}%"))
                    ->orWhereHas('legalProfile', fn ($profile) => $profile
                        ->where('registered_name', 'like', "%{$s}%")
                        ->orWhere('tax_number', 'like', "%{$s}%"));
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
            $b->menu_items_count = (int) ($itemCounts[$b->id] ?? 0);
        });

        // For the duplication modal: sources = branches that have items.
        $sourceBranchIds = MenuItem::withoutGlobalScopes()
            ->select('branch_id')
            ->distinct()
            ->pluck('branch_id');
        $sourceBranches = Branch::query()
            ->whereIn('id', $sourceBranchIds)
            ->orderBy('display_order')->get();

        $activeBranchId = BranchContext::current();

        return AdminShell::render('Admin/Branches/Index', [
            'branches' => [
                'data' => $branches->getCollection()->map(fn (Branch $b) => [
                    'id' => $b->id,
                    'code' => $b->code,
                    'name' => $b->name,
                    'city' => $b->city,
                    'phone' => $b->phone,
                    'legalName' => $b->legalProfile?->registered_name,
                    'taxNumber' => $b->legalProfile?->tax_number,
                    'ownersCount' => (int) $b->owners_count,
                    'ownerNames' => $b->owners->pluck('name')->values()->all(),
                    'isActive' => (bool) $b->is_active,
                    'usersCount' => (int) $b->users_count,
                    'rolesCount' => (int) $b->roles_count,
                    'menuCategories' => $b->menu_categories_count,
                    'menuItems' => $b->menu_items_count,
                    // The branch the operator is currently standing in —
                    // disabling it under your own feet is a foot-gun, so the
                    // UI says so instead of offering the button silently.
                    'isCurrent' => $activeBranchId && (int) $activeBranchId === (int) $b->id,
                    // Deleting is refused server-side while staff are still
                    // assigned; surface that up front rather than as an error.
                    'blocksDelete' => (int) $b->users_count > 0,
                    'can' => [
                        'update' => auth()->user()->can('update', $b),
                        'delete' => auth()->user()->can('delete', $b),
                    ],
                    'urls' => [
                        'edit' => route('admin.branches.edit', $b),
                        'toggle' => route('admin.branches.toggle-status', $b),
                        'destroy' => route('admin.branches.destroy', $b),
                        'duplicateMenu' => route('admin.branches.menu.duplicate', $b),
                    ],
                ])->all(),
                'links' => $branches->linkCollection()->toArray(),
                'total' => $branches->total(),
            ],
            'stats' => [
                'total' => Branch::count(),
                'active' => Branch::where('is_active', true)->count(),
                'inactive' => Branch::where('is_active', false)->count(),
                'staff' => DB::table('branch_user')->distinct()->count('user_id'),
            ],
            'sourceBranches' => $sourceBranches->map(fn ($b) => [
                'id' => $b->id, 'name' => $b->name,
            ])->values()->all(),
            'filters' => [
                'search' => (string) $request->get('search', ''),
                'status' => (string) $request->get('status', ''),
            ],
            'can' => ['create' => auth()->user()->can('create', Branch::class)],
            'urls' => [
                'index' => route('admin.branches.index'),
                'create' => route('admin.branches.create'),
            ],
        ]);
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

        return AdminShell::render('Admin/Branches/Form', $this->branchFormData());
    }

    public function store(Request $request)
    {
        $this->authorize('create', Branch::class);

        $payload = $this->validateData($request);

        // Branch + its day-zero defaults are one atomic unit: a branch that
        // exists without a storage location dead-ends the first ingredient
        // form (location is required), and without a station no menu item
        // can ever reach a KDS screen.
        $branch = DB::transaction(function () use ($payload) {
            $branch = Branch::create($payload['branch']);
            $this->provisionDefaults($branch);
            $this->syncLegalProfile($branch, $payload['legal']);
            $this->syncOwners($branch, $payload['owners']);

            return $branch;
        });

        ActivityLog::log(
            'branch.created',
            "إنشاء فرع جديد: {$branch->name}",
            $branch,
            $this->branchAuditSnapshot($branch),
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
            $code = 'main-'.$branch->code;
            if (StorageLocation::withoutGlobalScopes()->where('code', $code)->exists()) {
                $code = 'main-'.$branch->id;
            }

            $location = StorageLocation::create([
                'branch_id' => $branch->id,
                'code' => $code,
                'name' => 'المخزن الرئيسي',
                'icon' => 'bi-box-seam',
                'is_default' => true,
                'active' => true,
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
                'branch_id' => $branch->id,
                'code' => 'kitchen',
                'name' => 'المطبخ',
                'color' => '#ef4444',
                'icon' => 'ri-fire-fill',
                'storage_location_id' => $location?->id,
                'display_order' => 1,
                'active' => true,
            ]);
        }
    }

    public function edit(Branch $branch)
    {
        $this->authorize('update', $branch);

        return AdminShell::render('Admin/Branches/Form', $this->branchFormData($branch));
    }

    public function update(Request $request, Branch $branch)
    {
        $this->authorize('update', $branch);

        $payload = $this->validateData($request, $branch->id, $branch);
        DB::transaction(function () use ($branch, $payload) {
            $branch->update($payload['branch']);
            $this->syncLegalProfile($branch, $payload['legal']);
            $this->syncOwners($branch, $payload['owners']);
        });

        ActivityLog::log(
            'branch.updated',
            "تعديل بيانات فرع «{$branch->name}»",
            $branch,
            $this->branchAuditSnapshot($branch),
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
                'لا يمكن حذف فرع به مستخدمون مُعيَّنون. أزل التعيينات أولاً أو عطِّل الفرع بدلاً من حذفه.'
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
            "تغيير حالة فرع «{$branch->name}» إلى ".($branch->is_active ? 'مفعّل' : 'معطَّل'),
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
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:32', Rule::unique('branches')->ignore($id)],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'customer_tax_display' => ['nullable', 'in:inherit,exclusive,inclusive'],
            'legal' => ['nullable', 'array'],
            'legal.registered_name' => ['nullable', 'string', 'max:191'],
            'legal.tax_number' => ['nullable', 'string', 'max:80'],
            'legal.commercial_registration_number' => ['nullable', 'string', 'max:100'],
            'legal.municipal_license_number' => ['nullable', 'string', 'max:100'],
            'legal.invoice_phone' => ['nullable', 'string', 'max:20'],
            'legal.invoice_email' => ['nullable', 'email', 'max:191'],
            'legal.invoice_address' => ['nullable', 'string', 'max:1000'],
            'legal.notes' => ['nullable', 'string', 'max:2000'],
            'owners' => ['required', 'array', 'min:1', 'max:10'],
            'owners.*.id' => ['nullable', 'integer', Rule::exists('business_owners', 'id')->whereNull('deleted_at')],
            'owners.*.owner_type' => ['required', Rule::in(['person', 'company'])],
            'owners.*.name' => ['required', 'string', 'max:191'],
            'owners.*.national_id' => ['nullable', 'string', 'max:80'],
            'owners.*.tax_number' => ['nullable', 'string', 'max:80'],
            'owners.*.commercial_registration_number' => ['nullable', 'string', 'max:100'],
            'owners.*.phone' => ['nullable', 'string', 'max:20'],
            'owners.*.email' => ['nullable', 'email', 'max:191'],
            'owners.*.address' => ['nullable', 'string', 'max:1000'],
            'owners.*.notes' => ['nullable', 'string', 'max:2000'],
            'owners.*.ownership_percentage' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'owners.*.title' => ['nullable', 'string', 'max:100'],
            'owners.*.is_primary' => ['nullable', 'boolean'],
            'owners.*.is_authorized_signatory' => ['nullable', 'boolean'],
            'owners.*.starts_on' => ['nullable', 'date'],
            'owners.*.ends_on' => ['nullable', 'date'],
        ]);

        $validator->after(function ($validator) use ($request) {
            $owners = collect($request->input('owners', []));
            $ids = $owners->pluck('id')->filter()->map(fn ($id) => (int) $id);

            if ($ids->duplicates()->isNotEmpty()) {
                $validator->errors()->add('owners', 'لا يمكن ربط المالك نفسه بالفرع مرتين.');
            }

            if ($owners->where('is_primary', true)->count() > 1) {
                $validator->errors()->add('owners', 'اختر مالكاً رئيسياً واحداً فقط للفرع.');
            }

            $total = $owners->sum(fn ($owner) => is_numeric($owner['ownership_percentage'] ?? null)
                ? (float) $owner['ownership_percentage']
                : 0);
            if ($total > 100.001) {
                $validator->errors()->add('owners', 'مجموع نسب الملكية لا يمكن أن يتجاوز 100%.');
            }

            foreach ($owners as $index => $owner) {
                if (filled($owner['starts_on'] ?? null) && filled($owner['ends_on'] ?? null)
                    && $owner['ends_on'] < $owner['starts_on']) {
                    $validator->errors()->add("owners.{$index}.ends_on", 'تاريخ الانتهاء يجب أن يأتي بعد تاريخ بدء الملكية.');
                }
            }
        });

        $data = $validator->validate();

        $branchData = Arr::only($data, [
            'code', 'name', 'phone', 'email', 'city', 'address', 'display_order', 'is_active',
        ]);
        $branchData['code'] = Str::slug($data['code'], '-');
        $branchData['is_active'] = $request->boolean('is_active');
        $settings = $branch?->settings ?? [];
        $taxDisplay = $data['customer_tax_display'] ?? 'inherit';

        if ($taxDisplay === 'inherit' || $taxDisplay === '') {
            unset($settings['customer_tax_display']);
        } else {
            $settings['customer_tax_display'] = $taxDisplay;
        }

        $branchData['settings'] = $settings ?: null;

        $clean = fn (array $values): array => collect($values)
            ->map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value)
            ->all();

        return [
            'branch' => $branchData,
            'legal' => $clean($data['legal'] ?? []),
            'owners' => collect($data['owners'])->map(fn ($owner) => $clean($owner))->all(),
        ];
    }

    protected function syncLegalProfile(Branch $branch, array $legal): void
    {
        $meaningful = collect(Arr::except($legal, ['notes']))->contains(fn ($value) => filled($value));

        if (! $meaningful && blank($legal['notes'] ?? null)) {
            $branch->legalProfile()->delete();

            return;
        }

        $profile = $branch->legalProfile()->firstOrNew();
        $profile->fill($legal);
        $profile->updated_by_user_id = auth()->id();
        if (! $profile->exists) {
            $profile->created_by_user_id = auth()->id();
        }
        $profile->save();
    }

    protected function syncOwners(Branch $branch, array $owners): void
    {
        if (! collect($owners)->contains(fn ($owner) => (bool) ($owner['is_primary'] ?? false))) {
            $owners[0]['is_primary'] = true;
        }

        $existingPivotUuids = DB::table('branch_ownerships')
            ->where('branch_id', $branch->id)
            ->pluck('uuid', 'business_owner_id');
        $sync = [];

        foreach ($owners as $ownerData) {
            $ownerFields = Arr::only($ownerData, [
                'owner_type', 'name', 'national_id', 'tax_number',
                'commercial_registration_number', 'phone', 'email', 'address', 'notes',
            ]);

            $owner = filled($ownerData['id'] ?? null)
                ? BusinessOwner::findOrFail((int) $ownerData['id'])
                : new BusinessOwner(['created_by_user_id' => auth()->id(), 'is_active' => true]);
            $owner->fill($ownerFields)->save();

            $sync[$owner->id] = [
                'uuid' => $existingPivotUuids[$owner->id] ?? (string) Str::ulid(),
                'ownership_percentage' => $ownerData['ownership_percentage'] ?? null,
                'title' => $ownerData['title'] ?? null,
                'is_primary' => (bool) ($ownerData['is_primary'] ?? false),
                'is_authorized_signatory' => (bool) ($ownerData['is_authorized_signatory'] ?? false),
                'starts_on' => $ownerData['starts_on'] ?? null,
                'ends_on' => $ownerData['ends_on'] ?? null,
            ];
        }

        $branch->owners()->sync($sync);
    }

    protected function branchFormData(?Branch $branch = null): array
    {
        $branch?->load([
            'legalProfile',
            'owners' => fn ($owners) => $owners->orderByDesc('branch_ownerships.is_primary')->orderBy('business_owners.name'),
        ]);

        return [
            'branch' => [
                'id' => $branch?->id,
                'code' => $branch?->code ?? '',
                'name' => $branch?->name ?? '',
                'phone' => $branch?->phone ?? '',
                'email' => $branch?->email ?? '',
                'city' => $branch?->city ?? '',
                'address' => $branch?->address ?? '',
                'displayOrder' => $branch?->display_order ?? 0,
                'isActive' => $branch ? (bool) $branch->is_active : true,
                'customerTaxDisplay' => $branch?->settingValue('customer_tax_display', 'inherit') ?? 'inherit',
                'legal' => [
                    'registered_name' => $branch?->legalProfile?->registered_name ?? '',
                    'tax_number' => $branch?->legalProfile?->tax_number ?? '',
                    'commercial_registration_number' => $branch?->legalProfile?->commercial_registration_number ?? '',
                    'municipal_license_number' => $branch?->legalProfile?->municipal_license_number ?? '',
                    'invoice_phone' => $branch?->legalProfile?->invoice_phone ?? '',
                    'invoice_email' => $branch?->legalProfile?->invoice_email ?? '',
                    'invoice_address' => $branch?->legalProfile?->invoice_address ?? '',
                    'notes' => $branch?->legalProfile?->notes ?? '',
                ],
                'owners' => $branch?->owners->map(fn (BusinessOwner $owner) => $this->ownerFormRow($owner))->values()->all() ?? [],
            ],
            'availableOwners' => BusinessOwner::query()
                ->with(['branches:id,name'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (BusinessOwner $owner) => [
                    ...$this->ownerFormRow($owner, false),
                    'branchNames' => $owner->branches->pluck('name')->values()->all(),
                ])->values()->all(),
            'urls' => [
                'index' => route('admin.branches.index'),
                'submit' => $branch
                    ? route('admin.branches.update', $branch)
                    : route('admin.branches.store'),
            ],
        ];
    }

    protected function ownerFormRow(BusinessOwner $owner, bool $withPivot = true): array
    {
        return [
            'id' => $owner->id,
            'owner_type' => $owner->owner_type,
            'name' => $owner->name,
            'national_id' => $owner->national_id ?? '',
            'tax_number' => $owner->tax_number ?? '',
            'commercial_registration_number' => $owner->commercial_registration_number ?? '',
            'phone' => $owner->phone ?? '',
            'email' => $owner->email ?? '',
            'address' => $owner->address ?? '',
            'notes' => $owner->notes ?? '',
            'ownership_percentage' => $withPivot ? ($owner->pivot?->ownership_percentage ?? '') : '',
            'title' => $withPivot ? ($owner->pivot?->title ?? '') : '',
            'is_primary' => $withPivot ? (bool) $owner->pivot?->is_primary : false,
            'is_authorized_signatory' => $withPivot ? (bool) $owner->pivot?->is_authorized_signatory : false,
            'starts_on' => $withPivot ? ($owner->pivot?->starts_on ?? '') : '',
            'ends_on' => $withPivot ? ($owner->pivot?->ends_on ?? '') : '',
        ];
    }

    protected function branchAuditSnapshot(Branch $branch): array
    {
        $branch->load(['legalProfile', 'owners']);

        return [
            'legal_profile' => [
                'registered_name' => $branch->legalProfile?->registered_name,
                'tax_number_recorded' => filled($branch->legalProfile?->tax_number),
                'commercial_registration_recorded' => filled($branch->legalProfile?->commercial_registration_number),
            ],
            'owners' => $branch->owners->map(fn (BusinessOwner $owner) => [
                'id' => $owner->id,
                'name' => $owner->name,
                'ownership_percentage' => $owner->pivot?->ownership_percentage,
                'is_primary' => (bool) $owner->pivot?->is_primary,
                'is_authorized_signatory' => (bool) $owner->pivot?->is_authorized_signatory,
            ])->values()->all(),
        ];
    }
}
