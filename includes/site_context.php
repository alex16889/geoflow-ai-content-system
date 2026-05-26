<?php

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

if (!function_exists('geoflow_db_table_exists')) {
    function geoflow_db_table_exists(PDO $pdo, string $table): bool {
        static $cache = [];

        $key = spl_object_hash($pdo) . ':' . $table;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.tables
            WHERE table_schema = current_schema()
              AND table_name = ?
            LIMIT 1
        ");
        $stmt->execute([$table]);
        return $cache[$key] = (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('geoflow_db_constraint_exists')) {
    function geoflow_db_constraint_exists(PDO $pdo, string $constraintName): bool {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM pg_constraint
            WHERE conname = ?
            LIMIT 1
        ");
        $stmt->execute([$constraintName]);
        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('geoflow_normalize_host')) {
    function geoflow_normalize_host(string $host): string {
        $host = trim(strtolower($host));
        if ($host === '') {
            return '';
        }

        if (str_starts_with($host, '[') && str_contains($host, ']')) {
            $end = strpos($host, ']');
            if ($end !== false) {
                return substr($host, 1, $end - 1);
            }
        }

        if (substr_count($host, ':') === 1) {
            [$host] = explode(':', $host, 2);
        }

        return trim($host, " \t\n\r\0\x0B.");
    }
}

if (!function_exists('geoflow_slugify_site_name')) {
    function geoflow_slugify_site_name(string $name): string {
        $source = trim($name);

        if (function_exists('iconv')) {
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $source);
            if ($transliterated !== false && trim($transliterated) !== '') {
                $source = $transliterated;
            }
        }

        $slug = strtolower($source);
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'site-' . substr(md5($name), 0, 8);
    }
}

if (!function_exists('geoflow_extract_url_host')) {
    function geoflow_extract_url_host(string $url): string {
        $host = (string) parse_url(trim($url), PHP_URL_HOST);
        return geoflow_normalize_host($host);
    }
}

if (!function_exists('geoflow_request_scheme')) {
    function geoflow_request_scheme(): string {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return 'https';
        }

        $scheme = strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? ''));
        if ($scheme === 'https') {
            return 'https';
        }

        $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwardedProto === 'https') {
            return 'https';
        }

        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return 'https';
        }

        return 'http';
    }
}

if (!function_exists('geoflow_request_host')) {
    function geoflow_request_host(): string {
        return geoflow_normalize_host((string) ($_SERVER['HTTP_HOST'] ?? ''));
    }
}

if (!function_exists('geoflow_table_has_site_column')) {
    function geoflow_table_has_site_column(PDO $pdo, string $table): bool {
        static $cache = [];

        $key = spl_object_hash($pdo) . ':' . $table;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        return $cache[$key] = geoflow_db_table_exists($pdo, $table) && db_column_exists($pdo, $table, 'site_id');
    }
}

if (!function_exists('geoflow_site_scoped_tables')) {
    function geoflow_site_scoped_tables(): array {
        return [
            'site_settings',
            'categories',
            'authors',
            'articles',
            'tasks',
            'keyword_libraries',
            'title_libraries',
            'image_libraries',
            'knowledge_bases',
            'url_import_jobs',
            'search_performance_snapshots',
            'ai_visibility_checks',
            'competitor_briefs',
            'redirect_rules',
            'not_found_logs',
        ];
    }
}

if (!function_exists('geoflow_fetch_default_site_record')) {
    function geoflow_fetch_default_site_record(PDO $pdo): ?array {
        if (!geoflow_db_table_exists($pdo, 'sites')) {
            return null;
        }

        $stmt = $pdo->query("
            SELECT *
            FROM sites
            WHERE status = 'active'
            ORDER BY is_default DESC, id ASC
            LIMIT 1
        ");

        $site = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        return $site ?: null;
    }
}

if (!function_exists('geoflow_fetch_site_by_host')) {
    function geoflow_fetch_site_by_host(PDO $pdo, string $host): ?array {
        $host = geoflow_normalize_host($host);
        if ($host === '' || !geoflow_db_table_exists($pdo, 'site_domains')) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT s.*, d.domain AS matched_domain
            FROM site_domains d
            INNER JOIN sites s ON s.id = d.site_id
            WHERE s.status = 'active'
              AND LOWER(d.domain) = ?
            ORDER BY d.is_primary DESC, s.is_default DESC, s.id ASC
            LIMIT 1
        ");
        $stmt->execute([$host]);
        $site = $stmt->fetch(PDO::FETCH_ASSOC);

        return $site ?: null;
    }
}

if (!function_exists('geoflow_current_site')) {
    function geoflow_current_site(): array {
        global $db;

        $fallbackName = defined('SITE_NAME') ? SITE_NAME : 'GEOflow';
        $fallbackDescription = defined('SITE_DESCRIPTION') ? SITE_DESCRIPTION : '';
        $fallbackHost = geoflow_request_host();
        $fallbackUrl = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';

        $fallback = [
            'id' => 1,
            'name' => $fallbackName,
            'slug' => geoflow_slugify_site_name($fallbackName),
            'description' => $fallbackDescription,
            'status' => 'active',
            'is_default' => 1,
            'primary_domain' => $fallbackHost !== '' ? $fallbackHost : geoflow_extract_url_host($fallbackUrl),
            'site_title' => $fallbackName,
            'resolved_by' => 'fallback',
        ];

        if (!($db instanceof PDO) || !geoflow_db_table_exists($db, 'sites')) {
            return $fallback;
        }

        static $cache = [];
        $host = geoflow_request_host();
        $runtimeSiteId = geoflow_runtime_site_id();
        $selectedSiteId = geoflow_admin_selected_site_id();
        $cacheKey = spl_object_hash($db) . ':' . $host . ':runtime=' . $runtimeSiteId . ':admin=' . $selectedSiteId;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        if ($runtimeSiteId > 0) {
            $site = geoflow_find_site_by_id($db, $runtimeSiteId);
            if ($site && ($site['status'] ?? 'active') === 'active') {
                $site['resolved_by'] = 'runtime';
                return $cache[$cacheKey] = $site;
            }
        }

        if ($selectedSiteId > 0) {
            $site = geoflow_find_site_by_id($db, $selectedSiteId);
            if ($site && ($site['status'] ?? 'active') === 'active') {
                $site['resolved_by'] = 'admin-selection';
                return $cache[$cacheKey] = $site;
            }
        }

        $site = geoflow_fetch_site_by_host($db, $host);
        if ($site) {
            $site['resolved_by'] = 'domain';
            return $cache[$cacheKey] = $site;
        }

        $site = geoflow_fetch_default_site_record($db);
        if ($site) {
            $site['resolved_by'] = 'default';
            return $cache[$cacheKey] = $site;
        }

        return $cache[$cacheKey] = $fallback;
    }
}

if (!function_exists('geoflow_runtime_site_id')) {
    function geoflow_runtime_site_id(): int {
        return (int) ($GLOBALS['geoflow_runtime_site_id'] ?? 0);
    }
}

if (!function_exists('geoflow_set_runtime_site_id')) {
    function geoflow_set_runtime_site_id(int $siteId): void {
        if ($siteId > 0) {
            $GLOBALS['geoflow_runtime_site_id'] = $siteId;
            return;
        }

        unset($GLOBALS['geoflow_runtime_site_id']);
    }
}

if (!function_exists('geoflow_clear_runtime_site_id')) {
    function geoflow_clear_runtime_site_id(): void {
        unset($GLOBALS['geoflow_runtime_site_id']);
    }
}

if (!function_exists('geoflow_find_site_by_id')) {
    function geoflow_find_site_by_id(PDO $pdo, int $siteId): ?array {
        if ($siteId <= 0 || !geoflow_db_table_exists($pdo, 'sites')) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = ? LIMIT 1");
        $stmt->execute([$siteId]);
        $site = $stmt->fetch(PDO::FETCH_ASSOC);
        return $site ?: null;
    }
}

if (!function_exists('geoflow_admin_selected_site_session_key')) {
    function geoflow_admin_selected_site_session_key(): string {
        return 'geoflow_admin_selected_site_id';
    }
}

if (!function_exists('geoflow_admin_selected_site_id')) {
    function geoflow_admin_selected_site_id(): int {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return 0;
        }

        return (int) ($_SESSION[geoflow_admin_selected_site_session_key()] ?? 0);
    }
}

if (!function_exists('geoflow_set_admin_selected_site_id')) {
    function geoflow_set_admin_selected_site_id(int $siteId): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION[geoflow_admin_selected_site_session_key()] = $siteId;
    }
}

if (!function_exists('geoflow_clear_admin_selected_site_id')) {
    function geoflow_clear_admin_selected_site_id(): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        unset($_SESSION[geoflow_admin_selected_site_session_key()]);
    }
}

if (!function_exists('geoflow_list_sites')) {
    function geoflow_list_sites(PDO $pdo, bool $includeInactive = true): array {
        if (!geoflow_db_table_exists($pdo, 'sites')) {
            return [];
        }

        $sql = "
            SELECT s.*,
                   (
                       SELECT string_agg(d.domain, ', ' ORDER BY d.is_primary DESC, d.id ASC)
                       FROM site_domains d
                       WHERE d.site_id = s.id
                   ) AS domains
            FROM sites s
        ";

        if (!$includeInactive) {
            $sql .= " WHERE s.status = 'active'";
        }

        $sql .= " ORDER BY s.is_default DESC, s.name ASC, s.id ASC";
        $stmt = $pdo->query($sql);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}

if (!function_exists('geoflow_is_safe_identifier')) {
    function geoflow_is_safe_identifier(string $identifier): bool {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier);
    }
}

if (!function_exists('geoflow_current_site_id')) {
    function geoflow_current_site_id(): int {
        return (int) (geoflow_current_site()['id'] ?? 0);
    }
}

if (!function_exists('geoflow_current_site_base_url')) {
    function geoflow_current_site_base_url(): string {
        $site = geoflow_current_site();
        $host = geoflow_request_host();
        $scheme = geoflow_request_scheme();

        if ($host !== '') {
            return $scheme . '://' . $host;
        }

        $primaryDomain = geoflow_normalize_host((string) ($site['primary_domain'] ?? ''));
        if ($primaryDomain !== '') {
            return $scheme . '://' . $primaryDomain;
        }

        if (defined('SITE_URL') && SITE_URL) {
            return rtrim((string) SITE_URL, '/');
        }

        return $scheme . '://localhost';
    }
}

if (!function_exists('geoflow_site_scope_condition_for_site')) {
    function geoflow_site_scope_condition_for_site(PDO $pdo, string $table, int $siteId, string $alias = ''): string {
        if ($siteId <= 0 || !geoflow_is_safe_identifier($table) || !geoflow_table_has_site_column($pdo, $table)) {
            return '';
        }

        $column = $alias !== '' ? $alias . '.site_id' : 'site_id';
        return $column . ' = ' . $siteId;
    }
}

if (!function_exists('geoflow_site_scope_condition')) {
    function geoflow_site_scope_condition(string $table, string $alias = ''): string {
        global $db;

        if (!($db instanceof PDO)) {
            return '';
        }

        return geoflow_site_scope_condition_for_site($db, $table, geoflow_current_site_id(), $alias);
    }
}

if (!function_exists('geoflow_site_scope_sql')) {
    function geoflow_site_scope_sql(string $table, string $alias = ''): string {
        $condition = geoflow_site_scope_condition($table, $alias);
        return $condition !== '' ? ' AND ' . $condition : '';
    }
}

if (!function_exists('geoflow_record_belongs_to_site')) {
    function geoflow_record_belongs_to_site(PDO $pdo, string $table, int $id, int $siteId): bool {
        if ($id <= 0 || !geoflow_is_safe_identifier($table)) {
            return false;
        }

        if (!geoflow_table_has_site_column($pdo, $table)) {
            $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            return (bool) $stmt->fetchColumn();
        }

        if ($siteId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = ? AND site_id = ? LIMIT 1");
        $stmt->execute([$id, $siteId]);
        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('geoflow_record_belongs_to_current_site')) {
    function geoflow_record_belongs_to_current_site(PDO $pdo, string $table, int $id): bool {
        return geoflow_record_belongs_to_site($pdo, $table, $id, geoflow_current_site_id());
    }
}

if (!function_exists('geoflow_required_site_id')) {
    function geoflow_required_site_id(PDO $pdo, ?int $fallbackSiteId = null): int {
        $siteId = (int) ($fallbackSiteId ?: geoflow_current_site_id());
        if ($siteId > 0) {
            return $siteId;
        }

        $defaultSite = geoflow_fetch_default_site_record($pdo);
        return (int) ($defaultSite['id'] ?? 0);
    }
}

if (!function_exists('geoflow_fetch_setting_value')) {
    function geoflow_fetch_setting_value(PDO $pdo, string $key, $default = '') {
        if (!geoflow_db_table_exists($pdo, 'site_settings')) {
            return $default;
        }

        try {
            if (geoflow_table_has_site_column($pdo, 'site_settings')) {
                $siteId = geoflow_current_site_id();
                if ($siteId > 0) {
                    $stmt = $pdo->prepare("
                        SELECT setting_value
                        FROM site_settings
                        WHERE site_id = ?
                          AND setting_key = ?
                        ORDER BY id DESC
                        LIMIT 1
                    ");
                    $stmt->execute([$siteId, $key]);
                    $value = $stmt->fetchColumn();
                    if ($value !== false && $value !== '') {
                        return $value;
                    }
                }

                $stmt = $pdo->prepare("
                    SELECT setting_value
                    FROM site_settings
                    WHERE setting_key = ?
                      AND (site_id IS NULL OR site_id = 0)
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $stmt->execute([$key]);
                $value = $stmt->fetchColumn();
                return ($value !== false && $value !== '') ? $value : $default;
            }

            $stmt = $pdo->prepare("
                SELECT setting_value
                FROM site_settings
                WHERE setting_key = ?
                ORDER BY id DESC
                LIMIT 1
            ");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return ($value !== false && $value !== '') ? $value : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }
}

if (!function_exists('geoflow_upsert_setting_value')) {
    function geoflow_upsert_setting_value(PDO $pdo, string $key, string $value): bool {
        try {
            if (geoflow_table_has_site_column($pdo, 'site_settings')) {
                $siteId = geoflow_current_site_id();
                if ($siteId <= 0) {
                    $siteId = (int) (geoflow_fetch_default_site_record($pdo)['id'] ?? 0);
                }

                if ($siteId <= 0) {
                    return false;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO site_settings (site_id, setting_key, setting_value, created_at, updated_at)
                    VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                    ON CONFLICT (site_id, setting_key)
                    DO UPDATE SET
                        setting_value = EXCLUDED.setting_value,
                        updated_at = CURRENT_TIMESTAMP
                ");

                return $stmt->execute([$siteId, $key, $value]);
            }

            $stmt = $pdo->prepare("
                UPDATE site_settings
                SET setting_value = ?, updated_at = CURRENT_TIMESTAMP
                WHERE setting_key = ?
            ");
            $stmt->execute([$value, $key]);

            if ($stmt->rowCount() > 0) {
                return true;
            }

            $stmt = $pdo->prepare("
                INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at)
                VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            return $stmt->execute([$key, $value]);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('geoflow_seed_site_network_domain')) {
    function geoflow_seed_site_network_domain(PDO $pdo, int $siteId): void {
        if ($siteId <= 0 || !geoflow_db_table_exists($pdo, 'site_domains')) {
            return;
        }

        $candidate = geoflow_extract_url_host(defined('SITE_URL') ? (string) SITE_URL : '');
        if ($candidate === '') {
            $candidate = geoflow_request_host();
        }

        if ($candidate === '') {
            return;
        }

        $update = $pdo->prepare("
            UPDATE sites
            SET primary_domain = CASE WHEN COALESCE(primary_domain, '') = '' THEN ? ELSE primary_domain END,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $update->execute([$candidate, $siteId]);

        $exists = $pdo->prepare("SELECT 1 FROM site_domains WHERE domain = ? LIMIT 1");
        $exists->execute([$candidate]);
        if ($exists->fetchColumn()) {
            return;
        }

        $insert = $pdo->prepare("
            INSERT INTO site_domains (site_id, domain, is_primary, created_at, updated_at)
            VALUES (?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $insert->execute([$siteId, $candidate]);
    }
}

if (!function_exists('geoflow_ensure_site_network_schema')) {
    function geoflow_ensure_site_network_schema(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sites (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                slug VARCHAR(120) NOT NULL UNIQUE,
                description TEXT DEFAULT '',
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                is_default INTEGER NOT NULL DEFAULT 0,
                primary_domain VARCHAR(255) DEFAULT '',
                site_title VARCHAR(255) DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS site_domains (
                id BIGSERIAL PRIMARY KEY,
                site_id BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                domain VARCHAR(255) NOT NULL UNIQUE,
                is_primary INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );

            CREATE UNIQUE INDEX IF NOT EXISTS idx_sites_slug ON sites(slug);
            CREATE UNIQUE INDEX IF NOT EXISTS idx_site_domains_domain ON site_domains(domain);
            CREATE INDEX IF NOT EXISTS idx_site_domains_site_id ON site_domains(site_id);
        ");

        $siteName = defined('SITE_NAME') ? (string) SITE_NAME : 'GEOflow';
        $siteDescription = defined('SITE_DESCRIPTION') ? (string) SITE_DESCRIPTION : '';
        $siteSlug = geoflow_slugify_site_name($siteName);
        $primaryDomain = geoflow_extract_url_host(defined('SITE_URL') ? (string) SITE_URL : '');

        $defaultSite = geoflow_fetch_default_site_record($pdo);
        if (!$defaultSite) {
            $insert = $pdo->prepare("
                INSERT INTO sites (id, name, slug, description, status, is_default, primary_domain, site_title, created_at, updated_at)
                VALUES (1, ?, ?, ?, 'active', 1, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                ON CONFLICT (id)
                DO UPDATE SET
                    name = EXCLUDED.name,
                    slug = EXCLUDED.slug,
                    description = CASE WHEN COALESCE(sites.description, '') = '' THEN EXCLUDED.description ELSE sites.description END,
                    primary_domain = CASE WHEN COALESCE(sites.primary_domain, '') = '' THEN EXCLUDED.primary_domain ELSE sites.primary_domain END,
                    site_title = CASE WHEN COALESCE(sites.site_title, '') = '' THEN EXCLUDED.site_title ELSE sites.site_title END,
                    is_default = 1,
                    updated_at = CURRENT_TIMESTAMP
            ");
            $insert->execute([$siteName, $siteSlug, $siteDescription, $primaryDomain, $siteName]);
            $defaultSite = geoflow_fetch_default_site_record($pdo);
        }

        $defaultSiteId = (int) ($defaultSite['id'] ?? 1);
        $pdo->query("SELECT setval(pg_get_serial_sequence('sites', 'id'), COALESCE((SELECT MAX(id) FROM sites), 1), true)");

        foreach (geoflow_site_scoped_tables() as $table) {
            if (!geoflow_db_table_exists($pdo, $table)) {
                continue;
            }

            if (!db_column_exists($pdo, $table, 'site_id')) {
                $pdo->exec("ALTER TABLE {$table} ADD COLUMN site_id BIGINT DEFAULT NULL");
            }

            $pdo->exec("UPDATE {$table} SET site_id = {$defaultSiteId} WHERE site_id IS NULL");
            $pdo->exec("ALTER TABLE {$table} ALTER COLUMN site_id SET DEFAULT {$defaultSiteId}");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$table}_site_id ON {$table}(site_id)");
        }

        $pdo->exec("DROP INDEX IF EXISTS idx_site_settings_key");
        if (geoflow_db_constraint_exists($pdo, 'site_settings_setting_key_key')) {
            $pdo->exec("ALTER TABLE site_settings DROP CONSTRAINT site_settings_setting_key_key");
        }
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_site_settings_site_key ON site_settings(site_id, setting_key)");

        if (geoflow_db_constraint_exists($pdo, 'categories_slug_key')) {
            $pdo->exec("ALTER TABLE categories DROP CONSTRAINT categories_slug_key");
        }
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_categories_site_slug ON categories(site_id, slug)");

        if (geoflow_db_constraint_exists($pdo, 'articles_slug_key')) {
            $pdo->exec("ALTER TABLE articles DROP CONSTRAINT articles_slug_key");
        }
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_articles_site_slug ON articles(site_id, slug)");

        geoflow_seed_site_network_domain($pdo, $defaultSiteId);
    }
}
