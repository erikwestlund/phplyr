<?php

declare(strict_types=1);

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;

/** @internal The slice_*() verbs: pick row indexes per group, then take them. */
final class Slice
{
    /**
     * @param Closure(Table $group, int $size): list<int> $pick 0-based rows within the group.
     */
    public static function run(Table $t, Closure $pick, ?int $n, ?float $prop): Table
    {
        return self::byGroup($t, fn (Table $g) => $pick($g, self::size($g->nrow(), $n, $prop)));
    }

    /** @param list<int> $positions 1-based; negatives drop. */
    public static function positions(Table $t, array $positions): Table
    {
        $positive = array_filter($positions, fn ($p) => $p > 0);
        $negative = array_filter($positions, fn ($p) => $p < 0);
        if ($positive !== [] && $negative !== []) {
            throw new \InvalidArgumentException('slice(): positions must be all positive or all negative.');
        }

        return self::byGroup($t, function (Table $g) use ($positive, $negative) {
            $m = $g->nrow();
            if ($negative !== []) {
                $drop = array_map(fn ($p) => -$p - 1, $negative);

                return array_values(array_diff(range(0, \max(0, $m - 1)), $drop, $m === 0 ? [0] : []));
            }

            return array_values(array_filter(array_map(fn ($p) => $p - 1, $positive), fn ($i) => $i < $m));
        });
    }

    public static function extreme(Table $t, mixed $orderBy, bool $max, ?int $n, ?float $prop, bool $withTies, bool $naRm): Table
    {
        return self::byGroup($t, function (Table $g) use ($orderBy, $max, $n, $prop, $withTies, $naRm) {
            $values = Vec::values($orderBy, $g, true);
            $order = Window::order($values, $max);
            if (! $naRm) {
                foreach (array_keys($values) as $i) {
                    if (! \in_array($i, $order, true)) {
                        $order[] = $i;
                    }
                }
            }
            $size = self::size($naRm ? \count($order) : $g->nrow(), $n, $prop);
            $picked = \array_slice($order, 0, $size);
            if ($withTies && $size > 0 && $size < \count($order)) {
                $edge = $values[$order[$size - 1]];
                for ($i = $size; $i < \count($order) && $edge !== null && $values[$order[$i]] !== null
                    && Window::compare($values[$order[$i]], $edge) === 0; $i++) {
                    $picked[] = $order[$i];
                }
            }

            return $picked;
        });
    }

    public static function sample(Table $t, ?int $n, ?float $prop, bool $replace, mixed $weightBy): Table
    {
        return self::byGroup($t, function (Table $g) use ($n, $prop, $replace, $weightBy) {
            $m = $g->nrow();
            $size = $replace && $n !== null ? \max(0, $n) : self::size($m, $n, $prop);
            if ($m === 0) {
                return [];
            }
            $weights = $weightBy === null ? array_fill(0, $m, 1.0) : array_map(fn ($w) => (float) ($w ?? 0), Vec::values($weightBy, $g, true));
            $picked = [];
            $available = range(0, $m - 1);
            for ($k = 0; $k < $size && $available !== []; $k++) {
                $total = array_sum(array_map(fn ($i) => $weights[$i], $available));
                $r = mt_rand() / mt_getrandmax() * $total;
                $choice = end($available);
                foreach ($available as $pos => $i) {
                    $r -= $weights[$i];
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
            $prop !== null => (int) floor(abs($prop) * $m) * ($prop < 0 ? -1 : 1),
            default => $n ?? 1,
        };

        return $k < 0 ? \max(0, $m + $k) : \min($k, $m);
    }

    /** @param Closure(Table): list<int> $pick */
    private static function byGroup(Table $t, Closure $pick): Table
    {
        $indices = [];
        foreach (Ops::groupRows($t) as ['table' => $g, 'rows' => $rows]) {
            foreach ($pick($g) as $i) {
                $indices[] = $rows[$i];
            }
        }

        return Ops::take($t, $indices);
    }
}
