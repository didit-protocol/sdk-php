<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * Tokens for one session, from {@see Authentication::exchangeCode()} or
 * {@see Authentication::refresh()}. After a refresh, replace all three
 * tokens together: the previous refresh token no longer works.
 */
final class TokenSet extends BaseModel
{
    /** ES256 JWT (`typ: at+jwt`), valid for at most 5 minutes. */
    public readonly ?string $accessToken;

    /** ES256 JWT (`typ: JWT`) saying who signed in. Never send it as an access token. */
    public readonly ?string $idToken;

    /** Single-use: each refresh returns a new one. */
    public readonly ?string $refreshToken;

    /** Always `Bearer`. */
    public readonly ?string $tokenType;

    /** Seconds until the access token expires (at most 300). */
    public readonly ?int $expiresIn;

    /** The session the tokens belong to (the `sid` claim). */
    public readonly ?string $sessionId;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->accessToken = Hydrate::string($data['access_token'] ?? null);
        $this->idToken = Hydrate::string($data['id_token'] ?? null);
        $this->refreshToken = Hydrate::string($data['refresh_token'] ?? null);
        $this->tokenType = Hydrate::string($data['token_type'] ?? null);
        $this->expiresIn = Hydrate::int($data['expires_in'] ?? null);
        $this->sessionId = Hydrate::string($data['session_id'] ?? null);
    }
}
