<?php

declare(strict_types=1);

namespace Phplyr;

/**
 * @internal Window functions over plain lists, with dplyr's semantics:
 * NA (null) stays NA in ranks, and cumulative functions propagate NA.
 */
final class Window
{
    /**
     * Compare two non-null values the way R orders them: numbers
     * numerically, strings by byte (C locale), booleans false first.
     */
    public static function compare(mixed $a, mixed $b): int
    {
        if (\is_string($a) && \is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }

        return $a <=> $b;
    }

    /**
     * Positions of the non-null values in sorted order (stable).
     *
     * @param list<mixed> $values
     * @return list<int>
     */
    public static function order(array $values, bool $desc = false): array
    {
        $idx = array_keys(array_filter($values, fn ($v) => $v !== null && ! (\is_float($v) && is_nan($v))));
        usort($idx, function (int $a, int $b) use ($values, $desc) {
            $c = self::compare($values[$a], $values[$b]);

            return ($desc ? -$c : $c) ?: $a <=> $b;
        });

        return $idx;
    }

    /**
     * Ranks with ties broken by method: first, min, max or dense.
     *
     * @param list<mixed> $values
     * @return list<int|null>
     */
    public static function rank(array $values, string $ties, bool $desc = false): array
    {
        $order = self::order($values, $desc);
        $out = array_fill(0, \count($values), null);
        $n = \count($order);
        $dense = 0;
        for ($i = 0; $i < $n;) {
            $j = $i;
            if ($ties !== 'first') {
                while ($j + 1 < $n && self::compare($values[$order[$j + 1]], $values[$order[$i]]) === 0) {
                    $j++;
                }
            }
            $dense++;
            for ($k = $i; $k <= $j; $k++) {
                $out[$order[$k]] = match ($ties) {
                    'first' => $k + 1,
                    'min' => $i + 1,
                    'max' => $j + 1,
                    'dense' => $dense,
                };
            }
            $i = $j + 1;
        }

        return $out;
    }

    /**
     * @param list<mixed> $values
     * @return list<float|null>
     */
    public static function percentRank(array $values, bool $desc = false): array
    {
        $ranks = self::rank($values, 'min', $desc);
        $m = \count(array_filter($ranks, fn ($r) => $r !== null));

        return array_map(fn ($r) => $r === null ? null : ($m > 1 ? ($r - 1) / ($m - 1) : NAN), $ranks);
    }

    /**
     * @param list<mixed> $values
     * @return list<float|null>
     */
    public static function cumeDist(array $values, bool $desc = false): array
    {
        $ranks = self::rank($values, 'max', $desc);
        $m = \count(array_filter($ranks, fn ($r) => $r !== null));

        return array_map(fn ($r) => $r === null ? null : $r / $m, $ranks);
    }

    /**
     * dplyr 1.1's ntile(): bins that differ in size by at most one, larger
     * bins first.
     *
     * @param list<int|null> $rowNumbers
     * @return list<int|null>
     */
    public static function ntile(array $rowNumbers, int $n): array
    {
        if ($n <= 0) {
            throw new \InvalidArgumentException('ntile() needs a positive number of buckets.');
        }
        $len = \count(array_filter($rowNumbers, fn ($x) => $x !== null));
        if ($len === 0) {
            return array_fill(0, \count($rowNumbers), null);
        }
        $nLarger = $len % $n;
        $nSmaller = $n - $nLarger;
        $size = $len / $n;
        $largerSize = (int) ceil($size);
        $smallerSize = (int) \floor($size);
        $threshold = $largerSize * $nLarger;

        return array_map(function ($x) use ($threshold, $largerSize, $smallerSize, $nLarger) {
            if ($x === null) {
                return null;
            }

            return $x <= $threshold
                ? intdiv($x + $largerSize - 1, $largerSize)
                : intdiv($x - $threshold + $smallerSize - 1, $smallerSize) + $nLarger;
        }, $rowNumbers);
    }

    /**
     * Cumulative fold that turns NA for good once it meets NA, like R.
     *
     * @param list<mixed> $values
     * @param \Closure(mixed, mixed): mixed $step
     * @return list<mixed>
     */
    public static function cumulate(array $values, \Closure $step): array
    {
        $out = [];
        $acc = null;
        $na = false;
        foreach ($values as $i => $v) {
            if ($na || $v === null) {
                $na = true;
                $out[] = null;

                continue;
            }
            $acc = $i === 0 ? $v : $step($acc, $v);
            $out[] = $acc;
        }

        return $out;
    }

    /**
     * Shift values by $n (positive lags, negative leads), filling with $default.
     *
     * @param list<mixed> $values
     * @return list<mixed>
     */
    public static function shift(array $values, int $n, mixed $default): array
    {
        $len = \count($values);
        $out = [];
        for ($i = 0; $i < $len; $i++) {
            $j = $i - $n;
            $out[] = $j >= 0 && $j < $len ? $values[$j] : $default;
        }

        return $out;
    }
}
