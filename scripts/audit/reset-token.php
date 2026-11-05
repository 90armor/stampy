<?php

use App\Models\User;
use Illuminate\Support\Facades\Password;

// AUDIT-ONLY (Phase 6b screenshots): a password-reset token for Kyaw Kyaw Naing, to open the reset page.
echo 'TOKEN='.Password::createToken(User::where('username', 'kyaw.kyaw.naing')->first())."\n";
