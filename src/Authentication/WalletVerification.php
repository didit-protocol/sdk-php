<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * What the Didit ID Wallet shared at sign-in.
 */
final class WalletVerification extends BaseModel
{
    /** The wallet's issuer. */
    public readonly ?string $issuer;

    /** The person's ID at the wallet. */
    public readonly ?string $subject;

    /** A verification the person chose to share, redeemed with your own API key. */
    public readonly ?WalletVerificationSharedSession $sharedSession;

    /** @var array<string, bool|string>|null The verified facts the person agreed to share. */
    public readonly ?array $claims;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->issuer = Hydrate::string($data['issuer'] ?? null);
        $this->subject = Hydrate::string($data['subject'] ?? null);
        $this->sharedSession = Hydrate::model($data['shared_session'] ?? null, WalletVerificationSharedSession::class);
        $this->claims = Hydrate::map($data['claims'] ?? null, static fn (mixed $value): bool|string|null => \is_bool($value) || \is_string($value) ? $value : null);
    }
}
