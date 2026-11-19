<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Http;

use Acadly\Core\Institution\Application\Actions\ReplaceLogo;
use Acadly\Core\Institution\Application\Actions\SaveCampus;
use Acadly\Core\Institution\Application\Actions\SaveLook;
use Acadly\Core\Institution\Application\Actions\SaveSchoolDetails;
use Acadly\Core\Institution\Application\Actions\SetLevels;
use Acadly\Core\Institution\Application\Actions\SetOffering;
use Acadly\Core\Institution\Application\InstitutionDirectory;
use Acadly\Core\Institution\Contracts\CampusView;
use Acadly\Core\Institution\Contracts\DivisionView;
use Acadly\Core\Institution\Contracts\InstitutionPermission;
use Acadly\Core\Institution\Contracts\Term;
use Acadly\Core\Institution\Domain\Campus;
use Acadly\Core\Institution\Domain\Countries;
use Acadly\Core\Institution\Domain\Division;
use Acadly\Core\Institution\Domain\School;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Compiled\CompiledProfile;
use Acadly\Kernel\Documents\DocumentReader;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InstitutionController
{
    public function __construct(private readonly InstitutionDirectory $directory) {}

    /**
     * The school, its levels, campuses and where each level is taught, with
     * the choices the setup screens offer.
     *
     * @response array{school: array{name: string, short_name: string|null, code: string|null, email: string|null, phone: string|null, website: string|null, district: string|null, country: string|null, physical_address: string|null, postal_address: string|null, has_logo: bool, open: bool, details_complete: bool}, countries: list<array{code: string, name: string, timezone: string, districts: list<string>}>, profiles: list<array{key: string, title: string, summary: string, words: string}>, divisions: list<array{id: string, name: string, profile: string, words: string}>, campuses: list<array{id: string, name: string, place: string, address: string|null}>, offerings: list<array{division_id: string, campus_id: string}>}
     */
    public function show(Request $request, Access $access, Blueprints $blueprints): JsonResponse
    {
        $access->check(self::user($request), InstitutionPermission::Manage);
        $school = School::current();

        return response()->json([
            'school' => [
                'name' => $school->name,
                'short_name' => $school->short_name,
                'code' => $school->code,
                'email' => $school->email,
                'phone' => $school->phone,
                'website' => $school->website,
                'district' => $school->district,
                'country' => $school->country,
                'physical_address' => $school->physical_address,
                'postal_address' => $school->postal_address,
                'has_logo' => $school->logo_document_id !== null,
                'open' => $school->isOpen(),
                'details_complete' => $this->directory->detailsComplete(),
            ],
            'countries' => array_map(
                fn (string $code, array $country): array => ['code' => $code, 'name' => $country['name'], 'timezone' => $country['timezone_label'], 'districts' => $country['districts']],
                array_keys(Countries::ALL), array_values(Countries::ALL),
            ),
            'profiles' => array_map(fn (CompiledProfile $profile): array => [
                'key' => $profile->ref->key,
                'title' => $profile->title,
                'summary' => $profile->summary,
                'words' => self::words($profile),
            ], $blueprints->pinnableProfiles()),
            'divisions' => array_map(fn (DivisionView $division): array => [
                'id' => $division->id,
                'name' => $division->name,
                'profile' => $division->profile->ref->key,
                'words' => self::words($division->profile),
            ], $this->directory->divisionViews()),
            'campuses' => array_map(fn (CampusView $campus): array => [
                'id' => $campus->id, 'name' => $campus->name, 'place' => $campus->place, 'address' => $campus->address,
            ], $this->directory->campusViews()),
            'offerings' => $this->directory->offerings(),
        ]);
    }

    /** Saves the school details. */
    public function update(Request $request, SaveSchoolDetails $save): JsonResponse
    {
        $save->handle(self::user($request), $request->only(['name', 'short_name', 'code', 'email', 'phone', 'website', 'country', 'district', 'physical_address', 'postal_address']));

        return $this->show($request, app(Access::class), app(Blueprints::class));
    }

    /**
     * Saves the school's own look: `{app_name, short_name, main, accent, accent_last}`. Empty values return to the product's own.
     *
     * @response array{app_name: string|null, main: string, accent: string, accent_last: bool, custom: bool}
     */
    public function look(Request $request, SaveLook $save, SchoolProfile $school): JsonResponse
    {
        $str = fn (string $key): ?string => is_string($request->input($key)) ? $request->string($key)->toString() : null;
        $save->handle(self::user($request), $str('app_name'), $str('main'), $str('accent'), $request->boolean('accent_last', true), $str('short_name'));

        return response()->json($school->look());
    }

    /** Sets the school logo: a PNG or JPEG of at most 1 MB. */
    public function logo(Request $request, ReplaceLogo $replace, Access $access): JsonResponse
    {
        $access->check(self::user($request), InstitutionPermission::Manage);
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => ['Choose an image file.']]);
        }

        $replace->handle(self::user($request), $file);

        return $this->show($request, app(Access::class), app(Blueprints::class));
    }

    /** Sets the levels the school runs: `levels: [{profile, name}]`. */
    public function levels(Request $request, SetLevels $set, Access $access): JsonResponse
    {
        $access->check(self::user($request), InstitutionPermission::Manage);
        $request->validate(['levels' => ['required', 'array', 'min:1'], 'levels.*.profile' => ['required', 'string'], 'levels.*.name' => ['nullable', 'string', 'max:80']]);
        $levels = [];

        foreach ((array) $request->input('levels') as $level) {
            if (is_array($level) && is_string($level['profile'] ?? null)) {
                $levels[] = ['profile' => $level['profile'], 'name' => is_string($level['name'] ?? null) ? $level['name'] : null];
            }
        }

        $set->handle(self::user($request), $levels);

        return $this->show($request, app(Access::class), app(Blueprints::class));
    }

    /** Adds a campus. */
    public function storeCampus(Request $request, SaveCampus $save): JsonResponse
    {
        $save->handle(self::user($request), null, $request->only(['name', 'place', 'address']));

        return $this->show($request, app(Access::class), app(Blueprints::class));
    }

    /** Changes a campus. */
    public function updateCampus(Request $request, string $id, SaveCampus $save, Access $access): JsonResponse
    {
        $access->check(self::user($request), InstitutionPermission::Manage);
        $campus = Campus::query()->whereNull('ended_at')->find($id) ?? throw new NotFoundHttpException;
        $save->handle(self::user($request), $campus, $request->only(['name', 'place', 'address']));

        return $this->show($request, app(Access::class), app(Blueprints::class));
    }

    /** Says whether a level is taught at a campus: `{division_id, campus_id, taught}`. */
    public function offering(Request $request, SetOffering $set, Access $access): JsonResponse
    {
        $access->check(self::user($request), InstitutionPermission::Manage);
        $request->validate(['division_id' => ['required', 'uuid'], 'campus_id' => ['required', 'uuid'], 'taught' => ['required', 'boolean']]);
        $division = Division::query()->whereNull('ended_at')->find($request->string('division_id')->toString()) ?? throw new NotFoundHttpException;
        $campus = Campus::query()->whereNull('ended_at')->find($request->string('campus_id')->toString()) ?? throw new NotFoundHttpException;

        $set->handle(self::user($request), $division, $campus, $request->boolean('taught'));

        return $this->show($request, app(Access::class), app(Blueprints::class));
    }

    /** The school logo, for the sign-in page. No authentication. */
    public function publicLogo(DocumentReader $reader): StreamedResponse
    {
        $id = School::current()->logo_document_id ?? throw new NotFoundHttpException;
        $opened = $reader->openPublic($id);

        return new StreamedResponse(function () use ($opened): void {
            foreach ($opened['bytes'] as $piece) {
                echo $piece;
            }
        }, 200, [
            'Content-Type' => $opened['file']->mime,
            'Content-Length' => (string) $opened['file']->size,
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** "Pupil · Standard · Term", from the profile's words. */
    private static function words(CompiledProfile $profile): string
    {
        return implode(' · ', [$profile->word(Term::Learner->value), $profile->word(Term::Level->value), $profile->word(Term::Period->value)]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
