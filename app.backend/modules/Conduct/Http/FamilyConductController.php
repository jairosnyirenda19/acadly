<?php

declare(strict_types=1);

namespace Acadly\Modules\Conduct\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Modules\Conduct\Application\FamilyConduct;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conduct in the parent portal (D-110). No permission: the parent's active
 * link to the child is the rule, asked on every request.
 */
final class FamilyConductController
{
    /**
     * A child's conduct, as the family was told it.
     *
     * This term's counts, the serious incidents still open with their stage
     * and decision, and the entries of the school year: the date and the kind.
     *
     * @response array{child: array{id: string, first_name: string, name: string}, term: string|null, commendations: int, incidents: int, serious: list<array{id: string, kind: string, happened_on: string, stage: string, decision: string|null, meeting: bool}>, entries: list<array{id: string, kind: string, commendation: bool, serious: bool, happened_on: string, this_term: bool}>, school_phone: string|null}
     */
    public function show(Request $request, string $id, FamilyConduct $conduct): JsonResponse
    {
        $user = $request->user();

        return response()->json($conduct->child($user instanceof User ? $user : throw new AuthenticationException, $id));
    }
}
