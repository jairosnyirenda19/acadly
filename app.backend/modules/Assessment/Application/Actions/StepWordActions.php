<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Assessment\Contracts\AssessmentAudit;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The school's words for the review steps (D-108). A save is a new row, kept with who and when. */
final class StepWordActions
{
    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array{starting: string, growing: string, can_do: string}  $en
     * @param  array{starting: string, growing: string, can_do: string}  $ny
     */
    public function save(User $by, array $en, array $ny): void
    {
        $this->access->check($by, AssessmentPermission::Plan);
        $errors = [];
        $seen = [];
        foreach (['starting', 'growing', 'can_do'] as $k) {
            $word = trim($en[$k]);
            if ($word === '' || mb_strlen($word) > 24 || in_array(mb_strtolower($word), $seen, true)) {
                $errors["en.{$k}"] = ['Give each step its own word, up to 24 characters.'];
            }
            $seen[] = mb_strtolower($word);
            if (mb_strlen(trim($ny[$k])) > 24) {
                $errors["ny.{$k}"] = ['Up to 24 characters.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $ny = array_map(fn (string $w): ?string => trim($w) === '' ? null : trim($w), $ny);
        DB::transaction(function () use ($by, $en, $ny): void {
            DB::table('observation_step_words')->insert(['id' => Ids::next(), 'starting' => trim($en['starting']), 'growing' => trim($en['growing']), 'can_do' => trim($en['can_do']),
                'starting_ny' => $ny['starting'], 'growing_ny' => $ny['growing'], 'can_do_ny' => $ny['can_do'], 'saved_by' => $by->id, 'saved_at' => $this->clock->now()]);
            $this->audit->record(AssessmentAudit::StepWordsSaved, actor: $this->audit->actorFor($by), meta: ['starting' => trim($en['starting']), 'growing' => trim($en['growing']), 'can_do' => trim($en['can_do'])]);
        });
    }
}
