<?php

declare(strict_types=1);

namespace Acadly\Kernel\Documents;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Turns the photos of a paper document, one per page, into one PDF (D-090).
 * Each photo is decoded and drawn again as a JPEG, so the PDF holds only
 * pictures: no script, no form, no metadata from the camera.
 */
final class ImagesToPdf
{
    /** The longest side of a page picture, in pixels: enough to read a printed page. */
    private const int LONG_SIDE = 2200;

    /** The width of every page, in points (A4 is 595). */
    private const float PAGE_WIDTH = 595.0;

    /**
     * @param  list<string>  $paths  the photos, in page order
     * @return string the path of the PDF, a temporary file the caller removes
     */
    public static function make(array $paths): string
    {
        $objects = [];
        $kids = [];
        // 1: catalogue, 2: pages; each page takes three objects from 3 on.
        $next = 3;

        foreach ($paths as $path) {
            $image = Thumbnails::load($path, self::LONG_SIDE, self::LONG_SIDE);

            if ($image === null) {
                throw ValidationException::withMessages(['files' => ['A photo cannot be read. Take it again.']]);
            }

            ob_start();
            imagejpeg($image, null, 82);
            $jpeg = (string) ob_get_clean();
            $w = imagesx($image);
            $h = imagesy($image);
            $pageW = self::PAGE_WIDTH;
            $pageH = round(self::PAGE_WIDTH * $h / $w, 2);
            [$page, $content, $picture] = [$next, $next + 1, $next + 2];
            $next += 3;
            $kids[] = "{$page} 0 R";
            $draw = sprintf('q %.2F 0 0 %.2F 0 0 cm /P Do Q', $pageW, $pageH);

            $objects[$page] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /XObject << /P %d 0 R >> >> /Contents %d 0 R >>', $pageW, $pageH, $picture, $content);
            $objects[$content] = '<< /Length '.strlen($draw)." >>\nstream\n{$draw}\nendstream";
            $objects[$picture] = "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($jpeg)." >>\nstream\n{$jpeg}\nendstream";
        }

        if ($kids === []) {
            throw ValidationException::withMessages(['files' => ['Add at least one page.']]);
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".($next)."\n0000000000 65535 f \n";

        for ($i = 1; $i < $next; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size {$next} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
        $out = tempnam(sys_get_temp_dir(), 'acadly-');

        if ($out === false || file_put_contents($out, $pdf) === false) {
            throw new RuntimeException('The scan cannot be written.');
        }

        return $out;
    }
}
