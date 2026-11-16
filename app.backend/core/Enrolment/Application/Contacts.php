<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application;

use Acadly\Core\Enrolment\Contracts\Relationship;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Phone;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who the school contacts about a learner (the approved design): at least
 * two people, each with a relationship and a phone, one of them the main
 * contact. A contact removed is closed, not deleted.
 */
final class Contacts
{
    public const int MINIMUM = 2;

    /** How a contact may choose to receive notices. */
    public const array CHANNELS = ['sms', 'email'];

    public function __construct(private readonly Clock $clock) {}

    /**
     * @param  list<array{name: string, relationship: string, phone: string, email: string|null, notices_by?: string, main: bool}>  $contacts
     * @return list<array{name: string, relationship: string, phone: string, email: string|null, notices_by: string, main: bool}>
     */
    public function check(array $contacts, int $minimum = self::MINIMUM): array
    {
        $errors = [];
        $out = [];

        foreach ($contacts as $i => $c) {
            $name = trim((string) preg_replace('/\s+/u', ' ', $c['name']));
            $phone = trim($c['phone']);
            $email = trim((string) $c['email']);

            if ($name === '' || mb_strlen($name) > 120) {
                $errors["contacts.{$i}.name"] = ['Enter the full name.'];
            }

            if (Relationship::tryFrom($c['relationship']) === null) {
                $errors["contacts.{$i}.relationship"] = ['Choose a relationship.'];
            }

            if (preg_match('/^\+?[0-9 ()\-]{7,30}$/', $phone) !== 1 || Phone::international($phone) === null) {
                $errors["contacts.{$i}.phone"] = ['Enter the phone number as 0991 234 567, or with the country code, such as +265 991 234 567.'];
            }

            $by = mb_strtolower(trim($c['notices_by'] ?? 'sms'));
            $by = $by === '' ? 'sms' : $by;

            if (! in_array($by, self::CHANNELS, true)) {
                $errors["contacts.{$i}.notices_by"] = ['Choose SMS or email.'];
            } elseif ($by === 'email' && $email === '') {
                $errors["contacts.{$i}.notices_by"] = ['Add an email address to send notices by email.'];
            }

            if ($email !== '' && (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 190)) {
                $errors["contacts.{$i}.email"] = ['Enter a valid email address, or leave it empty.'];
            }

            $out[] = ['name' => $name, 'relationship' => $c['relationship'], 'phone' => $phone, 'email' => $email !== '' ? mb_strtolower($email) : null, 'notices_by' => $by, 'main' => $c['main']];
        }

        if (count($contacts) < $minimum) {
            $errors['contacts'] = ['Record at least two contacts, so the school can reach someone when the first does not answer.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $main = array_search(true, array_column($out, 'main'), true);

        foreach ($out as $i => $c) {
            $out[$i]['main'] = $main === false ? $i === 0 : $i === $main;
        }

        return $out;
    }

    /** @param  list<array{name: string, relationship: string, phone: string, email: string|null, notices_by: string, main: bool}>  $contacts */
    public function add(User $by, string $learnerId, array $contacts): void
    {
        $now = $this->clock->now();
        $max = DB::table('learner_contacts')->where('learner_id', $learnerId)->max('position');
        $position = is_numeric($max) ? (int) $max : 0;

        if (array_filter($contacts, fn (array $c): bool => $c['main']) !== []) {
            DB::table('learner_contacts')->where('learner_id', $learnerId)->whereNull('removed_at')->update(['is_main' => false]);
        }

        foreach ($contacts as $c) {
            DB::table('learner_contacts')->insert(['id' => Ids::next(), 'learner_id' => $learnerId, 'name' => $c['name'], 'relationship' => $c['relationship'],
                'phone' => $c['phone'], 'email' => $c['email'], 'notices_by' => $c['notices_by'], 'is_main' => $c['main'], 'position' => ++$position, 'added_by' => $by->id, 'added_at' => $now]);
        }
    }

    /** @return list<array{id: string, name: string, relationship: string, relationship_label: string, phone: string, email: string|null, notices_by: string, main: bool}> */
    public function of(string $learnerId): array
    {
        return array_values(DB::table('learner_contacts')->where('learner_id', $learnerId)->whereNull('removed_at')
            ->orderByDesc('is_main')->orderBy('position')->get()
            ->map(fn (object $c): array => [
                'id' => Row::text($c, 'id'), 'name' => Row::text($c, 'name'), 'relationship' => Row::text($c, 'relationship'),
                'relationship_label' => Relationship::tryFrom(Row::text($c, 'relationship'))?->label() ?? '',
                'phone' => Row::text($c, 'phone'), 'email' => Row::maybeText($c, 'email'), 'notices_by' => Row::text($c, 'notices_by'), 'main' => (bool) ($c->is_main ?? false),
            ])->all());
    }
}
