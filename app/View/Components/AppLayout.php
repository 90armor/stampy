<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    /**
     * @param  array<int, array{label: string, route?: string}>|null  $breadcrumbs  Trail
     *                                                                              shown in the topbar instead of the single `header` slot — every entry but the
     *                                                                              last renders as a link via its `route`; the last is the current page.
     */
    public function __construct(public ?array $breadcrumbs = null) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.app');
    }
}
