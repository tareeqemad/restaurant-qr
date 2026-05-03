@extends('portal.layout')
@section('title', 'القائمة - '.$branch->name)

@section('content')
<div class="pf-section">
    <div class="pom-header">
        <div>
            <a href="{{ route('portal.order.branches') }}" class="pom-back" title="تغيير الفرع">
                <i class="bi bi-arrow-right"></i>
            </a>
            <h1 class="pf-h1 d-inline-block">{{ $branch->name }}</h1>
        </div>
        @if($cartCount > 0)
            <a href="{{ route('portal.order.checkout', $branch) }}" class="pom-cart-pill">
                <i class="bi bi-bag-fill"></i>
                <span>{{ $cartCount }} صنف</span>
                <strong>{{ number_format($cartTotal, 2) }} ر.ع</strong>
            </a>
        @endif
    </div>

    @if($categories->isEmpty())
        <div class="pf-section pf-section--centered">
            <i class="bi bi-emoji-frown" style="font-size:3rem;color:#9ca3af;"></i>
            <p class="pf-sub mt-3">القائمة فارغة لهذا الفرع حالياً.</p>
        </div>
    @else
        @foreach($categories as $cat)
            <section class="pom-cat">
                <h2 class="pom-cat__title">
                    @if($cat->icon)<i class="bi {{ $cat->icon }}"></i>@endif
                    {{ $cat->name }}
                </h2>
                <div class="pom-items">
                    @foreach($cat->menuItems as $item)
                        <article class="pom-item">
                            <img src="{{ $item->imageUrl() }}" alt="{{ $item->name }}" class="pom-item__img" loading="lazy">
                            <div class="pom-item__body">
                                <h3>{{ $item->name }}</h3>
                                @if($item->description)
                                    <p class="pom-item__desc">{{ $item->description }}</p>
                                @endif
                                <div class="pom-item__foot">
                                    <span class="pom-item__price">{{ number_format($item->price, 2) }} ر.ع</span>
                                    <form action="{{ route('portal.order.cart.add', $branch) }}" method="POST" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="menu_item_id" value="{{ $item->id }}">
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit" class="pom-add" title="أضف">
                                            <i class="bi bi-plus-lg"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif

    @if(! empty($cart))
        <div class="pom-cart-bar">
            <div class="pom-cart-bar__items">
                @foreach($cart as $row)
                    <div class="pom-cart-row">
                        <span class="pom-cart-row__qty">×{{ $row['quantity'] }}</span>
                        <span class="pom-cart-row__name">{{ $row['name'] }}</span>
                        <span class="pom-cart-row__sub">{{ number_format($row['subtotal'], 2) }}</span>
                        <form action="{{ route('portal.order.cart.remove', $branch) }}" method="POST" class="d-inline">
                            @csrf
                            <input type="hidden" name="row_id" value="{{ $row['id'] }}">
                            <button type="submit" class="pom-cart-row__del" title="حذف">
                                <i class="bi bi-x"></i>
                            </button>
                        </form>
                    </div>
                @endforeach
            </div>
            <a href="{{ route('portal.order.checkout', $branch) }}" class="pom-checkout-btn">
                <span>المتابعة للدفع</span>
                <strong>{{ number_format($cartTotal, 2) }} ر.ع</strong>
                <i class="bi bi-arrow-left"></i>
            </a>
        </div>
    @endif
</div>

@push('styles')
<style>
    .pom-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:16px; flex-wrap:wrap; }
    .pom-back { display:inline-flex; align-items:center; justify-content:center;
                width:36px;height:36px;border-radius:50%; background:#fff; color:var(--ink);
                text-decoration:none; border:1px solid #e5e7eb; margin-inline-end:8px; }
    .pom-cart-pill {
        display:inline-flex; align-items:center; gap:8px;
        padding:10px 16px; border-radius:999px;
        background: var(--green-primary); color:#fff; text-decoration:none;
        font-weight:700; font-size:.9rem;
        box-shadow:0 4px 12px -3px rgba(46,125,50,.4);
    }

    .pom-cat { margin-bottom:24px; }
    .pom-cat__title { font-size:1.05rem; font-weight:800; color:var(--ink);
                      margin-bottom:10px; padding-bottom:6px;
                      border-bottom: 2px solid var(--green-primary); display:inline-block; }
    .pom-cat__title i { color: var(--green-primary); margin-inline-end:6px; }

    .pom-items { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:12px; }
    .pom-item {
        display:flex; gap:12px; padding:12px;
        background:#fff; border:1px solid #e5e7eb; border-radius:12px;
        transition:border-color .15s, transform .15s;
    }
    .pom-item:hover { border-color:rgba(46,125,50,.3); transform:translateY(-1px); }
    .pom-item__img { width:88px;height:88px;border-radius:10px;object-fit:cover; flex-shrink:0; }
    .pom-item__body { flex:1; min-width:0; display:flex; flex-direction:column; }
    .pom-item__body h3 { margin:0 0 4px; font-size:.95rem; font-weight:800; color:var(--ink); }
    .pom-item__desc { font-size:.78rem; color:#6b7280; margin:0 0 8px; line-clamp:2; -webkit-line-clamp:2;
                      overflow:hidden; display:-webkit-box; -webkit-box-orient:vertical; }
    .pom-item__foot { margin-top:auto; display:flex; align-items:center; justify-content:space-between; gap:8px; }
    .pom-item__price { font-weight:800; color:var(--green-primary); font-size:.95rem; }
    .pom-add { width:36px;height:36px; border-radius:50%; border:0;
               background:var(--green-primary); color:#fff; cursor:pointer;
               display:inline-flex; align-items:center; justify-content:center;
               transition:transform .12s, background .12s; }
    .pom-add:hover { transform:scale(1.08); background:var(--green-soft); }

    /* Sticky cart bar at the bottom */
    .pom-cart-bar {
        position:sticky; bottom:0;
        margin-top:24px;
        background:#fff; border:1px solid #e5e7eb; border-radius:14px;
        padding:14px;
        box-shadow:0 -8px 24px -8px rgba(15,23,42,.12);
    }
    .pom-cart-bar__items { max-height:200px; overflow-y:auto; margin-bottom:10px; padding-inline-end:6px; }
    .pom-cart-row { display:flex; align-items:center; gap:10px; padding:6px 0; border-bottom:1px solid #f3f4f6; font-size:.85rem; }
    .pom-cart-row:last-child { border-bottom:0; }
    .pom-cart-row__qty { font-weight:700; color:var(--green-primary); min-width:30px; }
    .pom-cart-row__name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .pom-cart-row__sub { font-weight:700; color:var(--ink); }
    .pom-cart-row__del { background:transparent; border:0; color:#dc2626; cursor:pointer; font-size:1.1rem; padding:4px; }

    .pom-checkout-btn {
        display:flex; align-items:center; gap:12px; justify-content:space-between;
        width:100%; padding:14px 18px; border-radius:10px;
        background:var(--green-primary); color:#fff;
        text-decoration:none; font-weight:800;
    }
    .pom-checkout-btn:hover { background:var(--green-dark); }
</style>
@endpush
@endsection
