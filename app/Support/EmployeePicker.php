<?php

namespace App\Support;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;

/**
 * The search behind the employee picker (livewire/partials/employee-picker), for
 * an admin filing a request: name or code, the first LIMIT by name, and how many
 * match in all, so the picker can say the list is cut short.
 */
class EmployeePicker
{
    public const LIMIT = 8;

    /**
     * @return array{matches: Collection<int, Employee>, matchTotal: int}
     */
    public static function search(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return ['matches' => new Collection, 'matchTotal' => 0];
        }

        $query = Employee::query()
            ->where(fn ($query) => $query->where('full_name', 'like', '%'.$term.'%')
                ->orWhere('employee_code', 'like', '%'.$term.'%'));

        $matches = (clone $query)->orderBy('full_name')->limit(self::LIMIT)->get();

        return [
            'matches' => $matches,
            'matchTotal' => $matches->count() < self::LIMIT ? $matches->count() : $query->count(),
        ];
    }
}
