<?php
// Copyright (c) 2026 Raymond Munro
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/scripts/cache-array-share.php';

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $controller = new CacheArrayShare();
    $action = (string)($_REQUEST['action'] ?? 'status');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'status') {
            respond(['ok' => true, 'data' => $controller->status()]);
        }
        if ($action === 'job_status') {
            respond(['ok' => true, 'data' => $controller->jobStatus((string)($_GET['job'] ?? ''))]);
        }
        respond(['ok' => false, 'error' => 'Unsupported request.'], 405);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['ok' => false, 'error' => 'Unsupported request method.'], 405);
    }

    switch ($action) {
        case 'apply':
            $cacheShares = $_POST['cache_shares'] ?? ($_POST['shares'] ?? []);
            $cacheOnlyShares = $_POST['cache_only_shares'] ?? [];
            $noCacheShares = $_POST['no_cache_shares'] ?? [];
            $forceShares = $_POST['force_shares'] ?? [];
            if (!is_array($cacheShares)) {
                $cacheShares = [$cacheShares];
            }
            if (!is_array($noCacheShares)) {
                $noCacheShares = [$noCacheShares];
            }
            if (!is_array($cacheOnlyShares)) {
                $cacheOnlyShares = [$cacheOnlyShares];
            }
            if (!is_array($forceShares)) {
                $forceShares = [$forceShares];
            }
            if ($noCacheShares) {
                $result = $controller->startPolicyJob($cacheShares, $noCacheShares, (string)($_POST['pool'] ?? ''), $forceShares, $cacheOnlyShares);
                respond([
                    'ok' => true,
                    'message' => 'Mover safety operation started. Only Array-only shares are checked for pool leftovers; Cache-only shares are excluded from that check and applied afterward.',
                    'result' => $result,
                ]);
            }

            $result = $controller->applyPolicies($cacheShares, [], (string)($_POST['pool'] ?? ''), $cacheOnlyShares);
            $message = $result['changed'] === 0
                ? 'The selected shares already use the requested storage policies.'
                : sprintf(
                    '%d share(s) changed: %d Cache -> Array, %d Cache only, %d Array-only. Backup: %s',
                    $result['changed'],
                    $result['changed_cache'],
                    $result['changed_cache_only'],
                    $result['changed_no_cache'],
                    $result['backup']
                );
            respond(['ok' => true, 'message' => $message, 'result' => $result]);

        case 'restore':
            $result = $controller->restoreLatest();
            $message = $result['changed'] === 0
                ? 'There is no un-restored batch to restore.'
                : sprintf('%d share(s) restored from %s.', $result['changed'], $result['backup']);
            respond(['ok' => true, 'message' => $message, 'result' => $result]);

        default:
            respond(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (InvalidArgumentException $error) {
    respond(['ok' => false, 'error' => $error->getMessage()], 400);
} catch (Throwable $error) {
    error_log('cache-array-share: ' . $error->getMessage());
    respond(['ok' => false, 'error' => $error->getMessage()], 500);
}
