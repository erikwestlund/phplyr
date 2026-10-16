<?php

declare(strict_types=1);

/*
 * Vector and summary helpers. Each returns a Vec, evaluated per group by
 * mutate(), filter() and summarise().
 *
 * Argument convention: the first argument of a helper is its input column.
 * A string there is a column name; it can also be col('x'), another helper,
 * or a row closure fn ($r) => .... Later arguments are values, so a string
 * there is a literal; wrap it in col() to use a column.
 */

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;

/** A column as a vector: mutate(y: col('x')). */
function col(string $name): Vec
{
    return new Vec(fn (Table $g) => $g->column($name));
}

/** A row closure or callable as a vector, for use where a Vec is expected. */
function vec(Closure $fn): Vec
{
    return new Vec(fn (Table $g) => Vec::values($fn, $g));
}

// ---------------------------------------------------------------------------
// Summary helpers: one value per group.
// ---------------------------------------------------------------------------

/** Number of rows in the group. */
function n(): Vec
{
    return new Vec(fn (Table $g) => $g->nrow());
}

/** Number of distinct values (or combinations); NA counts unless $na_rm. */
function n_distinct(mixed ...$columns): Vec
{
    $naRm = (bool) ($columns['na_rm'] ?? false);
    unset($columns['na_rm']);

    return new Vec(function (Table $g) use ($columns, $naRm) {
        $vectors = array_map(fn ($c) => Vec::values($c, $g, true), array_values($columns));
        $seen = [];
        for ($i = 0, $n = $g->nrow(); $i < $n; $i++) {
            $tuple = array_map(fn ($v) => $v[$i], $vectors);
            if ($naRm && \in_array(null, $tuple, true)) {
                continue;
            }
            $seen[serialize($tuple)] = true;
        }

        return \count($seen);
    });
}

function sum(mixed $x, bool $na_rm = false): Vec
{
    return new Vec(fn (Table $g) => Stats::sum(Vec::values($x, $g, true), $na_rm));
}

function mean(mixed $x, bool $na_rm = false): Vec
{
    return new Vec(fn (Table $g) => Stats::mean(Vec::values($x, $g, true), $na_rm));
}

function median(mixed $x, bool $na_rm = false): Vec
{
    return new Vec(fn (Table $g) => Stats::median(Vec::values($x, $g, true), $na_rm));
}

/** Sample standard deviation (n - 1). */
function sd(mixed $x, bool $na_rm = false): Vec
{
    return new Vec(fn (Table $g) => Stats::sd(Vec::values($x, $g, true), $na_rm));
}

/** Sample variance (n - 1). Named variance() because var is reserved in PHP. */
function variance(mixed $x, bool $na_rm = false): Vec
{
    return new Vec(fn (Table $g) => Stats::var(Vec::values($x, $g, true), $na_rm));
}

/** Type 7 quantile, R's default. */
function quantile(mixed $x, float $p, bool $na_rm = false): Vec
{
    return new Vec(fn (Table $g) => Stats::quantile(Vec::values($x, $g, true), $p, $na_rm));
}

/** Interquartile range (type 7). */
function iqr(mixed $x, bool $na_rm = false): Vec
{
    return new Vec(function (Table $g) use ($x, $na_rm) {
        $v = Vec::values($x, $g, true);
        $q3 = Stats::quantile($v, 0.75, $na_rm);

        return $q3 === null ? null : $q3 - Stats::quantile($v, 0.25, $na_rm);
    });
}

function min(mixed $x, bool $na_rm = false): Vec
{
    return new Vec(fn (Table $g) => Stats::min(Vec::values($x, $g, true), $na_rm));
}

function max(mixed $x, bool $na_rm = false): Vec
{
    return new Vec(fn (Table $g) => Stats::max(Vec::values($x, $g, true), $na_rm));
}

/** First value; $default when the group is empty. */
function first(mixed $x, mixed $default = null, bool $na_rm = false): Vec
{
    return nth($x, 1, $default, $na_rm);
}

/** Last value; $default when the group is empty. */
function last(mixed $x, mixed $default = null, bool $na_rm = false): Vec
{
    return nth($x, -1, $default, $na_rm);
}

/** The $n-th value, 1-based; negative counts from the end. */
function nth(mixed $x, int $n, mixed $default = null, bool $na_rm = false): Vec
{
    return new Vec(function (Table $g) use ($x, $n, $default, $na_rm) {
        $values = Vec::values($x, $g, true);
        if ($na_rm) {
            $values = array_values(array_filter($values, fn ($v) => $v !== null));
        }
        $i = $n > 0 ? $n - 1 : \count($values) + $n;

        return $n !== 0 && $i >= 0 && $i < \count($values) ? $values[$i] : $default;
    });
}

/** True when any value is true (R's any(); NA when undecided). */
function any(mixed $x, bool $na_rm = false): Vec
{
    return new Vec(function (Table $g) use ($x, $na_rm) {
        $values = Vec::values($x, $g, true);
        if (\in_array(true, $values, true)) {
            return true;
        }

        return ! $na_rm && \in_array(null, $values, true) ? null : false;
    });
}

/** True when all values are true (R's all(); NA when undecided). */
function all(mixed $x, bool $na_rm = false): Vec
{
    return new Vec(function (Table $g) use ($x, $na_rm) {
        $values = Vec::values($x, $g, true);
        if (\in_array(false, $values, true)) {
            return false;
        }

        return ! $na_rm && \in_array(null, $values, true) ? null : true;
    });
}

// ---------------------------------------------------------------------------
// Vector helpers: one value per row.
// ---------------------------------------------------------------------------

/**
 * Vectorised if/else. A null condition gives $missing.
 */
function if_else(mixed $condition, mixed $true, mixed $false, mixed $missing = null): Vec
{
    return new Vec(function (Table $g) use ($condition, $true, $false, $missing) {
        $c = Vec::values($condition, $g, true);
        $t = Vec::values($true, $g);
        $f = Vec::values($false, $g);
        $m = Vec::values($missing, $g);

        return array_map(fn ($i) => $c[$i] === null ? $m[$i] : ($c[$i] ? $t[$i] : $f[$i]), array_keys($c));
    });
}

/**
 * The value of the first case whose condition is true, else $default.
 * Cases are [condition, value] pairs:
 * case_when([fn ($r) => $r->x < 0, 'neg'], [fn ($r) => $r->x > 0, 'pos'], default: 'zero').
 */
function case_when(mixed ...$cases): Vec
{
    $default = null;
    if (\array_key_exists('default', $cases)) {
        $default = $cases['default'];
        unset($cases['default']);
    }
    foreach ($cases as $case) {
        if (! \is_array($case) || ! array_is_list($case) || \count($case) !== 2) {
            throw new \InvalidArgumentException('case_when() cases must be [condition, value] pairs.');
        }
    }

    return new Vec(function (Table $g) use ($cases, $default) {
        $n = $g->nrow();
        $out = Vec::values($default, $g);
        $done = array_fill(0, $n, false);
        foreach ($cases as [$condition, $value]) {
            $c = Vec::values($condition, $g, true);
            $v = Vec::values($value, $g);
            for ($i = 0; $i < $n; $i++) {
                if (! $done[$i] && $c[$i] === true) {
                    $out[$i] = $v[$i];
                    $done[$i] = true;
                }
            }
        }

        return $out;
    });
}

/** First non-NA value across the arguments, row by row. */
function coalesce(mixed $x, mixed ...$values): Vec
{
    return new Vec(function (Table $g) use ($x, $values) {
        $out = Vec::values($x, $g, true);
        foreach ($values as $value) {
            $v = Vec::values($value, $g);
            foreach ($out as $i => $current) {
                $out[$i] = $current ?? $v[$i];
            }
        }

        return $out;
    });
}

/** NA where $x equals $y. */
function na_if(mixed $x, mixed $y): Vec
{
    return new Vec(function (Table $g) use ($x, $y) {
        $xs = Vec::values($x, $g, true);
        $ys = Vec::values($y, $g);

        return array_map(fn ($a, $b) => $a !== null && $a == $b ? null : $a, $xs, $ys);
    });
}

/** $left <= $x <= $right; NA when $x is NA. */
function between(mixed $x, mixed $left, mixed $right): Vec
{
    return new Vec(function (Table $g) use ($x, $left, $right) {
        $xs = Vec::values($x, $g, true);
        $ls = Vec::values($left, $g);
        $rs = Vec::values($right, $g);

        return array_map(
            fn ($v, $l, $r) => $v === null || $l === null || $r === null ? null : $v >= $l && $v <= $r,
            $xs, $ls, $rs,
        );
    });
}

/** Equality with tolerance, for floating point. */
function near(mixed $x, mixed $y, float $tol = 1.4901161193847656e-8): Vec
{
    return new Vec(function (Table $g) use ($x, $y, $tol) {
        return array_map(
            fn ($a, $b) => $a === null || $b === null ? null : abs($a - $b) < $tol,
            Vec::values($x, $g, true),
            Vec::values($y, $g),
        );
    });
}

/** Previous values: lag('x'), lag('x', 2, default: 0, order_by: 'year'). */
function lag(mixed $x, int $n = 1, mixed $default = null, mixed $order_by = null): Vec
{
    if ($n < 0) {
        throw new \InvalidArgumentException('lag() needs n >= 0; use lead().');
    }

    return Internal::ordered($x, $order_by, fn (array $v) => Window::shift($v, $n, $default));
}

/** Next values: lead('x'), lead('x', 2, default: 0, order_by: 'year'). */
function lead(mixed $x, int $n = 1, mixed $default = null, mixed $order_by = null): Vec
{
    if ($n < 0) {
        throw new \InvalidArgumentException('lead() needs n >= 0; use lag().');
    }

    return Internal::ordered($x, $order_by, fn (array $v) => Window::shift($v, -$n, $default));
}

/** Row numbers: 1..n, or ranks of $x with ties broken by position. */
function row_number(mixed $x = null): Vec
{
    if ($x === null) {
        return new Vec(fn (Table $g) => $g->nrow() === 0 ? [] : range(1, $g->nrow()));
    }

    return Internal::ranked($x, fn (array $v, bool $desc) => Window::rank($v, 'first', $desc));
}

/** Ranks with ties sharing the lowest rank (1, 2, 2, 4). */
function min_rank(mixed $x): Vec
{
    return Internal::ranked($x, fn (array $v, bool $desc) => Window::rank($v, 'min', $desc));
}

/** Ranks with no gaps after ties (1, 2, 2, 3). */
function dense_rank(mixed $x): Vec
{
    return Internal::ranked($x, fn (array $v, bool $desc) => Window::rank($v, 'dense', $desc));
}

/** min_rank rescaled to [0, 1]. */
function percent_rank(mixed $x): Vec
{
    return Internal::ranked($x, fn (array $v, bool $desc) => Window::percentRank($v, $desc));
}

/** Proportion of values less than or equal to each value. */
function cume_dist(mixed $x): Vec
{
    return Internal::ranked($x, fn (array $v, bool $desc) => Window::cumeDist($v, $desc));
}

/** Split into $n buckets of near-equal size: ntile('x', 4), or ntile(n: 4) by row. */
function ntile(mixed $x = null, int $n = 1): Vec
{
    $rows = row_number($x);

    return new Vec(fn (Table $g) => Window::ntile($rows($g), $n));
}

/** A new id each time the value (or combination) changes. */
function consecutive_id(mixed ...$xs): Vec
{
    return new Vec(function (Table $g) use ($xs) {
        $vectors = array_map(fn ($x) => Vec::values($x, $g, true), $xs);
        $out = [];
        $id = 0;
        $prev = null;
        for ($i = 0, $n = $g->nrow(); $i < $n; $i++) {
            $key = serialize(array_map(fn ($v) => $v[$i], $vectors));
            if ($key !== $prev) {
                $id++;
                $prev = $key;
            }
            $out[] = $id;
        }

        return $out;
    });
}

function cumsum(mixed $x): Vec
{
    return new Vec(fn (Table $g) => Window::cumulate(Vec::values($x, $g, true), fn ($a, $v) => $a + $v));
}

function cumprod(mixed $x): Vec
{
    return new Vec(fn (Table $g) => Window::cumulate(Vec::values($x, $g, true), fn ($a, $v) => $a * $v));
}

function cummin(mixed $x): Vec
{
    return new Vec(fn (Table $g) => Window::cumulate(Vec::values($x, $g, true), fn ($a, $v) => $v < $a ? $v : $a));
}

function cummax(mixed $x): Vec
{
    return new Vec(fn (Table $g) => Window::cumulate(Vec::values($x, $g, true), fn ($a, $v) => $v > $a ? $v : $a));
}

/** Running mean. */
function cummean(mixed $x): Vec
{
    return new Vec(function (Table $g) use ($x) {
        $sums = Window::cumulate(Vec::values($x, $g, true), fn ($a, $v) => $a + $v);

        return array_map(fn ($s, $i) => $s === null ? null : $s / ($i + 1), $sums, array_keys($sums));
    });
}

/** Running all(): false from the first false on; NA while undecided. */
function cumall(mixed $x): Vec
{
    return new Vec(function (Table $g) use ($x) {
        $out = [];
        $state = true;
        foreach (Vec::values($x, $g, true) as $v) {
            if ($state !== false) {
                $state = $v === false ? false : ($v === null || $state === null ? null : true);
            }
            $out[] = $state;
        }

        return $out;
    });
}

/** Running any(): true from the first true on; NA while undecided. */
function cumany(mixed $x): Vec
{
    return new Vec(function (Table $g) use ($x) {
        $out = [];
        $state = false;
        foreach (Vec::values($x, $g, true) as $v) {
            if ($state !== true) {
                $state = $v === true ? true : ($v === null || $state === null ? null : false);
            }
            $out[] = $state;
        }

        return $out;
    });
}

/**
 * Match values like a switch: case_match('code', [['a', 'b'], 'vowel'], ['z', 'last'], default: 'other').
 * Each case is [value or list of values, result]; NA matches null.
 */
function case_match(mixed $x, mixed ...$cases): Vec
{
    $default = null;
    if (\array_key_exists('default', $cases)) {
        $default = $cases['default'];
        unset($cases['default']);
    }

    return new Vec(function (Table $g) use ($x, $cases, $default) {
        $values = Vec::values($x, $g, true);
        $out = Vec::values($default, $g);
        $done = array_fill(0, \count($values), false);
        foreach ($cases as [$match, $result]) {
            $keys = array_flip(array_map(Internal::key(...), \is_array($match) ? $match : [$match]));
            $results = Vec::values($result, $g);
            foreach ($values as $i => $v) {
                if (! $done[$i] && isset($keys[Internal::key($v)])) {
                    $out[$i] = $results[$i];
                    $done[$i] = true;
                }
            }
        }

        return $out;
    });
}

/**
 * Elementwise any() across conditions (x | y | z): when_any(fn ($r) => ..., 'flag').
 * With na_rm, NA inputs are ignored.
 */
function when_any(mixed ...$conditions): Vec
{
    $naRm = (bool) ($conditions['na_rm'] ?? false);
    unset($conditions['na_rm']);

    return Internal::when($conditions, $naRm, any: true);
}

/** Elementwise all() across conditions (x & y & z). */
function when_all(mixed ...$conditions): Vec
{
    $naRm = (bool) ($conditions['na_rm'] ?? false);
    unset($conditions['na_rm']);

    return Internal::when($conditions, $naRm, any: false);
}

/**
 * Map values to new values (dplyr 1.2): recode_values('state', ['NC', 'North Carolina'], [['NY', 'NYC'], 'New York'], default: 'other'),
 * or with a lookup table: recode_values('score', from: [1, 2, 3], to: ['low', 'mid', 'high']).
 * Unmatched values get default (NA by default); unmatched: 'error' throws instead.
 */
function recode_values(mixed $x, mixed ...$cases): Vec
{
    return Internal::recode($x, $cases, replace: false);
}

/** Like recode_values(), but unmatched values keep their original value. */
function replace_values(mixed $x, mixed ...$cases): Vec
{
    return Internal::recode($x, $cases, replace: true);
}

/**
 * Like case_when(), but rows matching no case keep x's value:
 * replace_when('score', [fn ($r) => $r->score > 8, 8]).
 */
function replace_when(mixed $x, array ...$cases): Vec
{
    return case_when(...$cases, default: $x instanceof Vec || ! \is_string($x) ? $x : col($x));
}
