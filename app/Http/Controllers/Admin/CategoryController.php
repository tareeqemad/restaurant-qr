<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Station;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', MenuItem::class);
        $categories = Category::with('station')->withCount('menuItems')->orderBy('display_order')->paginate(20);
        $stats = [
            'total'      => Category::count(),
            'active'     => Category::where('active', true)->count(),
            'with_items' => Category::has('menuItems')->count(),
            'empty'      => Category::doesntHave('menuItems')->count(),
        ];
        return view('admin.categories.index', compact('categories', 'stats'));
    }

    public function create()
    {
        $this->authorize('create', MenuItem::class);
        return view('admin.categories.create', ['stations' => Station::where('active', true)->get()]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', MenuItem::class);
        $data = $this->valid($request);
        $data['image'] = $this->resolveImage($request, null);
        Category::create($data);
        return redirect()->route('admin.categories.index')->with('success', 'تم إنشاء القسم');
    }

    public function edit(Category $category)
    {
        $this->authorize('update', MenuItem::class);
        return view('admin.categories.edit', [
            'category' => $category,
            'stations' => Station::where('active', true)->get(),
        ]);
    }

    public function update(Request $request, Category $category)
    {
        $this->authorize('update', MenuItem::class);
        $data = $this->valid($request);
        $resolved = $this->resolveImage($request, $category);
        // Only overwrite image if the user provided new input (upload or URL)
        // — otherwise preserve the existing value.
        if ($resolved !== null) $data['image'] = $resolved;
        $category->update($data);
        return redirect()->route('admin.categories.index')->with('success', 'تم التحديث');
    }

    public function destroy(Category $category)
    {
        $this->authorize('delete', MenuItem::class);
        $category->delete();
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
            if ($existing && $existing->image && !str_starts_with($existing->image, 'http')) {
                \Storage::disk('public')->delete($existing->image);
            }
            return $request->file('image')->store('categories', 'public');
        }
        if ($url = $request->input('image_url')) {
            $request->validate(['image_url' => ['url', 'max:500']]);
            return $url;
        }
        // No new input — keep existing (return null signals "don't change")
        return null;
    }

    protected function valid(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'description_en' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:7'],
            'default_station_id' => ['nullable', 'exists:stations,id'],
            'display_order' => ['nullable', 'integer'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
