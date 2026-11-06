<?php

namespace App\Support;

/**
 * The "type it again" rule for a new password, on the Confirm field itself
 * (Phase 6i follow-up). Laravel's `confirmed` rule puts its message under the
 * new password ("The password field confirmation does not match."), on the
 * field that was typed correctly; this puts "The passwords don't match." under
 * Confirm, where the mismatch is fixed. Used by the profile's Change password
 * modal, the forced change after a temporary password and the reset link.
 * An empty Confirm is a mismatch too (`same` alone skips an empty field).
 */
class PasswordConfirmation
{
    public const MESSAGE = "The passwords don't match.";

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['password_confirmation' => ['required_with:password', 'same:password']];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'password_confirmation.required_with' => self::MESSAGE,
            'password_confirmation.same' => self::MESSAGE,
        ];
    }
}
