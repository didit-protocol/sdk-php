<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * The answer to {@see Authentication::createAgentApprovalOptions()}: the
 * WebAuthn request options for the approving person.
 */
final class AgentApprovalOptions extends BaseModel
{
    /** Pass these to `navigator.credentials.get()` as `publicKey`, after decoding the base64url fields. */
    public readonly ?AgentWebAuthnOptions $options;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->options = Hydrate::model($data['options'] ?? null, AgentWebAuthnOptions::class);
    }
}
