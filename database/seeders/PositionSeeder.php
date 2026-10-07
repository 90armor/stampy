<?php

namespace Database\Seeders;

use App\Models\Position;

class PositionSeeder extends DemoSeeder
{
    public function run(): void
    {
        foreach ([
            'Engineering Manager',
            'Software Engineer',
            'QA Engineer',
            'Operations Manager',
            'Office Coordinator',
        ] as $name) {
            Position::firstOrCreate(['name' => $name]);
        }
    }
}
