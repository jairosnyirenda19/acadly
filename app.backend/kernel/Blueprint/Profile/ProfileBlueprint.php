<?php

declare(strict_types=1);

namespace Acadly\Kernel\Blueprint\Profile;

use Acadly\Kernel\Blueprint\BlueprintKind;
use Acadly\Kernel\Blueprint\BlueprintRef;
use Acadly\Kernel\Blueprint\Compiled\CompiledProfile;
use BackedEnum;
use InvalidArgumentException;

/**
 * Declares what a division is (02 §2): its words, its period model, its
 * ladder of levels and how its classes are grouped. Authored in
 * `app.backend/blueprints/profiles/<Name>/V<YYYY>_<N>.php`.
 *
 * The kernel names no education level: terms, period models and groupings
 * are enums owned by the core domains, passed in as cases. A profile that
 * extends another takes its values and overrides what it states itself.
 * Bindings, journeys, roles and defaults join this declaration with the
 * code that runs them.
 */
final class ProfileBlueprint
{
    private string $title = '';

    private string $summary = '';

    private ?BlueprintRef $extends = null;

    /** @var array<string, array{one: string, many: string}> */
    private array $vocabulary = [];

    /** @var array<class-string<BackedEnum>, true> */
    private array $termEnums = [];

    private ?string $periodModel = null;

    private ?int $periodsPerYear = null;

    /** @var list<array{code: string, label: string}>|null */
    private ?array $levels = null;

    private ?string $grouping = null;

    /** @var list<array{short: string, name: string, core: bool, levels: list<string>|null}>|null */
    private ?array $subjects = null;

    /** @var array<string, string> slot key => bound value */
    private array $bindings = [];

    /** @var list<string>|null the modules the profile offers, by key; null: as its parent */
    private ?array $modules = null;

    private function __construct(
        public readonly BlueprintRef $ref,
        public readonly bool $abstract,
    ) {}

    public static function make(string $ref): self
    {
        return new self(self::parse($ref), false);
    }

    /** A profile that others extend. It cannot be pinned on a division. */
    public static function abstract(string $ref): self
    {
        return new self(self::parse($ref), true);
    }

    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /** One plain sentence for the setup screen. */
    public function summary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    public function extends(string $ref): self
    {
        $this->extends = self::parse($ref);

        return $this;
    }

    public function term(BackedEnum $term, string $one, string $many): self
    {
        $this->vocabulary[self::value($term)] = ['one' => $one, 'many' => $many];
        $this->termEnums[$term::class] = true;

        return $this;
    }

    public function periods(BackedEnum $model, int $perYear): self
    {
        $this->periodModel = self::value($model);
        $this->periodsPerYear = $perYear;

        return $this;
    }

    /** @param  array<string, string>  $levels  code => label, lowest first */
    public function levels(array $levels): self
    {
        $this->levels = [];

        foreach ($levels as $code => $label) {
            $this->levels[] = ['code' => $code, 'label' => $label];
        }

        return $this;
    }

    public function grouping(BackedEnum $grouping): self
    {
        $this->grouping = self::value($grouping);

        return $this;
    }

    /**
     * A starter subject: the school begins with it and keeps its own list
     * from then on. `$levels` are level codes; null means every level.
     *
     * @param  list<string>|null  $levels
     */
    public function subject(string $short, string $name, bool $core = true, ?array $levels = null): self
    {
        $this->subjects ??= [];
        $this->subjects[] = ['short' => $short, 'name' => $name, 'core' => $core, 'levels' => $levels];

        return $this;
    }

    /**
     * The parts a division of this profile has (D-099): the modules it offers, by
     * key, shared ones included. A module not listed is not offered there: not
     * in its menu, and refused by the server. A child adds to its parent's list.
     *
     * @param  list<string>  $keys
     */
    public function modules(array $keys): self
    {
        $this->modules = array_values(array_unique($keys));

        return $this;
    }

    /** Binds a value to a slot a module declares (02 §2.4). */
    public function bind(BindingSlot $slot, BackedEnum $value): self
    {
        if (! $value instanceof ($slot->accepts())) {
            throw new InvalidArgumentException("{$this->ref}: the slot [{$slot->key()}] takes a ".$slot->accepts().'.');
        }

        $this->bindings[$slot->key()] = self::value($value);

        return $this;
    }

    public function extendsRef(): ?BlueprintRef
    {
        return $this->extends;
    }

    /** @return list<class-string<BackedEnum>> */
    public function termEnums(): array
    {
        return array_keys($this->termEnums);
    }

    /** The compiled form, with the parent merged in. */
    public function compile(?CompiledProfile $parent = null): CompiledProfile
    {
        if ($this->extends !== null && ($parent === null || ! $parent->ref->equals($this->extends))) {
            throw new InvalidArgumentException("{$this->ref}: the profile it extends, [{$this->extends}], is not declared.");
        }

        $levels = $this->levels ?? ($parent->levels ?? []);
        $every = array_column($levels, 'code');
        $subjects = $this->subjects === null ? ($parent->subjects ?? []) : array_map(
            fn (array $s): array => ['short' => $s['short'], 'name' => $s['name'], 'core' => $s['core'], 'levels' => $s['levels'] ?? $every],
            $this->subjects,
        );

        return new CompiledProfile(
            $this->ref,
            $this->title !== '' ? $this->title : ($parent->title ?? ''),
            $this->summary !== '' ? $this->summary : ($parent->summary ?? ''),
            $this->abstract,
            $this->extends !== null ? (string) $this->extends : null,
            [...($parent->vocabulary ?? []), ...$this->vocabulary],
            $this->periodModel ?? $parent?->periodModel,
            $this->periodsPerYear ?? $parent?->periodsPerYear,
            $levels,
            $this->grouping ?? $parent?->grouping,
            $subjects,
            [...($parent->bindings ?? []), ...$this->bindings],
            array_values(array_unique([...($parent->modules ?? []), ...($this->modules ?? [])])),
        );
    }

    private static function parse(string $ref): BlueprintRef
    {
        $parsed = BlueprintRef::parse($ref);

        return $parsed->kind() === BlueprintKind::Profile ? $parsed : throw new InvalidArgumentException("[{$ref}] is not a profile reference.");
    }

    private static function value(BackedEnum $case): string
    {
        return is_string($case->value) ? $case->value : throw new InvalidArgumentException('Profile terms, models and groupings are string-backed enums.');
    }
}
