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
        return $this->draw($this->dress($instruction), 60.0);
    }

    public function renderMany(array $instructions): string
    {
        return $this->draw(['documents' => array_map(fn (array $i): array => $this->dress($i), $instructions)], 60.0 + 2.0 * count($instructions));
    }

    /**
     * The school's letterhead and the time drawn, added to an instruction.
     *
     * @param  array<string, mixed>  $instruction
     * @return array<string, mixed>
     */
    private function dress(array $instruction): array
    {
        $letterhead = $this->school->letterhead();
        $instruction['brand'] = [
            'name' => $letterhead['name'],
            'address' => implode(' · ', array_filter([$letterhead['address'], $letterhead['phone']])),
            'main' => $this->school->look()['main'],
            'logo' => $letterhead['logo'] !== null ? base64_encode($letterhead['logo']) : null,
        ];
        $instruction['generated'] ??= CarbonImmutable::parse($this->clock->now())->setTimezone($letterhead['timezone'])->format('j M Y, H:i');

        return $instruction;
    }

    /** @param  array<string, mixed>  $instruction */
    private function draw(array $instruction, float $timeout): string
    {
        $python = config('acadly.pdf.python');
        $process = new Process([is_string($python) && $python !== '' ? $python : 'python3', '-I', base_path('workers/acadly_pdf.py')]);
        $process->setInput(json_encode($instruction, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $process->setTimeout(is_numeric(config('acadly.pdf.timeout')) ? max((float) config('acadly.pdf.timeout'), $timeout) : $timeout);
        $process->run();

        $pdf = $process->getOutput();

        if (! $process->isSuccessful() || ! str_starts_with($pdf, '%PDF')) {
            report(new RuntimeException('The document could not be drawn: '.mb_substr($process->getErrorOutput(), -2000)));

            throw new RuntimeException('The document could not be made. Try again shortly.');
        }

        return $pdf;
    }
}
