<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * Claims shared by access and ID tokens. Every claim the token carries,
 * documented or not, stays readable through `toArray()` and array access.
 */
abstract class TokenClaims extends BaseModel
{
    /** The issuer; verification checked that it equals the configured one. */
    public readonly ?string $iss;

    /** The application ID, when `aud` is a single string. */
    public readonly ?string $aud;

    /** The person's identity ID in this application. */
    public readonly ?string $sub;

    /** The session ID; for an agent token, the agent's grant ID. */
    public readonly ?string $sid;

    /** The token's unique ID. */
    public readonly ?string $jti;

    /** Issue time, seconds since the Unix epoch. */
    public readonly ?int $iat;

    /** Expiry, seconds since the Unix epoch. */
    public readonly ?int $exp;

    /** The person's reference across Didit products. */
    public readonly ?string $vendorData;

    /** @var list<string>|null How the session signed in (`password`, `passkey`, ...). */
    public readonly ?array $amr;

    /** The person's organization membership, or null. */
    public readonly ?OrganizationClaim $org;

    /** Identity verification the person passed. */
    public readonly ?VerifiedClaims $verified;

    /** The AI agent acting for the person, or null for the person's own session. */
    public readonly ?ActorClaim $act;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->iss = Hydrate::string($data['iss'] ?? null);
        $this->aud = Hydrate::string($data['aud'] ?? null);
        $this->sub = Hydrate::string($data['sub'] ?? null);
        $this->sid = Hydrate::string($data['sid'] ?? null);
        $this->jti = Hydrate::string($data['jti'] ?? null);
        $this->iat = Hydrate::int($data['iat'] ?? null);
        $this->exp = Hydrate::int($data['exp'] ?? null);
        $this->vendorData = Hydrate::string($data['vendor_data'] ?? null);
        $this->amr = Hydrate::list($data['amr'] ?? null, Hydrate::string(...));
        $this->org = Hydrate::model($data['org'] ?? null, OrganizationClaim::class);
        $this->verified = Hydrate::model($data['verified'] ?? null, VerifiedClaims::class);
        $this->act = Hydrate::model($data['act'] ?? null, ActorClaim::class);
    }
}
