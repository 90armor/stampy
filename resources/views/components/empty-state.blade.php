{{-- heading: the title's element — h1 where the empty state is the whole page (an error page), so the page keeps one h1; same look either way. --}}
@props(['icon' => 'inbox', 'title', 'description' => null, 'heading' => 'h3'])

<div class="flex flex-col items-center justify-center py-16 px-6 text-center">
    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-slate-750 dark:text-slate-400">
        <x-icon :name="$icon" class="w-6 h-6" />
    </div>
    <{{ $heading }} class="mt-4 text-sm font-medium text-slate-900 dark:text-slate-100">{{ $title }}</{{ $heading }}>
    @if ($description)
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $description }}</p>
    @endif
    @isset($action)
        <div class="mt-6">
            {{ $action }}
        </div>
    @endisset
</div>
