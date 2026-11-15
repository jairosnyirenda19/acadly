<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Application\Actions;

use Acadly\Core\Institution\Contracts\InstitutionAudit;
use Acadly\Core\Institution\Contracts\InstitutionPermission;
use Acadly\Core\Institution\Domain\Countries;
use Acadly\Core\Institution\Domain\School;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Input;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Saves the details the school is known by. The school code cannot change
 * once the school is open: it is printed in learner and document numbers.
 */
final class SaveSchoolDetails
{
    public function __construct(
        private readonly Access $access,
        private readonly Audit $audit,
    ) {}

    /** @param  array<mixed>  $input */
    public function handle(User $by, array $input): School
    {
        $this->access->check($by, InstitutionPermission::Manage);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'short_name' => ['required', 'string', 'max:16'],
            'code' => ['required', 'string', 'regex:/^[A-Z]{2,6}$/'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'website' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string'],
            'district' => ['required', 'string'],
            'physical_address' => ['nullable', 'string', 'max:500'],
            'postal_address' => ['nullable', 'string', 'max:500'],
        ], [
            'code.regex' => 'Use 2 to 6 capital letters.',
            'phone.regex' => 'Use digits and spaces, with the country code.',
        ])->validate();

        $country = Input::text($data, 'country');

        if (! Countries::has($country)) {
            throw ValidationException::withMessages(['country' => ['The country is not available.']]);
        }

        if (! Countries::hasDistrict($country, Input::text($data, 'district'))) {
            throw ValidationException::withMessages(['district' => ['Choose a district of the country.']]);
        }

        return DB::transaction(function () use ($by, $data): School {
            $school = School::query()->lockForUpdate()->firstOrFail();

            if ($school->isOpen() && $school->code !== Input::text($data, 'code')) {
                throw ValidationException::withMessages(['code' => ['The school code cannot change after the school has opened.']]);
            }

            $watch = $this->audit->watch($school);
            $school->fill([
                'name' => Input::text($data, 'name'),
                'short_name' => Input::text($data, 'short_name'),
                'code' => Input::text($data, 'code'),
                'email' => mb_strtolower(Input::text($data, 'email')),
                'phone' => Input::text($data, 'phone'),
                'website' => Input::maybe($data, 'website'),
                'country' => Input::text($data, 'country'),
                'district' => Input::text($data, 'district'),
                'physical_address' => Input::maybe($data, 'physical_address'),
                'postal_address' => Input::maybe($data, 'postal_address'),
            ])->save();

            $this->audit->record(InstitutionAudit::DetailsSaved, subject: $school, changes: $watch->diff(), actor: $this->audit->actorFor($by));

            return $school;
        });
    }
}
