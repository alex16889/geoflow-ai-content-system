<?php
/**
 * Atomic file batch. Each put is read back and hashed. rollback() restores
 * the pre-image and checks that the restored bytes hash to the snapshot.
 */

declare(strict_types=1);

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class FileTransaction
{
    /** @var array<string, array{existed: bool, bytes: string, hash: ?string}> */
    private array $tracked = [];

    /** @var callable|null */
    private $readbackMutator;

    public function __construct(?callable $readbackMutator = null)
    {
        $this->readbackMutator = $readbackMutator;
    }

    public function put(string $path, string $contents): void
    {
        $this->track($path);
        $this->atomicWrite($path, $contents);
        $actual = $this->readForVerify($path);
        if (hash('sha256', $actual) !== hash('sha256', $contents)) {
            throw new PublisherException('Published output failed readback verification', 'readback_failed');
        }
    }

    public function commit(): void
    {
        $this->tracked = [];
    }

    public function rollback(): void
    {
        $entries = array_reverse($this->tracked, true);
        foreach ($entries as $path => $snapshot) {
            $this->restore($path, $snapshot);
        }
        $this->tracked = [];
    }

    private function track(string $path): void
    {
        if (isset($this->tracked[$path])) {
            return;
        }

        if (is_file($path)) {
            $bytes = file_get_contents($path);
            if ($bytes === false) {
                throw new PublisherException('Unable to snapshot existing output', 'snapshot_failed');
            }
            $this->tracked[$path] = [
                'existed' => true,
                'bytes' => $bytes,
                'hash' => hash('sha256', $bytes),
            ];
            return;
        }

        if (is_dir($path)) {
            throw new PublisherException('Output path is a directory', 'write_failed');
        }

        $this->tracked[$path] = [
            'existed' => false,
            'bytes' => '',
            'hash' => null,
        ];
    }

    private function readForVerify(string $path): string
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new PublisherException('Published output failed readback verification', 'readback_failed');
        }

        if ($this->readbackMutator !== null) {
            $raw = ($this->readbackMutator)($path, $raw);
            if (!is_string($raw)) {
                throw new PublisherException('Published output failed readback verification', 'readback_failed');
            }
        }

        return $raw;
    }

    private function restore(string $path, array $snapshot): void
    {
        if (!$snapshot['existed']) {
            if (is_file($path) && !unlink($path)) {
                throw new PublisherException('Rollback failed hash verification', 'rollback_failed');
            }
            if (is_file($path)) {
                throw new PublisherException('Rollback failed hash verification', 'rollback_failed');
            }
            return;
        }

        $this->atomicWrite($path, $snapshot['bytes']);
        $restored = file_get_contents($path);
        if ($restored === false || hash('sha256', $restored) !== $snapshot['hash']) {
            throw new PublisherException('Rollback failed hash verification', 'rollback_failed');
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new PublisherException('Unable to prepare output directory', 'write_failed');
        }

        $temporary = $directory . '/.tmp-' . bin2hex(random_bytes(8));
        $handle = fopen($temporary, 'wb');
        if ($handle === false) {
            throw new PublisherException('Unable to write temporary output', 'write_failed');
        }

        try {
            if (fwrite($handle, $contents) === false || !fflush($handle)) {
                throw new PublisherException('Unable to write temporary output', 'write_failed');
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($temporary);
            if ($e instanceof PublisherException) {
                throw $e;
            }
            throw new PublisherException('Unable to write temporary output', 'write_failed');
        }
        fclose($handle);

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new PublisherException('Unable to commit output', 'write_failed');
        }
    }
}
