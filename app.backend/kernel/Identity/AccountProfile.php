<?php

declare(strict_types=1);

namespace Acadly\Kernel\Identity;

use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Access\RoleAssignment;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\NoticeCatalogue;
use Acadly\Kernel\Notify\OptOut;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * Your account (the approved design, D-089), as in eOffice: who you are and
 * your roles, how the school reaches you, how you sign in, and the messages
 * sent to you in the last 90 days.
 */
final class AccountProfile
{
    public const int KEEP_DAYS = 90;

    public function __construct(
        private readonly Access $access,
        private readonly NoticeCatalogue $catalogue,
        private readonly Clock $clock,
    ) {}

    /** @return array<string, mixed> */
    public function show(User $who): array
    {
        $now = $this->clock->now();
        $roles = RoleAssignment::query()->with('role')->where('user_id', $who->id)
            ->where('starts_at', '<=', $now)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))->orderBy('starts_at')->get();
        $prefs = [];

        foreach (DB::table('notification_preferences')->where('user_id', $who->id)->get() as $p) {
            $prefs[Row::text($p, 'topic_key').'|'.Row::text($p, 'channel')] = (bool) $p->enabled;
        }

        $notices = [];

        // The notices that go to people of the school, not to families.
        foreach ($this->catalogue->all() as $key => $notice) {
            if (! $notice->allowsPeople() && ! $notice->hasDesk()) {
                continue;
            }

            $allowed = array_map(fn (Channel $c): string => $c->value, $notice->allowedChannels());
            $defaults = array_map(fn (Channel $c): string => $c->value, $notice->defaultChannels());
            $notices[] = [
                'key' => $key,
                'name' => $notice->name(),
                'category' => $notice->categoryOf()?->label(),
                'locked' => $notice->optOutRule() === OptOut::Never,
                'channels' => array_map(fn (string $c): array => ['channel' => $c, 'on' => $prefs[$key.'|'.$c] ?? in_array($c, $defaults, true)], $allowed),
            ];
        }

        $messages = DB::table('notification_deliveries')->where('recipient_user_id', $who->id)->where('queued_at', '>=', $now->subDays(self::KEEP_DAYS))
            ->orderByDesc('queued_at')->limit(20)->get(['id', 'channel', 'subject', 'short', 'status', 'queued_at']);

        return [
            'user' => ['name' => $who->name, 'email' => $who->email, 'phone' => $who->phone],
            'roles' => array_values($roles->map(fn (RoleAssignment $a): array => ['role' => $a->role->name, 'where' => $this->access->label($a->scope())])->all()),
            'notices' => $notices,
            'security' => [
                'password_changed_at' => $who->password_changed_at?->toIso8601String(),
                'two_step' => $who->two_step_on_at !== null,
                'two_step_since' => $who->two_step_on_at?->toIso8601String(),
                'recovery_left' => $who->two_step_on_at !== null ? DB::table('two_step_recovery_codes')->where('user_id', $who->id)->whereNull('used_at')->whereNull('retired_at')->count() : 0,
            ],
            'messages' => array_values(array_map(fn (object $m): array => [
                'id' => Row::text($m, 'id'), 'channel' => Row::text($m, 'channel'),
                'text' => Row::maybeText($m, 'short') ?? Row::text($m, 'subject'), 'status' => Row::text($m, 'status'), 'at' => Row::text($m, 'queued_at'),
            ], $messages->all())),
        ];
    }
}
