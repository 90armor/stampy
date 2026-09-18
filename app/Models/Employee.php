<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class Employee extends Model
{
    use HasFactory;

    /**
     * subordinateIds() results, keyed by employee id, for the lifetime of
     * this PHP process — it's called once per acting user per request on
     * the attendance list (not per row), but memoizing avoids re-walking
     * the tree if the same Employee instance is asked more than once.
     *
     * @var array<int, int[]>
     */
    private static array $subordinateIdsCache = [];

    protected $fillable = [
        'user_id',
        'employee_code',
        'full_name',
        'department_id',
        'position_id',
        'join_date',
        'device_user_id',
        'work_schedule_id',
        'manager_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'join_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function dailyAttendances(): HasMany
    {
        return $this->hasMany(DailyAttendance::class);
    }

    public function effectiveSchedule(): WorkSchedule
    {
        return $this->workSchedule ?? WorkSchedule::default();
    }

    /**
     * All descendant employee ids at any depth (direct reports, their
     * reports, and so on), excluding this employee itself. Walked
     * level-by-level rather than via a recursive CTE so cycle safety is
     * explicit in PHP: a visited-id set means a cycle can never be
     * re-descended, and it terminates on its own once every reachable id
     * has been seen. The depth cap is a second, independent guard — mostly
     * against a pathologically deep/wide chain rather than an actual
     * infinite loop, since the visited set alone already bounds this to at
     * most the number of rows in the table.
     *
     * @return int[]
     */
    public function subordinateIds(): array
    {
        return self::$subordinateIdsCache[$this->id] ??= $this->resolveSubordinateIds();
    }

    public function isManagerOf(self $other): bool
    {
        return in_array($other->id, $this->subordinateIds(), true);
    }

    /**
     * @return int[]
     */
    private function resolveSubordinateIds(): array
    {
        $maxDepth = 10;
        $visited = [$this->id => true];
        $result = [];
        $frontier = [$this->id];
        $depth = 0;

        while ($frontier !== [] && $depth < $maxDepth) {
            $children = static::query()
                ->whereIn('manager_id', $frontier)
                ->pluck('id')
                ->all();

            $frontier = [];

            foreach ($children as $id) {
                if (isset($visited[$id])) {
                    continue;
                }

                $visited[$id] = true;
                $result[] = $id;
                $frontier[] = $id;
            }

            $depth++;
        }

        // A non-empty frontier at the cap only means "not checked yet" — warn only if those nodes really have unvisited reports.
        $truncated = $frontier !== []
            && static::query()->whereIn('manager_id', $frontier)->pluck('id')->contains(fn ($id) => ! isset($visited[$id]));

        if ($truncated) {
            Log::warning('Employee::subordinateIds hit its depth cap without exhausting the tree — check for a manager_id cycle or an unusually deep org chart.', [
                'employee_id' => $this->id,
                'max_depth' => $maxDepth,
            ]);
        }

        return $result;
    }
}
