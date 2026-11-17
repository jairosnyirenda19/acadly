<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application\Actions;

use Acadly\Core\Enrolment\Application\Families;
use Acadly\Core\Enrolment\Contracts\EnrolmentAudit;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Domain\GuardianLink;
use Acadly\Core\Enrolment\Journeys\GuardianLink\OutcomeId;
use Acadly\Core\Enrolment\Journeys\GuardianLink\State;
use Acadly\Core\Enrolment\Journeys\GuardianLink\StepId;
use Acadly\Core\Enrolment\Notifications\PortalInvitation;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Blueprint\Blueprints;
use Acadly\Kernel\Blueprint\Engine\JourneyEngine;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Notifier;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Phone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The parent link (`guardian.link`, D-082): the parent confirms the children
 * the school's contact list proposes; the office suspends, restores, asks
 * again or ends a link. Every state change goes through the journey engine.
 */
final class FamilyActions
{
    private const array OFFICE = [
        'suspend' => [StepId::Suspend, OutcomeId::Suspended],
        'reinstate' => [StepId::Reinstate, OutcomeId::Reinstated],
        'recheck' => [StepId::Recheck, OutcomeId::AskedAgain],
        'revoke' => [StepId::Revoke, OutcomeId::Revoked],
    ];

    /** A family invited this many days ago or less is not invited again. */
    public const int INVITE_AGAIN_DAYS = 7;

    public function __construct(
        private readonly Families $families,
        private readonly JourneyEngine $engine,
        private readonly Blueprints $blueprints,
        private readonly Audit $audit,
        private readonly Clock $clock,
        private readonly Access $access,
        private readonly Notifier $notifier,
    ) {}

    /**
     * The parent answers for each child proposed: theirs, or not theirs.
     * A child left out stays to confirm.
     *
     * @param  list<string>  $mine
     * @param  list<string>  $notMine
     */
    public function confirm(User $parent, array $mine, array $notMine): void
    {
        if ($parent->phone === null) {
            throw new AuthorizationException('Access is refused.');
        }

        if (array_intersect($mine, $notMine) !== []) {
            throw ValidationException::withMessages(['learners' => ['A child is either yours or not yours.']]);
        }

        $proposed = [];

        foreach ($this->families->toConfirm($parent) as $p) {
            $proposed[$p['id']] = $p;
        }

        foreach ([...$mine, ...$notMine] as $id) {
            if (! isset($proposed[$id])) {
                throw ValidationException::withMessages(['learners' => ['This child is not one the school proposes for this phone number.']]);
            }
        }

        DB::transaction(function () use ($parent, $mine, $notMine, $proposed): void {
            $guardianId = $this->guardian($parent);
            $links = $this->families->linksOf($parent, false);

            foreach ([[OutcomeId::Confirmed, $mine], [OutcomeId::NotMine, $notMine]] as [$outcome, $ids]) {
                foreach ($ids as $id) {
                    $link = isset($links[$id]) ? GuardianLink::query()->find($links[$id]['id']) : null;
                    $link ??= $this->ask($parent, $guardianId, $id, $proposed[$id]['contact_id']);

                    $this->engine->perform($link, $parent, StepId::Confirm, $outcome);
                }
            }
        });
    }

    /** The office acts on one link: suspend, reinstate, recheck or revoke, with a reason. */
    public function office(User $by, string $linkId, string $action, string $reason): GuardianLink
    {
        $link = Ids::isValid($linkId) ? GuardianLink::query()->find($linkId) : null;

        if ($link === null) {
            throw new NotFoundHttpException;
        }

        [$step, $outcome] = self::OFFICE[$action] ?? throw ValidationException::withMessages(['action' => ['Choose what to do with this link.']]);

        $this->engine->perform($link, $by, $step, $outcome, ['reason' => trim($reason)]);

        return $link;
    }

    /**
     * Invites families to the parent portal by text message (D-083). A number
     * already in the portal, invited in the last week, or no longer a contact
     * is passed over. Answers how many were invited.
     *
     * @param  list<string>  $phones  as written; read in the international form
     */
    public function invite(User $by, array $phones): int
    {
        $this->access->check($by, EnrolmentPermission::Families);

        $now = $this->clock->now();
        $wanted = array_values(array_unique(array_filter(array_map(Phone::international(...), $phones))));
        $inPortal = DB::table('users')->whereIn('phone', $wanted ?: ['-'])->whereNotNull('last_signed_in_at')->pluck('phone')->all();
        $recent = DB::table('portal_invitations')->whereIn('phone', $wanted ?: ['-'])->where('invited_at', '>', $now->subDays(self::INVITE_AGAIN_DAYS))->pluck('phone')->all();
        $invited = 0;

        DB::transaction(function () use ($by, $wanted, $inPortal, $recent, $now, &$invited): void {
            foreach ($wanted as $phone) {
                if (in_array($phone, $inPortal, true) || in_array($phone, $recent, true) || $this->families->nameFor($phone) === null) {
                    continue;
                }

                $id = Ids::next();
                DB::table('portal_invitations')->insert(['id' => $id, 'phone' => $phone, 'invited_by' => $by->id, 'invited_at' => $now]);
                $this->notifier->send(new PortalInvitation($id, $phone), $by);
                $invited++;
            }

            if ($invited > 0) {
                $this->audit->record(EnrolmentAudit::FamiliesInvited, actor: $this->audit->actorFor($by), meta: ['families' => $invited]);
            }
        });

        return $invited;
    }

    /** The role record of this parent; made at the first answer. */
    private function guardian(User $parent): string
    {
        $id = DB::table('guardians')->where('user_id', $parent->id)->value('id');

        if (is_string($id)) {
            return $id;
        }

        $id = Ids::next();
        DB::table('guardians')->insert(['id' => $id, 'phone' => $parent->phone, 'name' => $parent->name, 'user_id' => $parent->id, 'created_at' => $this->clock->now()]);

        return $id;
    }

    private function ask(User $parent, string $guardianId, string $learnerId, string $contactId): GuardianLink
    {
        $link = GuardianLink::query()->create([
            'guardian_id' => $guardianId,
            'learner_id' => $learnerId,
            'contact_id' => $contactId,
            'held_by' => $parent->id,
            'state' => State::Requested->value,
            'journey_ref' => (string) $this->blueprints->newest('enrolment.guardian_link'),
        ]);

        $this->audit->record(EnrolmentAudit::ParentLinkAsked, subject: $link, actor: $this->audit->actorFor($parent), aboutPersonId: null, meta: ['learner' => $learnerId]);

        return $link;
    }
}
