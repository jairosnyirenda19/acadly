<?php

declare(strict_types=1);

namespace Database\Seeders;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Programmes\Application\Actions\CourseActions;
use Acadly\Modules\Programmes\Application\Actions\OfferingActions;
use Acadly\Modules\Programmes\Application\Actions\ProgrammeActions;
use Acadly\Modules\Programmes\Application\Actions\StructureActions;
use Acadly\Modules\Programmes\Application\ProgrammesViews;
use Acadly\Modules\Programmes\Contracts\Award;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * D-097. A college division for the development school, to try Programmes:
 * the division, its semesters, three faculties, a catalogue, a released
 * Diploma in Accounting, a Bachelor of Accountancy still in draft, and the
 * first semester's offerings. The division and its semesters are written
 * straight to the tables, as setup would; the rest goes through the Actions.
 *
 * Local only. Run: php artisan db:seed --class=DevelopmentCollegeSeeder
 */
final class DevelopmentCollegeSeeder extends Seeder
{
    /** @var array<string, string> course code => id */
    private array $courses = [];

    private User $by;

    private string $division = '';

    public function run(Clock $clock, StructureActions $structure, CourseActions $courses, ProgrammeActions $programmes, OfferingActions $offerings): void
    {
        if (! app()->environment(['local'])) {
            throw new RuntimeException('The development college is seeded only in the local environment.');
        }

        $email = mb_strtolower(trim(is_string($e = config('acadly.development.admin_email')) ? $e : ''));
        $this->by = User::query()->where('email', $email)->first() ?? throw new RuntimeException('Seed the development administrator first.');

        if (DB::table('institution_divisions')->where('profile_ref', 'like', 'TERTIARY@%')->whereNull('ended_at')->exists()) {
            $this->command?->getOutput()->writeln('  The development college is there already.');

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
