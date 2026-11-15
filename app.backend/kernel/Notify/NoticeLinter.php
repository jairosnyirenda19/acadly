<?php

declare(strict_types=1);

namespace Acadly\Kernel\Notify;

use Acadly\Kernel\Access\PermissionCatalogue;

/**
 * Checks what each notice means (04 §9), as part of `acadly:lint`:
 *  - every `:token` in the words is a declared fact or one that is always there;
 *  - `short()` exists when SMS is allowed, and fits one message;
 *  - the default channels are allowed, and an urgent fallback is allowed;
 *  - the notice has an audience, and every party resolver is real;
 *  - a notice the party cannot silence is urgent, with a fallback;
 *  - every acting permission is in the build.
 */
final class NoticeLinter
{
    public function __construct(
        private readonly NoticeCatalogue $catalogue,
        private readonly PermissionCatalogue $permissions,
    ) {}

    /** @return list<string> */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->catalogue->all() as $key => $notice) {
            $problems = [...$problems, ...$this->check($key, $notice)];
        }

        return $problems;
    }

    /** @return list<string> */
    private function check(string $key, Notice $notice): array
    {
        $problems = [];
        $known = [...$notice->factNames(), ...Notice::ALWAYS];

        if (trim($notice->name()) === '' || $notice->categoryOf() === null) {
            $problems[] = "{$key}: the notice needs a name and a category.";
        }

        if (trim($notice->subjectText()) === '' || $notice->lines() === []) {
            $problems[] = "{$key}: the notice needs a subject and at least one line.";
        }

        foreach ($notice->factNames() as $fact) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $fact) !== 1) {
                $problems[] = "{$key}: [{$fact}] is not a valid fact name.";
            }
        }

        foreach ($notice->templates() as $template) {
            foreach (Words::tokensIn($template) as $token) {
                if (! in_array($token, $known, true)) {
                    $problems[] = "{$key}: the words use :{$token}, which the notice does not declare.";
                }
            }
        }

        $allowed = $notice->allowedChannels();

        if ($allowed === []) {
            $problems[] = "{$key}: the notice allows no channel.";
        }

        foreach ([...$notice->defaultChannels(), ...$notice->fallback()] as $channel) {
            if (! in_array($channel, $allowed, true)) {
                $problems[] = "{$key}: the channel [{$channel->value}] is used but not allowed.";
            }
        }

        if (in_array(Channel::Sms, $allowed, true)) {
            $short = $notice->shortText();

            if ($short === null || trim($short) === '') {
                $problems[] = "{$key}: SMS is allowed, so the notice needs short words.";
            } elseif (mb_strlen($short) > Notice::SHORT_BUDGET) {
                $problems[] = "{$key}: the short words are longer than ".Notice::SHORT_BUDGET.' characters.';
            }
        }

        if ($notice->parties() === [] && ! $notice->hasDesk() && ! $notice->allowsPeople()) {
            $problems[] = "{$key}: the notice has no audience.";
        }

        foreach ($notice->parties() as $resolver) {
            if (! is_subclass_of($resolver, PartyResolver::class)) {
                $problems[] = "{$key}: [{$resolver}] is not a party resolver.";
            }
        }

        if ($notice->optOutRule() === OptOut::Never && (! $notice->isUrgent() || $notice->fallback() === [])) {
            $problems[] = "{$key}: a notice that cannot be silenced must be urgent, with a fallback channel.";
        }

        foreach ($notice->actingPermissions() as $permission) {
            if (! $this->permissions->has($permission)) {
                $problems[] = "{$key}: [{$permission}] is not a permission in this build.";
            }
        }

        return $problems;
    }
}
