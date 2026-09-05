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
foreach ([$config, $user, $pools, $backups] as $directory) {
    mkdir($directory, 0700, true);
}
mkdir($user . '/Movies');
mkdir($user . '/appdata');
file_put_contents($pools . '/cache.cfg', "poolName=\"cache\"\n");
file_put_contents($pools . '/fast.cfg', "poolName=\"fast\"\n");
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
putenv('CACHE_ARRAY_SHARE_SHARES_INI=' . $root . '/missing-shares.ini');
putenv('CACHE_ARRAY_SHARE_LOCK=' . $root . '/operation.lock');

require_once __DIR__ . '/../src/usr/local/emhttp/plugins/cache-array-share/scripts/cache-array-share.php';

$apiSource = (string)file_get_contents(__DIR__ . '/../src/usr/local/emhttp/plugins/cache-array-share/api.php');
check(!str_contains($apiSource, 'assertCsrf'), 'does not repeat Unraid CSRF validation after local_prepend removes the token');

$controller = new CacheArrayShare();
$status = $controller->status();
check($status['counts']['total'] === 2, 'discovers user shares');
check($status['pools'] === ['cache', 'fast'], 'discovers and sorts pools');
check($status['shares'][0]['sensitive'] === true, 'marks appdata as service-sensitive');

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
