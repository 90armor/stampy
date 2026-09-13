<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Engineering', 'Operations'] as $name) {
            Department::firstOrCreate(['name' => $name]);
        }
    }
}
