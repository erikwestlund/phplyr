<?php

declare(strict_types=1);

/*
 * phplyr's verbs. Each verb returns a Closure that takes one table, so verbs
 * chain with the PHP 8.5 pipe operator:
 *
 *     $out = Frame::fromCsv('sales.csv')
 *         |> filter(fn ($r) => $r->price > 0)
 *         |> group_by('brand')
 *         |> summarise(n: n(), avg: mean('price'))
 *         |> arrange(desc('avg'));
 *
 * Verbs delegate to Phibble\Contracts\Table, so any backend works unchanged.
 *
 * dplyr's dot-arguments (.by, .keep, .groups...) are written with a leading
 * underscore: mutate(z: ..., _by: 'g'). Column names starting with an
 * underscore therefore can't be created through named arguments.
 *
 * count, min and max shadow PHP built-ins inside this namespace, so
 * built-ins are called with a leading backslash.
 */

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;
use Phibble\Frame;

/**
 * Keep rows where every condition is true; NA counts as false.
 * Conditions are row closures fn ($r, $group), vector helpers such as
 * between('x', 1, 5) or if_any(...), or a logical column name.
 * Option: _by.
 */
function filter(mixed ...$conditions): Closure
{
    [$conditions, $o] = Internal::options($conditions, ['_by' => null], 'filter');
    $conditions = array_values($conditions);

    return fn (Table $t) => Internal::withBy($t, $o['_by'], function (Table $t) use ($conditions) {
        if ($conditions !== [] && array_all($conditions, fn ($c) => $c instanceof Closure)) {
            return $t->filter(function ($r, $g) use ($conditions) {
                foreach ($conditions as $c) {
                    if ($c($r, $g) !== true) {
                        return false;
                    }
                }

                return true;
            });
        }

        return Ops::filterGroups($t, function (Table $g) use ($conditions) {
            $mask = array_fill(0, $g->nrow(), true);
            foreach ($conditions as $c) {
                foreach (Vec::values($c, $g, true) as $i => $v) {
                    $mask[$i] = $mask[$i] && $v === true;
                }
            }

            return $mask;
        });
    });
}

/**
 * Keep or drop columns: names, lists of names, selectors such as
 * starts_with('x') or except('id'). Named arguments rename: select(id: 'ID').
 */
function select(mixed ...$columns): Closure
{
    return function (Table $t) use ($columns) {
        $map = Selector::resolve($columns, $t);
        $out = $t->select(array_values($map));
        $renames = array_filter($map, fn ($source, $name) => $source !== $name, ARRAY_FILTER_USE_BOTH);

        return $renames === [] ? $out : $out->rename($renames);
    };
}

/**
 * Add, replace or drop (with null) columns, named by argument:
 * mutate(log_price: fn ($r) => log($r->price), rank: min_rank('price')).
 *
 * Values may be a row closure fn ($r, $group), a vector helper (lag, cumsum,
 * mean...; summaries recycle within groups), a list with one value per row,
 * a scalar, or null to drop the column. across(...) may be passed first.
 *
 * Options: _by, _keep ('all', 'none'), _before, _after.
 */
function mutate(mixed ...$columns): Closure
{
    [$columns, $o] = Internal::options($columns, ['_by' => null, '_keep' => 'all', '_before' => null, '_after' => null], 'mutate');
    Internal::assertNamed($columns, 'mutate');

    return fn (Table $t) => Internal::withBy($t, $o['_by'], fn (Table $t) => Mutate::run($t, $columns, $o));
}

/** Like mutate(), keeping only the grouping columns and the new columns. */
function transmute(mixed ...$columns): Closure
{
    [$columns, $o] = Internal::options($columns, ['_by' => null], 'transmute');
    Internal::assertNamed($columns, 'transmute');

    return fn (Table $t) => Internal::withBy($t, $o['_by'], function (Table $t) use ($columns) {
        $made = [];
        $out = Mutate::run($t, $columns, ['_keep' => 'all', '_before' => null, '_after' => null], $made);

        return $out->select(array_values(array_unique([...$t->groups(), ...$made])));
    });
}

/** Rename columns, new name as the argument name: rename(price: 'cost'). */
function rename(string ...$map): Closure
{
    Internal::assertNamed($map, 'rename');

    return fn (Table $t) => $t->rename($map);
}

/** Rename selected columns (all by default) with a function of the old name. */
function rename_with(Closure $fn, mixed $cols = null): Closure
{
    return function (Table $t) use ($fn, $cols) {
        $map = [];
        foreach (Selector::select([$cols ?? everything()], $t) as $old) {
            $new = (string) $fn($old);
            if ($new !== $old) {
                $map[$new] = $old;
            }
        }

        return $map === [] ? $t : $t->rename($map);
    };
}

/**
 * Move columns: to the front by default, or _before / _after a column.
 * Named arguments rename while moving.
 */
function relocate(mixed ...$columns): Closure
{
    [$columns, $o] = Internal::options($columns, ['_before' => null, '_after' => null], 'relocate');

    return function (Table $t) use ($columns, $o) {
        $map = Selector::resolve($columns, $t);
        $order = Internal::relocated($t->columns(), array_values($map), $o['_before'], $o['_after'], $t);
        $out = $t->select($order);
        $renames = array_filter($map, fn ($source, $name) => $source !== $name, ARRAY_FILTER_USE_BOTH);

        return $renames === [] ? $out : $out->rename($renames);
    };
}

/**
 * Sort rows by columns or expressions; desc() reverses. Stable; NA last.
 * Ignores grouping unless _by_group: true.
 */
function arrange(mixed ...$keys): Closure
{
    [$keys, $o] = Internal::options($keys, ['_by_group' => false], 'arrange');

    return fn (Table $t) => Internal::arrange($t, array_values($keys), (bool) $o['_by_group']);
}

/** Descending sort key for arrange(), and for rank helpers: min_rank(desc('x')). */
function desc(mixed $x): Desc
{
    return new Desc($x);
}

/**
 * Group by columns (names or selectors). Named arguments group by a computed
 * column: group_by(decade: fn ($r) => intdiv($r->year, 10) * 10).
 * Option: _add (add to the existing groups).
 */
function group_by(mixed ...$columns): Closure
{
    [$columns, $o] = Internal::options($columns, ['_add' => false], 'group_by');

    return function (Table $t) use ($columns, $o) {
        $computed = array_filter($columns, fn ($k) => \is_string($k), ARRAY_FILTER_USE_KEY);
        $selected = array_filter($columns, fn ($k) => \is_int($k), ARRAY_FILTER_USE_KEY);
        if ($computed !== []) {
            $t = Mutate::run($t, $computed, ['_keep' => 'all', '_before' => null, '_after' => null]);
        }
        $names = [...Selector::select($selected, $t), ...array_keys($computed)];
        if ($o['_add']) {
            $names = [...$t->groups(), ...$names];
        }

        return $t->groupBy(array_values(array_unique($names)));
    };
}

/** Remove grouping, or only the named grouping columns. */
function ungroup(mixed ...$columns): Closure
{
    return function (Table $t) use ($columns) {
        if ($columns === []) {
            return $t->ungroup();
        }
        $drop = Selector::select($columns, $t);
        $keep = array_values(array_diff($t->groups(), $drop));

        return $keep === [] ? $t->ungroup() : $t->groupBy($keep);
    };
}

/**
 * Treat each row as its own group, for row-wise summaries.
 * Optional id columns are kept as groups by summarise(). Needs a table that
 * supports rowwise() (phibble Table contract v1).
 */
function rowwise(string ...$idColumns): Closure
{
    return function (Table $t) use ($idColumns) {
        if (! method_exists($t, 'rowwise')) {
            throw new \LogicException('rowwise() needs a table with rowwise support (phibble Table contract v1).');
        }

        return $t->rowwise(array_values($idColumns));
    };
}

/**
 * One row per group. Each named argument is a summary: a helper such as n()
 * or mean('x'), or any fn (Table $group) => value. across(...) may be
 * passed first. Options: _by, _groups ('drop_last', 'drop', 'keep').
 */
function summarise(mixed ...$aggregates): Closure
{
    [$aggregates, $o] = Internal::options($aggregates, ['_by' => null, '_groups' => null], 'summarise');
    Internal::assertNamed($aggregates, 'summarise');

    return fn (Table $t) => Internal::withBy($t, $o['_by'], function (Table $t) use ($aggregates, $o) {
        $aggregates = Internal::summaries($t, $aggregates, 'summarise');

        return $o['_groups'] === null ? $t->summarise($aggregates) : $t->summarise($aggregates, $o['_groups']);
    });
}

/** Alias of summarise(). */
function summarize(mixed ...$aggregates): Closure
{
    return summarise(...$aggregates);
}

/**
 * Like summarise(), but each summary may return any number of values
 * (a list); they are recycled to a common length per group. The result is
 * ungrouped. Option: _by.
 */
function reframe(mixed ...$aggregates): Closure
{
    [$aggregates, $o] = Internal::options($aggregates, ['_by' => null], 'reframe');
    Internal::assertNamed($aggregates, 'reframe');

    return fn (Table $t) => Internal::withBy($t, $o['_by'], fn (Table $t) => Internal::reframe($t, $aggregates));
}

/**
 * Count rows per combination of columns, within any existing groups. The
 * result keeps the original grouping, like dplyr.
 * Options: wt (column to sum instead of counting), sort, name.
 */
function count(mixed ...$args): Closure
{
    [$columns, $wt, $sort, $name] = Internal::countArgs($args, 'count');

    return function (Table $t) use ($columns, $wt, $sort, $name) {
        $groups = $t->groups();
        $by = array_values(array_unique([...$groups, ...Selector::select($columns, $t)]));
        $out = $t->groupBy($by)->summarise([Internal::countName($t, $name) => Internal::tallyOf($wt)]);
        $out = $groups === [] ? $out->ungroup() : $out->groupBy($groups);

        return $sort ? $out->arrange([[Internal::countName($t, $name), 'desc']]) : $out;
    };
}

/** Count rows in each group (summarise(n = n())). Options: wt, sort, name. */
function tally(mixed $wt = null, bool $sort = false, ?string $name = null): Closure
{
    return function (Table $t) use ($wt, $sort, $name) {
        $name = Internal::countName($t, $name);
        $out = $t->summarise([$name => Internal::tallyOf($wt)]);

        return $sort ? $out->arrange([[$name, 'desc']]) : $out;
    };
}

/** Like count(), but adds the count as a column instead of summarising. */
function add_count(mixed ...$args): Closure
{
    [$columns, $wt, $sort, $name] = Internal::countArgs($args, 'add_count');

    return function (Table $t) use ($columns, $wt, $sort, $name) {
        $groups = $t->groups();
        $by = array_values(array_unique([...$groups, ...Selector::select($columns, $t)]));
        $name = Internal::countName($t, $name);
        $out = Ops::mutateGroups($t->groupBy($by), [$name => Internal::tallyOf($wt)->fn]);
        $out = $groups === [] ? $out->ungroup() : $out->groupBy($groups);

        return $sort ? $out->arrange([[$name, 'desc']]) : $out;
    };
}

/** Like tally(), but adds the count as a column. */
function add_tally(mixed $wt = null, bool $sort = false, ?string $name = null): Closure
{
    return function (Table $t) use ($wt, $sort, $name) {
        $name = Internal::countName($t, $name);
        $out = Ops::mutateGroups($t, [$name => Internal::tallyOf($wt)->fn]);

        return $sort ? $out->arrange([[$name, 'desc']]) : $out;
    };
}

/**
 * Unique rows over the given columns (all columns when none are given).
 * Named arguments add computed columns first. Option: _keep_all (keep every
 * column, from the first row of each combination).
 */
function distinct(mixed ...$columns): Closure
{
    [$columns, $o] = Internal::options($columns, ['_keep_all' => false], 'distinct');

    return function (Table $t) use ($columns, $o) {
        $computed = array_filter($columns, fn ($k) => \is_string($k), ARRAY_FILTER_USE_KEY);
        $selected = array_filter($columns, fn ($k) => \is_int($k), ARRAY_FILTER_USE_KEY);
        if ($computed !== []) {
            $t = Mutate::run($t, $computed, ['_keep' => 'all', '_before' => null, '_after' => null]);
        }
        $names = [...Selector::select($selected, $t), ...array_keys($computed)];
        if (! $o['_keep_all'] || $names === []) {
            return $t->distinct(array_values(array_unique($names)));
        }
        $key = array_values(array_unique([...$t->groups(), ...$names]));
        $vectors = array_map(fn ($c) => $t->column($c), $key);
        $seen = [];
        $keep = [];
        for ($i = 0, $n = $t->nrow(); $i < $n; $i++) {
            $k = implode("\x1f", array_map(fn ($v) => Internal::key($v[$i]), $vectors));
            if (! isset($seen[$k])) {
                $seen[$k] = true;
                $keep[] = $i;
            }
        }

        return Ops::take($t, $keep);
    };
}

/** First $n rows of the whole table, ignoring groups (like utils::head). */
function head(int $n = 6): Closure
{
    return fn (Table $t) => $t->head($n);
}

/**
 * Rows by 1-based position within each group, like dplyr's slice():
 * slice(1, 2), slice(range(1, 5)), or negative positions to drop rows.
 * Option: _by.
 */
function slice(int|array ...$positions): Closure
{
    [$positions, $o] = Internal::options($positions, ['_by' => null], 'slice');
    $positions = array_merge(...array_map(fn ($p) => (array) $p, array_values($positions)));

    return fn (Table $t) => Internal::withBy($t, $o['_by'], fn (Table $t) => Slice::positions($t, $positions));
}

/** First rows of each group: slice_head(n: 3) or slice_head(prop: 0.1). */
function slice_head(?int $n = null, ?float $prop = null, mixed $_by = null): Closure
{
    return fn (Table $t) => Internal::withBy($t, $_by, fn (Table $t) => Slice::run($t, fn ($g, $k) => $k === 0 ? [] : range(0, $k - 1), $n, $prop));
}

/** Last rows of each group. */
function slice_tail(?int $n = null, ?float $prop = null, mixed $_by = null): Closure
{
    return fn (Table $t) => Internal::withBy($t, $_by, fn (Table $t) => Slice::run($t, fn ($g, $k) => $k === 0 ? [] : range($g->nrow() - $k, $g->nrow() - 1), $n, $prop));
}

/** Rows with the smallest values of $order_by in each group, ordered by it. */
function slice_min(mixed $order_by, ?int $n = null, ?float $prop = null, bool $with_ties = true, bool $na_rm = false, mixed $_by = null): Closure
{
    return fn (Table $t) => Internal::withBy($t, $_by, fn (Table $t) => Slice::extreme($t, $order_by, false, $n, $prop, $with_ties, $na_rm));
}

/** Rows with the largest values of $order_by in each group, ordered by it. */
function slice_max(mixed $order_by, ?int $n = null, ?float $prop = null, bool $with_ties = true, bool $na_rm = false, mixed $_by = null): Closure
{
    return fn (Table $t) => Internal::withBy($t, $_by, fn (Table $t) => Slice::extreme($t, $order_by, true, $n, $prop, $with_ties, $na_rm));
}

/**
 * Random rows from each group. Uses mt_rand(), so mt_srand() makes it
 * reproducible. $weight_by is a column of non-negative weights.
 */
function slice_sample(?int $n = null, ?float $prop = null, bool $replace = false, mixed $weight_by = null, mixed $_by = null): Closure
{
    return fn (Table $t) => Internal::withBy($t, $_by, fn (Table $t) => Slice::sample($t, $n, $prop, $replace, $weight_by));
}

/**
 * Extract one column as a PHP list: by name, or by position like dplyr
 * (1 is the first column, -1 the last; the default). With $name, the list
 * is keyed by that column's values.
 */
function pull(string|int $var = -1, ?string $name = null): Closure
{
    return function (Table $t) use ($var, $name) {
        if (\is_int($var)) {
            $columns = $t->columns();
            $index = $var > 0 ? $var - 1 : \count($columns) + $var;
            $var = $var !== 0 && isset($columns[$index])
                ? $columns[$index]
                : throw new \OutOfRangeException("pull(): no column at position {$var}.");
        }
        $values = $t->column($var);

        return $name === null ? $values : array_combine(array_map(strval(...), $t->column($name)), $values);
    };
}

/**
 * Print a compact column-by-column summary and return the table unchanged,
 * so it can sit in the middle of a pipeline.
 */
function glimpse(int $width = 80): Closure
{
    return function (Table $t) use ($width) {
        echo Internal::glimpse($t, $width);

        return $t;
    };
}
