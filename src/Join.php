<?php

declare(strict_types=1);

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;

/** @internal Argument handling for the join verbs. */
final class Join
{
    public static function verb(string $type, Table $y, string|array|null $by, array $suffix, bool $keep, string $naMatches, string $multiple, string $unmatched, ?string $relationship): Closure
    {
        if (! \in_array($naMatches, ['na', 'never'], true)) {
            throw new \InvalidArgumentException("na_matches must be 'na' or 'never', got '{$naMatches}'.");
        }
        if (\count($suffix) !== 2) {
            throw new \InvalidArgumentException('suffix must have two strings.');
        }

        return function (Table $x) use ($type, $y, $by, $suffix, $keep, $naMatches, $multiple, $unmatched, $relationship) {
            if (! method_exists($x, 'join')) {
                throw new \LogicException('Joins need a table with join() (phibble Table contract v1).');
            }
            $pairs = $type === 'cross' ? [] : self::pairs($x, $y, $by);

            return $x->join($y, $pairs, $type, array_values($suffix), $keep, $naMatches === 'na', $multiple, $relationship, $unmatched);
        };
    }

    /**
     * Normalise by into [leftCol, rightCol] pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function pairs(Table $x, Table $y, string|array|null $by): array
    {
        if ($by === null) {
            $common = array_values(array_intersect($x->columns(), $y->columns()));
            if ($common === []) {
                throw new \InvalidArgumentException('by must be given when the tables share no columns; use cross_join() for every combination.');
            }

            return array_map(fn ($c) => [$c, $c], $common);
        }
        $pairs = [];
        foreach ((array) $by as $left => $right) {
            $pairs[] = match (true) {
                \is_array($right) && \count($right) === 2 => array_values($right),
                \is_int($left) => [$right, $right],
                default => [$left, $right],
            };
        }

        return $pairs;
    }
}
