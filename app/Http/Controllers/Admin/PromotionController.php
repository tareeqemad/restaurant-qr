<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\MenuPromotion;
use App\Models\Modifier;
use App\Support\AdminShell;
use App\Support\BranchContext;
use App\Support\MenuWorkspace;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin CRUD for menu promotions — supports all three patterns the
 * restaurant ever needs in one form:
 *
 *   - Permanent sale price ("الشاورما 25 بدل 30")
 *   - Scheduled campaign ("عروض رمضان من 1 → 30 يونيو")
 *   - Happy hour / weekly ("كل الحلويات 20% خصم من 3 - 5 عصراً")
 *
 * All gated by the `promotions.*` permission group (seeded by the
 * 2026_05_28_120000 migration). Cashier-level users get viewAny so
 * the POS can light up discount badges; everything else requires
 * manager-or-higher.
 */
class PromotionController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('promotions.viewAny'), 403);

        return $this->page($request);
    }

    protected function page(Request $request, ?array $editor = null)
    {
        $user = auth()->user();

        $branchId = BranchContext::current();
        $now = now();

        $base = MenuPromotion::query()
            ->with(['creator:id,name', 'branch:id,name'])
            ->orderByDesc('active')
            ->orderBy('priority')
            ->latest('id');

        if ($branchId) {
            $base->where(function ($q2) use ($branchId) {
                $q2->whereNull('branch_id')->orWhere('branch_id', $branchId);
            });
        }

        $q = clone $base;

        if ($s = trim((string) $request->get('search'))) {
            $q->where('name', 'like', "%{$s}%");
        }
        if ($status = $request->get('status')) {
            match ($status) {
                'active' => $q->where('active', true),
                'paused' => $q->where('active', false),
                'expired' => $q->whereNotNull('ends_at')->where('ends_at', '<', $now),
                'upcoming' => $q->whereNotNull('starts_at')->where('starts_at', '>', $now),
                default => null,
            };
        }

        $promotions = $q->paginate(20)->withQueryString();

        $itemIds = $promotions->where('target_type', 'menu_item')->pluck('target_id')->all();
        $catIds = $promotions->where('target_type', 'category')->pluck('target_id')->all();
        $items = MenuItem::whereIn('id', $itemIds)->pluck('name', 'id');
        $cats = Category::whereIn('id', $catIds)->pluck('name', 'id');

        $canUpdate = (bool) $user?->hasPermission('promotions.update');
        $canDelete = (bool) $user?->hasPermission('promotions.delete');

        $promotions->through(function (MenuPromotion $promotion) use ($items, $cats, $now, $canUpdate, $canDelete, $user, $branchId) {
            $isLive = $promotion->isLiveAt($now);
            $isUpcoming = $promotion->starts_at?->gt($now) ?? false;
            $isExpired = $promotion->ends_at?->lt($now) ?? false;
            $status = ! $promotion->active
                ? 'paused'
                : ($isLive ? 'live' : ($isUpcoming ? 'upcoming' : ($isExpired ? 'expired' : 'outside')));

            $canManageThisPromotion = (bool) $user?->isOwnerLevel()
                || ($promotion->branch_id !== null && (int) $promotion->branch_id === (int) $branchId);

            return [
                ...$this->promotionData($promotion),
                'targetName' => $promotion->target_type === 'menu_item'
                    ? ($items[$promotion->target_id] ?? 'صنف محذوف')
                    : ($cats[$promotion->target_id] ?? 'قسم محذوف'),
                'typeLabel' => $promotion->typeLabel(),
                'valueLabel' => $promotion->valueLabel(),
                'scheduleLabel' => $promotion->scheduleLabel(),
                'branchName' => $promotion->branch?->name ?: 'كل الفروع',
                'status' => $status,
                'can' => [
                    'update' => $canUpdate && $canManageThisPromotion,
                    'delete' => $canDelete && $canManageThisPromotion,
                ],
                'urls' => [
                    'update' => route('admin.promotions.update', $promotion),
                    'toggle' => route('admin.promotions.toggle', $promotion),
                    'destroy' => route('admin.promotions.destroy', $promotion),
                ],
            ];
        });

        $formData = $this->formData();

        return AdminShell::render('Admin/MenuCatalog/Promotions', [
            'navigation' => MenuWorkspace::navigation(),
            'promotions' => $promotions,
            'stats' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->where('active', true)->count(),
                'paused' => (clone $base)->where('active', false)->count(),
                'upcoming' => (clone $base)->where('active', true)->whereNotNull('starts_at')->where('starts_at', '>', $now)->count(),
            ],
            'filters' => [
                'search' => (string) $request->get('search', ''),
                'status' => (string) $request->get('status', ''),
            ],
            'editor' => $editor,
            'formOptions' => [
                'menuItems' => $formData['menuItems']->map(fn (MenuItem $item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'price' => (float) $item->price,
                    'categoryId' => $item->category_id,
                    'category' => $item->category?->name,
                ])->values(),
                'categories' => $formData['categories']->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                ])->values(),
                'branches' => $formData['branches']->map(fn (Branch $branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name,
                ])->values(),
                'modifiers' => $formData['modifiers']->map(fn (Modifier $modifier) => [
                    'id' => $modifier->id,
                    'name' => $modifier->name,
                    'group' => $modifier->group?->name,
                    'priceDelta' => (float) $modifier->price_delta,
                ])->values(),
                'activeBranchId' => $formData['activeBranchId'],
                'canChainWide' => (bool) $user?->isOwnerLevel(),
            ],
            'can' => [
                'create' => (bool) $user?->hasPermission('promotions.create'),
            ],
            'urls' => [
                'index' => route('admin.promotions.index'),
                'store' => route('admin.promotions.store'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('promotions.create'), 403);

        $targetType = in_array($request->query('target_type'), ['menu_item', 'category'], true)
            ? $request->query('target_type')
            : 'menu_item';
        $targetId = (int) $request->query('target_id', 0);

        return $this->page($request, [
            'mode' => 'create',
            'promotion' => $targetId > 0 ? [
                'targetType' => $targetType,
                'targetId' => $targetId,
            ] : null,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('promotions.create'), 403);
        $data = $this->validateData($request);
        $data['created_by_user_id'] = auth()->id();

        $promo = MenuPromotion::create($data);

        ActivityLog::log(
            'promotion.created',
            "إنشاء عرض: {$promo->name} ({$promo->typeLabel()} {$promo->valueLabel()})",
            $promo,
            ['type' => $promo->type, 'value' => (float) $promo->value],
        );

        return redirect()->route('admin.promotions.index')
            ->with('success', "تم إنشاء العرض «{$promo->name}».");
    }

    public function edit(Request $request, MenuPromotion $promotion)
    {
        abort_unless(auth()->user()?->hasPermission('promotions.update'), 403);
        $this->assertPromotionScope($promotion);

        return $this->page($request, [
            'mode' => 'edit',
            'promotion' => [
                ...$this->promotionData($promotion),
                'updateUrl' => route('admin.promotions.update', $promotion),
            ],
        ]);
    }

    public function update(Request $request, MenuPromotion $promotion)
    {
        abort_unless(auth()->user()?->hasPermission('promotions.update'), 403);
        $this->assertPromotionScope($promotion);
        $promotion->update($this->validateData($request));

        ActivityLog::log(
            'promotion.updated',
            "تعديل العرض: {$promotion->name}",
            $promotion,
        );

        return redirect()->route('admin.promotions.index')
            ->with('success', "تم تحديث العرض «{$promotion->name}».");
    }

    public function destroy(MenuPromotion $promotion)
    {
        abort_unless(auth()->user()?->hasPermission('promotions.delete'), 403);
        $this->assertPromotionScope($promotion);
        $name = $promotion->name;
        $promotion->delete();
        ActivityLog::log('promotion.deleted', "حذف العرض: {$name}", null);

        return back()->with('success', 'تم حذف العرض.');
    }

    /**
     * Quick pause/resume — toggles the `active` master switch without
     * needing to edit the whole form. Used by the row action on the
     * index page so the manager can pause a campaign in one click
     * (e.g., when a popular item runs out and continuing the discount
     * would just frustrate customers).
     */
    public function toggle(MenuPromotion $promotion)
    {
        abort_unless(auth()->user()?->hasPermission('promotions.update'), 403);
        $this->assertPromotionScope($promotion);
        $promotion->update(['active' => ! $promotion->active]);
        $verb = $promotion->active ? 'تشغيل' : 'إيقاف';
        ActivityLog::log("promotion.{$verb}", "{$verb} العرض: {$promotion->name}", $promotion);

        return back()->with('success', "تم {$verb} العرض «{$promotion->name}».");
    }

    // ─── helpers ─────────────────────────────────────────────────────

    protected function assertPromotionScope(MenuPromotion $promotion): void
    {
        $user = auth()->user();
        if ($user?->isOwnerLevel()) {
            return;
        }

        $branchId = BranchContext::current();
        abort_unless(
            $promotion->branch_id !== null && (int) $promotion->branch_id === (int) $branchId,
            403,
        );
    }

    protected function formData(): array
    {
        $branchId = BranchContext::current();
        $user = auth()->user();

        return [
            'menuItems' => MenuItem::with('category:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'price', 'category_id']),
            'categories' => Category::orderBy('display_order')->orderBy('name')->get(['id', 'name']),
            'branches' => $user?->isOwnerLevel()
                ? Branch::where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : $user?->branches()->where('is_active', true)->orderBy('branches.name')->get(['branches.id', 'branches.name']),
            // Modifier catalog for the "free modifier" picker (e.g.
            // "cheese is free when ordered with a burger"). Listed
            // alphabetically with parent group label for context.
            'modifiers' => Modifier::with('group:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'price_delta', 'modifier_group_id']),
            'activeBranchId' => $branchId,
        ];
    }

    protected function promotionData(MenuPromotion $promotion): array
    {
        $time = fn ($value) => $value instanceof Carbon
            ? $value->format('H:i')
            : ($value ? substr((string) $value, 0, 5) : null);

        return [
            'id' => $promotion->id,
            'name' => $promotion->name,
            'description' => $promotion->description,
            'branchId' => $promotion->branch_id,
            'type' => $promotion->type,
            'value' => (float) $promotion->value,
            'minSubtotal' => $promotion->min_subtotal !== null ? (float) $promotion->min_subtotal : null,
            'usageLimit' => $promotion->usage_limit,
            'usageCount' => (int) $promotion->usage_count,
            'audience' => $promotion->audience ?: 'everyone',
            'freeModifierIds' => array_map('intval', $promotion->free_modifier_ids ?? []),
            'bxgyBuyQty' => $promotion->bxgy_buy_qty,
            'bxgyGetQty' => $promotion->bxgy_get_qty,
            'targetType' => $promotion->target_type,
            'targetId' => $promotion->target_id,
            'startsAt' => $promotion->starts_at?->format('Y-m-d\TH:i'),
            'endsAt' => $promotion->ends_at?->format('Y-m-d\TH:i'),
            'timeFrom' => $time($promotion->time_from),
            'timeTo' => $time($promotion->time_to),
            'daysOfWeek' => array_map('intval', $promotion->days_of_week ?? []),
            'channels' => array_values($promotion->channels ?? []),
            'excludedItemIds' => array_map('intval', $promotion->excluded_item_ids ?? []),
            'active' => (bool) $promotion->active,
            'priority' => (int) $promotion->priority,
        ];
    }

    /**
     * Validate the promotion form. Cross-field rules (sale_price needs
     * positive value, percent between 0-100, time_to required if
     * time_from set) live here so the view can stay declarative.
     */
    protected function validateData(Request $request): array
    {
        $data = $request->validate([
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('is_active', true)],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['required', 'in:sale_price,percent,fixed_off'],
            'value' => ['required', 'numeric', 'min:0'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'audience' => ['nullable', 'in:everyone,birthday_month'],
            'free_modifier_ids' => ['nullable', 'array'],
            'free_modifier_ids.*' => ['integer'],
            'bxgy_buy_qty' => ['nullable', 'integer', 'min:1', 'required_with:bxgy_get_qty'],
            'bxgy_get_qty' => ['nullable', 'integer', 'min:1', 'required_with:bxgy_buy_qty'],
            'target_type' => ['required', 'in:menu_item,category'],
            'target_id' => ['required', 'integer'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'time_from' => ['nullable', 'date_format:H:i'],
            'time_to' => ['nullable', 'date_format:H:i'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'between:0,6'],
            'channels' => ['nullable', 'array'],
            'channels.*' => ['string', 'in:qr,cashier,waiter,phone,delivery,other'],
            'excluded_item_ids' => ['nullable', 'array'],
            'excluded_item_ids.*' => ['integer', 'exists:menu_items,id'],
            'active' => ['sometimes', 'boolean'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ], [], [
            'name' => 'اسم العرض',
            'type' => 'نوع الخصم',
            'value' => 'قيمة الخصم',
            'min_subtotal' => 'الحد الأدنى للفاتورة',
            'target_type' => 'نطاق التطبيق',
            'target_id' => 'الصنف / الفئة',
            'channels' => 'المنصات',
            'excluded_item_ids' => 'الأصناف المستثناة',
        ]);

        // Percent must be ≤ 100; sale_price should be > 0 (free items
        // would just be a "free meal" — use the staff-meal gift flow).
        if ($data['type'] === 'percent' && (float) $data['value'] > 100) {
            throw ValidationException::withMessages([
                'value' => 'نسبة الخصم المئوية لا يمكن أن تتجاوز 100%.',
            ]);
        }
        if ($data['type'] === 'sale_price' && (float) $data['value'] <= 0) {
            throw ValidationException::withMessages([
                'value' => 'سعر العرض يجب أن يكون أكبر من صفر.',
            ]);
        }

        // Cross-check the target exists in the correct table.
        $exists = match ($data['target_type']) {
            'menu_item' => MenuItem::where('id', $data['target_id'])->exists(),
            'category' => Category::where('id', $data['target_id'])->exists(),
            default => false,
        };
        if (! $exists) {
            throw ValidationException::withMessages([
                'target_id' => 'الصنف أو الفئة المختارة غير موجودة.',
            ]);
        }

        // Time window: both endpoints required if either is set.
        if (($data['time_from'] ?? null) xor ($data['time_to'] ?? null)) {
            throw ValidationException::withMessages([
                'time_to' => 'حدد بداية ونهاية الوقت معاً لـ Happy Hour.',
            ]);
        }

        // Normalise days_of_week → unique sorted ints, or null
        if (! empty($data['days_of_week'])) {
            $data['days_of_week'] = collect($data['days_of_week'])
                ->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();
        } else {
            $data['days_of_week'] = null;
        }

        $data['active'] = (bool) ($data['active'] ?? false);
        $data['priority'] = (int) ($data['priority'] ?? 0);

        // A branch manager can never create or move a chain-wide promotion.
        // Owner-level users keep the intentional null = all branches option.
        if (! auth()->user()?->isOwnerLevel()) {
            $currentBranchId = BranchContext::current();
            abort_unless($currentBranchId, 422, 'اختر فرعاً فعالاً قبل إدارة العروض.');
            $data['branch_id'] = $currentBranchId;
        }

        // Normalize the new flexibility arrays. Empty → null so we don't
        // store [] (which would defeat the "null = no filter" convention).
        $data['channels'] = ! empty($data['channels']) ? array_values(array_unique($data['channels'])) : null;
        $data['excluded_item_ids'] = ! empty($data['excluded_item_ids'])
            ? array_values(array_unique(array_map('intval', $data['excluded_item_ids'])))
            : null;
        // Normalize blanks to null — empty strings round-trip from the
        // form and would otherwise be persisted as 0 (for ints) or ''.
        foreach (['min_subtotal', 'usage_limit', 'bxgy_buy_qty', 'bxgy_get_qty'] as $blankable) {
            if (($data[$blankable] ?? null) === '' || ($data[$blankable] ?? null) === null) {
                $data[$blankable] = null;
            }
        }
        $data['audience'] = $data['audience'] ?? 'everyone';
        $data['free_modifier_ids'] = ! empty($data['free_modifier_ids'])
            ? array_values(array_unique(array_map('intval', $data['free_modifier_ids'])))
            : null;

        return $data;
    }
}
