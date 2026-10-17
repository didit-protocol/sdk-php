<?php

declare(strict_types=1);

namespace Didit\Core;

/**
 * Base of every generated resource class.
 *
 * @internal
 */
abstract class BaseResource
{
    public function __construct(
        protected readonly Requester $requester,
    ) {
    }
}
