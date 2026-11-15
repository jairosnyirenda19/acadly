<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module\Licence;

/** The state of the deployment's licence (docs/architecture/03-deployment-campus-access.md §5.5). */
enum LicenceStatus: string
{
    /** Signed, verified and in date. */
    case Valid = 'valid';

    /** Past its expiry date: records can be read and exported, not changed. */
    case Expired = 'expired';

    /** No verified licence is installed: only core functions are available. */
    case Missing = 'missing';

    /** A development or test machine with no licence key configured: every module, no expiry. */
    case Development = 'development';

    public function allowsChanges(): bool
    {
        return $this !== self::Expired;
    }
}
