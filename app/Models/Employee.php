<?php

namespace App\Models;

use App\Exceptions\InvalidEmploymentPeriodException;
use App\Exceptions\NoDefaultWorkScheduleException;
use App\Exceptions\NoScheduleAssignmentException;
use App\Services\Leave\LeaveGranter;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
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
        'manager_id',
        'status',
        'left_on',
    ];

    /**
     * Assigns a freshly-created employee the current default schedule,
     * effective from their join_date — every employee must have at least
     * one employee_work_schedules row from this point on (see scheduleOn()) —
     * and this year's leave grants (LeaveGranter). save() below wraps both in
     * the same transaction as the employee insert itself, so a missing default
     * or a failed grant leaves no row behind. With no leave types configured
     * the grant simply creates nothing; leave:grant catches up daily.
     *
     * A corrected join_date re-grants the automatic grants it was computed
     * from (LeaveGranter::regrantAfterJoinDateChange()), in the same
     * transaction as the update.
     */
    protected static function booted(): void
    {
        // Status and the employment period must agree on every write, not
        // only in the deactivate form — see InvalidEmploymentPeriodException.
        static::saving(function (self $employee) {
            if ($employee->exists && ! $employee->isDirty(['status', 'left_on', 'join_date'])) {
                return;
            }

            $employee->validateEmploymentPeriod();
        });

        static::created(function (self $employee) {
            $default = WorkSchedule::default() ?? throw new NoDefaultWorkScheduleException($employee);

            $employee->scheduleAssignments()->create([
                'work_schedule_id' => $default->id,
                'effective_from' => $employee->join_date,
                'created_by' => auth()->id(),
            ]);

            app(LeaveGranter::class)->grant($employee, today()->year, today());
        });

        static::updated(function (self $employee) {
            if ($employee->wasChanged('join_date')) {
                app(LeaveGranter::class)->regrantAfterJoinDateChange($employee);
            }
        });

        // The reporting tree changed, so every memoized subordinateIds() may be
        // stale — approval eligibility is evaluated when someone decides, and
        // must see a move made earlier in the same request.
        static::saved(function (self $employee) {
            if ($employee->wasRecentlyCreated || $employee->wasChanged('manager_id')) {
                self::forgetSubordinateIds();
            }
        });
    }

    /**
     * A brand-new row is wrapped: the created() listener above inserts
     * this employee's initial schedule assignment and leave grants as part of
     * this very same save() call (Eloquent fires model events synchronously,
     * inside the call that triggered them), and they must succeed or fail
     * together — without this, a missing default would leave a committed
     * employee row with no schedule at all, the exact state scheduleOn() must
     * never see. An update that changes join_date is wrapped too, for the
     * re-grant the updated() listener does. Any other update isn't.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists && ! $this->isDirty('join_date')) {
            return parent::save($options);
        }

        return DB::transaction(fn () => parent::save($options));
    }

    protected function casts(): array
    {
        return [
            'join_date' => 'date',
            'left_on' => 'date',
        ];
    }

    /**
     * @throws InvalidEmploymentPeriodException
     */
    private function validateEmploymentPeriod(): void
    {
        $inactive = $this->status === 'inactive';

        if ($inactive && $this->left_on === null) {
            throw InvalidEmploymentPeriodException::inactiveWithoutLeftOn();
        }

        if (! $inactive && $this->left_on !== null) {
            throw InvalidEmploymentPeriodException::activeWithLeftOn();
        }

        if ($this->left_on === null) {
            return;
        }

        if ($this->join_date !== null && $this->left_on->lt($this->join_date)) {
            throw InvalidEmploymentPeriodException::leftBeforeJoining();
        }

        if ($this->left_on->gt(today())) {
            throw InvalidEmploymentPeriodException::leftInFuture();
        }
    }

    /**
     * Employed on $date: join_date ≤ $date and (no left_on, or $date ≤
     * left_on). The one definition of "active on a date" — isActiveOn() is
     * the same rule for a loaded instance, and EmployeeTest checks the two
     * agree at every boundary. It deliberately ignores `status`: the
     * invariants above keep status and left_on in step, and a date question
     * needs the date, not today's flag.
     */
    public function scopeActiveOn(Builder $query, CarbonInterface $date): Builder
    {
        $day = $date->format('Y-m-d');

        return $query->whereDate('join_date', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('left_on')->orWhereDate('left_on', '>=', $day));
    }

    /**
     * Active on at least one date in $from..$to — the employees a build over
     * that range has to visit (attendance:build-daily, an import's rebuild).
     * The same rule as scopeActiveOn(), widened to a range; per date, the
     * builder still asks isActiveOn().
     */
    public function scopeActiveBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereDate('join_date', '<=', $to->format('Y-m-d'))
            ->where(fn (Builder $q) => $q->whereNull('left_on')->orWhereDate('left_on', '>=', $from->format('Y-m-d')));
    }

    /**
     * scopeActiveOn() for a loaded instance — same rule, see there.
     */
    public function isActiveOn(CarbonInterface $date): bool
    {
        $day = Carbon::instance($date)->startOfDay();

        return $this->join_date->lte($day) && ($this->left_on === null || $day->lte($this->left_on));
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

    /**
     * Every schedule this employee has ever been assigned, oldest first,
     * with each row's workSchedule eager-loaded — accessing this (or calling
     * scheduleOn()) queries once per Employee instance and is cached on it
     * for the rest of the request, the same way subordinateIds() is cached
     * across calls; a build run that reuses one Employee across many dates
     * (attendance:build-daily, rebuildAround()) only pays for this once.
     */
    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(EmployeeWorkSchedule::class)->with('workSchedule')->orderBy('effective_from');
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

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class);
    }

    public function leaveEntitlements(): HasMany
    {
        return $this->hasMany(LeaveEntitlement::class);
    }

    public function leaveAdjustments(): HasMany
    {
        return $this->hasMany(LeaveAdjustment::class);
    }

    /**
     * The schedule in force on a given date — the employee's latest
     * assignment whose effective_from is on or before it, so a later
     * reassignment never changes what an earlier date resolves to. If the
     * date is before their earliest assignment (e.g. join_date was edited
     * to something earlier after the fact), the earliest one is used
     * instead of throwing — every date from join_date onward must resolve
     * to *something*. Works for a future date with no daily_attendances row
     * at all, and for one long before or after any date ever built.
     *
     * Every employee has at least one assignment from the moment they're
     * created (see booted() above) — reaching the empty case here means the
     * assignment(s) were removed some other way, a data-integrity problem
     * distinct from "no default schedule exists" (which can only happen at
     * creation time now, not here).
     */
    public function scheduleOn(CarbonInterface $date): WorkSchedule
    {
        $date = Carbon::instance($date)->startOfDay();

        $assignments = $this->scheduleAssignments;

        if ($assignments->isEmpty()) {
            throw new NoScheduleAssignmentException($this);
        }

        $match = $assignments->filter(fn (EmployeeWorkSchedule $a) => $a->effective_from->lte($date))->last()
            ?? $assignments->first();

        return $match->workSchedule;
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

    /** Drops every memoized subordinateIds() result (see booted()). */
    public static function forgetSubordinateIds(): void
    {
        self::$subordinateIdsCache = [];
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
