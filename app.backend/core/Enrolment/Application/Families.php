<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Academics\Contracts\AcademicCalendar;
use Acadly\Core\Enrolment\Application\Actions\FamilyActions;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Contracts\FamilyLine;
use Acadly\Core\Enrolment\Contracts\Guardianship;
use Acadly\Core\Enrolment\Contracts\Relationship;
use Acadly\Core\Enrolment\Journeys\GuardianLink\State;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\Boundary;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Identity\PhoneHolders;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Phone;
use Acadly\Kernel\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Families: the learners who share a contact phone number (D-082).
 *
 * The school's contact list proposes a family; the parent confirms each
 * child once. Only an active link lets a parent see a child. The office sees
 * every family by phone number, and what needs checking.
 */
final class Families implements Guardianship, PhoneHolders
{
    /** @var list<array{contact_id: string, learner_id: string, name: string, relationship: string, phone: string, first_name: string, learner: string, main: bool}>|null */
    private ?array $contacts = null;

    /** @param  iterable<FamilyLine>  $lines */
    public function __construct(
        private readonly AcademicCalendar $calendar,
        private readonly LearnerPlaces $places,
        private readonly Access $access,
        private readonly SchoolProfile $school,
        private readonly Clock $clock,
        private readonly iterable $lines = [],
    ) {}

    public function nameFor(string $phone): ?string
    {
        foreach ($this->contactsInForce() as $c) {
            if ($c['phone'] === $phone) {
                return $c['name'];
            }
        }

        return null;
    }

    public function sees(User $parent, string $learnerId): bool
    {
        return Ids::isValid($learnerId) && DB::table('learner_guardian_relationships')
            ->where('held_by', $parent->id)->where('learner_id', $learnerId)->where('state', State::Active->value)->exists();
    }

    public function child(User $parent, string $learnerId): ?array
    {
        return $this->sees($parent, $learnerId) ? $this->learnerCard($learnerId) : null;
    }

    public function children(User $parent): array
    {
        $out = [];

        foreach ($this->linksOf($parent) as $learnerId => $link) {
            $card = $link['state'] === State::Active->value ? $this->learnerCard((string) $learnerId) : null;

            if ($card !== null) {
                $out[] = $card;
            }
        }

        return $out;
    }

    /**
     * What the parent portal shows a signed-in parent: the children they see,
     * and the children the school's contact list proposes for them to confirm.
     *
     * @return array{school: array{name: string, phone: string|null}, parent: array{name: string}, children: list<array<string, mixed>>, to_confirm: list<array{id: string, name: string, class: string, relationship: string}>}
     */
    public function forParent(User $parent): array
    {
        $links = $this->linksOf($parent);
        $children = [];

        foreach ($links as $learnerId => $link) {
            if ($link['state'] !== State::Active->value && $link['state'] !== State::Suspended->value) {
                continue;
            }

            $child = $this->learnerCard($learnerId);

            if ($child === null) {
                continue;
            }

            $children[] = [...$child, 'state' => $link['state'], 'lines' => $link['state'] === State::Active->value ? $this->linesFor($learnerId, $child['class_id']) : []];
        }

        return [
            'school' => ['name' => $this->school->name(), 'phone' => Phone::forPeople($this->school->phone())],
            'parent' => ['name' => $parent->name],
            'children' => $children,
            'to_confirm' => $this->toConfirm($parent, $links),
        ];
    }

    /**
     * The learners this parent may be asked to confirm: their phone is a
     * contact of the learner, and no link of theirs to the learner is
     * active, suspended, disputed or ended.
     *
     * @param  array<string, array{id: string, state: string}>|null  $links
     * @return list<array{id: string, name: string, class: string, relationship: string, contact_id: string}>
     */
    public function toConfirm(User $parent, ?array $links = null): array
    {
        if ($parent->phone === null) {
            return [];
        }

        $links ??= $this->linksOf($parent, true);
        $out = [];

        foreach ($this->contactsInForce() as $c) {
            if ($c['phone'] !== $parent->phone || isset($out[$c['learner_id']])) {
                continue;
            }

            $state = $links[$c['learner_id']]['state'] ?? null;

            if ($state !== null && $state !== State::Requested->value) {
                continue;
            }

            $place = $this->places->current($c['learner_id']);
            $classId = $place !== null && $place['open'] ? $place['class_id'] : null;

            $out[$c['learner_id']] = [
                'id' => $c['learner_id'],
                'name' => $c['learner'],
                'class' => $this->className($classId),
                'relationship' => Relationship::tryFrom($c['relationship'])?->label() ?? $c['relationship'],
                'contact_id' => $c['contact_id'],
            ];
        }

        return array_values($out);
    }

    /**
     * The other learners at the school whose contacts share this phone
     * number, as the learner record shows them ("Also the contact of").
     * Only learners the viewer may see are named.
     *
     * @return list<array{id: string, name: string, class: string}>
     */
    public function alsoContactOf(User $viewer, string $phoneWritten, string $exceptLearner): array
    {
        $phone = Phone::international($phoneWritten);

        if ($phone === null) {
            return [];
        }

        $out = [];

        foreach ($this->contactsInForce() as $c) {
            if ($c['phone'] !== $phone || $c['learner_id'] === $exceptLearner || isset($out[$c['learner_id']])) {
                continue;
            }

            $place = $this->places->current($c['learner_id']);
            $classId = $place !== null && $place['open'] ? $place['class_id'] : null;

            if ($classId === null || ! $this->access->allows($viewer, EnrolmentPermission::View, ScopeRef::of(ScopeType::ClassGroup, $classId))) {
                continue;
            }

            $out[$c['learner_id']] = ['id' => $c['learner_id'], 'name' => $c['learner'], 'class' => $this->className($classId)];
        }

        return array_values($out);
    }

    /**
     * The phone numbers, in the international form, of the parents who see
     * this learner in the portal now (an active link).
     *
     * @return list<string>
     */
    public function portalPhones(string $learnerId): array
    {
        return array_values(array_filter(DB::table('learner_guardian_relationships as r')->join('guardians as g', 'g.id', '=', 'r.guardian_id')
            ->where('r.learner_id', $learnerId)->where('r.state', State::Active->value)->pluck('g.phone')->all(), 'is_string'));
    }

    /**
     * Every family, by phone number, for the office (Learners › Families).
     *
     * @return array{families: list<array<string, mixed>>, totals: array{families: int, in_portal: int, to_check: int, invitable: int}}
     */
    public function forOffice(User $by): array
    {
        $this->access->check($by, EnrolmentPermission::Families);

        $byPhone = [];

        // Only the learners the office may see in the place it works in (D-099). A learner with no class
        // now belongs to no division: shown only where no place bounds the request.
        $unbounded = app(Boundary::class)->offerings() === null;
        foreach ($this->contactsInForce() as $c) {
            $place = $this->places->current($c['learner_id']);
            $seen = $place !== null && $place['open']
                ? $this->access->allows($by, EnrolmentPermission::Families, ScopeRef::of(ScopeType::ClassGroup, $place['class_id']))
                : $unbounded;
            if ($seen) {
                $byPhone[$c['phone']][] = $c;
            }
        }

        $guardians = DB::table('guardians as g')->leftJoin('users as u', 'u.id', '=', 'g.user_id')
            ->get(['g.id', 'g.phone', 'u.last_signed_in_at', 'u.status'])->keyBy('phone')->all();
        $links = DB::table('learner_guardian_relationships')->where('state', '!=', State::Revoked->value)
            ->get(['id', 'guardian_id', 'learner_id', 'state'])->all();
        $linkOf = [];

        foreach ($links as $l) {
            $linkOf[Row::text($l, 'guardian_id')][Row::text($l, 'learner_id')] = ['id' => Row::text($l, 'id'), 'state' => Row::text($l, 'state'),
                'label' => State::from(Row::text($l, 'state'))->label()];
        }

        $classIds = [];
        $placeOf = [];

        foreach ($byPhone as $rows) {
            foreach ($rows as $c) {
                $place = $this->places->current($c['learner_id']);
                $placeOf[$c['learner_id']] = $place !== null && $place['open'] ? $place['class_id'] : null;

                if ($placeOf[$c['learner_id']] !== null) {
                    $classIds[] = $placeOf[$c['learner_id']];
                }
            }
        }

        $classNames = $this->calendar->classNames(array_values(array_unique($classIds)));
        $invitedAt = DB::table('portal_invitations')->groupBy('phone')->selectRaw('phone, max(invited_at) as at')->pluck('at', 'phone')->all();
        $againAfter = $this->clock->now()->subDays(FamilyActions::INVITE_AGAIN_DAYS);
        $invitable = 0;
        $families = [];
        $inPortal = 0;
        $toCheck = 0;

        foreach ($byPhone as $phone => $rows) {
            $guardian = $guardians[$phone] ?? null;
            $gid = $guardian !== null ? Row::text($guardian, 'id') : null;
            $names = [];
            $children = [];

            foreach ($rows as $c) {
                $names[mb_strtolower($c['name'])] = $c['name'];

                if (isset($children[$c['learner_id']])) {
                    continue;
                }

                $classId = $placeOf[$c['learner_id']] ?? null;
                $children[$c['learner_id']] = [
                    'id' => $c['learner_id'],
                    'name' => $c['learner'],
                    'class' => $classId !== null ? ($classNames[$classId]['name'] ?? '') : '',
                    'relationship' => Relationship::tryFrom($c['relationship'])?->label() ?? $c['relationship'],
                    'link' => $gid !== null ? ($linkOf[$gid][$c['learner_id']] ?? null) : null,
                ];
            }

            $checks = [];

            foreach ($children as $child) {
                if (($child['link']['state'] ?? null) === State::Disputed->value) {
                    $checks[] = ['kind' => 'not_their_child', 'learner' => $child['name']];
                }
            }

            if (count($names) > 1) {
                $checks[] = ['kind' => 'names_differ', 'learner' => null];
            }

            $signedIn = $guardian !== null ? Row::maybeText($guardian, 'last_signed_in_at') : null;
            $invited = is_string($invitedAt[$phone] ?? null) ? $invitedAt[$phone] : null;
            $mayInvite = $signedIn === null && ($invited === null || CarbonImmutable::parse($invited)->lessThan($againAfter));
            $inPortal += $signedIn !== null ? 1 : 0;
            $invitable += $mayInvite ? 1 : 0;
            $toCheck += $checks !== [] ? 1 : 0;

            $families[] = [
                'phone' => Phone::forPeople($phone),
                'names' => array_values($names),
                'children' => array_values($children),
                'portal' => ['signed_in' => $signedIn !== null, 'last_signed_in_at' => $signedIn, 'invited_at' => $invited, 'may_invite' => $mayInvite],
                'checks' => $checks,
            ];
        }

        usort($families, fn (array $a, array $b): int => [$b['checks'] !== [], count($b['children']), $a['names'][0]]
            <=> [$a['checks'] !== [], count($a['children']), $b['names'][0]]);

        return ['families' => $families, 'totals' => ['families' => count($families), 'in_portal' => $inPortal, 'to_check' => $toCheck, 'invitable' => $invitable]];
    }

    /**
     * The links of this parent, by learner: open ones, and ended ones when asked.
     *
     * @return array<string, array{id: string, state: string}>
     */
    public function linksOf(User $parent, bool $withEnded = true): array
    {
        $rows = DB::table('learner_guardian_relationships')->where('held_by', $parent->id)
            ->when(! $withEnded, fn ($q) => $q->where('state', '!=', State::Revoked->value))
            ->orderBy('created_at')->get(['id', 'learner_id', 'state'])->all();
        $out = [];

        foreach ($rows as $r) {
            $learner = Row::text($r, 'learner_id');
            $state = Row::text($r, 'state');

            // An open link wins over an ended one for the same learner.
            if (! isset($out[$learner]) || $out[$learner]['state'] === State::Revoked->value) {
                $out[$learner] = ['id' => Row::text($r, 'id'), 'state' => $state];
            }
        }

        return $out;
    }

    /** @return array{id: string, first_name: string, name: string, class_id: string|null, class: string}|null */
    private function learnerCard(string $learnerId): ?array
    {
        $row = DB::table('learners')->where('id', $learnerId)->first(['id', 'first_name', 'surname']);

        if ($row === null) {
            return null;
        }

        $place = $this->places->current($learnerId);
        $classId = $place !== null && $place['open'] ? $place['class_id'] : null;

        return [
            'id' => $learnerId,
            'first_name' => Row::text($row, 'first_name'),
            'name' => Row::text($row, 'first_name').' '.Row::text($row, 'surname'),
            'class_id' => $classId,
            'class' => $this->className($classId),
        ];
    }

    private function className(?string $classId): string
    {
        return $classId !== null ? ($this->calendar->classNames([$classId])[$classId]['name'] ?? '') : '';
    }

    /** @return list<array{key: string, word: string, label: string|null, tone: string}> */
    private function linesFor(string $learnerId, ?string $classId): array
    {
        $out = [];

        foreach ($this->lines as $line) {
            $one = $line->line($learnerId, $classId);

            if ($one !== null) {
                $out[] = $one;
            }
        }

        return $out;
    }

    /**
     * Whether a phone number (international form) is a contact of a learner the
     * office may see in the place it works in (D-099).
     */
    public function reachesPhone(User $by, string $phone): bool
    {
        foreach ($this->contactsInForce() as $c) {
            if ($c['phone'] !== $phone) {
                continue;
            }
            $place = $this->places->current($c['learner_id']);
            if ($place !== null && $place['open'] && $this->access->allows($by, EnrolmentPermission::Families, ScopeRef::of(ScopeType::ClassGroup, $place['class_id']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The contacts in force of learners at the school now, with the phone in
     * its international form. Read once per request.
     *
     * @return list<array{contact_id: string, learner_id: string, name: string, relationship: string, phone: string, first_name: string, learner: string, main: bool}>
     */
    private function contactsInForce(): array
    {
        if ($this->contacts !== null) {
            return $this->contacts;
        }

        $rows = DB::table('learner_contacts as c')
            ->join('learners as l', 'l.id', '=', 'c.learner_id')
            ->join('enrolments as e', fn (JoinClause $j): JoinClause => $j->on('e.learner_id', '=', 'c.learner_id')->where('e.status', '=', 'active'))
            ->whereNull('c.removed_at')
            ->orderByDesc('c.is_main')->orderBy('c.position')->orderBy('l.surname')->orderBy('l.first_name')
            ->get(['c.id', 'c.learner_id', 'c.name', 'c.relationship', 'c.phone', 'c.is_main', 'l.first_name', 'l.surname'])->all();
        $out = [];

        foreach ($rows as $r) {
            $phone = Phone::international(Row::text($r, 'phone'));

            if ($phone === null) {
                continue;
            }

            $out[] = [
                'contact_id' => Row::text($r, 'id'),
                'learner_id' => Row::text($r, 'learner_id'),
                'name' => Row::text($r, 'name'),
                'relationship' => Row::text($r, 'relationship'),
                'phone' => $phone,
                'first_name' => Row::text($r, 'first_name'),
                'learner' => Row::text($r, 'first_name').' '.Row::text($r, 'surname'),
                'main' => (bool) $r->is_main,
            ];
        }

        return $this->contacts = $out;
    }
}
