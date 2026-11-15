<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Profile;

use Acadly\Kernel\Blueprint\Compiled\CompiledProfile;
use BackedEnum;

/**
 * Checks a compiled profile (02 §3.9): a profile that can be pinned has a
 * title, a sentence, a period model, a ladder and a grouping; level codes are
 * unique; and every term of each vocabulary it uses has its words.
 */
final class ProfileLinter
{
    /**
     * @param  list<class-string<BackedEnum>>  $termEnums
     * @return list<string>
     */
    public function problems(CompiledProfile $profile, array $termEnums): array
    {
        $at = (string) $profile->ref;
        $problems = [];

        if (! $profile->abstract) {
            if (trim($profile->title) === '' || trim($profile->summary) === '') {
                $problems[] = "{$at}: the profile needs a title and a sentence.";
            }

            if ($profile->periodModel === null || ($profile->periodsPerYear ?? 0) < 1) {
                $problems[] = "{$at}: the profile needs a period model with at least one period a year.";
            }

            if ($profile->levels === []) {
                $problems[] = "{$at}: the profile has no levels.";
            }

            if ($profile->grouping === null) {
                $problems[] = "{$at}: the profile needs a grouping.";
            }
        }

        $codes = [];

        foreach ($profile->levels as $level) {
            if (preg_match('/^[A-Z0-9][A-Z0-9_]{0,15}$/', $level['code']) !== 1) {
                $problems[] = "{$at}: [{$level['code']}] is not a valid level code.";
            }

            if (isset($codes[$level['code']])) {
                $problems[] = "{$at}: the level [{$level['code']}] is declared twice.";
            }

            $codes[$level['code']] = true;
        }

        foreach ($termEnums as $enum) {
            foreach ($enum::cases() as $case) {
                if (! $profile->abstract && is_string($case->value) && ! isset($profile->vocabulary[$case->value])) {
                    $problems[] = "{$at}: the term [{$case->value}] has no words.";
                }
            }
        }

        return $problems;
    }
}
