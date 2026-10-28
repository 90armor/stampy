<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends DemoSeeder
{
    /**
     * Default local/dev credentials — must be changed after first login.
     * Never seeds a known password in production: DemoSeeder refuses to run
     * there (the first real admin is created as docs/GO_LIVE.md, step 3, says).
     */
    public function run(): void
    {
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrator',
                'username' => 'admin',
                'password' => Hash::make('password'),
            ]
        );

        $admin->syncRoles(['admin']);
    }
}
