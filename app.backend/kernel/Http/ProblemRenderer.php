<?php

declare(strict_types=1);

namespace Acadly\Kernel\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every error as an RFC 9457 problem document
 * (docs/architecture/05-data-and-api.md §7).
 *
 * The response never carries an exception class, a file path, a stack trace
 * or a framework message, in any environment. Diagnostic detail belongs in
 * the log, where the request identifier links the two.
 */
final class ProblemRenderer
{
    /** @var array<int, array{code: string, title: string}> */
    private const array PROBLEMS = [
        400 => ['code' => 'bad_request', 'title' => 'The request is not valid.'],
        401 => ['code' => 'unauthenticated', 'title' => 'Authentication is required.'],
        403 => ['code' => 'access_refused', 'title' => 'Access is refused.'],
        404 => ['code' => 'not_found', 'title' => 'The resource was not found.'],
        405 => ['code' => 'method_not_allowed', 'title' => 'The method is not allowed.'],
        409 => ['code' => 'conflict', 'title' => 'The request conflicts with the current state.'],
        419 => ['code' => 'session_expired', 'title' => 'The session has expired.'],
        422 => ['code' => 'validation_failed', 'title' => 'The submitted data is not valid.'],
        429 => ['code' => 'too_many_requests', 'title' => 'Too many requests.'],
        503 => ['code' => 'unavailable', 'title' => 'The service is unavailable.'],
    ];

    private const array FALLBACK = ['code' => 'server_error', 'title' => 'The request could not be completed.'];

    public function render(Throwable $e): JsonResponse
    {
        $status = $this->status($e);
        $problem = self::PROBLEMS[$status] ?? self::FALLBACK;

        $body = [
            'type' => 'about:blank',
            'title' => $problem['title'],
            'status' => $status,
            'code' => $problem['code'],
        ];

        if ($e instanceof ValidationException) {
            $body['errors'] = $e->errors();
        }

        return new JsonResponse($body, $status, ['Content-Type' => 'application/problem+json']);
    }

    private function status(Throwable $e): int
    {
        return match (true) {
            $e instanceof ValidationException => 422,
            $e instanceof AuthenticationException => 401,
            $e instanceof AuthorizationException => 403,
            $e instanceof ModelNotFoundException => 404,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => 500,
        };
    }
}
