<?php
declare(strict_types=1);

final class CacheArrayShare
{
    private string $shareConfigDir;
    private string $userShareDir;
    private string $sharesIni;
    private string $poolsConfigDir;
    private string $backupDir;
    private string $varIni;
    private string $emhttpSocket;
    private string $lockFile;
    private bool $testMode;

    public function __construct()
    {
        $this->shareConfigDir = getenv('CACHE_ARRAY_SHARE_CONFIG_DIR') ?: '/boot/config/shares';
        $this->userShareDir = getenv('CACHE_ARRAY_SHARE_USER_DIR') ?: '/mnt/user';
        $this->sharesIni = getenv('CACHE_ARRAY_SHARE_SHARES_INI') ?: '/var/local/emhttp/shares.ini';
        $this->poolsConfigDir = getenv('CACHE_ARRAY_SHARE_POOLS_DIR') ?: '/boot/config/pools';
        $this->backupDir = getenv('CACHE_ARRAY_SHARE_BACKUPS') ?: '/boot/config/plugins/cache-array-share/backups';
        $this->varIni = getenv('CACHE_ARRAY_SHARE_VAR_INI') ?: '/var/local/emhttp/var.ini';
        $this->emhttpSocket = getenv('CACHE_ARRAY_SHARE_SOCKET') ?: '/var/run/emhttpd.socket';
        $this->lockFile = getenv('CACHE_ARRAY_SHARE_LOCK') ?: '/var/lock/cache-array-share.lock';
        $this->testMode = getenv('CACHE_ARRAY_SHARE_TEST_MODE') === '1';
    }

    public function status(): array
    {
        $pools = $this->discoverPools();
        $shares = $this->discoverShares();
        $configured = 0;

        foreach ($shares as &$share) {
            $share['current_label'] = $this->storageLabel($share);
            $share['pool_to_array'] = $share['use_cache'] === 'yes' && $share['cache_pool'] !== '' && $share['cache_pool2'] === '';
            $share['sensitive'] = in_array(strtolower($share['name']), ['appdata', 'domains', 'system'], true);
            if ($share['pool_to_array']) {
                $configured++;
            }
        }
        unset($share);

        return [
            'counts' => ['total' => count($shares), 'configured' => $configured, 'pools' => count($pools)],
            'pools' => $pools,
            'shares' => array_values($shares),
            'can_restore' => $this->latestRestorableBackup() !== null,
        ];
    }

    public function applySelection(array $requestedNames, string $pool): array
    {
        return $this->withLock(function () use ($requestedNames, $pool): array {
            if (!$this->validName($pool) || !in_array($pool, $this->discoverPools(), true)) {
                throw new InvalidArgumentException('Choose an available cache pool.');
            }

            $selected = [];
            foreach ($requestedNames as $name) {
                $name = (string)$name;
                if (!$this->validName($name)) {
                    throw new InvalidArgumentException('The share selection contains an invalid name.');
                }
                $selected[$name] = true;
            }
            if (!$selected) {
                throw new InvalidArgumentException('Select at least one share.');
            }

            $available = [];
            foreach ($this->discoverShares() as $share) {
                $available[$share['name']] = $share;
            }

            $targets = [];
            foreach (array_keys($selected) as $name) {
                if (!isset($available[$name])) {
                    throw new InvalidArgumentException('Share "' . $name . '" no longer exists. Refresh the page and try again.');
                }
                $share = $available[$name];
                if ($share['use_cache'] !== 'yes' || $share['cache_pool'] !== $pool || $share['cache_pool2'] !== '') {
                    $targets[] = $share;
                }
            }

            if (!$targets) {
                return ['changed' => 0, 'backup' => null, 'pool' => $pool];
            }

            $this->assertRuntimeReady();
            $backup = $this->createBackup('apply', $pool, $targets);
            $this->pruneBackups(30);
            $completed = [];

            try {
                foreach ($targets as $share) {
                    $this->applyStoragePolicy($share['name'], 'yes', $pool, '');
                    $completed[] = $share;
                }
            } catch (Throwable $error) {
                $rollbackErrors = $this->rollback($completed);
                $suffix = $rollbackErrors
                    ? ' Rollback also failed for: ' . implode(', ', $rollbackErrors) . '.'
                    : ' Earlier changes were rolled back.';
                throw new RuntimeException($error->getMessage() . $suffix);
            }

            return ['changed' => count($completed), 'backup' => basename($backup), 'pool' => $pool];
        });
    }

    public function restoreLatest(): array
    {
        return $this->withLock(function (): array {
            $backup = $this->latestRestorableBackup();
            if ($backup === null) {
                return ['changed' => 0, 'backup' => null];
            }

            $manifestFile = $backup . '/manifest.json';
            $manifest = json_decode((string)file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
            $targets = is_array($manifest['targets'] ?? null) ? $manifest['targets'] : [];
            if (!$targets) {
                return ['changed' => 0, 'backup' => basename($backup)];
            }

            $this->assertRuntimeReady();
            $restored = [];
            foreach ($targets as $target) {
                $this->applyStoragePolicy(
                    (string)$target['name'],
                    (string)$target['use_cache'],
                    (string)$target['cache_pool'],
                    (string)$target['cache_pool2']
                );
                $restored[] = (string)$target['name'];
            }

            $manifest['restored_at'] = gmdate('c');
            $this->atomicWrite($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
            return ['changed' => count($restored), 'backup' => basename($backup)];
        });
    }

    private function discoverPools(): array
    {
        $pools = [];
        foreach (glob($this->poolsConfigDir . '/*.cfg', GLOB_NOSORT) ?: [] as $file) {
            $name = basename($file, '.cfg');
            if ($this->validName($name)) {
                $pools[$name] = true;
            }
        }
        $names = array_keys($pools);
        natcasesort($names);
        return array_values($names);
    }

    private function discoverShares(): array
    {
        $names = [];
        if (is_file($this->sharesIni)) {
            foreach (array_keys(parse_ini_file($this->sharesIni, true) ?: []) as $name) {
                $name = (string)$name;
                if (strtolower($name) !== 'flash' && $this->validName($name)) {
                    $names[$name] = true;
                }
            }
        }
        if (is_dir($this->userShareDir)) {
            foreach (new DirectoryIterator($this->userShareDir) as $entry) {
                if (!$entry->isDot() && $entry->isDir()) {
                    $name = $entry->getFilename();
                    if (strtolower($name) !== 'flash' && $this->validName($name)) {
                        $names[$name] = true;
                    }
                }
            }
        }

        uksort($names, 'strnatcasecmp');
        $shares = [];
        foreach (array_keys($names) as $name) {
            $file = $this->shareConfigDir . '/' . $name . '.cfg';
            $cfg = is_file($file) ? (parse_ini_file($file) ?: []) : [];
            $shares[] = [
                'name' => $name,
                'use_cache' => $this->normaliseUseCache((string)($cfg['shareUseCache'] ?? 'no')),
                'cache_pool' => (string)($cfg['shareCachePool'] ?? ''),
                'cache_pool2' => (string)($cfg['shareCachePool2'] ?? ''),
                'has_config' => is_file($file),
            ];
        }
        return $shares;
    }

    private function applyStoragePolicy(string $name, string $useCache, string $cachePool, string $cachePool2): void
    {
        if (!$this->validName($name)) {
            throw new InvalidArgumentException('Invalid share name.');
        }
        $useCache = $this->normaliseUseCache($useCache);
        foreach ([$cachePool, $cachePool2] as $pool) {
            if ($pool !== '' && !$this->validName($pool)) {
                throw new InvalidArgumentException('Invalid pool name.');
            }
        }

        $file = $this->shareConfigDir . '/' . $name . '.cfg';
        $cfg = is_file($file) ? (parse_ini_file($file) ?: []) : [];

        if ($this->testMode) {
            $this->patchConfig($file, [
                'shareUseCache' => $useCache,
                'shareCachePool' => $cachePool,
                'shareCachePool2' => $cachePool2,
            ]);
        } else {
            $var = parse_ini_file($this->varIni) ?: [];
            $token = (string)($var['csrf_token'] ?? '');
            if ($token === '') {
                throw new RuntimeException('Unraid CSRF token is unavailable; no changes were made.');
            }

            $post = http_build_query([
                'shareName' => $name,
                'shareNameOrig' => $name,
                'shareAllocator' => (string)($cfg['shareAllocator'] ?? 'highwater'),
                'shareFloor' => (string)($cfg['shareFloor'] ?? '0'),
                'shareSplitLevel' => (string)($cfg['shareSplitLevel'] ?? ''),
                'shareInclude' => (string)($cfg['shareInclude'] ?? ''),
                'shareExclude' => (string)($cfg['shareExclude'] ?? ''),
                'shareUseCache' => $useCache,
                'shareCachePool' => $cachePool,
                'shareCachePool2' => $cachePool2,
                'cmdEditShare' => 'Apply',
                'csrf_token' => $token,
            ], '', '&', PHP_QUERY_RFC3986);

            $command = '/usr/bin/curl --silent --show-error --fail --max-time 30'
                . ' --unix-socket ' . escapeshellarg($this->emhttpSocket)
                . ' --data ' . escapeshellarg($post)
                . ' ' . escapeshellarg('http://localhost/update.htm') . ' 2>&1';
            exec($command, $output, $status);
            if ($status !== 0) {
                throw new RuntimeException('Unraid rejected the storage update for share "' . $name . '": ' . trim(implode("\n", $output)));
            }
        }

        clearstatcache(true, $file);
        $verified = is_file($file) ? (parse_ini_file($file) ?: []) : [];
        if (($verified['shareUseCache'] ?? null) !== $useCache
            || (string)($verified['shareCachePool'] ?? '') !== $cachePool
            || (string)($verified['shareCachePool2'] ?? '') !== $cachePool2) {
            throw new RuntimeException('The storage update for share "' . $name . '" could not be verified.');
        }
    }

    private function rollback(array $completed): array
    {
        $errors = [];
        foreach (array_reverse($completed) as $share) {
            try {
                $this->applyStoragePolicy($share['name'], $share['use_cache'], $share['cache_pool'], $share['cache_pool2']);
            } catch (Throwable $error) {
                $errors[] = $share['name'];
            }
        }
        return $errors;
    }

    private function createBackup(string $action, string $pool, array $targets): string
    {
        $path = $this->backupDir . '/' . gmdate('Ymd-His') . '-' . $action . '-' . bin2hex(random_bytes(2));
        $filesPath = $path . '/shares';
        if (!mkdir($filesPath, 0700, true) && !is_dir($filesPath)) {
            throw new RuntimeException('Unable to create the share configuration backup.');
        }

        foreach (glob($this->shareConfigDir . '/*.cfg', GLOB_NOSORT) ?: [] as $file) {
            if (!copy($file, $filesPath . '/' . basename($file))) {
                throw new RuntimeException('Unable to back up ' . basename($file) . '.');
            }
        }

        $originals = array_map(static fn(array $share): array => [
            'name' => $share['name'],
            'use_cache' => $share['use_cache'],
            'cache_pool' => $share['cache_pool'],
            'cache_pool2' => $share['cache_pool2'],
        ], $targets);
        $manifest = [
            'created_at' => gmdate('c'),
            'action' => $action,
            'target_pool' => $pool,
            'targets' => $originals,
        ];
        $this->atomicWrite($path . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
        return $path;
    }

    private function latestRestorableBackup(): ?string
    {
        $paths = glob($this->backupDir . '/*/manifest.json', GLOB_NOSORT) ?: [];
        usort($paths, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach ($paths as $manifestFile) {
            $manifest = json_decode((string)@file_get_contents($manifestFile), true);
            if (is_array($manifest) && empty($manifest['restored_at']) && !empty($manifest['targets'])) {
                return dirname($manifestFile);
            }
        }
        return null;
    }

    private function pruneBackups(int $keep): void
    {
        $paths = glob($this->backupDir . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [];
        usort($paths, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($paths, $keep) as $path) {
            $this->removeBackupTree($path);
        }
    }

    private function removeBackupTree(string $path): void
    {
        $root = realpath($this->backupDir);
        $target = realpath($path);
        if ($root === false || $target === false || $target === $root || !str_starts_with($target . '/', rtrim($root, '/') . '/')) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($target);
    }

    private function assertRuntimeReady(): void
    {
        if ($this->testMode) {
            return;
        }
        if (!file_exists($this->emhttpSocket) || !is_file($this->varIni)) {
            throw new RuntimeException('The Unraid management service is not ready. Start the array and try again.');
        }
        if (!is_executable('/usr/bin/curl')) {
            throw new RuntimeException('The curl command required to update Unraid is unavailable.');
        }
    }

    private function withLock(callable $operation): array
    {
        $directory = dirname($this->lockFile);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the operation lock directory.');
        }
        $handle = fopen($this->lockFile, 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Another Cache -> Array operation is already running.');
        }
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function patchConfig(string $path, array $changes): void
    {
        $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $seen = [];
        foreach ($lines as &$line) {
            if (preg_match('/^([A-Za-z][A-Za-z0-9_]*)=/', $line, $match) && array_key_exists($match[1], $changes)) {
                $key = $match[1];
                $line = $key . '="' . $this->iniEscape((string)$changes[$key]) . '"';
                $seen[$key] = true;
            }
        }
        unset($line);
        foreach ($changes as $key => $value) {
            if (!isset($seen[$key])) {
                $lines[] = $key . '="' . $this->iniEscape((string)$value) . '"';
            }
        }
        $this->atomicWrite($path, implode("\n", $lines) . "\n", 0644);
    }

    private function atomicWrite(string $path, string $content, int $mode): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create ' . $directory . '.');
        }
        $temporary = tempnam($directory, '.cachearray-');
        if ($temporary === false || file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write ' . basename($path) . '.');
        }
        chmod($temporary, $mode);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to replace ' . basename($path) . '.');
        }
    }

    private function storageLabel(array $share): string
    {
        $pool = $share['cache_pool'] !== '' ? $share['cache_pool'] : 'Pool';
        return match ($share['use_cache']) {
            'yes' => $pool . ' -> Array',
            'prefer' => 'Array -> ' . $pool,
            'only' => $pool . ' only',
            default => 'Array only',
        };
    }

    private function normaliseUseCache(string $value): string
    {
        return in_array($value, ['no', 'yes', 'only', 'prefer'], true) ? $value : 'no';
    }

    private function validName(string $name): bool
    {
        return $name !== '' && $name !== '.' && $name !== '..' && !str_contains($name, '/') && !preg_match('/[\x00-\x1F\x7F]/', $name);
    }

    private function iniEscape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $controller = new CacheArrayShare();
        $command = $argv[1] ?? '--status';
        if ($command === '--status') {
            $result = $controller->status();
        } elseif ($command === '--restore') {
            $result = $controller->restoreLatest();
        } else {
            throw new InvalidArgumentException('Usage: cache-array-share.php [--status|--restore]');
        }
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'cache-array-share: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
