<?php

declare(strict_types=1);

namespace Acadly\Kernel\School;

/** The school as configuration names it, before any school record exists. */
final class ConfiguredSchool implements SchoolProfile
{
    public function phone(): ?string
    {
        return null;
    }

    public function name(): string
    {
        return config()->string('acadly.school_name');
    }

    public function shortName(): string
    {
        return mb_substr($this->name(), 0, 16);
    }

    public function hasLogo(): bool
    {
        return false;
    }

    public function letterhead(): array
    {
        return ['name' => $this->name(), 'address' => null, 'phone' => null, 'logo' => null, 'timezone' => 'Africa/Blantyre'];
    }

    public function look(): array
    {
        return ['app_name' => null, 'main' => Contrast::MAIN, 'accent' => Contrast::ACCENT, 'accent_last' => true, 'custom' => false];
    }

    public function isOpen(): bool
    {
        return true;
    }

    public function divisions(): array
    {
        return [];
    }

    public function campuses(): array
    {
        return [];
    }
}
