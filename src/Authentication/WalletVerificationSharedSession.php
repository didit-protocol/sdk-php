<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * A verification session shared through the Didit ID Wallet.
 */
final class WalletVerificationSharedSession extends BaseModel
{
    public readonly ?string $shareToken;

    public readonly ?string $sessionId;

    public readonly ?string $fromApplicationId;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->shareToken = Hydrate::string($data['share_token'] ?? null);
        $this->sessionId = Hydrate::string($data['session_id'] ?? null);
        $this->fromApplicationId = Hydrate::string($data['from_application_id'] ?? null);
    }
}
