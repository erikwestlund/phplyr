<?php

declare(strict_types=1);

namespace Phplyr;

/** closest(): a rolling-join condition for join_by(). */
final readonly class Closest
{
    public function __construct(public mixed $condition)
    {
    }
}
