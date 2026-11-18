<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Application;

use Acadly\Core\Enrolment\Contracts\LearnerSignals;
use Illuminate\Support\Facades\DB;

/**
 * Conduct's signals and figures (D-093). The signals are Conduct's own
 * rules for learners to watch, set in Settings › Conduct.
 */
final class ConductSignals implements LearnerSignals
{
    public function __construct(private readonly ConductViews $views) {}

    public function key(): string
    {
        return 'conduct';
    }

    public function label(): string
    {
        return 'Conduct';
    }

    public function rules(): array
    {
        return [];
    }

    public function signals(array $learnerIds, array $rules): array
    {
        return array_filter($this->views->watch($learnerIds), fn (array $why): bool => $why !== []);
    }

    public function trends(string $learnerId, array $terms): array
    {
        $incidents = [];
        $commendations = [];

        foreach ($terms as $term) {
            $rows = DB::table('conduct_entry_learners as l')->join('conduct_entries as e', 'e.id', '=', 'l.entry_id')
                ->leftJoin('conduct_withdrawals as w', 'w.entry_id', '=', 'e.id')->whereNull('w.entry_id')
                ->where('l.learner_id', $learnerId)->whereBetween('e.happened_on', [$term['starts_on'], $term['ends_on']])->get(['e.commendation']);
            $c = count(array_filter($rows->all(), fn (object $r): bool => (bool) ($r->commendation ?? false)));
            $incidents[$term['key']] = (float) (count($rows) - $c);
            $commendations[$term['key']] = (float) $c;
        }

        return [
            ['key' => 'conduct.incidents', 'label' => 'Incidents', 'unit' => '', 'values' => $incidents, 'worse' => 'up'],
            ['key' => 'conduct.commendations', 'label' => 'Commendations', 'unit' => '', 'values' => $commendations, 'worse' => 'down'],
        ];
    }
}
