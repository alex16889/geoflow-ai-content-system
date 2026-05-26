<?php
/**
 * 智能GEO内容系统 - 站点管理
 */

define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/site_template_service.php';

require_super_admin();

$page_title = __('sites.page_title');
$action = $_GET['action'] ?? 'list';
$id = max(0, (int) ($_GET['id'] ?? 0));
$message = '';
$error = '';

function build_sites_redirect_url(array $params = []): string
{
    $query = http_build_query($params);
    return 'sites.php' . ($query !== '' ? '?' . $query : '');
}

function build_site_slug(PDO $db, string $name, string $rawSlug = '', int $excludeId = 0): string
{
    $source = trim($rawSlug) !== '' ? trim($rawSlug) : trim($name);
    $slug = geoflow_slugify_site_name($source);
    $baseSlug = $slug;
    $counter = 2;

    while (true) {
        if ($excludeId > 0) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM sites WHERE slug = ? AND id != ?');
            $stmt->execute([$slug, $excludeId]);
        } else {
            $stmt = $db->prepare('SELECT COUNT(*) FROM sites WHERE slug = ?');
            $stmt->execute([$slug]);
        }

        if ((int) $stmt->fetchColumn() === 0) {
            return $slug;
        }

        $slug = $baseSlug . '-' . $counter;
        $counter++;
    }
}

function normalize_site_domain_input(string $domain): string
{
    return geoflow_normalize_host($domain);
}

function parse_site_domains(string $primaryDomain, string $aliasDomains): array
{
    $domains = [];

    $primaryDomain = normalize_site_domain_input($primaryDomain);
    if ($primaryDomain !== '') {
        $domains[] = $primaryDomain;
    }

    $parts = preg_split('/[\s,]+/', trim($aliasDomains)) ?: [];
    foreach ($parts as $part) {
        $normalized = normalize_site_domain_input($part);
        if ($normalized !== '' && !in_array($normalized, $domains, true)) {
            $domains[] = $normalized;
        }
    }

    return [$primaryDomain, $domains];
}

function find_conflicting_site_domain(PDO $db, array $domains, int $excludeSiteId = 0): string
{
    if (empty($domains)) {
        return '';
    }

    $placeholders = implode(',', array_fill(0, count($domains), '?'));
    $sql = "
        SELECT domain
        FROM site_domains
        WHERE domain IN ($placeholders)
    ";
    $params = $domains;

    if ($excludeSiteId > 0) {
        $sql .= ' AND site_id != ?';
        $params[] = $excludeSiteId;
    }

    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return (string) ($stmt->fetchColumn() ?: '');
}

function sync_site_domains(PDO $db, int $siteId, string $primaryDomain, array $domains): void
{
    $deleteSql = 'DELETE FROM site_domains WHERE site_id = ?';
    $deleteParams = [$siteId];

    if (!empty($domains)) {
        $placeholders = implode(',', array_fill(0, count($domains), '?'));
        $deleteSql .= " AND domain NOT IN ($placeholders)";
        $deleteParams = array_merge($deleteParams, $domains);
    }

    $deleteStmt = $db->prepare($deleteSql);
    $deleteStmt->execute($deleteParams);

    if (empty($domains)) {
        return;
    }

    $insertStmt = $db->prepare("
        INSERT INTO site_domains (site_id, domain, is_primary, created_at, updated_at)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ON CONFLICT (domain)
        DO UPDATE SET
            site_id = EXCLUDED.site_id,
            is_primary = EXCLUDED.is_primary,
            updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($domains as $domain) {
        $insertStmt->execute([$siteId, $domain, $domain === $primaryDomain ? 1 : 0]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = __('message.csrf_failed');
    } else {
        $postAction = $_POST['action'] ?? '';

        try {
            switch ($postAction) {
                case 'save_site':
                    $siteId = max(0, (int) ($_POST['site_id'] ?? 0));
                    $name = trim((string) ($_POST['name'] ?? ''));
                    $slug = trim((string) ($_POST['slug'] ?? ''));
                    $siteTitle = trim((string) ($_POST['site_title'] ?? ''));
                    $description = trim((string) ($_POST['description'] ?? ''));
                    $primaryDomainInput = trim((string) ($_POST['primary_domain'] ?? ''));
                    $aliasDomainsInput = trim((string) ($_POST['alias_domains'] ?? ''));
                    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
                    $isDefault = !empty($_POST['is_default']);

                    if ($name === '') {
                        throw new RuntimeException(__('sites.error.name_required'));
                    }

                    if ($isDefault && $status !== 'active') {
                        throw new RuntimeException(__('sites.error.default_site_inactive'));
                    }

                    $slug = build_site_slug($db, $name, $slug, $siteId);
                    [$primaryDomain, $domains] = parse_site_domains($primaryDomainInput, $aliasDomainsInput);
                    $conflictDomain = find_conflicting_site_domain($db, $domains, $siteId);
                    if ($conflictDomain !== '') {
                        throw new RuntimeException(__('sites.error.domain_exists', ['domain' => $conflictDomain]));
                    }

                    $db->beginTransaction();

                    if ($siteId > 0) {
                        $stmt = $db->prepare("
                            UPDATE sites
                            SET name = ?, slug = ?, site_title = ?, description = ?, primary_domain = ?, status = ?, updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmt->execute([$name, $slug, $siteTitle, $description, $primaryDomain, $status, $siteId]);

                        if ($stmt->rowCount() === 0) {
                            throw new RuntimeException(__('sites.error.not_found'));
                        }
                    } else {
                        $stmt = $db->prepare("
                            INSERT INTO sites (name, slug, site_title, description, primary_domain, status, is_default, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                            RETURNING id
                        ");
                        $stmt->execute([$name, $slug, $siteTitle, $description, $primaryDomain, $status]);
                        $siteId = (int) $stmt->fetchColumn();
                    }

                    if ($siteId <= 0) {
                        throw new RuntimeException(__('sites.error.not_found'));
                    }

                    if ($isDefault) {
                        $resetDefaultStmt = $db->prepare('UPDATE sites SET is_default = CASE WHEN id = ? THEN 1 ELSE 0 END');
                        $resetDefaultStmt->execute([$siteId]);
                    } else {
                        $hasDefaultStmt = $db->query("SELECT id FROM sites WHERE is_default = 1 LIMIT 1");
                        $hasDefault = (int) ($hasDefaultStmt->fetchColumn() ?: 0);
                        if ($hasDefault === 0) {
                            $promoteDefaultStmt = $db->prepare('UPDATE sites SET is_default = CASE WHEN id = ? THEN 1 ELSE 0 END');
                            $promoteDefaultStmt->execute([$siteId]);
                        }
                    }

                    sync_site_domains($db, $siteId, $primaryDomain, $domains);
                    $db->commit();

                    if ($status === 'inactive' && geoflow_admin_selected_site_id() === $siteId) {
                        geoflow_clear_admin_selected_site_id();
                    }

                    header('Location: ' . build_sites_redirect_url([
                        'message' => !empty($_POST['site_id']) ? __('sites.message.update_success') : __('sites.message.add_success')
                    ]));
                    exit;

                case 'toggle_status':
                    $siteId = max(0, (int) ($_POST['site_id'] ?? 0));
                    $nextStatus = ($_POST['next_status'] ?? 'inactive') === 'inactive' ? 'inactive' : 'active';
                    $stmt = $db->prepare('SELECT id, is_default FROM sites WHERE id = ? LIMIT 1');
                    $stmt->execute([$siteId]);
                    $site = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$site) {
                        throw new RuntimeException(__('sites.error.not_found'));
                    }

                    if ($nextStatus === 'inactive' && (int) ($site['is_default'] ?? 0) === 1) {
                        throw new RuntimeException(__('sites.error.default_site_inactive'));
                    }

                    $updateStmt = $db->prepare('UPDATE sites SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                    $updateStmt->execute([$nextStatus, $siteId]);

                    if ($nextStatus === 'inactive' && geoflow_admin_selected_site_id() === $siteId) {
                        geoflow_clear_admin_selected_site_id();
                    }

                    header('Location: ' . build_sites_redirect_url([
                        'message' => __('sites.message.status_updated')
                    ]));
                    exit;

                case 'clone_site':
                    $sourceSiteId = max(0, (int) ($_POST['source_site_id'] ?? 0));
                    $cloneName = trim((string) ($_POST['clone_name'] ?? ''));
                    $cloneSlug = trim((string) ($_POST['clone_slug'] ?? ''));
                    $clonePrimaryDomain = trim((string) ($_POST['clone_primary_domain'] ?? ''));

                    $cloneResult = SiteTemplateService::cloneSite($db, $sourceSiteId, [
                        'name' => $cloneName,
                        'slug' => $cloneSlug,
                        'site_title' => $cloneName,
                        'primary_domain' => $clonePrimaryDomain,
                    ]);

                    header('Location: ' . build_sites_redirect_url([
                        'message' => '已复制新站点：' . $cloneName . '（ID ' . (int) $cloneResult['site_id'] . '）'
                    ]));
                    exit;

                case 'make_default':
                    $siteId = max(0, (int) ($_POST['site_id'] ?? 0));
                    $stmt = $db->prepare("SELECT id, status FROM sites WHERE id = ? LIMIT 1");
                    $stmt->execute([$siteId]);
                    $site = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$site) {
                        throw new RuntimeException(__('sites.error.not_found'));
                    }

                    if (($site['status'] ?? 'inactive') !== 'active') {
                        throw new RuntimeException(__('sites.error.default_site_inactive'));
                    }

                    $db->beginTransaction();
                    $updateStmt = $db->prepare('UPDATE sites SET is_default = CASE WHEN id = ? THEN 1 ELSE 0 END');
                    $updateStmt->execute([$siteId]);
                    $db->commit();

                    header('Location: ' . build_sites_redirect_url([
                        'message' => __('sites.message.default_updated')
                    ]));
                    exit;
            }
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $error = $e->getMessage() !== '' ? $e->getMessage() : __('sites.message.action_failed');
        }
    }
}

session_write_close();

if (isset($_GET['message']) && trim((string) $_GET['message']) !== '') {
    $message = trim((string) $_GET['message']);
}

$siteFormData = [
    'id' => 0,
    'name' => '',
    'slug' => '',
    'site_title' => '',
    'description' => '',
    'primary_domain' => '',
    'alias_domains' => '',
    'status' => 'active',
    'is_default' => 0,
];

if ($action === 'edit' && $id > 0) {
    $stmt = $db->prepare("
        SELECT s.*
        FROM sites s
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $site = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$site) {
        $error = __('sites.error.not_found');
        $action = 'list';
    } else {
        $domainsStmt = $db->prepare("
            SELECT domain, is_primary
            FROM site_domains
            WHERE site_id = ?
            ORDER BY is_primary DESC, id ASC
        ");
        $domainsStmt->execute([$id]);
        $siteDomains = $domainsStmt->fetchAll(PDO::FETCH_ASSOC);

        $primaryDomain = (string) ($site['primary_domain'] ?? '');
        $aliasDomains = [];
        foreach ($siteDomains as $domainRow) {
            $domain = (string) ($domainRow['domain'] ?? '');
            if ($domain === '' || $domain === $primaryDomain) {
                continue;
            }
            $aliasDomains[] = $domain;
        }

        $siteFormData = [
            'id' => (int) ($site['id'] ?? 0),
            'name' => (string) ($site['name'] ?? ''),
            'slug' => (string) ($site['slug'] ?? ''),
            'site_title' => (string) ($site['site_title'] ?? ''),
            'description' => (string) ($site['description'] ?? ''),
            'primary_domain' => $primaryDomain,
            'alias_domains' => implode("\n", $aliasDomains),
            'status' => (string) ($site['status'] ?? 'active'),
            'is_default' => (int) ($site['is_default'] ?? 0),
        ];
    }
}

$sites = geoflow_list_sites($db, true);
$selectedSiteId = geoflow_current_site_id();
$stats = [
    'total_sites' => count($sites),
    'active_sites' => count(array_filter($sites, static fn(array $site): bool => ($site['status'] ?? 'inactive') === 'active')),
    'domain_count' => 0,
];

foreach ($sites as $site) {
    $domains = array_filter(array_map('trim', explode(',', (string) ($site['domains'] ?? ''))));
    $stats['domain_count'] += count($domains);
}

require_once __DIR__ . '/includes/header.php';
?>

            <div class="mb-8">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo __('sites.heading'); ?></h1>
                        <p class="mt-1 text-sm text-gray-600"><?php echo __('sites.subtitle'); ?></p>
                    </div>
                    <div class="flex items-center gap-3">
                        <?php if ($action === 'list'): ?>
                            <a href="sites.php?action=add" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                <i data-lucide="plus" class="mr-2 h-4 w-4"></i>
                                <?php echo __('sites.add'); ?>
                            </a>
                        <?php else: ?>
                            <a href="sites.php" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                <i data-lucide="arrow-left" class="mr-2 h-4 w-4"></i>
                                <?php echo __('sites.back_to_list'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($action === 'add' || $action === 'edit'): ?>
                <div class="rounded-lg bg-white shadow">
                    <form method="POST" class="space-y-6 p-6">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                        <input type="hidden" name="action" value="save_site">
                        <input type="hidden" name="site_id" value="<?php echo (int) $siteFormData['id']; ?>">

                        <div class="grid gap-6 md:grid-cols-2">
                            <div>
                                <label for="name" class="mb-2 block text-sm font-medium text-gray-700"><?php echo __('sites.field.name'); ?></label>
                                <input id="name" name="name" type="text" required value="<?php echo htmlspecialchars((string) $siteFormData['name']); ?>" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                            </div>
                            <div>
                                <label for="slug" class="mb-2 block text-sm font-medium text-gray-700"><?php echo __('sites.field.slug'); ?></label>
                                <input id="slug" name="slug" type="text" value="<?php echo htmlspecialchars((string) $siteFormData['slug']); ?>" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                            </div>
                            <div>
                                <label for="site_title" class="mb-2 block text-sm font-medium text-gray-700"><?php echo __('sites.field.site_title'); ?></label>
                                <input id="site_title" name="site_title" type="text" value="<?php echo htmlspecialchars((string) $siteFormData['site_title']); ?>" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                            </div>
                            <div>
                                <label for="status" class="mb-2 block text-sm font-medium text-gray-700"><?php echo __('sites.field.status'); ?></label>
                                <select id="status" name="status" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                                    <option value="active" <?php echo ($siteFormData['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>><?php echo __('sites.status.active'); ?></option>
                                    <option value="inactive" <?php echo ($siteFormData['status'] ?? 'active') === 'inactive' ? 'selected' : ''; ?>><?php echo __('sites.status.inactive'); ?></option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="description" class="mb-2 block text-sm font-medium text-gray-700"><?php echo __('sites.field.description'); ?></label>
                            <textarea id="description" name="description" rows="4" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"><?php echo htmlspecialchars((string) $siteFormData['description']); ?></textarea>
                        </div>

                        <div class="grid gap-6 md:grid-cols-2">
                            <div>
                                <label for="primary_domain" class="mb-2 block text-sm font-medium text-gray-700"><?php echo __('sites.field.primary_domain'); ?></label>
                                <input id="primary_domain" name="primary_domain" type="text" value="<?php echo htmlspecialchars((string) $siteFormData['primary_domain']); ?>" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                            </div>
                            <div>
                                <label for="alias_domains" class="mb-2 block text-sm font-medium text-gray-700"><?php echo __('sites.field.alias_domains'); ?></label>
                                <textarea id="alias_domains" name="alias_domains" rows="4" placeholder="<?php echo htmlspecialchars(__('sites.placeholder.alias_domains')); ?>" class="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"><?php echo htmlspecialchars((string) $siteFormData['alias_domains']); ?></textarea>
                                <p class="mt-2 text-xs text-gray-500"><?php echo __('sites.help.alias_domains'); ?></p>
                            </div>
                        </div>

                        <label class="inline-flex items-center gap-3 rounded-md border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                            <input type="checkbox" name="is_default" value="1" <?php echo !empty($siteFormData['is_default']) ? 'checked' : ''; ?> class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                            <span><?php echo __('sites.field.is_default'); ?></span>
                        </label>

                        <div class="flex items-center justify-end gap-3 border-t border-gray-100 pt-6">
                            <a href="sites.php" class="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"><?php echo __('button.cancel'); ?></a>
                            <button type="submit" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                                <i data-lucide="save" class="mr-2 h-4 w-4"></i>
                                <?php echo __('button.save'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <div class="mb-8 grid gap-6 md:grid-cols-3">
                    <div class="rounded-lg bg-white shadow">
                        <div class="p-5">
                            <div class="flex items-center">
                                <i data-lucide="layers-3" class="h-6 w-6 text-blue-600"></i>
                                <div class="ml-4">
                                    <div class="text-sm text-gray-500"><?php echo __('sites.stats.total_sites'); ?></div>
                                    <div class="text-2xl font-bold text-gray-900"><?php echo $stats['total_sites']; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="rounded-lg bg-white shadow">
                        <div class="p-5">
                            <div class="flex items-center">
                                <i data-lucide="badge-check" class="h-6 w-6 text-green-600"></i>
                                <div class="ml-4">
                                    <div class="text-sm text-gray-500"><?php echo __('sites.stats.active_sites'); ?></div>
                                    <div class="text-2xl font-bold text-gray-900"><?php echo $stats['active_sites']; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="rounded-lg bg-white shadow">
                        <div class="p-5">
                            <div class="flex items-center">
                                <i data-lucide="globe" class="h-6 w-6 text-indigo-600"></i>
                                <div class="ml-4">
                                    <div class="text-sm text-gray-500"><?php echo __('sites.stats.domain_count'); ?></div>
                                    <div class="text-2xl font-bold text-gray-900"><?php echo $stats['domain_count']; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg bg-white shadow">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"><?php echo __('sites.column.site'); ?></th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"><?php echo __('sites.column.slug'); ?></th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"><?php echo __('sites.column.domains'); ?></th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"><?php echo __('sites.column.status'); ?></th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500"><?php echo __('sites.column.actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <?php foreach ($sites as $site): ?>
                                    <?php
                                    $siteId = (int) ($site['id'] ?? 0);
                                    $isDefault = (int) ($site['is_default'] ?? 0) === 1;
                                    $isSelected = $selectedSiteId === $siteId;
                                    $domains = array_filter(array_map('trim', explode(',', (string) ($site['domains'] ?? ''))));
                                    ?>
                                    <tr>
                                        <td class="px-6 py-4 align-top">
                                            <div class="font-medium text-gray-900"><?php echo htmlspecialchars((string) ($site['name'] ?? '')); ?></div>
                                            <?php if (!empty($site['site_title'])): ?>
                                                <div class="mt-1 text-sm text-gray-500"><?php echo htmlspecialchars((string) $site['site_title']); ?></div>
                                            <?php endif; ?>
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                <?php if ($isDefault): ?>
                                                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800"><?php echo __('sites.badge.default'); ?></span>
                                                <?php endif; ?>
                                                <?php if ($isSelected): ?>
                                                    <span class="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-medium text-blue-700"><?php echo __('sites.badge.selected'); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 align-top text-sm text-gray-600"><?php echo htmlspecialchars((string) ($site['slug'] ?? '')); ?></td>
                                        <td class="px-6 py-4 align-top text-sm text-gray-600">
                                            <?php if (!empty($domains)): ?>
                                                <div class="space-y-1">
                                                    <?php foreach ($domains as $domain): ?>
                                                        <div><?php echo htmlspecialchars($domain); ?></div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-gray-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 align-top">
                                            <?php if (($site['status'] ?? 'inactive') === 'active'): ?>
                                                <span class="rounded-full bg-green-100 px-2.5 py-1 text-xs font-medium text-green-700"><?php echo __('sites.status.active'); ?></span>
                                            <?php else: ?>
                                                <span class="rounded-full bg-gray-200 px-2.5 py-1 text-xs font-medium text-gray-700"><?php echo __('sites.status.inactive'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 align-top">
                                            <div class="flex flex-wrap gap-2">
                                                <a href="sites.php?action=edit&id=<?php echo $siteId; ?>" class="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                                    <?php echo __('button.edit'); ?>
                                                </a>

                                                <?php if (!$isDefault): ?>
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                                        <input type="hidden" name="action" value="make_default">
                                                        <input type="hidden" name="site_id" value="<?php echo $siteId; ?>">
                                                        <button type="submit" class="inline-flex items-center rounded-md border border-amber-300 px-3 py-1.5 text-sm font-medium text-amber-700 hover:bg-amber-50">
                                                            <?php echo __('sites.action.make_default'); ?>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="site_id" value="<?php echo $siteId; ?>">
                                                    <input type="hidden" name="next_status" value="<?php echo ($site['status'] ?? 'inactive') === 'active' ? 'inactive' : 'active'; ?>">
                                                    <button type="submit" class="inline-flex items-center rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50">
                                                        <?php echo ($site['status'] ?? 'inactive') === 'active' ? __('sites.action.deactivate') : __('sites.action.activate'); ?>
                                                    </button>
                                                </form>

                                                <form method="POST" class="flex flex-wrap items-center gap-2 rounded-lg border border-blue-100 bg-blue-50 p-2">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                                                    <input type="hidden" name="action" value="clone_site">
                                                    <input type="hidden" name="source_site_id" value="<?php echo $siteId; ?>">
                                                    <input type="text" name="clone_name" required placeholder="新站名称" class="w-32 rounded-md border-blue-200 px-2 py-1 text-xs focus:border-blue-500 focus:ring-blue-500">
                                                    <input type="text" name="clone_primary_domain" placeholder="新站域名，可后补" class="w-36 rounded-md border-blue-200 px-2 py-1 text-xs focus:border-blue-500 focus:ring-blue-500">
                                                    <button type="submit" class="inline-flex items-center rounded-md bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">
                                                        复制为新站
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
