<?php

declare(strict_types=1);

namespace Acadly\Kernel\Module\Http;

use Acadly\Kernel\Http\Problem;
use Acadly\Kernel\Module\Licence\Licences;
use Acadly\Kernel\Module\ModuleActivation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `module:<key>` on every route of a module. A module that is not on answers
 * `module_inactive`. While the licence is expired, a request that would
 * change something answers `licence_read_only`; reading and exporting go on.
 */
final class RequireModule
{
    private const array SAFE = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly ModuleActivation $activation,
        private readonly Licences $licences,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (! $this->activation->isActive($module)) {
            throw Problem::moduleInactive();
        }

        if (! in_array($request->getMethod(), self::SAFE, true) && ! $this->licences->current()->status->allowsChanges()) {
            throw Problem::readOnly();
        }

        return $next($request);
    }
}
