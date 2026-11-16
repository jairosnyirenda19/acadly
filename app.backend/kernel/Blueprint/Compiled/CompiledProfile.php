<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Compiled;

use Acadly\Kernel\Blueprint\BlueprintKind;
use Acadly\Kernel\Blueprint\BlueprintRef;
use Acadly\Kernel\Blueprint\Canonical;
use Acadly\Kernel\Blueprint\Profile\BindingSlot;
use BackedEnum;
use InvalidArgumentException;

/**
 * A Profile Blueprint in its compiled form, with what it extends already
 * merged in (02 §2). What a division is: its words, its period model, its
 * ladder of levels, how classes are grouped, the subjects it starts with,
 * and the values it binds to the slots modules declare.
 */
final readonly class CompiledProfile implements CompiledBlueprint
{
    /**
     * @param  array<string, array{one: string, many: string}>  $vocabulary  term => words
     * @param  list<array{code: string, label: string}>  $levels  in order, lowest first
     * @param  list<array{short: string, name: string, core: bool, levels: list<string>}>  $subjects  the starter subjects
     * @param  array<string, string>  $bindings  slot key => bound value
     */
    public function __construct(
        public BlueprintRef $ref,
        public string $title,
        public string $summary,
        public bool $abstract,
        public ?string $extends,
        public array $vocabulary,
        public ?string $periodModel,
        public ?int $periodsPerYear,
        public array $levels,
        public ?string $grouping,
        public array $subjects = [],
        public array $bindings = [],
    ) {}

    /** The value bound to a slot, or the slot's own when the profile binds none. */
    public function binding(BindingSlot $slot): BackedEnum
    {
        $value = $this->bindings[$slot->key()] ?? null;
        $enum = $slot->accepts();

        return ($value !== null ? $enum::tryFrom($value) : null) ?? $slot->fallback();
    }

    public function reference(): BlueprintRef
    {
        return $this->ref;
    }

    /** The word for a term, or the term itself when the profile has none. */
    public function word(string $term, bool $plural = false): string
    {
        $words = $this->vocabulary[$term] ?? null;

        return $words === null ? $term : ($plural ? $words['many'] : $words['one']);
    }

    public function hasLevel(string $code): bool
    {
        foreach ($this->levels as $level) {
            if ($level['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    public function levelLabel(string $code): string
    {
        foreach ($this->levels as $level) {
            if ($level['code'] === $code) {
                return $level['label'];
            }
        }

        return $code;
    }

    public function hash(): string
    {
        return Canonical::hash($this->toArray());
    }

    public function json(): string
    {
        return Canonical::json($this->toArray());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => BlueprintKind::Profile->value,
            'ref' => (string) $this->ref,
            'title' => $this->title,
            'summary' => $this->summary,
            'abstract' => $this->abstract,
            'extends' => $this->extends,
            'vocabulary' => $this->vocabulary,
            'structure' => [
                'period_model' => $this->periodModel,
                'periods_per_year' => $this->periodsPerYear,
                'levels' => $this->levels,
                'grouping' => $this->grouping,
            ],
            'subjects' => $this->subjects,
            'bindings' => $this->bindings === [] ? (object) [] : $this->bindings,
        ];
    }

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        if (($data['kind'] ?? null) !== BlueprintKind::Profile->value) {
            throw new InvalidArgumentException('The compiled blueprint is not a profile.');
        }

        $structure = is_array($data['structure'] ?? null) ? $data['structure'] : [];
        $vocabulary = [];

        foreach (is_array($data['vocabulary'] ?? null) ? $data['vocabulary'] : [] as $term => $words) {
            if (is_string($term) && is_array($words)) {
                $vocabulary[$term] = ['one' => Shape::string($words, 'one'), 'many' => Shape::string($words, 'many')];
            }
        }

        $levels = array_map(
            fn (array $level): array => ['code' => Shape::string($level, 'code'), 'label' => Shape::string($level, 'label')],
            Shape::records($structure, 'levels'),
        );
        $perYear = $structure['periods_per_year'] ?? null;
        $subjects = array_map(
            fn (array $s): array => [
                'short' => Shape::string($s, 'short'),
                'name' => Shape::string($s, 'name'),
                'core' => Shape::bool($s, 'core'),
                'levels' => array_values(array_filter(is_array($s['levels'] ?? null) ? $s['levels'] : [], is_string(...))),
            ],
            Shape::records($data, 'subjects'),
        );

        $bindings = [];

        foreach (is_array($data['bindings'] ?? null) ? $data['bindings'] : [] as $slot => $value) {
            if (is_string($slot) && is_string($value)) {
                $bindings[$slot] = $value;
            }
        }

        return new self(
            BlueprintRef::parse(Shape::string($data, 'ref')),
            Shape::string($data, 'title'),
            Shape::string($data, 'summary'),
            Shape::bool($data, 'abstract'),
            Shape::nullableString($data, 'extends'),
            $vocabulary,
            Shape::nullableString($structure, 'period_model'),
            is_int($perYear) ? $perYear : null,
            $levels,
            Shape::nullableString($structure, 'grouping'),
            $subjects,
            $bindings,
        );
    }
}
