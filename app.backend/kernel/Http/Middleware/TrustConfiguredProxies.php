<?php

declare(strict_types=1);

namespace Acadly\Kernel\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Trusts only the web server in front, named in ACADLY_TRUSTED_PROXIES (D-112).
 * Without it every visitor seems to come from that server, and one person's
 * tries would use up the sign-in limits of a whole school.
 */
final class TrustConfiguredProxies extends TrustProxies
{
    /** @return list<string>|null */
    protected function proxies()
    {
        $proxies = config('acadly.trusted_proxies');

        return is_array($proxies) && $proxies !== [] ? array_values(array_filter($proxies, 'is_string')) : null;
    }
}
