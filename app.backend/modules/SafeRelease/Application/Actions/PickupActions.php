<?php

declare(strict_types=1);

namespace Acadly\Modules\SafeRelease\Application\Actions;

use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\Guardianship;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\SafeRelease\Application\Collectors;
use Acadly\Modules\SafeRelease\Application\PickupReach;
use Acadly\Modules\SafeRelease\Contracts\SafeReleaseAudit;
use Acadly\Modules\SafeRelease\Contracts\SafeReleasePermission;
use Acadly\Modules\SafeRelease\Domain\Pickup;
use Acadly\Modules\SafeRelease\Journeys\Pickup\OutcomeId;
use Acadly\Modules\SafeRelease\Journeys\Pickup\State;
use Acadly\Modules\SafeRelease\Journeys\Pickup\StepId;
use Acadly\Modules\SafeRelease\Notifications\ChildReleased;
use Acadly\Modules\SafeRelease\Notifications\ReleaseRefused;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Safe pick-up (D-094). A release is checked against the family's list by
 * the journey's gate; a refusal is recorded with who came. The family is
 * told of both. Collectors and bars are ended, never deleted.
 */
final class PickupActions
{
    /** The most one-day collectors a family may add for a child on one day. */
    public const int DAY_LIMIT = 3;

    public function __construct(
        private readonly PickupReach $reach,
        private readonly Collectors $collectors,
        private readonly EnrolmentDirectory $directory,
        private readonly Blueprints $blueprints,
        private readonly JourneyEngine $engine,
        private readonly Notifier $notifier,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** Releases a child to someone on the list: a contact or a collector, with today's code where one is needed. */
    public function release(User $by, string $learnerId, string $kind, string $id, ?string $code): Pickup
    {
        $at = $this->reach->learner($by, $learnerId, SafeReleasePermission::Release);
        $today = $this->clock->today();
        $person = null;

        foreach ($this->collectors->mayCollect($learnerId, $today) as $c) {
            if ($c['kind'] === $kind && $c['id'] === $id) {
                $person = $c;
            }
        }

        if ($person === null) {
            throw ValidationException::withMessages(['collector' => ['This person is not on the list of who may collect the child.']]);
        }

        $codeOk = false;

        if ($person['needs_code']) {
            $hash = DB::table('release_collectors')->where('id', $id)->value('code_hash');
            $codeOk = is_string($hash) && $code !== null && hash_equals($hash, Collectors::codeHash($id, trim($code)));

            if (! $codeOk) {
                throw ValidationException::withMessages(['code' => ['The code is not right. Do not release the child. Call the family.']]);
            }
        }

        if ($this->collectedToday($learnerId)) {
            throw ValidationException::withMessages(['collector' => ['The child was already collected today.']]);
        }

        return DB::transaction(function () use ($by, $learnerId, $at, $today, $person, $codeOk): Pickup {
            $pickup = $this->start($by, $learnerId, $at, $today, $person['kind'], $person['id'], $person['name'], $person['relationship'], $codeOk, null);
            $this->engine->perform($pickup, $by, StepId::Verify, OutcomeId::Verified);
            $this->engine->perform($pickup, $by, StepId::Release, OutcomeId::Released);
            $now = $this->clock->now();
            DB::table('pickups')->where('id', $pickup->id)->update(['released_at' => $now, 'updated_at' => $now]);
            $this->notifier->send(new ChildReleased($pickup->id, $learnerId, (string) $at->id, CarbonImmutable::parse($now)->format('H:i'),
                $person['name'].' ('.$person['relationship'].')'), $by);

            return $pickup;
        });
    }

    /** Records that someone not on the list came, and that the child was kept. */
    public function refuse(User $by, string $learnerId, string $name, string $note): Pickup
    {
        $at = $this->reach->learner($by, $learnerId, SafeReleasePermission::Release);
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Say who came: their name, and how they know the child.']]);
        }

        return DB::transaction(function () use ($by, $learnerId, $at, $name, $note): Pickup {
            $pickup = $this->start($by, $learnerId, $at, $this->clock->today(), Pickup::UNKNOWN, null, mb_substr($name, 0, 120), null, false, trim($note) !== '' ? mb_substr(trim($note), 0, 2000) : null);
            $this->engine->perform($pickup, $by, StepId::Dispute, OutcomeId::NotOnTheList, ['reason' => trim($note) !== '' ? trim($note) : $name]);
            $this->notifier->send(new ReleaseRefused($pickup->id, $learnerId, (string) $at->id, CarbonImmutable::parse($this->clock->now())->format('H:i'), $pickup->collector_name), $by);

            return $pickup;
        });
    }

    /** Staff add someone who may collect: for good, or for today only. */
    public function addCollector(User $by, string $learnerId, string $name, string $relationship, ?string $phone, bool $todayOnly): string
    {
        $at = $this->reach->learner($by, $learnerId, SafeReleasePermission::Manage);
        [$name, $relationship, $phone] = self::person($name, $relationship, $phone);
        $id = Ids::next();

        DB::transaction(function () use ($by, $learnerId, $at, $id, $name, $relationship, $phone, $todayOnly): void {
            DB::table('release_collectors')->insert(['id' => $id, 'learner_id' => $learnerId, 'name' => $name, 'relationship' => $relationship, 'phone' => $phone,
                'valid_on' => $todayOnly ? $this->clock->today() : null, 'code_hash' => null, 'added_as' => 'staff', 'added_by' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(SafeReleaseAudit::CollectorAdded, actor: $this->audit->actorFor($by), at: $at, aboutPersonId: $learnerId, meta: ['collector' => $id, 'today_only' => $todayOnly]);
        });

        return $id;
    }

    /**
     * A parent adds someone to collect their child today. The code is shown
     * once, for the parent to pass on; only its hash is kept.
     *
     * @return array{id: string, code: string}
     */
    public function addForToday(User $parent, Guardianship $guardianship, string $learnerId, string $name, string $relationship, ?string $phone): array
    {
        $child = $guardianship->child($parent, $learnerId) ?? throw new NotFoundHttpException;
        $place = $this->directory->placeOf($learnerId);

        if ($place === null || ! $place['open']) {
            throw new NotFoundHttpException;
        }

        [$name, $relationship, $phone] = self::person($name, $relationship, $phone);
        $today = $this->clock->today();

        if (DB::table('release_collectors')->where('learner_id', $child['id'])->where('valid_on', $today)->where('added_as', 'family')->count() >= self::DAY_LIMIT) {
            throw ValidationException::withMessages(['name' => ['Three people have been added for today. Please call the school.']]);
        }

        $id = Ids::next();
        $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($parent, $learnerId, $place, $id, $name, $relationship, $phone, $today, $code): void {
            DB::table('release_collectors')->insert(['id' => $id, 'learner_id' => $learnerId, 'name' => $name, 'relationship' => $relationship, 'phone' => $phone,
                'valid_on' => $today, 'code_hash' => Collectors::codeHash($id, $code), 'added_as' => 'family', 'added_by' => $parent->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(SafeReleaseAudit::CollectorAdded, actor: $this->audit->actorFor($parent), at: ScopeRef::of(ScopeType::ClassGroup, $place['class_id']),
                aboutPersonId: $learnerId, meta: ['collector' => $id, 'today_only' => true, 'by_family' => true]);
        });

        return ['id' => $id, 'code' => $code];
    }

    public function endCollector(User $by, string $collectorId): void
    {
        $learner = DB::table('release_collectors')->where('id', $collectorId)->whereNull('ended_at')->value('learner_id');

        if (! is_string($learner)) {
            throw new NotFoundHttpException;
        }

        $at = $this->reach->learner($by, $learner, SafeReleasePermission::Manage);

        DB::transaction(function () use ($by, $collectorId, $learner, $at): void {
            DB::table('release_collectors')->where('id', $collectorId)->update(['ended_at' => $this->clock->now(), 'ended_by' => $by->id]);
            $this->audit->record(SafeReleaseAudit::CollectorEnded, actor: $this->audit->actorFor($by), at: $at, aboutPersonId: $learner, meta: ['collector' => $collectorId]);
        });
    }

    /** Records that a contact must not collect the child, with the reason (for example a court order). */
    public function bar(User $by, string $learnerId, string $contactId, string $reason): void
    {
        $at = $this->reach->learner($by, $learnerId, SafeReleasePermission::Manage);

        if (! in_array($contactId, array_column($this->directory->contactsOf($learnerId), 'id'), true)) {
            throw ValidationException::withMessages(['contact_id' => ['Choose one of the child\'s contacts.']]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Say why this person must not collect the child.']]);
        }

        DB::transaction(function () use ($by, $learnerId, $contactId, $reason, $at): void {
            DB::table('release_bars')->insert(['id' => Ids::next(), 'learner_id' => $learnerId, 'contact_id' => $contactId, 'reason' => mb_substr(trim($reason), 0, 2000),
                'set_by' => $by->id, 'created_at' => $this->clock->now()]);
            $this->audit->record(SafeReleaseAudit::Barred, actor: $this->audit->actorFor($by), at: $at, reason: trim($reason), aboutPersonId: $learnerId, meta: ['contact' => $contactId]);
        });
    }

    public function endBar(User $by, string $barId, string $reason): void
    {
        $learner = DB::table('release_bars')->where('id', $barId)->whereNull('ended_at')->value('learner_id');

        if (! is_string($learner)) {
            throw new NotFoundHttpException;
        }

        $at = $this->reach->learner($by, $learner, SafeReleasePermission::Manage);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Say why the record ends.']]);
        }

        DB::transaction(function () use ($by, $barId, $learner, $at, $reason): void {
            DB::table('release_bars')->where('id', $barId)->update(['ended_at' => $this->clock->now(), 'ended_by' => $by->id]);
            $this->audit->record(SafeReleaseAudit::BarEnded, actor: $this->audit->actorFor($by), at: $at, reason: trim($reason), aboutPersonId: $learner, meta: ['bar' => $barId]);
        });
    }

    public function collectedToday(string $learnerId): bool
    {
        return Pickup::query()->where('learner_id', $learnerId)->where('day', $this->clock->today())->where('state', State::Released->value)->exists();
    }

    private function start(User $by, string $learnerId, ScopeRef $at, string $day, string $kind, ?string $ref, string $name, ?string $relationship, bool $codeOk, ?string $note): Pickup
    {
        return Pickup::query()->create([
            'learner_id' => $learnerId, 'class_id' => (string) $at->id, 'day' => $day, 'state' => State::Announced->value,
            'journey_ref' => (string) $this->blueprints->newest('saferelease.pickup'), 'collector_kind' => $kind, 'collector_ref' => $ref,
            'collector_name' => $name, 'collector_relationship' => $relationship, 'code_checked' => $codeOk, 'note' => $note, 'created_by' => $by->id,
        ]);
    }

    /** @return array{0: string, 1: string, 2: string|null} */
    private static function person(string $name, string $relationship, ?string $phone): array
    {
        $name = trim($name);
        $relationship = trim($relationship);

        if ($name === '' || $relationship === '') {
            throw ValidationException::withMessages(['name' => ['Give their name and how they know the child.']]);
        }

        $phone = $phone !== null && trim($phone) !== '' ? mb_substr(trim($phone), 0, 30) : null;

        return [mb_substr($name, 0, 120), mb_substr($relationship, 0, 60), $phone];
    }
}
