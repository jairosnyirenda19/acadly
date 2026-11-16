<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Notifications;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\PartyResolver;
use Acadly\Kernel\Notify\Recipient;
use Acadly\Kernel\Notify\Topic;
use Acadly\Modules\Assessment\Journeys\Marks\StepId;
use Illuminate\Support\Facades\DB;

/** The person who last submitted the marks of the assessment. */
final class TheSubmitter implements PartyResolver
{
    public function resolve(Topic $topic): array
    {
        if (! $topic instanceof MarksTopic) {
            return [];
        }

        $id = DB::table('journey_step_completions')->where('subject_type', 'assessment')->where('subject_id', $topic->assessmentId)
            ->where('step', StepId::Submit->value)->orderByDesc('seq')->value('actor_user_id');
        $user = is_string($id) ? User::query()->find($id) : null;

        return $user instanceof User && $user->isActive() ? [Recipient::user($user)] : [];
    }
}
