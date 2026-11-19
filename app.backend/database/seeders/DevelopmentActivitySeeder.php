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
