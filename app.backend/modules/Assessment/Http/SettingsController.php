<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Modules\Assessment\Application\Actions\SavePlan;
use Acadly\Modules\Assessment\Application\Actions\ScaleActions;
use Acadly\Modules\Assessment\Application\Settings;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Assessment settings (the approved Scales and Grading plans screens). */
final class SettingsController
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Every scale with the levels that use it, and every level with its scale.
     *
     * @response array{scales: list<array{id: string, name: string, overall: 'average_percent'|'average_grade'|'best_six', pass_from: string|null, bands: list<array{grade: string, word: string, from: int, to: int}>, track: string, position: int, retired: bool, used_by: list<array{division_id: string, division: string, level_code: string, level: string}>}>, levels: list<array{division_id: string, division: string, level_code: string, level: string, scale_id: string|null, takes_marks: bool}>, can_edit: bool}
     */
    public function scales(Request $request): JsonResponse
    {
        return response()->json($this->settings->scales(self::user($request)));
    }

    /**
     * Adds a scale: `{name, overall, pass_from, track, bands: [{grade, word, from, to}]}`.
     *
     * @response array{scales: list<array{id: string, name: string, overall: 'average_percent'|'average_grade'|'best_six', pass_from: string|null, bands: list<array{grade: string, word: string, from: int, to: int}>, track: string, position: int, retired: bool, used_by: list<array{division_id: string, division: string, level_code: string, level: string}>}>, levels: list<array{division_id: string, division: string, level_code: string, level: string, scale_id: string|null, takes_marks: bool}>, can_edit: bool}
     */
    public function addScale(Request $request, ScaleActions $actions): JsonResponse
    {
        $viewer = self::user($request);
        $actions->add($viewer, $request->all());

        return response()->json($this->settings->scales($viewer));
    }

    /**
     * Changes a scale, or retires it: `{name, overall, pass_from, track, bands, retired}`.
     *
     * @response array{scales: list<array{id: string, name: string, overall: 'average_percent'|'average_grade'|'best_six', pass_from: string|null, bands: list<array{grade: string, word: string, from: int, to: int}>, track: string, position: int, retired: bool, used_by: list<array{division_id: string, division: string, level_code: string, level: string}>}>, levels: list<array{division_id: string, division: string, level_code: string, level: string, scale_id: string|null, takes_marks: bool}>, can_edit: bool}
     */
    public function saveScale(Request $request, string $id, ScaleActions $actions): JsonResponse
    {
        $viewer = self::user($request);
        $actions->save($viewer, $id, $request->all());

        return response()->json($this->settings->scales($viewer));
    }

    /**
     * Chooses the scale of a level: `{division_id, level_code, scale_id}`.
     *
     * @response array{scales: list<array{id: string, name: string, overall: 'average_percent'|'average_grade'|'best_six', pass_from: string|null, bands: list<array{grade: string, word: string, from: int, to: int}>, track: string, position: int, retired: bool, used_by: list<array{division_id: string, division: string, level_code: string, level: string}>}>, levels: list<array{division_id: string, division: string, level_code: string, level: string, scale_id: string|null, takes_marks: bool}>, can_edit: bool}
     */
    public function levelScale(Request $request, ScaleActions $actions): JsonResponse
    {
        $viewer = self::user($request);
        $actions->setLevel($viewer, self::text($request, 'division_id'), self::text($request, 'level_code'), self::text($request, 'scale_id'));

        return response()->json($this->settings->scales($viewer));
    }

    /**
     * The grading plan of a level in a term, or of one of its subjects: `?division_id=&level_code=&period_id=&subject_id=`.
     *
     * @response array{division: array{id: string, name: string}, level: array{code: string, label: string}, period: array{id: string, name: string, division_id: string, year_id: string, starts_on: string, ends_on: string}, begun: bool, subject_id: string|null, plan: array{id: string|null, version: int, mode: 'continuous'|'exam_only', parts: list<array{key: string, label: string, weight: int, kinds: list<string>, rule: 'average'|'best_n'|'weighted_by_max'|'single', best_n: int|null, missing: 'left_out'|'zero'|'ask'}>, reason: string|null, created_by: string|null, created_at: string|null, default: bool, subject_id: string|null, summary: string}, versions: list<array{id: string|null, version: int, mode: 'continuous'|'exam_only', parts: list<array{key: string, label: string, weight: int, kinds: list<string>, rule: 'average'|'best_n'|'weighted_by_max'|'single', best_n: int|null, missing: 'left_out'|'zero'|'ask'}>, reason: string|null, created_by: string|null, created_at: string|null, default: bool, subject_id: string|null, summary: string}>, subjects: list<array{id: string, name: string, short: string, own: array{version: int, summary: string}|null}>, scale: array{id: string, name: string}|null, kinds: list<array{value: string, label: string}>, modes: list<array{value: string, label: string}>, rules: list<array{value: string, label: string}>, missing: list<array{value: string, label: string}>, can_edit: bool}
     */
    public function plans(Request $request): JsonResponse
    {
        $subject = self::text($request, 'subject_id');

        return response()->json($this->settings->plans(self::user($request), self::text($request, 'division_id'), self::text($request, 'level_code'),
            self::text($request, 'period_id'), $subject !== '' ? $subject : null));
    }

    /**
     * Saves a new version of a grading plan: `{division_id, level_code, period_id, subject_id, mode, parts: [{key, label, weight, kinds, rule, best_n, missing}], reason}`.
     *
     * @response array{division: array{id: string, name: string}, level: array{code: string, label: string}, period: array{id: string, name: string, division_id: string, year_id: string, starts_on: string, ends_on: string}, begun: bool, subject_id: string|null, plan: array{id: string|null, version: int, mode: 'continuous'|'exam_only', parts: list<array{key: string, label: string, weight: int, kinds: list<string>, rule: 'average'|'best_n'|'weighted_by_max'|'single', best_n: int|null, missing: 'left_out'|'zero'|'ask'}>, reason: string|null, created_by: string|null, created_at: string|null, default: bool, subject_id: string|null, summary: string}, versions: list<array{id: string|null, version: int, mode: 'continuous'|'exam_only', parts: list<array{key: string, label: string, weight: int, kinds: list<string>, rule: 'average'|'best_n'|'weighted_by_max'|'single', best_n: int|null, missing: 'left_out'|'zero'|'ask'}>, reason: string|null, created_by: string|null, created_at: string|null, default: bool, subject_id: string|null, summary: string}>, subjects: list<array{id: string, name: string, short: string, own: array{version: int, summary: string}|null}>, scale: array{id: string, name: string}|null, kinds: list<array{value: string, label: string}>, modes: list<array{value: string, label: string}>, rules: list<array{value: string, label: string}>, missing: list<array{value: string, label: string}>, can_edit: bool}
     */
    public function savePlan(Request $request, SavePlan $save): JsonResponse
    {
        $viewer = self::user($request);
        $save->handle($viewer, $request->all());
        $subject = self::text($request, 'subject_id');

        return response()->json($this->settings->plans($viewer, self::text($request, 'division_id'), self::text($request, 'level_code'),
            self::text($request, 'period_id'), $subject !== '' ? $subject : null), 201);
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
