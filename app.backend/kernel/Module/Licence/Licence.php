<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module\Licence;

use Carbon\CarbonImmutable;

/**
 * What the deployment is licensed for. Built only from a document whose
 * signature verifies, or as the development licence.
 */
final readonly class Licence
{
    /**
     * @param  list<string>  $modules  optional module keys the licence includes
     * @param  array<string, int>  $limits
     */
    public function __construct(
        public LicenceStatus $status,
        public ?string $serial = null,
        public ?string $licensee = null,
        public array $modules = [],
        public array $limits = [],
        public ?CarbonImmutable $expiresAt = null,
    ) {}

    public static function missing(): self
    {
        return new self(LicenceStatus::Missing);
    }

    public static function development(): self
    {
        return new self(LicenceStatus::Development, licensee: 'Development');
    }

    public function includes(string $module): bool
    {
        return match ($this->status) {
            LicenceStatus::Development => true,
            LicenceStatus::Missing => false,
            LicenceStatus::Valid, LicenceStatus::Expired => in_array($module, $this->modules, true),
        };
    }
}
