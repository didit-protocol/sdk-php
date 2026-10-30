<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * The issuer's OpenID Connect discovery document
 * (`/.well-known/openid-configuration`).
 */
final class OpenIdConfiguration extends BaseModel
{
    /** The issuer, without a trailing slash. Every token's `iss` equals it. */
    public readonly ?string $issuer;

    /** The hosted sign-in page. */
    public readonly ?string $authorizationEndpoint;

    public readonly ?string $tokenEndpoint;

    public readonly ?string $jwksUri;

    /** @var list<string>|null */
    public readonly ?array $responseTypesSupported;

    /** @var list<string>|null */
    public readonly ?array $responseModesSupported;

    /** @var list<string>|null */
    public readonly ?array $grantTypesSupported;

    /** @var list<string>|null */
    public readonly ?array $subjectTypesSupported;

    /** @var list<string>|null */
    public readonly ?array $idTokenSigningAlgValuesSupported;

    /** @var list<string>|null */
    public readonly ?array $tokenEndpointAuthMethodsSupported;

    /** @var list<string>|null */
    public readonly ?array $codeChallengeMethodsSupported;

    /** @var list<string>|null */
    public readonly ?array $scopesSupported;

    /** @var list<string>|null */
    public readonly ?array $claimsSupported;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->issuer = Hydrate::string($data['issuer'] ?? null);
        $this->authorizationEndpoint = Hydrate::string($data['authorization_endpoint'] ?? null);
        $this->tokenEndpoint = Hydrate::string($data['token_endpoint'] ?? null);
        $this->jwksUri = Hydrate::string($data['jwks_uri'] ?? null);
        $this->responseTypesSupported = Hydrate::list($data['response_types_supported'] ?? null, Hydrate::string(...));
        $this->responseModesSupported = Hydrate::list($data['response_modes_supported'] ?? null, Hydrate::string(...));
        $this->grantTypesSupported = Hydrate::list($data['grant_types_supported'] ?? null, Hydrate::string(...));
        $this->subjectTypesSupported = Hydrate::list($data['subject_types_supported'] ?? null, Hydrate::string(...));
        $this->idTokenSigningAlgValuesSupported = Hydrate::list($data['id_token_signing_alg_values_supported'] ?? null, Hydrate::string(...));
        $this->tokenEndpointAuthMethodsSupported = Hydrate::list($data['token_endpoint_auth_methods_supported'] ?? null, Hydrate::string(...));
        $this->codeChallengeMethodsSupported = Hydrate::list($data['code_challenge_methods_supported'] ?? null, Hydrate::string(...));
        $this->scopesSupported = Hydrate::list($data['scopes_supported'] ?? null, Hydrate::string(...));
        $this->claimsSupported = Hydrate::list($data['claims_supported'] ?? null, Hydrate::string(...));
    }
}
