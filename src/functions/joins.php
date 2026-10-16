<?php

declare(strict_types=1);

/*
 * Two-table verbs with dplyr 1.1 semantics. The table's join() does the
 * work (phibble Table contract v1), so backends can run joins natively.
 *
 * by: null joins on the columns both tables share; 'id' or ['id', 'year']
 * joins on same-named columns; ['left' => 'right'] maps differing names;
 * the forms mix: ['id', 'code' => 'country_code']. join_by() adds
 * inequality, rolling (closest) and overlap conditions.
 *
 * keep: null (dplyr's default) merges equality keys and keeps both sides'
 * columns for inequality conditions; true keeps every key; false merges
 * equality keys and is an error for non-equality joins.
 */

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;

/** Rows of x with a match in y, with y's columns. */
function inner_join(Table $y, string|array|JoinBy|null $by = null, array $suffix = ['.x', '.y'], ?bool $keep = null, string $na_matches = 'na', string $multiple = 'all', string $unmatched = 'drop', ?string $relationship = null): Closure
{
    return Join::verb('inner', $y, $by, $suffix, $keep, $na_matches, $multiple, $unmatched, $relationship);
}

/** All rows of x, with y's columns where they match (NA otherwise). */
function left_join(Table $y, string|array|JoinBy|null $by = null, array $suffix = ['.x', '.y'], ?bool $keep = null, string $na_matches = 'na', string $multiple = 'all', string $unmatched = 'drop', ?string $relationship = null): Closure
{
    return Join::verb('left', $y, $by, $suffix, $keep, $na_matches, $multiple, $unmatched, $relationship);
}

/** All rows of y, with x's columns where they match. */
function right_join(Table $y, string|array|JoinBy|null $by = null, array $suffix = ['.x', '.y'], ?bool $keep = null, string $na_matches = 'na', string $multiple = 'all', string $unmatched = 'drop', ?string $relationship = null): Closure
{
    return Join::verb('right', $y, $by, $suffix, $keep, $na_matches, $multiple, $unmatched, $relationship);
}

/** All rows of both tables. */
function full_join(Table $y, string|array|JoinBy|null $by = null, array $suffix = ['.x', '.y'], ?bool $keep = null, string $na_matches = 'na', string $multiple = 'all', ?string $relationship = null): Closure
{
    return Join::verb('full', $y, $by, $suffix, $keep, $na_matches, $multiple, 'drop', $relationship);
}

/** Rows of x that have a match in y (x's columns only). */
function semi_join(Table $y, string|array|JoinBy|null $by = null, string $na_matches = 'na'): Closure
{
    return Join::verb('semi', $y, $by, ['.x', '.y'], null, $na_matches, 'all', 'drop', null);
}

/** Rows of x that have no match in y. */
function anti_join(Table $y, string|array|JoinBy|null $by = null, string $na_matches = 'na'): Closure
{
    return Join::verb('anti', $y, $by, ['.x', '.y'], null, $na_matches, 'all', 'drop', null);
}

/** Every combination of rows of x and y. */
function cross_join(Table $y, array $suffix = ['.x', '.y']): Closure
{
    return Join::verb('cross', $y, [], $suffix, null, 'na', 'all', 'drop', null);
}

/**
 * A join specification, as dplyr's join_by(). Conditions are column names
 * (equality on the same name), ['x' => 'y'] or [x, op, y] arrays, or
 * comparisons where the left side is x's column and the right side y's:
 *
 *     join_by('id', col('t')->ge(col('start')))
 *     join_by('id', between('t', 'start', 'end'))      // bounds are y's columns
 *     join_by('id', closest(col('t')->ge(col('start'))))
 *     join_by(overlaps('start', 'end', 'from', 'to'))
 *
 * Every condition must hold. Inequality and rolling joins keep the key
 * columns of both tables, as in dplyr.
 */
function join_by(mixed ...$conditions): JoinBy
{
    $out = [];
    foreach ($conditions as $key => $condition) {
        $out = [...$out, ...JoinBy::parse($condition, $key)];
    }

    return new JoinBy($out);
}

/** A rolling join: keep only the closest match, as in join_by(closest(col('t')->ge(col('start')))). */
function closest(mixed $condition): Closest
{
    return new Closest($condition);
}

/** x's interval [x_lower, x_upper] lies within y's [y_lower, y_upper]. */
function within(string $x_lower, string $x_upper, string $y_lower, string $y_upper): array
{
    return [[$x_lower, '>=', $y_lower], [$x_upper, '<=', $y_upper]];
}

/** x's interval [x_lower, x_upper] overlaps y's [y_lower, y_upper]. */
function overlaps(string $x_lower, string $x_upper, string $y_lower, string $y_upper): array
{
    return [[$x_lower, '<=', $y_upper], [$x_upper, '>=', $y_lower]];
}
