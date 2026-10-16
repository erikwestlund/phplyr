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
 * Verbs delegate to the Phibble\Contracts\Table methods, so any backend that
 * implements the contract works unchanged.
 *
 * Some names (count, min, max) shadow PHP built-ins inside this namespace, so
 * built-ins are always called with a leading backslash here.
 */

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;
use Phibble\Frame;

// ---------------------------------------------------------------------------
// Verbs
// ---------------------------------------------------------------------------

/**
 * Keep rows where every predicate returns true. Predicates receive
 * (Row $r, Table $group); null counts as false.
 */
function filter(Closure ...$predicates): Closure
{
    if (\count($predicates) === 1) {
        $predicate = $predicates[0];
    } else {
        $predicate = function ($r, $g) use ($predicates): bool {
            foreach ($predicates as $p) {
                if ($p($r, $g) !== true) {
                    return false;
                }
            }

            return true;
        };
    }

    return fn (Table $t) => $t->filter($predicate);
}

/** Keep the named columns, in that order. */
function select(string ...$columns): Closure
{
    return fn (Table $t) => $t->select(array_values($columns));
}

/**
 * Add or replace columns, named by argument name:
 * mutate(log_price: fn ($r) => log($r->price)).
 *
 * Values may be a closure taking (Row $r, Table $group), a list with one
 * value per row, or a scalar repeated on every row.
 */
function mutate(mixed ...$columns): Closure
{
    Internal::assertNamed($columns, 'mutate');
    $columns = array_map(
        fn ($v) => $v instanceof Closure || (\is_array($v) && array_is_list($v)) ? $v : fn () => $v,
        $columns,
    );

    return fn (Table $t) => $t->mutate($columns);
}

/** Rename columns, new name as the argument name: rename(price: 'cost'). */
function rename(string ...$map): Closure
{
    Internal::assertNamed($map, 'rename');

    return fn (Table $t) => $t->rename($map);
}

/** Sort rows by column names or desc('col'). Stable; nulls sort last. */
function arrange(string|array ...$keys): Closure
{
    return fn (Table $t) => $t->arrange(array_values($keys));
}

/** Descending sort key for arrange(). */
function desc(string $column): array
{
    return [$column, 'desc'];
}

/** Group by the named columns, replacing any existing grouping. */
function group_by(string ...$columns): Closure
{
    return fn (Table $t) => $t->groupBy(array_values($columns));
}

function ungroup(): Closure
{
    return fn (Table $t) => $t->ungroup();
}

/**
 * One row per group. Each named argument is an aggregate: a helper such as
 * n() or mean('x'), or any fn (Table $group) => value.
 */
function summarise(Closure ...$aggregates): Closure
{
    Internal::assertNamed($aggregates, 'summarise');

    return fn (Table $t) => $t->summarise($aggregates);
}

/** Alias of summarise(). */
function summarize(Closure ...$aggregates): Closure
{
    return summarise(...$aggregates);
}

/**
 * Count rows per combination of the named columns, within any existing
 * groups. The result keeps the original grouping, like dplyr.
 *
 * Options as named arguments: name: 'n' (count column), sort: false
 * (sort by count, descending).
 */
function count(string|bool ...$args): Closure
{
    $name = 'n';
    $sort = false;
    $columns = [];
    foreach ($args as $key => $value) {
        match (true) {
            $key === 'name' => $name = (string) $value,
            $key === 'sort' => $sort = (bool) $value,
            \is_int($key) && \is_string($value) => $columns[] = $value,
            default => throw new \InvalidArgumentException("count(): unknown argument '{$key}'."),
        };
    }

    return function (Table $t) use ($columns, $name, $sort) {
        $groups = $t->groups();
        $out = $t->groupBy(array_values(array_unique([...$groups, ...$columns])))
            ->summarise([$name => n()]);
        $out = $groups === [] ? $out->ungroup() : $out->groupBy($groups);

        return $sort ? $out->arrange([[$name, 'desc']]) : $out;
    };
}

/** Unique rows over the named columns (all columns when none are given). */
function distinct(string ...$columns): Closure
{
    return fn (Table $t) => $t->distinct(array_values($columns));
}

/** First $n rows of the whole table, ignoring groups. */
function head(int $n = 6): Closure
{
    return fn (Table $t) => $t->head($n);
}

/**
 * First $n rows of each group (of the whole table when ungrouped). Grouped
 * tables are materialised into a Phibble\Frame, ordered by group.
 */
function slice_head(int $n = 1): Closure
{
    return function (Table $t) use ($n) {
        if ($t->groups() === []) {
            return $t->head($n);
        }
        $rows = [];
        foreach ($t->eachGroup() as $group) {
            array_push($rows, ...$group['table']->head($n)->toRows());
        }

        return $rows === [] ? $t->slice(0, 0) : Frame::fromRows($rows)->groupBy($t->groups());
    };
}

/** Rows [$offset, $offset + $length), ignoring groups. */
function slice(int $offset, ?int $length = null): Closure
{
    return fn (Table $t) => $t->slice($offset, $length);
}

/**
 * Extract one column as a PHP list. Takes a name, or a position where
 * negative counts from the end. Defaults to the last column, like dplyr.
 */
function pull(string|int $column = -1): Closure
{
    return function (Table $t) use ($column) {
        if (\is_int($column)) {
            $columns = $t->columns();
            $index = $column < 0 ? \count($columns) + $column : $column;
            $column = $columns[$index] ?? throw new \OutOfRangeException("pull(): no column at position {$column}.");
        }

        return $t->column($column);
    };
}

// ---------------------------------------------------------------------------
// Summary helpers for summarise(). Each returns fn (Table $group) => value.
// $na_rm follows R: missing values make the result null unless dropped.
// ---------------------------------------------------------------------------

/** Number of rows in the group. */
function n(): Closure
{
    return fn (Table $g) => $g->nrow();
}

/** Number of distinct values (null counts as a value, like R). */
function n_distinct(string $column): Closure
{
    return fn (Table $g) => \count(array_unique(array_map(serialize(...), $g->column($column))));
}

function sum(string $column, bool $na_rm = false): Closure
{
    return fn (Table $g) => Stats::sum($g->column($column), $na_rm);
}

function mean(string $column, bool $na_rm = false): Closure
{
    return fn (Table $g) => Stats::mean($g->column($column), $na_rm);
}

function median(string $column, bool $na_rm = false): Closure
{
    return fn (Table $g) => Stats::median($g->column($column), $na_rm);
}

/** Sample standard deviation (n - 1). */
function sd(string $column, bool $na_rm = false): Closure
{
    return fn (Table $g) => Stats::sd($g->column($column), $na_rm);
}

/** Sample variance (n - 1). Named variance() because var is reserved in PHP. */
function variance(string $column, bool $na_rm = false): Closure
{
    return fn (Table $g) => Stats::var($g->column($column), $na_rm);
}

/** Type 7 quantile, R's default. */
function quantile(string $column, float $p, bool $na_rm = false): Closure
{
    return fn (Table $g) => Stats::quantile($g->column($column), $p, $na_rm);
}

function min(string $column, bool $na_rm = false): Closure
{
    return fn (Table $g) => Stats::min($g->column($column), $na_rm);
}

function max(string $column, bool $na_rm = false): Closure
{
    return fn (Table $g) => Stats::max($g->column($column), $na_rm);
}

/** First value in the group. */
function first(string $column): Closure
{
    return fn (Table $g) => $g->column($column)[0] ?? null;
}

/** Last value in the group. */
function last(string $column): Closure
{
    return function (Table $g) use ($column) {
        $values = $g->column($column);

        return $values === [] ? null : $values[\count($values) - 1];
    };
}

// ---------------------------------------------------------------------------
// Models. Thin adapters over php_stats' formula API (stats_lm, stats_glm),
// which takes plain column arrays. Each returns fn (Table $t) => array.
// ---------------------------------------------------------------------------

/** Linear regression: $t |> lm('y ~ a + b'). Needs stats_lm from php_stats. */
function lm(string $formula, mixed ...$options): Closure
{
    return fn (Table $t) => Internal::callStats('stats_lm', $formula, $t->toColumns(), ...$options);
}

/**
 * Generalised linear model: $t |> glm('y ~ x', family: 'binomial').
 * Needs stats_glm from php_stats.
 */
function glm(string $formula, string $family = 'gaussian', mixed ...$options): Closure
{
    return fn (Table $t) => Internal::callStats('stats_glm', $formula, $t->toColumns(), $family, ...$options);
}
