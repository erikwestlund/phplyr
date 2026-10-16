<?php

declare(strict_types=1);

/*
 * Vector and summary helpers. Each returns a Vec, evaluated per group by
 * mutate(), filter() and summarise(). When every argument can be inspected,
 * the Vec carries a Phibble\Expr, so the same helper works on in-memory and
 * database tables.
 *
 * Argument convention: the first argument of a helper is its input column.
 * A string there is a column name; it can also be col('x'), another helper,
 * or a row closure fn ($r) => .... Later arguments are values, so a string
 * there is a literal; wrap it in col() to use a column.
 */

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;
use Phibble\Expr\Col;
use Phibble\Expr\Lit;

/** A column as a vector: mutate(y: col('x')), col('price')->mul(1.1). */
function col(string $name): Vec
{
    return Vec::of(new Col($name));
}

/** A literal value as a vector. */
function lit(mixed $value): Vec
{
    return Vec::of(new Lit($value));
}

/** A row closure as a vector, for use where a Vec is expected. */
function vec(Closure $fn): Vec
{
    return new Vec(fn (Table $g) => Vec::values($fn, $g));
}

// ---------------------------------------------------------------------------
// Summary helpers: one value per group (recycled inside mutate).
// ---------------------------------------------------------------------------

/** Number of rows in the group. */
function n(): Vec
{
    return Internal::call('n', []);
}

/** Number of distinct values (or combinations); NA counts unless na_rm. */
function n_distinct(mixed ...$columns): Vec
{
    $naRm = (bool) ($columns['na_rm'] ?? false);
    unset($columns['na_rm']);
    $columns = array_values($columns);

    return Internal::call('n_distinct', $columns, Internal::naRm($naRm), columns: array_keys($columns));
}

function sum(mixed $x, bool $na_rm = false): Vec
{
    return Internal::call('sum', [$x], Internal::naRm($na_rm));
}

function mean(mixed $x, bool $na_rm = false): Vec
{
    return Internal::call('mean', [$x], Internal::naRm($na_rm));
}

function median(mixed $x, bool $na_rm = false): Vec
{
    return Internal::call('median', [$x], Internal::naRm($na_rm));
}

/** Sample standard deviation (n - 1). */
function sd(mixed $x, bool $na_rm = false): Vec
{
    return Internal::call('sd', [$x], Internal::naRm($na_rm));
}

/** Sample variance (n - 1). Named variance() because var is reserved in PHP. */
function variance(mixed $x, bool $na_rm = false): Vec
{
    return Internal::call('var', [$x], Internal::naRm($na_rm));
}

/** Type 7 quantile, R's default. */
function quantile(mixed $x, float $p, bool $na_rm = false): Vec
{
    if ($p < 0.0 || $p > 1.0) {
        throw new \InvalidArgumentException("Quantile probability must be in [0, 1], got {$p}.");
    }

    return Internal::call('quantile', [$x, $p], Internal::naRm($na_rm));
}

/** Interquartile range (type 7). */
function iqr(mixed $x, bool $na_rm = false): Vec
{
    return Internal::call('iqr', [$x], Internal::naRm($na_rm));
}

function min(mixed $x, bool $na_rm = false): Vec
{
    return Internal::call('min', [$x], Internal::naRm($na_rm));
}

function max(mixed $x, bool $na_rm = false): Vec
{
    return Internal::call('max', [$x], Internal::naRm($na_rm));
}

/** First value; $default when the group is empty. */
function first(mixed $x, mixed $default = null, mixed $order_by = null, bool $na_rm = false): Vec
{
    return Internal::call('first', [$x], Internal::positionOptions($default, $order_by, $na_rm));
}

/** Last value; $default when the group is empty. */
function last(mixed $x, mixed $default = null, mixed $order_by = null, bool $na_rm = false): Vec
{
    return Internal::call('last', [$x], Internal::positionOptions($default, $order_by, $na_rm));
}

/** The $n-th value, 1-based; negative counts from the end. */
function nth(mixed $x, int $n, mixed $default = null, mixed $order_by = null, bool $na_rm = false): Vec
{
    return Internal::call('nth', [$x, $n], Internal::positionOptions($default, $order_by, $na_rm));
}

/** True when any value is true (R's any(); NA when undecided). */
function any(mixed $x, bool $na_rm = false): Vec
{
    return Internal::call('any', [$x], Internal::naRm($na_rm));
}

/** True when all values are true (R's all(); NA when undecided). */
function all(mixed $x, bool $na_rm = false): Vec
{
    return Internal::call('all', [$x], Internal::naRm($na_rm));
}

// ---------------------------------------------------------------------------
// Vector helpers: one value per row.
// ---------------------------------------------------------------------------

/** Vectorised if/else. A null condition gives $missing. */
function if_else(mixed $condition, mixed $true, mixed $false, mixed $missing = null): Vec
{
    return Internal::call('if_else', [$condition, $true, $false], $missing === null ? [] : ['missing' => $missing]);
}

/**
 * The value of the first case whose condition is true, else $default.
 * Cases are [condition, value] pairs:
 * case_when([fn ($r) => $r->x < 0, 'neg'], [fn ($r) => $r->x > 0, 'pos'], default: 'zero').
 */
function case_when(mixed ...$cases): Vec
{
    $default = $cases['default'] ?? null;
    unset($cases['default']);
    $args = [];
    foreach ($cases as $case) {
        if (! \is_array($case) || ! array_is_list($case) || \count($case) !== 2) {
            throw new \InvalidArgumentException('case_when() cases must be [condition, value] pairs.');
        }
        array_push($args, ...$case);
    }
    if ($args === []) {
        throw new \InvalidArgumentException('case_when() needs at least one [condition, value] pair.');
    }

    return Internal::call('case_when', $args, $default === null ? [] : ['default' => $default], columns: array_values(array_filter(array_keys($args), fn ($i) => $i % 2 === 0)));
}

/**
 * Like case_when(), but rows matching no case keep x's value:
 * replace_when('score', [fn ($r) => $r->score > 8, 8]).
 */
function replace_when(mixed $x, array ...$cases): Vec
{
    return case_when(...$cases, default: \is_string($x) ? col($x) : $x);
}

/** First non-NA value across the arguments, row by row. */
function coalesce(mixed $x, mixed ...$values): Vec
{
    return Internal::call('coalesce', [$x, ...$values]);
}

/** NA where $x equals $y. */
function na_if(mixed $x, mixed $y): Vec
{
    return Internal::call('na_if', [$x, $y]);
}

/** $left <= $x <= $right; NA when $x is NA. */
function between(mixed $x, mixed $left, mixed $right): Vec
{
    return Internal::call('between', [$x, $left, $right]);
}

/** Equality with tolerance, for floating point. */
function near(mixed $x, mixed $y, ?float $tol = null): Vec
{
    return Internal::call('near', [$x, $y], $tol === null ? [] : ['tol' => $tol]);
}

/** Previous values: lag('x'), lag('x', 2, default: 0, order_by: 'year'). */
function lag(mixed $x, int $n = 1, mixed $default = null, mixed $order_by = null): Vec
{
    if ($n < 0) {
        throw new \InvalidArgumentException('lag() needs n >= 0; use lead().');
    }

    return Internal::call('lag', [$x], Internal::shiftOptions($n, $default, $order_by));
}

/** Next values: lead('x'), lead('x', 2, default: 0, order_by: 'year'). */
function lead(mixed $x, int $n = 1, mixed $default = null, mixed $order_by = null): Vec
{
    if ($n < 0) {
        throw new \InvalidArgumentException('lead() needs n >= 0; use lag().');
    }

    return Internal::call('lead', [$x], Internal::shiftOptions($n, $default, $order_by));
}

/** Row numbers: 1..n, or ranks of $x with ties broken by position. */
function row_number(mixed $x = null): Vec
{
    return Internal::call('row_number', $x === null ? [] : [$x]);
}

/** Ranks with ties sharing the lowest rank (1, 2, 2, 4); min_rank(desc('x')) reverses. */
function min_rank(mixed $x): Vec
{
    return Internal::call('min_rank', [$x]);
}

/** Ranks with no gaps after ties (1, 2, 2, 3). */
function dense_rank(mixed $x): Vec
{
    return Internal::call('dense_rank', [$x]);
}

/** min_rank rescaled to [0, 1]. */
function percent_rank(mixed $x): Vec
{
    return Internal::call('percent_rank', [$x]);
}

/** Proportion of values less than or equal to each value. */
function cume_dist(mixed $x): Vec
{
    return Internal::call('cume_dist', [$x]);
}

/** Split into $n buckets of near-equal size: ntile('x', 4), or ntile(n: 4) by row. */
function ntile(mixed $x = null, int $n = 1): Vec
{
    if ($n <= 0) {
        throw new \InvalidArgumentException('ntile() needs a positive number of buckets.');
    }

    return Internal::call('ntile', $x === null ? [] : [$x], ['n' => $n]);
}

/** A new id each time the value (or combination) changes. */
function consecutive_id(mixed ...$xs): Vec
{
    $xs = array_values($xs);

    return Internal::call('consecutive_id', $xs, columns: array_keys($xs));
}

function cumsum(mixed $x): Vec
{
    return Internal::call('cumsum', [$x]);
}

function cumprod(mixed $x): Vec
{
    return Internal::call('cumprod', [$x]);
}

function cummin(mixed $x): Vec
{
    return Internal::call('cummin', [$x]);
}

function cummax(mixed $x): Vec
{
    return Internal::call('cummax', [$x]);
}

/** Running mean. */
function cummean(mixed $x): Vec
{
    return Internal::call('cummean', [$x]);
}

/** Running all(): false from the first false on; NA while undecided. */
function cumall(mixed $x): Vec
{
    return Internal::call('cumall', [$x]);
}

/** Running any(): true from the first true on; NA while undecided. */
function cumany(mixed $x): Vec
{
    return Internal::call('cumany', [$x]);
}

/**
 * Elementwise any() across conditions (x | y | z): when_any(fn ($r) => ..., 'flag').
 * With na_rm, NA inputs are ignored.
 */
function when_any(mixed ...$conditions): Vec
{
    $naRm = (bool) ($conditions['na_rm'] ?? false);
    unset($conditions['na_rm']);

    return Internal::when(array_values($conditions), $naRm, any: true);
}

/** Elementwise all() across conditions (x & y & z). */
function when_all(mixed ...$conditions): Vec
{
    $naRm = (bool) ($conditions['na_rm'] ?? false);
    unset($conditions['na_rm']);

    return Internal::when(array_values($conditions), $naRm, any: false);
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
 * Match values like a switch (deprecated in dplyr 1.2 in favor of recode_values()):
 * case_match('code', [['a', 'b'], 'vowel'], ['z', 'last'], default: 'other').
 */
function case_match(mixed $x, mixed ...$cases): Vec
{
    return Internal::recode($x, $cases, replace: false);
}

/**
 * Replace values by name (superseded in dplyr by recode_values()):
 * recode('grade', a: 'A', b: 'B', _default: 'other'), or with a map for keys
 * that aren't valid names: recode('code', [1 => 'one', 2 => 'two']).
 * The first argument is dplyr's .x. Values match by their string form. Unmatched values stay as they are
 * unless _default is given; NA becomes _missing.
 */
function recode(mixed $_x, mixed ...$replacements): Vec
{
    $hasDefault = \array_key_exists('_default', $replacements);
    [$replacements, $o] = Internal::options($replacements, ['_default' => null, '_missing' => null], 'recode');
    $map = [];
    foreach ($replacements as $key => $value) {
        if (\is_int($key) && \is_array($value)) {
            foreach ($value as $from => $to) {
                $map[(string) $from] = $to;
            }
        } else {
            $map[(string) $key] = $value;
        }
    }

    return new Vec(fn (Table $g) => array_map(
        fn ($v) => match (true) {
            $v === null || (\is_float($v) && is_nan($v)) => $o['_missing'],
            \array_key_exists(Tidyr::str($v), $map) => $map[Tidyr::str($v)],
            $hasDefault => $o['_default'],
            default => $v,
        },
        Vec::values($_x, $g, true),
    ));
}

/**
 * Evaluate a window function with the rows in another order, then put the
 * results back: order_by('year', cumsum('score')). Wrap the order in desc()
 * to reverse it; NA sorts last.
 */
function order_by(mixed $order_by, Vec $call): Vec
{
    return new Vec(function (Table $g) use ($order_by, $call) {
        [$by, $desc] = Internal::direction($order_by);
        $values = Vec::values($by, $g, true);
        $idx = Window::order($values, $desc);
        $idx = [...$idx, ...array_values(array_diff(array_keys($values), $idx))];
        $results = Vec::values($call, Ops::take($g->ungroup(), $idx));
        $out = array_fill(0, \count($values), null);
        foreach ($idx as $j => $i) {
            $out[$i] = $results[$j];
        }

        return $out;
    });
}
