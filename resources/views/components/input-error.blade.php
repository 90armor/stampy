{{-- A field's validation messages. stampyErrors (resources/js/app.js) ties the
list to its field — aria-invalid="true" on the field, this list's id in its
aria-describedby — and moves focus to the first invalid field when a submit
fails (Phase 6e). The field is the one whose <label for> shares this list's
wrapper; `for` names it instead (space-separated ids) where one list serves
several fields, such as a row of dates. `alert`: a message with no field of
its own, announced as it appears. --}}
@props(['messages', 'for' => null, 'alert' => false])

@if ($messages)
    <ul data-input-error @if ($for) data-for="{{ $for }}" @endif @if ($alert) role="alert" @endif {{ $attributes->merge(['class' => 'text-sm text-red-600 space-y-1 dark:text-red-400']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
