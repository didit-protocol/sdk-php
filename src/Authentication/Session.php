<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * The live session behind an access token, from
 * {@see Authentication::getSession()}.
 */
final class Session extends BaseModel
{
    /** The identity ID. */
    public readonly ?string $sub;

    /** The session ID. */
    public readonly ?string $sid;

    public readonly ?string $applicationId;

    /** The person's reference across Didit products, as in the tokens. */
    public readonly ?string $vendorData;

    /** @var list<string>|null */
    public readonly ?array $amr;

    /** Whether the session passed a second factor. */
    public readonly ?bool $mfaVerified;

    public readonly ?string $email;

    public readonly ?bool $emailVerified;

    /** E.164 phone number, or null. */
    public readonly ?string $phone;

    /** What the Didit ID Wallet shared at sign-in, or null. */
    public readonly ?WalletVerification $verifiedIdentity;

    /** Verification level and selected claims, or null when there is none. */
    public readonly ?VerifiedClaims $verified;

    /** When the session ends at the latest; idle timeout can end it sooner. */
    public readonly ?\DateTimeImmutable $expiresAt;

    /** Where to send the person after sign-out; empty string when not set. */
    public readonly ?string $postLogoutRedirectUri;

    /** @var list<SessionOrganization>|null */
    public readonly ?array $organizations;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->sub = Hydrate::string($data['sub'] ?? null);
        $this->sid = Hydrate::string($data['sid'] ?? null);
        $this->applicationId = Hydrate::string($data['application_id'] ?? null);
        $this->vendorData = Hydrate::string($data['vendor_data'] ?? null);
        $this->amr = Hydrate::list($data['amr'] ?? null, Hydrate::string(...));
        $this->mfaVerified = Hydrate::bool($data['mfa_verified'] ?? null);
        $this->email = Hydrate::string($data['email'] ?? null);
        $this->emailVerified = Hydrate::bool($data['email_verified'] ?? null);
        $this->phone = Hydrate::string($data['phone'] ?? null);
        $this->verifiedIdentity = Hydrate::model($data['verified_identity'] ?? null, WalletVerification::class);
        $this->verified = Hydrate::model($data['verified'] ?? null, VerifiedClaims::class);
        $this->expiresAt = Hydrate::dateTime($data['expires_at'] ?? null);
        $this->postLogoutRedirectUri = Hydrate::string($data['post_logout_redirect_uri'] ?? null);
        $this->organizations = Hydrate::list($data['organizations'] ?? null, static fn (mixed $item): ?SessionOrganization => Hydrate::model($item, SessionOrganization::class));
    }
}
