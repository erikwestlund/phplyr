<?php

declare(strict_types=1);

namespace Phplyr;

/** nesting(): only the combinations of these columns that occur in the data. */
final readonly class Nesting
{
    /** @param list<mixed> $columns */
    public function __construct(public array $columns)
    {
    }
}
