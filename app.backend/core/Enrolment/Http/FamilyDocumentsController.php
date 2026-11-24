<?php

declare(strict_types=1);

namespace Acadly\Core\Enrolment\Http;

use Acadly\Core\Enrolment\Application\FamilyDocuments;
use Acadly\Kernel\Identity\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A child's documents in the parent portal (D-110). No permission: the
 * parent's active link to the child is the rule, asked on every request.
 */
final class FamilyDocumentsController
{
    /**
     * The documents the school shows to the family.
     *
     * Copies of the documents of the types the school shares with families,
     * newest first. Not found when there is none.
     *
     * @response array{child: array{id: string, first_name: string, name: string}, documents: list<array{id: string, title: string, type: string, date: string|null, added_on: string, file_type: string}>, school_phone: string|null}
     */
    public function index(Request $request, string $id, FamilyDocuments $documents): JsonResponse
    {
        return response()->json($documents->child(self::user($request), $id));
    }

    /** One shared document, to open, save or print. Each opening is recorded. */
    public function content(Request $request, string $id, string $document, FamilyDocuments $documents): StreamedResponse
    {
        $opened = $documents->open(self::user($request), $id, $document);
        $inline = in_array($opened['mime'], ['application/pdf', 'image/jpeg', 'image/png'], true);

        return new StreamedResponse(function () use ($opened): void {
            foreach ($opened['bytes'] as $piece) {
                echo $piece;
                flush();
            }
        }, 200, [
            'Content-Type' => $opened['mime'],
            'Content-Length' => (string) $opened['size'],
            'Content-Disposition' => HeaderUtils::makeDisposition($inline ? 'inline' : 'attachment', $opened['name'], 'document'),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
