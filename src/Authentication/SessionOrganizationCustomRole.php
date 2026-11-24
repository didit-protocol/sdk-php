<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * A custom role in a customer organization.
 */
final class SessionOrganizationCustomRole extends BaseModel
{
    public readonly ?string $key;

    /** @var list<string>|null */
    public readonly ?array $permissions;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->key = Hydrate::string($data['key'] ?? null);
        $this->permissions = Hydrate::list($data['permissions'] ?? null, Hydrate::string(...));
    }
}
