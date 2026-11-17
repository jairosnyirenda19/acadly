<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Http;

use Acadly\Core\Enrolment\Application\Actions\AdmitLearner;
use Acadly\Core\Enrolment\Application\Actions\ChangeClass;
use Acadly\Core\Enrolment\Application\Actions\ContactActions;
use Acadly\Core\Enrolment\Application\Actions\CorrectLearner;
use Acadly\Core\Enrolment\Application\Actions\ReplacePhoto;
use Acadly\Core\Enrolment\Application\Learners;
use Acadly\Core\Enrolment\Application\Reach;
use Acadly\Core\Enrolment\Contracts\EnrolmentPermission;
use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Access\WorkPlaces;
use Acadly\Kernel\Documents\DocumentReader;
use Acadly\Kernel\Grid\Grids;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Notify\Messages;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The Learners screens (the approved designs): the list, admitting, the record and its changes. */
final class LearnersController
{
    public function __construct(
        private readonly Learners $learners,
        private readonly Reach $reach,
    ) {}

    /**
     * The learners in the classes the viewer may see: `?in=type:id&class_id=&q=`.
     *
     * @response array{year: array{id: string, name: string}|null, word: string, word_plural: string, classes: list<array{id: string, name: string, level: string, campus: string, count: int, can_admit: bool}>, learners: list<array{id: string, acadly_id: string, name: string, admission_number: string|null, class_id: string, class: string, has_photo: bool, main_contact: array{name: string, relationship: string, phone: string}|null, contacts: int, possible_duplicate: bool}>, totals: array{active: int, admitted_this_year: int, classes: int, few_contacts: int, duplicates: int}, can_admit: bool, relationships: list<array{value: string, label: string}>}
     */
    public function index(Request $request, WorkPlaces $places): JsonResponse
    {
        $viewer = self::user($request);
        $request->validate(['in' => ['nullable', 'string', 'max:80'], 'class_id' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:80']]);
        $in = self::workPlace($request, $viewer, $places);
        $class = $request->string('class_id')->toString();

        return response()->json($this->learners->list($viewer, $in, $class !== '' ? $class : null, $request->string('q')->toString()));
    }

    /**
     * The learners as a data grid: `?page=&per_page=&sort=&dir=&q=&filter[class]=a,b&filter[state]=few_contacts&filter[sex]=female&filter[admitted]=from..to`.
     *
     * @response array{rows: list<array{id: string, acadly_id: string, name: string, admission_number: string|null, class_id: string, class: string, campus: string, sex: string, date_of_birth: string, admitted_on: string|null, contacts: int, main_contact: string|null, has_photo: bool, few_contacts: bool, possible_duplicate: bool}>, total: int, page: int, per_page: int, pages: int, sort: string, dir: 'asc'|'desc', q: string, filters: array<string, list<string>>, columns: list<array{key: string, label: string, sortable: bool, shown: bool, always: bool}>, filter_options: list<array{key: string, label: string, kind: 'choice'|'dates', options: list<array{value: string, label: string, count: int}>}>, views: list<array{id: string, name: string, state: array<string, mixed>}>, bulk: list<array{key: string, label: string}>, can_export: bool}
     */
    public function grid(Request $request, Grids $grids): JsonResponse
    {
        $viewer = self::user($request);

        return response()->json($grids->page($viewer, $grids->grid('learners'), $request->query()));
    }

    /**
     * Admits a learner: `{first_name, other_names, surname, sex, date_of_birth, class_id, admitted_on, admission_number, different, contacts: [{name, relationship, phone, email, main}]}`.
     *
     * @response array{id: string}
     */
    public function store(Request $request, AdmitLearner $admit): JsonResponse
    {
        $learner = $admit->handle(self::user($request), [
            'first_name' => self::text($request, 'first_name'),
            'other_names' => self::text($request, 'other_names'),
            'surname' => self::text($request, 'surname'),
            'sex' => self::text($request, 'sex'),
            'date_of_birth' => self::text($request, 'date_of_birth'),
            'class_id' => self::text($request, 'class_id'),
            'admitted_on' => self::text($request, 'admitted_on'),
            'admission_number' => self::text($request, 'admission_number'),
            'different' => $request->boolean('different'),
        ], self::contacts($request->input('contacts')));

        return response()->json(['id' => $learner->id], 201);
    }

    /**
     * One learner's record.
     *
     * @response array{id: string, acadly_id: string, admission_number: string|null, first_name: string, other_names: string|null, surname: string, name: string, sex: string, date_of_birth: string, has_photo: bool, status: string, admitted_on: string|null, word: string, word_plural: string, class: array{id: string, name: string}|null, level: string|null, division: string|null, campus: string|null, class_teacher: string|null, placements: list<array{class: string, campus: string, year: string, starts_on: string, ends_on: string|null, entry_reason: string, reason: string|null, now: bool}>, contacts: list<array{id: string, name: string, relationship: string, relationship_label: string, phone: string, email: string|null, notices_by: string, main: bool, also: list<array{id: string, name: string, class: string}>, in_portal: bool}>, activity: list<array{at: string, what: string, who: string, reason: string|null}>, possible_duplicate: bool, move_to: list<array{id: string, name: string, count: int}>, can_manage: bool, details_request: array{id: string, changes: list<array{field: string, label: string, from: string, to: string}>, reason: string, asked_by: string, asked_by_id: string, waiting_for: list<string>, mine: bool}|null, details_asks: bool, relationships: list<array{value: string, label: string}>, parts: list<array{key: string, title: string, band: list<array{label: string, value: string, note: string, tone: 'plain'|'attention'}>, attention: int}>, told: array{all: int, delivered: int, not_delivered: int}}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $viewer = self::user($request);
        ['learner' => $learner] = $this->reach->learner($viewer, $id, EnrolmentPermission::View);

        return response()->json($this->learners->record($viewer, $learner));
    }

    /**
     * Every notice the school sent about the learner, to whom, and what happened to it.
     *
     * @response array{messages: list<array{id: string, at: string, to: string, address: string|null, notice: string, about: string, channel: string, state: 'delivered'|'sent'|'accepted'|'not_delivered'|'waiting'|'not_reachable'|'over_allowance'|'not_sent', reason: string|null, units: int, again: bool}>}
     */
    public function messages(Request $request, string $id, Messages $messages): JsonResponse
    {
        $this->reach->learner(self::user($request), $id, EnrolmentPermission::View);

        return response()->json(['messages' => $messages->carrying('learner', $id)]);
    }

    /** Corrects the details: `{first_name, other_names, surname, sex, date_of_birth, admission_number, reason}`. */
    public function update(Request $request, string $id, CorrectLearner $correct): JsonResponse
    {
        $correct->handle(self::user($request), $id, [
            'first_name' => self::text($request, 'first_name'),
            'other_names' => self::text($request, 'other_names'),
            'surname' => self::text($request, 'surname'),
            'sex' => self::text($request, 'sex'),
            'date_of_birth' => self::text($request, 'date_of_birth'),
            'admission_number' => self::text($request, 'admission_number'),
        ], self::text($request, 'reason'));

        return $this->show($request, $id);
    }

    /** Moves the learner to another class: `{class_id, from, reason}`. */
    public function move(Request $request, string $id, ChangeClass $change): JsonResponse
    {
        $change->handle(self::user($request), $id, self::text($request, 'class_id'), self::text($request, 'from'), self::text($request, 'reason'));

        return $this->show($request, $id);
    }

    /** Adds a contact: `{name, relationship, phone, email, main}`. */
    public function addContact(Request $request, string $id, ContactActions $contacts): JsonResponse
    {
        $contacts->add(self::user($request), $id, ['name' => self::text($request, 'name'), 'relationship' => self::text($request, 'relationship'),
            'phone' => self::text($request, 'phone'), 'email' => self::text($request, 'email'), 'notices_by' => self::text($request, 'notices_by'), 'main' => $request->boolean('main')]);

        return $this->show($request, $id);
    }

    /** Changes how a contact receives notices: `{notices_by: sms|email}`. */
    public function contactNotices(Request $request, string $id, string $contact, ContactActions $contacts): JsonResponse
    {
        $contacts->noticesBy(self::user($request), $id, $contact, self::text($request, 'notices_by'));

        return $this->show($request, $id);
    }

    /** Makes a contact the main contact. */
    public function mainContact(Request $request, string $id, string $contact, ContactActions $contacts): JsonResponse
    {
        $contacts->makeMain(self::user($request), $id, $contact);

        return $this->show($request, $id);
    }

    /** Removes a contact while two others remain: `{reason}`. */
    public function removeContact(Request $request, string $id, string $contact, ContactActions $contacts): JsonResponse
    {
        $contacts->remove(self::user($request), $id, $contact, self::text($request, 'reason'));

        return $this->show($request, $id);
    }

    /** Sets the photo: a JPEG or PNG of at most 2 MB, as `file`. */
    public function photo(Request $request, string $id, ReplacePhoto $replace): JsonResponse
    {
        // Refused before anything about the file is said.
        $this->reach->learner(self::user($request), $id, EnrolmentPermission::Manage);
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => ['Choose a photo: JPEG or PNG.']]);
        }

        $replace->handle(self::user($request), $id, $file);

        return $this->show($request, $id);
    }

    /** The current photo, to those who may see the learner. Opening it is recorded. */
    public function openPhoto(Request $request, string $id, DocumentReader $reader): StreamedResponse
    {
        $viewer = self::user($request);
        ['learner' => $learner] = $this->reach->learner($viewer, $id, EnrolmentPermission::View);
        $document = $reader->find($viewer, $learner->photo_document_id ?? throw new NotFoundHttpException);
        $opened = $reader->open($viewer, $document);

        return new StreamedResponse(function () use ($opened): void {
            foreach ($opened['bytes'] as $piece) {
                echo $piece;
            }
        }, 200, [
            'Content-Type' => $opened['file']->mime,
            'Content-Length' => (string) $opened['file']->size,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The place the viewer works in (`?in=type:id`), only a selector, refused unless the viewer works there. */
    private static function workPlace(Request $request, User $viewer, WorkPlaces $places): ?ScopeRef
    {
        $in = $request->string('in')->toString();

        if ($in === '') {
            return null;
        }

        [$type, $id] = array_pad(explode(':', $in, 2), 2, '');
        $kind = ScopeType::tryFrom($type);

        try {
            $place = $kind !== null ? ScopeRef::of($kind, $id !== '' ? $id : null) : null;
        } catch (InvalidArgumentException) {
            $place = null;
        }

        if ($place === null || ! $places->allows($viewer, $place)) {
            throw new NotFoundHttpException;
        }

        return $place;
    }

    /** @return list<array{name: string, relationship: string, phone: string, email: string|null, notices_by?: string, main: bool}> */
    private static function contacts(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > 20) {
            return [];
        }

        $out = [];

        foreach ($value as $c) {
            $c = is_array($c) ? $c : [];
            $out[] = [
                'name' => is_string($c['name'] ?? null) ? $c['name'] : '',
                'relationship' => is_string($c['relationship'] ?? null) ? $c['relationship'] : '',
                'phone' => is_string($c['phone'] ?? null) ? $c['phone'] : '',
                'email' => is_string($c['email'] ?? null) ? $c['email'] : null,
                'notices_by' => is_string($c['notices_by'] ?? null) ? $c['notices_by'] : 'sms',
                'main' => ($c['main'] ?? false) === true,
            ];
        }

        return $out;
    }

    private static function text(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_string($value) ? $value : '';
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
