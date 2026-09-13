<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EmployeeSeeder extends Seeder
{
    public function run(): void
    {
        $engineering = Department::where('name', 'Engineering')->firstOrFail();
        $operations = Department::where('name', 'Operations')->firstOrFail();
        $positions = Position::pluck('id', 'name');

        $employees = [
            [
                'employee_code' => 'EMP-0001',
                'full_name' => 'Aye Aye Mon',
                'department_id' => $engineering->id,
                'position' => 'Engineering Manager',
                'join_date' => '2023-02-01',
                'device_user_id' => '1001',
                'role' => 'manager',
                'create_user' => true,
            ],
            [
                'employee_code' => 'EMP-0002',
                'full_name' => 'Kyaw Kyaw Naing',
                'department_id' => $engineering->id,
                'position' => 'Software Engineer',
                'join_date' => '2023-06-15',
                'device_user_id' => '1002',
                'role' => 'employee',
                'create_user' => true,
            ],
            [
                'employee_code' => 'EMP-0003',
                'full_name' => 'Su Su Hlaing',
                'department_id' => $engineering->id,
                'position' => 'QA Engineer',
                'join_date' => '2024-01-10',
                'device_user_id' => '1003',
                'role' => null,
                'create_user' => false,
            ],
            [
                'employee_code' => 'EMP-0004',
                'full_name' => 'Zaw Zaw Htet',
                'department_id' => $operations->id,
                'position' => 'Operations Manager',
                'join_date' => '2022-11-20',
                'device_user_id' => '1004',
                'role' => 'manager',
                'create_user' => true,
            ],
            [
                'employee_code' => 'EMP-0005',
                'full_name' => 'Thida Win',
                'department_id' => $operations->id,
                'position' => 'Office Coordinator',
                'join_date' => '2024-04-05',
                'device_user_id' => '1005',
                'role' => null,
                'create_user' => false,
            ],
        ];

        foreach ($employees as $data) {
            $userId = null;

            if ($data['create_user']) {
                $email = strtolower(str_replace(' ', '.', $data['full_name'])).'@example.com';

                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => $data['full_name'],
                        'password' => Hash::make('password'),
                        'email_verified_at' => now(),
                    ]
                );

                $user->syncRoles([$data['role']]);
                $userId = $user->id;
            }

            Employee::firstOrCreate(
                ['employee_code' => $data['employee_code']],
                [
                    'user_id' => $userId,
                    'full_name' => $data['full_name'],
                    'department_id' => $data['department_id'],
                    'position_id' => $positions[$data['position']],
                    'join_date' => $data['join_date'],
                    'device_user_id' => $data['device_user_id'],
                    'status' => 'active',
                ]
            );
        }
    }
}
