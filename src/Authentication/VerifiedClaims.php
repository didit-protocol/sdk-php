<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * Identity verification the person passed, with the claims the application
 * selected. Tokens carry `{}` when there is none.
 */
final class VerifiedClaims extends BaseModel
{
    public const LEVEL_LIVENESS = 'liveness';
    public const LEVEL_LIVENESS_FACE_MATCH = 'liveness_face_match';
    public const LEVEL_FULL_ID = 'full_id';

    /** The highest level passed: `liveness`, `liveness_face_match` or `full_id`. */
    public readonly ?string $level;

    public readonly ?bool $over18;

    public readonly ?bool $over21;

    public readonly ?string $givenName;

    public readonly ?string $familyName;

    /** Date of birth from the document, `YYYY-MM-DD`. */
    public readonly ?string $birthdate;

    /** ISO 3166-1 alpha-2 country code. */
    public readonly ?string $nationality;

    /** ISO 3166-1 alpha-2 code of the document's country. */
    public readonly ?string $country;

    public readonly ?bool $documentVerified;

    public readonly ?bool $liveness;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->level = Hydrate::string($data['level'] ?? null);
        $this->over18 = Hydrate::bool($data['over_18'] ?? null);
        $this->over21 = Hydrate::bool($data['over_21'] ?? null);
        $this->givenName = Hydrate::string($data['given_name'] ?? null);
        $this->familyName = Hydrate::string($data['family_name'] ?? null);
        $this->birthdate = Hydrate::string($data['birthdate'] ?? null);
        $this->nationality = Hydrate::string($data['nationality'] ?? null);
        $this->country = Hydrate::string($data['country'] ?? null);
        $this->documentVerified = Hydrate::bool($data['document_verified'] ?? null);
        $this->liveness = Hydrate::bool($data['liveness'] ?? null);
    }
}
