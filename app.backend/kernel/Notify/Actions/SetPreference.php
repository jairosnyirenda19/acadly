<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify\Actions;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Channel;
use Acadly\Kernel\Notify\NoticeCatalogue;
use Acadly\Kernel\Notify\OptOut;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A person chooses whether a notice that concerns them reaches them on a
 * channel. A notice declared `OptOut::Never` cannot be silenced.
 */
final class SetPreference
{
    public function __construct(
        private readonly NoticeCatalogue $catalogue,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    public function handle(User $who, string $topicKey, Channel $channel, bool $enabled): void
    {
        if (! $this->catalogue->has($topicKey)) {
            throw ValidationException::withMessages(['topic' => ['The notice is not available.']]);
        }

        $notice = $this->catalogue->notice($topicKey);

        if (! in_array($channel, $notice->allowedChannels(), true)) {
            throw ValidationException::withMessages(['channel' => ['The notice cannot be sent on this channel.']]);
        }

        if (! $enabled && $notice->optOutRule() === OptOut::Never) {
            throw ValidationException::withMessages(['channel' => ['This notice cannot be switched off.']]);
        }

        DB::transaction(function () use ($who, $topicKey, $channel, $enabled): void {
            DB::table('notification_preferences')->upsert([[
                'id' => Ids::next(),
                'user_id' => $who->id,
                'topic_key' => $topicKey,
                'channel' => $channel->value,
                'enabled' => $enabled,
                'updated_at' => $this->clock->now(),
            ]], ['user_id', 'topic_key', 'channel'], ['enabled', 'updated_at']);

            $this->audit->record(KernelAudit::PreferenceChanged, actor: $this->audit->actorFor($who),
                meta: ['topic' => $topicKey, 'channel' => $channel->value, 'enabled' => $enabled]);
        });
    }
}
