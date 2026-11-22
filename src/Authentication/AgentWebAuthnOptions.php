<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * WebAuthn request options for a fresh agent approval.
 */
final class AgentWebAuthnOptions extends BaseModel
{
    /** Base64url challenge, bound to this approval and valid for less than 300 seconds. */
    public readonly ?string $challenge;

    /** The relying-party hostname. */
    public readonly ?string $rpId;

    /** The browser timeout in milliseconds; it does not extend the server expiry. */
    public readonly ?int $timeout;

    /** Always `required`. */
    public readonly ?string $userVerification;

    /** @var list<AgentCredentialDescriptor>|null Only the approving person's enrolled passkeys. */
    public readonly ?array $allowCredentials;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->challenge = Hydrate::string($data['challenge'] ?? null);
        $this->rpId = Hydrate::string($data['rpId'] ?? null);
        $this->timeout = Hydrate::int($data['timeout'] ?? null);
        $this->userVerification = Hydrate::string($data['userVerification'] ?? null);
        $this->allowCredentials = Hydrate::list($data['allowCredentials'] ?? null, static fn (mixed $credential): ?AgentCredentialDescriptor => Hydrate::model($credential, AgentCredentialDescriptor::class));
    }
}
