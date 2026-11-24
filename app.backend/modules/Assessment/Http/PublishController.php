<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Modules\Assessment\Application\Actions\PublishResults;
use Acadly\Modules\Assessment\Application\Actions\ReportCardActions;
use Acadly\Modules\Assessment\Application\CardDocuments;
use Acadly\Modules\Assessment\Application\Publishing;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

/** Publishing results and report cards (the approved designs, D-080). */
final class PublishController
{
    public function __construct(
        private readonly Publishing $publishing,
        private readonly PublishResults $publish,
        private readonly ReportCardActions $actions,
    ) {}

    /**
     * The classes the viewer may publish, each with its term, how ready it is and its latest version.
     *
     * @response array{classes: list<array{class_id: string, class: string, level: string, campus: string, period: array{id: string, name: string}, learners: int, subjects: int, subjects_ready: int, comments: int, ready: bool, missing: list<string>, state: 'not_ready'|'ready'|'published'|'changed'|'waiting', waiting: array{id: string, asked_by: string, asked_by_id: string, asked_at: string, step: string, waiting_for: list<string>, changed: bool}|null, latest: array{id: string, version: int, published_at: string}|null}>, settings: array{show_position: bool, head_comment: bool}, asks: bool}
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->publishing->screen(self::user($request)));
    }

    /**
     * Publishes the classes chosen: `{class_ids: [...]}`. Returns the screen as it is now.
     *
     * @response array{published: list<array{class_id: string, publication_id: string|null, version: int|null, asked: bool}>, classes: list<array{class_id: string, class: string, level: string, campus: string, period: array{id: string, name: string}, learners: int, subjects: int, subjects_ready: int, comments: int, ready: bool, missing: list<string>, state: 'not_ready'|'ready'|'published'|'changed'|'waiting', waiting: array{id: string, asked_by: string, asked_by_id: string, asked_at: string, step: string, waiting_for: list<string>, changed: bool}|null, latest: array{id: string, version: int, published_at: string}|null}>, settings: array{show_position: bool, head_comment: bool}, asks: bool}
     */
    public function publish(Request $request): JsonResponse
    {
        $user = self::user($request);
        $ids = $request->input('class_ids');
        $published = $this->publish->handle($user, is_array($ids) ? array_values(array_filter($ids, 'is_string')) : []);

        return response()->json(['published' => $published, ...$this->publishing->screen($user)]);
    }

    /**
     * What report cards show: `{show_position: bool, head_comment: bool}`.
     *
     * @response array{classes: list<array{class_id: string, class: string, level: string, campus: string, period: array{id: string, name: string}, learners: int, subjects: int, subjects_ready: int, comments: int, ready: bool, missing: list<string>, state: 'not_ready'|'ready'|'published'|'changed'|'waiting', waiting: array{id: string, asked_by: string, asked_by_id: string, asked_at: string, step: string, waiting_for: list<string>, changed: bool}|null, latest: array{id: string, version: int, published_at: string}|null}>, settings: array{show_position: bool, head_comment: bool}, asks: bool}
     */
    public function settings(Request $request): JsonResponse
    {
        $user = self::user($request);
        $this->actions->settings($user, $request->boolean('show_position'), $request->boolean('head_comment'));

        return response()->json($this->publishing->screen($user));
    }

    /**
     * The report comments of a class this term: `?class_id=`.
     *
     * @response array{class: array{id: string, name: string}, period: array{id: string, name: string}, can_class: bool, can_head: bool, head_comment: bool, max: int, learners: list<array{id: string, name: string, total: array{total: float, out_of: int, complete: bool}, position: array{position: int, of: int, shared: bool}|null, grades: list<array{subject: string, short: string, grade: string|null, ready: bool}>, lines: list<array{label: string, value: string}>, comments: array{class_teacher: array{text: string, by: string, at: string}|null, head: array{text: string, by: string, at: string}|null}}>, written: int}
     */
    public function comments(Request $request): JsonResponse
    {
        return response()->json($this->publishing->commentsScreen(self::user($request), self::text($request, 'class_id')));
    }

    /**
     * Writes a learner's comment: `{class_id, learner_id, author: 'class_teacher'|'head', text}`. An empty text takes it away.
     *
     * @response array{class: array{id: string, name: string}, period: array{id: string, name: string}, can_class: bool, can_head: bool, head_comment: bool, max: int, learners: list<array{id: string, name: string, total: array{total: float, out_of: int, complete: bool}, position: array{position: int, of: int, shared: bool}|null, grades: list<array{subject: string, short: string, grade: string|null, ready: bool}>, lines: list<array{label: string, value: string}>, comments: array{class_teacher: array{text: string, by: string, at: string}|null, head: array{text: string, by: string, at: string}|null}}>, written: int}
     */
    public function comment(Request $request): JsonResponse
    {
        $user = self::user($request);
        $class = self::text($request, 'class_id');
        $this->actions->comment($user, $class, self::text($request, 'learner_id'), self::text($request, 'author'), self::text($request, 'text'));

        return response()->json($this->publishing->commentsScreen($user, $class));
    }

    /**
     * The report cards of a published version, or one learner's: `?learner_id=`.
     *
     * @response array{school: array{name: string, has_logo: bool}, publication: array{id: string, class_id: string, period_id: string, version: int, published_at: string}, cards: list<array{reference: string, learner: array{id: string, name: string, admission_number: string|null}, class: array{id: string, name: string}, period: array{id: string, name: string, starts_on: string, ends_on: string}, scale: array{name: string, overall: string, pass_from: string|null}|null, subjects: list<array{subject_id: string, subject: string, short: string, parts: list<array{label: string, weight: int, score: float|null, points: float|null}>, percent: float|null, grade: string|null, word: string|null, passes: bool|null, ready: bool}>, summary: array{kind: 'average_percent'|'average_grade'|'best_six', value: float|null, of: int}, total: array{total: float, out_of: int, complete: bool}, position: array{position: int, of: int, shared: bool}|null, comments: array{class_teacher: array{text: string, by: string, at: string}|null, head: array{text: string, by: string, at: string}|null}, lines: list<array{label: string, value: string}>, next_term: string|null}>}
     */
    public function cards(Request $request, string $id): JsonResponse
    {
        $learner = self::text($request, 'learner_id');

        return response()->json($this->publishing->cards(self::user($request), $id, $learner !== '' ? $learner : null));
    }

    /** The published report cards as one PDF, the class or one learner (`?learner_id=`), with check codes (D-107). */
    public function pdf(Request $request, string $id, CardDocuments $documents): Response
    {
        $who = self::user($request);
        $learner = self::text($request, 'learner_id');
        $data = $this->publishing->cards($who, $id, $learner !== '' ? $learner : null);
        $pub = is_array($data['publication'] ?? null) ? $data['publication'] : [];
        $cards = array_values(array_filter(is_array($data['cards'] ?? null) ? $data['cards'] : [], 'is_array'));
        $first = $cards[0] ?? [];
        $class = is_array($first['class'] ?? null) && is_string($first['class']['name'] ?? null) ? $first['class']['name'] : '';
        $period = is_array($first['period'] ?? null) && is_string($first['period']['name'] ?? null) ? $first['period']['name'] : '';
        $version = is_int($pub['version'] ?? null) ? $pub['version'] : 1;
        $one = $learner !== '' && count($cards) === 1;
        $name = $one && is_array($first['learner'] ?? null) && is_string($first['learner']['name'] ?? null)
            ? 'Report card '.$first['learner']['name'].' '.(is_string($first['reference'] ?? null) ? $first['reference'] : '')
            : 'Report cards '.$class.' '.$period.' version '.$version;
        $doc = $documents->cards($who, $cards, is_string($pub['published_at'] ?? null) ? $pub['published_at'] : 'now', $version, $name);
        $documents->downloaded($who, CardDocuments::CARD, $one && is_string($first['reference'] ?? null) ? $first['reference'] : $id, $one && is_array($first['learner'] ?? null) && is_string($first['learner']['id'] ?? null) ? $first['learner']['id'] : null, count($cards));

        return new Response($doc['pdf'], 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $doc['name'], 'report-cards.pdf'), 'Cache-Control' => 'no-store']);
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
