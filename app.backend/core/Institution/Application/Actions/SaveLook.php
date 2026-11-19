<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Application\Actions;

use Acadly\Core\Institution\Contracts\InstitutionAudit;
use Acadly\Core\Institution\Contracts\InstitutionPermission;
use Acadly\Core\Institution\Domain\School;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\Contrast;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves the school's own look (D-096): the name the console shows, the main
 * and accent colours, and whether the name's last word takes the accent.
 * A colour that cannot be read is refused, with the nearest one that can.
 * The colours that mean done, attention and wrong are not the school's to change.
 */
final class SaveLook
{
    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
    ) {}

    public function handle(User $by, ?string $appName, ?string $main, ?string $accent, bool $accentLast, ?string $shortName = null): School
    {
        $this->access->check($by, InstitutionPermission::Manage);
        $appName = $appName !== null && trim($appName) !== '' ? preg_replace('/\s+/', ' ', trim($appName)) : null;
        $main = $main !== null && trim($main) !== '' ? strtoupper(trim($main)) : null;
        $accent = $accent !== null && trim($accent) !== '' ? strtoupper(trim($accent)) : null;
        $errors = [];

        $shortName = $shortName !== null && trim($shortName) !== '' ? trim($shortName) : null;

        if ($shortName !== null && mb_strlen($shortName) > 16) {
            $errors['short_name'] = ['Use up to 16 letters. This name has '.mb_strlen($shortName).'.'];
        }

        if ($appName !== null && mb_strlen($appName) > 40) {
            $errors['app_name'] = ['Use up to 40 letters. This name has '.mb_strlen($appName).'.'];
        }

        foreach (['main' => $main, 'accent' => $accent] as $field => $colour) {
            if ($colour !== null && ! Contrast::isColour($colour)) {
                $errors[$field] = ['Give the colour as # and six letters or digits, such as #123E2A.'];
            }
        }

        if ($errors === []) {
            $m = $main ?? Contrast::MAIN;
            $a = $accent ?? Contrast::ACCENT;

            if (Contrast::ratio('#FFFFFF', $m) < Contrast::TEXT) {
                $errors['main'] = ['White text cannot be read on this colour ('.Contrast::ratio('#FFFFFF', $m).' : 1, at least 4.5 is needed). The nearest that can: '.Contrast::nearestMain($m).'.'];
            } elseif (Contrast::ratio($a, $m) < Contrast::ACCENT_ON_MAIN) {
                $errors['accent'] = ['The accent cannot be read on the main colour ('.Contrast::ratio($a, $m).' : 1, at least 3 is needed). The nearest that can: '.Contrast::nearestAccent($a, $m).'.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($by, $appName, $main, $accent, $accentLast, $shortName): School {
            $school = School::current();
            $school->forceFill(['app_name' => $appName, 'brand_main' => $main, 'brand_accent' => $accent, 'accent_last_word' => $accentLast]);

            if ($shortName !== null) {
                $school->short_name = $shortName;
            }
            $school->save();
            $this->audit->record(InstitutionAudit::LookSaved, subject: $school, actor: $this->audit->actorFor($by),
                meta: ['app_name' => $appName, 'main' => $main, 'accent' => $accent, 'accent_last_word' => $accentLast]);

            return $school;
        });
    }
}
