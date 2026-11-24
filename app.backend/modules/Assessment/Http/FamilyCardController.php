<?php

declare(strict_types=1);

namespace Acadly\Modules\Assessment\Http;

use Acadly\Core\Enrolment\Contracts\Guardianship;
use Acadly\Kernel\Identity\User;
use Acadly\Modules\Assessment\Application\CardDocuments;
use Acadly\Modules\Assessment\Application\FamilyCards;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The parent portal's report card screen (D-082). */
final class FamilyCardController
{
    /**
     * A child's report card.
     *
     * The newest report card published for the child, exactly as published,
     * for a parent whose link to the child is active. `card` is null when no
     * card has been published.
     *
     * @response array{child: array{id: string, first_name: string, name: string, class_id: string|null, class: string}, card: array{school: array{name: string, has_logo: bool}, published_at: string, version: int, reference: string, card: array<string, mixed>}|null}
     */
    public function __invoke(Request $request, string $id, Guardianship $guardianship, FamilyCards $cards): JsonResponse
    {
        $parent = $request->user();

        if (! $parent instanceof User) {
            throw new AuthenticationException;
        }

        $child = $guardianship->child($parent, $id) ?? throw new NotFoundHttpException;

        return response()->json(['child' => $child, 'card' => $cards->newest($id)]);
    }

    /** The child's newest report card as a PDF, with its check code (D-107). */
    public function pdf(Request $request, string $id, Guardianship $guardianship, FamilyCards $cards, CardDocuments $documents): Response
    {
        $parent = $request->user();
        if (! $parent instanceof User) {
            throw new AuthenticationException;
        }
        $child = $guardianship->child($parent, $id) ?? throw new NotFoundHttpException;
        $card = $cards->newest($id) ?? throw new NotFoundHttpException;
        $doc = $documents->cards($parent, [[...$card['card'], 'reference' => $card['reference']]], $card['published_at'], $card['version'], 'Report card '.$child['name'].' '.$card['reference']);
        $documents->downloaded($parent, CardDocuments::CARD, $card['reference'], $id);

        return new Response($doc['pdf'], 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $doc['name'], 'report-card.pdf'), 'Cache-Control' => 'no-store']);
    }
}
