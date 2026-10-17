<?php

declare(strict_types=1);

namespace Phplyr;

use Phibble\Contracts\Table;
use Phibble\DataFrame;

/** @internal Helpers for set operations and rows_*(). */
final class Rows
{
    /** y with x's columns, in x's order; all must be present unless $partial. */
    public static function aligned(Table $x, Table $y, bool $partial = false): Table
    {
        $missing = array_diff($x->columns(), $y->columns());
        $extra = array_diff($y->columns(), $x->columns());
        if ($extra !== []) {
            throw new \InvalidArgumentException('y has columns that x lacks: '.implode(', ', $extra).'.');
        }
        if ($missing !== [] && ! $partial) {
            throw new \InvalidArgumentException('y lacks columns of x: '.implode(', ', $missing).'.');
        }

        return $y->select(array_values(array_intersect($x->columns(), $y->columns())));
    }

    /** @return list<string> */
    public static function by(Table $y, string|array|null $by): array
    {
        return $by === null ? [$y->columns()[0]] : array_values((array) $by);
    }

    /** @return list<string> One key per row. */
    public static function keyList(Table $t, array $cols): array
    {
        $vectors = array_map(fn ($c) => $t->column($c), $cols);
        $out = [];
        for ($i = 0, $n = $t->nrow(); $i < $n; $i++) {
            $out[] = implode("\x1f", array_map(fn ($v) => Internal::key($v[$i]), $vectors));
        }

        return $out;
    }

    /** @return array<string, int> Key => first row. */
    public static function keys(Table $t, array $cols): array
    {
        $out = [];
        foreach (self::keyList($t, $cols) as $i => $k) {
            $out[$k] ??= $i;
        }

        return $out;
    }

    /** Rows of $t whose key over $cols (all columns by default) is, or isn't, in $keys. */
    public static function keep(Table $t, array $keys, bool $present, ?array $cols = null): Table
    {
        $cols ??= $t->columns();
        $rows = [];
        foreach (self::keyList($t, $cols) as $i => $k) {
            if (isset($keys[$k]) === $present) {
                $rows[] = $i;
            }
        }

        return Ops::take($t, $rows);
    }

    /** rows_update() / rows_patch(). */
    public static function modify(Table $x, Table $y, array $by, string $verb, string $unmatched, bool $patch): Table
    {
        $y = self::aligned($x, $y, partial: true);
        $yKeys = self::keys($y, $by);
        if (\count($yKeys) !== $y->nrow()) {
            throw new \UnexpectedValueException("{$verb}(): y's keys must be unique.");
        }
        $xKeys = self::keyList($x, $by);
        if ($unmatched === 'error') {
            $present = array_flip($xKeys);
            foreach (array_keys($yKeys) as $k) {
                if (! isset($present[$k])) {
                    throw new \UnexpectedValueException("{$verb}(): y has keys that aren't in x. Use unmatched: 'ignore'.");
                }
            }
        }
        $updates = [];
        foreach (array_diff($y->columns(), $by) as $col) {
            $xs = $x->column($col);
            $ys = $y->column($col);
            foreach ($xKeys as $i => $k) {
                if (isset($yKeys[$k]) && (! $patch || $xs[$i] === null)) {
                    $xs[$i] = $ys[$yKeys[$k]];
                }
            }
            $updates[$col] = $xs;
        }

        return $updates === [] ? $x : $x->mutate($updates);
    }
}
