<?php
/**
 * Site template cloning for multi-site operations.
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class SiteTemplateService {
    public static function cloneSite(PDO $db, int $sourceSiteId, array $target): array {
        if ($sourceSiteId <= 0) {
            throw new InvalidArgumentException('请选择要复制的来源站点');
        }

        $source = geoflow_find_site_by_id($db, $sourceSiteId);
        if (!$source) {
            throw new RuntimeException('来源站点不存在');
        }

        $name = trim((string) ($target['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('请输入新站点名称');
        }

        $slug = self::uniqueSiteSlug($db, $target['slug'] ?? $name);
        $siteTitle = trim((string) ($target['site_title'] ?? '')) ?: $name;
        $description = trim((string) ($target['description'] ?? ($source['description'] ?? '')));
        $primaryDomain = geoflow_normalize_domain_input((string) ($target['primary_domain'] ?? ''));
        $aliasDomains = self::parseAliasDomains((string) ($target['alias_domains'] ?? ''));
        $domains = array_values(array_unique(array_filter(array_merge($primaryDomain !== '' ? [$primaryDomain] : [], $aliasDomains))));

        self::assertDomainsAvailable($db, $domains);

        $db->beginTransaction();
        try {
            $siteStmt = $db->prepare("
                INSERT INTO sites (name, slug, description, status, is_default, primary_domain, site_title, created_at, updated_at)
                VALUES (?, ?, ?, 'active', 0, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                RETURNING id
            ");
            $siteStmt->execute([$name, $slug, $description, $primaryDomain, $siteTitle]);
            $targetSiteId = (int) $siteStmt->fetchColumn();
            if ($targetSiteId <= 0) {
                throw new RuntimeException('新站点创建失败');
            }

            self::syncDomains($db, $targetSiteId, $primaryDomain, $domains);
            $counts = [
                'site_settings' => self::cloneSiteSettings($db, $sourceSiteId, $targetSiteId, $name, $siteTitle, $description),
                'categories' => count(self::cloneRows($db, 'categories', $sourceSiteId, $targetSiteId, ['name', 'slug', 'description', 'sort_order'])),
                'authors' => count(self::cloneRows($db, 'authors', $sourceSiteId, $targetSiteId, ['name', 'bio', 'email', 'avatar', 'website', 'social_links'])),
                'knowledge_bases' => count(self::cloneRows($db, 'knowledge_bases', $sourceSiteId, $targetSiteId, ['name', 'description', 'content', 'file_type', 'file_path', 'word_count', 'character_count', 'used_task_count'])),
            ];

            $keywordLibraryMap = self::cloneRows($db, 'keyword_libraries', $sourceSiteId, $targetSiteId, ['name', 'description', 'keyword_count']);
            $counts['keyword_libraries'] = count($keywordLibraryMap);
            $counts['keywords'] = self::cloneKeywords($db, $keywordLibraryMap);

            $titleLibraryMap = self::cloneTitleLibraries($db, $sourceSiteId, $targetSiteId, $keywordLibraryMap);
            $counts['title_libraries'] = count($titleLibraryMap);
            $counts['titles'] = self::cloneTitles($db, $titleLibraryMap);

            $db->commit();

            return [
                'site_id' => $targetSiteId,
                'slug' => $slug,
                'counts' => $counts,
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function uniqueSiteSlug(PDO $db, string $source): string {
        $base = geoflow_slugify_site_name($source);
        $slug = $base;
        $counter = 2;

        while (true) {
            $stmt = $db->prepare('SELECT 1 FROM sites WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
            if (!$stmt->fetchColumn()) {
                return $slug;
            }
            $slug = $base . '-' . $counter++;
        }
    }

    private static function parseAliasDomains(string $input): array {
        $domains = [];
        foreach (preg_split('/[\s,]+/', $input, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $domain = geoflow_normalize_domain_input((string) $part);
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }
        return $domains;
    }

    private static function assertDomainsAvailable(PDO $db, array $domains): void {
        if (empty($domains)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($domains), '?'));
        $stmt = $db->prepare("SELECT domain FROM site_domains WHERE domain IN ($placeholders) LIMIT 1");
        $stmt->execute($domains);
        $conflict = (string) ($stmt->fetchColumn() ?: '');
        if ($conflict !== '') {
            throw new RuntimeException('域名已被其他站点使用：' . $conflict);
        }
    }

    private static function syncDomains(PDO $db, int $siteId, string $primaryDomain, array $domains): void {
        if (empty($domains)) {
            return;
        }

        $stmt = $db->prepare("
            INSERT INTO site_domains (site_id, domain, is_primary, created_at, updated_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        foreach ($domains as $domain) {
            $stmt->execute([$siteId, $domain, $domain === $primaryDomain ? 1 : 0]);
        }
    }

    private static function cloneSiteSettings(PDO $db, int $sourceSiteId, int $targetSiteId, string $name, string $siteTitle, string $description): int {
        if (!geoflow_db_table_exists($db, 'site_settings') || !geoflow_table_has_site_column($db, 'site_settings')) {
            return 0;
        }

        $stmt = $db->prepare("SELECT setting_key, setting_value FROM site_settings WHERE site_id = ?");
        $stmt->execute([$sourceSiteId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $insert = $db->prepare("
            INSERT INTO site_settings (site_id, setting_key, setting_value, created_at, updated_at)
            VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ON CONFLICT (site_id, setting_key)
            DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP
        ");
        $count = 0;

        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            $value = (string) ($row['setting_value'] ?? '');
            if ($key === 'site_name' || $key === 'site_title') {
                $value = $siteTitle;
            } elseif ($key === 'site_description') {
                $value = $description;
            }

            $insert->execute([$targetSiteId, $key, $value]);
            $count++;
        }

        foreach (['site_name' => $name, 'site_title' => $siteTitle, 'site_description' => $description] as $key => $value) {
            $insert->execute([$targetSiteId, $key, $value]);
        }

        return $count;
    }

    private static function cloneRows(PDO $db, string $table, int $sourceSiteId, int $targetSiteId, array $candidateColumns): array {
        if (!geoflow_db_table_exists($db, $table) || !geoflow_table_has_site_column($db, $table)) {
            return [];
        }

        $columns = array_values(array_filter($candidateColumns, static fn(string $column): bool => db_column_exists($db, $table, $column)));
        if (empty($columns)) {
            return [];
        }

        $select = $db->prepare('SELECT id, ' . implode(', ', $columns) . " FROM {$table} WHERE site_id = ? ORDER BY id ASC");
        $select->execute([$sourceSiteId]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        $map = [];

        $timestampColumns = [];
        $timestampValues = [];
        if (db_column_exists($db, $table, 'created_at')) {
            $timestampColumns[] = 'created_at';
            $timestampValues[] = 'CURRENT_TIMESTAMP';
        }
        if (db_column_exists($db, $table, 'updated_at')) {
            $timestampColumns[] = 'updated_at';
            $timestampValues[] = 'CURRENT_TIMESTAMP';
        }

        $columnSql = implode(', ', array_merge(['site_id'], $columns, $timestampColumns));
        $placeholders = implode(', ', array_merge(['?'], array_fill(0, count($columns), '?'), $timestampValues));
        $insert = $db->prepare("INSERT INTO {$table} ({$columnSql}) VALUES ({$placeholders}) RETURNING id");

        foreach ($rows as $row) {
            $values = [$targetSiteId];
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? null;
            }
            $insert->execute($values);
            $map[(int) $row['id']] = (int) $insert->fetchColumn();
        }

        return $map;
    }

    private static function cloneKeywords(PDO $db, array $keywordLibraryMap): int {
        if (empty($keywordLibraryMap) || !geoflow_db_table_exists($db, 'keywords')) {
            return 0;
        }

        $columns = array_values(array_filter([
            'keyword', 'used_count', 'source', 'seed_keyword', 'location_code', 'language_code',
            'search_volume', 'cpc', 'competition', 'competition_index', 'monthly_searches_json', 'metrics_updated_at'
        ], static fn(string $column): bool => db_column_exists($db, 'keywords', $column)));

        $select = $db->prepare('SELECT library_id, ' . implode(', ', $columns) . ' FROM keywords WHERE library_id = ? ORDER BY id ASC');
        $insert = $db->prepare(
            'INSERT INTO keywords (library_id, ' . implode(', ', $columns) . ', created_at) VALUES (?, ' . implode(', ', array_fill(0, count($columns), '?')) . ', CURRENT_TIMESTAMP)'
        );
        $count = 0;

        foreach ($keywordLibraryMap as $oldLibraryId => $newLibraryId) {
            $select->execute([(int) $oldLibraryId]);
            foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $values = [(int) $newLibraryId];
                foreach ($columns as $column) {
                    $values[] = $row[$column] ?? null;
                }
                $insert->execute($values);
                $count++;
            }
        }

        return $count;
    }

    private static function cloneTitleLibraries(PDO $db, int $sourceSiteId, int $targetSiteId, array $keywordLibraryMap): array {
        if (!geoflow_db_table_exists($db, 'title_libraries') || !geoflow_table_has_site_column($db, 'title_libraries')) {
            return [];
        }

        $columns = array_values(array_filter(['name', 'description', 'title_count', 'generation_type', 'keyword_library_id', 'ai_model_id', 'prompt_id', 'generation_rounds'], static fn(string $column): bool => db_column_exists($db, 'title_libraries', $column)));
        $select = $db->prepare('SELECT id, ' . implode(', ', $columns) . ' FROM title_libraries WHERE site_id = ? ORDER BY id ASC');
        $select->execute([$sourceSiteId]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        $map = [];

        $insert = $db->prepare(
            'INSERT INTO title_libraries (site_id, ' . implode(', ', $columns) . ', created_at, updated_at) VALUES (?, ' . implode(', ', array_fill(0, count($columns), '?')) . ', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) RETURNING id'
        );

        foreach ($rows as $row) {
            $values = [$targetSiteId];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if ($column === 'keyword_library_id' && $value !== null) {
                    $value = $keywordLibraryMap[(int) $value] ?? null;
                }
                $values[] = $value;
            }
            $insert->execute($values);
            $map[(int) $row['id']] = (int) $insert->fetchColumn();
        }

        return $map;
    }

    private static function cloneTitles(PDO $db, array $titleLibraryMap): int {
        if (empty($titleLibraryMap) || !geoflow_db_table_exists($db, 'titles')) {
            return 0;
        }

        $columns = array_values(array_filter(['title', 'keyword', 'used_count'], static fn(string $column): bool => db_column_exists($db, 'titles', $column)));
        $select = $db->prepare('SELECT library_id, ' . implode(', ', $columns) . ' FROM titles WHERE library_id = ? ORDER BY id ASC');
        $insert = $db->prepare('INSERT INTO titles (library_id, ' . implode(', ', $columns) . ', created_at) VALUES (?, ' . implode(', ', array_fill(0, count($columns), '?')) . ', CURRENT_TIMESTAMP)');
        $count = 0;

        foreach ($titleLibraryMap as $oldLibraryId => $newLibraryId) {
            $select->execute([(int) $oldLibraryId]);
            foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $values = [(int) $newLibraryId];
                foreach ($columns as $column) {
                    $values[] = $row[$column] ?? null;
                }
                $insert->execute($values);
                $count++;
            }
        }

        return $count;
    }
}
