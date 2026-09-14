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

        $employees = $this->definitions($engineering->id, $operations->id);

        $idsByCode = [];

        foreach ($employees as $data) {
            $userId = null;

            if ($data['create_user']) {
                $username = strtolower(str_replace(' ', '.', $data['full_name']));
                $email = $username.'@example.com';

                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name' => $data['full_name'],
                        'username' => $username,
                        'password' => Hash::make('password'),
                        'email_verified_at' => now(),
                    ]
                );

                $user->syncRoles([$data['role']]);
                $userId = $user->id;
            }

            $employee = Employee::firstOrCreate(
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

            $idsByCode[$data['employee_code']] = $employee->id;
        }

        // Second pass: manager_code references another row's employee_code,
        // which only resolves to an id once every employee above exists.
        foreach ($employees as $data) {
            if ($data['manager_code'] === null) {
                continue;
            }

            Employee::where('employee_code', $data['employee_code'])
                ->update(['manager_id' => $idsByCode[$data['manager_code']]]);
        }
    }

    /**
     * 35 employees across the existing departments/positions — enough to
     * exercise list/pagination/filter views (Phase 2.4). A few people act as
     * manager_id anchors (the two named department managers, plus 3 informal
     * team leads holding a regular position) so the reporting tree has more
     * than one level; everyone else reports to one of those five. Roughly
     * half get a login account, so "employee with no account" stays
     * represented at scale, not just as the two original examples.
     *
     * @return array<int, array{employee_code: string, full_name: string, department_id: int, position: string, join_date: string, device_user_id: string, role: ?string, create_user: bool, manager_code: ?string}>
     */
    private function definitions(int $engineeringId, int $operationsId): array
    {
        return [
            // --- Original 5 — identity columns kept exactly as before; only
            // manager_id is new (introduced this phase). ---
            ['employee_code' => 'EMP-0001', 'full_name' => 'Aye Aye Mon', 'department_id' => $engineeringId, 'position' => 'Engineering Manager', 'join_date' => '2023-02-01', 'device_user_id' => '1001', 'role' => 'manager', 'create_user' => true, 'manager_code' => null],
            ['employee_code' => 'EMP-0002', 'full_name' => 'Kyaw Kyaw Naing', 'department_id' => $engineeringId, 'position' => 'Software Engineer', 'join_date' => '2023-06-15', 'device_user_id' => '1002', 'role' => 'employee', 'create_user' => true, 'manager_code' => 'EMP-0001'],
            ['employee_code' => 'EMP-0003', 'full_name' => 'Su Su Hlaing', 'department_id' => $engineeringId, 'position' => 'QA Engineer', 'join_date' => '2024-01-10', 'device_user_id' => '1003', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0001'],
            ['employee_code' => 'EMP-0004', 'full_name' => 'Zaw Zaw Htet', 'department_id' => $operationsId, 'position' => 'Operations Manager', 'join_date' => '2022-11-20', 'device_user_id' => '1004', 'role' => 'manager', 'create_user' => true, 'manager_code' => null],
            ['employee_code' => 'EMP-0005', 'full_name' => 'Thida Win', 'department_id' => $operationsId, 'position' => 'Office Coordinator', 'join_date' => '2024-04-05', 'device_user_id' => '1005', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0004'],

            // --- Engineering: two Software-Engineer team leads under Aye Aye
            // Mon, each with their own reports. ---
            ['employee_code' => 'EMP-0006', 'full_name' => 'Htet Htet Oo', 'department_id' => $engineeringId, 'position' => 'Software Engineer', 'join_date' => '2022-03-10', 'device_user_id' => '1006', 'role' => 'employee', 'create_user' => true, 'manager_code' => 'EMP-0001'],
            ['employee_code' => 'EMP-0007', 'full_name' => 'Wai Yan Aung', 'department_id' => $engineeringId, 'position' => 'Software Engineer', 'join_date' => '2022-05-18', 'device_user_id' => '1007', 'role' => 'employee', 'create_user' => true, 'manager_code' => 'EMP-0001'],
            ['employee_code' => 'EMP-0008', 'full_name' => 'Nay Chi Win', 'department_id' => $engineeringId, 'position' => 'Software Engineer', 'join_date' => '2023-01-09', 'device_user_id' => '1008', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0006'],
            ['employee_code' => 'EMP-0009', 'full_name' => 'Moe Moe Khaing', 'department_id' => $engineeringId, 'position' => 'Software Engineer', 'join_date' => '2023-02-14', 'device_user_id' => '1009', 'role' => 'employee', 'create_user' => true, 'manager_code' => 'EMP-0006'],
            ['employee_code' => 'EMP-0010', 'full_name' => 'Thura Kyaw', 'department_id' => $engineeringId, 'position' => 'Software Engineer', 'join_date' => '2023-04-22', 'device_user_id' => '1010', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0006'],
            ['employee_code' => 'EMP-0011', 'full_name' => 'Ei Ei Phyo', 'department_id' => $engineeringId, 'position' => 'Software Engineer', 'join_date' => '2023-07-03', 'device_user_id' => '1011', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0006'],
            ['employee_code' => 'EMP-0012', 'full_name' => 'Kaung Kaung Htun', 'department_id' => $engineeringId, 'position' => 'Software Engineer', 'join_date' => '2023-09-11', 'device_user_id' => '1012', 'role' => 'employee', 'create_user' => true, 'manager_code' => 'EMP-0006'],
            ['employee_code' => 'EMP-0013', 'full_name' => 'Yamin Thu', 'department_id' => $engineeringId, 'position' => 'Software Engineer', 'join_date' => '2023-11-27', 'device_user_id' => '1013', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0007'],
            ['employee_code' => 'EMP-0014', 'full_name' => 'Phyo Phyo Aye', 'department_id' => $engineeringId, 'position' => 'Software Engineer', 'join_date' => '2024-01-15', 'device_user_id' => '1014', 'role' => 'employee', 'create_user' => true, 'manager_code' => 'EMP-0007'],
            ['employee_code' => 'EMP-0015', 'full_name' => 'Zin Mar Aung', 'department_id' => $engineeringId, 'position' => 'Software Engineer', 'join_date' => '2024-03-08', 'device_user_id' => '1015', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0007'],
            ['employee_code' => 'EMP-0016', 'full_name' => 'Aung Aung Myint', 'department_id' => $engineeringId, 'position' => 'Software Engineer', 'join_date' => '2024-05-20', 'device_user_id' => '1016', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0007'],
            ['employee_code' => 'EMP-0017', 'full_name' => 'Hnin Hnin Wai', 'department_id' => $engineeringId, 'position' => 'Software Engineer', 'join_date' => '2024-08-02', 'device_user_id' => '1017', 'role' => 'employee', 'create_user' => true, 'manager_code' => 'EMP-0007'],
            ['employee_code' => 'EMP-0018', 'full_name' => 'Kyaw Zin Latt', 'department_id' => $engineeringId, 'position' => 'Software Engineer', 'join_date' => '2025-01-13', 'device_user_id' => '1018', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0007'],

            // --- Engineering: QA, reporting directly to Aye Aye Mon. ---
            ['employee_code' => 'EMP-0019', 'full_name' => 'Su Myat Noe', 'department_id' => $engineeringId, 'position' => 'QA Engineer', 'join_date' => '2023-05-06', 'device_user_id' => '1019', 'role' => 'employee', 'create_user' => true, 'manager_code' => 'EMP-0001'],
            ['employee_code' => 'EMP-0020', 'full_name' => 'Thet Paing Oo', 'department_id' => $engineeringId, 'position' => 'QA Engineer', 'join_date' => '2023-10-19', 'device_user_id' => '1020', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0001'],
            ['employee_code' => 'EMP-0021', 'full_name' => 'Khin Khin Lay', 'department_id' => $engineeringId, 'position' => 'QA Engineer', 'join_date' => '2024-02-25', 'device_user_id' => '1021', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0001'],
            ['employee_code' => 'EMP-0022', 'full_name' => 'Myo Min Htike', 'department_id' => $engineeringId, 'position' => 'QA Engineer', 'join_date' => '2024-09-30', 'device_user_id' => '1022', 'role' => 'employee', 'create_user' => true, 'manager_code' => 'EMP-0001'],

            // --- Operations: one Office-Coordinator team lead under Zaw Zaw
            // Htet, plus direct reports. ---
            ['employee_code' => 'EMP-0023', 'full_name' => 'Ohnmar Kyaw', 'department_id' => $operationsId, 'position' => 'Office Coordinator', 'join_date' => '2022-06-01', 'device_user_id' => '1023', 'role' => 'employee', 'create_user' => true, 'manager_code' => 'EMP-0004'],
            ['employee_code' => 'EMP-0024', 'full_name' => 'Zaw Min Tun', 'department_id' => $operationsId, 'position' => 'Office Coordinator', 'join_date' => '2022-09-14', 'device_user_id' => '1024', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0023'],
            ['employee_code' => 'EMP-0025', 'full_name' => 'Shwe Yi Aung', 'department_id' => $operationsId, 'position' => 'Office Coordinator', 'join_date' => '2023-01-27', 'device_user_id' => '1025', 'role' => 'employee', 'create_user' => true, 'manager_code' => 'EMP-0023'],
            ['employee_code' => 'EMP-0026', 'full_name' => 'Htay Htay Win', 'department_id' => $operationsId, 'position' => 'Office Coordinator', 'join_date' => '2023-04-15', 'device_user_id' => '1026', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0023'],
            ['employee_code' => 'EMP-0027', 'full_name' => 'Kyaw Swar Oo', 'department_id' => $operationsId, 'position' => 'Office Coordinator', 'join_date' => '2023-08-08', 'device_user_id' => '1027', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0023'],
            ['employee_code' => 'EMP-0028', 'full_name' => 'Nandar Hlaing', 'department_id' => $operationsId, 'position' => 'Office Coordinator', 'join_date' => '2023-12-01', 'device_user_id' => '1028', 'role' => 'employee', 'create_user' => true, 'manager_code' => 'EMP-0023'],
            ['employee_code' => 'EMP-0029', 'full_name' => 'Pyae Phyo Kyaw', 'department_id' => $operationsId, 'position' => 'Office Coordinator', 'join_date' => '2024-02-19', 'device_user_id' => '1029', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0004'],
            ['employee_code' => 'EMP-0030', 'full_name' => 'Cho Cho Lwin', 'department_id' => $operationsId, 'position' => 'Office Coordinator', 'join_date' => '2024-06-03', 'device_user_id' => '1030', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0004'],
            ['employee_code' => 'EMP-0031', 'full_name' => 'Aung Kyaw Moe', 'department_id' => $operationsId, 'position' => 'Office Coordinator', 'join_date' => '2024-09-25', 'device_user_id' => '1031', 'role' => 'employee', 'create_user' => true, 'manager_code' => 'EMP-0004'],
            ['employee_code' => 'EMP-0032', 'full_name' => 'Thida Aye', 'department_id' => $operationsId, 'position' => 'Office Coordinator', 'join_date' => '2025-01-08', 'device_user_id' => '1032', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0004'],
            ['employee_code' => 'EMP-0033', 'full_name' => 'Min Min Latt', 'department_id' => $operationsId, 'position' => 'Office Coordinator', 'join_date' => '2025-03-30', 'device_user_id' => '1033', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0004'],
            ['employee_code' => 'EMP-0034', 'full_name' => 'Yin Yin Htwe', 'department_id' => $operationsId, 'position' => 'Office Coordinator', 'join_date' => '2025-06-17', 'device_user_id' => '1034', 'role' => 'employee', 'create_user' => true, 'manager_code' => 'EMP-0004'],
            ['employee_code' => 'EMP-0035', 'full_name' => 'Saw Naing Oo', 'department_id' => $operationsId, 'position' => 'Office Coordinator', 'join_date' => '2025-08-11', 'device_user_id' => '1035', 'role' => null, 'create_user' => false, 'manager_code' => 'EMP-0004'],
        ];
    }
}
