<?php

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Branches admin board — live filter, inline status toggle, no full reload.
 *
 * Owner-level work (multi-branch monitoring) was awkward with the old form-
 * submit page: every status flip and search refresh blew away the scroll
 * position of a long branch list. This component keeps the page steady and
 * defers all mutations to wire:click handlers.
 *
 * Polling is deliberately slow (15s) — branch list rarely changes mid-day.
 * The aggressive Reverb-driven refresh that the kitchen board uses would be
 * overkill here.
 */
new class extends Component
{
    use WithPagination;

    /** Bootstrap pagination matches the rest of the admin shell. */
    protected $paginationTheme = 'bootstrap';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'status', except: '')]
    public string $statusFilter = ''; // '', 'active', 'inactive'

    /** Reset to first page whenever a filter changes — avoids "page 3 of 0 results". */
    public function updatingSearch(): void { $this->resetPage(); }
    public function updatingStatusFilter(): void { $this->resetPage(); }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter']);
        $this->resetPage();
    }

    /**
     * Toggle the branch's active flag inline. Returns silently on failure
     * (the policy `update` would 403, the catch-all dispatches a flash).
     */
    public function toggleStatus(int $branchId): void
    {
        $branch = Branch::find($branchId);
        if (! $branch) return;

        $this->authorize('update', $branch);

        $branch->update(['is_active' => ! $branch->is_active]);

        ActivityLog::log(
            'branch.status_changed',
            "تغيير حالة فرع «{$branch->name}» إلى " . ($branch->is_active ? 'مفعّل' : 'معطَّل'),
            $branch
        );

        $this->dispatch('flash', type: 'success',
            message: $branch->is_active
                ? "تم تفعيل فرع «{$branch->name}»"
                : "تم تعطيل فرع «{$branch->name}»");

        // Bust the computed-property cache so the row re-renders with the
        // new badge + button colour without a manual `unset`.
        unset($this->branches, $this->stats);
    }

    public function deleteBranch(int $branchId): void
    {
        $branch = Branch::find($branchId);
        if (! $branch) return;

        $this->authorize('delete', $branch);

        if ($branch->users()->exists()) {
            $this->dispatch('flash', type: 'error',
                message: "لا يمكن حذف فرع «{$branch->name}» — لديه مستخدمون مُعيَّنون.");
            return;
        }

        $name = $branch->name;
        $branch->delete();

        ActivityLog::log('branch.deleted', "حذف فرع «{$name}»");
        $this->dispatch('flash', type: 'success', message: "تم حذف فرع «{$name}»");

        unset($this->branches, $this->stats);
    }

    #[Computed]
    public function branches()
    {
        $query = Branch::query()->withCount(['users', 'roles']);

        if ($this->search !== '') {
            $s = $this->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('name_en', 'like', "%{$s}%")
                  ->orWhere('code', 'like', "%{$s}%")
                  ->orWhere('city', 'like', "%{$s}%");
            });
        }

        if ($this->statusFilter !== '') {
            $query->where('is_active', $this->statusFilter === 'active');
        }

        $page = $query->orderBy('display_order')->orderBy('name')->paginate(20);

        // Per-branch menu counts — drives the "نسخ القائمة من" button. The
        // BelongsToBranch global scope on Category/MenuItem would hide cross-
        // branch rows from this owner view, so withoutGlobalScopes() spans
        // every branch in one query.
        $branchIds = $page->pluck('id');
        if ($branchIds->isNotEmpty()) {
            $cats = Category::withoutGlobalScopes()->whereIn('branch_id', $branchIds)
                ->selectRaw('branch_id, COUNT(*) as c')->groupBy('branch_id')
                ->pluck('c', 'branch_id');
            $items = MenuItem::withoutGlobalScopes()->whereIn('branch_id', $branchIds)
                ->selectRaw('branch_id, COUNT(*) as c')->groupBy('branch_id')
                ->pluck('c', 'branch_id');
            $page->each(function ($b) use ($cats, $items) {
                $b->menu_categories_count = (int) ($cats[$b->id] ?? 0);
                $b->menu_items_count      = (int) ($items[$b->id] ?? 0);
            });
        }

        return $page;
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total'    => Branch::count(),
            'active'   => Branch::where('is_active', true)->count(),
            'inactive' => Branch::where('is_active', false)->count(),
            'staff'    => \DB::table('branch_user')->distinct()->count('user_id'),
        ];
    }

    /** Source list for the duplication modal (any branch with menu items). */
    #[Computed]
    public function sourceBranches()
    {
        $withItems = MenuItem::withoutGlobalScopes()
            ->selectRaw('DISTINCT branch_id')
            ->pluck('branch_id');

        return Branch::whereIn('id', $withItems)->orderBy('display_order')->get();
    }
}
?>

<div wire:poll.visible.30s class="bb-wrap">
    {{-- Stats row --}}
    <x-admin.stat-rail :stats="[
        ['label' => 'إجمالي الفروع', 'value' => $this->stats['total'],    'icon' => 'bi-buildings',         'color' => 'primary'],
        ['label' => 'مفعّلة',         'value' => $this->stats['active'],   'icon' => 'bi-check-circle-fill', 'color' => 'success'],
        ['label' => 'معطَّلة',        'value' => $this->stats['inactive'], 'icon' => 'bi-pause-circle',      'color' => 'muted'],
        ['label' => 'موظفون مُعيَّنون', 'value' => $this->stats['staff'],    'icon' => 'bi-people-fill',       'color' => 'accent'],
    ]" />

    <x-admin.data-panel title="قائمة الفروع" :count="$this->branches->total()" icon="bi-buildings">
        <x-slot:actions>
            <a href="{{ route('admin.branches.create') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> فرع جديد
            </a>
            @if($search !== '' || $statusFilter !== '')
                <button wire:click="clearFilters" type="button" class="btn btn-light">
                    <i class="bi bi-x-circle"></i> مسح الفلاتر
                </button>
            @endif
        </x-slot:actions>

        <x-slot:filters>
            <div class="row g-2">
                <div class="col-md-7">
                    <input type="text" wire:model.live.debounce.500ms="search"
                           class="form-control" placeholder="🔍 الاسم / الرمز / المدينة">
                </div>
                <div class="col-md-5">
                    <select wire:model.live="statusFilter" class="form-select">
                        <option value="">كل الحالات</option>
                        <option value="active">مفعَّل</option>
                        <option value="inactive">معطَّل</option>
                    </select>
                </div>
            </div>
        </x-slot:filters>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>الفرع</th>
                        <th>الرمز</th>
                        <th>المدينة</th>
                        <th>الهاتف</th>
                        <th>الموظفون</th>
                        <th>القائمة</th>
                        <th>الأدوار المخصَّصة</th>
                        <th>الحالة</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($this->branches as $b)
                        <tr wire:key="br-{{ $b->id }}">
                            <td>
                                <div class="fw-bold">{{ $b->name }}</div>
                                @if($b->name_en)
                                    <small class="text-muted">{{ $b->name_en }}</small>
                                @endif
                            </td>
                            <td><code>{{ $b->code }}</code></td>
                            <td>{{ $b->city ?? '—' }}</td>
                            <td>{{ $b->phone ?? '—' }}</td>
                            <td>
                                <span class="badge bg-primary-transparent">{{ $b->users_count }}</span>
                            </td>
                            <td>
                                @if(($b->menu_items_count ?? 0) > 0)
                                    <span class="badge bg-success-transparent" title="{{ $b->menu_categories_count }} تصنيف">
                                        {{ $b->menu_items_count }} صنف
                                    </span>
                                @else
                                    <span class="badge bg-warning-transparent">فارغ</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-secondary-transparent">{{ $b->roles_count }}</span>
                            </td>
                            <td>
                                @if($b->is_active)
                                    <span class="badge bg-success">مفعَّل</span>
                                @else
                                    <span class="badge bg-secondary">معطَّل</span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('admin.branches.edit', $b) }}"
                                   class="btn btn-sm btn-light" title="تعديل">
                                    <i class="bi bi-pencil"></i>
                                </a>

                                @if(($b->menu_items_count ?? 0) === 0 && $this->sourceBranches->where('id', '!=', $b->id)->count() > 0)
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            data-bs-toggle="modal"
                                            data-bs-target="#duplicateMenuModal-{{ $b->id }}"
                                            title="نسخ القائمة من فرع آخر">
                                        <i class="bi bi-clipboard-plus"></i>
                                    </button>
                                @endif

                                <button wire:click="toggleStatus({{ $b->id }})" type="button"
                                        class="btn btn-sm {{ $b->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                        title="{{ $b->is_active ? 'تعطيل' : 'تفعيل' }}">
                                    <i class="bi {{ $b->is_active ? 'bi-pause-fill' : 'bi-play-fill' }}"></i>
                                </button>

                                @if($b->users_count === 0)
                                    <button wire:click="deleteBranch({{ $b->id }})" type="button"
                                            wire:confirm="حذف فرع «{{ $b->name }}» نهائياً؟ هذا الإجراء لا يمكن التراجع عنه."
                                            class="btn btn-sm btn-outline-danger" title="حذف">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">
                                <x-admin.empty-state icon="bi-buildings"
                                    title="لا توجد فروع"
                                    message="ابدأ بإضافة أول فرع لك." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->branches->hasPages())
            <x-slot:footer>{{ $this->branches->links() }}</x-slot:footer>
        @endif
    </x-admin.data-panel>

    {{-- Per-branch "Duplicate menu from…" modals.
         Bootstrap data-bs-toggle wires the trigger button (in the row actions)
         to this dialog. The form posts to the controller (not Livewire) so the
         service-level guards + DB transaction stay where they belong. --}}
    @foreach($this->branches as $b)
        @php $eligibleSources = $this->sourceBranches->where('id', '!=', $b->id); @endphp
        @if(($b->menu_items_count ?? 0) === 0 && $eligibleSources->count() > 0)
            <div class="modal fade" id="duplicateMenuModal-{{ $b->id }}" tabindex="-1" aria-hidden="true" wire:ignore.self>
                <div class="modal-dialog modal-dialog-centered">
                    <form action="{{ route('admin.branches.menu.duplicate', $b) }}" method="POST" class="modal-content">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="bi bi-clipboard-plus me-2"></i>
                                نسخ قائمة فرع «{{ $b->name }}»
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted mb-3">
                                اختر فرع المصدر اللي تنسخ منه — هتنتقل التصنيفات والأصناف وكل الإضافات والوصفات.
                                الـ stations بتنتقل بالكود (kitchen → kitchen، bar → bar، …)؛ لو الفرع الجديد
                                ما عنده محطة بنفس الكود الصنف هينتقل بدون محطة وهتقدر تحدّدها لاحقاً.
                            </p>
                            <label class="form-label fw-bold">الفرع المصدر</label>
                            <select name="source_id" class="form-select" required>
                                @foreach($eligibleSources as $src)
                                    <option value="{{ $src->id }}">{{ $src->name }}</option>
                                @endforeach
                            </select>
                            <div class="alert alert-warning mt-3 mb-0 small">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                النسخ متاح فقط لما يكون الفرع الهدف فاضي (لا تصنيفات ولا أصناف).
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-clipboard-check"></i> نسخ القائمة
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endforeach
</div>
