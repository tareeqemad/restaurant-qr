{{-- Single tree-node + its recursive children (GFC tree convention).
     Receives:
       $account   the current Account model
       $byParent  collection grouped by parent_id (built once in index.blade)

     Markup contract (consumed by the JS in index.blade.php):
       <li class="parent_li?">
         <span data-id data-code data-name data-type data-balance data-active data-system>
            <i class="bi bi-dash-square|bi-plus-square"></i>   ← toggle icon (only when has children)
            <span class="acc-code-chip">CODE</span>
            <span class="acc-name-text">NAME</span>
            ... badges ...
         </span>
         <ul>… children …</ul>
       </li>

     Children are looked up from the in-memory map → zero extra DB queries.
--}}
@php
    $children = $byParent->get($account->id, collect())->sortBy([['display_order','asc'],['code','asc']]);
    $hasChildren = $children->isNotEmpty();
@endphp

<li class="{{ $hasChildren ? 'parent_li' : '' }}{{ $account->is_active ? '' : ' acc-inactive' }}"
    data-id="{{ $account->id }}" data-active="{{ $account->is_active ? '1' : '0' }}">
    <span
        data-id="{{ $account->id }}"
        data-code="{{ $account->code }}"
        data-name="{{ $account->name }}"
        data-type="{{ $account->type }}"
        data-balance="{{ $account->normal_balance }}"
        data-parent="{{ $account->parent_account_id ?? '' }}"
        data-active="{{ $account->is_active ? '1' : '0' }}"
        data-system="{{ $account->is_system ? '1' : '0' }}"
        title="{{ $account->code }} — {{ $account->name }}{{ $account->description ? ' • '.$account->description : '' }}">

        {{-- Toggle icon (only shown for parents) --}}
        @if($hasChildren)
            <i class="bi bi-dash-square"></i>
        @else
            <i class="bi bi-circle-fill" style="font-size:.4rem; color:#94a3b8; margin:0 4px;"></i>
        @endif

        {{-- Code chip --}}
        <span class="acc-code-chip">{{ $account->code }}</span>

        {{-- Name --}}
        <span class="acc-name-text">{{ $account->name }}</span>

        {{-- Meta: normal balance --}}
        <span class="acc-meta-text">· {{ $account->normalBalanceLabel() }}</span>

        {{-- Badges --}}
        @if($account->isProtected())
            <span class="acc-badge-sys" title="حساب نظامي — كوده مقفول">
                <i class="bi bi-shield-fill-check"></i> نظامي
            </span>
        @endif
        @if(! $account->is_active)
            <span class="acc-badge-off" title="معطّل">
                <i class="bi bi-pause-fill"></i> معطّل
            </span>
        @endif
    </span>

    @if($hasChildren)
        <ul>
            @foreach($children as $child)
                @include('admin.accounts._tree-node', ['account' => $child, 'byParent' => $byParent])
            @endforeach
        </ul>
    @endif
</li>
