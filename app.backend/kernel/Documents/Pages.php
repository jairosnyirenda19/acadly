<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents;

use Acadly\Kernel\Documents\Storage\BlobStore;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The pages of a document version as pictures (D-103), the way eOffice shows
 * documents: a PDF is drawn page by page, a picture is one page, and an
 * Office file is first turned into a PDF by LibreOffice when the server has
 * it. The console shows the pictures; the file itself is only downloaded.
 * Each picture is a blob, encrypted like any file. A type that cannot be
 * drawn here is marked 'unsupported', and the screen offers the download.
 */
final class Pages
{
    public const string PDF = 'application/pdf';

    public const array IMAGES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Office types LibreOffice turns into a PDF, with the extension it is told. */
    public const array OFFICE = [
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text' => 'odt',
        'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/vnd.oasis.opendocument.presentation' => 'odp',
        'application/rtf' => 'rtf',
    ];

    private const string PRODUCER = 'pdfium-110';

    public function __construct(
        private readonly BlobStore $blobs,
        private readonly Clock $clock,
    ) {}

    /** Whether a version of this type is ever drawn: PDFs and pictures always, Office files when LibreOffice is set. */
    public static function drawable(string $mime): bool
    {
        return $mime === self::PDF || in_array($mime, self::IMAGES, true) || isset(self::OFFICE[$mime]);
    }

    /**
     * Where the pages of a version stand.
     *
     * @return array{status: 'pending'|'ready'|'failed'|'unsupported', pages: list<array{page: int, width: int, height: int}>}
     */
    public function state(string $fileId, string $mime): array
    {
        $render = DB::table('document_renders')->where('file_id', $fileId)->first(['status']);
        if ($render === null) {
            return ['status' => self::drawable($mime) && ($mime === self::PDF || in_array($mime, self::IMAGES, true) || self::soffice() !== null) ? 'pending' : 'unsupported', 'pages' => []];
        }
        $pages = [];
        foreach (DB::table('document_pages')->where('file_id', $fileId)->orderBy('page')->get(['page', 'width', 'height']) as $p) {
            $pages[] = ['page' => Row::int($p, 'page'), 'width' => Row::int($p, 'width'), 'height' => Row::int($p, 'height')];
        }
        $status = Row::text($render, 'status');

        return ['status' => in_array($status, ['ready', 'failed', 'unsupported'], true) ? $status : 'failed', 'pages' => $pages];
    }

    /** The blob of one page picture, or null. */
    public function page(string $fileId, int $page): ?string
    {
        $sha = DB::table('document_pages')->where('file_id', $fileId)->where('page', $page)->value('blob_sha256');

        return is_string($sha) ? $sha : null;
    }

    /** Draws the pages of a version once. Doing it again does nothing. */
    public function render(string $fileId): void
    {
        if (DB::table('document_renders')->where('file_id', $fileId)->exists()) {
            return;
        }
        $file = DB::table('document_files')->where('id', $fileId)->first(['blob_sha256', 'mime_type']);
        if ($file === null) {
            return;
        }
        $mime = Row::text($file, 'mime_type');
        if (! self::drawable($mime) || (isset(self::OFFICE[$mime]) && self::soffice() === null)) {
            $this->finish($fileId, 'unsupported', []);

            return;
        }
        try {
            $bytes = '';
            foreach ($this->blobs->read(Row::text($file, 'blob_sha256')) as $piece) {
                $bytes .= $piece;
            }
            if (isset(self::OFFICE[$mime])) {
                $bytes = self::toPdf($bytes, self::OFFICE[$mime]);
                $mime = self::PDF;
            }
            $pages = $this->draw($bytes, $mime);
        } catch (Throwable $e) {
            report($e);
            $this->finish($fileId, 'failed', []);

            return;
        }
        $this->finish($fileId, $pages === [] ? 'failed' : 'ready', $pages);
    }

    /**
     * @param  list<array{page: int, width: int, height: int, sha: string}>  $pages
     */
    private function finish(string $fileId, string $status, array $pages): void
    {
        DB::transaction(function () use ($fileId, $status, $pages): void {
            foreach ($pages as $p) {
                DB::table('document_pages')->insertOrIgnore(['file_id' => $fileId, 'page' => $p['page'], 'blob_sha256' => $p['sha'], 'width' => $p['width'], 'height' => $p['height']]);
            }
            DB::table('document_renders')->insertOrIgnore(['file_id' => $fileId, 'status' => $status, 'page_count' => count($pages), 'producer' => self::PRODUCER,
                'created_at' => $this->clock->now()]);
        });
    }

    /**
     * Runs the page worker and stores each picture as a blob.
     *
     * @return list<array{page: int, width: int, height: int, sha: string}>
     */
    private function draw(string $bytes, string $mime): array
    {
        $python = config('acadly.pdf.python');
        $process = new Process([is_string($python) && $python !== '' ? $python : 'python3', '-I', base_path('workers/acadly_pages.py'), $mime]);
        $process->setInput($bytes);
        $process->setTimeout(is_numeric(config('acadly.pdf.timeout')) ? (float) config('acadly.pdf.timeout') * 3 : 180.0);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('The pages could not be drawn: '.mb_substr($process->getErrorOutput(), -2000));
        }
        $out = [];
        foreach (explode("\n", $process->getOutput()) as $line) {
            $row = json_decode($line, true);
            if (! is_array($row) || ! isset($row['page'], $row['jpeg']) || ! is_string($row['jpeg'])) {
                continue;
            }
            $jpeg = base64_decode($row['jpeg'], true);
            if ($jpeg === false || ! str_starts_with($jpeg, "\xFF\xD8")) {
                continue;
            }
            $path = tempnam(sys_get_temp_dir(), 'acadly-');
            if ($path === false) {
                throw new RuntimeException('No room for a page picture.');
            }
            try {
                file_put_contents($path, $jpeg);
                $sha = $this->blobs->put($path, 'image/jpeg');
            } finally {
                @unlink($path);
            }
            $out[] = ['page' => is_numeric($row['page']) ? (int) $row['page'] : count($out) + 1, 'width' => is_numeric($row['width'] ?? null) ? (int) $row['width'] : 0,
                'height' => is_numeric($row['height'] ?? null) ? (int) $row['height'] : 0, 'sha' => $sha];
        }

        return $out;
    }

    /** LibreOffice on this server, or null when there is none. */
    public static function soffice(): ?string
    {
        $bin = config('acadly.documents.soffice');

        return is_string($bin) && $bin !== '' && is_file($bin) ? $bin : null;
    }

    /** Turns an Office file into a PDF with LibreOffice, in a folder of its own that is removed after. */
    private static function toPdf(string $bytes, string $extension): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'acadly-office-'.bin2hex(random_bytes(8));
        if (! mkdir($dir, 0700) && ! is_dir($dir)) {
            throw new RuntimeException('No room to convert the file.');
        }
        $in = $dir.DIRECTORY_SEPARATOR.'file.'.$extension;
        try {
            file_put_contents($in, $bytes);
            $process = new Process([(string) self::soffice(), '--headless', '--norestore', '-env:UserInstallation=file:///'.str_replace('\\', '/', $dir).'/profile',
                '--convert-to', 'pdf', '--outdir', $dir, $in]);
            $process->setTimeout(180.0);
            $process->run();
            $pdf = @file_get_contents($dir.DIRECTORY_SEPARATOR.'file.pdf');
            if (! $process->isSuccessful() || ! is_string($pdf) || ! str_starts_with($pdf, '%PDF')) {
                throw new RuntimeException('The file could not be turned into a PDF.');
            }

            return $pdf;
        } finally {
            self::remove($dir);
        }
    }

    private static function remove(string $dir): void
    {
        foreach (glob($dir.DIRECTORY_SEPARATOR.'{,.}*', GLOB_BRACE) ?: [] as $path) {
            if (in_array(basename($path), ['.', '..'], true)) {
                continue;
            }
            is_dir($path) ? self::remove($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
