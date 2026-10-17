<?php

declare(strict_types=1);

namespace Didit\Webhooks\Events;

use Didit\Core\Hydrate;
use Didit\Webhooks\Event;

/**
 * `kyb.registry_search.resolved`: an asynchronous KYB registry search
 * (`kyb.search` with `webhook_url`) finished.
 *
 * Didit sends this callback unsigned today (header
 * `X-Didit-Unsigned-Callback: true`, type in `event_type`), so
 * {@see \Didit\Webhooks\Webhook::verify()} rejects it with
 * `missing_signature`. Build it with
 * {@see \Didit\Webhooks\Event::fromPayload()} and treat its content as
 * untrusted: fetch the result from the API before acting on it.
 */
final class KybRegistrySearchResolvedEvent extends Event
{
    /** The search request, as returned by `kyb.search`. */
    public readonly ?string $requestId;

    /** Your identifier given with the search. */
    public readonly ?string $vendorData;

    /** @var array<string, mixed>|null the metadata given with the search */
    public readonly ?array $metadata;

    /** Search status, for example `completed`. */
    public readonly ?string $searchStatus;

    /** Whether the registry search finished. */
    public readonly ?bool $searchResolved;

    /** @var array<string, mixed>|null the candidates (`companies`) and pagination */
    public readonly ?array $kybRegistry;

    /**
     * @param array<string, mixed> $data the decoded JSON body
     */
    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->requestId = Hydrate::string($data['request_id'] ?? null);
        $this->vendorData = Hydrate::string($data['vendor_data'] ?? null);
        $this->metadata = Hydrate::assoc($data['metadata'] ?? null);
        $this->searchStatus = Hydrate::string($data['search_status'] ?? null);
        $this->searchResolved = Hydrate::bool($data['search_resolved'] ?? null);
        $this->kybRegistry = Hydrate::assoc($data['kyb_registry'] ?? null);
    }
}
