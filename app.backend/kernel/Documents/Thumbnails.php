<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents;

use Acadly\Kernel\Documents\Storage\BlobStore;
use Acadly\Kernel\Support\Clock;
use Acadly\Kernel\Support\Row;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Small pictures of the first page of a document version (D-090).
 *
 * Every picture is decoded and drawn again by the server as a JPEG of at
 * most 360 by 480 pixels, so nothing of the original file but its look is
 * kept: no metadata, no script, no second image. When a picture cannot be
 * made, there is none, and screens show the file type instead. A failure
 * here never fails an upload.
 */
final class Thumbnails
{
    public const int WIDTH = 360;

    public const int HEIGHT = 480;

    /** The types a picture is drawn from. */
    public const array IMAGES = ['image/jpeg', 'image/png', 'image/webp'];

    /** The largest image the server decodes, in bytes and in pixels. */
    private const int MAX_BYTES = 25 * 1024 * 1024;

    private const int MAX_PIXELS = 40_000_000;

    /** A picture the browser drew of a PDF's first page is small. */
    private const int MAX_PREVIEW_BYTES = 3 * 1024 * 1024;

    public function __construct(
        private readonly BlobStore $blobs,
        private readonly Clock $clock,
    ) {}

    /** From an image version itself. */
    public function fromImage(string $fileId): bool
    {
        if ($this->has($fileId)) {
            return true;
        }

        $file = DB::table('document_files')->where('id', $fileId)->first(['blob_sha256', 'mime_type', 'byte_size']);

        if ($file === null || ! in_array($file->mime_type, self::IMAGES, true) || ! is_numeric($file->byte_size) || (int) $file->byte_size > self::MAX_BYTES) {
            return false;
        }

        $path = tempnam(sys_get_temp_dir(), 'acadly-');

        if ($path === false) {
            return false;
        }

        try {
            $out = fopen($path, 'wb');

            if ($out === false) {
                return false;
            }

            foreach ($this->blobs->read(Row::text($file, 'blob_sha256')) as $piece) {
                fwrite($out, $piece);
            }

            fclose($out);

            return $this->keep($fileId, $path, 'image');
        } catch (Throwable) {
            return false;
        } finally {
            @unlink($path);
        }
    }

    /** From a picture of the first page, drawn by the browser from a PDF. */
    public function fromPreview(string $fileId, UploadedFile $preview): bool
    {
        $path = $preview->getRealPath();

        if ($path === false || $preview->getSize() > self::MAX_PREVIEW_BYTES || $this->has($fileId)) {
            return false;
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return in_array($mime, self::IMAGES, true) && $this->keep($fileId, $path, 'preview');
    }

    /** From an image on disk that the server already holds, such as the first photo of a scan. */
    public function fromPath(string $fileId, string $path): bool
    {
        return ! $this->has($fileId) && $this->keep($fileId, $path, 'image');
    }

    /** The blob of a version's picture, or null when it has none. */
    public function of(?string $fileId): ?string
    {
        if ($fileId === null) {
            return null;
        }

        $sha = DB::table('document_thumbnails')->where('file_id', $fileId)->value('blob_sha256');

        return is_string($sha) ? $sha : null;
    }

    /**
     * Which of these versions have a picture.
     *
     * @param  list<string>  $fileIds
     * @return array<string, true>
     */
    public function present(array $fileIds): array
    {
        $found = [];

        foreach ($fileIds === [] ? [] : DB::table('document_thumbnails')->whereIn('file_id', $fileIds)->pluck('file_id') as $id) {
            if (is_string($id)) {
                $found[$id] = true;
            }
        }

        return $found;
    }

    /**
     * Decodes an image and draws it again, upright, on white, at most 360 by 480.
     * Also used to turn scanned photos into the pages of one PDF.
     */
    public static function load(string $path, int $maxWidth, int $maxHeight): ?GdImage
    {
        $size = @getimagesize($path);

        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }

        $data = @file_get_contents($path);
        $image = $data === false ? false : @imagecreatefromstring($data);

        if (! $image instanceof GdImage) {
            return null;
        }

        $image = self::upright($image, $path);
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxWidth / $width, $maxHeight / $height);
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($w, $h);

        if (! $canvas instanceof GdImage) {
            return null;
        }

        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $w, $h, $width, $height);

        return $canvas;
    }

    private function keep(string $fileId, string $path, string $source): bool
    {
        $picture = self::load($path, self::WIDTH, self::HEIGHT);
        $out = tempnam(sys_get_temp_dir(), 'acadly-');

        if ($picture === null || $out === false) {
            return false;
        }

        try {
            if (! imagejpeg($picture, $out, 78)) {
                return false;
            }

            $sha = $this->blobs->put($out, 'image/jpeg');
            DB::table('document_thumbnails')->insertOrIgnore([
                'file_id' => $fileId,
                'blob_sha256' => $sha,
                'width' => imagesx($picture),
                'height' => imagesy($picture),
                'source' => $source,
                'created_at' => $this->clock->now(),
            ]);

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            @unlink($out);
        }
    }

    private function has(string $fileId): bool
    {
        return DB::table('document_thumbnails')->where('file_id', $fileId)->exists();
    }

    /** A phone photo says which way up it was taken; the pixels do not. */
    private static function upright(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $turn = match (is_array($exif) ? ($exif['Orientation'] ?? 1) : 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($turn === 0) {
            return $image;
        }

        $turned = imagerotate($image, $turn, 0);

        return $turned instanceof GdImage ? $turned : $image;
    }
}
