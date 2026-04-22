{{-- Admin Card Component --}}
@props(['class' => ''])

<div {{ $attributes->merge(['class' => 'general-card ' . $class]) }}>
    {{ $slot }}
</div>
