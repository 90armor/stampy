<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Required only when the user is free to set it. A user linked to
            // an employee record sees it read-only and can't submit a form
            // without it either way, but the field's actual content is never
            // trusted — ProfileController::update() drops it regardless of
            // what's submitted, so 'nullable' here just avoids failing
            // validation over a field this request is going to ignore.
            'name' => $this->user()->employee !== null
                ? ['nullable', 'string', 'max:255']
                : ['required', 'string', 'max:255'],
            // Nullable: many employees have no email at all (users.email is
            // nullable — see LoginRequest's own doc comment on the same
            // point) and sign in with their username instead. Still unique
            // and a valid address when one is given.
            'email' => [
                'nullable',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];
    }
}
