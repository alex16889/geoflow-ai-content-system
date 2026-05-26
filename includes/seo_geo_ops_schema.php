<?php

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

function geoflow_ensure_seo_geo_ops_schema(PDO $pdo): void {
    $imageColumns = [
        'alt_text' => "ALTER TABLE images ADD COLUMN alt_text TEXT DEFAULT ''",
        'caption' => "ALTER TABLE images ADD COLUMN caption TEXT DEFAULT ''",
        'seo_filename' => "ALTER TABLE images ADD COLUMN seo_filename VARCHAR(255) DEFAULT ''",
        'metadata_json' => "ALTER TABLE images ADD COLUMN metadata_json TEXT DEFAULT ''",
    ];

    if (geoflow_db_table_exists($pdo, 'images')) {
        foreach ($imageColumns as $column => $sql) {
            if (!db_column_exists($pdo, 'images', $column)) {
                $pdo->exec($sql);
            }
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS search_performance_snapshots (
            id BIGSERIAL PRIMARY KEY,
            site_id BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            source VARCHAR(60) NOT NULL DEFAULT 'manual',
            snapshot_date DATE NOT NULL DEFAULT CURRENT_DATE,
            query TEXT DEFAULT '',
            page_url TEXT DEFAULT '',
            clicks INTEGER NOT NULL DEFAULT 0,
            impressions INTEGER NOT NULL DEFAULT 0,
            ctr NUMERIC(8,4) DEFAULT NULL,
            avg_position NUMERIC(8,2) DEFAULT NULL,
            notes TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE INDEX IF NOT EXISTS idx_search_performance_site_date
            ON search_performance_snapshots(site_id, snapshot_date DESC);
        CREATE INDEX IF NOT EXISTS idx_search_performance_source
            ON search_performance_snapshots(site_id, source, snapshot_date DESC);

        CREATE TABLE IF NOT EXISTS ai_visibility_checks (
            id BIGSERIAL PRIMARY KEY,
            site_id BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            provider VARCHAR(80) NOT NULL DEFAULT 'manual',
            query TEXT NOT NULL,
            brand_mentioned INTEGER NOT NULL DEFAULT 0,
            citation_url TEXT DEFAULT '',
            answer_excerpt TEXT DEFAULT '',
            visibility_score INTEGER DEFAULT NULL,
            notes TEXT DEFAULT '',
            checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE INDEX IF NOT EXISTS idx_ai_visibility_site_checked
            ON ai_visibility_checks(site_id, checked_at DESC);
        CREATE INDEX IF NOT EXISTS idx_ai_visibility_provider
            ON ai_visibility_checks(site_id, provider, checked_at DESC);

        CREATE TABLE IF NOT EXISTS competitor_briefs (
            id BIGSERIAL PRIMARY KEY,
            site_id BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            seed_keyword VARCHAR(255) NOT NULL,
            competitor_url TEXT DEFAULT '',
            competitor_title TEXT DEFAULT '',
            notes TEXT DEFAULT '',
            brief_json TEXT DEFAULT '',
            created_by_admin_id BIGINT DEFAULT NULL REFERENCES admins(id) ON DELETE SET NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );

        CREATE INDEX IF NOT EXISTS idx_competitor_briefs_site_created
            ON competitor_briefs(site_id, created_at DESC);

        CREATE TABLE IF NOT EXISTS redirect_rules (
            id BIGSERIAL PRIMARY KEY,
            site_id BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            source_path VARCHAR(500) NOT NULL,
            target_url TEXT NOT NULL,
            status_code INTEGER NOT NULL DEFAULT 301,
            is_active INTEGER NOT NULL DEFAULT 1,
            hit_count INTEGER NOT NULL DEFAULT 0,
            last_hit_at TIMESTAMP DEFAULT NULL,
            notes TEXT DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(site_id, source_path)
        );

        CREATE INDEX IF NOT EXISTS idx_redirect_rules_site_active_path
            ON redirect_rules(site_id, is_active, source_path);

        CREATE TABLE IF NOT EXISTS not_found_logs (
            id BIGSERIAL PRIMARY KEY,
            site_id BIGINT NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
            path VARCHAR(500) NOT NULL,
            referrer TEXT DEFAULT '',
            user_agent TEXT DEFAULT '',
            hit_count INTEGER NOT NULL DEFAULT 1,
            first_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(site_id, path)
        );

        CREATE INDEX IF NOT EXISTS idx_not_found_logs_site_last_seen
            ON not_found_logs(site_id, last_seen_at DESC);
    ");
}

