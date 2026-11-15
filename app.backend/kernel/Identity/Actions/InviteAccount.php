<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Actions\AssignRole;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Identity\AccountStatus;
use Acadly\Kernel\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Makes an account for a member of staff, with a role at a scope. The
 * account cannot sign in until the person sets a password with the
 * invitation link, which `SendInvitation` sends.
 */
final class InviteAccount
{
    public function __construct(
        private readonly Access $access,
        private readonly AssignRole $assign,
        private readonly Audit $audit,
    ) {}

    public function handle(User $by, string $name, string $email, Role $role, ScopeRef $at): User
    {
        $this->access->check($by, AccessPermission::AssignRoles, $at);

        $data = Validator::make(['name' => trim($name), 'email' => mb_strtolower(trim($email))], [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', 'regex:/@[^@]+\.[^@.]{2,}$/'],
        ], [
            'email.regex' => 'The email address has no domain ending, such as .mw or .com.',
        ])->validate();

        $email = is_string($data['email']) ? $data['email'] : '';
        $name = is_string($data['name']) ? $data['name'] : '';

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => ['An account with this email address already exists.']]);
        }

        return DB::transaction(function () use ($by, $name, $email, $role, $at): User {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Str::random(64),
                'status' => AccountStatus::Invited,
            ]);

            $this->audit->record(KernelAudit::AccountInvited, subject: $user, actor: $this->audit->actorFor($by), at: $at,
                meta: ['role' => $role->key]);

            $this->assign->handle($by, $user, $role, $at);

            return $user;
        });
    }
}
