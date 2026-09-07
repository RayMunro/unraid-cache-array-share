<?php
declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

$root = sys_get_temp_dir() . '/cache-array-share-test-' . bin2hex(random_bytes(4));
$config = $root . '/shares';
$user = $root . '/user';
$pools = $root . '/pools';
$backups = $root . '/backups';
$jobs = $root . '/jobs';
$mountRoot = $root . '/mnt';
foreach ([$config, $user, $pools, $backups, $jobs, $mountRoot . '/cache/appdata', $mountRoot . '/fast/appdata', $mountRoot . '/user0'] as $directory) {
    mkdir($directory, 0700, true);
}
mkdir($user . '/Movies');
mkdir($user . '/appdata');
file_put_contents($pools . '/cache.cfg', "poolName=\"cache\"\n");
file_put_contents($pools . '/fast.cfg', "poolName=\"fast\"\n");
file_put_contents($root . '/docker.cfg', "DOCKER_APP_CONFIG_PATH=\"/mnt/user/Movies/\"\n");
file_put_contents($config . '/Movies.cfg', implode("\n", [
    'shareComment="Films"',
    'shareAllocator="highwater"',
    'shareFloor="1000000"',
    'shareSplitLevel="2"',
    'shareInclude="disk1,disk2"',
    'shareExclude="disk3"',
    'shareUseCache="no"',
    'shareCachePool=""',
    'shareCachePool2=""',
    'shareExport="eh"',
    'shareSecurity="private"',
]) . "\n");
file_put_contents($config . '/appdata.cfg', implode("\n", [
    'shareUseCache="only"',
    'shareCachePool="cache"',
    'shareCachePool2=""',
]) . "\n");

putenv('CACHE_ARRAY_SHARE_TEST_MODE=1');
putenv('CACHE_ARRAY_SHARE_CONFIG_DIR=' . $config);
putenv('CACHE_ARRAY_SHARE_USER_DIR=' . $user);
putenv('CACHE_ARRAY_SHARE_POOLS_DIR=' . $pools);
putenv('CACHE_ARRAY_SHARE_BACKUPS=' . $backups);
putenv('CACHE_ARRAY_SHARE_JOBS=' . $jobs);
putenv('CACHE_ARRAY_SHARE_MOUNT_ROOT=' . $mountRoot);
putenv('CACHE_ARRAY_SHARE_MOVER_PID=' . $root . '/mover.pid');
putenv('CACHE_ARRAY_SHARE_SHARES_INI=' . $root . '/missing-shares.ini');
putenv('CACHE_ARRAY_SHARE_LOCK=' . $root . '/operation.lock');

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/cache-array-share/scripts/cache-array-share.php';

$apiSource = (string)file_get_contents(__DIR__ . '/../src/usr/local/emhttp/plugins/cache-array-share/api.php');
check(!str_contains($apiSource, 'assertCsrf'), 'does not repeat Unraid CSRF validation after local_prepend removes the token');
$pageSource = (string)file_get_contents(__DIR__ . '/../src/usr/local/emhttp/plugins/cache-array-share/cache-array-share.page');
check(str_contains($pageSource, '.cas-cache[data-bulk-selectable="1"]'), 'Cache to Array Select all excludes system-dependent shares');
check(str_contains($pageSource, '.cas-cache-only[data-bulk-selectable="1"]'), 'Cache-only Select all excludes system-dependent shares');
check(str_contains($pageSource, '.cas-no-cache[data-bulk-selectable="1"]'), 'Array-only Select all excludes system-dependent shares');
check(str_contains($pageSource, 'Copyright © Raymond Munro 2026'), 'shows the requested copyright notice');
check(str_contains($pageSource, 'class="cas-copyright"'), 'makes the copyright notice a prominent banner');
check(str_contains($pageSource, 'class="cas-force"'), 'shows a per-share Force remaining files option');
check(str_contains($pageSource, 'id="cas-all-force"'), 'shows a Force all selected Array-only button');
check(str_contains($pageSource, "$('.cas-no-cache:checked').closest('tr').find('.cas-force').prop('checked',true)"), 'bulk Force affects only shares currently selected as Array only');
check(str_contains($pageSource, 'Tag="cache-array-share.png"'), 'uses the custom icon for the plugin page');
$pluginTemplate = (string)file_get_contents(__DIR__ . '/../plugin/cache-array-share.plg.in');
check(str_contains($pluginTemplate, 'icon="cache-array-share.png"'), 'uses the custom icon in Plugin Manager');
$pageIcon = __DIR__ . '/../src/usr/local/emhttp/plugins/cache-array-share/icons/cache-array-share.png';
$pluginIcon = __DIR__ . '/../src/usr/local/emhttp/plugins/cache-array-share/images/cache-array-share.png';
check(is_file($pageIcon) && is_file($pluginIcon), 'packages the custom icon for both Unraid views');
check(substr((string)file_get_contents($pageIcon), 1, 3) === 'PNG', 'custom icon is a PNG image');

$controller = new CacheArrayShare();
$status = $controller->status();
check($status['counts']['total'] === 2, 'discovers user shares');
check($status['counts']['no_cache'] === 1, 'counts shares already using No cache');
check($status['counts']['cache_only'] === 1, 'counts shares already using Cache only');
check($status['pools'] === ['cache', 'fast'], 'discovers and sorts pools');
check($status['shares'][0]['sensitive'] === true, 'marks appdata as service-sensitive');
check($status['shares'][0]['bulk_selectable'] === false, 'excludes standard system shares from Select all');
check($status['shares'][1]['system_dependent'] === true, 'detects a custom share referenced by Docker configuration');
check($status['shares'][1]['bulk_selectable'] === false, 'excludes detected custom system shares from Select all');

$result = $controller->applySelection(['Movies'], 'cache');
check($result['changed'] === 1, 'changes one selected share');
$movies = parse_ini_file($config . '/Movies.cfg');
check($movies['shareUseCache'] === 'yes', 'sets Pool to Array mover direction');
check($movies['shareCachePool'] === 'cache', 'sets selected primary pool');
check($movies['shareCachePool2'] === '', 'sets Array as secondary storage');
check($movies['shareExport'] === 'eh' && $movies['shareSecurity'] === 'private', 'preserves SMB settings');
check($movies['shareAllocator'] === 'highwater' && $movies['shareSplitLevel'] === '2', 'preserves allocation settings');
check(count(glob($backups . '/*/manifest.json')) === 1, 'creates a timestamped backup manifest');

$same = $controller->applySelection(['Movies'], 'cache');
check($same['changed'] === 0, 'does not rewrite an already-matching share');

$restored = $controller->restoreLatest();
check($restored['changed'] === 1, 'restores the most recent batch');
$movies = parse_ini_file($config . '/Movies.cfg');
check($movies['shareUseCache'] === 'no' && $movies['shareCachePool'] === '', 'restores original storage policy');
check($movies['shareExport'] === 'eh', 'restore preserves unrelated settings');

$cacheOnly = $controller->applyPolicies([], [], 'fast', ['Movies']);
check($cacheOnly['changed'] === 1 && $cacheOnly['changed_cache_only'] === 1, 'changes one selected share to Cache only');
$movies = parse_ini_file($config . '/Movies.cfg');
check($movies['shareUseCache'] === 'only', 'sets the Cache-only mover policy');
check($movies['shareCachePool'] === 'fast' && $movies['shareCachePool2'] === '', 'sets the chosen pool with no secondary storage for Cache only');
$controller->restoreLatest();

$queued = $controller->startPolicyJob([], ['appdata'], 'cache');
check($queued['queued'] === true, 'queues Array-only as a background Mover operation');
check($controller->status()['active_job']['id'] === $queued['job'], 'reports the active Mover operation');
$noCache = $controller->runPolicyJob($queued['job']);
check($noCache['status'] === 'completed' && $noCache['result']['mover_runs'] === 1, 'runs Mover before completing Array-only');
$appdata = parse_ini_file($config . '/appdata.cfg');
check($appdata['shareUseCache'] === 'no', 'sets Array-only after Mover completes');
check($appdata['shareCachePool'] === '' && $appdata['shareCachePool2'] === '', 'clears both pools for Array-only');
$controller->restoreLatest();

mkdir($mountRoot . '/cache/Movies', 0700, true);
file_put_contents($mountRoot . '/cache/Movies/keep-on-cache.dat', 'must stay on cache');
$cacheOnlyMixedQueued = $controller->startPolicyJob([], ['appdata'], 'cache', [], ['Movies']);
$cacheOnlyMixed = $controller->runPolicyJob($cacheOnlyMixedQueued['job']);
check($cacheOnlyMixed['status'] === 'completed', 'does not include Cache-only shares in the post-Mover remaining-files check');
$movies = parse_ini_file($config . '/Movies.cfg');
$appdata = parse_ini_file($config . '/appdata.cfg');
check($movies['shareUseCache'] === 'only' && $movies['shareCachePool'] === 'cache', 'applies Cache only after the Array-only verification passes');
check(is_file($mountRoot . '/cache/Movies/keep-on-cache.dat'), 'leaves Cache-only share files on the pool');
check($appdata['shareUseCache'] === 'no', 'still applies Array only in the same mixed operation');
$controller->restoreLatest();
unlink($mountRoot . '/cache/Movies/keep-on-cache.dat');

$mixedQueued = $controller->startPolicyJob(['Movies'], ['appdata'], 'fast');
$mixed = $controller->runPolicyJob($mixedQueued['job']);
check($mixed['status'] === 'completed' && $mixed['result']['changed'] === 2, 'applies both per-share choices after Mover in one batch');
$movies = parse_ini_file($config . '/Movies.cfg');
$appdata = parse_ini_file($config . '/appdata.cfg');
check($movies['shareUseCache'] === 'yes' && $movies['shareCachePool'] === 'fast', 'uses the selected pool for Cache to Array');
check($appdata['shareUseCache'] === 'no' && $appdata['shareCachePool'] === '', 'uses Array only after the safety pass');
$controller->restoreLatest();

$conflict = false;
try {
    $controller->startPolicyJob(['Movies'], ['Movies'], 'cache');
} catch (InvalidArgumentException $error) {
    $conflict = true;
}
check($conflict, 'rejects both tickboxes for the same share');

$cacheOnlyConflict = false;
try {
    $controller->startPolicyJob([], ['Movies'], 'cache', [], ['Movies']);
} catch (InvalidArgumentException $error) {
    $cacheOnlyConflict = true;
}
check($cacheOnlyConflict, 'rejects Cache only and Array only for the same share');

$invalidForce = false;
try {
    $controller->startPolicyJob([], ['appdata'], 'cache', ['Movies']);
} catch (InvalidArgumentException $error) {
    $invalidForce = true;
}
check($invalidForce, 'rejects Force remaining unless Array only is selected for that share');

file_put_contents($mountRoot . '/cache/appdata/open-file.dat', 'still in use');
$blockedQueued = $controller->startPolicyJob([], ['appdata'], 'cache');
$blocked = $controller->runPolicyJob($blockedQueued['job']);
check($blocked['status'] === 'failed', 'refuses Array-only when files remain on a pool');
$appdata = parse_ini_file($config . '/appdata.cfg');
check($appdata['shareUseCache'] === 'yes' && $appdata['shareCachePool'] === 'cache', 'leaves the safe Pool to Array policy in place after verification fails');
check($controller->status()['can_restore'] === false, 'blocks automatic restore after a failed safety operation');
unlink($mountRoot . '/cache/appdata/open-file.dat');
check($controller->restoreLatest()['changed'] === 0, 'does not restore an unsafe pre-Mover policy');

file_put_contents($mountRoot . '/cache/appdata/force-me.dat', 'force this file');
$forcedQueued = $controller->startPolicyJob([], ['appdata'], 'cache', ['appdata']);
$forced = $controller->runPolicyJob($forcedQueued['job']);
check($forced['status'] === 'completed', 'completes Array-only when Force remaining is authorised');
check($forced['result']['forced_shares'] === ['appdata'], 'records the share whose remaining files were forced');
check(!is_file($mountRoot . '/cache/appdata/force-me.dat'), 'removes a forced file from the pool after copying');
check((string)file_get_contents($mountRoot . '/user0/appdata/force-me.dat') === 'force this file', 'copies a forced file to the Array-only target');
$appdata = parse_ini_file($config . '/appdata.cfg');
check($appdata['shareUseCache'] === 'no' && $appdata['shareCachePool'] === '', 'applies Array-only after the forced transfer verifies clean');

$invalidPool = false;
try {
    $controller->applySelection(['Movies'], 'missing');
} catch (InvalidArgumentException $error) {
    $invalidPool = true;
}
check($invalidPool, 'rejects an unavailable pool');

$invalidShare = false;
try {
    $controller->applySelection(['Missing Share'], 'cache');
} catch (InvalidArgumentException $error) {
    $invalidShare = true;
}
check($invalidShare, 'rejects a missing share');

echo "All tests passed.\n";
