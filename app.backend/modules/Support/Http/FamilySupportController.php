<?php

declare(strict_types=1);

namespace Acadly\Modules\Support\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Modules\Support\Application\FamilySupport;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Support plans in the parent portal (D-110). No permission: the parent's
 * active link to the child is the rule, asked on every request.
 */
final class FamilySupportController
{
    /**
     * A child's support plans, as the family sees them.
     *
     * The open plans the school shows to the family: the title, who leads it,
     * the next review, and the part written for the family. Not found when
     * there is none.
     *
     * @response array{child: array{id: string, first_name: string, name: string}, plans: list<array{id: string, title: string, lead: string, opened_on: string, next_review_on: string, state: string, school: string, home: string}>, school_phone: string|null}
     */
    public function show(Request $request, string $id, FamilySupport $support): JsonResponse
    {
        $user = $request->user();

        return response()->json($support->child($user instanceof User ? $user : throw new AuthenticationException, $id));
    }
}
