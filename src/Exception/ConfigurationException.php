<?php

declare(strict_types=1);

namespace Didit\Exception;

/**
 * The client is missing something it needs before it can send a request:
 * an API key, an organization or application id, an account token or a
 * webhook secret. Thrown before anything is sent.
 */
class ConfigurationException extends DiditException
{
}
