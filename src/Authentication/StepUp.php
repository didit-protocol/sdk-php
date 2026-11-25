<?php

declare(strict_types=1);

namespace Didit\Authentication;

use Didit\Core\BaseModel;
use Didit\Core\Hydrate;

/**
 * A step-up: a verification the person passes before a sensitive action.
 *
 * {@see Authentication::createStepUp()} returns one with `required` false
 * and every other field null when no rule applies. Otherwise send the person
 * to {@see self::$verificationUrl}, then read the step-up with
 * {@see Authentication::retrieveStepUp()} and act only when its status is
 * `approved` ({@see self::isApproved()}); the `status` query parameter on
 * `return_to` proves nothing.
 */
final class StepUp extends BaseModel
{
    public const ACTION_PAYOUT = 'payout';
    public const ACTION_BANK = 'bank';
    public const ACTION_CONTACT = 'contact';

    public const RULE_PAYOUT = 'payout';
    public const RULE_DEVICE = 'device';
    public const RULE_BANK = 'bank';
    public const RULE_CONTACT = 'contact';

    public const METHOD_LIVENESS = 'liveness';
    public const METHOD_SELFIE = 'selfie';
    public const METHOD_FULL_ID = 'full_id';

    public const STATUS_PENDING = 'pending';
    public const STATUS_REVIEW = 'review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * False only when no rule applies (the answer was `{"required": false}`):
     * go ahead with the action.
     */
    public readonly bool $required;

    public readonly ?string $id;

    /** The rule that asked for it: `payout`, `device`, `bank` or `contact`. */
    public readonly ?string $rule;

    /** What the person does: `liveness`, `selfie` or `full_id`. */
    public readonly ?string $method;

    /** `pending`, `review`, `approved`, `declined`, `expired` or `superseded`. Act only on `approved`. */
    public readonly ?string $status;

    /** The level the verification reached, once approved. */
    public readonly ?string $level;

    /** The payout amount, two decimals. */
    public readonly ?string $amount;

    public readonly ?string $currency;

    public readonly ?\DateTimeImmutable $createdAt;

    public readonly ?\DateTimeImmutable $completedAt;

    public readonly ?\DateTimeImmutable $expiresAt;

    /** The hosted page where the person verifies (only when just created). Single use. */
    public readonly ?string $verificationUrl;

    /** Seconds the step-up stays open (only when just created). */
    public readonly ?int $expiresIn;

    public function __construct(array $data)
    {
        parent::__construct($data);
        $this->required = Hydrate::bool($data['required'] ?? null) ?? true;
        $this->id = Hydrate::string($data['id'] ?? null);
        $this->rule = Hydrate::string($data['rule'] ?? null);
        $this->method = Hydrate::string($data['method'] ?? null);
        $this->status = Hydrate::string($data['status'] ?? null);
        $this->level = Hydrate::string($data['level'] ?? null);
        $this->amount = Hydrate::string($data['amount'] ?? null);
        $this->currency = Hydrate::string($data['currency'] ?? null);
        $this->createdAt = Hydrate::dateTime($data['created_at'] ?? null);
        $this->completedAt = Hydrate::dateTime($data['completed_at'] ?? null);
        $this->expiresAt = Hydrate::dateTime($data['expires_at'] ?? null);
        $this->verificationUrl = Hydrate::string($data['verification_url'] ?? null);
        $this->expiresIn = Hydrate::int($data['expires_in'] ?? null);
    }

    /**
     * Whether the step-up was approved: the only status to act on.
     */
    public function isApproved(): bool
    {
        return self::STATUS_APPROVED === $this->status;
    }
}
