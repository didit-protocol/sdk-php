<?php

declare(strict_types=1);

/*
 * AML screening: check a person against sanctions, PEP and watchlists.
 *
 *     DIDIT_API_KEY=... php examples/aml_screening.php ["Full Name"] [YYYY-MM-DD]
 *
 * With a sandbox API key, DIDIT_SANDBOX_SCENARIO=decline_aml_hit (or
 * review_aml_possible_match) returns a simulated hit.
 */

require __DIR__ . '/../vendor/autoload.php';

use Didit\DiditClient;
use Didit\Exception\DiditException;

$fullName = $argv[1] ?? 'Jane Example';
$dateOfBirth = $argv[2] ?? '1990-05-17';
$baseUrl = getenv('DIDIT_BASE_URL');
$scenario = getenv('DIDIT_SANDBOX_SCENARIO');

$didit = new DiditClient(baseUrl: false === $baseUrl || '' === $baseUrl ? null : $baseUrl);

$params = [
    'full_name' => $fullName,
    'date_of_birth' => $dateOfBirth, // improves match precision
    'entity_type' => 'person',
    'vendor_data' => 'user-123',
];
if (false !== $scenario && '' !== $scenario) {
    $params['sandbox_scenario'] = $scenario;
}

try {
    $result = $didit->aml->screen($params);
} catch (DiditException $e) {
    fwrite(\STDERR, 'Didit error: ' . $e->getMessage() . "\n");
    exit(1);
}

$aml = $result->aml;
if (null === $aml) {
    fwrite(\STDERR, "The response has no aml result.\n");
    exit(1);
}

// `status` follows `score` (0-100) and your approve / review thresholds.
echo "Screened {$fullName} ({$dateOfBirth}): {$aml->status}\n";
echo "  score: {$aml->score}, hits: {$aml->totalHits}\n";
foreach ($aml->hits ?? [] as $hit) {
    $datasets = implode(', ', $hit->datasets ?? []);
    echo "  - {$hit->caption}: match score {$hit->matchScore}, risk score {$hit->riskScore}, datasets [{$datasets}], review {$hit->reviewStatus}\n";
}
foreach ($aml->warnings ?? [] as $warning) {
    echo "  warning: {$warning->risk}: {$warning->shortDescription}\n";
}
