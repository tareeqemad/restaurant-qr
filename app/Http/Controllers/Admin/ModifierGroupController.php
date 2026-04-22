<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModifierGroupController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', MenuItem::class);
        $groups = ModifierGroup::with('allModifiers')->orderBy('display_order')->paginate(20);

        // Summary stats for the rail at top
        $stats = [
            'total_groups' => ModifierGroup::count(),
            'required'     => ModifierGroup::where('required', true)->count(),
            'optional'     => ModifierGroup::where('required', false)->count(),
            'total_options'=> Modifier::count(),
        ];

        return view('admin.modifiers.index', compact('groups', 'stats'));
    }

    public function create() { $this->authorize('create', MenuItem::class); return view('admin.modifiers.create'); }

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

    public function edit(ModifierGroup $modifier)
    {
        $this->authorize('update', MenuItem::class);
        $modifier->load('allModifiers');
        return view('admin.modifiers.edit', ['group' => $modifier]);
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
                'name_en' => $m['name_en'] ?? null,
                'price_delta' => $m['price_delta'] ?? 0,
                'display_order' => $m['display_order'] ?? 0,
                'active' => ! empty($m['active']),
            ];
            if (! empty($m['id'])) {
                Modifier::where('id', $m['id'])->update($payload);
                $keepIds[] = $m['id'];
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
            'name_en' => ['nullable', 'string', 'max:255'],
            'min_select' => ['required', 'integer', 'min:0'],
            'max_select' => ['required', 'integer', 'min:1'],
            'required' => ['sometimes', 'boolean'],
            'display_order' => ['nullable', 'integer'],
            'active' => ['sometimes', 'boolean'],
            'modifiers' => ['array'],
            'modifiers.*.id' => ['nullable', 'integer'],
            'modifiers.*.name' => ['nullable', 'string'],
            'modifiers.*.name_en' => ['nullable', 'string'],
            'modifiers.*.price_delta' => ['nullable', 'numeric'],
            'modifiers.*.display_order' => ['nullable', 'integer'],
            'modifiers.*.active' => ['sometimes', 'boolean'],
        ]);
    }
}
