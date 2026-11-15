<?php

declare(strict_types=1);

namespace Acadly\Core\Setup\Application\Actions;

use Acadly\Core\Setup\Application\StarterRoles;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Audit\NotAudited;
use Acadly\Kernel\Identity\Actions\InviteAccount;
use Acadly\Kernel\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a member of staff to the list during setup: an account, invited, with
 * a role where they work. The invitation is sent later, from the list.
 */
#[NotAudited('The kernel actions it calls record the account, the role and the invitation.')]
final class InviteStaff
{
    public function __construct(private readonly InviteAccount $invite) {}

    public function handle(User $by, string $name, string $email, string $roleKey, ScopeRef $at): User
    {
        return DB::transaction(function () use ($by, $name, $email, $roleKey, $at): User {
            $role = StarterRoles::ensure($roleKey) ?? throw ValidationException::withMessages(['role' => ['Choose a role.']]);

            return $this->invite->handle($by, $name, $email, $role, $at);
        });
    }
}
