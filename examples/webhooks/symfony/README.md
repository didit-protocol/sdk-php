# Didit webhooks in Symfony

A controller that verifies Didit webhook deliveries at `POST /webhooks/didit`.
Works with Symfony 6.4 and 7 on PHP 8.1 or later.

| File here | Where it goes in your application |
|---|---|
| [`src/Controller/DiditWebhookController.php`](src/Controller/DiditWebhookController.php) | `src/Controller/DiditWebhookController.php` |

## Setup

1. Install the SDK: `composer require didit-protocol/sdk-php`.
2. Set the secret of your webhook destination (from the Didit Console, or `secretSharedKey` of `$didit->webhooks->destinations->retrieve()`) in `.env.local`:

   ```dotenv
   DIDIT_WEBHOOK_SECRET=whsec_test_your_destination_secret
   ```

   In production, prefer a Symfony secret: `php bin/console secrets:set DIDIT_WEBHOOK_SECRET`.
   The controller receives it through `#[Autowire('%env(DIDIT_WEBHOOK_SECRET)%')]`.
3. Copy the controller. The default `config/routes.yaml` loads the `#[Route]` attributes of `src/Controller/`, and the default `config/services.yaml` autowires the constructor (the secret and the PSR-3 logger).
4. Point the webhook destination at `https://your-app.example/webhooks/didit`.

## CSRF and security

Symfony checks CSRF tokens only in forms, so this controller needs no exemption.
If a firewall covers the path, let Didit in without a login, in `config/packages/security.yaml`:

```yaml
security:
    access_control:
        - { path: ^/webhooks/didit, roles: PUBLIC_ACCESS }
```

## Notes

- The controller verifies `$request->getContent()`, the raw body. Never verify `$request->toArray()` or `$request->getPayload()`: they are decoded.
- `$request->headers->all()` passes every header; the signature header names are matched in any case.
- During a secret rotation, pass both secrets as an array: `Webhook::verify($body, $headers, [$newSecret, $oldSecret])`.
- Didit retries a delivery until it gets a `2xx`. Answer quickly (send slow work to Messenger) and skip `event_id`s you already processed.
- Try it locally with `symfony server:start` (or `php -S 127.0.0.1:8000 -t public`) and the signed `curl` request from [`examples/README.md`](../../README.md), sent to `http://127.0.0.1:8000/webhooks/didit`.
