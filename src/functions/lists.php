<?php

declare(strict_types=1);

/*
 * tidyr's list-column verbs: rectangling (unnest_longer, unnest_wider,
 * hoist), chopping (chop, unchop), and packing (pack, unpack). A list
 * element is a PHP array: a list is R's unnamed list or vector, and an
 * array with string keys is a named list. A scalar counts as a list of one.
 */

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;

/**
 * Turn each element of list-columns into rows: unnest_longer('tags').
 * Elements with names add an index column of the names ({col}_id); with
 * indices_include: true, unnamed elements add 1-based positions. Empty and
 * NA elements drop their row unless keep_empty: true. values_to and
 * indices_to may use {col}. Several columns must have elements of the same
 * length in each row, or length one.
 */
function unnest_longer(mixed $col, ?string $values_to = null, ?string $indices_to = null, ?bool $indices_include = null, bool $keep_empty = false): Closure
{
    return function (Table $t) use ($col, $values_to, $indices_to, $indices_include, $keep_empty) {
        $names = Selector::select([$col], $t);
        $include = [];
        foreach ($names as $c) {
            $include[$c] = $indices_include ?? array_any($t->column($c), fn ($v) => \is_array($v) && ! array_is_list($v));
        }
        [$index, $pieces] = Lists::longer($t, $names, $keep_empty, 'unnest_longer');
        $out = Ops::take($t, $index);
        foreach ($names as $c) {
            $valuesTo = strtr($values_to ?? '{col}', ['{col}' => $c]);
            $new = [];
            if ($include[$c]) {
                $named = array_any($t->column($c), fn ($v) => \is_array($v) && ! array_is_list($v));
                $new[strtr($indices_to ?? '{col}_id', ['{col}' => $c])] = array_map(
                    fn ($k) => match (true) {
                        $k === null => null,
                        $named => \is_string($k) ? $k : '',
                        default => $k + 1,
                    },
                    $pieces[$c]['keys'],
                );
            }
            $new[$valuesTo] = $pieces[$c]['values'];
            $out = Tidyr::insertAt($out, $c, $new, remove: $valuesTo !== $c);
        }

        return $out;
    };
}

/**
 * Turn each element of list-columns into columns: unnest_wider('meta').
 * Named elements give a column per name, in order of first appearance;
 * unnamed elements need names_sep, which names them {col}{sep}1,
 * {col}{sep}2... (names_sep also prefixes named ones). Missing names and NA
 * elements become NA.
 */
function unnest_wider(mixed $col, ?string $names_sep = null): Closure
{
    return function (Table $t) use ($col, $names_sep) {
        foreach (Selector::select([$col], $t) as $c) {
            $t = Tidyr::insertAt($t, $c, Lists::widerColumns($t, $c, $names_sep, 'unnest_wider'), remove: true);
        }

        return $t;
    };
}

/**
 * Pull components out of a list-column into new columns, before it:
 * hoist('meta', name: 'name', first: 1, city: ['address', 'city']).
 * A path is a name, a 1-based position, or a list of them for nested
 * elements. With _remove (the default), hoisted components are removed
 * from the list-column, and the column is dropped once every element is
 * empty.
 */
function hoist(string $col, mixed ...$paths): Closure
{
    [$paths, $o] = Internal::options($paths, ['_remove' => true], 'hoist');
    Internal::assertNamed($paths, 'hoist');

    return function (Table $t) use ($col, $paths, $o) {
        $elements = $t->column($col);
        $new = [];
        foreach ($paths as $name => $path) {
            $path = (array) $path;
            $new[$name] = array_map(fn ($e) => Lists::pluck($e, $path), $elements);
            if ($o['_remove']) {
                $elements = array_map(fn ($e) => Lists::without($e, $path), $elements);
            }
        }
        $out = Tidyr::insertAt($t, $col, $new, remove: false, newFirst: true)->mutate([$col => $elements]);
        if ($o['_remove'] && array_all($elements, fn ($e) => $e === null || $e === [])) {
            $out = $out->select(array_values(array_diff($out->columns(), [$col])));
        }

        return $out;
    };
}

/**
 * Collapse rows into list-columns: chop(['x', 'y']) gives one row per
 * combination of the other columns (in order of first appearance), with x
 * and y as lists.
 */
function chop(mixed $cols): Closure
{
    return function (Table $t) use ($cols) {
        $chopped = Selector::select([$cols], $t);
        $keys = array_values(array_diff($t->columns(), $chopped));
        $keyVectors = array_map(fn ($c) => $t->column($c), $keys);
        $first = [];
        $members = [];
        for ($i = 0, $n = $t->nrow(); $i < $n; $i++) {
            $k = implode("\x1f", array_map(fn ($v) => Internal::key($v[$i]), $keyVectors));
            if (! isset($members[$k])) {
                $first[] = $i;
                $members[$k] = [];
            }
            $members[$k][] = $i;
        }
        $new = [];
        foreach ($chopped as $c) {
            $values = $t->column($c);
            $new[$c] = array_values(array_map(fn ($rows) => array_map(fn ($r) => $values[$r], $rows), $members));
        }

        return Ops::take($t, $first)->mutate($new);
    };
}

/**
 * Expand list-columns into rows, the reverse of chop(). Empty and NA
 * elements drop their row unless keep_empty: true.
 */
function unchop(mixed $cols, bool $keep_empty = false): Closure
{
    return function (Table $t) use ($cols, $keep_empty) {
        $names = Selector::select([$cols], $t);
        [$index, $pieces] = Lists::longer($t, $names, $keep_empty, 'unchop');

        return Ops::take($t, $index)->mutate(array_map(fn ($p) => $p['values'], $pieces));
    };
}

/**
 * Pack columns into a column of named arrays (tidyr's data frame columns):
 * pack(dims: ['width', 'height']). Packed columns go at the end. With
 * _names_sep, the inner names drop their outer name and the separator.
 */
function pack(mixed ...$spec): Closure
{
    [$spec, $o] = Internal::options($spec, ['_names_sep' => null], 'pack');
    Internal::assertNamed($spec, 'pack');

    return function (Table $t) use ($spec, $o) {
        $new = [];
        $used = [];
        foreach ($spec as $name => $cols) {
            $names = Selector::select([$cols], $t);
            $inner = array_map(fn ($c) => $o['_names_sep'] !== null && str_starts_with($c, $name.$o['_names_sep']) ? substr($c, \strlen($name.$o['_names_sep'])) : $c, $names);
            $vectors = array_map(fn ($c) => $t->column($c), $names);
            $new[$name] = array_map(fn ($i) => array_combine($inner, array_column($vectors, $i)), $t->nrow() === 0 ? [] : range(0, $t->nrow() - 1));
            $used = [...$used, ...$names];
        }
        $kept = array_values(array_diff($t->columns(), $used));

        return $t->select($kept)->mutate($new);
    };
}

/** Spread columns of named arrays into columns, the reverse of pack(). */
function unpack(mixed $cols, ?string $names_sep = null): Closure
{
    return function (Table $t) use ($cols, $names_sep) {
        foreach (Selector::select([$cols], $t) as $c) {
            $t = Tidyr::insertAt($t, $c, Lists::widerColumns($t, $c, $names_sep, 'unpack', positional: true), remove: true);
        }

        return $t;
    };
}
