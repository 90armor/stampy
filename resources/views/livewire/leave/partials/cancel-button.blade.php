{{-- Cancel a leave from Time off, behind the page's confirm dialog. Only
rendered where LeavePolicy::cancel allows it. --}}
<button
    type="button"
    @click="$dispatch('confirm-dialog-time-off', {
        title: 'Cancel this leave?',
        message: @js('Cancel your '.$leave->leaveType->name.' leave for '.$label.'? Its days go back to your balance.'),
        confirmText: 'Cancel leave',
        {{-- Never a bare "Cancel" to dismiss: on Time off it means cancelling leave. --}}
        cancelText: 'Keep leave',
        method: 'cancel',
        args: [{{ $leave->id }}],
    })"
    class="inline-flex h-control items-center rounded-lg px-3 text-sm font-medium text-red-700 ring-1 ring-inset ring-slate-border transition hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-red-400 dark:hover:bg-red-900/30"
    aria-label="Cancel {{ $leave->leaveType->name }} leave, {{ $label }}"
>Cancel</button>
