<?php

declare(strict_types=1);

namespace Tests\Fixtures\Toy;

use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\PartyResolver;
use Acadly\Kernel\Notify\Recipient;
use Acadly\Kernel\Notify\Topic;

/** The person who asked for the errand the notice is about, with the phone number on the errand. */
final class TheRequester implements PartyResolver
{
    public function resolve(Topic $topic): array
    {
        $subject = $topic->subject();
        $errand = $subject->type === 'toy_errand' && $subject->id !== null ? ToyErrand::query()->find($subject->id) : null;
        $user = $errand?->requested_by !== null ? User::query()->find($errand->requested_by) : null;

        if (! $errand instanceof ToyErrand || ! $user instanceof User) {
            return [];
        }

        return [new Recipient($user->name, $user->id, $user->email, $errand->requester_phone)];
    }
}
