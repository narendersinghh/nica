<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Database;

/**
 * Safe image uploads: the real type is detected from the file contents, the
 * image is decoded and re-encoded as WebP (which drops metadata and anything
 * hidden in the original), and resized copies are generated for srcset.
 */
final class ImageUploader
{
    private const MAX_BYTES = 10 * 1024 * 1024;
    private const MAX_SIDE = 6000;
    private const MAX_STORED_SIDE = 2048;
    private const WIDTHS = ['medium' => 300, 'medium_large' => 768, 'large' => 1024, 'woocommerce_single' => 600, '1536x1536' => 1536];
    private const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(private Database $db, private string $uploadsDir)
    {
    }

    /**
     * @param array $file one entry of $_FILES
     * @return int media id
     * @throws \InvalidArgumentException with a user-facing message
     */
    public function store(array $file, ?int $userId, string $alt = ''): int
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new \RuntimeException('The GD extension is required for image uploads.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name']) && PHP_SAPI !== 'cli') {
            throw new \InvalidArgumentException('The upload failed. Please try again.');
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Images must be smaller than 10 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if (!in_array($mime, self::TYPES, true)) {
            throw new \InvalidArgumentException('Please upload a JPG, PNG, WebP or GIF image.');
        }
        $info = @getimagesize((string) $file['tmp_name']);
        if (!$info || $info[0] < 50 || $info[1] < 50 || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
            throw new \InvalidArgumentException('Images must be between 50 and 6000 pixels on each side.');
        }
        $image = @imagecreatefromstring((string) file_get_contents((string) $file['tmp_name']));
        if ($image === false) {
            throw new \InvalidArgumentException('This image could not be read.');
        }
        $image = $this->orient($image, (string) $file['tmp_name'], $mime);
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(pathinfo((string) $file['name'], PATHINFO_FILENAME))), '-') ?: 'image';
        $dir = gmdate('Y/m');
        $name = substr($base, 0, 50) . '-' . bin2hex(random_bytes(4));
        if (!is_dir($this->uploadsDir . '/' . $dir) && !mkdir($this->uploadsDir . '/' . $dir, 0775, true) && !is_dir($this->uploadsDir . '/' . $dir)) {
            throw new \RuntimeException('Upload folder is not writable.');
        }

        // Original, capped to a sensible size.
        $main = $this->resize($image, self::MAX_STORED_SIDE);
        $path = $dir . '/' . $name . '.webp';
        $this->save($main, $path);
        $width = imagesx($main);
        $height = imagesy($main);

        $sizes = [];
        foreach (self::WIDTHS as $label => $w) {
            if ($w < $width) {
                $copy = $this->resize($main, $w, true);
                $sizePath = $dir . '/' . $name . '-' . imagesx($copy) . 'x' . imagesy($copy) . '.webp';
                $this->save($copy, $sizePath);
                $sizes[$label] = ['path' => $sizePath, 'width' => imagesx($copy), 'height' => imagesy($copy)];
            }
        }
        // Square thumbnail (admin lists, cart).
        $thumb = $this->crop($main, 150);
        $thumbPath = $dir . '/' . $name . '-150x150.webp';
        $this->save($thumb, $thumbPath);
        $sizes['thumbnail'] = ['path' => $thumbPath, 'width' => 150, 'height' => 150];

        return $this->db->insert('media', [
            'path' => $path,
            'mime' => 'image/webp',
            'width' => $width,
            'height' => $height,
            'filesize' => (int) filesize($this->uploadsDir . '/' . $path),
            'alt' => mb_substr_safe(trim($alt), 255),
            'title' => mb_substr_safe($base, 255),
            'sizes' => json_encode($sizes, JSON_UNESCAPED_SLASHES),
            'uploaded_by' => $userId,
        ]);
    }

    private function resize(\GdImage $src, int $max, bool $byWidth = false): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = $byWidth ? $max / $w : $max / max($w, $h);
        if ($scale >= 1) {
            return $src;
        }
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $dst;
    }

    private function crop(\GdImage $src, int $size): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $dst = imagecreatetruecolor($size, $size);
        imagecopyresampled($dst, $src, 0, 0, (int) (($w - $side) / 2), (int) (($h - $side) / 2), $size, $size, $side, $side);
        return $dst;
    }

    private function save(\GdImage $img, string $relative): void
    {
        if (!imagewebp($img, $this->uploadsDir . '/' . $relative, 82)) {
            throw new \RuntimeException('Could not save the image.');
        }
    }

    /** Respect the camera's EXIF orientation before the metadata is dropped. */
    private function orient(\GdImage $img, string $file, string $mime): \GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($file);
        return match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
    }
}
