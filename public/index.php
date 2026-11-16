<?php
declare(strict_types=1);

use App\Listener;
use App\SmtpSender;
use App\Templates;
use App\WebhookRelay;
use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $root = dirname(__DIR__);
    Dotenv::createImmutable($root)->safeLoad();
    $listener = new Listener(
        array_merge(getenv(), $_SERVER, $_ENV),
        new Templates($root, array_merge(getenv(), $_SERVER, $_ENV)),
        Closure::fromCallable(new SmtpSender()),
        Closure::fromCallable(new WebhookRelay()),
    );
    [$status, $body] = $listener->handle(
        $_SERVER['REQUEST_METHOD'] ?? '',
        getallheaders(),
        file_get_contents('php://input'),
    );
    if ($status === 405) {
        header('Allow: POST');
    }
    http_response_code($status);
    echo $body;
} catch (Throwable $exception) {
    // Avoid logging SMTP exceptions, which may contain customer or server data.
    error_log('Webhook processing failed (' . get_class($exception) . ')');
    http_response_code(500);
    var_dump($exception);
    echo 'Unable to process webhook';
}
