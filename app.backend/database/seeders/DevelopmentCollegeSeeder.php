<?php

declare(strict_types=1);

namespace Database\Seeders;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Application\Actions\AdmitLearner;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Application\Actions\CourseActions;
use Acadly\Modules\Programmes\Application\Actions\OfferingActions;
use Acadly\Modules\Programmes\Application\Actions\ProgrammeActions;
use Acadly\Modules\Programmes\Application\Actions\RegistrationActions;
use Acadly\Modules\Programmes\Application\Actions\StructureActions;
use Acadly\Modules\Programmes\Application\Actions\StudentActions;
use Acadly\Modules\Programmes\Application\ProgrammesViews;
use Acadly\Modules\Programmes\Contracts\Award;
use Acadly\Modules\Programmes\Contracts\StudyMode;
use Acadly\Modules\Programmes\Journeys\Registration\State;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * D-097. A college division for the development school, to try Programmes:
 * the division, its semesters, three faculties, a catalogue, a released
 * Diploma in Accounting, a Bachelor of Accountancy still in draft, and the
 * first semester's offerings. D-098 adds students: Year 1 and Year 2
 * classes, twelve students placed in the Diploma, registration dates for
 * the semester in progress, the semester started and some approved. The
 * division, its semesters and its classes are written straight to the
 * tables, as setup would; the rest goes through the Actions.
 *
 * Local only. Run: php artisan db:seed --class=DevelopmentCollegeSeeder
 */
final class DevelopmentCollegeSeeder extends Seeder
{
    /** @var array<string, string> course code => id */
    private array $courses = [];

    private User $by;

    private string $division = '';

    public function run(Clock $clock, StructureActions $structure, CourseActions $courses, ProgrammeActions $programmes, OfferingActions $offerings,
        AcademicCalendar $calendar, AdmitLearner $admit, StudentActions $students, RegistrationActions $registration): void
    {
        if (! app()->environment(['local'])) {
            throw new RuntimeException('The development college is seeded only in the local environment.');
        }

        $email = mb_strtolower(trim(is_string($e = config('acadly.development.admin_email')) ? $e : ''));
        $this->by = User::query()->where('email', $email)->first() ?? throw new RuntimeException('Seed the development administrator first.');

        $college = DB::table('institution_divisions')->where('profile_ref', 'like', 'TERTIARY@%')->whereNull('ended_at')->first(['id']);
        if ($college !== null) {
            $this->division = Row::text($college, 'id');
            $this->command?->getOutput()->writeln('  The development college is there already.');
            $this->students($clock, $calendar, $offerings, $admit, $students, $registration);

            return;
        }

        $this->makeDivision($clock);
        $by = $this->by;
        $division = $this->division;

        $structure->saveFaculties($by, $division, [
            ['id' => null, 'name' => 'Faculty of Commerce', 'code' => 'COM', 'retired' => false],
            ['id' => null, 'name' => 'Faculty of Computing', 'code' => 'CMP', 'retired' => false],
            ['id' => null, 'name' => 'Faculty of Education', 'code' => 'EDU', 'retired' => false],
        ]);
        $acf = $this->department($structure, 'COM', 'Accounting and Finance', 'ACF', ['ACC', 'FIN', 'TAX', 'AUD']);
        $bmg = $this->department($structure, 'COM', 'Business Management', 'BMG', ['MGT', 'MKT', 'LAW', 'ECO', 'STA']);
        $csc = $this->department($structure, 'CMP', 'Computer Science', 'CSC', ['CSC']);
        $isy = $this->department($structure, 'CMP', 'Information Systems', 'ISY', ['ICT']);

        foreach ([
            [$acf, 'ACC 101', 'Financial Accounting I', [], 0], [$acf, 'ACC 102', 'Financial Accounting II', ['ACC 101'], 0],
            [$acf, 'ACC 201', 'Intermediate Accounting', ['ACC 102'], 0], [$acf, 'ACC 202', 'Advanced Accounting', ['ACC 201'], 0],
            [$acf, 'ACC 211', 'Cost Accounting', ['ACC 101'], 0], [$acf, 'TAX 201', 'Taxation I', [], 0],
            [$acf, 'FIN 201', 'Corporate Finance', ['ACC 102'], 0], [$acf, 'AUD 201', 'Auditing Principles', ['ACC 201'], 0],
            [$bmg, 'LAW 101', 'Business Law', [], 0], [$bmg, 'ECO 101', 'Principles of Economics', [], 0],
            [$bmg, 'MGT 101', 'Principles of Management', [], 0], [$bmg, 'STA 101', 'Business Statistics I', [], 0],
            [$bmg, 'STA 202', 'Business Statistics II', ['STA 101'], 0], [$bmg, 'MKT 203', 'Principles of Marketing', [], 0],
            [$bmg, 'MGT 204', 'Organisational Behaviour', [], 0], [$isy, 'ICT 101', 'Computers in Business', [], 2],
            [$isy, 'ICT 201', 'Spreadsheets for Business', [], 2], [$csc, 'CSC 101', 'Introduction to Programming', [], 2],
            [$csc, 'CSC 201', 'Data Structures', ['CSC 101'], 2],
        ] as [$dept, $code, $title, $needs, $practical]) {
            $this->courses[$code] = $courses->save($by, $division, ['id' => null, 'department_id' => $dept, 'code' => $code, 'title' => $title, 'credits' => 15,
                'lecture_hours' => 3, 'tutorial_hours' => $practical > 0 ? 0 : 1, 'practical_hours' => $practical,
                'needs' => array_values(array_map(fn (string $n): string => $this->courses[$n] ?? '', $needs)), 'retired' => false]);
        }

        $yearOne = [['ACC 101', 1, 1, null], ['LAW 101', 1, 1, null], ['ICT 101', 1, 1, null], ['STA 101', 1, 1, null],
            ['ACC 102', 1, 2, null], ['ECO 101', 1, 2, null], ['MGT 101', 1, 2, null], ['TAX 201', 1, 2, null]];

        $dac = $programmes->save($by, $division, ['id' => null, 'department_id' => $acf, 'code' => 'DAC', 'name' => 'Diploma in Accounting', 'award' => Award::Diploma,
            'years' => 2, 'semesters' => 2, 'credits' => 240, 'min_load' => 45, 'max_load' => 75, 'open' => true, 'retired' => false]);
        $draft = $this->draftOf($dac);
        $programmes->saveDraft($by, $division, $draft, [
            ['key' => 'a', 'year' => 2, 'semester' => 1, 'label' => 'Elective group A', 'choose' => 1],
            ['key' => 'b', 'year' => 2, 'semester' => 2, 'label' => 'Elective group B', 'choose' => 2],
        ], $this->rows([...$yearOne, ['ACC 201', 2, 1, null], ['ACC 211', 2, 1, null], ['FIN 201', 2, 1, null], ['MKT 203', 2, 1, 'a'], ['ICT 201', 2, 1, 'a'],
            ['ACC 202', 2, 2, null], ['AUD 201', 2, 2, null], ['STA 202', 2, 2, 'b'], ['MGT 204', 2, 2, 'b'], ['CSC 101', 2, 2, 'b']]));
        $programmes->release($by, $division, $draft);

        $bac = $programmes->save($by, $division, ['id' => null, 'department_id' => $acf, 'code' => 'BAC', 'name' => 'Bachelor of Accountancy', 'award' => Award::Bachelor,
            'years' => 4, 'semesters' => 2, 'credits' => 480, 'min_load' => 45, 'max_load' => 75, 'open' => false, 'retired' => false]);
        $programmes->saveDraft($by, $division, $this->draftOf($bac), [['key' => 'a', 'year' => 2, 'semester' => 1, 'label' => 'Elective group A', 'choose' => 1]],
            $this->rows([...$yearOne, ['ACC 201', 2, 1, null], ['ACC 211', 2, 1, null], ['MKT 203', 2, 1, 'a'], ['ICT 201', 2, 1, 'a']]));

        $programmes->save($by, $division, ['id' => null, 'department_id' => $csc, 'code' => 'BSC-CS', 'name' => 'Bachelor of Science in Computer Science', 'award' => Award::Bachelor,
            'years' => 4, 'semesters' => 2, 'credits' => 480, 'min_load' => 45, 'max_load' => 75, 'open' => false, 'retired' => false]);

        $first = DB::table('academic_periods')->where('division_id', $division)->orderBy('starts_on')->first();
        if ($first !== null) {
            $period = Row::text($first, 'id');
            $added = $offerings->prepare($by, $division, $period);
            $staff = ProgrammesViews::staff();
            foreach (array_slice(ProgrammesViews::offerings($division, $period), 0, 4) as $i => $o) {
                if (isset($staff[$i])) {
                    $offerings->save($by, $division, $o['id'], 80, [$staff[$i]['id']], []);
                }
            }
            $this->command?->getOutput()->writeln("  Development college: {$added} offerings in the first semester.");
        }

        $this->students($clock, $calendar, $offerings, $admit, $students, $registration);
    }

    /** D-098: students in the Diploma, and the semester in progress started for them. */
    private function students(Clock $clock, AcademicCalendar $calendar, OfferingActions $offerings, AdmitLearner $admit, StudentActions $students, RegistrationActions $registration): void
    {
        if (DB::table('programme_students')->where('division_id', $this->division)->exists()) {
            return;
        }

        $year = $calendar->currentYear();
        $period = $year['terms'][$this->division][0] ?? null;
        $dac = DB::table('programmes')->where('division_id', $this->division)->where('code', 'DAC')->first(['id']);
        $campus = DB::table('institution_offerings')->where('division_id', $this->division)->first(['campus_id']);
        if ($year === null || $period === null || $dac === null || $campus === null) {
            return;
        }

        $now = $clock->now();
        $classes = [];
        foreach ([1, 2] as $level) {
            $have = DB::table('academic_classes')->where('year_id', $year['id'])->where('division_id', $this->division)->where('level_code', "Y{$level}")->whereNull('ended_at')->first(['id']);
            if ($have !== null) {
                $classes[$level] = Row::text($have, 'id');

                continue;
            }
            $classes[$level] = Ids::next();
            DB::table('academic_classes')->insert(['id' => $classes[$level], 'year_id' => $year['id'], 'division_id' => $this->division, 'campus_id' => Row::text($campus, 'campus_id'),
                'level_code' => "Y{$level}", 'stream' => null, 'name' => "Year {$level}", 'position' => $level, 'created_at' => $now, 'updated_at' => $now]);
        }

        $names = [['Chikondi', 'Banda'], ['Thokozani', 'Mwale'], ['Kondwani', 'Phiri'], ['Tiwonge', 'Chirwa'], ['Mphatso', 'Nkhoma'], ['Chimwemwe', 'Kaunda'],
            ['Limbani', 'Gondwe'], ['Takondwa', 'Msiska'], ['Madalitso', 'Tembo'], ['Yamikani', 'Zulu'], ['Atupele', 'Mvula'], ['Kumbukani', 'Lungu']];
        $placed = [];
        foreach ($names as $i => [$first, $surname]) {
            $level = $i < 8 ? 1 : 2;
            $learner = $admit->handle($this->by, ['first_name' => $first, 'other_names' => null, 'surname' => $surname, 'sex' => $i % 2 === 0 ? 'female' : 'male',
                'date_of_birth' => (2004 + $i % 3).'-0'.(1 + $i % 9).'-1'.($i % 9), 'class_id' => $classes[$level], 'admitted_on' => $period['starts_on'], 'admission_number' => null, 'different' => false],
                [['name' => "Grace {$surname}", 'relationship' => 'mother', 'phone' => '0991 '.(300 + $i).' 100', 'email' => null, 'main' => true],
                    ['name' => "John {$surname}", 'relationship' => 'father', 'phone' => '0888 '.(300 + $i).' 200', 'email' => null, 'main' => false]]);
            $students->place($this->by, $this->division, $learner->id, Row::text($dac, 'id'), $level, $i === 7 ? StudyMode::PartTime : StudyMode::FullTime);
            $placed[] = $learner->id;
        }

        $offerings->prepare($this->by, $this->division, $period['id']);
        $today = $clock->now();
        $registration->saveWindow($this->by, $this->division, $period['id'], ['opens_on' => $today->subDays(18)->toDateString(), 'closes_on' => $today->addDays(11)->toDateString(),
            'late_until' => $today->addDays(16)->toDateString(), 'add_drop_until' => $today->addDays(21)->toDateString(), 'late_fee' => 2500000, 'fees_share' => 50]);
        $started = $registration->startSemester($this->by, $this->division, $period['id']);

        // Approve four of the registrations waiting, so each state has someone in it.
        $waiting = DB::table('programme_registrations')->where('period_id', $period['id'])->where('state', State::Submitted->value)->orderBy('created_at')->limit(4)->pluck('id')->all();
        $approved = $waiting !== [] ? $registration->approve($this->by, $this->division, array_values(array_map(fn ($id): string => is_string($id) ? $id : '', $waiting))) : ['approved' => 0, 'registered' => 0];
        $this->command?->getOutput()->writeln('  Development college: '.count($placed)." students; {$started['opened']} registrations opened, {$started['sent']} sent for approval, {$approved['approved']} approved.");
    }

    private function makeDivision(Clock $clock): void
    {
        $now = $clock->now();
        $this->division = Ids::next();
        DB::table('institution_divisions')->insert(['id' => $this->division, 'name' => 'College', 'profile_ref' => 'TERTIARY@2026.1',
            'position' => DB::table('institution_divisions')->count() + 1, 'created_at' => $now, 'updated_at' => $now]);
        $campus = DB::table('institution_campuses')->orderBy('position')->first() ?? throw new RuntimeException('The school has no campus.');
        DB::table('institution_offerings')->insert(['id' => Ids::next(), 'division_id' => $this->division, 'campus_id' => Row::text($campus, 'id'), 'started_at' => $now]);

        foreach (DB::table('academic_years')->orderBy('starts_on')->get() as $year) {
            $start = (int) substr(Row::text($year, 'starts_on'), 0, 4);
            $next = $start + 1;
            foreach ([1 => ["{$start}-09-07", "{$start}-12-18"], 2 => ["{$next}-01-18", "{$next}-05-07"]] as $position => [$from, $to]) {
                DB::table('academic_periods')->insert(['id' => Ids::next(), 'year_id' => Row::text($year, 'id'), 'division_id' => $this->division, 'name' => "Semester {$position}",
                    'position' => $position, 'starts_on' => $from, 'ends_on' => $to, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $last = DB::table('module_switches')->where('module_key', 'programmes')->orderByDesc('switched_at')->first();
        if ($last === null || ! (bool) ($last->enabled ?? false)) {
            DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'programmes', 'enabled' => true, 'reason' => 'Development college.',
                'switched_by' => $this->by->id, 'switched_at' => $now]);
        }
        app()->forgetScopedInstances();
    }

    /** @param  list<string>  $prefixes */
    private function department(StructureActions $structure, string $faculty, string $name, string $code, array $prefixes): string
    {
        return $structure->saveDepartment($this->by, $this->division, ['id' => null, 'faculty_id' => $this->facultyId($faculty), 'name' => $name, 'code' => $code,
            'prefixes' => $prefixes, 'head_user_id' => null, 'retired' => false]);
    }

    private function facultyId(string $code): string
    {
        return Row::text(DB::table('programme_faculties')->where('division_id', $this->division)->where('code', $code)->first() ?? throw new RuntimeException("No faculty {$code}."), 'id');
    }

    private function draftOf(string $programme): string
    {
        return Row::text(DB::table('programme_curricula')->where('programme_id', $programme)->whereNull('released_at')->first() ?? throw new RuntimeException('No draft.'), 'id');
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int, 3: string|null}>  $rows
     * @return list<array{course_id: string, year: int, semester: int, group: string|null}>
     */
    private function rows(array $rows): array
    {
        $out = [];
        foreach ($rows as [$code, $year, $semester, $group]) {
            $out[] = ['course_id' => $this->courses[$code] ?? '', 'year' => $year, 'semester' => $semester, 'group' => $group];
        }

        return $out;
    }
}
