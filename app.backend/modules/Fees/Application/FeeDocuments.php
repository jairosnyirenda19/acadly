<?php

declare(strict_types=1);

namespace Acadly\Modules\Fees\Application;

use Acadly\Kernel\Documents\Pdf\DocumentChecks;
use Acadly\Kernel\Documents\Pdf\PdfRenderer;
use Acadly\Kernel\Identity\User;
use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Acadly\Modules\Fees\Contracts\FeesPermission;
use Carbon\CarbonImmutable;

/**
 * The fee documents (D-096): a receipt and a learner's fee statement, drawn
 * by the document engine from the same records the screens read. Each has
 * a check code; a cancelled receipt says so on the paper and on the check page.
 */
final class FeeDocuments
{
    public const string RECEIPT = 'fees.receipt';

    public const string STATEMENT = 'fees.statement';

    public function __construct(
        private readonly FeesViews $views,
        private readonly Ledger $ledger,
        private readonly FeesReach $reach,
        private readonly PdfRenderer $renderer,
        private readonly DocumentChecks $checks,
        private readonly SchoolProfile $school,
        private readonly Clock $clock,
    ) {}

    /** @return array{name: string, pdf: string} */
    public function receipt(User $viewer, string $paymentId): array
    {
        $this->views->receipt($viewer, $paymentId);

        return $this->receiptOf($viewer, $paymentId);
    }

    /**
     * A receipt, drawn without a check: for a caller that has proved the person may see it
     * (the parent portal proves the receipt includes their child). The same paper and the
     * same check code as the fees desk gives.
     *
     * @return array{name: string, pdf: string}
     */
    public function receiptOf(User $viewer, string $paymentId): array
    {
        $r = $this->views->receiptRecord($paymentId);
        $code = $this->checks->codeFor(self::RECEIPT, $r['number'], substr($r['received_on'], 0, 10), $viewer->id);
        $rows = [];
        $total = 0;

        foreach ($r['shares'] as $share) {
            $first = true;
            $lines = $share['applied'] === [] ? [['label' => 'Payment', 'amount' => $share['amount']]] : $share['applied'];

            foreach ($lines as $line) {
                $rows[] = [$first ? $share['name'] : '', $first ? $share['class'] : '', $line['label'], self::plain($line['amount'])];
                $first = false;
            }

            $total += $share['amount'];
        }

        $balance = array_sum(array_map(fn (array $s): int => $s['balance_after'], $r['shares']));
        $void = $r['void'];

        $instruction = [
            'title' => 'Receipt',
            'reference' => $r['number'],
            'status' => $void !== null ? 'Cancelled' : 'Original',
            'sections' => [
                ['kind' => 'facts', 'heading' => 'Payment', 'items' => array_values(array_filter([
                    ['label' => 'Received from', 'value' => $r['payer']],
                    ['label' => 'Paid by', 'value' => $r['method_label'].($r['reference'] !== null ? ' · '.$r['reference'] : '')],
                    ['label' => 'Received on', 'value' => CarbonImmutable::parse($r['received_on'])->format('l j F Y')],
                    $r['received_by'] !== null ? ['label' => 'Received by', 'value' => $r['received_by']] : null,
                    $void !== null ? ['label' => 'Cancelled', 'value' => $void['number'].' on '.CarbonImmutable::parse($void['on'])->format('j F Y').'. '.$void['reason']] : null,
                ]))],
                ['kind' => 'table', 'heading' => 'Applied to', 'columns' => ['Learner', 'Class', 'Instalment', 'Amount'], 'align' => ['left', 'left', 'left', 'right'],
                    'weights' => [2, 1.3, 3, 1.2], 'rows' => $rows],
                ['kind' => 'tiles', 'items' => [
                    ['label' => 'Total received', 'value' => self::money($total), 'note' => $void !== null ? 'Cancelled: not counted' : self::words($total)],
                    ['label' => count($r['shares']) > 1 ? 'Balance now, together' : 'Balance now', 'value' => self::money($balance), 'note' => 'After this payment'],
                ]],
                ['kind' => 'signatures', 'signed_by' => 'Received by'.($r['received_by'] !== null ? ' · '.$r['received_by'] : '')],
                ['kind' => 'text', 'text' => 'This receipt is kept by the school and cannot be changed. A mistake is put right by a cancellation receipt with its own number.'],
            ],
            'check' => ['code' => $code, 'url' => self::checkUrl()],
            'footer' => $this->school->name().' · Receipt '.$r['number'],
        ];

        return ['name' => 'Receipt '.$r['number'].'.pdf', 'pdf' => $this->renderer->render($instruction)];
    }

    /** @return array{name: string, pdf: string} */
    public function statement(User $viewer, string $learnerId, string $name, ?string $class): array
    {
        $this->reach->learner($viewer, $learnerId, FeesPermission::View);

        return $this->statementOf($viewer, $learnerId, $name, $class);
    }

    /**
     * A learner's statement, drawn without a check: for a caller that has proved the person may
     * see it (the student portal proves the learner is the person asking, D-101).
     *
     * @return array{name: string, pdf: string}
     */
    public function statementOf(User $viewer, string $learnerId, string $name, ?string $class): array
    {
        $s = $this->ledger->standing($learnerId);
        $statement = $this->ledger->statement($learnerId);
        $today = $this->clock->today();
        $reference = 'STM-'.str_replace('-', '', $today).'-'.substr(str_replace('-', '', $learnerId), -6);
        $code = $this->checks->codeFor(self::STATEMENT, $reference, $today, $viewer->id);

        $rows = array_map(fn (array $l): array => [
            CarbonImmutable::parse($l['date'])->format('d M Y'),
            $l['detail'].($l['note'] !== null && $l['note'] !== '' ? ' · '.$l['note'] : ''),
            $l['reference'],
            $l['charged'] !== null ? self::plain($l['charged']) : '',
            $l['paid'] !== null ? self::plain($l['paid']) : '',
            self::plain($l['balance']),
        ], $statement);

        $instalments = array_map(fn (array $i): array => [
            $i['charge'].' · '.$i['label'],
            CarbonImmutable::parse($i['due_on'])->format('j M Y'),
            self::plain($i['amount']),
            self::plain(max(0, $i['amount'] - $i['covered'])),
            match ($i['state']) {
                'paid' => 'Paid', 'overdue' => 'Overdue', 'due' => 'Due', default => 'Not yet due'
            },
        ], $s['instalments']);

        $instruction = [
            'title' => 'Fee statement',
            'reference' => $reference,
            'status' => 'Original',
            'sections' => [
                ['kind' => 'facts', 'heading' => 'Learner', 'columns' => 2, 'items' => [
                    ['label' => 'Learner', 'value' => $name],
                    ['label' => 'Class', 'value' => $class ?? ''],
                    ['label' => 'As at', 'value' => CarbonImmutable::parse($today)->format('j F Y')],
                    ['label' => 'Due now', 'value' => self::money($s['due_now'])],
                ]],
                ['kind' => 'tiles', 'items' => [
                    ['label' => 'Balance', 'value' => self::money($s['balance']), 'note' => $s['due_now'] > 0 ? 'Due now: '.self::money($s['due_now']) : 'Nothing due now'],
                    ['label' => 'Charged', 'value' => self::money($s['charged']), 'note' => $s['reduced'] > 0 ? 'Reduced by '.self::money($s['reduced']) : ''],
                    ['label' => 'Paid', 'value' => self::money($s['paid']), 'note' => $s['credit'] > 0 ? 'Credit held: '.self::money($s['credit']) : ''],
                ]],
                ['kind' => 'table', 'heading' => 'Instalments', 'columns' => ['Instalment', 'Due', 'Amount', 'Left', 'State'], 'align' => ['left', 'left', 'right', 'right', 'left'],
                    'weights' => [3, 1.3, 1.3, 1.3, 1.2], 'rows' => $instalments],
                ['kind' => 'table', 'heading' => 'Statement', 'margin' => true, 'columns' => ['Date', 'Detail', 'Reference', 'Charged', 'Paid', 'Balance'],
                    'align' => ['mono', 'left', 'mono', 'right', 'right', 'right'], 'weights' => [1.2, 3.2, 1.7, 1.1, 1.1, 1.2], 'rows' => $rows],
                ['kind' => 'signatures', 'signed_by' => 'For the school'],
                ['kind' => 'text', 'text' => 'Each payment counts towards the earliest instalment still owed. Nothing on a statement is changed or deleted; a mistake is put right by a new line.'],
            ],
            'check' => ['code' => $code, 'url' => self::checkUrl()],
            'footer' => $this->school->name().' · Fee statement '.$reference,
        ];

        return ['name' => 'Fee statement '.$name.'.pdf', 'pdf' => $this->renderer->render($instruction)];
    }

    private static function checkUrl(): string
    {
        $base = config('acadly.frontend_url');

        return rtrim(is_string($base) ? $base : '', '/').'/check';
    }

    /** "MK 150,000"; tambala only when there are some. */
    public static function money(int $minor): string
    {
        return ($minor < 0 ? '−' : '').'MK '.self::plain(abs($minor));
    }

    private static function plain(int $minor): string
    {
        $kwacha = intdiv(abs($minor), 100);
        $tambala = abs($minor) % 100;

        return ($minor < 0 ? '−' : '').number_format($kwacha).($tambala > 0 ? '.'.str_pad((string) $tambala, 2, '0', STR_PAD_LEFT) : '');
    }

    /** The amount in words, as a receipt says it: "One hundred thousand kwacha". */
    private static function words(int $minor): string
    {
        $n = intdiv($minor, 100);
        $text = $n === 0 ? 'zero' : self::spell($n);

        return ucfirst($text).' kwacha'.($minor % 100 > 0 ? ' and '.self::spell($minor % 100).' tambala' : '');
    }

    private static function spell(int $n): string
    {
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        $part = function (int $x) use ($ones, $tens): string {
            $out = [];
            if ($x >= 100) {
                $out[] = $ones[intdiv($x, 100)].' hundred';
                $x %= 100;
            }
            if ($x >= 20) {
                $out[] = $tens[intdiv($x, 10)].($x % 10 > 0 ? '-'.$ones[$x % 10] : '');
            } elseif ($x > 0) {
                $out[] = $ones[$x];
            }

            return implode(' and ', $out);
        };
        $out = [];

        foreach ([[1_000_000_000, 'billion'], [1_000_000, 'million'], [1_000, 'thousand']] as [$size, $word]) {
            if ($n >= $size) {
                $out[] = $part(intdiv($n, $size)).' '.$word;
                $n %= $size;
            }
        }

        if ($n > 0) {
            $out[] = $part($n);
        }

        return implode(' ', $out);
    }
}
