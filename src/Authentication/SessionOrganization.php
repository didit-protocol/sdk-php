<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * A customer organization the person belongs to.
 */
final class SessionOrganization extends BaseModel
{
    public readonly ?string $organizationId;

    /** The organization's name (wire field `organization__name`). */
    public readonly ?string $organizationName;

    /** `admin`, `member` or `billing`. */
    public readonly ?string $role;

    /** The custom role assigned to the member, or null. */
    public readonly ?SessionOrganizationCustomRole $customRole;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->organizationId = Hydrate::string($data['organization_id'] ?? null);
        $this->organizationName = Hydrate::string($data['organization__name'] ?? null);
        $this->role = Hydrate::string($data['role'] ?? null);
        $this->customRole = Hydrate::model($data['custom_role'] ?? null, SessionOrganizationCustomRole::class);
    }
}
