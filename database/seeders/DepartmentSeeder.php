<?php

namespace Database\Seeders;

use App\Models\Department;

class DepartmentSeeder extends DemoSeeder
{
    public function run(): void
    {
        foreach (['Engineering', 'Operations'] as $name) {
            Department::firstOrCreate(['name' => $name]);
        }
    }
}
