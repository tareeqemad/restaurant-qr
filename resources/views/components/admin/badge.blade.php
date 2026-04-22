{{-- Badge Component --}}
@props(['type' => 'neutral'])

@php
    $classMap = [
        'primary'  => 'badge-role-system',
        'success'  => 'badge-status-active',
        'danger'   => 'badge-status-inactive',
        'warning'  => 'badge-warning',
        'info'     => 'badge-info',
        'neutral'  => 'badge-neutral',
        'custom'   => 'badge-role-custom',
        'users'    => 'badge-users-count',
        'permissions' => 'badge-permissions-count',
    ];
    $class = $classMap[$type] ?? $classMap['neutral'];
@endphp

<span class="{{ $class }}" {{ $attributes }}>{{ $slot }}</span>
