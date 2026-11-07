<?php

declare(strict_types=1);

/*
 * Standalone ID verification: send document images, get the extracted data
 * and the fraud checks in one call, with no session or hosted flow.
 *
 *     DIDIT_API_KEY=... php examples/id_verification.php front.jpg [back.jpg]
 */

require __DIR__ . '/../vendor/autoload.php';

use Didit\DiditClient;
use Didit\Exception\DiditException;
use Didit\File;

$front = $argv[1] ?? null;
if (null === $front) {
    fwrite(\STDERR, "Usage: php examples/id_verification.php front.jpg [back.jpg]\n");
    exit(1);
}
$back = $argv[2] ?? null;
$baseUrl = getenv('DIDIT_BASE_URL');

$didit = new DiditClient(baseUrl: false === $baseUrl || '' === $baseUrl ? null : $baseUrl);

try {
    // Upload fields also take File::fromBytes($bytes, 'front.jpg'), File::fromStream(),
    // a PSR-7 stream or an fopen() resource.
    $params = [
        'front_image' => File::fromPath($front),
        'vendor_data' => 'user-123',
    ];
    if (null !== $back) {
        $params['back_image'] = File::fromPath($back);
    }
} catch (InvalidArgumentException $e) {
    fwrite(\STDERR, $e->getMessage() . "\n");
    exit(1);
}

try {
    $result = $didit->idVerification->verify($params);
} catch (DiditException $e) {
    fwrite(\STDERR, 'Didit error: ' . $e->getMessage() . "\n");
    exit(1);
}

$document = $result->idVerification;
if (null === $document) {
    fwrite(\STDERR, "The response has no id_verification.\n");
    exit(1);
}

echo "Request {$result->requestId}: {$document->status}\n";
echo "  document:   {$document->documentType} issued by {$document->issuingState}\n";
echo "  name:       {$document->fullName}\n";
echo "  born:       {$document->dateOfBirth}\n";
echo "  expires:    {$document->expirationDate}\n";
foreach ($document->warnings ?? [] as $warning) {
    // Each warning explains one detected risk; `error` warnings decline the document.
    echo "  warning:    [{$warning->logType}] {$warning->risk}: {$warning->shortDescription}\n";
}
