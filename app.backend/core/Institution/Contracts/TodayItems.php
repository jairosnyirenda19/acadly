<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Contracts;

/**
 * A module's part of a person's Today (D-100): what they must do now, in the
 * place they work in. A module adds only what this person does: a teacher's
 * register, a head's late registers, an educator's pick-up.
 *
 * - now: the one or two things happening now, each with its action
 * - chart: one small chart of the person's own week (the first module to give one wins)
 * - items: the to-do list; the console sorts by order, attention first
 *
 * @phpstan-type Tone 'plain'|'done'|'attention'|'wrong'
 * @phpstan-type Now array{key: string, order: int, label: string, title: string, sub: string, state: array{tone: Tone, text: string}|null, action: string, href: string}
 * @phpstan-type Item array{key: string, order: int, title: string, sub: string, count: string|null, tone: Tone, href: string|null}
 * @phpstan-type Chart array{kind: 'columns'|'segments', label: string, unit: string, labels: list<string>, values: list<float|int|null>, parts: list<array{name: string, value: int, tone: Tone}>}
 * @phpstan-type Part array{now?: list<Now>, chart?: Chart, items?: list<Item>}
 */
interface TodayItems
{
    public const string TAG = 'institution.today_items';

    /** The module's key: its items show only while it is on. */
    public function key(): string;

    /** @return Part */
    public function today(DashboardScope $scope): array;
}
