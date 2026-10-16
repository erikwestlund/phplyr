<?php

declare(strict_types=1);

/*
 * Two-table verbs with dplyr 1.1 semantics. The table's join() does the
 * work (phibble Table contract v1), so backends can run joins natively.
 *
 * by: null joins on the columns both tables share; 'id' or ['id', 'year']
 * joins on same-named columns; ['left' => 'right'] maps differing names;
 * the forms mix: ['id', 'code' => 'country_code'].
 */

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;

/** Rows of x with a match in y, with y's columns. */
function inner_join(Table $y, string|array|null $by = null, array $suffix = ['.x', '.y'], bool $keep = false, string $na_matches = 'na', string $multiple = 'all', string $unmatched = 'drop', ?string $relationship = null): Closure
{
    return Join::verb('inner', $y, $by, $suffix, $keep, $na_matches, $multiple, $unmatched, $relationship);
}

/** All rows of x, with y's columns where they match (NA otherwise). */
function left_join(Table $y, string|array|null $by = null, array $suffix = ['.x', '.y'], bool $keep = false, string $na_matches = 'na', string $multiple = 'all', string $unmatched = 'drop', ?string $relationship = null): Closure
{
    return Join::verb('left', $y, $by, $suffix, $keep, $na_matches, $multiple, $unmatched, $relationship);
}

/** All rows of y, with x's columns where they match. */
function right_join(Table $y, string|array|null $by = null, array $suffix = ['.x', '.y'], bool $keep = false, string $na_matches = 'na', string $multiple = 'all', string $unmatched = 'drop', ?string $relationship = null): Closure
{
    return Join::verb('right', $y, $by, $suffix, $keep, $na_matches, $multiple, $unmatched, $relationship);
}

/** All rows of both tables. */
function full_join(Table $y, string|array|null $by = null, array $suffix = ['.x', '.y'], bool $keep = false, string $na_matches = 'na', string $multiple = 'all', ?string $relationship = null): Closure
{
    return Join::verb('full', $y, $by, $suffix, $keep, $na_matches, $multiple, 'drop', $relationship);
}

/** Rows of x that have a match in y (x's columns only). */
function semi_join(Table $y, string|array|null $by = null, string $na_matches = 'na'): Closure
{
    return Join::verb('semi', $y, $by, ['.x', '.y'], false, $na_matches, 'all', 'drop', null);
}

/** Rows of x that have no match in y. */
function anti_join(Table $y, string|array|null $by = null, string $na_matches = 'na'): Closure
{
    return Join::verb('anti', $y, $by, ['.x', '.y'], false, $na_matches, 'all', 'drop', null);
}

/** Every combination of rows of x and y. */
function cross_join(Table $y, array $suffix = ['.x', '.y']): Closure
{
    return Join::verb('cross', $y, [], $suffix, false, 'na', 'all', 'drop', null);
}
