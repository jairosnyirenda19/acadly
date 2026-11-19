<?php

declare(strict_types=1);

namespace Acadly\Core\Institution\Domain;

/**
 * The countries a school can be set up in, with their time zone and
 * districts. Malawi first (PRODUCT.md); others are added as they are served.
 */
final class Countries
{
    /** @var array<string, array{name: string, timezone: string, timezone_label: string, districts: list<string>}> */
    public const array ALL = [
        'MW' => [
            'name' => 'Malawi',
            'timezone' => 'Africa/Blantyre',
            'timezone_label' => 'Central Africa Time (UTC+2)',
            'districts' => [
                'Balaka', 'Blantyre', 'Chikwawa', 'Chiradzulu', 'Chitipa', 'Dedza', 'Dowa', 'Karonga', 'Kasungu',
                'Likoma', 'Lilongwe', 'Machinga', 'Mangochi', 'Mchinji', 'Mulanje', 'Mwanza', 'Mzimba', 'Neno',
                'Nkhata Bay', 'Nkhotakota', 'Nsanje', 'Ntcheu', 'Ntchisi', 'Phalombe', 'Rumphi', 'Salima',
                'Thyolo', 'Zomba',
            ],
        ],
    ];

    /** The time zone of a country the school may be in; Malawi's when unknown. */
    public static function timezone(?string $code): string
    {
        return self::ALL[$code ?? '']['timezone'] ?? 'Africa/Blantyre';
    }

    public static function has(string $code): bool
    {
        return isset(self::ALL[$code]);
    }

    public static function hasDistrict(string $country, string $district): bool
    {
        return in_array($district, self::ALL[$country]['districts'] ?? [], true);
    }
}
