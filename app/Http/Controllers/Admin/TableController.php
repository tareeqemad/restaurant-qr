<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Table;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;

class TableController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Table::class);
        $tables = Table::with('activeSession')->orderBy('number')->paginate(30);
        $total    = Table::count();
        $occupied = Table::has('activeSession')->count();
        $stats = [
            'total'    => $total,
            'occupied' => $occupied,
            'free'     => max(0, $total - $occupied),
            'capacity' => (int) Table::sum('capacity'),
        ];
        return view('admin.tables.index', compact('tables', 'stats'));
    }

    public function create()
    {
        $this->authorize('create', Table::class);
        return view('admin.tables.create');
    }

    public function store(Request $request)
    {
        $this->authorize('create', Table::class);
        $data = $this->valid($request);
        $table = Table::create($data);
        return redirect()->route('admin.tables.index')->with('success', 'تم إنشاء الطاولة');
    }

    public function edit(Table $table)
    {
        $this->authorize('update', $table);
        return view('admin.tables.edit', compact('table'));
    }

    public function update(Request $request, Table $table)
    {
        $this->authorize('update', $table);
        $table->update($this->valid($request, $table->id));
        return redirect()->route('admin.tables.index')->with('success', 'تم التحديث');
    }

    public function destroy(Table $table)
    {
        $this->authorize('delete', $table);
        $table->delete();
        return back()->with('success', 'تم الحذف');
    }

    public function qr(Table $table)
    {
        $renderer = new ImageRenderer(
            new RendererStyle(300, 2),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $svg = $writer->writeString($table->qrUrl());
        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }

    public function qrPrint(Table $table)
    {
        $renderer = new ImageRenderer(
            new RendererStyle(400, 2),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $svg = $writer->writeString($table->qrUrl());
        return view('admin.tables.qr-print', ['table' => $table, 'svg' => $svg]);
    }

    protected function valid(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'number' => ['required', 'string', 'max:16', \Illuminate\Validation\Rule::unique('tables')->ignore($id)],
            'name' => ['nullable', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'zone' => ['nullable', 'string', 'max:64'],
            'status' => ['required', \Illuminate\Validation\Rule::in(['available','occupied','reserved','out_of_service'])],
            'active' => ['sometimes', 'boolean'],
        ]);
    }
}
