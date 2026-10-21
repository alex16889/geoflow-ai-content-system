<?php

declare(strict_types=1);

define('FEISHU_TREASURE', true);

require_once __DIR__ . '/../includes/publisher/bootstrap.php';

function assert_true($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function make_root(): string
{
    $root = sys_get_temp_dir() . '/geoflow-publisher-' . bin2hex(random_bytes(6));
    assert_true(mkdir($root, 0775, true), 'Temp root should be created');
    return $root;
}

function remove_tree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        remove_tree($path . '/' . $entry);
    }
    rmdir($path);
}

function snapshot_files(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        $files[$path] = hash_file('sha256', $path);
    }
    ksort($files);
    return $files;
}

function sample_item(string $id, string $body = 'Body text for the draft.'): array
{
    return [
        'id' => $id,
        'slug' => $id,
        'title' => 'Title ' . $id,
        'body' => $body,
    ];
}

class FlakyImportPublisher extends Publisher
{
    public int $failAfterWrites = PHP_INT_MAX;
    public int $writes = 0;

    protected function beforeItemPersist(string $itemId): void
    {
        if ($this->writes >= $this->failAfterWrites) {
            throw new PublisherException('Import write failed', 'import_failed');
        }
        $this->writes++;
    }
}

$root = make_root();

try {
    $now = new DateTimeImmutable('2026-10-21T18:00:00Z');
    $clock = new FrozenPublisherClock($now);
    $publisher = new Publisher($root, $clock);

    $publisher->initSite('alpha', 'Alpha', 'https://example.com', 'UTC');
    $publisher->initSite('beta', 'Beta', 'https://example.com/beta', 'America/Los_Angeles');
    file_put_contents($root . '/alpha/templates/article.html', "<article>ALPHA {{title}} {{body}}</article>\n");
    file_put_contents($root . '/beta/templates/article.html', "<article>BETA {{title}} {{body}}</article>\n");

    $drySnapshot = snapshot_files($root);
    $dryPlan = $publisher->importDrafts('alpha', [sample_item('one'), sample_item('two')], true);
    assert_same(['one', 'two'], $dryPlan['created'], 'Dry-run import should report both drafts');
    assert_true($dryPlan['dry_run'] === true, 'Dry-run import should set the flag');
    assert_same($drySnapshot, snapshot_files($root), 'Dry-run import must not write files');

    $created = $publisher->importDrafts('alpha', [sample_item('one'), sample_item('two')], false);
    assert_same(['one', 'two'], $created['created'], 'Live import should create both drafts');
    $retry = $publisher->importDrafts('alpha', [sample_item('one'), sample_item('two')], false);
    assert_same(['one', 'two'], $retry['unchanged'], 'Retrying the same import should be idempotent');
    assert_same([], $retry['created'], 'Idempotent retry must not create duplicates');
    assert_true(is_file($root . '/alpha/items/one.json'), 'First draft should exist once');
    assert_same(1, count(glob($root . '/alpha/items/one.json') ?: []), 'Draft id should have a single file');

    $flakyRoot = make_root();
    $flaky = new FlakyImportPublisher($flakyRoot, $clock);
    $flaky->initSite('alpha', 'Alpha', 'https://example.com');
    $flaky->importDrafts('alpha', [sample_item('keep', 'original body')], false);
    $flaky->failAfterWrites = 1;
    $flaky->writes = 0;
    $partialFailed = false;
    try {
        $flaky->importDrafts('alpha', [
            sample_item('keep', 'changed body'),
            sample_item('other', 'second body'),
        ], false);
    } catch (PublisherException $e) {
        $partialFailed = $e->errorCode === 'import_failed';
    }
    assert_true($partialFailed, 'Partial import failure should be reported');
    $kept = json_decode((string) file_get_contents($flakyRoot . '/alpha/items/keep.json'), true);
    assert_same('original body', $kept['body'], 'Partial failure should restore the previous draft');
    assert_same(hash('sha256', (string) file_get_contents($flakyRoot . '/alpha/items/keep.json')), hash_file('sha256', $flakyRoot . '/alpha/items/keep.json'), 'Restored draft should match its hash');
    assert_true(!is_file($flakyRoot . '/alpha/items/other.json'), 'Partial failure should remove the incomplete item');

    $recovered = (new Publisher($flakyRoot, $clock))->importDrafts('alpha', [
        sample_item('keep', 'original body'),
        sample_item('other', 'second body'),
    ], false);
    assert_same(['keep'], $recovered['unchanged'], 'Retry should keep the restored draft');
    assert_same(['other'], $recovered['created'], 'Retry should create only the missing draft');
    assert_true(is_file($flakyRoot . '/alpha/items/other.json'), 'Idempotent retry should finish the batch');

    $freshFail = new FlakyImportPublisher($flakyRoot, $clock);
    $freshFail->failAfterWrites = 1;
    $freshFail->writes = 0;
    $cleaned = false;
    try {
        $freshFail->importDrafts('alpha', [sample_item('left'), sample_item('right')], false);
    } catch (PublisherException $e) {
        $cleaned = $e->errorCode === 'import_failed';
    }
    assert_true($cleaned, 'New-file batch should fail closed');
    assert_true(!is_file($flakyRoot . '/alpha/items/left.json'), 'Cleanup should delete the draft written before the failure');
    assert_true(!is_file($flakyRoot . '/alpha/items/right.json'), 'Cleanup should not leave the failed draft');

    try {
        $publisher->publish('alpha', ['one'], false);
        throw new RuntimeException('Unreviewed publish should have been refused');
    } catch (PublisherException $e) {
        assert_same('unreviewed', $e->errorCode, 'Draft publish should be refused as unreviewed');
    }
    assert_true(!is_file($root . '/alpha/published.json'), 'Refused publish must not write a ledger');
    assert_true(!is_file($root . '/alpha/output/one.html'), 'Refused publish must not write output');

    $publisher->review('alpha', 'one');
    $reviewedAgain = $publisher->review('alpha', 'one');
    assert_same('reviewed', $reviewedAgain['status'], 'Reviewing twice should stay reviewed');
    $publisher->schedule('alpha', 'one', $now);
    try {
        $publisher->schedule('alpha', 'two', $now);
        throw new RuntimeException('Scheduling a draft should fail');
    } catch (PublisherException $e) {
        assert_same('unreviewed', $e->errorCode, 'Unreviewed items cannot be scheduled');
    }

    $beforeRun = snapshot_files($root);
    $preview = $publisher->runScheduled('alpha', true);
    assert_true($preview['dry_run'] === true, 'Scheduled preview should be a dry run');
    assert_same('one', $preview['sites'][0]['would_publish'][0]['id'], 'Preview should name the due item');
    assert_same($beforeRun, snapshot_files($root), 'Scheduled dry run must not write files');
    $stillScheduled = json_decode((string) file_get_contents($root . '/alpha/items/one.json'), true);
    assert_same('scheduled', $stillScheduled['status'], 'Dry run must leave the item scheduled');

    $published = $publisher->runScheduled('alpha', false);
    assert_same('one', $published['sites'][0]['published'][0]['id'], 'Scheduler should publish the due item');
    $output = (string) file_get_contents($root . '/alpha/output/one.html');
    assert_true(str_contains($output, 'ALPHA'), 'Output should use the site template');
    assert_true(!str_contains($output, 'BETA'), 'Output should not use another site template');
    $readback = hash('sha256', $output);
    assert_same($published['sites'][0]['published'][0]['output_hash'], $readback, 'Ledger hash should match the file read back from disk');
    $ledger = json_decode((string) file_get_contents($root . '/alpha/published.json'), true);
    assert_same('alpha', $ledger['site_id'], 'Ledger should belong to the publishing site');
    assert_same('2026-10-21', $ledger['entries'][0]['published_on'], 'Ledger should record the site-local day');
    assert_same(1, count($ledger['entries']), 'First publish should append one ledger entry');
    assert_true(!is_file($root . '/beta/published.json'), 'Another site should not receive a ledger');
    assert_true(!is_file($root . '/beta/output/one.html'), 'Another site should not receive the output');

    $outputHash = hash_file('sha256', $root . '/alpha/output/one.html');
    $publisher->schedule('alpha', 'one', $now);
    $guard = new Publisher($root, $clock, static function (string $path, string $raw): string {
        if (str_ends_with($path, '.html')) {
            return $raw . 'mutated';
        }
        return $raw;
    });
    $second = $guard->runScheduled('alpha', false);
    assert_same('duplicate_same_day', $second['sites'][0]['skipped'][0]['reason'], 'Same-day publish should be deduplicated');
    assert_same([], $second['sites'][0]['published'], 'Deduplicated run should not publish again');
    assert_same($outputHash, hash_file('sha256', $root . '/alpha/output/one.html'), 'Deduplicated run should leave the output bytes unchanged');
    $ledgerAgain = json_decode((string) file_get_contents($root . '/alpha/published.json'), true);
    assert_same(1, count($ledgerAgain['entries']), 'Same-day dedup should not add a ledger entry');

    $laClock = new FrozenPublisherClock(new DateTimeImmutable('2026-10-21T06:30:00Z'));
    $laPublisher = new Publisher($root, $laClock);
    $laPublisher->importDrafts('beta', [sample_item('west')], false);
    $laPublisher->review('beta', 'west');
    $laPublisher->schedule('beta', 'west', new DateTimeImmutable('2026-10-21T06:00:00Z'));
    $laPublisher->runScheduled('beta', false);
    $betaLedger = json_decode((string) file_get_contents($root . '/beta/published.json'), true);
    assert_same('2026-10-20', $betaLedger['entries'][0]['published_on'], 'Ledger day should follow the site timezone');
    assert_true(str_contains((string) file_get_contents($root . '/beta/output/west.html'), 'BETA'), 'Beta should render its own template');
    $alphaLedger = json_decode((string) file_get_contents($root . '/alpha/published.json'), true);
    assert_same('one', $alphaLedger['entries'][0]['id'], 'Alpha ledger should stay isolated');

    $original = "ORIGINAL\n";
    file_put_contents($root . '/alpha/output/three.html', $original);
    $originalHash = hash('sha256', $original);
    $publisher->importDrafts('alpha', [sample_item('three')], false);
    $publisher->review('alpha', 'three');
    $corrupt = new Publisher($root, $clock, static function (string $path, string $raw): string {
        if (str_ends_with($path, '.html')) {
            return $raw . 'CORRUPT';
        }
        return $raw;
    });
    $rolledBack = false;
    try {
        $corrupt->publish('alpha', ['three'], false);
    } catch (PublisherException $e) {
        $rolledBack = $e->errorCode === 'readback_failed';
    }
    assert_true($rolledBack, 'Readback mismatch should fail the publish');
    $restored = (string) file_get_contents($root . '/alpha/output/three.html');
    assert_same($original, $restored, 'Rollback should restore the previous output bytes');
    assert_same($originalHash, hash('sha256', $restored), 'Rollback should pass a hash check');
    $three = json_decode((string) file_get_contents($root . '/alpha/items/three.json'), true);
    assert_same('reviewed', $three['status'], 'Failed publish should not advance the item');
    $alphaAfter = json_decode((string) file_get_contents($root . '/alpha/published.json'), true);
    $alphaIds = array_column($alphaAfter['entries'], 'id');
    assert_true(!in_array('three', $alphaIds, true), 'Failed publish should not record a ledger entry');
    assert_same([], glob($root . '/alpha/output/.tmp-*') ?: [], 'Temporary files should not remain after rollback');

    $manual = json_decode((string) file_get_contents($root . '/alpha/items/two.json'), true);
    $manual['status'] = 'scheduled';
    $manual['publish_at'] = '2026-10-21T17:00:00+00:00';
    $manual['reviewed_at'] = null;
    file_put_contents($root . '/alpha/items/two.json', json_encode($manual, JSON_PRETTY_PRINT));
    $queue = $publisher->runScheduled('alpha', false);
    $reasons = array_column($queue['sites'][0]['skipped'], 'reason', 'id');
    assert_same('unreviewed', $reasons['two'] ?? '', 'Scheduler should refuse an unreviewed queue item');
    assert_true(!is_file($root . '/alpha/output/two.html'), 'Unreviewed queue item should not be written');

    $pending = ArticlePublisherBridge::toItem([
        'id' => 9,
        'title' => 'Pending note',
        'slug' => 'pending-note',
        'content' => 'Not reviewed yet.',
        'review_status' => 'pending',
    ]);
    assert_same('draft', $pending['status'], 'Pending articles should stay drafts in the file pipeline');
    $approved = ArticlePublisherBridge::toItem([
        'id' => 10,
        'title' => 'Approved note',
        'slug' => 'approved-note',
        'content' => 'Reviewed by a person.',
        'review_status' => 'approved',
    ]);
    assert_same('reviewed', $approved['status'], 'Approved articles should be eligible for review handoff');

    $cliSite = make_root();
    $cliPublisher = new Publisher($cliSite, $clock);
    $cliPublisher->initSite('cli', 'CLI', 'https://example.com');
    $cliPublisher->importDrafts('cli', [sample_item('queued')], false);
    $cliPublisher->review('cli', 'queued');
    $cliPublisher->schedule('cli', 'queued', $now);
    $beforeCli = snapshot_files($cliSite);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/publisher')
        . ' run --scheduled --dry-run --site cli --root ' . escapeshellarg($cliSite)
        . ' --now 2026-10-21T18:00:00Z';
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptor, $pipes);
    assert_true(is_resource($process), 'CLI process should start');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    assert_same(0, $exitCode, 'CLI dry run should exit 0. ' . $stderr);
    $payload = json_decode((string) $stdout, true);
    assert_true(($payload['ok'] ?? false) === true, 'CLI should return ok JSON');
    assert_same('queued', $payload['result']['sites'][0]['would_publish'][0]['id'], 'CLI preview should list the queued item');
    assert_same($beforeCli, snapshot_files($cliSite), 'CLI dry run must not write files');
    assert_true(!str_contains((string) $stdout . (string) $stderr, $cliSite), 'CLI preview should not echo the filesystem root');

    try {
        $publisher->initSite('../secret', 'Nope', 'https://example.com');
        throw new RuntimeException('Path traversal site id should fail');
    } catch (PublisherException $e) {
        assert_same('invalid_site', $e->errorCode, 'Site ids should be restricted to a safe slug');
    }

    fwrite(STDOUT, "publisher tests passed\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
} finally {
    remove_tree($root);
    if (isset($flakyRoot)) {
        remove_tree($flakyRoot);
    }
    if (isset($cliSite)) {
        remove_tree($cliSite);
    }
}
