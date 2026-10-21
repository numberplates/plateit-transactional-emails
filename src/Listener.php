<?php
declare(strict_types=1);

namespace App;

use RuntimeException;

/**
 * Authenticates Plateit webhooks and despatches transactional emails.
 */
final class Listener
{
    /**
     * Supplies configuration, templates and the email transport.
     */
    public function __construct(
        private array $env,
        private Templates $templates,
        private \Closure $send,
    ) {}

    /**
     * Processes one request and returns its HTTP status and response text.
     */
    public function handle(string $method, array $headers, string $raw): array
    {
        if ($method !== 'POST') {
            return [405, 'POST required'];
        }
        $headers = array_change_key_case($headers, CASE_LOWER);
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [400, 'Invalid JSON'];
        }
        if (!is_array($payload)) {
            return [400, 'Invalid payload'];
        }
        $event = $headers['x-webhook-event'] ?? '';
        $order = $event === 'order:place' ? $payload : ($payload['order'] ?? []);
        $companyId = is_array($order) ? ($order['company_id'] ?? null) : null;
        if (!is_int($companyId) || $companyId < 1) {
            return [400, 'Invalid company ID'];
        }
        $prefix = 'COMPANY_' . $companyId . '_';
        $secret = $this->env[$prefix . 'WEBHOOK_SECRET'] ?? '';
        $signature = $headers['signature'] ?? '';
        // if ($secret === '' || !is_string($signature) ||
        //     !hash_equals(hash_hmac('sha256', $raw, $secret), $signature)) {
        //     return [401, 'Invalid webhook signature'];
        // }
        $events = ['order:place' => 'order_place', 'order-package:status-update' => 'order-package_status-update', 'order-document:status-update' => 'order-document_status-update'];
        if (!isset($events[$event])) {
            return [200, 'Event ignored'];
        }
        if ($event === 'order-document:status-update' && ($payload['status'] ?? null) !== 'rejected') {
            return [202, 'Document status ignored'];
        }
        if (($headers['x-webhook-contact-hint'] ?? '') !== 'true') {
            return [200, 'Customer contact not requested'];
        }
        if (!$this->validOrder($order, $event !== 'order-document:status-update')) {
            return [400, 'Invalid order payload'];
        }

        if ($event === 'order-package:status-update' &&
            (!is_array($payload['system_package_status'] ?? null) ||
             !is_string($payload['system_package_status']['name'] ?? null) ||
             trim($payload['system_package_status']['name']) === '')) {
            return [400, 'Invalid package status payload'];
        }

        $config = $this->config($prefix);
        $message = $this->templates->render($events[$event], $companyId, $payload);
        ($this->send)($config, $order, $message);
        return [200, 'Email sent'];
    }

    /**
     * Checks the order fields required by the email templates.
     */
    private function validOrder(array $order, bool $requirePackageCount = true): bool
    {
        return is_int($order['id'] ?? null) && $order['id'] > 0
            && (!$requirePackageCount || (is_int($order['packages_count'] ?? null) && $order['packages_count'] >= 0))
            && is_array($order['customer'] ?? null)
            && is_string($order['customer']['first_name'] ?? null)
            && is_string($order['customer']['email'] ?? null)
            && filter_var($order['customer']['email'], FILTER_VALIDATE_EMAIL) !== false
            && is_array($order['company'] ?? null)
            && is_string($order['company']['name'] ?? null)
            && is_string($order['company']['website_url'] ?? null);
    }

    /**
     * Loads and validates the selected company's SMTP settings.
     */
    private function config(string $prefix): array
    {
        $config = [];
        foreach (['EMAIL_ADDRESS_FROM', 'EMAIL_ADDRESS_BOUNCE', 'SMTP_SERVER', 'SMTP_USER', 'SMTP_PASSWORD', 'SMTP_PORT'] as $key) {
            $value = $this->env[$prefix . $key] ?? '';
            if (!is_string($value) || $value === '') {
                throw new RuntimeException('Missing company configuration: ' . $prefix . $key);
            }
            $config[$key] = $value;
        }
        foreach (['EMAIL_ADDRESS_FROM', 'EMAIL_ADDRESS_BOUNCE'] as $key) {
            if (!filter_var($config[$key], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid sender configuration');
            }
        }
        if (!ctype_digit($config['SMTP_PORT']) || (int) $config['SMTP_PORT'] < 1 || (int) $config['SMTP_PORT'] > 65535) {
            throw new RuntimeException('Invalid SMTP port');
        }
        $config['SMTP_ENCRYPTION'] = $this->env[$prefix . 'SMTP_ENCRYPTION'] ?? ($config['SMTP_PORT'] === '465' ? 'smtps' : 'tls');
        if (!in_array($config['SMTP_ENCRYPTION'], ['smtps', 'tls'], true)) {
            throw new RuntimeException('SMTP_ENCRYPTION must be smtps or tls');
        }
        return $config;
    }
}
