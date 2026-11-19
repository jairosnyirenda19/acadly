<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Pdf;

use Acadly\Kernel\School\SchoolProfile;
use Acadly\Kernel\Support\Clock;
use Carbon\CarbonImmutable;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The document engine (D-096): the ReportLab worker in `workers/`, started as
 * a process, with the instruction on its input and the PDF on its output.
 * The same design as eOffice's. Documents are drawn from the records each
 * time and never stored.
 */
final class ReportlabRenderer implements PdfRenderer
{
    public function __construct(
        private readonly SchoolProfile $school,
        private readonly Clock $clock,
    ) {}

    public function render(array $instruction): string
    {
        $letterhead = $this->school->letterhead();
        $instruction['brand'] = [
            'name' => $letterhead['name'],
            'address' => implode(' · ', array_filter([$letterhead['address'], $letterhead['phone']])),
            'main' => $this->school->look()['main'],
            'logo' => $letterhead['logo'] !== null ? base64_encode($letterhead['logo']) : null,
        ];
        $instruction['generated'] ??= CarbonImmutable::parse($this->clock->now())->setTimezone($letterhead['timezone'])->format('j M Y, H:i');

        $python = config('acadly.pdf.python');
        $process = new Process([is_string($python) && $python !== '' ? $python : 'python3', '-I', base_path('workers/acadly_pdf.py')]);
        $process->setInput(json_encode($instruction, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $process->setTimeout(is_numeric(config('acadly.pdf.timeout')) ? (float) config('acadly.pdf.timeout') : 60.0);
        $process->run();

        $pdf = $process->getOutput();

        if (! $process->isSuccessful() || ! str_starts_with($pdf, '%PDF')) {
            report(new RuntimeException('The document could not be drawn: '.mb_substr($process->getErrorOutput(), -2000)));

            throw new RuntimeException('The document could not be made. Try again shortly.');
        }

        return $pdf;
    }
}
