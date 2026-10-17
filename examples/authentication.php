<?php

declare(strict_types=1);

/*
 * Didit Authentication on your backend: verify the access token your frontend
 * sends, and create the PKCE, state and nonce values a sign-in starts with.
 *
 *     DIDIT_PUBLISHABLE_KEY=pk_test_... DIDIT_APPLICATION_ID=... \
 *         php examples/authentication.php [access-token] [--check-session]
 *
 * DIDIT_AUTH_ISSUER (for example https://your-app.auth.didit.me) works in place
 * of DIDIT_PUBLISHABLE_KEY. No API key is used: tokens are verified against
 * your application's issuer and its public keys.
 */

require __DIR__ . '/../vendor/autoload.php';

use Didit\Authentication\Authentication;
use Didit\Authentication\TokenVerificationException;
use Didit\Exception\DiditException;

$env = static function (string $name): ?string {
    $value = getenv($name);

    return false === $value || '' === $value ? null : $value;
};

// Values for the sign-in redirect: keep the verifier, state and nonce server-side
// (in the session) and send the challenge. Building the authorize URL is the
// frontend SDK's job.
$pkce = Authentication::createPkce();
echo "PKCE verifier:  {$pkce->verifier}\n";
echo "PKCE challenge: {$pkce->challenge} ({$pkce->method})\n";
echo 'state:          ' . Authentication::createState() . "\n";
echo 'nonce:          ' . Authentication::createNonce() . "\n";
// After the redirect back: $auth->exchangeCode($code, $pkce->verifier, $redirectUri).

$token = $argv[1] ?? null;
if (null === $token) {
    echo "\nPass an access token as the first argument to verify it.\n";
    exit(0);
}

$publishableKey = $env('DIDIT_PUBLISHABLE_KEY');
$issuer = $env('DIDIT_AUTH_ISSUER');
$applicationId = $env('DIDIT_APPLICATION_ID');

try {
    if (null !== $publishableKey) {
        $auth = Authentication::fromPublishableKey($publishableKey);
    } elseif (null !== $issuer) {
        $auth = new Authentication($issuer);
    } else {
        fwrite(\STDERR, "Set DIDIT_PUBLISHABLE_KEY or DIDIT_AUTH_ISSUER.\n");
        exit(1);
    }

    // Offline check: ES256 signature, issuer, expiry and, with the application id, the audience.
    // Discovery and the signing keys are fetched once and cached.
    $claims = $auth->verifyAccessToken($token, audience: $applicationId);
    echo "\nValid access token for {$claims->sub} (session {$claims->sid})\n";
    echo "  client_id:   {$claims->clientId}\n";
    echo "  vendor_data: {$claims->vendorData}\n";
    echo '  signed in with: ' . implode(', ', $claims->amr ?? []) . "\n";
    echo '  expires:     ' . date('c', $claims->exp ?? 0) . "\n";
    if (null !== $claims->org) {
        echo "  organization: {$claims->org->id} as {$claims->org->role}\n";
    }
    if (null !== $claims->verified?->level) {
        echo "  verified:    {$claims->verified->level}\n";
    }
    if (null !== $claims->act) {
        echo "  agent:       {$claims->act->agent} acting for the user\n";
    }

    // Tokens stay valid offline until they expire. For sensitive actions, ask the
    // issuer whether the session is still alive (it fails at once after a sign-out).
    if (\in_array('--check-session', $argv, true)) {
        $session = $auth->getSession($token);
        echo "  live session: {$session->sid}, expires {$session->expiresAt?->format('c')}\n";
    }
} catch (TokenVerificationException $e) {
    // `reason` is machine-readable: expired, invalid_signature, invalid_audience, ...
    fwrite(\STDERR, "Rejected token ({$e->reason}): {$e->getMessage()}\n");
    exit(1);
} catch (DiditException $e) {
    fwrite(\STDERR, 'Didit error: ' . $e->getMessage() . "\n");
    exit(1);
}
