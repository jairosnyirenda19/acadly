<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Application\Actions;

use Acadly\Core\Institution\Contracts\InstitutionAudit;
use Acadly\Core\Institution\Contracts\InstitutionPermission;
use Acadly\Core\Institution\Domain\Campus;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Input;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Adds a campus, or changes one. A campus name is unique among the campuses in force. */
final class SaveCampus
{
    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
    ) {}

    /** @param  array<mixed>  $input  name, place, address */
    public function handle(User $by, ?Campus $campus, array $input): Campus
    {
        $this->access->check($by, InstitutionPermission::Manage);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'place' => ['required', 'string', 'min:2', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $name = Input::text($data, 'name');
        $taken = Campus::query()->whereNull('ended_at')->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($campus !== null, fn ($query) => $query->whereKeyNot($campus?->id))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => ['Another campus has this name.']]);
        }

        return DB::transaction(function () use ($by, $campus, $data, $name): Campus {
            $max = Campus::query()->max('position');
            $record = $campus ?? new Campus(['position' => (is_numeric($max) ? (int) $max : 0) + 1]);
            $watch = $this->audit->watch($record);
            $record->fill(['name' => $name, 'place' => Input::text($data, 'place'), 'address' => Input::maybe($data, 'address')])->save();

            $this->audit->record(InstitutionAudit::CampusSaved, subject: $record, changes: $watch->diff(), actor: $this->audit->actorFor($by));

            return $record;
        });
    }
}
