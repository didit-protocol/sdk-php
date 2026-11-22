<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * The `act` claim: an AI agent acting for the person under a grant the
 * person approved.
 */
final class ActorClaim extends BaseModel
{
    /** The agent's ID. */
    public readonly ?string $sub;

    /** The agent's name. */
    public readonly ?string $agent;

    /** @var list<string>|null What the person allowed the agent to do. */
    public readonly ?array $scopes;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->sub = Hydrate::string($data['sub'] ?? null);
        $this->agent = Hydrate::string($data['agent'] ?? null);
        $this->scopes = Hydrate::list($data['scopes'] ?? null, Hydrate::string(...));
    }
}
