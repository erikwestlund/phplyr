<?php

declare(strict_types=1);

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;

/**
 * @internal The slice_*() verbs: pick row indexes per group, then take them.
 * Groups are handled as row-index lists; a group's table is built only when
 * an expression has to be evaluated on it.
 */
final class Slice
{
    /**
     * @param Closure(int $groupSize, int $size): list<int> $pick 0-based rows within the group.
     */
    public static function run(Table $t, Closure $pick, ?int $n, ?float $prop): Table
    {
        return self::byGroup($t, fn (array $rows) => $pick(\count($rows), self::size(\count($rows), $n, $prop)));
    }

    /** @param list<int> $positions 1-based; negatives drop. */
    public static function positions(Table $t, array $positions): Table
    {
        $positive = array_filter($positions, fn ($p) => $p > 0);
        $negative = array_filter($positions, fn ($p) => $p < 0);
        if ($positive !== [] && $negative !== []) {
            throw new \InvalidArgumentException('slice(): positions must be all positive or all negative.');
        }

        return self::byGroup($t, function (array $rows) use ($positive, $negative) {
            $m = \count($rows);
            if ($negative !== []) {
                $drop = array_flip(array_map(fn ($p) => -$p - 1, $negative));

                return array_values(array_filter($m === 0 ? [] : range(0, $m - 1), fn ($i) => ! isset($drop[$i])));
            }

            return array_values(array_filter(array_map(fn ($p) => $p - 1, $positive), fn ($i) => $i < $m));
        });
    }

    public static function extreme(Table $t, mixed $orderBy, bool $max, ?int $n, ?float $prop, bool $withTies, bool $naRm): Table
    {
        [$orderBy, $reverse] = Internal::direction($orderBy);
        $max = $max !== $reverse;
        $values = self::lookup($t, $orderBy, ordinal: true);

        return self::byGroup($t, function (array $rows, Closure $group) use ($values, $max, $n, $prop, $withTies, $naRm) {
            $v = $values($rows, $group);
            $order = Window::order($v, $max);
            if (! $naRm && \count($order) < \count($v)) {
                $seen = array_flip($order);
                foreach (array_keys($v) as $i) {
                    if (! isset($seen[$i])) {
                        $order[] = $i;
                    }
                }
            }
            $size = self::size($naRm ? \count($order) : \count($v), $n, $prop);
            $picked = \array_slice($order, 0, $size);
            if ($withTies && $size > 0 && $size < \count($order)) {
                $edge = $v[$order[$size - 1]];
                for ($i = $size; $i < \count($order) && $edge !== null && $v[$order[$i]] !== null
                    && Window::compare($v[$order[$i]], $edge) === 0; $i++) {
                    $picked[] = $order[$i];
                }
            }

            return $picked;
        });
    }

    public static function sample(Table $t, ?int $n, ?float $prop, bool $replace, mixed $weightBy): Table
    {
        $weights = $weightBy === null ? null : self::lookup($t, $weightBy);

        return self::byGroup($t, function (array $rows, Closure $group) use ($n, $prop, $replace, $weights) {
            $m = \count($rows);
            $size = $replace
                ? \max(0, $n ?? ($prop !== null ? (int) \floor($prop * $m) : 1))
                : self::size($m, $n, $prop);
            if ($m === 0) {
                return [];
            }
            $w = $weights === null ? array_fill(0, $m, 1.0) : array_map(fn ($x) => (float) ($x ?? 0), $weights($rows, $group));
            $picked = [];
            // Rows with zero weight are never drawn, as in R's sample().
            $available = array_values(array_filter(range(0, $m - 1), fn ($i) => $w[$i] > 0));
            if ($available === [] && $size > 0 || (! $replace && $size > \count($available))) {
                throw new \InvalidArgumentException('slice_sample(): too few rows with positive weight to draw '.$size.'.');
            }
            for ($k = 0; $k < $size && $available !== []; $k++) {
                $total = 0.0;
                foreach ($available as $i) {
                    $total += $w[$i];
                }
                $r = mt_rand() / mt_getrandmax() * $total;
                $choice = end($available);
                foreach ($available as $pos => $i) {
                    $r -= $w[$i];
                    if ($r <= 0) {
                        $choice = $i;
                        break;
                    }
                }
                $picked[] = $choice;
                if (! $replace) {
                    $available = array_values(array_diff($available, [$choice]));
                }
            }

            return $picked;
        });
    }

    /** Rows to keep from a group of $m: n (negative: all but n), prop, or 1. */
    public static function size(int $m, ?int $n, ?float $prop): int
    {
        if ($n !== null && $prop !== null) {
            throw new \InvalidArgumentException('Give n or prop, not both.');
        }
        $k = match (true) {
            $prop !== null => (int) \floor(\abs($prop) * $m) * ($prop < 0 ? -1 : 1),
            default => $n ?? 1,
        };

        return $k < 0 ? \max(0, $m + $k) : \min($k, $m);
    }

    /**
     * Values of a column or expression for a group's rows. A column name is
     * read once for the whole table; anything else is evaluated on the group.
     *
     * @return Closure(list<int>, Closure(): Table): list<mixed>
     */
    private static function lookup(Table $t, mixed $x, bool $ordinal = false): Closure
    {
        if (\is_string($x)) {
            $column = $t->column($x);
            if ($ordinal) {
                $column = Internal::ordinal($t, $x, $column);
            }

            return fn (array $rows) => array_map(fn ($i) => $column[$i], $rows);
        }

        return fn (array $rows, Closure $group) => Vec::values($x, $group(), true);
    }

    /** @param Closure(list<int> $rows, Closure(): Table $group): list<int> $pick */
    private static function byGroup(Table $t, Closure $pick): Table
    {
        $indices = [];
        $ungrouped = null;
        foreach (Ops::groupIndex($t) as ['rows' => $rows]) {
            $group = function () use ($t, $rows, &$ungrouped) {
                $ungrouped ??= $t->ungroup();

                return Ops::take($ungrouped, $rows);
            };
            foreach ($pick($rows, $group) as $i) {
                $indices[] = $rows[$i];
            }
        }

        return Ops::take($t, $indices);
    }
}
