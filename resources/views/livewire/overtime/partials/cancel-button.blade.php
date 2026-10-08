{{-- Cancel an overtime request from the Overtime page, behind the page's
confirm dialog. Only rendered where OvertimePolicy::cancel allows it. --}}
<button
    type="button"
    @click="$dispatch('confirm-dialog-overtime', {
        title: 'Cancel this overtime request?',
        message: @js('Cancel your overtime request for '.$request->displayDateAndWindow().'?'.($request->status->value === 'approved' ? ' Anything it has credited is taken back.' : '')),
        confirmText: 'Cancel request',
        {{-- Never a bare "Cancel" to dismiss: here it means cancelling the request. --}}
        cancelText: 'Keep request',
        method: 'cancel',
        args: [{{ $request->id }}],
    })"
    class="inline-flex h-control items-center rounded-lg px-3 text-sm font-medium text-red-700 ring-1 ring-inset ring-slate-border transition hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-red-400 dark:hover:bg-red-900/30"
    aria-label="Cancel overtime request, {{ $request->displayDateAndWindow() }}"
>Cancel</button>
