<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Compiled;

use Acadly\Kernel\Blueprint\BlueprintKind;
use Acadly\Kernel\Blueprint\BlueprintRef;
use Acadly\Kernel\Blueprint\Canonical;
use InvalidArgumentException;

/**
 * A Profile Blueprint in its compiled form, with what it extends already
 * merged in (02 §2). What a division is: its words, its period model, its
 * ladder of levels and how classes are grouped.
 */
final readonly class CompiledProfile implements CompiledBlueprint
{
    /**
     * @param  array<string, array{one: string, many: string}>  $vocabulary  term => words
     * @param  list<array{code: string, label: string}>  $levels  in order, lowest first
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
    ) {}

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
        );
    }
}
