<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\Events\LearnerLeft;
use Acadly\Kernel\Access\Permission;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Approvals\Actions\ApprovalActions;
use Acadly\Kernel\Approvals\ApprovalCase;
use Acadly\Kernel\Approvals\ApprovalTopic;
use Acadly\Kernel\Events\DomainEvent;
use Acadly\Kernel\Events\EventConsumer;
use Acadly\Kernel\Events\EventRecord;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Acadly\Modules\Fees\Application\Actions\ChargeActions;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Acadly\Modules\Fees\Domain\AdjustmentKind;
use Acadly\Modules\Fees\Domain\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Fees not yet due when a learner leaves during the year (D-114). They stay
 * owed unless the office asked to waive them; then what is not yet due after
 * the last day waits for the bursar's yes (`fees.waive_on_leaving`), and the
 * last yes makes one waiver per charge. Nothing owed by the last day is waived.
 */
final class LeavingWaivers implements ApprovalTopic, EventConsumer
{
    public const string KEY = 'fees.waive_on_leaving';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly EnrolmentDirectory $directory,
        private readonly Clock $clock,
    ) {}

    public static function consumerKey(): string
    {
        return 'fees.waive_on_leaving';
    }

    public function handle(DomainEvent $event, EventRecord $record): void
    {
        if (! $event instanceof LearnerLeft || ! $event->waiveAsked) {
            return;
        }

        $byCharge = [];

        foreach ($this->ledger->standing($event->learnerId)['instalments'] as $i) {
            $left = max(0, $i['amount'] - $i['covered']);

            if ($left > 0 && $i['due_on'] > $event->lastDay) {
                $byCharge[$i['charge_id']] = ($byCharge[$i['charge_id']] ?? 0) + $left;
            }
        }

        $total = array_sum($byCharge);

        if ($total === 0) {
            return;
        }

        $id = Ids::next();
        DB::table('fee_leaving_waivers')->insert(['id' => $id, 'learner_id' => $event->learnerId, 'last_day' => $event->lastDay,
            'lines' => json_encode(array_map(fn (string $charge, int $amount): array => ['charge_id' => $charge, 'amount' => $amount], array_keys($byCharge), $byCharge), JSON_THROW_ON_ERROR),
            'total_minor' => $total, 'requested_by' => $event->recordedBy, 'created_at' => $this->clock->now()]);
        app(ApprovalActions::class)->open(self::KEY, $id);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function module(): string
    {
        return 'fees';
    }

    public function area(): string
    {
        return 'Fees';
    }

    public function title(): string
    {
        return 'Waive fees when a learner leaves';
    }

    public function description(): string
    {
        return 'A learner left during the year and the office asked to waive the instalments not yet due. Nothing is waived until the last yes.';
    }

    public function permission(): Permission
    {
        return FeesPermission::Adjust;
    }

    public function relations(): array
    {
        return [];
    }

    public function facts(): array
    {
        return [];
    }

    public function case(string $subjectId): ?ApprovalCase
    {
        $row = Ids::isValid($subjectId) ? DB::table('fee_leaving_waivers')->where('id', $subjectId)->first() : null;

        if ($row === null) {
            return null;
        }

        $learner = Row::text($row, 'learner_id');
        $class = $this->directory->placeOf($learner)['class_id'] ?? null;
        $name = $this->directory->names([$learner])[$learner] ?? '';

        return new ApprovalCase(
            requesterId: Row::text($row, 'requested_by'),
            at: $class !== null ? ScopeRef::of(ScopeType::ClassGroup, $class) : ScopeRef::institution(),
            title: 'Waive fees not yet due · '.$name,
            where: 'Left on '.CarbonImmutable::parse(Row::text($row, 'last_day'))->format('D j M Y').' · '.Money::text(Row::int($row, 'total_minor')),
            reason: 'Left the school during the year. The office asked to waive the instalments due after the last day.',
            link: '/learners/'.$learner,
            related: [],
            facts: [],
        );
    }

    public function settle(User $by, string $subjectId, bool $approved, ?string $reason): void
    {
        $row = DB::table('fee_leaving_waivers')->where('id', $subjectId)->first();

        if (! $approved || $row === null) {
            return;
        }

        $lines = json_decode(Row::text($row, 'lines'), true);
        $why = 'Left the school on '.CarbonImmutable::parse(Row::text($row, 'last_day'))->format('j M Y').': instalments not yet due waived.';

        foreach (is_array($lines) ? $lines : [] as $line) {
            if (is_array($line) && is_string($line['charge_id'] ?? null) && is_int($line['amount'] ?? null) && $line['amount'] > 0) {
                app(ChargeActions::class)->adjust($by, $line['charge_id'], AdjustmentKind::Waiver, $line['amount'], $why);
            }
        }
    }
}
