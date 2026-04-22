<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        protected OrderService $orders,
        protected InventoryService $inventory,
    ) {}

    public function view(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $cart = session('cart.'.$session->token, []);
        return view('customer.cart', compact('cart', 'session'));
    }

    public function add(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $data = $request->validate([
            'menu_item_id' => ['required', 'exists:menu_items,id'],
            'quantity' => ['required', 'numeric', 'min:1'],
            'modifier_ids' => ['array'],
            'modifier_ids.*' => ['exists:modifiers,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $item = MenuItem::findOrFail($data['menu_item_id']);
        if (! $item->is_available) {
            return back()->with('error', 'الصنف غير متوفر حالياً');
        }

        $cart = session('cart.'.$session->token, []);
        $modifiers = Modifier::whereIn('id', $data['modifier_ids'] ?? [])->get();

        $row = [
            'id' => uniqid(),
            'menu_item_id' => $item->id,
            'name' => $item->name,
            'image' => $item->imageUrl(),
            'quantity' => $data['quantity'],
            'unit_price' => (float) $item->price,
            'modifier_ids' => $data['modifier_ids'] ?? [],
            'modifiers' => $modifiers->map(fn($m) => ['id'=>$m->id, 'name'=>$m->name, 'price_delta'=>(float)$m->price_delta])->values()->toArray(),
            'modifiers_total' => (float) $modifiers->sum('price_delta'),
            'notes' => $data['notes'] ?? null,
        ];
        $row['subtotal'] = ($row['unit_price'] + $row['modifiers_total']) * $row['quantity'];

        $cart[] = $row;
        session()->put('cart.'.$session->token, $cart);

        return redirect()->route('customer.cart.view')->with('success', 'تمت إضافة الصنف للسلة');
    }

    public function update(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $data = $request->validate([
            'row_id' => ['required', 'string'],
            'quantity' => ['nullable', 'numeric', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $cart = session('cart.'.$session->token, []);
        foreach ($cart as $i => $row) {
            if ($row['id'] === $data['row_id']) {
                if (array_key_exists('quantity', $data) && $data['quantity'] !== null) {
                    $cart[$i]['quantity'] = $data['quantity'];
                    $cart[$i]['subtotal'] = ($row['unit_price'] + $row['modifiers_total']) * $data['quantity'];
                }
                if (array_key_exists('notes', $data)) {
                    $cart[$i]['notes'] = $data['notes'];
                }
                break;
            }
        }
        session()->put('cart.'.$session->token, $cart);
        return response()->json(['ok' => true]);
    }

    public function remove(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $data = $request->validate(['row_id' => ['required', 'string']]);
        $cart = collect(session('cart.'.$session->token, []))->reject(fn($r) => $r['id'] === $data['row_id'])->values()->toArray();
        session()->put('cart.'.$session->token, $cart);
        return back();
    }

    public function submit(Request $request)
    {
        $session = $request->attributes->get('table_session');
        $cart = session('cart.'.$session->token, []);
        if (empty($cart)) return back()->with('error', 'السلة فارغة');

        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:100'],
            'cover_count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $update = array_filter([
            'customer_name' => $data['customer_name'] ?? null,
            'cover_count' => $data['cover_count'] ?? null,
        ], fn($v) => $v !== null && $v !== '');
        if (! empty($update)) $session->update($update);

        $issues = $this->inventory->checkStockForOrderPreview($cart);
        if (! empty($issues)) {
            $msg = collect($issues)->map(fn($i) => "{$i['ingredient']}: متاح {$i['available']}، مطلوب {$i['required']}")->join(' | ');
            return back()->with('error', 'نقص في المخزون: '.$msg);
        }

        $order = $this->orders->createFromCart($session, $cart, null, $data['notes'] ?? null);

        session()->forget('cart.'.$session->token);

        return redirect()->route('customer.track')->with('success', "تم إرسال طلبك #{$order->number} — الجرسون سيعتمده قريباً");
    }
}
