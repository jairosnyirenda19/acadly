<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Http;

use Acadly\Core\Enrolment\Application\Actions\FamilyActions;
use Acadly\Core\Enrolment\Application\Families;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Core\Enrolment\Contracts\Guardianship;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Messages;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Families (D-082): the parent portal's own reads and answers, and the office's list. */
final class FamiliesController
{
    public function __construct(
        private readonly Families $families,
        private readonly FamilyActions $actions,
    ) {}

    /**
     * The parent's family.
     *
     * The children this parent sees, each with its lines for today, and the
     * children the school's contact list proposes for the parent to confirm.
     *
     * @response array{school: array{name: string, phone: string|null}, parent: array{name: string}, children: list<array{id: string, first_name: string, name: string, class_id: string|null, class: string, state: string, lines: list<array{key: string, word: string, label: string|null, tone: 'done'|'attention'|'wrong'|'plain'}>}>, to_confirm: list<array{id: string, name: string, class: string, relationship: string, contact_id: string}>}
     */
    public function family(Request $request): JsonResponse
    {
        return response()->json($this->families->forParent(self::user($request)));
    }

    /**
     * Confirm the children.
     *
     * `{mine: [learner ids], not_mine: [learner ids]}`. A child left out stays
     * to confirm. The answer is the family as it is now.
     *
     * @response array{school: array{name: string, phone: string|null}, parent: array{name: string}, children: list<array{id: string, first_name: string, name: string, class_id: string|null, class: string, state: string, lines: list<array{key: string, word: string, label: string|null, tone: 'done'|'attention'|'wrong'|'plain'}>}>, to_confirm: list<array{id: string, name: string, class: string, relationship: string, contact_id: string}>}
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'mine' => ['present', 'array', 'max:20'],
            'mine.*' => ['string', 'uuid'],
            'not_mine' => ['present', 'array', 'max:20'],
            'not_mine.*' => ['string', 'uuid'],
        ]);

        $parent = self::user($request);
        $this->actions->confirm($parent, self::ids($request, 'mine'), self::ids($request, 'not_mine'));

        return response()->json(app(Families::class)->forParent($parent));
    }

    /**
     * A child's messages.
     *
     * The text messages the school sent to this phone about the child, newest first.
     *
     * @response array{child: array{id: string, first_name: string, name: string, class_id: string|null, class: string}, messages: list<array{id: string, at: string, notice: string, text: string}>}
     */
    public function messages(Request $request, string $id, Guardianship $guardianship, Messages $messages): JsonResponse
    {
        $parent = self::user($request);
        $child = $guardianship->child($parent, $id) ?? throw new NotFoundHttpException;

        return response()->json(['child' => $child, 'messages' => $parent->phone !== null ? $messages->toPhone($parent->phone, 'learner', $id) : []]);
    }

    /**
     * Every family (the office).
     *
     * Learners grouped by contact phone number, which children each parent
     * sees in the portal, and what needs checking.
     *
     * @response array{families: list<array{phone: string|null, names: list<string>, children: list<array{id: string, name: string, class: string, relationship: string, link: array{id: string, state: string, label: string}|null}>, portal: array{signed_in: bool, last_signed_in_at: string|null, invited_at: string|null, may_invite: bool}, checks: list<array{kind: 'not_their_child'|'names_differ', learner: string|null}>}>, totals: array{families: int, in_portal: int, to_check: int, invitable: int}}
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->families->forOffice(self::user($request)));
    }

    /**
     * Act on a parent link.
     *
     * `{action: suspend|reinstate|recheck|revoke, reason}`. The answer is
     * every family as it is now.
     *
     * @response array{families: list<array{phone: string|null, names: list<string>, children: list<array{id: string, name: string, class: string, relationship: string, link: array{id: string, state: string, label: string}|null}>, portal: array{signed_in: bool, last_signed_in_at: string|null, invited_at: string|null, may_invite: bool}, checks: list<array{kind: 'not_their_child'|'names_differ', learner: string|null}>}>, totals: array{families: int, in_portal: int, to_check: int, invitable: int}}
     */
    public function link(Request $request, string $id, Access $access): JsonResponse
    {
        // Refused before the form is read, so nothing about a link shows to someone who may not keep it.
        $by = self::user($request);
        $access->check($by, EnrolmentPermission::Families);

        $request->validate([
            'action' => ['required', 'string', 'in:suspend,reinstate,recheck,revoke'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $this->actions->office($by, $id, $request->string('action')->toString(), $request->string('reason')->toString());

        return response()->json(app(Families::class)->forOffice($by));
    }

    /**
     * Invite families to the portal.
     *
     * `{phones: [...]}`: one text message to each number not yet in the
     * portal and not invited in the last week. The answer is how many were
     * invited, and every family as it is now.
     *
     * @response array{invited: int, families: array{families: list<array{phone: string|null, names: list<string>, children: list<array{id: string, name: string, class: string, relationship: string, link: array{id: string, state: string, label: string}|null}>, portal: array{signed_in: bool, last_signed_in_at: string|null, invited_at: string|null, may_invite: bool}, checks: list<array{kind: 'not_their_child'|'names_differ', learner: string|null}>}>, totals: array{families: int, in_portal: int, to_check: int, invitable: int}}}
     */
    public function invite(Request $request, Access $access): JsonResponse
    {
        $by = self::user($request);
        $access->check($by, EnrolmentPermission::Families);

        $request->validate([
            'phones' => ['required', 'array', 'min:1', 'max:2000'],
            'phones.*' => ['string', 'max:30'],
        ]);

        $invited = $this->actions->invite($by, self::ids($request, 'phones'));

        return response()->json(['invited' => $invited, 'families' => $this->families->forOffice($by)]);
    }

    /** @return list<string> */
    private static function ids(Request $request, string $key): array
    {
        $value = $request->input($key);

        return is_array($value) ? array_values(array_unique(array_filter($value, 'is_string'))) : [];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
