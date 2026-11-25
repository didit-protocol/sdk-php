<?php

declare(strict_types=1);

namespace Didit\Tests\Contract;

use Didit\Resources\Messages;

/** PHPStan reports an unmatched ignore if the generated method accepts either invalid request. */
function checkMessagesSendParams(Messages $messages): void
{
    // @phpstan-ignore argument.type (Both message forms supplied.)
    $messages->send(['to' => 'recipient', 'body' => 'hello', 'template_id' => 'template']);
    // @phpstan-ignore argument.type (Neither message form supplied.)
    $messages->send(['to' => 'recipient']);
}
