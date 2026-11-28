<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * The issuer's published signing keys (`/.well-known/jwks.json`).
 */
final class JsonWebKeySet extends BaseModel
{
    /** @var list<JsonWebKey>|null */
    public readonly ?array $keys;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->keys = Hydrate::list($data['keys'] ?? null, static fn (mixed $key): ?JsonWebKey => Hydrate::model($key, JsonWebKey::class));
    }
}
