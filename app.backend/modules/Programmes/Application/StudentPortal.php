<?php

declare(strict_types=1);

namespace Acadly\Modules\Programmes\Application;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Application\Registration\RegistrationChecks;
use Acadly\Modules\Programmes\Application\Registration\RegistrationViews;
use Acadly\Modules\Programmes\Application\Registration\Windows;
use Acadly\Modules\Programmes\Domain\Registration;
use Acadly\Modules\Programmes\Journeys\Registration\State;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The student portal's own records (D-101): who the student is, their
 * courses this semester, and their registration. Only the signed-in
 * student's own; there is no way to name another student.
 *
 * @phpstan-type Student array{learner_id: string, student_id: string, division_id: string, programme_id: string, department_id: string, faculty_id: string, year_of_study: int}
 */
final class StudentPortal
{
    public function __construct(
        private readonly SchoolProfile $school,
        private readonly RegistrationViews $views,
        private readonly Windows $windows,
    ) {}

    /** @return Student */
    public function student(User $user): array
    {
        return StudentAccountDirectory::studentOf($user->id) ?? throw new NotFoundHttpException;
    }

    /**
     * Who the student is: name, number, programme and year, and the semester now.
     *
     * @return array{name: string, number: string, college: string, programme: array{code: string, name: string}, year: int, semester: string|null, email: string|null, phone: string|null}
     */
    public function me(User $user): array
    {
        $s = $this->student($user);
        $p = DB::table('programmes')->where('id', $s['programme_id'])->first(['code', 'name']);
        $r = $this->latest($s);
        $phone = DB::table('programme_student_accounts')->where('learner_id', $s['learner_id'])->value('phone');
        $email = $user->email !== null && ! str_ends_with($user->email, '@students.invalid') ? $user->email : null;
        $college = '';
        foreach ($this->school->divisions() as $d) {
            $college = $d['id'] === $s['division_id'] ? $d['name'] : $college;
        }

        return ['name' => $user->name, 'number' => StudentAccountDirectory::numberOf($s['learner_id']), 'college' => $college,
            'programme' => ['code' => $p !== null ? Row::text($p, 'code') : '', 'name' => $p !== null ? Row::text($p, 'name') : ''],
            'year' => $s['year_of_study'], 'semester' => $r === null ? null : self::periodName($r->period_id),
            'email' => $email, 'phone' => is_string($phone) ? '•• '.substr($phone, -2) : null];
    }

    /**
     * The courses of the semester: the registration's courses, with credits and lecturers.
     *
     * @return array{semester: string|null, state: string|null, credits: int, courses: list<array{code: string, title: string, credits: int, kind: string, group: string, lecturers: list<string>}>}
     */
    public function courses(User $user): array
    {
        $r = $this->latest($this->student($user));
        if ($r === null) {
            return ['semester' => null, 'state' => null, 'credits' => 0, 'courses' => []];
        }
        $rows = RegistrationChecks::courses($r);
        $titles = DB::table('programme_courses')->whereIn('id', array_column($rows, 'course_id') ?: [self::NONE])->get(['id', 'code', 'title'])->keyBy('id');
        $lecturers = [];
        foreach (DB::table('programme_offering_staff as s')->join('users as u', 'u.id', '=', 's.user_id')->whereIn('s.offering_id', array_column($rows, 'offering_id') ?: [self::NONE])
            ->orderBy('u.name')->get(['s.offering_id', 'u.name']) as $l) {
            $lecturers[Row::text($l, 'offering_id')][] = Row::text($l, 'name');
        }
        $courses = [];
        foreach ($rows as $c) {
            $t = $titles->get($c['course_id']);
            $courses[] = ['code' => is_object($t) ? Row::text($t, 'code') : '', 'title' => is_object($t) ? Row::text($t, 'title') : '', 'credits' => $c['credits'],
                'kind' => $c['kind'], 'group' => $c['group'], 'lecturers' => $lecturers[$c['offering_id']] ?? []];
        }

        return ['semester' => self::periodName($r->period_id), 'state' => $r->state,
            'credits' => array_sum(array_column($rows, 'credits')), 'courses' => $courses];
    }

    /**
     * The student's registration for the semester, as the registry sees it, with what the student may do.
     *
     * @return array<string, mixed>|null
     */
    public function registration(User $user): ?array
    {
        $r = $this->latest($this->student($user));
        if ($r === null) {
            return null;
        }
        $view = $this->views->registration($user, $r);
        // The names of the staff who acted stay with the registry.
        $view['history'] = array_map(fn (array $h): array => [...$h, 'by' => ''], $view['history']);
        $view['window'] = $this->window($r->period_id);
        $view['editable'] = $r->state === State::Draft->value && in_array($this->windows->phase($r->period_id), ['open', 'late'], true);

        return $view;
    }

    /** The student's own registration, for a change or the slip. */
    public function own(User $user, ?string $id = null): Registration
    {
        $r = $this->latest($this->student($user));
        if ($r === null || ($id !== null && $r->id !== $id)) {
            throw new NotFoundHttpException;
        }

        return $r;
    }

    /** @param  Student  $s */
    private function latest(array $s): ?Registration
    {
        return Registration::query()->join('academic_periods as p', 'p.id', '=', 'programme_registrations.period_id')
            ->where('programme_registrations.learner_id', $s['learner_id'])->where('programme_registrations.state', '!=', State::Withdrawn->value)
            ->orderByDesc('p.starts_on')->first(['programme_registrations.*']);
    }

    /** @return array{closes_on: string, late_until: string}|null */
    private function window(string $periodId): ?array
    {
        $w = DB::table('programme_registration_windows')->where('period_id', $periodId)->first(['closes_on', 'late_until']);

        return $w === null ? null : ['closes_on' => Row::text($w, 'closes_on'), 'late_until' => Row::text($w, 'late_until')];
    }

    private static function periodName(string $periodId): string
    {
        $name = DB::table('academic_periods')->where('id', $periodId)->value('name');

        return is_string($name) ? $name : '';
    }

    private const string NONE = '00000000-0000-0000-0000-000000000000';
}
