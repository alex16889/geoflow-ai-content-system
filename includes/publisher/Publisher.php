<?php
/**
 * Per-site static publisher.
 *
 * Items move draft -> reviewed -> scheduled -> published.
 * Each site keeps its own config, templates, and published.json ledger.
 */

declare(strict_types=1);

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class Publisher
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';

    private string $root;
    private PublisherClock $clock;

    /** @var callable|null */
    private $readbackMutator;

    public function __construct(string $root, ?PublisherClock $clock = null, ?callable $readbackMutator = null)
    {
        $this->root = rtrim($root, '/');
        $this->clock = $clock ?? new SystemPublisherClock();
        $this->readbackMutator = $readbackMutator;
    }

    public function initSite(string $siteId, string $name, string $baseUrl, string $timezone = 'UTC'): array
    {
        $siteId = $this->assertSiteId($siteId);
        $name = trim($name);
        if ($name === '') {
            throw new PublisherException('Site name is required', 'validation_failed');
        }
        $baseUrl = $this->assertBaseUrl($baseUrl);
        $this->assertTimezone($timezone);

        $path = $this->sitePath($siteId);
        if (is_file($path . '/site.json')) {
            throw new PublisherException('Site already exists', 'site_exists');
        }

        foreach (['items', 'templates', 'output'] as $directory) {
            $target = $path . '/' . $directory;
            if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
                throw new PublisherException('Unable to prepare site directory', 'write_failed');
            }
        }

        $config = [
            'id' => $siteId,
            'name' => $name,
            'timezone' => $timezone,
            'base_url' => $baseUrl,
        ];

        $transaction = new FileTransaction(null);
        try {
            $transaction->put($path . '/site.json', $this->encode($config));
            $transaction->put($path . '/templates/article.html', $this->defaultTemplate());
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollback();
            throw $this->wrap($e);
        }

        return $config;
    }

    public function importDrafts(string $siteId, array $items, bool $dryRun = false): array
    {
        $site = $this->loadSite($siteId);
        $normalized = [];
        $errors = [];
        $seenIds = [];
        $seenSlugs = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $errors[] = ['index' => $index, 'message' => 'Item must be an object'];
                continue;
            }
            try {
                $draft = $this->normalizeIncoming($item);
            } catch (PublisherException $e) {
                $errors[] = ['index' => $index, 'id' => (string) ($item['id'] ?? ''), 'message' => $e->getMessage()];
                continue;
            }
            if (isset($seenIds[$draft['id']]) || isset($seenSlugs[$draft['slug']])) {
                $errors[] = ['index' => $index, 'id' => $draft['id'], 'message' => 'Duplicate id or slug in batch'];
                continue;
            }
            $seenIds[$draft['id']] = true;
            $seenSlugs[$draft['slug']] = true;
            $normalized[] = $draft;
        }

        if ($errors !== []) {
            throw new PublisherException('Import validation failed', 'validation_failed', $errors);
        }

        $plan = [
            'site_id' => $site['id'],
            'dry_run' => $dryRun,
            'created' => [],
            'updated' => [],
            'unchanged' => [],
            'skipped' => [],
        ];
        $writes = [];

        foreach ($normalized as $draft) {
            $existing = $this->loadItem($site, $draft['id']);
            if ($existing === null) {
                $plan['created'][] = $draft['id'];
                $writes[] = $this->newItemDocument($draft, self::STATUS_DRAFT, null, null, null);
                continue;
            }

            if ($existing['content_hash'] === $draft['content_hash']) {
                $plan['unchanged'][] = $draft['id'];
                continue;
            }

            if ($existing['status'] !== self::STATUS_DRAFT) {
                $plan['skipped'][] = ['id' => $draft['id'], 'reason' => 'already_advanced'];
                continue;
            }

            $plan['updated'][] = $draft['id'];
            $existing['title'] = $draft['title'];
            $existing['slug'] = $draft['slug'];
            $existing['body'] = $draft['body'];
            $existing['content_hash'] = $draft['content_hash'];
            $existing['updated_at'] = $this->stamp($this->clock->now());
            $writes[] = $existing;
        }

        if ($dryRun) {
            return $plan;
        }

        $this->withSiteLock($site, function () use ($site, $writes): void {
            $transaction = new FileTransaction(null);
            try {
                foreach ($writes as $document) {
                    $this->beforeItemPersist((string) $document['id']);
                    $transaction->put($this->itemPath($site, (string) $document['id']), $this->encode($document));
                }
                $transaction->commit();
            } catch (Throwable $e) {
                $transaction->rollback();
                throw $this->wrap($e);
            }
        });

        return $plan;
    }

    public function review(string $siteId, string $itemId): array
    {
        $site = $this->loadSite($siteId);
        $item = $this->requireItem($site, $itemId);
        if (!empty($item['reviewed_at'])) {
            return $item;
        }
        if ($item['status'] !== self::STATUS_DRAFT) {
            throw new PublisherException('Only drafts can be reviewed', 'invalid_transition');
        }

        $item['status'] = self::STATUS_REVIEWED;
        $item['reviewed_at'] = $this->stamp($this->clock->now());
        $item['updated_at'] = $item['reviewed_at'];
        $this->saveItem($site, $item);
        return $item;
    }

    public function schedule(string $siteId, string $itemId, DateTimeImmutable $publishAt): array
    {
        $site = $this->loadSite($siteId);
        $item = $this->requireItem($site, $itemId);
        if (empty($item['reviewed_at']) || $item['status'] === self::STATUS_DRAFT) {
            throw new PublisherException('Publishing refused because the item has not been reviewed', 'unreviewed');
        }

        $item['status'] = self::STATUS_SCHEDULED;
        $item['publish_at'] = $this->stamp($publishAt);
        $item['updated_at'] = $this->stamp($this->clock->now());
        $this->saveItem($site, $item);
        return $item;
    }

    public function publish(string $siteId, array $itemIds, bool $dryRun = false): array
    {
        $site = $this->loadSite($siteId);
        $items = [];
        foreach ($itemIds as $itemId) {
            $items[] = $this->requireItem($site, (string) $itemId);
        }

        foreach ($items as $item) {
            if (!$this->isReviewed($item)) {
                throw new PublisherException('Publishing refused because the item has not been reviewed', 'unreviewed');
            }
        }

        return $this->publishItems($site, $items, $dryRun, false);
    }

    public function runScheduled(?string $siteId = null, bool $dryRun = false): array
    {
        if ($siteId !== null && $siteId !== '') {
            $siteIds = [$this->assertSiteId($siteId)];
        } elseif (!is_dir($this->root)) {
            return ['dry_run' => $dryRun, 'sites' => []];
        } else {
            $siteIds = $this->listSiteIds();
        }

        $sites = [];
        foreach ($siteIds as $id) {
            $site = $this->loadSite($id);
            $due = [];
            $skipped = [];
            $now = $this->clock->now();
            foreach ($this->loadAllItems($site) as $item) {
                if ($item['status'] !== self::STATUS_SCHEDULED) {
                    continue;
                }
                if (!$this->isReviewed($item)) {
                    $skipped[] = ['id' => $item['id'], 'reason' => 'unreviewed'];
                    continue;
                }
                if (empty($item['publish_at'])) {
                    $skipped[] = ['id' => $item['id'], 'reason' => 'missing_publish_at'];
                    continue;
                }
                $publishAt = new DateTimeImmutable((string) $item['publish_at']);
                if ($publishAt > $now) {
                    $skipped[] = ['id' => $item['id'], 'reason' => 'not_due'];
                    continue;
                }
                $due[] = $item;
            }
            $result = $this->publishItems($site, $due, $dryRun, true);
            $result['skipped'] = array_merge($skipped, $result['skipped']);
            $sites[] = $result;
        }

        return ['dry_run' => $dryRun, 'sites' => $sites];
    }

    protected function beforeItemPersist(string $itemId): void
    {
    }

    private function publishItems(array $site, array $items, bool $dryRun, bool $fromScheduler): array
    {
        $now = $this->clock->now();
        $ledger = $this->loadLedger($site);
        $day = $now->setTimezone(new DateTimeZone($site['timezone']))->format('Y-m-d');
        $result = [
            'site_id' => $site['id'],
            'dry_run' => $dryRun,
            'published' => [],
            'would_publish' => [],
            'skipped' => [],
        ];

        $ready = [];
        foreach ($items as $item) {
            if (!$this->isReviewed($item)) {
                throw new PublisherException('Publishing refused because the item has not been reviewed', 'unreviewed');
            }
            if (!$fromScheduler && $item['status'] === self::STATUS_SCHEDULED && !empty($item['publish_at'])) {
                $publishAt = new DateTimeImmutable((string) $item['publish_at']);
                if ($publishAt > $now) {
                    $result['skipped'][] = ['id' => $item['id'], 'reason' => 'not_due'];
                    continue;
                }
            }
            if ($this->ledgerHasSameDay($ledger, (string) $item['id'], (string) $item['slug'], $day)) {
                $result['skipped'][] = ['id' => $item['id'], 'reason' => 'duplicate_same_day'];
                if (!$dryRun && $item['status'] !== self::STATUS_PUBLISHED) {
                    $item['status'] = self::STATUS_PUBLISHED;
                    $item['published_at'] = $this->existingPublishedAt($ledger, (string) $item['id'], $day);
                    $item['updated_at'] = $this->stamp($now);
                    $this->saveItem($site, $item);
                }
                continue;
            }
            $ready[] = $item;
        }

        if ($ready === []) {
            return $result;
        }

        $rendered = [];
        foreach ($ready as $item) {
            $publishedAt = $this->stamp($now);
            $html = $this->render($site, $item, $publishedAt);
            $relative = $item['slug'] . '.html';
            $rendered[] = [
                'item' => $item,
                'html' => $html,
                'relative' => $relative,
                'published_at' => $publishedAt,
                'output_hash' => hash('sha256', $html),
            ];
            $preview = [
                'id' => $item['id'],
                'slug' => $item['slug'],
                'output' => $relative,
                'output_hash' => hash('sha256', $html),
            ];
            if ($dryRun) {
                $result['would_publish'][] = $preview;
            }
        }

        if ($dryRun) {
            return $result;
        }

        $this->withSiteLock($site, function () use ($site, $rendered, $ledger, $day, &$result): void {
            $transaction = new FileTransaction($this->readbackMutator);
            $nextLedger = $ledger;
            try {
                foreach ($rendered as $entry) {
                    $transaction->put($site['path'] . '/output/' . $entry['relative'], $entry['html']);
                }

                foreach ($rendered as $entry) {
                    $nextLedger['entries'][] = [
                        'id' => $entry['item']['id'],
                        'slug' => $entry['item']['slug'],
                        'published_on' => $day,
                        'published_at' => $entry['published_at'],
                        'content_hash' => $entry['item']['content_hash'],
                        'output' => $entry['relative'],
                        'output_hash' => $entry['output_hash'],
                    ];
                }
                $transaction->put($site['path'] . '/published.json', $this->encode($nextLedger));

                foreach ($rendered as $entry) {
                    $item = $entry['item'];
                    $item['status'] = self::STATUS_PUBLISHED;
                    $item['published_at'] = $entry['published_at'];
                    $item['updated_at'] = $entry['published_at'];
                    $transaction->put($this->itemPath($site, (string) $item['id']), $this->encode($item));
                    $result['published'][] = [
                        'id' => $item['id'],
                        'slug' => $item['slug'],
                        'output' => $entry['relative'],
                        'output_hash' => $entry['output_hash'],
                    ];
                }
                $transaction->commit();
            } catch (Throwable $e) {
                $transaction->rollback();
                $result['published'] = [];
                throw $this->wrap($e);
            }
        });

        return $result;
    }

    private function render(array $site, array $item, string $publishedAt): string
    {
        $templatePath = $site['path'] . '/templates/article.html';
        $template = $this->defaultTemplate();
        if (is_file($templatePath)) {
            $loaded = file_get_contents($templatePath);
            if ($loaded === false) {
                throw new PublisherException('Site template could not be read', 'template_unreadable');
            }
            $template = $loaded;
        }

        return strtr($template, [
            '{{title}}' => $this->escape($item['title']),
            '{{body}}' => $this->escape($item['body']),
            '{{slug}}' => $this->escape($item['slug']),
            '{{site_name}}' => $this->escape($site['name']),
            '{{base_url}}' => $this->escape($site['base_url']),
            '{{published_at}}' => $this->escape($publishedAt),
        ]);
    }

    private function ledgerHasSameDay(array $ledger, string $id, string $slug, string $day): bool
    {
        foreach ($ledger['entries'] as $entry) {
            if (($entry['published_on'] ?? '') !== $day) {
                continue;
            }
            if (($entry['id'] ?? '') === $id || ($entry['slug'] ?? '') === $slug) {
                return true;
            }
        }
        return false;
    }

    private function existingPublishedAt(array $ledger, string $id, string $day): string
    {
        foreach ($ledger['entries'] as $entry) {
            if (($entry['id'] ?? '') === $id && ($entry['published_on'] ?? '') === $day) {
                return (string) ($entry['published_at'] ?? $this->stamp($this->clock->now()));
            }
        }
        return $this->stamp($this->clock->now());
    }

    private function loadLedger(array $site): array
    {
        $path = $site['path'] . '/published.json';
        if (!is_file($path)) {
            return ['version' => 1, 'site_id' => $site['id'], 'entries' => []];
        }
        $decoded = $this->readJson($path);
        $entries = $decoded['entries'] ?? null;
        if (!is_array($entries)) {
            throw new PublisherException('Site ledger is invalid', 'invalid_ledger');
        }
        return [
            'version' => 1,
            'site_id' => $site['id'],
            'entries' => array_values($entries),
        ];
    }

    private function isReviewed(array $item): bool
    {
        return !empty($item['reviewed_at']) && $item['status'] !== self::STATUS_DRAFT;
    }

    private function normalizeIncoming(array $item): array
    {
        $id = $this->assertItemId((string) ($item['id'] ?? ''));
        $slug = $this->assertSlug((string) ($item['slug'] ?? ''));
        $title = trim((string) ($item['title'] ?? ''));
        $body = trim((string) ($item['body'] ?? ''));
        if ($title === '' || $body === '') {
            throw new PublisherException('Title and body are required', 'validation_failed');
        }

        return [
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'body' => $body,
            'content_hash' => hash('sha256', $id . "\n" . $slug . "\n" . $title . "\n" . $body),
        ];
    }

    private function newItemDocument(array $draft, string $status, ?string $reviewedAt, ?string $publishAt, ?string $publishedAt): array
    {
        $stamp = $this->stamp($this->clock->now());
        return [
            'id' => $draft['id'],
            'slug' => $draft['slug'],
            'title' => $draft['title'],
            'body' => $draft['body'],
            'status' => $status,
            'content_hash' => $draft['content_hash'],
            'reviewed_at' => $reviewedAt,
            'publish_at' => $publishAt,
            'published_at' => $publishedAt,
            'updated_at' => $stamp,
        ];
    }

    private function saveItem(array $site, array $item): void
    {
        $this->withSiteLock($site, function () use ($site, $item): void {
            $transaction = new FileTransaction(null);
            try {
                $transaction->put($this->itemPath($site, (string) $item['id']), $this->encode($item));
                $transaction->commit();
            } catch (Throwable $e) {
                $transaction->rollback();
                throw $this->wrap($e);
            }
        });
    }

    private function requireItem(array $site, string $itemId): array
    {
        $itemId = $this->assertItemId($itemId);
        $item = $this->loadItem($site, $itemId);
        if ($item === null) {
            throw new PublisherException('Item was not found', 'item_not_found');
        }
        return $item;
    }

    private function loadItem(array $site, string $itemId): ?array
    {
        $path = $this->itemPath($site, $itemId);
        if (!is_file($path)) {
            return null;
        }
        return $this->hydrateItem($this->readJson($path));
    }

    private function loadAllItems(array $site): array
    {
        $directory = $site['path'] . '/items';
        if (!is_dir($directory)) {
            return [];
        }
        $items = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if (!str_ends_with($entry, '.json')) {
                continue;
            }
            $decoded = $this->readJson($directory . '/' . $entry);
            $items[] = $this->hydrateItem($decoded);
        }
        usort($items, static function (array $a, array $b): int {
            return strcmp((string) $a['id'], (string) $b['id']);
        });
        return $items;
    }

    private function hydrateItem(array $decoded): array
    {
        $id = $this->assertItemId((string) ($decoded['id'] ?? ''));
        $status = (string) ($decoded['status'] ?? '');
        if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_REVIEWED, self::STATUS_SCHEDULED, self::STATUS_PUBLISHED], true)) {
            throw new PublisherException('Item status is invalid', 'invalid_document');
        }
        return [
            'id' => $id,
            'slug' => $this->assertSlug((string) ($decoded['slug'] ?? '')),
            'title' => (string) ($decoded['title'] ?? ''),
            'body' => (string) ($decoded['body'] ?? ''),
            'status' => $status,
            'content_hash' => (string) ($decoded['content_hash'] ?? ''),
            'reviewed_at' => $decoded['reviewed_at'] ?? null,
            'publish_at' => $decoded['publish_at'] ?? null,
            'published_at' => $decoded['published_at'] ?? null,
            'updated_at' => (string) ($decoded['updated_at'] ?? ''),
        ];
    }

    private function loadSite(string $siteId): array
    {
        $siteId = $this->assertSiteId($siteId);
        $path = $this->sitePath($siteId);
        $configFile = $path . '/site.json';
        if (!is_file($configFile)) {
            throw new PublisherException('Site was not found', 'site_not_found');
        }
        $config = $this->readJson($configFile);
        if (($config['id'] ?? '') !== $siteId) {
            throw new PublisherException('Site config does not match its directory', 'invalid_site');
        }
        $timezone = (string) ($config['timezone'] ?? 'UTC');
        $this->assertTimezone($timezone);
        return [
            'id' => $siteId,
            'name' => trim((string) ($config['name'] ?? $siteId)),
            'timezone' => $timezone,
            'base_url' => $this->assertBaseUrl((string) ($config['base_url'] ?? '')),
            'path' => $path,
        ];
    }

    private function listSiteIds(): array
    {
        $ids = [];
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_file($this->root . '/' . $entry . '/site.json')) {
                $ids[] = $entry;
            }
        }
        sort($ids);
        return $ids;
    }

    private function sitePath(string $siteId): string
    {
        return $this->root . '/' . $siteId;
    }

    private function itemPath(array $site, string $itemId): string
    {
        return $site['path'] . '/items/' . $itemId . '.json';
    }

    private function withSiteLock(array $site, callable $callback): void
    {
        $lockPath = $site['path'] . '/.publisher.lock';
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new PublisherException('Site is busy', 'site_busy');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new PublisherException('Site is busy', 'site_busy');
            }
            $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function readJson(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new PublisherException('Document could not be read', 'invalid_document');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new PublisherException('Document is not valid JSON', 'invalid_document');
        }
        return $decoded;
    }

    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new PublisherException('Unable to encode document', 'encode_failed');
        }
        return $json . "\n";
    }

    private function stamp(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    private function assertSiteId(string $siteId): string
    {
        $siteId = trim($siteId);
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $siteId) !== 1) {
            throw new PublisherException('Site id is invalid', 'invalid_site');
        }
        return $siteId;
    }

    private function assertItemId(string $itemId): string
    {
        $itemId = trim($itemId);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,80}$/', $itemId) !== 1) {
            throw new PublisherException('Item id is invalid', 'validation_failed');
        }
        return $itemId;
    }

    private function assertSlug(string $slug): string
    {
        $slug = trim($slug);
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 80) {
            throw new PublisherException('Slug is invalid', 'validation_failed');
        }
        return $slug;
    }

    private function assertBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new PublisherException('Base URL is invalid', 'validation_failed');
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new PublisherException('Base URL must not include credentials', 'validation_failed');
        }
        return $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . (isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '');
    }

    private function assertTimezone(string $timezone): void
    {
        try {
            new DateTimeZone($timezone);
        } catch (Throwable $e) {
            throw new PublisherException('Timezone is invalid', 'invalid_timezone');
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function wrap(Throwable $e): PublisherException
    {
        if ($e instanceof PublisherException) {
            return $e;
        }
        return new PublisherException('Publisher command failed', 'publisher_error');
    }

    private function defaultTemplate(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>{{title}}</title>
  <link rel="canonical" href="{{base_url}}/{{slug}}">
</head>
<body>
  <article>
    <h1>{{title}}</h1>
    <div>{{body}}</div>
    <footer>{{site_name}}</footer>
  </article>
</body>
</html>

HTML;
    }
}
