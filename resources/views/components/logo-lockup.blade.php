@props(['size' => 64, 'variant' => 'light'])

@php
$textClass = $variant === 'dark' ? 'text-white' : 'text-slate-900 dark:text-slate-100';
@endphp

<div {{ $attributes->class(['flex items-center gap-x-2.5']) }}>
    <x-logo :size="$size" :variant="$variant" class="shrink-0" />
    <span
        class="font-medium tracking-tight {{ $textClass }}"
        style="font-size: {{ round($size * 0.4375) }}px"
    >Stampy</span>
</div>
