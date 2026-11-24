<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Application;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\SafeRelease\Contracts\SafeReleasePermission;
use Acadly\Modules\SafeRelease\Domain\Pickup;
use Acadly\Modules\SafeRelease\Journeys\Pickup\State;

/**
 * What the pick-up screens show (D-094): the desk by room, one child's list
 * of who may collect, and the family's view.
 *
 * @phpstan-import-type MayCollect from Collectors
 *
 * @phpstan-type PickupRow array{id: string, state: string, state_label: string, name: string, relationship: string|null, at: string, note: string|null}
 */
final class PickupViews
{
    public function __construct(
        private readonly PickupReach $reach,
        private readonly Collectors $collectors,
        private readonly EnrolmentDirectory $directory,
        private readonly Clock $clock,
    ) {}

    /**
     * The rooms the account sees and, for the chosen room, each child today.
     *
     * @return array{day: string, rooms: list<array{id: string, name: string}>, room: array{id: string, name: string}|null, children: list<array{id: string, name: string, collected: PickupRow|null, refused: int}>, at_gate: list<array{pickup_id: string, learner_id: string, child: string, name: string, relationship: string|null, kind: string, ref: string|null, needs_code: bool, at: string}>, totals: array{children: int, collected: int, here: int, at_gate: int}, can_release: bool}
     */
    public function desk(User $who, ?string $classId): array
    {
        $rooms = $this->reach->rooms($who);
        $room = null;

        foreach ($rooms as $r) {
            if ($r['id'] === $classId || ($room === null && $classId === null)) {
                $room = $r;
            }
        }

        $today = $this->clock->today();
        $children = $room === null ? [] : $this->directory->learnersIn($room['id'], $today);
        $byChild = [];

        if ($room !== null) {
            foreach (Pickup::query()->where('class_id', $room['id'])->where('day', $today)->orderBy('created_at')->get() as $p) {
                $byChild[$p->learner_id][] = $p;
            }
        }

        $rows = [];
        $collected = 0;
        $names = [];
        foreach ($children as $c) {
            $names[$c['id']] = $c['name'];
        }
        // D-108: who the gate marked as arrived, waiting for the room to bring the child.
        $atGate = [];
        foreach ($byChild as $learnerId => $list) {
            foreach ($list as $p) {
                if ($p->state !== State::Announced->value) {
                    continue;
                }
                $needs = false;
                foreach ($this->collectors->mayCollect((string) $learnerId, $today) as $m) {
                    if ($m['kind'] === $p->collector_kind && $m['id'] === $p->collector_ref) {
                        $needs = $m['needs_code'];
                    }
                }
                $atGate[] = ['pickup_id' => $p->id, 'learner_id' => (string) $learnerId, 'child' => $names[$learnerId] ?? '', 'name' => $p->collector_name,
                    'relationship' => $p->collector_relationship, 'kind' => $p->collector_kind, 'ref' => $p->collector_ref, 'needs_code' => $needs,
                    'at' => $p->created_at->toIso8601String()];
            }
        }

        foreach ($children as $c) {
            $done = null;
            $refused = 0;

            foreach ($byChild[$c['id']] ?? [] as $p) {
                if ($p->state === State::Released->value) {
                    $done = self::row($p);
                }
                $refused += $p->state === State::Disputed->value ? 1 : 0;
            }

            $collected += $done !== null ? 1 : 0;
            $rows[] = ['id' => $c['id'], 'name' => $c['name'], 'collected' => $done, 'refused' => $refused];
        }

        return [
            'day' => $today,
            'rooms' => array_map(fn (array $r): array => ['id' => $r['id'], 'name' => $r['name']], $rooms),
            'room' => $room === null ? null : ['id' => $room['id'], 'name' => $room['name']],
            'children' => $rows,
            'at_gate' => $atGate,
            'totals' => ['children' => count($rows), 'collected' => $collected, 'here' => count($rows) - $collected, 'at_gate' => count($atGate)],
            'can_release' => $room !== null && $this->reach->may($who, SafeReleasePermission::Release, $room['id']),
        ];
    }

    /**
     * One child: who may collect today, who must not, and today's pick-ups.
     *
     * @return array{learner: array{id: string, name: string}, may_collect: list<MayCollect>, barred: list<array{id: string, contact_id: string, name: string, reason: string, since: string}>, contacts: list<array{id: string, name: string}>, today: list<PickupRow>, recent: list<array{id: string, state: string, state_label: string, name: string, relationship: string|null, at: string, note: string|null, day: string}>, can: array{release: bool, manage: bool}}
     */
    public function child(User $who, string $learnerId): array
    {
        $at = $this->reach->learner($who, $learnerId);
        $today = $this->clock->today();
        $classId = (string) $at->id;
        $manage = $this->reach->may($who, SafeReleasePermission::Manage, $classId);
        $all = Pickup::query()->where('learner_id', $learnerId)->orderByDesc('created_at')->limit(30)->get()->all();

        return [
            'learner' => ['id' => $learnerId, 'name' => $this->directory->names([$learnerId])[$learnerId] ?? ''],
            'may_collect' => $this->collectors->mayCollect($learnerId, $today),
            'barred' => $manage ? $this->collectors->bars($learnerId) : [],
            'contacts' => $manage ? array_map(fn (array $c): array => ['id' => $c['id'], 'name' => $c['name']], $this->directory->contactsOf($learnerId)) : [],
            'today' => array_values(array_map(fn (Pickup $p): array => self::row($p), array_filter($all, fn (Pickup $p): bool => $p->day->toDateString() === $today))),
            'recent' => array_values(array_map(fn (Pickup $p): array => self::row($p) + ['day' => $p->day->toDateString()], array_filter($all, fn (Pickup $p): bool => $p->day->toDateString() !== $today))),
            'can' => ['release' => $this->reach->may($who, SafeReleasePermission::Release, $classId), 'manage' => $manage],
        ];
    }

    /**
     * What the family sees today: who collected the child, and who may collect.
     *
     * @return array{today: PickupRow|null, may_collect: list<array{name: string, relationship: string, day_only: bool}>}
     */
    public function family(string $learnerId): array
    {
        $today = $this->clock->today();
        $pickup = Pickup::query()->where('learner_id', $learnerId)->where('day', $today)->whereIn('state', [State::Released->value, State::Disputed->value])
            ->orderByDesc('created_at')->first();

        return [
            'today' => $pickup instanceof Pickup ? self::row($pickup) : null,
            'may_collect' => array_map(fn (array $c): array => ['name' => $c['name'], 'relationship' => $c['relationship'], 'day_only' => $c['day_only']],
                $this->collectors->mayCollect($learnerId, $today)),
        ];
    }

    /** @return PickupRow */
    private static function row(Pickup $p): array
    {
        $at = $p->released_at ?? $p->created_at;

        return ['id' => $p->id, 'state' => $p->state, 'state_label' => State::from($p->state)->label(), 'name' => $p->collector_name,
            'relationship' => $p->collector_relationship, 'at' => $at->format('H:i'), 'note' => $p->note];
    }
}
