<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * A passkey registered to the approving person.
 */
final class AgentCredentialDescriptor extends BaseModel
{
    /** Base64url credential identifier. */
    public readonly ?string $id;

    /** Always `public-key`. */
    public readonly ?string $type;

    /** @var list<string>|null Optional authenticator transport hints. */
    public readonly ?array $transports;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->id = Hydrate::string($data['id'] ?? null);
        $this->type = Hydrate::string($data['type'] ?? null);
        $this->transports = Hydrate::list($data['transports'] ?? null, Hydrate::string(...));
    }
}
