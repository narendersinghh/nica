<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Core\Database;

/**
 * Responsive <img> tags from the media library: width/height always set
 * (no layout shift), srcset from every stored size with the same aspect ratio.
 */
final class Images
{
    /** @var array<string,?array> */
    private array $byPath = [];
    /** @var array<int,?array> */
    private array $byId = [];

    public function __construct(private Database $db)
    {
    }

    public function find(string|int|null $ref): ?array
    {
        if ($ref === null || $ref === '' || $ref === 0) {
            return null;
        }
        if (is_int($ref) || ctype_digit((string) $ref)) {
            $id = (int) $ref;
            if (!array_key_exists($id, $this->byId)) {
                $this->remember($this->db->first('SELECT * FROM media WHERE id = ?', [$id]), $id, null);
            }
            return $this->byId[$id];
        }
        $path = ltrim((string) $ref, '/');
        if (str_starts_with($path, 'uploads/')) {
            $path = substr($path, 8);
        }
        if (!array_key_exists($path, $this->byPath)) {
            $this->remember($this->db->first('SELECT * FROM media WHERE path = ?', [$path]), null, $path);
        }
        return $this->byPath[$path];
    }

    /** Preload many media rows at once (avoids N queries in grids). */
    public function prime(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0 && !array_key_exists($id, $this->byId))));
        if ($ids === []) {
            return;
        }
        $rows = $this->db->select('SELECT * FROM media WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        foreach ($rows as $row) {
            $this->remember($row, (int) $row['id'], null);
        }
        foreach ($ids as $id) {
            $this->byId[$id] ??= null;
        }
    }

    /** URL of the original, or of a named size ("medium_large", "large", ...). */
    public function url(string|int|null $ref, ?string $size = null): string
    {
        $media = $this->find($ref);
        if ($media === null) {
            return is_string($ref) && $ref !== '' && !ctype_digit($ref) ? upload_url($ref) : '';
        }
        $sizes = $media['_sizes'];
        return upload_url($size !== null && isset($sizes[$size]) ? $sizes[$size]['path'] : $media['path']);
    }

    /**
     * @param array{sizes?:string,class?:string,loading?:string,fetchpriority?:string,max?:int,alt?:string} $opts
     *   max: largest width to offer in srcset (keeps big originals off small slots)
     */
    public function tag(string|int|null $ref, array $opts = []): string
    {
        $media = $this->find($ref);
        $alt = $opts['alt'] ?? ($media['alt'] ?? '');
        if ($media === null) {
            if (!is_string($ref) || $ref === '' || ctype_digit($ref)) {
                return '';
            }
            return '<img src="' . e(upload_url($ref)) . '" alt="' . e($alt) . '" loading="lazy" decoding="async"'
                . (isset($opts['class']) ? ' class="' . e($opts['class']) . '"' : '') . '>';
        }

        $width = (int) $media['width'];
        $height = (int) $media['height'];
        $max = (int) ($opts['max'] ?? 0);
        $candidates = [];
        if ($width > 0) {
            $candidates[$width] = $media['path'];
        }
        foreach ($media['_sizes'] as $size) {
            $w = (int) $size['width'];
            $h = (int) $size['height'];
            // Same aspect ratio only (skip square crops etc.), 2% tolerance.
            if ($w > 0 && $h > 0 && $width > 0 && $height > 0 && abs(($w / $h) - ($width / $height)) < 0.02 * ($width / $height)) {
                $candidates[$w] = $size['path'];
            }
        }
        ksort($candidates);
        if ($max > 0) {
            $limited = array_filter($candidates, static fn ($w) => $w <= $max * 2, ARRAY_FILTER_USE_KEY);
            $candidates = $limited ?: [array_key_first($candidates) => reset($candidates)];
        }

        // Default src: the smallest candidate at least $max wide, else the largest.
        $src = end($candidates) ?: $media['path'];
        $srcWidth = (int) array_key_last($candidates);
        if ($max > 0) {
            foreach ($candidates as $w => $path) {
                if ($w >= $max) {
                    $src = $path;
                    $srcWidth = $w;
                    break;
                }
            }
        }
        $srcHeight = ($width > 0 && $srcWidth > 0) ? (int) round($height * $srcWidth / $width) : $height;

        $attrs = [
            'src' => upload_url($src),
            'alt' => $alt,
            'width' => $srcWidth ?: null,
            'height' => $srcHeight ?: null,
            'loading' => $opts['loading'] ?? 'lazy',
            'decoding' => 'async',
            'class' => $opts['class'] ?? null,
            'fetchpriority' => $opts['fetchpriority'] ?? null,
        ];
        if (count($candidates) > 1) {
            $attrs['srcset'] = implode(', ', array_map(static fn ($w, $p) => upload_url($p) . " {$w}w", array_keys($candidates), $candidates));
            $attrs['sizes'] = $opts['sizes'] ?? '100vw';
        }

        $html = '<img';
        foreach ($attrs as $name => $value) {
            if ($value !== null && $value !== '') {
                $html .= ' ' . $name . '="' . e((string) $value) . '"';
            } elseif ($name === 'alt') {
                $html .= ' alt=""';
            }
        }
        return $html . '>';
    }

    private function remember(?array $row, ?int $id, ?string $path): void
    {
        if ($row !== null) {
            $row['_sizes'] = json_decode((string) ($row['sizes'] ?? ''), true) ?: [];
            $this->byId[(int) $row['id']] = $row;
            $this->byPath[$row['path']] = $row;
            return;
        }
        if ($id !== null) {
            $this->byId[$id] = null;
        }
        if ($path !== null) {
            $this->byPath[$path] = null;
        }
    }
}
