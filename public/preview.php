<?php
declare(strict_types=1);

use App\Templates;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// Preview responses can contain customer data supplied in the request.
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src https: http:; base-uri 'none'; form-action 'none'; sandbox");

/**
 * Returns a plain text error response and stops processing.
 */
function previewError(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    previewError(405, 'POST required');
}

$format = $_GET['format'] ?? 'html';
if (!in_array($format, ['html', 'text', 'json'], true)) {
    previewError(400, 'Format must be html, text or json');
}

// Keep event-to-directory mappings explicit; never use a header as a file path.
$events = ['order:place' => 'order_place', 'order-package:status-update' => 'order-package_status-update', 'order-document:status-update' => 'order-document_status-update'];
$event = $_SERVER['HTTP_X_WEBHOOK_EVENT'] ?? '';
if (!isset($events[$event])) {
    previewError(400, 'Unsupported event; use order:place, order-package:status-update or order-document:status-update');
}

try {
    $payload = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException) {
    previewError(400, 'Invalid JSON');
}

$order = is_array($payload) ? ($event === 'order:place' ? $payload : ($payload['order'] ?? null)) : null;
$companyId = is_array($order) ? ($order['company_id'] ?? null) : null;
if (!is_int($companyId) || $companyId < 1) {
    previewError(400, 'Order must contain a positive integer company_id');
}

if ($event === 'order-document:status-update' && ($payload['status'] ?? null) !== 'rejected') {
    previewError(202, 'Document status ignored');
}

try {
    $root = dirname(__DIR__);
    Dotenv::createImmutable($root)->safeLoad();
    $message = (new Templates($root, array_merge(getenv(), $_SERVER, $_ENV)))->render(
        $events[$event],
        $companyId,
        $payload,
    );
} catch (\Twig\Error\RuntimeError) {
    previewError(400, 'Unable to render preview; check the payload contains all required template fields');
} catch (Throwable $exception) {
    error_log('Email preview failed (' . get_class($exception) . ')');
    previewError(500, 'Unable to render preview');
}

// Preview only renders templates; it never sends email or relays webhooks.
switch ($format) {
    case 'json':
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'subject' => $message['subject'],
            'html' => $message['html'],
            'text' => $message['body'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        break;
    case 'text':
        header('Content-Type: text/plain; charset=utf-8');
        echo $message['body'];
        break;
    default:
        header('Content-Type: text/html; charset=utf-8');
        echo $message['html'];
}
