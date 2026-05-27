<?php

$files = [
    __DIR__ . '/../includes/database.php',
    __DIR__ . '/../includes/database_admin.php',
];

foreach ($files as $file) {
    $source = file_get_contents($file);
    if ($source === false) {
        throw new RuntimeException('Unable to read ' . $file);
    }

    if (str_contains($source, 'CREATE UNIQUE INDEX IF NOT EXISTS idx_site_settings_key ON site_settings(setting_key)')) {
        throw new RuntimeException('Legacy global site_settings index is still created in ' . basename($file));
    }
}

$siteContext = file_get_contents(__DIR__ . '/../includes/site_context.php');
if ($siteContext === false || !str_contains($siteContext, 'idx_site_settings_site_key ON site_settings(site_id, setting_key)')) {
    throw new RuntimeException('Site-scoped site_settings unique index is missing');
}

echo "unit_multisite_schema_guards: ok\n";
