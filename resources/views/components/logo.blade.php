@props(['size' => 64, 'variant' => 'light'])

@php
$bg = $variant === 'dark' ? '#1c1917' : '#2f6850';
$stroke = $variant === 'dark' ? '#48cf99' : '#FFFFFF';
@endphp

<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" {{ $attributes }}>
    <rect width="64" height="64" rx="17" fill="{{ $bg }}"/>
    <g transform="translate(32,33)" stroke="{{ $stroke }}" stroke-width="4" stroke-linecap="round" fill="none">
        <path d="M -14 9.4 A 16.4 16.4 0 1 1 14 9.4"/>
        <path d="M -8.6 7.8 A 10.1 10.1 0 1 1 8.6 7.8"/>
        <path d="M -3.1 6.2 A 4.3 4.3 0 1 1 3.1 6.2"/>
        <path d="M 0 6.2 L 0 12.5"/>
    </g>
</svg>
