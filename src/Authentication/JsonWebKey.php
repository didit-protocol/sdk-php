<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * One public signing key in JWK form (RFC 7517): EC P-256 for ES256.
 */
final class JsonWebKey extends BaseModel
{
    public readonly ?string $kty;

    public readonly ?string $crv;

    /** The public point's x coordinate, base64url. */
    public readonly ?string $x;

    /** The public point's y coordinate, base64url. */
    public readonly ?string $y;

    /** Key ID, the `kid` in the header of every token this key signs. */
    public readonly ?string $kid;

    public readonly ?string $use;

    public readonly ?string $alg;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->kty = Hydrate::string($data['kty'] ?? null);
        $this->crv = Hydrate::string($data['crv'] ?? null);
        $this->x = Hydrate::string($data['x'] ?? null);
        $this->y = Hydrate::string($data['y'] ?? null);
        $this->kid = Hydrate::string($data['kid'] ?? null);
        $this->use = Hydrate::string($data['use'] ?? null);
        $this->alg = Hydrate::string($data['alg'] ?? null);
    }
}
