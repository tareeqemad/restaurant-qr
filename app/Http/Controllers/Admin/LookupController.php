<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manage "soft enum" rows (Lookup model). The screen lists every known
 * group as a tab and renders a CRUD table per group. Operational tables
 * reference these by `lookups.id` (FK), so renaming the label is always
 * safe — the only thing the UI restricts is hard-deleting a `is_system`
 * row, which would break the seeded backfill.
 */
class LookupController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Lookup::class);

        $groups = Lookup::knownGroups();
        $activeGroup = $request->get('group', array_key_first($groups));
        if (! isset($groups[$activeGroup])) {
            $activeGroup = array_key_first($groups);
        }

        $rows = Lookup::withTrashed()
            ->where('group', $activeGroup)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        // Per-group usage count — for the dashboard chips above each tab.
        // Derived from knownGroups() so a new lookup group automatically
        // gets its count chip without touching this file. Each call hits
        // the cached `Lookup::for($group)` (default cache driver, 5 min).
        $usage = collect(array_keys($groups))
            ->mapWithKeys(fn ($g) => [$g => Lookup::for($g)->count()])
            ->all();

        return view('admin.lookups.index', [
            'groups'      => $groups,
            'activeGroup' => $activeGroup,
            'rows'        => $rows,
            'usage'       => $usage,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Lookup::class);

        $data = $this->validateData($request, isCreate: true);

        $lookup = Lookup::create([
            ...$data,
            // Always derive branch_id server-side from the group, NEVER from
            // request input. Per-branch groups bind to the active branch;
            // global groups stay null. Stops a non-owner from POSTing
            // `branch_id=<other branch>` to seed lookups in someone else's
            // branch. (`validateData()` doesn't include branch_id in its
            // rules but this is defense in depth — model is fillable.)
            'branch_id' => $this->resolveBranchIdForGroup($data['group']),
            'is_system' => false,        // anything created via UI is user-defined
            'is_active' => $data['is_active'] ?? true,
        ]);

        Lookup::forget($lookup->group);  // bust cross-request cache

        ActivityLog::log('lookup.created',
            "إضافة قيمة \"{$lookup->label}\" إلى \"{$lookup->group}\"",
            $lookup
        );

        return redirect()->route('admin.lookups.index', ['group' => $lookup->group])
            ->with('success', 'تمت إضافة القيمة.');
    }

    public function update(Request $request, Lookup $lookup)
    {
        $this->authorize('update', $lookup);

        $data = $this->validateData($request, isCreate: false, lookupId: $lookup->id);

        // System rows: protect the code so the seeder/backfill mapping
        // stays intact. Label/color/icon/order/active are all editable.
        if ($lookup->is_system) {
            unset($data['code']);
        }

        // Strip branch_id from update payload — group is immutable post-create
        // and branch_id derives from group. Belt-and-braces against fillable.
        unset($data['branch_id'], $data['group']);

        $lookup->update($data);

        Lookup::forget($lookup->group);  // bust cross-request cache

        ActivityLog::log('lookup.updated',
            "تعديل قيمة \"{$lookup->label}\" في \"{$lookup->group}\"",
            $lookup
        );

        return redirect()->route('admin.lookups.index', ['group' => $lookup->group])
            ->with('success', 'تم حفظ التعديلات.');
    }

    /**
     * Resolve the branch_id for a new lookup row based on its group:
     *   - Per-branch groups (`Lookup::PER_BRANCH_GROUPS`) → active branch
     *   - Global groups → null
     * Owner-level on "كل الفروع" creating a per-branch lookup falls back
     * to the user's primary branch (cleaner than refusing).
     */
    protected function resolveBranchIdForGroup(string $group): ?int
    {
        if (! in_array($group, Lookup::PER_BRANCH_GROUPS, true)) {
            return null;
        }
        return \App\Support\BranchContext::current()
            ?? auth()->user()?->primaryBranch()?->id;
    }

    public function destroy(Lookup $lookup)
    {
        $this->authorize('delete', $lookup);

        if ($lookup->is_system) {
            return back()->with('error',
                'لا يمكن حذف هذه القيمة لأنها أساسية للنظام. يمكنك إخفاؤها بدلاً من ذلك.');
        }

        $group = $lookup->group;
        $label = $lookup->label;

        $lookup->delete(); // soft delete — preserves FK references for history
        Lookup::forget($group);

        ActivityLog::log('lookup.deleted', "حذف قيمة \"{$label}\" من \"{$group}\"");

        return redirect()->route('admin.lookups.index', ['group' => $group])
            ->with('success', 'تم إخفاء القيمة. (السجلات السابقة تبقى مرتبطة بها للأرشيف).');
    }

    public function restore(int $id)
    {
        $lookup = Lookup::withTrashed()->findOrFail($id);
        $this->authorize('update', $lookup);

        $lookup->restore();
        Lookup::forget($lookup->group);

        ActivityLog::log('lookup.restored',
            "استعادة قيمة \"{$lookup->label}\" في \"{$lookup->group}\"",
            $lookup
        );

        return redirect()->route('admin.lookups.index', ['group' => $lookup->group])
            ->with('success', 'تم استرجاع القيمة.');
    }

    // ─── Validation ────────────────────────────────────────────────

    protected function validateData(Request $request, bool $isCreate, ?int $lookupId = null): array
    {
        $groupRule = $isCreate
            ? ['required', Rule::in(array_keys(Lookup::knownGroups()))]
            : ['nullable']; // group is locked once created — never re-validated

        $codeUnique = Rule::unique('lookups', 'code')
            ->where(fn ($q) => $q->where('group', $request->input('group')))
            ->whereNull('deleted_at')
            ->ignore($lookupId);

        $rules = [
            'group'         => $groupRule,
            'code'          => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/i', $codeUnique],
            'label'         => ['required', 'string', 'max:120'],
            'color'         => ['nullable', 'string', 'max:30'],
            'icon'          => ['nullable', 'string', 'max:60'],
            'display_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active'     => ['nullable', 'boolean'],
        ];

        $data = $request->validate($rules);

        // Normalise boolean (HTML checkbox sends nothing when unchecked).
        $data['is_active']     = $request->boolean('is_active');
        $data['display_order'] = (int) ($data['display_order'] ?? 0);

        return $data;
    }
}
