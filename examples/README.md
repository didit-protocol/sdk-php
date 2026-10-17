# Examples

Runnable scripts for the main Didit flows, and webhook receivers for plain PHP, Laravel and Symfony.
Each script reads its configuration from environment variables, prints what it does and exits with a non-zero status on failure.

From the repository root (PHP 8.1 or later):

```sh
composer install
export DIDIT_API_KEY=your-api-key
php examples/quickstart.php
```

Use the API key of a sandbox application while you try them.
In your own project, install the SDK with `composer require didit-protocol/sdk-php` and require your own `vendor/autoload.php`.

## Scripts

| Script | What it shows |
|---|---|
| [`quickstart.php`](quickstart.php) | Create a verification session, print its URL, read the decision, and verify a webhook delivery signed locally the way Didit signs it. |
| [`kyc_session.php`](kyc_session.php) | Create a session with `vendor_data`, `metadata` and a `callback`; list sessions with auto-pagination; read a decision feature by feature; save the PDF report. Pass a session id to read an existing session: `php examples/kyc_session.php <session-id>`. |
| [`id_verification.php`](id_verification.php) | Standalone ID verification from image files: `php examples/id_verification.php front.jpg [back.jpg]`. |
| [`aml_screening.php`](aml_screening.php) | Screen a person against sanctions, PEP and watchlists and print the score and hits: `php examples/aml_screening.php "Full Name" 1990-05-17`. |
| [`transaction_screening.php`](transaction_screening.php) | Submit a transaction for monitoring, read it back, and list the ones waiting for review. |
| [`travel_rule.php`](travel_rule.php) | Screen a wallet address, then read the Travel Rule settings, the VASP directory and your wallet address book: `php examples/travel_rule.php [address] [blockchain]`. |
| [`authentication.php`](authentication.php) | Didit Authentication: create PKCE, state and nonce values, verify an access token and handle `TokenVerificationException::$reason`, and check a session for revocation: `php examples/authentication.php <access-token> [--check-session]`. |

## Webhook receivers

| Receiver | How to run it |
|---|---|
| [`webhooks/plain_php.php`](webhooks/plain_php.php) | `DIDIT_WEBHOOK_SECRET=whsec_test_local php -S 127.0.0.1:8080 examples/webhooks/plain_php.php` |
| [`webhooks/laravel/`](webhooks/laravel/) | A controller and a route for your Laravel application; see its [README](webhooks/laravel/README.md). |
| [`webhooks/symfony/`](webhooks/symfony/) | A controller for your Symfony application; see its [README](webhooks/symfony/README.md). |

Every receiver verifies the raw request body with `Webhook::verify()` (also available as `$didit->webhooks->verify()`), answers `200` for a valid delivery and `400` or `401` with the `WebhookVerificationException` reason otherwise.
Didit retries a delivery until it gets a `2xx`, with the same `event_id`, so handle events idempotently.

To send a signed test delivery to a receiver, sign the body with the receiver's secret:

```sh
SECRET=whsec_test_local
NOW=$(date +%s)
BODY='{"created_at":'$NOW',"event_id":"6f1c2d3e-0000-4000-8000-000000000001","session_id":"11111111-2222-4333-8444-000000000001","status":"Approved","timestamp":'$NOW',"vendor_data":"user-123","webhook_type":"status.updated"}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -hex | sed 's/^.*= //')
curl -i http://127.0.0.1:8080/ -H 'Content-Type: application/json' -H "X-Signature: $SIG" -H "X-Timestamp: $NOW" --data-binary "$BODY"
```

## Environment variables

| Variable | Used by |
|---|---|
| `DIDIT_API_KEY` | Every script except `authentication.php`. The client reads it by itself. |
| `DIDIT_WORKFLOW_ID` | `quickstart.php`, `kyc_session.php`: the workflow the session runs, from the Didit Console. |
| `DIDIT_BASE_URL` | Optional: send the requests to another server, such as a local mock. |
| `DIDIT_WEBHOOK_SECRET` | The webhook receivers; `quickstart.php` defaults to a test secret. |
| `DIDIT_SANDBOX_SCENARIO` | Optional, `aml_screening.php` with a sandbox key: `decline_aml_hit` or `review_aml_possible_match`. |
| `DIDIT_TRAVEL_RULE_TRANSACTION_ID` | Optional, `travel_rule.php`: a transaction uuid whose Travel Rule transfer to print. |
| `DIDIT_PUBLISHABLE_KEY` or `DIDIT_AUTH_ISSUER` | `authentication.php`: your application's publishable key (`pk_test_...` or `pk_live_...`) or its issuer URL. |
| `DIDIT_APPLICATION_ID` | `authentication.php`: the expected audience of access tokens. |
| `DIDIT_LOG=debug` | Optional: the SDK logs method, path, status, duration and request id of every request to stderr. |

## Running against a local mock

`DIDIT_BASE_URL` points the client at any server that answers like the Didit API, so the scripts run without network access or an account.
For the quickstart, the server needs `POST /v3/session/` (answering `201` with `session_id`, `url` and `status`) and `GET /v3/session/{id}/decision/` (answering `200` with `session_id` and `status`):

```sh
php -S 127.0.0.1:8099 path/to/mock-router.php &
DIDIT_API_KEY=test_api_key_123 DIDIT_WORKFLOW_ID=wf-demo DIDIT_BASE_URL=http://127.0.0.1:8099 php examples/quickstart.php
```
