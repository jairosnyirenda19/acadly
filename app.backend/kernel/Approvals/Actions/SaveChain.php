<?php

declare(strict_types=1);

namespace Acadly\Kernel\Approvals\Actions;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\AccessPermission;
use Acadly\Kernel\Access\Role;
use Acadly\Kernel\Approvals\Approvals;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Changes who approves a kind of request, for the whole school (Settings ›
 * Approvals). A new row each time: the history of who decided what stays.
 * No steps puts the default back. Requests already waiting keep their chain.
 */
final class SaveChain
{
    public function __construct(
        private readonly Approvals $approvals,
        private readonly Access $access,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @param  array<array-key, mixed>  $steps */
    public function handle(User $by, string $topicKey, array $steps, ?string $fallbackRoleId): void
    {
        $this->access->check($by, AccessPermission::ManageApprovals);
        $topic = $this->approvals->topic($topicKey) ?? throw new NotFoundHttpException;
        $clean = [];
        $errors = [];

        if (count($steps) > 8) {
            $errors['steps'] = ['A chain has up to 8 steps.'];
        }

        foreach (array_values($steps) as $i => $raw) {
            $s = is_array($raw) ? Approvals::spec($raw) : Approvals::anyone();
            $n = $i + 1;

            $ok = match ($s['kind']) {
                'relation' => array_key_exists((string) $s['target'], $topic->relations()),
                'role' => Role::query()->inForce()->whereKey($s['target'])->exists(),
                'person' => in_array($s['target'], array_column($this->approvals->people($topic), 'id'), true),
                default => true,
            };

            if (! $ok) {
                $errors["steps.{$i}"] = ["Step {$n}: choose who decides."];
            }

            if ($s['when'] !== null && ! array_key_exists($s['when'], $topic->facts())) {
                $errors["steps.{$i}.when"] = ["Step {$n}: choose a condition this kind of request has."];
            }

            $clean[] = $s['kind'] === 'anyone' ? [...$s, 'target' => null] : $s;
        }

        if ($fallbackRoleId !== null && ! Role::query()->inForce()->whereKey($fallbackRoleId)->exists()) {
            $errors['fallback_role_id'] = ['Choose a role in use.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($by, $topic, $clean, $fallbackRoleId): void {
            DB::table('approval_chains')->insert([
                'id' => Ids::next(), 'topic_key' => $topic->key(), 'steps' => $clean === [] ? null : json_encode($clean, JSON_THROW_ON_ERROR),
                'fallback_role_id' => $clean === [] ? null : $fallbackRoleId, 'saved_by' => $by->id, 'created_at' => $this->clock->now(),
            ]);
            $this->audit->record(KernelAudit::ChainSaved, actor: $this->audit->actorFor($by),
                meta: ['topic' => $topic->key(), 'steps' => count($clean), 'default' => $clean === []]);
        });
    }
}
