# Didit webhooks in Laravel

A controller that verifies Didit webhook deliveries and a route for it.
Works with Laravel 10 and later on PHP 8.1 or later.

| File here | Where it goes in your application |
|---|---|
| [`app/Http/Controllers/DiditWebhookController.php`](app/Http/Controllers/DiditWebhookController.php) | `app/Http/Controllers/DiditWebhookController.php` |
| [`routes/api.php`](routes/api.php) | Add its `Route::post(...)` line to your `routes/api.php`. |

## Setup

1. Install the SDK: `composer require didit-protocol/sdk-php`.
2. Add the secret to `config/services.php`:

   ```php
   'didit' => [
       'webhook_secret' => env('DIDIT_WEBHOOK_SECRET'),
   ],
   ```

3. Set it in `.env` to the secret of your webhook destination (from the Didit Console, or `secretSharedKey` of `$didit->webhooks->destinations->retrieve()`):

   ```dotenv
   DIDIT_WEBHOOK_SECRET=whsec_test_your_destination_secret
   ```

   The controller reads it with `config('services.didit.webhook_secret')`, which keeps working after `php artisan config:cache`.
4. Copy the controller and add the route. On Laravel 11 and later, run `php artisan install:api` first if your application has no `routes/api.php`.
5. Point the webhook destination at `https://your-app.example/api/webhooks/didit`.

## CSRF

Routes in `routes/api.php` have no CSRF check, so nothing else is needed.
If you register the route in `routes/web.php` instead, exempt it from CSRF verification:

- Laravel 11 and later, in `bootstrap/app.php`:

  ```php
  ->withMiddleware(function (Middleware $middleware) {
      $middleware->validateCsrfTokens(except: ['webhooks/didit']);
  })
  ```

- Laravel 10, in `app/Http/Middleware/VerifyCsrfToken.php`:

  ```php
  protected $except = ['webhooks/didit'];
  ```

## Notes

- The controller verifies `$request->getContent()`, the raw body. Never verify `$request->all()` or `$request->json()`: they are decoded and re-encoded.
- `$request->headers->all()` passes every header; the signature header names are matched in any case.
- During a secret rotation, pass both secrets as an array: `Webhook::verify($body, $headers, [$newSecret, $oldSecret])`.
- Didit retries a delivery until it gets a `2xx`. Answer quickly (dispatch a queued job for slow work) and skip `event_id`s you already processed.
- Try it locally with `php artisan serve` and the signed `curl` request from [`examples/README.md`](../../README.md), sent to `http://127.0.0.1:8000/api/webhooks/didit`.
