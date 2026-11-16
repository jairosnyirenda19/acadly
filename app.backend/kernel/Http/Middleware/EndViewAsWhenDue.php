<?php

declare(strict_types=1);

namespace Acadly\Kernel\Http\Middleware;

use Acadly\Kernel\Identity\Actions\ViewAsActions;
use Acadly\Kernel\Identity\ViewAs;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lock 4 of View as: when its time is up, or it has been turned off, the
 * session steps back into the administrator's own account before the
 * request is handled, so the end bites at once.
 */
final class EndViewAsWhenDue
{
    public function __construct(
        private readonly ViewAs $viewAs,
        private readonly ViewAsActions $actions,
    ) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && $this->viewAs->by($request) !== null && $this->viewAs->due($request)) {
            $this->actions->end($request, 'ended');
        }

        return $next($request);
    }
}
