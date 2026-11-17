<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Application;

use Acadly\Core\Enrolment\Contracts\FamilyLine;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * Report cards in the parent portal (D-082): the newest card published for
 * the child, and the line on the child's card while it is new. The caller
 * checks that the parent sees the learner.
 */
final class FamilyCards implements FamilyLine
{
    /** A card is new for this many days after it was published. */
    public const int NEW_DAYS = 14;

    public function __construct(
        private readonly Clock $clock,
        private readonly SchoolProfile $school,
    ) {}

    public function line(string $learnerId, ?string $classId): ?array
    {
        $newest = $this->newestRow($learnerId);

        if ($newest === null) {
            return null;
        }

        $card = self::decode(Row::text($newest, 'card'));
        $period = is_array($card['period'] ?? null) && is_string($card['period']['name'] ?? null) ? $card['period']['name'] : null;
        $new = $this->clock->now()->subDays(self::NEW_DAYS)->toIso8601String() <= Row::text($newest, 'published_at');

        return ['key' => 'card', 'word' => $new ? 'new_card' : 'card', 'label' => $period, 'tone' => $new ? 'attention' : 'plain'];
    }

    /**
     * The newest card published for the learner, as it was published.
     *
     * @return array{school: array{name: string, has_logo: bool}, published_at: string, version: int, reference: string, card: array<string, mixed>}|null
     */
    public function newest(string $learnerId): ?array
    {
        $row = $this->newestRow($learnerId);

        if ($row === null) {
            return null;
        }

        return [
            'school' => ['name' => $this->school->name(), 'has_logo' => $this->school->hasLogo()],
            'published_at' => Row::text($row, 'published_at'),
            'version' => Row::int($row, 'version'),
            'reference' => Row::text($row, 'reference'),
            'card' => self::decode(Row::text($row, 'card')),
        ];
    }

    private function newestRow(string $learnerId): ?object
    {
        if (! Ids::isValid($learnerId)) {
            return null;
        }

        return DB::table('result_publication_learners as l')->join('result_publications as p', 'p.id', '=', 'l.publication_id')
            ->where('l.learner_id', $learnerId)->orderByDesc('p.published_at')->orderByDesc('p.version')
            ->first(['l.card', 'l.reference', 'p.published_at', 'p.version']);
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        $out = json_decode($json, true);

        return is_array($out) ? array_filter($out, fn ($k): bool => is_string($k), ARRAY_FILTER_USE_KEY) : [];
    }
}
