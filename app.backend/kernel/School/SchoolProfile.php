<?php

declare(strict_types=1);

namespace Acadly\Kernel\School;

/**
 * What the kernel may know about the school it serves, without knowing the
 * module that keeps it: the name the screens and notices show, whether a logo
 * exists, whether setup is finished, and the divisions and campuses with
 * the words each division uses. The Institution core domain binds the real
 * one; until it is installed, `ConfiguredSchool` answers from configuration.
 */
interface SchoolProfile
{
    public function name(): string;

    /** The short name that starts a text message. */
    public function shortName(): string;

    /** The school's own phone number, as the school wrote it, for the words of a notice. */
    public function phone(): ?string;

    public function hasLogo(): bool;

    /**
     * The school's own look (D-096): the name the console shows (null: the
     * product's), the main and accent colours, and whether the last word of
     * the name takes the accent.
     *
     * @return array{app_name: string|null, main: string, accent: string, accent_last: bool, custom: bool}
     */
    public function look(): array;

    /**
     * What every document's letterhead carries: the school's name, address and
     * phone, the logo's bytes when there is one, and the school's time zone.
     *
     * @return array{name: string, address: string|null, phone: string|null, logo: string|null, timezone: string}
     */
    public function letterhead(): array;

    /** Setup is finished and the school's work is available. */
    public function isOpen(): bool;

    /** @return list<array{id: string, name: string, profile: string, vocabulary: array<string, array{one: string, many: string}>, bindings: array<string, string>}> */
    public function divisions(): array;

    /** @return list<array{id: string, name: string}> */
    public function campuses(): array;
}
