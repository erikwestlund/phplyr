<?php

declare(strict_types=1);

namespace Phplyr;

/** A descending sort key, made by desc(). */
final readonly class Desc
{
    public function __construct(public mixed $x)
    {
    }
}
