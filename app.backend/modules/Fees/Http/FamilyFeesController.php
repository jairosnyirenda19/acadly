<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Http;

use Acadly\Kernel\Identity\User;
use Acadly\Modules\Fees\Application\Actions\FamilyFeeActions;
use Acadly\Modules\Fees\Application\FamilyFees;
use Acadly\Modules\Fees\Application\FeeDocuments;
use Acadly\Modules\Fees\Application\FeesViews;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Fees in the parent portal (D-108). No permission: the parent's active link to
 * the child is the rule, asked on every request. Not found while the school does
 * not show fees to families, or where Fees is not on for the child.
 */
final class FamilyFeesController
{
    /**
     * The family's fees at a glance.
     *
     * Each child with fees shown (what is left and the next instalment due), and
     * the family's total left with the next date due across the children.
     *
     * @response array{children: list<array{id: string, billed: int, left: int, next_due: array{on: string, amount: int, overdue: bool}|null}>, left: int, next: array{on: string, amount: int, overdue: bool, child_id: string, first_name: string}|null}
     */
    public function family(Request $request, FamilyFees $fees): JsonResponse
    {
        return response()->json($fees->family(self::user($request)));
    }

    /**
     * A child's fees.
     *
     * The term's instalments with their state, any instalment still owed from
     * before, the payments with the child's own part, and how to pay.
     *
     * @response array{child: array{id: string, first_name: string, name: string, class: string}, term: array{name: string, year: string}|null, billed: int, paid: int, left: int, credit: int, next_due: array{on: string, amount: int, overdue: bool}|null, instalments: list<array{label: string, charge: string, due_on: string, amount: int, paid: int, left: int, state: 'paid'|'overdue'|'due'|'not_due', this_term: bool}>, payments: list<array{id: string, number: string, received_on: string, method: string, amount: int, cancelled: bool, shared: bool, receipt: bool, this_term: bool, period: string|null}>, how_to_pay: string, school_phone: string|null}
     */
    public function child(Request $request, string $id, FamilyFees $fees): JsonResponse
    {
        return response()->json($fees->child(self::user($request), $id));
    }

    /** A child's fee statement as a PDF: the same document the fees desk gives, with its check code. */
    public function statement(Request $request, string $id, FamilyFees $fees, FeeDocuments $documents, FeesViews $views, FamilyFeeActions $actions): Response
    {
        $parent = self::user($request);
        $child = $fees->childOf($parent, $id);
        $doc = $documents->statementOf($parent, $id, $child['name'], $views->classOf($id));
        $actions->downloaded($parent, $id, 'statement', null);

        return self::pdf($doc);
    }

    /** A receipt that includes the child, as a PDF: the same receipt the fees desk gives, with its check code. */
    public function receipt(Request $request, string $id, string $receipt, FamilyFees $fees, FeeDocuments $documents, FamilyFeeActions $actions): Response
    {
        $parent = self::user($request);
        $number = $fees->receiptFor($parent, $id, $receipt);
        $doc = $documents->receiptOf($parent, $receipt);
        $actions->downloaded($parent, $id, 'receipt', $number);

        return self::pdf($doc);
    }

    /** @param array{name: string, pdf: string} $doc */
    private static function pdf(array $doc): Response
    {
        return new Response($doc['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $doc['name']).'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthenticationException;
    }
}
