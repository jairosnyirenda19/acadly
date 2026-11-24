<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Http;

use Acadly\Core\Enrolment\Contracts\Guardianship;
use Acadly\Kernel\Access\Access;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Assessment\Application\Actions\ObservationActions;
use Acadly\Modules\Assessment\Application\Actions\ReviewActions;
use Acadly\Modules\Assessment\Application\Actions\StepWordActions;
use Acadly\Modules\Assessment\Application\CardDocuments;
use Acadly\Modules\Assessment\Application\Observations;
use Acadly\Modules\Assessment\Application\ProgressReviews;
use Acadly\Modules\Assessment\Application\Reach;
use Acadly\Modules\Assessment\Application\StepWords;
use Acadly\Modules\Assessment\Contracts\AssessmentPermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Observations and progress reviews, for levels that record observations (D-094). */
final class ObservationController
{
    /**
     * The rooms the account sees, in levels that record observations.
     *
     * @response array{rooms: list<array{id: string, name: string, level: string}>}
     */
    public function rooms(Request $request, Observations $observations, Reach $reach): JsonResponse
    {
        return response()->json(['rooms' => array_map(fn (array $r): array => ['id' => $r['id'], 'name' => $r['name'], 'level' => $reach->levelLabel($r['division_id'], $r['level_code'])],
            $observations->rooms(self::user($request)))]);
    }

    /**
     * A room today: learning areas seen this week, children not seen, and the term's reviews.
     *
     * @response array{room: array{id: string, name: string, level: string}, day: string, week_from: string, period: array{id: string, name: string, ends_on: string}|null, areas: list<array{id: string, name: string, short: string, seen: int, observations: int}>, children: list<array{id: string, name: string, seen_this_week: bool, last_seen: string|null}>, reviews: array{children: int, started: int, ready: int, checked: int, shared: int}, can: array{observe: bool, review: bool}}
     */
    public function room(Request $request, string $id, Observations $observations): JsonResponse
    {
        $day = $request->query('day');

        return response()->json($observations->today(self::user($request), $id, is_string($day) && self::isDay($day) ? $day : null));
    }

    /**
     * What a room recorded for one learning area on a day.
     *
     * @response array{room: array{id: string, name: string}, day: string, subject: array{id: string, name: string}, focus: string|null, children: list<array{id: string, name: string, step: string|null, note: string|null}>, areas: list<array{id: string, name: string}>, steps: list<array{key: string, label: string, strokes: int}>, can_observe: bool}
     */
    public function day(Request $request, string $id, Observations $observations): JsonResponse
    {
        $day = $request->query('day');
        $subject = $request->query('subject_id');

        return response()->json($observations->day(self::user($request), $id, is_string($subject) ? $subject : null, is_string($day) && self::isDay($day) ? $day : null));
    }

    /**
     * Records the observations of one learning area on a day.
     *
     * @response array{recorded: int}
     */
    public function record(Request $request, string $id, ObservationActions $actions, Observations $observations, Access $access): JsonResponse
    {
        // Refused before anything about the form is said.
        $who = self::user($request);
        $access->check($who, AssessmentPermission::Observe, $observations->roomScope($observations->room($who, $id)['id']));
        $request->validate([
            'subject_id' => ['required', 'uuid'], 'observed_on' => ['required', 'date_format:Y-m-d'], 'focus' => ['nullable', 'string', 'max:120'],
            'marks' => ['required', 'array', 'max:200'], 'marks.*.learner_id' => ['required', 'uuid'], 'marks.*.step' => ['required', 'string'], 'marks.*.note' => ['nullable', 'string', 'max:255'],
        ]);
        $marks = [];

        foreach ((array) $request->input('marks') as $mark) {
            if (is_array($mark)) {
                $marks[] = ['learner_id' => is_string($mark['learner_id'] ?? null) ? $mark['learner_id'] : '', 'step' => is_string($mark['step'] ?? null) ? $mark['step'] : '',
                    'note' => is_string($mark['note'] ?? null) ? $mark['note'] : null];
            }
        }

        $focus = $request->string('focus')->toString();

        return response()->json(['recorded' => $actions->record($who, $id, $request->string('subject_id')->toString(), $request->string('observed_on')->toString(), $focus !== '' ? $focus : null, $marks)]);
    }

    /**
     * Every child of the rooms the account sees, with this term's review.
     *
     * @response array{children: list<array{learner_id: string, name: string, room_id: string, room: string, review_id: string|null, state: string|null, state_label: string, steps: list<string|null>, by: string|null, updated_at: string|null}>, areas: int, totals: array{children: int, started: int, ready: int, checked: int, shared: int}, can_review: bool}
     */
    public function reviews(Request $request, ProgressReviews $reviews): JsonResponse
    {
        return response()->json($reviews->index(self::user($request)));
    }

    /**
     * A child's progress review this term, beside the term's observations.
     *
     * @response array{learner: array{id: string, name: string, room_id: string, room: string}, period: array{id: string, name: string, ends_on: string}, review: array{id: string|null, state: string, state_label: string, family_note: string, home_note: string, drafted_by: string|null, shared_at: string|null}, areas: list<array{id: string, name: string, step: string|null, trail: list<array{observed_on: string, step: string, focus: string|null, note: string|null}>}>, steps: list<array{key: string, label: string, strokes: int}>, can: array{edit: bool, submit: bool, check: bool, return: bool, share: bool}}
     */
    public function review(Request $request, string $id, ProgressReviews $reviews): JsonResponse
    {
        return response()->json($reviews->one(self::user($request), $id));
    }

    /**
     * Saves the draft of a child's review.
     *
     * @response array{id: string}
     */
    public function save(Request $request, string $id, ReviewActions $actions, ProgressReviews $reviews, Observations $observations, Access $access): JsonResponse
    {
        $who = self::user($request);
        [$room] = $reviews->placeOf($who, $id);
        $access->check($who, AssessmentPermission::Observe, $observations->roomScope($room['id']));
        $request->validate(['steps' => ['present', 'array'], 'steps.*' => ['string'], 'family_note' => ['nullable', 'string', 'max:2000'], 'home_note' => ['nullable', 'string', 'max:2000']]);
        $steps = [];

        foreach ((array) $request->input('steps') as $area => $step) {
            if (is_string($area) && is_string($step)) {
                $steps[$area] = $step;
            }
        }

        $family = $request->string('family_note')->toString();
        $home = $request->string('home_note')->toString();

        return response()->json(['id' => $actions->save($who, $id, $steps, $family !== '' ? $family : null, $home !== '' ? $home : null)->id]);
    }

    /** Sends a review to be checked. */
    public function submit(Request $request, string $id, ReviewActions $actions): JsonResponse
    {
        $actions->submit(self::user($request), $id);

        return response()->json(['ok' => true]);
    }

    /** Marks a review checked. Never one the account sent to be checked. */
    public function check(Request $request, string $id, ReviewActions $actions): JsonResponse
    {
        $actions->check(self::user($request), $id);

        return response()->json(['ok' => true]);
    }

    /** Returns a review to the caregiver with what must change. */
    public function return(Request $request, string $id, ReviewActions $actions): JsonResponse
    {
        // The review is found first; the reason is checked after.
        $actions->return(self::user($request), $id, mb_substr($request->string('reason')->toString(), 0, 2000));

        return response()->json(['ok' => true]);
    }

    /**
     * Shares checked reviews with their families.
     *
     * @response array{shared: int}
     */
    public function share(Request $request, ReviewActions $actions, Access $access): JsonResponse
    {
        $who = self::user($request);

        if (! self::holdsAnywhere($access, $who)) {
            $access->check($who, AssessmentPermission::Review);
        }

        $request->validate(['ids' => ['sometimes', 'array', 'max:500'], 'ids.*' => ['uuid']]);
        $ids = array_values(array_filter((array) $request->input('ids'), 'is_string'));

        return response()->json(['shared' => $actions->share($who, $ids)]);
    }

    /**
     * A child's newest shared progress review, for the parent portal.
     *
     * Only steps and words. `review` is null until a review is shared.
     *
     * @response array{child: array{id: string, first_name: string, name: string, class_id: string|null, class: string}, review: array{period: string, room: string, shared_at: string, areas: list<array{name: string, step: string, label: string, label_ny: string, strokes: int}>, family_note: string, home_note: string|null}|null}
     */
    public function family(Request $request, string $id, Guardianship $guardianship, ProgressReviews $reviews): JsonResponse
    {
        $child = $guardianship->child(self::user($request), $id) ?? throw new NotFoundHttpException;

        return response()->json(['child' => $child, 'review' => $reviews->shared($id)]);
    }

    /**
     * The school's words for the review steps (D-108), and whether the viewer may change them.
     *
     * @response array{en: array{starting: string, growing: string, can_do: string}, ny: array{starting: string, growing: string, can_do: string}, saved_at: string|null, saved_by: string|null, can_change: bool}
     */
    public function stepWords(Request $request, Access $access): JsonResponse
    {
        $who = self::user($request);
        // A whole-school setting, for those who plan; the observation screens carry the words in their own answers.
        $access->check($who, AssessmentPermission::Plan);

        return response()->json([...StepWords::current(), 'can_change' => true]);
    }

    /** @response array{saved: bool} */
    public function saveStepWords(Request $request, StepWordActions $actions, Access $access): JsonResponse
    {
        $access->check(self::user($request), AssessmentPermission::Plan);
        $request->validate(['en' => ['required', 'array'], 'ny' => ['nullable', 'array'], 'en.starting' => ['required', 'string', 'max:24'], 'en.growing' => ['required', 'string', 'max:24'],
            'en.can_do' => ['required', 'string', 'max:24'], 'ny.starting' => ['nullable', 'string', 'max:24'], 'ny.growing' => ['nullable', 'string', 'max:24'], 'ny.can_do' => ['nullable', 'string', 'max:24']]);
        $word = fn (string $key): string => is_string($v = $request->input($key)) ? $v : '';
        $actions->save(self::user($request), ['starting' => $word('en.starting'), 'growing' => $word('en.growing'), 'can_do' => $word('en.can_do')],
            ['starting' => $word('ny.starting'), 'growing' => $word('ny.growing'), 'can_do' => $word('ny.can_do')]);

        return response()->json(['saved' => true]);
    }

    /** The progress review shared with the family, as a PDF with its check code (D-107). */
    public function familyPdf(Request $request, string $id, Guardianship $guardianship, ProgressReviews $reviews, CardDocuments $documents): Response
    {
        $parent = self::user($request);
        $child = $guardianship->child($parent, $id) ?? throw new NotFoundHttpException;
        $review = $reviews->shared($id) ?? throw new NotFoundHttpException;
        $doc = $documents->review($parent, $child['name'], $review['id'], $review);
        $documents->downloaded($parent, CardDocuments::REVIEW, $review['id'], $id);

        return new Response($doc['pdf'], 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $doc['name'], 'progress-review.pdf'), 'Cache-Control' => 'no-store']);
    }

    private static function holdsAnywhere(Access $access, User $who): bool
    {
        foreach ($access->grants($who) as $grant) {
            if ($grant->permission === AssessmentPermission::Review->value) {
                return true;
            }
        }

        return false;
    }

    private static function isDay(string $day): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1;
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
