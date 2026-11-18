<?php

declare(strict_types=1);

namespace Acadly\Core\Academics\Application\Actions;

use Acadly\Core\Academics\Application\Calendar;
use Acadly\Core\Academics\Application\NextYear;
use Acadly\Core\Academics\Contracts\AcademicsPermission;
use Acadly\Core\Academics\Domain\AcademicYear;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\NotAudited;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets up the school year after this one (D-088): its dates, the terms of
 * each level and the classes of each level at each campus, in one go. It
 * may be saved again until that year starts.
 */
#[NotAudited('It saves the year, the terms and the classes through their own Actions, which each write the audit line.')]
final class SaveNextYear
{
    public function __construct(
        private readonly Access $access,
        private readonly Calendar $calendar,
        private readonly NextYear $next,
        private readonly SaveAcademicYear $year,
        private readonly SaveTerms $terms,
        private readonly SaveClasses $classes,
        private readonly Clock $clock,
    ) {}

    /** @param  array<array-key, mixed>  $input  `{name, starts_on, ends_on, terms: [{division_id, terms}], classes: [{division_id, campus_id, streams}]}` */
    public function handle(User $by, array $input): AcademicYear
    {
        $this->access->check($by, AcademicsPermission::Manage);
        $current = $this->calendar->currentYear() ?? throw ValidationException::withMessages(['year' => ['Set up this school year first.']]);
        $saved = $this->next->find();
        $startsOn = is_string($input['starts_on'] ?? null) ? $input['starts_on'] : '';

        if ($startsOn <= $current['ends_on']) {
            throw ValidationException::withMessages(['starts_on' => ['The next school year starts after '.$current['name'].' ends, on '.$current['ends_on'].'.']]);
        }

        if ($saved !== null && $saved['starts_on'] <= $this->clock->today()) {
            throw ValidationException::withMessages(['year' => [$saved['name'].' has started. Change it on the school year settings.']]);
        }

        return DB::transaction(function () use ($by, $input, $saved): AcademicYear {
            $year = $this->year->handle($by, $saved !== null ? AcademicYear::query()->find($saved['id']) : null,
                ['name' => $input['name'] ?? null, 'starts_on' => $input['starts_on'] ?? null, 'ends_on' => $input['ends_on'] ?? null]);

            foreach (is_array($input['terms'] ?? null) ? $input['terms'] : [] as $level) {
                if (is_array($level) && is_string($level['division_id'] ?? null)) {
                    $this->terms->handle($by, $year, $level['division_id'], is_array($level['terms'] ?? null) ? $level['terms'] : []);
                }
            }

            foreach (is_array($input['classes'] ?? null) ? $input['classes'] : [] as $offering) {
                if (is_array($offering) && is_string($offering['division_id'] ?? null) && is_string($offering['campus_id'] ?? null)) {
                    $streams = [];

                    foreach (is_array($offering['streams'] ?? null) ? $offering['streams'] : [] as $code => $names) {
                        if (is_string($code) && is_array($names)) {
                            $streams[$code] = array_values(array_filter($names, 'is_string'));
                        }
                    }

                    $this->classes->handle($by, $year, $offering['division_id'], $offering['campus_id'], $streams);
                }
            }

            return $year;
        });
    }
}
