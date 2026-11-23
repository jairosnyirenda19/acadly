<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Application\Actions;

use Acadly\Kernel\Access\ScopeRef;
use Acadly\Kernel\Access\ScopeType;
use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Ids;
use Acadly\Modules\Programmes\Contracts\CourseTeaching;
use Acadly\Modules\Results\Application\Sheets;
use Acadly\Modules\Results\Contracts\ResultsAudit;
use Acadly\Modules\Results\Contracts\ResultsPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Transcripts asked for by a student and issued by the registry (D-106). Both are rows, append-only. */
final class TranscriptActions
{
    public function __construct(
        private readonly Sheets $sheets,
        private readonly CourseTeaching $teaching,
        private readonly Audit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * A student asks for an official transcript, for themself or for an organisation by email.
     *
     * @param  array{for_kind: string, organisation: string|null, email: string|null, purpose: string|null}  $input
     */
    public function request(User $student, array $input): string
    {
        $s = $this->teaching->studentOf($student->id) ?? throw new NotFoundHttpException;
        if ($input['for_kind'] === 'organisation' && (trim($input['organisation'] ?? '') === '' || filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL) === false)) {
            throw ValidationException::withMessages(['email' => ['Give the organisation and a valid email address.']]);
        }
        if (DB::table('results_transcript_requests')->where('learner_id', $s['learner_id'])->where('created_at', '>', $this->clock->now()->subDay())->count() >= 5) {
            throw ValidationException::withMessages(['for_kind' => ['You have asked for 5 transcripts today. Ask again tomorrow, or speak to the registry.']]);
        }
        $id = Ids::next();
        DB::transaction(function () use ($student, $s, $input, $id): void {
            DB::table('results_transcript_requests')->insert(['id' => $id, 'division_id' => $s['division_id'], 'learner_id' => $s['learner_id'], 'for_kind' => $input['for_kind'],
                'organisation' => $input['for_kind'] === 'organisation' ? trim((string) $input['organisation']) : null, 'email' => $input['for_kind'] === 'organisation' ? trim((string) $input['email']) : null,
                'purpose' => $input['purpose'] !== null && trim($input['purpose']) !== '' ? trim($input['purpose']) : null, 'created_at' => $this->clock->now()]);
            $this->audit->record(ResultsAudit::TranscriptRequested, actor: $this->audit->actorFor($student), at: ScopeRef::of(ScopeType::Division, $s['division_id']),
                aboutPersonId: $s['learner_id'], meta: ['request' => $id]);
        });

        return $id;
    }

    /** The registry issues an official transcript, on a request or for the college's own use. */
    public function issue(User $by, string $divisionId, string $learnerId, ?string $requestId): string
    {
        $this->sheets->enter($by, $divisionId);
        if (! $this->sheets->holds($by, ResultsPermission::Transcripts, $divisionId)) {
            throw new AccessDeniedHttpException;
        }
        $file = $this->teaching->studentFile($learnerId);
        if ($file === null || $file['division_id'] !== $divisionId) {
            throw new NotFoundHttpException;
        }
        if ($requestId !== null && ! DB::table('results_transcript_requests')->where('id', $requestId)->where('learner_id', $learnerId)->exists()) {
            throw new NotFoundHttpException;
        }
        $count = DB::table('results_transcripts')->where('learner_id', $learnerId)->count();
        $id = Ids::next();
        $reference = 'TR-'.$file['number'].'-'.($count + 1);
        DB::transaction(function () use ($by, $divisionId, $learnerId, $requestId, $id, $reference): void {
            DB::table('results_transcripts')->insert(['id' => $id, 'division_id' => $divisionId, 'learner_id' => $learnerId, 'request_id' => $requestId, 'reference' => $reference,
                'issued_by' => $by->id, 'issued_at' => $this->clock->now()]);
            $this->audit->record(ResultsAudit::TranscriptIssued, actor: $this->audit->actorFor($by), at: ScopeRef::of(ScopeType::Division, $divisionId),
                aboutPersonId: $learnerId, meta: ['transcript' => $id, 'reference' => $reference]);
        });

        return $id;
    }
}
