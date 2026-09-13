@props(['direction' => 'up'])

@php
    $isUp = $direction === 'up';
@endphp

<p {{ $attributes->merge(['class' => 'inline-flex items-center gap-x-1 text-xs font-medium '.($isUp ? 'text-primary-600 dark:text-primary-400' : 'text-red-600 dark:text-red-400')]) }}>
    <span aria-hidden="true">{{ $isUp ? '↑' : '↓' }}</span>
    {{ $slot }}
</p>
