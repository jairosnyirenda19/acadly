<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Contracts;

use Carbon\CarbonImmutable;

/**
 * Builds the panels of DashboardPanels (D-100), so every module draws its
 * part in the same shape.
 *
 * @phpstan-import-type Panel from DashboardPanels
 */
final class Panels
{
    /**
     * @param  'full'|'half'|'quarter'  $width
     * @param  array<string, mixed>  $data
     * @return Panel
     */
    public static function make(string $id, string $view, int $order, string $width, string $kind, string $label, string $title, ?string $finding, array $data): array
    {
        return ['id' => $id, 'view' => $view, 'order' => $order, 'width' => $width, 'kind' => $kind, 'label' => $label, 'title' => $title, 'finding' => $finding, 'data' => $data];
    }

    /**
     * A total in the band at the top of a view.
     *
     * @param  'count'|'percent'|'money'  $unit
     * @param  'ink'|'plain'|'attention'  $tone
     * @param  list<int|float>|null  $trend
     * @return Panel
     */
    public static function kpi(string $id, string $view, int $order, string $label, int|float|null $value, string $unit, string $sentence, string $tone = 'plain', ?array $trend = null): array
    {
        return self::make($id, $view, $order, 'quarter', 'kpi', $label, $label, null, ['value' => $value, 'unit' => $unit, 'sentence' => $sentence, 'tone' => $tone, 'trend' => $trend]);
    }

    /**
     * The last weeks up to the day, Monday to Sunday, oldest first.
     *
     * @return list<array{from: string, to: string, label: string}>
     */
    public static function weeks(string $day, int $count): array
    {
        $monday = CarbonImmutable::parse($day)->startOfWeek(CarbonImmutable::MONDAY);
        $out = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            $from = $monday->subWeeks($i);
            $out[] = ['from' => $from->toDateString(), 'to' => $from->addDays(6)->toDateString(), 'label' => $from->format('j M')];
        }

        return $out;
    }

    /** The share as a whole percent, or null when there is nothing to share. */
    public static function percent(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}
