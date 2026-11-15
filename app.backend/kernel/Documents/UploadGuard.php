<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents;

use Acadly\Kernel\Audit\Audit;
use Acadly\Kernel\Audit\KernelAudit;
use Acadly\Kernel\Audit\Outcome;
use Acadly\Kernel\Documents\Scanning\ScannerUnavailable;
use Acadly\Kernel\Documents\Scanning\VirusScanner;
use Acadly\Kernel\Documents\Storage\StorageMeter;
use Acadly\Kernel\Identity\User;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The one gate every upload passes before anything is stored (ported from
 * eOffice, with a type list per kind):
 *  1. size, within the kind's limit and the deployment's;
 *  2. type, read from the content, never from the file name;
 *  3. room, within the licence's storage, for kinds that are not essential;
 *  4. a virus scan. A scanner that cannot be reached is not a pass.
 *
 * A refusal is an audit line, named by the hash of the bytes. The bytes are not kept.
 */
final class UploadGuard
{
    public function __construct(
        private readonly VirusScanner $scanner,
        private readonly StorageMeter $storage,
        private readonly Audit $audit,
    ) {}

    /** @return array{sha: string, mime: string, size: int} */
    public function check(UploadedFile $upload, DocumentKind $kind, User $who): array
    {
        $path = $upload->getRealPath();

        if ($path === false || ! $upload->isValid()) {
            throw ValidationException::withMessages(['file' => ['The file was not received completely.']]);
        }

        $size = (int) filesize($path);
        $sha = (string) hash_file('sha256', $path);
        $limit = min($kind->maxBytes(), config()->integer('acadly.documents.max_upload_bytes'));

        if ($size > $limit) {
            $this->refuse($who, $kind, $sha, 'too_large', 'The file is larger than '.self::megabytes($limit).' MB.');
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);

        if (! in_array($mime, $kind->accepts(), true)) {
            $this->refuse($who, $kind, $sha, 'type', 'This type of file is not accepted here.');
        }

        if (! $kind->essential() && ! $this->storage->hasRoom($size, $sha)) {
            $this->refuse($who, $kind, $sha, 'storage_full', 'Document storage is full. Only essential records can be added.');
        }

        try {
            $signature = $this->scanner->scan($path);
        } catch (ScannerUnavailable) {
            $this->refuse($who, $kind, $sha, 'scanner_unavailable', 'The file could not be checked for viruses, so it was not accepted. Try again later.');
        }

        if ($signature !== null) {
            $this->refuse($who, $kind, $sha, 'infected', 'The file was refused: it contains a virus.');
        }

        return ['sha' => $sha, 'mime' => $mime, 'size' => $size];
    }

    private function refuse(User $who, DocumentKind $kind, string $sha, string $reason, string $message): never
    {
        $this->audit->record(KernelAudit::UploadRefused, outcome: Outcome::Refused, refusal: 'upload:'.$reason,
            actor: $this->audit->actorFor($who), meta: ['kind' => $kind->key(), 'sha256' => $sha]);

        throw ValidationException::withMessages(['file' => [$message]]);
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1), '0'), '.');
    }
}
