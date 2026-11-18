<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Application;

use Acadly\Core\Academics\Contracts\Teaching;
use Acadly\Core\Enrolment\Contracts\EnrolmentDirectory;
use Acadly\Core\Enrolment\Contracts\LearnerSignals;
use Acadly\Kernel\Module\ModuleActivation;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The signals of every module that is on, read through `LearnerSignals`
 * with the school's values of their rules, and the learner's figures by
 * term (D-093). Fees are never a signal.
 *
 * @phpstan-type Reason array{source: string, label: string, text: string}
 *
 * @phpstan-import-type Trend from LearnerSignals
 */
final class Signals
{
    public function __construct(
        private readonly ModuleActivation $modules,
        private readonly Teaching $teaching,
        private readonly EnrolmentDirectory $directory,
        private readonly Clock $clock,
    ) {}

    /** @return list<LearnerSignals> */
    public function sources(): array
    {
        $out = [];

        foreach (app()->tagged(LearnerSignals::TAG) as $source) {
            if ($source instanceof LearnerSignals && $this->modules->isActive($source->key())) {
                $out[] = $source;
            }
        }

        return $out;
    }

    /**
     * Each source's rules with the school's values.
     *
     * @return list<array{source: string, label: string, key: string, text: string, value: int, default: int}>
     */
    public function rules(): array
    {
        $values = self::values();
        $out = [];

        foreach ($this->sources() as $source) {
            foreach ($source->rules() as $rule) {
                $out[] = ['source' => $source->key(), 'label' => $source->label(), 'key' => $rule['key'], 'text' => $rule['text'],
                    'value' => $values[$rule['key']] ?? $rule['default'], 'default' => $rule['default']];
            }
        }

        return $out;
    }

    /**
     * Why each learner may need a closer look, every source together.
     *
     * @param  list<string>  $learnerIds
     * @return array<string, list<Reason>>
     */
    public function of(array $learnerIds): array
    {
        $values = [];

        foreach ($this->rules() as $rule) {
            $values[$rule['key']] = $rule['value'];
        }

        $out = [];

        foreach ($this->sources() as $source) {
            foreach ($source->signals($learnerIds, $values) as $learner => $reasons) {
                foreach ($reasons as $text) {
                    $out[$learner][] = ['source' => $source->key(), 'label' => $source->label(), 'text' => $text];
                }
            }
        }

        return $out;
    }

    /**
     * The learner's last four terms, oldest first, and each source's figures for them.
     *
     * @return array{terms: list<array{key: string, name: string, current: bool}>, rows: list<array{source: string, key: string, label: string, unit: string, values: array<string, float|null>, worse: string, change: string|null}>}
     */
    public function trends(string $learnerId): array
    {
        $division = $this->directory->placeOf($learnerId)['division_id'] ?? null;
        $today = $this->clock->today();
        $terms = [];
        $day = $today;

        while ($division !== null && count($terms) < 4) {
            $period = $this->teaching->periodAt($division, $day);

            if ($period === null) {
                break;
            }

            $terms[] = ['key' => $period['id'], 'name' => $period['name'], 'starts_on' => $period['starts_on'], 'ends_on' => $period['ends_on'], 'current' => $period['ends_on'] >= $today];
            $day = CarbonImmutable::parse($period['starts_on'])->subDay()->toDateString();
        }

        $terms = array_reverse($terms);
        $rows = [];
        $args = array_map(fn (array $t): array => ['key' => $t['key'], 'name' => $t['name'], 'starts_on' => $t['starts_on'], 'ends_on' => $t['ends_on']], $terms);

        foreach ($this->sources() as $source) {
            foreach ($source->trends($learnerId, $args) as $trend) {
                $rows[] = ['source' => $source->key(), ...$trend, 'change' => self::change($trend, $terms)];
            }
        }

        return ['terms' => array_map(fn (array $t): array => ['key' => $t['key'], 'name' => $t['name'], 'current' => $t['current']], $terms), 'rows' => $rows];
    }

    /** @return array<string, int> */
    public static function values(): array
    {
        $out = [];

        foreach (DB::table('support_rules')->get(['key', 'value']) as $r) {
            $out[Row::text($r, 'key')] = Row::int($r, 'value');
        }

        return $out;
    }

    /**
     * Better or worse: this term against the last, by the trend's direction.
     *
     * @param  Trend  $trend
     * @param  list<array{key: string}>  $terms
     */
    private static function change(array $trend, array $terms): ?string
    {
        $n = count($terms);

        if ($n < 2) {
            return null;
        }

        $now = $trend['values'][$terms[$n - 1]['key']] ?? null;
        $before = $trend['values'][$terms[$n - 2]['key']] ?? null;

        if ($now === null || $before === null || $now === $before) {
            return null;
        }

        return ($trend['worse'] === 'up') === ($now > $before) ? 'worse' : 'better';
    }
}
