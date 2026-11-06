<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-help-popover> (Phase 6i follow-up): opens on a click or tap only, names
 * what it controls, and is a live region that is empty while closed. The
 * behaviour — Escape, a click outside, focus back on the button — is
 * tests/browser/focus.mjs's.
 */
class HelpPopoverTest extends TestCase
{
    public function test_the_button_opens_on_click_only_and_names_its_popover(): void
    {
        $html = Blade::render('<x-help-popover label="About this">Ask HR.</x-help-popover>');

        $this->assertMatchesRegularExpression('/<button\b[^>]*aria-label="About this"/s', $html);
        preg_match('/aria-controls="([^"]+)"/', $html, $controls);
        $this->assertNotEmpty($controls);
        $this->assertStringContainsString('id="'.$controls[1].'" role="status"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('x-bind:aria-expanded="open.toString()"', $html);
        $this->assertStringContainsString('x-on:click="open = ! open"', $html);
        $this->assertStringContainsString('x-on:keydown.escape=', $html);
        $this->assertStringContainsString('x-on:click.outside="open = false"', $html);
        // Never hover-only.
        $this->assertDoesNotMatchRegularExpression('/mouseenter|mouseover|group-hover:/', $html);
        // The text is inside the live region only while open.
        $this->assertMatchesRegularExpression('/role="status"[^>]*>\s*<template x-if="open">.*Ask HR\..*<\/template>/s', $html);
        // A 44px target on a phone.
        $this->assertStringContainsString('max-sm:h-11 max-sm:w-11', $html);
    }
}
