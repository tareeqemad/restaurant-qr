<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Station;
use App\Support\AdminShell;
use App\Support\BranchContext;
use App\Support\MenuWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', MenuItem::class);

        return $this->page($request);
    }

    protected function page(Request $request, ?array $editor = null)
    {
        $user = auth()->user();
        $canUpdate = (bool) $user?->can('update', MenuItem::class);
        $canDelete = (bool) $user?->can('delete', MenuItem::class);

        return AdminShell::render('Admin/MenuCatalog/Categories', [
            'navigation' => MenuWorkspace::navigation(),
            // A restaurant has a short catalogue. Shipping it once keeps all
            // search, filters, editing and ordering inside the mounted app.
            'categories' => $this->categoryRows($canUpdate, $canDelete),
            'stations' => Station::orderByDesc('active')
                ->orderBy('display_order')
                ->get(['id', 'name', 'active']),
            'editor' => $editor,
            'can' => [
                'create' => (bool) $user?->can('create', MenuItem::class),
                'update' => $canUpdate,
            ],
            'urls' => [
                'index' => route('admin.categories.index'),
                'create' => route('admin.categories.create'),
                'store' => route('admin.categories.store'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', MenuItem::class);

        return $this->page($request, ['mode' => 'create', 'category' => null]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', MenuItem::class);
        $data = $this->valid($request);
        $data['display_order'] = Category::max('display_order') + 10;
        $data['image'] = $this->resolveImage($request, null);

        $category = Category::create($data)->load('station')->loadCount('menuItems');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'تم إنشاء القسم وإضافته إلى المنيو.',
                'category' => $this->serializeCategory($category),
            ], 201);
        }

        return redirect()->route('admin.categories.index')->with('success', 'تم إنشاء القسم');
    }

    public function edit(Request $request, Category $category)
    {
        $this->authorize('update', MenuItem::class);

        return $this->page($request, [
            'mode' => 'edit',
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'imageUrl' => $category->image ? $category->imageUrl() : null,
                'imageSource' => str_starts_with((string) $category->image, 'http') ? $category->image : null,
                'icon' => $category->icon,
                'color' => $category->color ?: '#166534',
                'stationId' => $category->default_station_id,
                'displayOrder' => (int) $category->display_order,
                'active' => (bool) $category->active,
                'updateUrl' => route('admin.categories.update', $category),
            ],
        ]);
    }

    public function update(Request $request, Category $category)
    {
        $this->authorize('update', MenuItem::class);
        $data = $this->valid($request);
        $resolved = $this->resolveImage($request, $category);

        if ($resolved !== null) {
            $data['image'] = $resolved;
        } elseif ($request->boolean('remove_image')) {
            $this->deleteUploadedImage($category->image);
            $data['image'] = null;
        }

        $category->update($data);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'تم حفظ التعديلات مباشرة.',
                'category' => $this->serializeCategory($category->fresh(['station'])->loadCount('menuItems')),
            ]);
        }

        return redirect()->route('admin.categories.index')->with('success', 'تم التحديث');
    }

    public function toggle(Request $request, Category $category): JsonResponse
    {
        $this->authorize('update', MenuItem::class);

        $category->update(['active' => ! $category->active]);

        return response()->json([
            'message' => $category->active
                ? 'أصبح القسم ظاهراً في المنيو.'
                : 'تم إخفاء القسم من المنيو دون حذف أصنافه.',
            'category' => $this->serializeCategory($category->fresh(['station'])->loadCount('menuItems')),
        ]);
    }

    public function move(Request $request, Category $category): JsonResponse
    {
        $this->authorize('update', MenuItem::class);

        $data = $request->validate([
            'direction' => ['required', Rule::in(['up', 'down'])],
        ]);

        DB::transaction(function () use ($category, $data) {
            $ordered = Category::query()
                ->orderBy('display_order')
                ->orderBy('id')
                ->get();
            $current = $ordered->search(fn (Category $row) => $row->is($category));
            $target = $data['direction'] === 'up' ? $current - 1 : $current + 1;

            if ($current === false || $target < 0 || $target >= $ordered->count()) {
                return;
            }

            $rows = $ordered->all();
            [$rows[$current], $rows[$target]] = [$rows[$target], $rows[$current]];

            // Normalize the sequence after every move so duplicate legacy
            // display orders never make the visual order unstable.
            foreach ($rows as $index => $row) {
                $row->updateQuietly(['display_order' => ($index + 1) * 10]);
            }
        });

        $user = auth()->user();

        return response()->json([
            'message' => 'تم تحديث ترتيب المنيو.',
            'categories' => $this->categoryRows(
                (bool) $user?->can('update', MenuItem::class),
                (bool) $user?->can('delete', MenuItem::class),
            ),
        ]);
    }

    public function destroy(Request $request, Category $category)
    {
        $this->authorize('delete', MenuItem::class);

        if ($category->menuItems()->exists()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'القسم مرتبط بأصناف. عطّله أو انقل الأصناف أولاً.',
                ], 422);
            }

            return back()->with('error', 'لا يمكن حذف قسم مرتبط بأصناف. عطّله أو انقل الأصناف أولاً.');
        }

        $this->deleteUploadedImage($category->image);
        $category->delete();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'تم حذف القسم الفارغ.',
                'id' => $category->id,
            ]);
        }

        return back()->with('success', 'تم الحذف');
    }

    /**
     * Resolve the image value from the request. Priority:
     *   1. Uploaded file (stores to storage/app/public/categories)
     *   2. External URL (stored verbatim — serves from original CDN)
     *   3. null (keeps existing)
     */
    protected function resolveImage(Request $request, ?Category $existing): ?string
    {
        if ($request->hasFile('image')) {
            $request->validate([
                'image' => ['file', 'image', 'max:5120'],
            ]);
            // Delete old uploaded file if any (keeps storage clean)
            $this->deleteUploadedImage($existing?->image);

            return $request->file('image')->store('categories', 'public');
        }
        if ($url = $request->input('image_url')) {
            $request->validate(['image_url' => ['url', 'max:500']]);
            $this->deleteUploadedImage($existing?->image);

            return $url;
        }

        // No new input — keep existing (return null signals "don't change")
        return null;
    }

    protected function valid(Request $request): array
    {
        $branchId = BranchContext::current();
        $station = Rule::exists('stations', 'id');
        if ($branchId) {
            $station->where(fn ($query) => $query->where('branch_id', $branchId));
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'default_station_id' => ['nullable', $station],
            'active' => ['sometimes', 'boolean'],
            'remove_image' => ['sometimes', 'boolean'],
        ]);

        // This flag controls file cleanup; it is not a categories column.
        unset($data['remove_image']);

        return $data;
    }

    protected function categoryRows(bool $canUpdate, bool $canDelete): array
    {
        return Category::with('station')
            ->withCount('menuItems')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Category $category) => $this->serializeCategory($category, $canUpdate, $canDelete))
            ->values()
            ->all();
    }

    protected function serializeCategory(
        Category $category,
        ?bool $canUpdate = null,
        ?bool $canDelete = null,
    ): array {
        $user = auth()->user();
        $canUpdate ??= (bool) $user?->can('update', MenuItem::class);
        $canDelete ??= (bool) $user?->can('delete', MenuItem::class);
        $itemsCount = (int) ($category->menu_items_count ?? $category->menuItems()->count());

        return [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'imageUrl' => $category->image ? $category->imageUrl() : null,
            'imageSource' => str_starts_with((string) $category->image, 'http') ? $category->image : null,
            'hasImage' => (bool) $category->image,
            'icon' => $category->icon,
            'color' => $category->color ?: '#166534',
            'stationId' => $category->default_station_id,
            'station' => $category->station?->name,
            'displayOrder' => (int) $category->display_order,
            'active' => (bool) $category->active,
            'itemsCount' => $itemsCount,
            'can' => [
                'update' => $canUpdate,
                'delete' => $canDelete && $itemsCount === 0,
            ],
            'urls' => [
                'edit' => route('admin.categories.edit', $category),
                'update' => route('admin.categories.update', $category),
                'destroy' => route('admin.categories.destroy', $category),
                'toggle' => route('admin.categories.toggle', $category),
                'move' => route('admin.categories.move', $category),
            ],
        ];
    }

    protected function deleteUploadedImage(?string $image): void
    {
        if ($image && ! str_starts_with($image, 'http')) {
            Storage::disk('public')->delete($image);
        }
    }
}
