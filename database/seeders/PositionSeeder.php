<?php

namespace Database\Seeders;

use App\Models\Position;
use Illuminate\Database\Seeder;

class PositionSeeder extends Seeder
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
