<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Http;

use Acadly\Kernel\Documents\Pdf\DocumentChecks;
use Acadly\Kernel\School\SchoolProfile;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The public document check (D-096): anyone with a document's code may ask whether it is genuine. */
final class CheckController
{
    /** Kinds of document, as the check page names them. */
    private const array KINDS = [
        'fees.receipt' => 'Receipt',
        'fees.statement' => 'Fee statement',
    ];

    /**
     * Whether a document with this code was issued by the school, and whether it was cancelled.
     *
     * Only the kind, the number and the date: never a name, an amount or a mark.
     *
     * @response array{school: string, kind: string, reference: string, issued_on: string, state: 'genuine'|'cancelled', cancelled: array{on: string, by: string}|null}
     */
    public function __invoke(string $code, DocumentChecks $checks, SchoolProfile $school): JsonResponse
    {
        $found = $checks->check($code) ?? throw new NotFoundHttpException;

        return response()->json([
            'school' => $school->name(),
            'kind' => self::KINDS[$found['kind']] ?? 'Document',
            'reference' => $found['reference'],
            'issued_on' => $found['issued_on'],
            'state' => $found['cancelled'] === null ? 'genuine' : 'cancelled',
            'cancelled' => $found['cancelled'],
        ])->header('Cache-Control', 'no-store');
    }
}
