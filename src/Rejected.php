<?php

namespace ErnestDefoe\Longshot;

use RuntimeException;

/**
 * A bet, offer or match refused for a reason the member can act on. The
 * controller turns it into a 422 (or a 409 when prices moved) whose message
 * is a translation key plus its parameters.
 */
class Rejected extends RuntimeException
{
    /**
     * @param array<string, scalar> $params
     * @param array<int, array<string, mixed>> $prices current prices, when they moved
     */
    public function __construct(public readonly string $reason, public readonly array $params = [], public readonly array $prices = [])
    {
        parent::__construct($reason);
    }
}
