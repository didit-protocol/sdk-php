<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\Hydrate;

/**
 * The verified payload of an ID token, from
 * {@see Authentication::verifyIdToken()}.
 */
final class IdTokenClaims extends TokenClaims
{
    /** When the session started, seconds since the Unix epoch. */
    public readonly ?int $authTime;

    /** The sign-in's nonce; only ID tokens from the code exchange carry it. */
    public readonly ?string $nonce;

    public readonly ?string $email;

    /** Whether the person proved the email address. */
    public readonly ?bool $emailVerified;

    public readonly ?string $name;

    /** E.164 phone number, once the person proved it. */
    public readonly ?string $phoneNumber;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->authTime = Hydrate::int($data['auth_time'] ?? null);
        $this->nonce = Hydrate::string($data['nonce'] ?? null);
        $this->email = Hydrate::string($data['email'] ?? null);
        $this->emailVerified = Hydrate::bool($data['email_verified'] ?? null);
        $this->name = Hydrate::string($data['name'] ?? null);
        $this->phoneNumber = Hydrate::string($data['phone_number'] ?? null);
    }
}
