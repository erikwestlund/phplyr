<?php

declare(strict_types=1);

namespace Phplyr;

use Phibble\Contracts\Table;

/** @internal Element handling for the list-column verbs. */
final class Lists
{
    /**
     * Rows for unnest_longer() / unchop(): each input row repeated once per
     * element, with each column's keys and values.
     *
     * @param list<string> $names
     * @return array{0: list<int>, 1: array<string, array{keys: list<int|string|null>, values: list<mixed>}>}
     */
    public static function longer(Table $t, array $names, bool $keepEmpty, string $verb): array
    {
        $vectors = array_map(fn ($c) => $t->column($c), array_combine($names, $names));
        $index = [];
        $pieces = array_fill_keys($names, ['keys' => [], 'values' => []]);
        for ($i = 0, $n = $t->nrow(); $i < $n; $i++) {
            $elements = array_map(fn ($v) => self::elements($v[$i]), $vectors);
            $len = \max(array_map('count', $elements));
            if ($len === 0) {
                if ($keepEmpty) {
                    $index[] = $i;
                    foreach ($names as $c) {
                        $pieces[$c]['keys'][] = null;
                        $pieces[$c]['values'][] = null;
                    }
                }

                continue;
            }
            foreach ($elements as $c => $e) {
                $m = \count($e);
                if ($m !== $len && $m > 1) {
                    throw new \LengthException("{$verb}(): in row ".($i + 1).", '{$c}' has {$m} elements and another column has {$len}.");
                }
                $keys = $m === 0 ? array_fill(0, $len, null) : array_keys($e);
                $values = $m === 0 ? array_fill(0, $len, null) : array_values($e);
                for ($j = 0; $j < $len; $j++) {
                    $pieces[$c]['keys'][] = $keys[$m === 1 ? 0 : $j];
                    $pieces[$c]['values'][] = $values[$m === 1 ? 0 : $j];
                }
            }
            for ($j = 0; $j < $len; $j++) {
                $index[] = $i;
            }
        }

        return [$index, $pieces];
    }

    /**
     * Columns for unnest_wider() / unpack() in place of $col, named in order
     * of first appearance. Names already used by other columns are an
     * error, as in tidyr.
     *
     * @return array<string, list<mixed>>
     */
    public static function widerColumns(Table $t, string $col, ?string $sep, string $verb, bool $positional = false): array
    {
        $new = self::wider($t->column($col), $col, $sep, $verb, $positional);
        $taken = array_values(array_intersect(array_keys($new), array_diff($t->columns(), [$col])));
        if ($taken !== []) {
            throw new \InvalidArgumentException("{$verb}(): the new columns would duplicate existing names: ".implode(', ', $taken).'. Use names_sep, or rename first.');
        }

        return $new;
    }

    /**
     * New columns for unnest_wider() / unpack(), named in order of first
     * appearance.
     *
     * @param list<mixed> $column
     * @return array<string, list<mixed>>
     */
    public static function wider(array $column, string $col, ?string $sep, string $verb, bool $positional = false): array
    {
        $rows = [];
        $order = [];
        foreach ($column as $i => $v) {
            $row = [];
            foreach (self::elements($v) as $k => $x) {
                if (\is_int($k) && $sep === null && ! $positional) {
                    throw new \InvalidArgumentException("{$verb}(): row ".($i + 1)." of '{$col}' has elements without names. Give names_sep to name them by position.");
                }
                $name = \is_int($k) ? (string) ($k + 1) : $k;
                $name = $sep === null ? $name : $col.$sep.$name;
                $row[$name] = $x;
                $order[$name] = true;
            }
            $rows[] = $row;
        }
        $out = [];
        foreach (array_keys($order) as $name) {
            $out[(string) $name] = array_map(fn ($row) => $row[$name] ?? null, $rows);
        }

        return $out;
    }

    /**
     * An element as an array: NA is empty, a scalar is a list of one, and
     * a table is its rows.
     *
     * @return array<int|string, mixed>
     */
    public static function elements(mixed $v): array
    {
        return match (true) {
            $v === null => [],
            \is_array($v) => $v,
            $v instanceof Table => $v->toRows(),
            default => [$v],
        };
    }

    /**
     * The component at a path of names and 1-based positions, or NA.
     *
     * @param list<int|string> $path
     */
    public static function pluck(mixed $element, array $path): mixed
    {
        foreach ($path as $step) {
            if (! \is_array($element)) {
                return null;
            }
            $key = self::keyAt($element, $step);
            if ($key === null) {
                return null;
            }
            $element = $element[$key];
        }

        return $element;
    }

    /**
     * The element with the component at a path removed.
     *
     * @param list<int|string> $path
     */
    public static function without(mixed $element, array $path): mixed
    {
        if (! \is_array($element) || $path === []) {
            return $element;
        }
        $step = array_shift($path);
        $key = self::keyAt($element, $step);
        if ($key === null) {
            return $element;
        }
        if ($path === []) {
            $wasList = array_is_list($element);
            unset($element[$key]);

            return $wasList ? array_values($element) : $element;
        }
        $element[$key] = self::without($element[$key], $path);

        return $element;
    }

    /** The array key for a path step: a name, or a 1-based position. */
    private static function keyAt(array $element, int|string $step): int|string|null
    {
        if (\is_string($step)) {
            return \array_key_exists($step, $element) ? $step : null;
        }
        $keys = array_keys($element);

        return $keys[$step - 1] ?? null;
    }
}
