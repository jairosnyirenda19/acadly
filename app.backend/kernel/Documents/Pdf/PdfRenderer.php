<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents\Pdf;

/**
 * Draws an A4 document from an instruction (D-096): the records, gathered by
 * the module that owns the document, turned into sections. The school's
 * letterhead is added here, so no document can leave it out or change it.
 *
 * The instruction: `{title, reference, status, sections, check, footer}`.
 * The sections and their kinds are those of `workers/acadly_pdf.py`.
 */
interface PdfRenderer
{
    /**
     * @param  array<string, mixed>  $instruction
     * @return string the PDF's bytes
     */
    public function render(array $instruction): string;

    /**
     * Several documents joined into one PDF, each on its own pages (D-107).
     *
     * @param  list<array<string, mixed>>  $instructions
     * @return string the PDF's bytes
     */
    public function renderMany(array $instructions): string;
}
