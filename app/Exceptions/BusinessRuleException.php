<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A request that is valid in shape but not allowed by a business rule
 * (not enough stock, expired batch, locked units). Answered as HTTP 409
 * with a machine-readable code the screens translate.
 */
class BusinessRuleException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(public readonly string $ruleCode, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return new JsonResponse(['code' => $this->ruleCode, 'message' => $this->getMessage()] + $this->details, 409);
    }
}
