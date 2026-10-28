<?php
// AUDIT-ONLY (Phase 6b screenshots): a password-reset token for Kyaw Kyaw Naing, to open the reset page.
echo 'TOKEN='.Illuminate\Support\Facades\Password::createToken(App\Models\User::where('username', 'kyaw.kyaw.naing')->first())."\n";
