<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Institution\Contracts\SchoolDirectory;
use Acadly\Kernel\Console\SyncStep;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Illuminate\Support\Facades\DB;

/**
 * The scales Acadly starts from (11 §9), seeded once by `acadly:sync`, and
 * the scale each level starts with. The bands are starting values, to be
 * checked against the latest examination-board circular; a school corrects
 * them on the Scales screen, and the sync never changes them again.
 *
 * @phpstan-type Seed array{key: string, name: string, track: 'malawi'|'british', overall: 'average_percent'|'average_grade'|'best_six', pass_from: string, bands: list<array{grade: string, word: string, from: int, to: int}>}
 */
final class ScaleSeeds implements SyncStep
{
    /** @var list<Seed> */
    public const array SCALES = [
        ['key' => 'primary_1_4', 'name' => 'Primary 1 to 4', 'track' => 'malawi', 'overall' => 'average_grade', 'pass_from' => '2', 'bands' => [
            ['grade' => '4', 'word' => 'Excellent', 'from' => 80, 'to' => 100],
            ['grade' => '3', 'word' => 'Good', 'from' => 60, 'to' => 79],
            ['grade' => '2', 'word' => 'Average', 'from' => 40, 'to' => 59],
            ['grade' => '1', 'word' => 'Below average', 'from' => 0, 'to' => 39],
        ]],
        ['key' => 'pslce', 'name' => 'PSLCE grades', 'track' => 'malawi', 'overall' => 'average_percent', 'pass_from' => 'D', 'bands' => [
            ['grade' => 'A', 'word' => 'Distinction', 'from' => 75, 'to' => 100],
            ['grade' => 'B', 'word' => 'Strong credit', 'from' => 60, 'to' => 74],
            ['grade' => 'C', 'word' => 'Credit', 'from' => 50, 'to' => 59],
            ['grade' => 'D', 'word' => 'Pass', 'from' => 40, 'to' => 49],
            ['grade' => 'F', 'word' => 'Fail', 'from' => 0, 'to' => 39],
        ]],
        ['key' => 'percent_letters', 'name' => 'Percent and letters', 'track' => 'malawi', 'overall' => 'average_percent', 'pass_from' => 'D', 'bands' => [
            ['grade' => 'A', 'word' => 'Excellent', 'from' => 80, 'to' => 100],
            ['grade' => 'B', 'word' => 'Very good', 'from' => 70, 'to' => 79],
            ['grade' => 'C', 'word' => 'Good', 'from' => 60, 'to' => 69],
            ['grade' => 'D', 'word' => 'Pass', 'from' => 50, 'to' => 59],
            ['grade' => 'F', 'word' => 'Fail', 'from' => 0, 'to' => 49],
        ]],
        ['key' => 'jce', 'name' => 'JCE grades', 'track' => 'malawi', 'overall' => 'average_percent', 'pass_from' => 'D', 'bands' => [
            ['grade' => 'A', 'word' => 'Distinction', 'from' => 75, 'to' => 100],
            ['grade' => 'B', 'word' => 'Strong credit', 'from' => 65, 'to' => 74],
            ['grade' => 'C', 'word' => 'Credit', 'from' => 55, 'to' => 64],
            ['grade' => 'D', 'word' => 'Pass', 'from' => 40, 'to' => 54],
            ['grade' => 'F', 'word' => 'Fail', 'from' => 0, 'to' => 39],
        ]],
        ['key' => 'msce', 'name' => 'MSCE points', 'track' => 'malawi', 'overall' => 'best_six', 'pass_from' => '8', 'bands' => [
            ['grade' => '1', 'word' => 'Distinction', 'from' => 75, 'to' => 100],
            ['grade' => '2', 'word' => 'Distinction', 'from' => 70, 'to' => 74],
            ['grade' => '3', 'word' => 'Strong credit', 'from' => 65, 'to' => 69],
            ['grade' => '4', 'word' => 'Credit', 'from' => 60, 'to' => 64],
            ['grade' => '5', 'word' => 'Credit', 'from' => 55, 'to' => 59],
            ['grade' => '6', 'word' => 'Weak credit', 'from' => 50, 'to' => 54],
            ['grade' => '7', 'word' => 'Pass', 'from' => 45, 'to' => 49],
            ['grade' => '8', 'word' => 'Weak pass', 'from' => 40, 'to' => 44],
            ['grade' => '9', 'word' => 'Fail', 'from' => 0, 'to' => 39],
        ]],
        ['key' => 'british_primary', 'name' => 'British primary', 'track' => 'british', 'overall' => 'average_percent', 'pass_from' => 'Expected', 'bands' => [
            ['grade' => 'Greater depth', 'word' => 'Greater depth', 'from' => 85, 'to' => 100],
            ['grade' => 'Expected', 'word' => 'Expected', 'from' => 60, 'to' => 84],
            ['grade' => 'Working towards', 'word' => 'Working towards', 'from' => 0, 'to' => 59],
        ]],
        ['key' => 'gcse', 'name' => 'GCSE 9 to 1', 'track' => 'british', 'overall' => 'average_percent', 'pass_from' => '4', 'bands' => [
            ['grade' => '9', 'word' => '', 'from' => 90, 'to' => 100],
            ['grade' => '8', 'word' => '', 'from' => 80, 'to' => 89],
            ['grade' => '7', 'word' => '', 'from' => 70, 'to' => 79],
            ['grade' => '6', 'word' => '', 'from' => 60, 'to' => 69],
            ['grade' => '5', 'word' => 'Strong pass', 'from' => 50, 'to' => 59],
            ['grade' => '4', 'word' => 'Standard pass', 'from' => 40, 'to' => 49],
            ['grade' => '3', 'word' => '', 'from' => 30, 'to' => 39],
            ['grade' => '2', 'word' => '', 'from' => 20, 'to' => 29],
            ['grade' => '1', 'word' => '', 'from' => 10, 'to' => 19],
            ['grade' => 'U', 'word' => 'Ungraded', 'from' => 0, 'to' => 9],
        ]],
        ['key' => 'a_level', 'name' => 'A level', 'track' => 'british', 'overall' => 'average_percent', 'pass_from' => 'E', 'bands' => [
            ['grade' => 'A*', 'word' => '', 'from' => 90, 'to' => 100],
            ['grade' => 'A', 'word' => '', 'from' => 80, 'to' => 89],
            ['grade' => 'B', 'word' => '', 'from' => 70, 'to' => 79],
            ['grade' => 'C', 'word' => '', 'from' => 60, 'to' => 69],
            ['grade' => 'D', 'word' => '', 'from' => 50, 'to' => 59],
            ['grade' => 'E', 'word' => '', 'from' => 40, 'to' => 49],
            ['grade' => 'U', 'word' => 'Ungraded', 'from' => 0, 'to' => 39],
        ]],
    ];

    /** The scale each level starts with, by level code. Any other level starts with the first scale. */
    public const array LEVELS = [
        'STD1' => 'primary_1_4', 'STD2' => 'primary_1_4', 'STD3' => 'primary_1_4', 'STD4' => 'primary_1_4',
        'STD5' => 'primary_1_4', 'STD6' => 'primary_1_4', 'STD7' => 'primary_1_4', 'STD8' => 'pslce',
        'F1' => 'jce', 'F2' => 'jce', 'F3' => 'msce', 'F4' => 'msce',
    ];

    public const string FIRST = 'percent_letters';

    public function __construct(
        private readonly SchoolDirectory $school,
        private readonly Clock $clock,
    ) {}

    public function sync(): string
    {
        $now = $this->clock->now();
        $added = 0;

        foreach (self::SCALES as $position => $seed) {
            $added += DB::table('assessment_scales')->insertOrIgnore([
                'id' => Ids::next(), 'seed_key' => $seed['key'], 'name' => $seed['name'], 'overall' => $seed['overall'],
                'pass_from' => $seed['pass_from'], 'bands' => json_encode($seed['bands'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'track' => $seed['track'], 'position' => $position + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $ids = DB::table('assessment_scales')->whereNotNull('seed_key')->pluck('id', 'seed_key')->all();
        $levels = 0;

        foreach ($this->school->divisionViews() as $division) {
            foreach ($division->profile->levels as $level) {
                $scale = $ids[self::LEVELS[$level['code']] ?? self::FIRST] ?? null;

                if (is_string($scale)) {
                    $levels += DB::table('assessment_level_scales')->insertOrIgnore([
                        'division_id' => $division->id, 'level_code' => $level['code'], 'scale_id' => $scale, 'set_by' => null, 'set_at' => $now,
                    ]);
                }
            }
        }

        return "Assessment scales added now: {$added}. Levels given a scale now: {$levels}.";
    }
}
