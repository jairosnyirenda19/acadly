<?php

declare(strict_types=1);

namespace Acadly\Modules\Results\Contracts;

use Acadly\Kernel\Access\Permission;

/** Results' permissions (D-105). Each is held at a division or the whole school. */
enum ResultsPermission: string implements Permission
{
    case View = 'results.view';
    case Enter = 'results.enter';
    case Moderate = 'results.moderate';
    case Board = 'results.board';
    case Release = 'results.release';
    case Setup = 'results.setup';
    case Award = 'results.award';
    case Transcripts = 'results.transcripts';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::View => 'See every result sheet and board list',
            self::Enter => 'Enter exam marks for your own courses',
            self::Moderate => 'Moderate the result sheets of a department',
            self::Board => 'Decide results as a member of the examiners\' board',
            self::Release => 'Release results to students',
            self::Setup => "Set the grading scale, the attendance bar and the board's rules",
            self::Award => 'Confirm the graduation list as the academic board',
            self::Transcripts => 'Issue official transcripts',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::View => 'See the result sheets, the board lists and each student\'s results in the division. Usually the registry and the deans.',
            self::Enter => 'For the courses you are named on as lecturer: enter exam marks, record absences and send the sheet for moderation.',
            self::Moderate => 'Check a course\'s marks and grade spread, change a mark with a reason, lift an attendance bar, and pass the sheet to the board or return it. Usually the head of department.',
            self::Board => 'Approve the results of each programme and year, accept an absence so the exam is deferred, and decide any change after release, with a reason.',
            self::Release => 'Release approved results to students in the portal and by text message, and prepare the supplementary sitting. Usually the registrar.',
            self::Setup => "Set the grading scale and the board's rules (standing, carried credits, attempts, classes of award) from a semester on, the attendance bar, and whether the results slip and the certificate wait for fees.",
            self::Award => "Confirm the graduation list the examiners' board approved, as the academic board. Usually the principal or the senate's secretary.",
            self::Transcripts => "Issue official transcripts with a check code, on a student's request or for the college's own use. Usually the registry.",
        };
    }

    public function isSight(): bool
    {
        return $this === self::View;
    }
}
