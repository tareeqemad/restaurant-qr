{{-- Modal Component --}}
@props(['id', 'title', 'icon' => null, 'size' => '', 'centered' => false])

<div class="modal fade" id="{{ $id }}" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog {{ $size }} {{ $centered ? 'modal-dialog-centered' : '' }}">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    @if($icon)<i class="bi {{ $icon }} me-1"></i>@endif
                    {{ $title }}
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            {{ $slot }}
        </div>
    </div>
</div>
