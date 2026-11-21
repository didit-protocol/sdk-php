<?php

declare(strict_types=1);

namespace Didit\Tests\Live;

use Didit\DiditClient;
use Didit\Exception\NotFoundException;
use Didit\Types\WorkflowListItem;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Smoke tests against a real sandbox application. They run only when
 * DIDIT_SANDBOX_API_KEY is set (`composer test:live`), never in default CI,
 * and create nothing but one sandbox session, which they delete again.
 *
 * Optional: DIDIT_SANDBOX_WORKFLOW_ID (otherwise the first workflow is used)
 * and DIDIT_SANDBOX_BASE_URL.
 *
 * @internal
 */
#[Group('live')]
final class SandboxSmokeTest extends TestCase
{
    private DiditClient $didit;

    protected function setUp(): void
    {
        $apiKey = self::env('DIDIT_SANDBOX_API_KEY');
        if (null === $apiKey) {
            self::markTestSkipped('Set DIDIT_SANDBOX_API_KEY to run the live smoke tests.');
        }
        $this->didit = new DiditClient(apiKey: $apiKey, baseUrl: self::env('DIDIT_SANDBOX_BASE_URL'), maxRetries: 1);
    }

    public function testListWorkflows(): void
    {
        $page = $this->didit->workflows->list(['limit' => 5]);

        self::assertSame(200, $page->rawResponse()?->status);
        self::assertContainsOnlyInstancesOf(WorkflowListItem::class, $page->results);
    }

    public function testSessionLifecycle(): void
    {
        $workflowId = self::env('DIDIT_SANDBOX_WORKFLOW_ID') ?? $this->firstWorkflowId();

        $session = $this->didit->sessions->create([
            'workflow_id' => $workflowId,
            'vendor_data' => 'sdk-php-smoke-' . bin2hex(random_bytes(4)),
            'metadata' => ['source' => 'sdk-php live smoke test'],
        ]);
        self::assertNotNull($session->sessionId);
        self::assertNotNull($session->url);

        try {
            $decision = $this->didit->sessions->retrieveDecision($session->sessionId);
            self::assertSame($session->sessionId, $decision->sessionId);
            self::assertNotNull($decision->status);

            $found = false;
            foreach ($this->didit->sessions->list(['limit' => 20, 'vendor_data' => $session->vendorData ?? '']) as $listed) {
                $found = $found || $listed->get('session_id') === $session->sessionId;
            }
            self::assertTrue($found, 'The new session is listed.');
        } finally {
            $this->didit->sessions->delete($session->sessionId);
        }

        $this->expectException(NotFoundException::class);
        $this->didit->sessions->retrieveDecision($session->sessionId);
    }

    private function firstWorkflowId(): string
    {
        foreach ($this->didit->workflows->list(['limit' => 1])->results as $workflow) {
            if (null !== $workflow->workflowId) {
                return $workflow->workflowId;
            }
        }
        self::markTestSkipped('The sandbox application has no workflow; set DIDIT_SANDBOX_WORKFLOW_ID.');
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return \is_string($value) && '' !== $value ? $value : null;
    }
}
