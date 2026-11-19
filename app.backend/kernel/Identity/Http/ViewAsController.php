<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Http;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\Actions\ViewAsActions;
use Acadly\Kernel\Identity\People;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Identity\ViewAs;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** View as (the approved design; development only). Answers 404 wherever it is not available. */
final class ViewAsController
{
    /** A search box, never the whole staff list. */
    private const int LIMIT = 12;

    public function __construct(
        private readonly ViewAs $viewAs,
        private readonly ViewAsActions $actions,
        private readonly Access $access,
        private readonly People $people,
        private readonly MeController $me,
    ) {}

    /**
     * Who an administrator may view as: `?search=` by name or email. Never an administrator, never yourself.
     *
     * @response array{people: list<array{id: string, name: string, email: string, roles: list<array{role: string, where: string}>}>, total: int}
     */
    public function candidates(Request $request): JsonResponse
    {
        $admin = self::user($request);

        if (! $this->viewAs->available()) {
            throw new NotFoundHttpException;
        }

        $this->access->check($admin, AccessPermission::AssignRoles, ScopeRef::institution());

        $search = is_string($s = $request->query('search')) ? trim($s) : '';
        $administrators = $this->access->holderIds(AccessPermission::AssignRoles, ScopeRef::institution());
        $query = User::query()
            ->whereKeyNot($admin->id)
            ->whereNotIn('id', $administrators)
            ->where('status', AccountStatus::Active->value)
            ->when($search !== '', fn (Builder $q): Builder => $q->where(fn (Builder $m): Builder => $m
                ->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('email', 'ilike', '%'.addcslashes($search, '%_\\').'%')))
            ->orderBy('name');
        $total = (clone $query)->count();

        // Only people the People list shows in the place worked in (D-099).
        $people = $query->limit(self::LIMIT)->get()->filter(fn (User $u): bool => $this->people->sees($admin, $u))->map(fn (User $u): array => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'roles' => array_map(fn (array $r): array => ['role' => $r['role'], 'where' => $r['where']], $this->people->rolesNow($u)),
        ])->values()->all();

        return response()->json(['people' => $people, 'total' => $total]);
    }

    /**
     * Steps into another account. The response is the same as GET /me, for that account.
     *
     * @response array{user: array{id: string, name: string, email: string}, grants: list<array{permission: string, scope_type: 'institution'|'division'|'campus'|'class', scope_id: string|null}>, view_as: array{available: bool, by: array{id: string, name: string}|null, ends_at: string|null}}
     */
    public function start(Request $request, string $id): JsonResponse
    {
        $this->actions->start($request, self::user($request), $id);

        return ($this->me)($request);
    }

    /**
     * Steps back into your own account. The response is the same as GET /me.
     *
     * @response array{user: array{id: string, name: string, email: string}, grants: list<array{permission: string, scope_type: 'institution'|'division'|'campus'|'class', scope_id: string|null}>, view_as: array{available: bool, by: array{id: string, name: string}|null, ends_at: string|null}}
     */
    public function leave(Request $request): JsonResponse
    {
        if (! $this->viewAs->available() && $this->viewAs->by($request) === null) {
            throw new NotFoundHttpException;
        }

        $this->actions->end($request);

        return ($this->me)($request);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
