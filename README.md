# Plateit Webhook Listener

PHP 8.2+ transactional emails using Twig, PHPMailer and per-company SMTP settings.

## Setup

1. Run `composer install` (PHP cURL and OpenSSL are required).
2. Copy `.env.example` to `.env` and configure each company's webhook secret and SMTP credentials using `COMPANY_<id>_...` keys. Port 465 defaults to SMTPS; other ports use STARTTLS.
3. Serve **public/** over HTTPS, keeping configuration and templates outside the document root.
4. Set each Plateit company's webhook endpoint to `/index.php`, with the matching secret and subscriptions below.

Local development: `php -S localhost:8080 -t public`.

## Events

| Event | Email |
| --- | --- |
| `order:place` | Order confirmation, items and conditionally entitlement document instructions |
| `order-package:status-update` | Package status update |
| `order-document:status-update` | Replacement document request only when the document `status` is `rejected`; other statuses return 202 |

Sending requires a valid HMAC-SHA256 `Signature` and `X-Webhook-Contact-Hint: true`. Successful sends return 200; invalid payloads return 400, invalid signatures 401, and processing failures 500 for retry. Repeated webhooks send again; there is no database.

For testing, set `WEBHOOK_VERIFY_SIGNATURE=false` in `.env` to skip signature verification for all companies. Verification is enabled by default; only an explicit `false` disables it. Email sending and relaying still run, and relays forward the supplied signature without generating one.

## Webhook Relays

Set optional URLs per company and event in `.env`:

```dotenv
COMPANY_1_RELAY_ORDER_PLACE_URL=https://example.com/webhooks/orders
COMPANY_1_RELAY_ORDER_PACKAGE_STATUS_UPDATE_URL=https://example.com/webhooks/packages
COMPANY_1_RELAY_ORDER_DOCUMENT_STATUS_UPDATE_URL=https://example.com/webhooks/documents
```

Empty or absent URLs disable forwarding. After authentication and payload validation, the listener relays before applying email rules, including for non-rejected documents and false contact hints. It forwards the exact raw body, original `Signature`, event and contact-hint headers, with JSON content type. The receiver verifies using the same company's webhook secret.

Only 2XX responses count as success. Network errors, timeouts (10 seconds), redirects and other HTTP responses return 502 and prevent email sending, allowing Plateit to retry. If the relay succeeds but email processing fails, the retry forwards the webhook again; receivers must tolerate duplicates. Use HTTP(S) endpoints and avoid relay loops.

## Previewing Emails

Preview never relays webhooks.

POST a webhook JSON payload to `/preview.php` with headers:

```text
Content-Type: application/json
X-Webhook-Event: order:place
```

Use the relevant event header and payload structure: package and document events contain a nested `order` object.

Returns HTML by default; `?format=text` returns plain text, and `?format=json` returns `subject`, `html` and `text`. Preview is always enabled, skips signatures and contact hints, and never sends email. Non-rejected document statuses return 202 here too.

## Templates and Company-Specific Branding

Shared layouts, headers, footers, URLs and CSS live in `templates/shared/default/`. Event subjects, bodies and specialised partials live in:

```text
templates/order_place/default/
templates/order-package_status-update/default/
templates/order-document_status-update/default/
```

Template lookup order (first match wins):

1. `<event>/companies/<id>/`
2. `<event>/default/`
3. `shared/companies/<id>/`
4. `shared/default/`

Paths above are relative to `templates/`. Copy only files that differ. For example, `templates/shared/companies/1/footer.html.twig` overrides company 1's shared HTML footer.

All templates receive `order`; original payload fields remain available. HTML templates use `.html.twig` and automatic escaping; plain text templates use `.twig`. Override `order_url.twig` to change the status/upload link; output only the URL with query values URL-encoded.

CSS cascades in this order: shared default → event default → shared company → event company. Edit `styles.css` at the appropriate level; both preview and delivery automatically inline it.
