<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Application\Actions;

use Acadly\Core\Enrolment\Application\Contacts;
use Acadly\Core\Enrolment\Application\Reach;
use Acadly\Core\Enrolment\Contracts\EnrolmentAudit;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Adds a contact, makes one the main contact, or removes one while two others remain. */
final class ContactActions
{
    public function __construct(
        private readonly Reach $reach,
        private readonly Contacts $contacts,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /** @param  array{name: string, relationship: string, phone: string, email: string|null, main: bool}  $contact */
    public function add(User $by, string $learnerId, array $contact): void
    {
        ['learner' => $learner, 'at' => $at] = $this->reach->learner($by, $learnerId, EnrolmentPermission::Manage);
        $checked = $this->contacts->check([$contact], 1);

        DB::transaction(function () use ($by, $learner, $at, $checked, $contact): void {
            // One contact added to those in force: it is the main contact only when asked.
            $this->contacts->add($by, $learner->id, [[...$checked[0], 'main' => $contact['main']]]);
            $this->audit->record(EnrolmentAudit::ContactAdded, subject: $learner, actor: $this->audit->actorFor($by), at: $at,
                personal: ['name' => $checked[0]['name'], 'phone' => $checked[0]['phone']], meta: ['relationship' => $checked[0]['relationship']]);
        });
    }

    public function makeMain(User $by, string $learnerId, string $contactId): void
    {
        ['learner' => $learner, 'at' => $at] = $this->reach->learner($by, $learnerId, EnrolmentPermission::Manage);
        $this->contactInForce($learner->id, $contactId);

        DB::transaction(function () use ($by, $learner, $at, $contactId): void {
            DB::table('learner_contacts')->where('learner_id', $learner->id)->whereNull('removed_at')->update(['is_main' => false]);
            DB::table('learner_contacts')->where('id', $contactId)->update(['is_main' => true]);
            $this->audit->record(EnrolmentAudit::MainContactSet, subject: $learner, actor: $this->audit->actorFor($by), at: $at, meta: ['contact' => $contactId]);
        });
    }

    public function remove(User $by, string $learnerId, string $contactId, string $reason): void
    {
        ['learner' => $learner, 'at' => $at] = $this->reach->learner($by, $learnerId, EnrolmentPermission::Manage);
        $contact = $this->contactInForce($learner->id, $contactId);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Say why the contact is removed.']]);
        }

        DB::transaction(function () use ($by, $learner, $at, $contact, $contactId, $reason): void {
            $left = count(DB::table('learner_contacts')->where('learner_id', $learner->id)->whereNull('removed_at')->lockForUpdate()->pluck('id')->all());

            if ($left - 1 < Contacts::MINIMUM) {
                throw ValidationException::withMessages(['contact' => ['A learner keeps at least two contacts. Add another contact before removing this one.']]);
            }

            DB::table('learner_contacts')->where('id', $contactId)->update(['removed_at' => $this->clock->now(), 'removed_by' => $by->id,
                'removed_reason' => mb_substr(trim($reason), 0, 255), 'is_main' => false]);

            if ((bool) ($contact->is_main ?? false)) {
                $next = DB::table('learner_contacts')->where('learner_id', $learner->id)->whereNull('removed_at')->orderBy('position')->value('id');
                DB::table('learner_contacts')->where('id', $next)->update(['is_main' => true]);
            }

            $this->audit->record(EnrolmentAudit::ContactRemoved, subject: $learner, reason: $reason, actor: $this->audit->actorFor($by), at: $at,
                meta: ['contact' => $contactId, 'relationship' => Row::text($contact, 'relationship')]);
        });
    }

    private function contactInForce(string $learnerId, string $contactId): object
    {
        $contact = DB::table('learner_contacts')->where('learner_id', $learnerId)->where('id', $contactId)->whereNull('removed_at')->first();

        return $contact ?? throw ValidationException::withMessages(['contact' => ['This contact is not recorded for the learner.']]);
    }
}
