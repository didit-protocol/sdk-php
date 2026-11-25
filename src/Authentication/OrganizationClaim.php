<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * The `org` claim: the person's earliest membership in one of the
 * application's customer organizations.
 */
final class OrganizationClaim extends BaseModel
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';
    public const ROLE_BILLING = 'billing';

    /** The organization ID. */
    public readonly ?string $id;

    /** `admin`, `member` or `billing`. */
    public readonly ?string $role;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->id = Hydrate::string($data['id'] ?? null);
        $this->role = Hydrate::string($data['role'] ?? null);
    }
}
