<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Support\AdminShell;
use App\Support\MenuWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModifierGroupController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', MenuItem::class);

        return $this->page($request);
    }

    protected function page(Request $request, ?array $editor = null)
    {
        $groups = ModifierGroup::with('allModifiers')
            ->withCount('menuItems')
            ->orderBy('display_order')
            ->paginate(20)
            ->withQueryString();

        $stats = [
            'total_groups' => ModifierGroup::count(),
            'required'     => ModifierGroup::where('required', true)->count(),
            'optional'     => ModifierGroup::where('required', false)->count(),
            'total_options'=> Modifier::count(),
        ];

        $user = auth()->user();
        $canUpdate = (bool) $user?->can('update', MenuItem::class);
        $canDelete = (bool) $user?->can('delete', MenuItem::class);

        $groups->through(fn (ModifierGroup $group) => [
            'id' => $group->id,
            'name' => $group->name,
            'minSelect' => (int) $group->min_select,
            'maxSelect' => (int) $group->max_select,
            'required' => (bool) $group->required,
            'displayOrder' => (int) $group->display_order,
            'active' => (bool) $group->active,
            'itemsCount' => (int) $group->menu_items_count,
            'options' => $group->allModifiers->map(fn (Modifier $modifier) => [
                'id' => $modifier->id,
                'name' => $modifier->name,
                'priceDelta' => (float) $modifier->price_delta,
                'displayOrder' => (int) $modifier->display_order,
                'active' => (bool) $modifier->active,
            ])->values(),
            'can' => [
                'update' => $canUpdate,
                'delete' => $canDelete && $group->menu_items_count === 0,
            ],
            'urls' => [
                'update' => route('admin.modifiers.update', $group),
                'destroy' => route('admin.modifiers.destroy', $group),
            ],
        ]);

        return AdminShell::render('Admin/MenuCatalog/Modifiers', [
            'navigation' => MenuWorkspace::navigation(),
            'groups' => $groups,
            'stats' => $stats,
            'editor' => $editor,
            'can' => [
                'create' => (bool) $user?->can('create', MenuItem::class),
            ],
            'urls' => [
                'index' => route('admin.modifiers.index'),
                'store' => route('admin.modifiers.store'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', MenuItem::class);

        return $this->page($request, ['mode' => 'create', 'group' => null]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', MenuItem::class);
        $data = $this->valid($request);
        DB::transaction(function () use ($data) {
            $group = ModifierGroup::create($data);
            $this->syncModifiers($group, $data['modifiers'] ?? []);
        });
        return redirect()->route('admin.modifiers.index')->with('success', 'تم');
    }

    public function edit(Request $request, ModifierGroup $modifier)
    {
        $this->authorize('update', MenuItem::class);
        $modifier->load('allModifiers');

        return $this->page($request, [
            'mode' => 'edit',
            'group' => [
                'id' => $modifier->id,
                'name' => $modifier->name,
                'minSelect' => (int) $modifier->min_select,
                'maxSelect' => (int) $modifier->max_select,
                'required' => (bool) $modifier->required,
                'displayOrder' => (int) $modifier->display_order,
                'active' => (bool) $modifier->active,
                'options' => $modifier->allModifiers->map(fn (Modifier $option) => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'priceDelta' => (float) $option->price_delta,
                    'displayOrder' => (int) $option->display_order,
                    'active' => (bool) $option->active,
                ])->values(),
                'updateUrl' => route('admin.modifiers.update', $modifier),
            ],
        ]);
    }

    public function update(Request $request, ModifierGroup $modifier)
    {
        $this->authorize('update', MenuItem::class);
        $data = $this->valid($request);
        DB::transaction(function () use ($modifier, $data) {
            $modifier->update($data);
            $this->syncModifiers($modifier, $data['modifiers'] ?? []);
        });
        return redirect()->route('admin.modifiers.index')->with('success', 'تم');
    }

    public function destroy(ModifierGroup $modifier)
    {
        $this->authorize('delete', MenuItem::class);

        if ($modifier->menuItems()->exists()) {
            return back()->with('error', 'هذه المجموعة مرتبطة بأصناف. افصلها عن الأصناف أو عطّلها بدلاً من حذفها.');
        }

        $modifier->delete();
        return back()->with('success', 'تم');
    }

    protected function syncModifiers(ModifierGroup $group, array $modifiers): void
    {
        $keepIds = [];
        foreach ($modifiers as $m) {
            if (empty($m['name'])) continue;
            $payload = [
                'modifier_group_id' => $group->id,
                'name' => $m['name'],
                'price_delta' => $m['price_delta'] ?? 0,
                'display_order' => $m['display_order'] ?? 0,
                'active' => ! empty($m['active']),
            ];
            if (! empty($m['id']) && $existing = $group->allModifiers()->whereKey($m['id'])->first()) {
                $existing->update($payload);
                $keepIds[] = $existing->id;
            } else {
                $new = Modifier::create($payload);
                $keepIds[] = $new->id;
            }
        }
        $group->allModifiers()->whereNotIn('id', $keepIds)->delete();
    }

    protected function valid(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'min_select' => ['required', 'integer', 'min:0'],
            'max_select' => ['required', 'integer', 'min:1', 'gte:min_select'],
            'required' => ['sometimes', 'boolean'],
            'display_order' => ['nullable', 'integer'],
            'active' => ['sometimes', 'boolean'],
            'modifiers' => ['array'],
            'modifiers.*.id' => ['nullable', 'integer'],
            'modifiers.*.name' => ['nullable', 'string'],
            'modifiers.*.price_delta' => ['nullable', 'numeric'],
            'modifiers.*.display_order' => ['nullable', 'integer'],
            'modifiers.*.active' => ['sometimes', 'boolean'],
        ]);
    }
}
