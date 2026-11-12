<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * The answer to {@see Authentication::submitAgentConsent()}: an approval or a denial.
 *
 * The two answers share no discriminator field. An approval carries `code`,
 * `redirectUri` and `state`; a denial carries `status` `denied` and no code.
 * Use {@see self::isApproved()} and {@see self::isDenied()}. Neither answer
 * holds an access or refresh token: the agent exchanges the code itself.
 */
final class AgentConsentResult extends BaseModel
{
    public const STATUS_DENIED = 'denied';

    /** The single-use agent authorization code (approval only), valid for 60 seconds. Never log it. */
    public readonly ?string $code;

    /** The registered redirect URI from the agent request (approval only). */
    public readonly ?string $redirectUri;

    /** The state from the agent request (approval only). */
    public readonly ?string $state;

    /** `denied` when the person declined; null for an approval. */
    public readonly ?string $status;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->code = Hydrate::string($data['code'] ?? null);
        $this->redirectUri = Hydrate::string($data['redirect_uri'] ?? null);
        $this->state = Hydrate::string($data['state'] ?? null);
        $this->status = Hydrate::string($data['status'] ?? null);
    }

    /**
     * Whether the person approved: the answer carries a code.
     */
    public function isApproved(): bool
    {
        return null !== $this->code;
    }

    /**
     * Whether the person denied: the answer's status is `denied`.
     */
    public function isDenied(): bool
    {
        return self::STATUS_DENIED === $this->status;
    }
}
