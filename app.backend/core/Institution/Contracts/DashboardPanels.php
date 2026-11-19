<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Contracts;

/**
 * A module's part of the dashboards (D-100): the whole-school overview, and a
 * division's own dashboard. Each panel names the view it belongs to; the
 * console draws the views that have panels, so a module that is off, or a
 * person who may see none of it, adds no view.
 *
 * Every series, bar and row that belongs to a division carries the division's
 * id, so the console can give the division the same colour on every chart.
 * Money is in minor units. Words are plain sentences in the school's language.
 *
 * Kinds and their data:
 * - kpi: value (number), unit, sentence, tone ('ink'|'plain'|'attention'), trend (list of numbers) or null
 * - line: labels, unit, min, max, series: list of {name, division_id|null, dashed, values (number|null)}
 * - columns: labels, unit, stacked, highlight (index|null), series: list of {name, division_id|null, hatched, values}
 * - bars: unit, rows: list of {name, division_id|null, value, whole|null, note|null, tone ('plain'|'done'|'attention')}
 * - segments: unit, parts: list of {name, value}
 * - bands: unit, names (in order, best first), below (index of the band below pass, or null), rows: list of {name, division_id|null, values, note|null}
 * - heat: columns, rule (number|null), rows: list of {name, values (number|null)}
 * - list: rows: list of {division_id|null, text, tone, href|null}
 * - table: heads, rows: list of list of {text, tone|null}
 *
 * @phpstan-type Panel array{id: string, view: string, order: int, width: 'full'|'half'|'quarter', kind: string, label: string, title: string, finding: string|null, data: array<string, mixed>}
 */
interface DashboardPanels
{
    public const string TAG = 'institution.dashboard_panels';

    /** The views, in the order the console shows them, with their names. */
    public const array VIEWS = [
        'overview' => 'Overview',
        'enrolment' => 'Enrolment',
        'attendance' => 'Attendance',
        'fees' => 'Fees',
        'results' => 'Results',
        'conduct' => 'Conduct and support',
    ];

    /** The module's key: its panels show only while it is on. */
    public function key(): string;

    /**
     * The views this module adds to, for this scope: none when the person may see nothing of it.
     *
     * @return list<string>
     */
    public function views(DashboardScope $scope): array;

    /**
     * The module's panels of one view.
     *
     * @return list<Panel>
     */
    public function panels(DashboardScope $scope, string $view): array;
}
