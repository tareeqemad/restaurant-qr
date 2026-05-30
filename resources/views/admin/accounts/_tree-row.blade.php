{{-- Recursive partial: one account row + every descendant.
     Uses inline-style margin-right for indentation so the table
     stays a single tbody (no nested tables — preserves striping). --}}
<tr class="{{ $account->is_active ? '' : 'text-muted' }}">
    <td>
        <code style="font-size: .95rem;">{{ $account->code }}</code>
        @if($account->isProtected())
            <i class="bi bi-shield-lock-fill text-secondary ms-1" title="حساب نظامي — الكود مقفول"></i>
        @endif
    </td>
    <td>
        <span style="margin-inline-start: {{ $depth * 20 }}px;">
            @if($depth > 0)<i class="bi bi-arrow-return-right text-muted"></i>@endif
            <strong>{{ $account->name }}</strong>
        </span>
        @if($account->description)
            <small class="d-block text-muted">{{ Str::limit($account->description, 100) }}</small>
        @endif
    </td>
    <td><small>{{ $account->normalBalanceLabel() }}</small></td>
    <td>
        @if($account->is_active)
            <span class="badge bg-success"><i class="bi bi-check-circle"></i> نشط</span>
        @else
            <span class="badge bg-secondary"><i class="bi bi-pause-circle"></i> معطّل</span>
        @endif
        @if($account->isProtected())
            <span class="badge bg-light text-dark border ms-1"><i class="bi bi-shield-fill-check"></i> نظامي</span>
        @endif
    </td>
    <td class="text-center">
        @can('update', $account)
            <form method="POST" action="{{ route('admin.accounts.toggle', $account) }}" class="d-inline">
                @csrf @method('PATCH')
                <button class="btn btn-sm {{ $account->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}"
                        title="{{ $account->is_active ? 'تعطيل' : 'تشغيل' }}"
                        {{ $account->isProtected() && $account->is_active ? 'disabled' : '' }}>
                    <i class="bi {{ $account->is_active ? 'bi-pause-fill' : 'bi-play-fill' }}"></i>
                </button>
            </form>
            <a href="{{ route('admin.accounts.edit', $account) }}" class="btn btn-sm btn-primary" title="تعديل">
                <i class="bi bi-pencil"></i>
            </a>
        @endcan
        @can('delete', $account)
            @if($account->isDeletable())
                <form method="POST" action="{{ route('admin.accounts.destroy', $account) }}" class="d-inline"
                      onsubmit="return confirm('حذف حساب «{{ $account->name }}»؟ هذا الإجراء لا يُرجع.');">
                    @csrf @method('DELETE')
                    <button class="btn btn-sm btn-outline-danger" title="حذف">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
            @endif
        @endcan
    </td>
</tr>

{{-- Recurse into children. --}}
@foreach($account->children as $child)
    @include('admin.accounts._tree-row', ['account' => $child, 'depth' => $depth + 1])
@endforeach
