<?php
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
        if ($action !== 'status') {
            respond(['ok' => false, 'error' => 'Unsupported request.'], 405);
        }
        respond(['ok' => true, 'data' => $controller->status()]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(['ok' => false, 'error' => 'Unsupported request method.'], 405);
    }

    switch ($action) {
        case 'apply':
            $shares = $_POST['shares'] ?? [];
            if (!is_array($shares)) {
                $shares = [$shares];
            }
            $result = $controller->applySelection($shares, (string)($_POST['pool'] ?? ''));
            $message = $result['changed'] === 0
                ? 'The selected shares already use that Pool -> Array policy.'
                : sprintf('%d share(s) changed. Backup: %s', $result['changed'], $result['backup']);
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
