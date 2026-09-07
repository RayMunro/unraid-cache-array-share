<?php
// Copyright (c) 2026 Raymond Munro
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
    private string $jobsDir;
    private string $mountRoot;
    private string $moverPid;
    private string $phpBinary;
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
        $this->jobsDir = getenv('CACHE_ARRAY_SHARE_JOBS') ?: '/var/local/emhttp/plugins/cache-array-share/jobs';
        $this->mountRoot = getenv('CACHE_ARRAY_SHARE_MOUNT_ROOT') ?: '/mnt';
        $this->moverPid = getenv('CACHE_ARRAY_SHARE_MOVER_PID') ?: '/var/run/mover.pid';
        $this->phpBinary = getenv('CACHE_ARRAY_SHARE_PHP') ?: '/usr/bin/php';
        $this->testMode = getenv('CACHE_ARRAY_SHARE_TEST_MODE') === '1';
    }

    public function status(): array
    {
        $pools = $this->discoverPools();
        $shares = $this->discoverShares();
        $systemDependent = $this->systemDependentShareMap();
        $configured = 0;
        $noCache = 0;
        $cacheOnly = 0;

        foreach ($shares as &$share) {
            $share['current_label'] = $this->storageLabel($share);
            $share['pool_to_array'] = $share['use_cache'] === 'yes' && $share['cache_pool'] !== '' && $share['cache_pool2'] === '';
            $share['system_dependent'] = isset($systemDependent[strtolower($share['name'])]);
            $share['sensitive'] = $share['system_dependent'];
            $share['bulk_selectable'] = !$share['system_dependent'];
            if ($share['pool_to_array']) {
                $configured++;
            }
            if ($share['use_cache'] === 'no' && $share['cache_pool'] === '' && $share['cache_pool2'] === '') {
                $noCache++;
            }
            if ($share['use_cache'] === 'only' && $share['cache_pool'] !== '' && $share['cache_pool2'] === '') {
                $cacheOnly++;
            }
        }
        unset($share);

        return [
            'counts' => [
                'total' => count($shares),
                'configured' => $configured,
                'no_cache' => $noCache,
                'cache_only' => $cacheOnly,
                'pools' => count($pools),
            ],
            'pools' => $pools,
            'shares' => array_values($shares),
            'can_restore' => $this->latestRestorableBackup() !== null,
            'active_job' => $this->latestActiveJob(),
        ];
    }

    private function systemDependentShareMap(): array
    {
        $dependent = array_fill_keys(['appdata', 'domains', 'system', 'isos'], true);
        $configRoot = dirname($this->shareConfigDir);
        foreach ([$configRoot . '/docker.cfg', $configRoot . '/domain.cfg'] as $file) {
            if (!is_file($file)) {
                continue;
            }
            foreach ((parse_ini_file($file) ?: []) as $value) {
                if (!is_string($value)) {
                    continue;
                }
                if (preg_match_all('#/mnt/(?:user0?|[^/]+)/([^/]+)#', $value, $matches)) {
                    foreach ($matches[1] as $name) {
                        $name = rtrim((string)$name);
                        if ($this->validName($name)) {
                            $dependent[strtolower($name)] = true;
                        }
                    }
                }
            }
        }
        return $dependent;
    }

    public function applySelection(array $requestedNames, string $pool): array
    {
        return $this->applyPolicies($requestedNames, [], $pool);
    }

    public function applyPolicies(array $cacheNames, array $noCacheNames, string $pool, array $cacheOnlyNames = []): array
    {
        return $this->withLock(function () use ($cacheNames, $noCacheNames, $pool, $cacheOnlyNames): array {
            if ($this->latestActiveJob() !== null) {
                throw new RuntimeException('A Mover operation is already running. Wait for it to finish.');
            }
            $cacheSelected = $this->selectionMap($cacheNames);
            $noCacheSelected = $this->selectionMap($noCacheNames);
            $cacheOnlySelected = $this->selectionMap($cacheOnlyNames);

            if (!$cacheSelected && !$noCacheSelected && !$cacheOnlySelected) {
                throw new InvalidArgumentException('Tick Cache -> Array, Cache only, or Array only for at least one share.');
            }
            if ($noCacheSelected) {
                throw new InvalidArgumentException('Array-only changes must run through the Mover safety operation.');
            }
            if (
                array_intersect_key($cacheSelected, $noCacheSelected)
                || array_intersect_key($cacheSelected, $cacheOnlySelected)
                || array_intersect_key($noCacheSelected, $cacheOnlySelected)
            ) {
                throw new InvalidArgumentException('A share cannot be selected for more than one storage policy.');
            }
            if (($cacheSelected || $cacheOnlySelected) && (!$this->validName($pool) || !in_array($pool, $this->discoverPools(), true))) {
                throw new InvalidArgumentException('Choose an available cache pool.');
            }

            $available = [];
            foreach ($this->discoverShares() as $share) {
                $available[$share['name']] = $share;
            }

            $targets = [];
            foreach ($cacheSelected as $name => $_selected) {
                if (!isset($available[$name])) {
                    throw new InvalidArgumentException('Share "' . $name . '" no longer exists. Refresh the page and try again.');
                }
                $share = $available[$name];
                if ($share['use_cache'] !== 'yes' || $share['cache_pool'] !== $pool || $share['cache_pool2'] !== '') {
                    $share['desired_use_cache'] = 'yes';
                    $share['desired_cache_pool'] = $pool;
                    $share['desired_cache_pool2'] = '';
                    $share['desired_action'] = 'cache_to_array';
                    $targets[] = $share;
                }
            }
            foreach ($cacheOnlySelected as $name => $_selected) {
                if (!isset($available[$name])) {
                    throw new InvalidArgumentException('Share "' . $name . '" no longer exists. Refresh the page and try again.');
                }
                $share = $available[$name];
                if ($share['use_cache'] !== 'only' || $share['cache_pool'] !== $pool || $share['cache_pool2'] !== '') {
                    $share['desired_use_cache'] = 'only';
                    $share['desired_cache_pool'] = $pool;
                    $share['desired_cache_pool2'] = '';
                    $share['desired_action'] = 'cache_only';
                    $targets[] = $share;
                }
            }

            if (!$targets) {
                return [
                    'changed' => 0,
                    'changed_cache' => 0,
                    'changed_no_cache' => 0,
                    'changed_cache_only' => 0,
                    'backup' => null,
                    'pool' => ($cacheSelected || $cacheOnlySelected) ? $pool : '',
                ];
            }

            $this->assertRuntimeReady();
            $backup = $this->createBackup('apply-policies', ($cacheSelected || $cacheOnlySelected) ? $pool : '', $targets);
            $this->pruneBackups(30);
            $completed = [];
            $changedCache = 0;
            $changedNoCache = 0;
            $changedCacheOnly = 0;

            try {
                foreach ($targets as $share) {
                    $this->applyStoragePolicy(
                        $share['name'],
                        $share['desired_use_cache'],
                        $share['desired_cache_pool'],
                        $share['desired_cache_pool2']
                    );
                    $completed[] = $share;
                    if ($share['desired_action'] === 'cache_to_array') {
                        $changedCache++;
                    } elseif ($share['desired_action'] === 'cache_only') {
                        $changedCacheOnly++;
                    } else {
                        $changedNoCache++;
                    }
                }
            } catch (Throwable $error) {
                $rollbackErrors = $this->rollback($completed);
                $suffix = $rollbackErrors
                    ? ' Rollback also failed for: ' . implode(', ', $rollbackErrors) . '.'
                    : ' Earlier changes were rolled back.';
                throw new RuntimeException($error->getMessage() . $suffix);
            }

            return [
                'changed' => count($completed),
                'changed_cache' => $changedCache,
                'changed_no_cache' => $changedNoCache,
                'changed_cache_only' => $changedCacheOnly,
                'backup' => basename($backup),
                'pool' => ($cacheSelected || $cacheOnlySelected) ? $pool : '',
            ];
        });
    }

    public function startPolicyJob(array $cacheNames, array $noCacheNames, string $pool, array $forceNames = [], array $cacheOnlyNames = []): array
    {
        $job = $this->withLock(function () use ($cacheNames, $noCacheNames, $pool, $forceNames, $cacheOnlyNames): array {
            if ($this->latestActiveJob() !== null) {
                throw new RuntimeException('A Mover operation is already running. Wait for it to finish.');
            }

            $cacheSelected = $this->selectionMap($cacheNames);
            $noCacheSelected = $this->selectionMap($noCacheNames);
            $forceSelected = $this->selectionMap($forceNames);
            $cacheOnlySelected = $this->selectionMap($cacheOnlyNames);
            if (!$noCacheSelected) {
                throw new InvalidArgumentException('Select Array only for at least one share.');
            }
            if (
                array_intersect_key($cacheSelected, $noCacheSelected)
                || array_intersect_key($cacheSelected, $cacheOnlySelected)
                || array_intersect_key($noCacheSelected, $cacheOnlySelected)
            ) {
                throw new InvalidArgumentException('A share cannot be selected for more than one storage policy.');
            }
            if (array_diff_key($forceSelected, $noCacheSelected)) {
                throw new InvalidArgumentException('Force remaining files can only be selected with Array only for the same share.');
            }

            $pools = $this->discoverPools();
            if (($cacheSelected || $cacheOnlySelected) && (!$this->validName($pool) || !in_array($pool, $pools, true))) {
                throw new InvalidArgumentException('Choose an available cache pool.');
            }
            if ($noCacheSelected && $pools && (!$this->validName($pool) || !in_array($pool, $pools, true))) {
                throw new InvalidArgumentException('Choose an available cache pool for the Mover safety pass.');
            }

            $available = [];
            foreach ($this->discoverShares() as $share) {
                $available[$share['name']] = true;
            }
            foreach (array_keys($cacheSelected + $noCacheSelected + $cacheOnlySelected) as $name) {
                if (!isset($available[$name])) {
                    throw new InvalidArgumentException('Share "' . $name . '" no longer exists. Refresh the page and try again.');
                }
            }

            $jobId = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $job = [
                'id' => $jobId,
                'status' => 'queued',
                'terminal' => false,
                'message' => 'Preparing the selected shares for Mover.',
                'created_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
                'pool' => $pools ? $pool : '',
                'cache_shares' => array_keys($cacheSelected),
                'no_cache_shares' => array_keys($noCacheSelected),
                'cache_only_shares' => array_keys($cacheOnlySelected),
                'force_shares' => array_keys($forceSelected),
                'forced_shares' => [],
                'mover_runs' => 0,
            ];
            $this->writeJob($job);
            return $job;
        });

        if (!$this->testMode) {
            if (!is_executable($this->phpBinary)) {
                $job['status'] = 'failed';
                $job['terminal'] = true;
                $job['error'] = 'The PHP command required to run the background operation is unavailable.';
                $job['message'] = $job['error'];
                $this->writeJob($job);
                throw new RuntimeException($job['error']);
            }
            $command = 'nohup ' . escapeshellarg($this->phpBinary)
                . ' ' . escapeshellarg(__FILE__)
                . ' --run-job ' . escapeshellarg($job['id'])
                . ' >/dev/null 2>&1 &';
            exec($command, $output, $status);
            if ($status !== 0) {
                $job['status'] = 'failed';
                $job['terminal'] = true;
                $job['error'] = 'Unable to start the background Mover operation.';
                $job['message'] = $job['error'];
                $this->writeJob($job);
                throw new RuntimeException($job['error']);
            }
        }

        return ['queued' => true, 'job' => $job['id']];
    }

    public function runPolicyJob(string $jobId): array
    {
        return $this->withLock(function () use ($jobId): array {
            $job = $this->loadJob($jobId);
            if (!empty($job['terminal'])) {
                return $job;
            }

            try {
                $job = $this->updateJob($job, 'preparing', 'Backing up the original share policies.');
                $cacheSelected = $this->selectionMap((array)$job['cache_shares']);
                $noCacheSelected = $this->selectionMap((array)$job['no_cache_shares']);
                $cacheOnlySelected = $this->selectionMap((array)($job['cache_only_shares'] ?? []));
                $forceSelected = $this->selectionMap((array)($job['force_shares'] ?? []));
                if (
                    array_intersect_key($cacheSelected, $noCacheSelected)
                    || array_intersect_key($cacheSelected, $cacheOnlySelected)
                    || array_intersect_key($noCacheSelected, $cacheOnlySelected)
                ) {
                    throw new RuntimeException('A share has more than one final storage policy in this operation.');
                }
                if (array_diff_key($forceSelected, $noCacheSelected)) {
                    throw new RuntimeException('The force selection is not valid for this operation.');
                }
                $pool = (string)$job['pool'];
                $pools = $this->discoverPools();

                $available = [];
                foreach ($this->discoverShares() as $share) {
                    $available[$share['name']] = $share;
                }

                $originals = [];
                foreach (array_keys($cacheSelected + $noCacheSelected + $cacheOnlySelected) as $name) {
                    if (!isset($available[$name])) {
                        throw new RuntimeException('Share "' . $name . '" no longer exists.');
                    }
                    $originals[] = $available[$name];
                }

                $this->assertRuntimeReady();
                $backup = $this->createBackup('mover-then-array-only', $pool, $originals);
                $this->pruneBackups(30);
                $job['backup'] = basename($backup);
                $this->writeJob($job);

                if ($pools) {
                    $this->assertPoolsMounted($pools);
                    if (is_file($this->moverPid)) {
                        $job = $this->updateJob($job, 'waiting_mover', 'Waiting for the currently running Mover operation to finish.');
                        $this->waitForMover($job, true);
                    }

                    $maximumPasses = max(1, count($pools) + 1);
                    for ($pass = 1; $pass <= $maximumPasses; $pass++) {
                        $before = $this->sharesWithPoolFiles(array_keys($noCacheSelected), $pools);
                        $job = $this->updateJob(
                            $job,
                            'preparing_mover',
                            sprintf('Preparing Mover pass %d. Array-only has not been applied yet.', $pass)
                        );
                        foreach (array_keys($noCacheSelected) as $name) {
                            $share = $available[$name];
                            $sourcePool = $this->sourcePoolForShare($name, $pools, $share['cache_pool'], $pool);
                            if ($sourcePool === '') {
                                throw new RuntimeException('No cache pool is available to empty for share "' . $name . '".');
                            }
                            $this->applyStoragePolicy($name, 'yes', $sourcePool, '');
                        }

                        $job = $this->updateJob($job, 'starting_mover', 'Starting Mover before Array-only is applied.');
                        $this->startMover();
                        $job['mover_runs'] = (int)$job['mover_runs'] + 1;
                        $this->writeJob($job);
                        $job = $this->updateJob($job, 'running_mover', 'Mover is running. Array-only has not been applied yet.');
                        $this->waitForMover($job, false);

                        $job = $this->updateJob($job, 'verifying', 'Mover finished. Checking the selected shares for files remaining on pools.');
                        $remainingByShare = $this->poolFilesByShare(array_keys($noCacheSelected), $pools);
                        $forceNow = array_intersect_key($remainingByShare, $forceSelected);
                        if ($forceNow) {
                            $job = $this->updateJob($job, 'forcing', 'Mover left files behind. Forcing the authorised shares directly to the Array.');
                            $this->forcePoolFilesToArray($forceNow, $job);
                            $job['forced_shares'] = array_values(array_unique(array_merge(
                                (array)($job['forced_shares'] ?? []),
                                array_keys($forceNow)
                            )));
                            $this->writeJob($job);
                            $remainingByShare = $this->poolFilesByShare(array_keys($noCacheSelected), $pools);
                        }
                        $remaining = $this->poolFileLabels($remainingByShare);
                        if (!$remaining) {
                            break;
                        }
                        if ($remaining === $before || $pass === $maximumPasses) {
                            throw new RuntimeException(
                                'Array-only was not applied because files remain on a pool for: '
                                . implode(', ', $remaining)
                                . '. Their temporary Pool -> Array policy has been left in place so Mover can be run again safely.'
                            );
                        }
                    }
                }

                $job = $this->updateJob($job, 'applying', 'Pools are clear. Applying the selected final policies.');
                $completed = [];
                $changedCache = 0;
                $changedNoCache = 0;
                $changedCacheOnly = 0;
                try {
                    foreach (array_keys($cacheSelected) as $name) {
                        $share = $available[$name];
                        if ($share['use_cache'] !== 'yes' || $share['cache_pool'] !== $pool || $share['cache_pool2'] !== '') {
                            $this->applyStoragePolicy($name, 'yes', $pool, '');
                            $completed[] = $share;
                            $changedCache++;
                        }
                    }
                    foreach (array_keys($noCacheSelected) as $name) {
                        $share = $available[$name];
                        $this->applyStoragePolicy($name, 'no', '', '');
                        $completed[] = $share;
                        $changedNoCache++;
                    }
                    foreach (array_keys($cacheOnlySelected) as $name) {
                        $share = $available[$name];
                        if ($share['use_cache'] !== 'only' || $share['cache_pool'] !== $pool || $share['cache_pool2'] !== '') {
                            $this->applyStoragePolicy($name, 'only', $pool, '');
                            $completed[] = $share;
                            $changedCacheOnly++;
                        }
                    }
                } catch (Throwable $error) {
                    $rollbackErrors = $this->rollback($completed);
                    $suffix = $rollbackErrors
                        ? ' Rollback also failed for: ' . implode(', ', $rollbackErrors) . '.'
                        : ' Completed final changes were rolled back.';
                    throw new RuntimeException($error->getMessage() . $suffix);
                }

                $job['status'] = 'completed';
                $job['terminal'] = true;
                $job['message'] = sprintf(
                    '%d share(s) changed after Mover completed: %d Cache -> Array, %d Array-only, %d Cache only, %d forced to the Array.',
                    count($completed),
                    $changedCache,
                    $changedNoCache,
                    $changedCacheOnly,
                    count((array)$job['forced_shares'])
                );
                $job['result'] = [
                    'changed' => count($completed),
                    'changed_cache' => $changedCache,
                    'changed_no_cache' => $changedNoCache,
                    'changed_cache_only' => $changedCacheOnly,
                    'backup' => $job['backup'],
                    'mover_runs' => $job['mover_runs'],
                    'forced_shares' => (array)$job['forced_shares'],
                ];
                $job['updated_at'] = gmdate('c');
                $this->writeJob($job);
            } catch (Throwable $error) {
                if (!empty($job['backup'])) {
                    try {
                        $this->blockBackupRestore((string)$job['backup'], $error->getMessage());
                    } catch (Throwable $backupError) {
                        error_log('cache-array-share: unable to mark failed operation backup: ' . $backupError->getMessage());
                    }
                }
                $job['status'] = 'failed';
                $job['terminal'] = true;
                $job['error'] = $error->getMessage();
                $job['message'] = $error->getMessage();
                $job['updated_at'] = gmdate('c');
                $this->writeJob($job);
            }

            return $job;
        });
    }

    public function jobStatus(string $jobId): array
    {
        return $this->loadJob($jobId);
    }

    public function restoreLatest(): array
    {
        return $this->withLock(function (): array {
            if ($this->latestActiveJob() !== null) {
                throw new RuntimeException('A Mover operation is running. Restore is available after it finishes.');
            }
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

    private function startMover(): void
    {
        if ($this->testMode) {
            return;
        }

        $var = parse_ini_file($this->varIni) ?: [];
        $token = (string)($var['csrf_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Unraid CSRF token is unavailable; Mover was not started.');
        }

        $post = http_build_query([
            'cmdStartMover' => 'Move',
            'csrf_token' => $token,
        ], '', '&', PHP_QUERY_RFC3986);
        $command = '/usr/bin/curl --silent --show-error --fail --max-time 30'
            . ' --unix-socket ' . escapeshellarg($this->emhttpSocket)
            . ' --data ' . escapeshellarg($post)
            . ' ' . escapeshellarg('http://localhost/update.htm') . ' 2>&1';
        exec($command, $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('Unraid rejected the request to start Mover: ' . trim(implode("\n", $output)));
        }
    }

    private function waitForMover(array &$job, bool $alreadyRunning): void
    {
        if ($this->testMode) {
            return;
        }

        $started = time();
        $deadline = $started + 43200;
        $appearanceDeadline = microtime(true) + 20;
        $seenRunning = $alreadyRunning;
        $lastUpdate = 0;

        while (true) {
            $running = is_file($this->moverPid);
            if ($running) {
                $seenRunning = true;
            }
            if ($seenRunning && !$running) {
                return;
            }
            if (!$seenRunning && microtime(true) >= $appearanceDeadline) {
                return;
            }
            if (time() >= $deadline) {
                throw new RuntimeException('Mover did not finish within 12 hours. Array-only was not applied.');
            }

            if (time() - $lastUpdate >= 5) {
                $elapsed = time() - $started;
                $job['message'] = sprintf('Mover is running (%d:%02d elapsed). Array-only has not been applied yet.', intdiv($elapsed, 60), $elapsed % 60);
                $job['updated_at'] = gmdate('c');
                $this->writeJob($job);
                $lastUpdate = time();
            }
            usleep(500000);
        }
    }

    private function sharesWithPoolFiles(array $shareNames, array $pools): array
    {
        return $this->poolFileLabels($this->poolFilesByShare($shareNames, $pools));
    }

    private function poolFilesByShare(array $shareNames, array $pools): array
    {
        $remaining = [];
        foreach ($shareNames as $name) {
            foreach ($pools as $pool) {
                if ($this->directoryContainsFiles($this->mountRoot . '/' . $pool . '/' . $name)) {
                    $remaining[$name][] = $pool;
                }
            }
        }
        return $remaining;
    }

    private function poolFileLabels(array $remainingByShare): array
    {
        $labels = [];
        foreach ($remainingByShare as $name => $pools) {
            foreach ($pools as $pool) {
                $labels[] = $name . ' (' . $pool . ')';
            }
        }
        sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
        return $labels;
    }

    private function forcePoolFilesToArray(array $remainingByShare, array &$job): void
    {
        $arrayRoot = $this->mountRoot . '/user0';
        if (!$this->testMode) {
            if (!is_dir($arrayRoot) || !$this->isMountedPath($arrayRoot)) {
                throw new RuntimeException('The Array-only user-share mount /mnt/user0 is unavailable. No files were forced.');
            }
            if (!is_executable('/usr/bin/rsync')) {
                throw new RuntimeException('The rsync command required to force remaining files is unavailable.');
            }
        } elseif (!is_dir($arrayRoot) && !mkdir($arrayRoot, 0777, true) && !is_dir($arrayRoot)) {
            throw new RuntimeException('Unable to create the test Array target.');
        }

        foreach ($remainingByShare as $name => $pools) {
            $destination = $arrayRoot . '/' . $name;
            if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
                throw new RuntimeException('Unable to create the Array destination for share "' . $name . '".');
            }
            foreach ($pools as $pool) {
                $source = $this->mountRoot . '/' . $pool . '/' . $name;
                if (!$this->directoryContainsFiles($source)) {
                    continue;
                }
                $job['message'] = 'Forcing remaining files for "' . $name . '" from pool "' . $pool . '" directly to the Array.';
                $this->writeJob($job);

                if ($this->testMode) {
                    $this->testForceTree($source, $destination);
                } else {
                    $command = '/usr/bin/rsync -aHAXS --numeric-ids --remove-source-files -- '
                        . escapeshellarg(rtrim($source, '/') . '/') . ' '
                        . escapeshellarg(rtrim($destination, '/') . '/') . ' 2>&1';
                    $output = [];
                    $status = 0;
                    exec($command, $output, $status);
                    if ($status !== 0) {
                        $detail = trim(implode("\n", array_slice($output, -10)));
                        throw new RuntimeException(
                            'The forced transfer failed for share "' . $name . '" from pool "' . $pool . '".'
                            . ($detail !== '' ? ' ' . $detail : '')
                        );
                    }
                }

                if ($this->directoryContainsFiles($source)) {
                    throw new RuntimeException('Files still remain for share "' . $name . '" on pool "' . $pool . '" after the forced transfer.');
                }
            }
        }
    }

    private function testForceTree(string $source, string $destination): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen(rtrim($source, '/')) + 1);
            $target = rtrim($destination, '/') . '/' . $relative;
            if ($entry->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0777, true);
                }
                continue;
            }
            $targetDir = dirname($target);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }
            if (!copy($entry->getPathname(), $target) || !unlink($entry->getPathname())) {
                throw new RuntimeException('The test forced transfer failed.');
            }
        }
    }

    private function isMountedPath(string $path): bool
    {
        foreach (file('/proc/mounts', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $fields = preg_split('/\s+/', $line);
            if (isset($fields[1])) {
                $mounted = str_replace(['\\040', '\\011'], [' ', "\t"], $fields[1]);
                if ($mounted === $path) {
                    return true;
                }
            }
        }
        return false;
    }

    private function sourcePoolForShare(string $shareName, array $pools, string $configuredPool, string $fallbackPool): string
    {
        $withFiles = [];
        foreach ($pools as $pool) {
            if ($this->directoryContainsFiles($this->mountRoot . '/' . $pool . '/' . $shareName)) {
                $withFiles[] = $pool;
            }
        }
        if ($withFiles) {
            if (in_array($configuredPool, $withFiles, true)) {
                return $configuredPool;
            }
            if (in_array($fallbackPool, $withFiles, true)) {
                return $fallbackPool;
            }
            return $withFiles[0];
        }
        if (in_array($configuredPool, $pools, true)) {
            return $configuredPool;
        }
        return in_array($fallbackPool, $pools, true) ? $fallbackPool : '';
    }

    private function assertPoolsMounted(array $pools): void
    {
        if ($this->testMode) {
            return;
        }
        $missing = [];
        foreach ($pools as $pool) {
            if (!$this->isMountedPath($this->mountRoot . '/' . $pool)) {
                $missing[] = $pool;
            }
        }
        if ($missing) {
            throw new RuntimeException('Array-only was not applied because these pools are not mounted: ' . implode(', ', $missing) . '.');
        }
    }

    private function directoryContainsFiles(string $path): bool
    {
        if (!is_dir($path)) {
            return false;
        }
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                if ($entry->isFile() || $entry->isLink()) {
                    return true;
                }
            }
            return false;
        } catch (UnexpectedValueException $error) {
            return true;
        }
    }

    private function latestActiveJob(): ?array
    {
        $active = ['queued', 'preparing', 'waiting_mover', 'preparing_mover', 'starting_mover', 'running_mover', 'verifying', 'forcing', 'applying'];
        $files = glob($this->jobsDir . '/*.json', GLOB_NOSORT) ?: [];
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach ($files as $file) {
            $job = json_decode((string)@file_get_contents($file), true);
            if (is_array($job) && in_array((string)($job['status'] ?? ''), $active, true)) {
                $updated = strtotime((string)($job['updated_at'] ?? '')) ?: 0;
                if ($updated > 0 && time() - $updated > 1800 && !is_file($this->moverPid)) {
                    $job['status'] = 'failed';
                    $job['terminal'] = true;
                    $job['error'] = 'The operation was interrupted. Array-only was not applied; review the current share policy and run the operation again.';
                    $job['message'] = $job['error'];
                    $this->writeJob($job);
                    continue;
                }
                return [
                    'id' => (string)$job['id'],
                    'status' => (string)$job['status'],
                    'message' => (string)($job['message'] ?? ''),
                ];
            }
        }
        return null;
    }

    private function jobFile(string $jobId): string
    {
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $jobId)) {
            throw new InvalidArgumentException('Invalid operation identifier.');
        }
        return $this->jobsDir . '/' . $jobId . '.json';
    }

    private function loadJob(string $jobId): array
    {
        $file = $this->jobFile($jobId);
        if (!is_file($file)) {
            throw new InvalidArgumentException('That operation could not be found.');
        }
        $job = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($job)) {
            throw new RuntimeException('The operation record is invalid.');
        }
        return $job;
    }

    private function writeJob(array $job): void
    {
        $job['updated_at'] = gmdate('c');
        $this->atomicWrite(
            $this->jobFile((string)$job['id']),
            json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            0600
        );
    }

    private function updateJob(array $job, string $status, string $message): array
    {
        $job['status'] = $status;
        $job['message'] = $message;
        $job['terminal'] = false;
        $job['updated_at'] = gmdate('c');
        $this->writeJob($job);
        return $job;
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
            if (is_array($manifest) && !empty($manifest['restore_blocked_at'])) {
                return null;
            }
            if (is_array($manifest) && empty($manifest['restored_at']) && !empty($manifest['targets'])) {
                return dirname($manifestFile);
            }
        }
        return null;
    }

    private function blockBackupRestore(string $backupName, string $reason): void
    {
        if (basename($backupName) !== $backupName) {
            return;
        }
        $manifestFile = $this->backupDir . '/' . $backupName . '/manifest.json';
        if (!is_file($manifestFile)) {
            return;
        }
        $manifest = json_decode((string)file_get_contents($manifestFile), true);
        if (!is_array($manifest)) {
            return;
        }
        $manifest['restore_blocked_at'] = gmdate('c');
        $manifest['restore_blocked_reason'] = $reason;
        $this->atomicWrite($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", 0600);
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

    private function selectionMap(array $requestedNames): array
    {
        $selected = [];
        foreach ($requestedNames as $name) {
            $name = (string)$name;
            if (!$this->validName($name)) {
                throw new InvalidArgumentException('The share selection contains an invalid name.');
            }
            $selected[$name] = true;
        }
        return $selected;
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
        } elseif ($command === '--run-job') {
            $result = $controller->runPolicyJob((string)($argv[2] ?? ''));
        } else {
            throw new InvalidArgumentException('Usage: cache-array-share.php [--status|--restore|--run-job JOB_ID]');
        }
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } catch (Throwable $error) {
        fwrite(STDERR, 'cache-array-share: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
