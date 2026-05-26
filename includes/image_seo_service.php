<?php

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class ImageSeoService {
    public static function defaultAltText(string $name): string {
        $name = trim(pathinfo($name, PATHINFO_FILENAME));
        $name = preg_replace('/[_-]+/u', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        return trim($name) !== '' ? trim($name) : 'Article image';
    }

    public static function seoFilename(string $originalName, string $fallbackFilename = ''): string {
        $extension = strtolower(pathinfo($fallbackFilename !== '' ? $fallbackFilename : $originalName, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{2,5}$/', $extension) ? $extension : 'jpg';
        $base = self::defaultAltText($originalName);

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
            if ($converted !== false && trim($converted) !== '') {
                $base = $converted;
            }
        }

        $slug = strtolower($base);
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'image-' . substr(md5($originalName . $fallbackFilename), 0, 8);
        }

        return substr($slug, 0, 80) . '.' . $extension;
    }

    public static function coverage(PDO $db, int $siteId): array {
        if ($siteId <= 0 || !geoflow_db_table_exists($db, 'images')) {
            return ['total' => 0, 'with_alt' => 0, 'with_caption' => 0, 'coverage' => 0.0];
        }

        $scope = geoflow_table_has_site_column($db, 'image_libraries') ? ' AND il.site_id = ?' : '';
        $stmt = $db->prepare("
            SELECT
                COUNT(i.id) AS total,
                SUM(CASE WHEN COALESCE(NULLIF(i.alt_text, ''), NULLIF(i.original_name, '')) IS NOT NULL THEN 1 ELSE 0 END) AS with_alt,
                SUM(CASE WHEN COALESCE(i.caption, '') <> '' THEN 1 ELSE 0 END) AS with_caption
            FROM images i
            INNER JOIN image_libraries il ON il.id = i.library_id
            WHERE 1=1
            {$scope}
        ");
        $scope !== '' ? $stmt->execute([$siteId]) : $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total'] ?? 0);
        $withAlt = (int) ($row['with_alt'] ?? 0);

        return [
            'total' => $total,
            'with_alt' => $withAlt,
            'with_caption' => (int) ($row['with_caption'] ?? 0),
            'coverage' => $total > 0 ? round(($withAlt / $total) * 100, 1) : 0.0,
        ];
    }
}

