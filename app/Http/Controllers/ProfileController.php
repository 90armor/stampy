<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();

        // Same relations Employees\Show eager-loads for the identical
        // Details card this page now also renders (x-employee-details-card).
        $user->employee?->load(['department', 'position', 'manager']);

        return view('profile.edit', [
            'user' => $user,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        // Name is HR data once a user is linked to an employee record —
        // admins own it (see Employees\FormModal::save(), which keeps it in
        // sync), and the form renders it read-only. That's presentation
        // only, so a submitted 'name' — however it got there — is dropped
        // here rather than trusted, whether it matches the current value or
        // not.
        if ($user->employee !== null) {
            unset($data['name']);
        }

        $user->fill($data)->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }
}
