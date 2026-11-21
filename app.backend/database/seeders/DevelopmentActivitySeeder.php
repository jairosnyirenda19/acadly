<?php

declare(strict_types=1);

namespace Database\Seeders;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Application\Actions\PaymentActions;
use Acadly\Modules\Fees\Application\FeeOffices;
use Acadly\Modules\Fees\Domain\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * D-100. A term of activity for the development school, so the dashboards
 * have something to draw: the development administrator named to every
 * finance office; twelve weeks of morning registers for every class, with
 * a few classes slipping; some reasons given by families; and payments
 * against the term's bills, spread over the weeks. Registers are written
 * straight to the tables, as the registers screen would leave them; payments
 * go through the Action. Running it again adds only what is missing.
 *
 * Local only. Run: php artisan db:seed --class=DevelopmentActivitySeeder
 */
final class DevelopmentActivitySeeder extends Seeder
{
    public function run(Clock $clock, AcademicCalendar $calendar, PaymentActions $payments, FeeOffices $offices): void
    {
        if (! app()->environment(['local'])) {
            throw new RuntimeException('Development activity is seeded only in the local environment.');
        }

        $email = mb_strtolower(trim(is_string($e = config('acadly.development.admin_email')) ? $e : ''));
        $by = User::query()->where('email', $email)->first() ?? throw new RuntimeException('Seed the development administrator first.');
        $today = $clock->today();

        foreach ($offices->offices() as $office) {
            if (! FeeOffices::works($by, $office['id'])) {
                DB::table('fee_office_staff')->insert(['office_id' => $office['id'], 'user_id' => $by->id, 'added_by' => $by->id, 'added_at' => $clock->now()]);
            }
        }

        $registers = $this->registers($calendar, $by, $today);
        $paid = $this->payments($payments, $by, $today);
        $this->command?->getOutput()->writeln("  {$registers} registers and {$paid} payments added.");
        $this->college($by, $clock);
    }

    /**
     * D-101. In the college: News switched on with two posts, and one student account to sign in
     * with, its password taken from DEV_STUDENT_PASSWORD in the local .env (never kept in code).
     */
    private function college(User $by, Clock $clock): void
    {
        $college = DB::table('institution_divisions')->where('profile_ref', 'like', 'TERTIARY@%')->whereNull('ended_at')->value('id');
        $password = config('acadly.development.student_password');
        if (! is_string($college) || ! is_string($password) || mb_strlen($password) < 12) {
            return;
        }
        if (! DB::table('module_switches')->where('module_key', 'news')->exists()) {
            DB::table('module_switches')->insert(['id' => Ids::next(), 'module_key' => 'news', 'enabled' => true, 'reason' => 'Development school.', 'switched_at' => $clock->now()]);
        }
        $learner = DB::table('programme_students')->where('division_id', $college)->whereNull('ended_at')->orderBy('created_at')->value('learner_id');
        if (! is_string($learner)) {
            return;
        }
        $account = DB::table('programme_student_accounts')->where('learner_id', $learner)->value('user_id');
        if (! is_string($account)) {
            $name = DB::table('learners')->where('id', $learner)->first(['first_name', 'surname']);
            $user = User::query()->create(['name' => $name === null ? 'Student' : Row::text($name, 'first_name').' '.Row::text($name, 'surname'),
                'email' => $learner.'@students.invalid', 'password' => Hash::make($password), 'status' => 'active']);
            DB::table('programme_student_accounts')->insert(['learner_id' => $learner, 'division_id' => $college, 'user_id' => $user->id, 'phone' => '+265991234534',
                'code_sent_at' => $clock->now(), 'set_up_at' => $clock->now(), 'created_by' => $by->id, 'created_at' => $clock->now()]);
        }
        if (! DB::table('news_posts')->where('division_id', $college)->exists()) {
            foreach ([['Semester 1 examination timetable', "The examination timetable for Semester 1 is published.\n\nCheck the room and the time of each paper. Bring your student card to every paper.", '2026-12-20'],
                ['Library hours for the exam weeks', 'The library opens from 07:00 to 21:00 on weekdays during the examinations.', null]] as [$title, $body, $pinned]) {
                $id = Ids::next();
                DB::table('news_posts')->insert(['id' => $id, 'root_id' => $id, 'version' => 1, 'division_id' => $college, 'title' => $title, 'body' => $body, 'audience' => 'everyone',
                    'pinned_until' => $pinned, 'publish_at' => $clock->now()->subHours(3), 'author_id' => $by->id, 'created_at' => $clock->now()]);
            }
        }
        $this->command?->getOutput()->writeln('  The college student account and two news posts are ready (DEV_STUDENT_NUMBER in .env).');
    }

    private function registers(AcademicCalendar $calendar, User $by, string $today): int
    {
        $year = $calendar->currentYear($today);
        if ($year === null) {
            return 0;
        }
        $monday = CarbonImmutable::parse($today)->startOfWeek(CarbonImmutable::MONDAY)->subWeeks(11);
        $kinds = ['sick', 'sick', 'family', 'travel', 'other'];
        $made = 0;

        foreach ($calendar->classesInYear($year['id']) as $n => $class) {
            // One class in five slips week by week; the rest hold steady.
            $slips = $n % 5 === 3;
            for ($day = $monday; $day->toDateString() < $today; $day = $day->addDay()) {
                if ($day->isWeekend() || $day->toDateString() < $year['starts_on']) {
                    continue;
                }
                $date = $day->toDateString();
                if (DB::table('attendance_registers')->where('class_id', $class['id'])->where('day', $date)->where('kind', 'morning')->exists()) {
                    continue;
                }
                $learners = DB::table('placements')->where('class_id', $class['id'])->where('starts_on', '<=', $date)
                    ->where(fn (Builder $q): Builder => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))->pluck('learner_id')->map(fn ($v): string => is_string($v) ? $v : '')->all();
                if ($learners === []) {
                    continue;
                }
                $week = (int) $monday->diffInWeeks($day);
                $away = 5 + ($slips ? $week : 0);
                $id = Ids::next();
                $at = $date.' 07:40:00';
                DB::table('attendance_registers')->insert(['id' => $id, 'class_id' => $class['id'], 'day' => $date, 'kind' => 'morning', 'submitted_by' => $by->id, 'submitted_at' => $at]);
                foreach ($learners as $learner) {
                    $roll = crc32($learner.$date) % 100;
                    $absent = $roll < $away;
                    DB::table('attendance_marks')->insert(['id' => Ids::next(), 'register_id' => $id, 'learner_id' => $learner, 'status' => $absent ? 'absent' : ($roll > 97 ? 'late' : 'present'),
                        'method' => 'tap', 'captured_by' => $by->id, 'captured_at' => $at, 'received_at' => $at]);
                    if ($absent && $roll % 3 !== 0) {
                        DB::table('attendance_absence_reasons')->insert(['id' => Ids::next(), 'register_id' => $id, 'learner_id' => $learner, 'kind' => $kinds[$roll % 5],
                            'note' => null, 'given_by' => $by->id, 'given_at' => $date.' 09:10:00']);
                    }
                }
                $made++;
            }
        }

        return $made;
    }

    private function payments(PaymentActions $payments, User $by, string $today): int
    {
        $made = 0;
        $methods = [PaymentMethod::Cash, PaymentMethod::AirtelMoney, PaymentMethod::Mpamba, PaymentMethod::Bank, PaymentMethod::Cash];

        foreach (DB::table('fee_charges as c')->join('fee_schedules as s', 's.id', '=', 'c.schedule_id')->join('academic_periods as p', 'p.id', '=', 's.period_id')
            ->get(['c.id', 'c.learner_id', 'c.total_minor', 'p.starts_on']) as $charge) {
            $learner = Row::text($charge, 'learner_id');
            if (DB::table('fee_payment_shares')->where('learner_id', $learner)->exists()) {
                continue;
            }
            $roll = crc32($learner) % 100;
            $share = $roll < 25 ? 0 : ($roll < 55 ? 50 : ($roll < 70 ? 80 : 100));
            $amount = intdiv(Row::int($charge, 'total_minor') * $share, 100);
            if ($amount <= 0) {
                continue;
            }
            $start = CarbonImmutable::parse(Row::text($charge, 'starts_on'));
            $span = max(1, (int) $start->diffInDays(CarbonImmutable::parse($today)));
            $on = $start->addDays(min($span, (int) (($roll * 7) % 100 / 100 * $span)))->toDateString();
            $method = $methods[$roll % 5];
            $payments->record($by, $method, $method->needsReference() ? 'DEV'.substr(str_replace('-', '', $learner), -10) : null, min($on, $today), 'Family of the learner', null,
                $amount, [['learner_id' => $learner, 'amount' => $amount, 'charge_id' => Row::text($charge, 'id')]], false);
            $made++;
        }

        return $made;
    }
}
