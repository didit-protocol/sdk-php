<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\Hydrate;

/**
 * The verified payload of an access token, from
 * {@see Authentication::verifyAccessToken()}.
 */
final class AccessTokenClaims extends TokenClaims
{
    /** The application ID. */
    public readonly ?string $clientId;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->clientId = Hydrate::string($data['client_id'] ?? null);
    }
}
