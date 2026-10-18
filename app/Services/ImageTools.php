<?php

namespace App\Services;

class ImageTools
{
    /** Make a JPEG thumbnail with GD, honouring the phone camera's EXIF rotation. Returns false for formats GD cannot read (e.g. HEIC). */
    public static function thumbnail(string $src, string $dest, int $max): bool
    {
        if (! function_exists('imagecreatefromstring')) {
            return false;
        }
        $data = @file_get_contents($src);
        $img = $data ? @imagecreatefromstring($data) : false;
        if (! $img) {
            return false;
        }
        if (function_exists('exif_read_data') && ($exif = @exif_read_data($src)) && ! empty($exif['Orientation'])) {
            $img = match ((int) $exif['Orientation']) {
                3 => imagerotate($img, 180, 0),
                6 => imagerotate($img, -90, 0),
                8 => imagerotate($img, 90, 0),
                default => $img,
            };
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        @mkdir(dirname($dest), 0775, true);
        $ok = imagejpeg($out, $dest, 82);
        imagedestroy($img);
        imagedestroy($out);

        return $ok;
    }

    /** Width of the small copy used in product cards and rails (about 10× lighter than the full photo). */
    public const THUMB = 480;

    public static function webpPath(string $path): string
    {
        return preg_replace('/\.\w+$/', '.webp', $path);
    }

    public static function thumbPath(string $path): string
    {
        return preg_replace('/\.\w+$/', '-'.self::THUMB.'.webp', $path);
    }

    /**
     * WebP copies of a catalogue photo next to the original: full size (max 1600 px) and a small one for cards.
     * Skips copies that already exist. Returns how many were made.
     */
    public static function variants(string $absPath): int
    {
        if (! is_file($absPath) || ! preg_match('/\.(jpe?g|png|webp)$/i', $absPath) || preg_match('/-'.self::THUMB.'\.webp$/', $absPath)) {
            return 0;
        }
        $made = 0;
        $webp = self::webpPath($absPath);
        if ($webp !== $absPath && ! is_file($webp)) {
            $made += (int) self::toWebp($absPath, $webp);
        }
        $thumb = self::thumbPath($absPath);
        if (! is_file($thumb)) {
            $made += (int) self::toWebp($absPath, $thumb, self::THUMB);
        }

        return $made;
    }

    /** Convert an image to WebP next to the original (for imported catalogue photos). */
    public static function toWebp(string $src, string $dest, int $max = 1600): bool
    {
        if (! function_exists('imagewebp')) {
            return false;
        }
        $img = @imagecreatefromstring((string) @file_get_contents($src));
        if (! $img) {
            return false;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        if (max($w, $h) > $max) {
            $scale = $max / max($w, $h);
            $res = imagescale($img, (int) round($w * $scale), (int) round($h * $scale));
            imagedestroy($img);
            $img = $res;
        }
        imagepalettetotruecolor($img);
        imagealphablending($img, true);
        imagesavealpha($img, true);
        $ok = imagewebp($img, $dest, 82);
        imagedestroy($img);

        return $ok;
    }
}
