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

    /** Setup is finished and the school's work is available. */
    public function isOpen(): bool;

    /** @return list<array{id: string, name: string, vocabulary: array<string, array{one: string, many: string}>}> */
    public function divisions(): array;

    /** @return list<array{id: string, name: string}> */
    public function campuses(): array;
}
