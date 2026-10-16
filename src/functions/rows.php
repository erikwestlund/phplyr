<?php

declare(strict_types=1);

/*
 * Set operations and the rows_*() family. Rows are compared by value
 * across all columns (set operations) or by key columns (rows_*), with
 * NA equal to NA.
 */

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;
use Phibble\Frame;

/** All rows of both tables. */
function union_all(Table $y): Closure
{
    return fn (Table $x) => Frame::concat([$x, Rows::aligned($x, $y)]);
}

/** Distinct rows of both tables. */
function union(Table $y): Closure
{
    return fn (Table $x) => Frame::concat([$x, Rows::aligned($x, $y)])->distinct();
}

/** Distinct rows of x that also appear in y. */
function intersect(Table $y): Closure
{
    return fn (Table $x) => Rows::keep($x->distinct(), Rows::keys(Rows::aligned($x, $y), $x->columns()), true);
}

/** Distinct rows of x that don't appear in y. */
function setdiff(Table $y): Closure
{
    return fn (Table $x) => Rows::keep($x->distinct(), Rows::keys(Rows::aligned($x, $y), $x->columns()), false);
}

/** Rows in exactly one of the tables. */
function symdiff(Table $y): Closure
{
    return fn (Table $x) => Frame::concat([setdiff($y)($x), setdiff($x)(Rows::aligned($x, $y))]);
}

/** True when both tables hold the same distinct rows, in any order. */
function setequal(Table $y): Closure
{
    return function (Table $x) use ($y) {
        if (array_diff($x->columns(), $y->columns()) !== [] || array_diff($y->columns(), $x->columns()) !== []) {
            return false;
        }
        $a = array_keys(Rows::keys($x, $x->columns()));
        $b = array_keys(Rows::keys($y, $x->columns()));
        sort($a);
        sort($b);

        return $a === $b;
    };
}

/**
 * Append rows of y whose keys are new. conflict: 'error' (default) or
 * 'ignore' for keys that already exist. by defaults to y's first column.
 */
function rows_insert(Table $y, string|array|null $by = null, string $conflict = 'error'): Closure
{
    return function (Table $x) use ($y, $by, $conflict) {
        $by = Rows::by($y, $by);
        $existing = Rows::keys($x, $by);
        $new = [];
        foreach (Rows::keyList($y, $by) as $i => $k) {
            if (isset($existing[$k])) {
                if ($conflict === 'error') {
                    throw new \UnexpectedValueException('rows_insert(): y row '.($i + 1).' has a key that already exists in x. Use conflict: \'ignore\'.');
                }

                continue;
            }
            $new[] = $i;
        }

        return Frame::concat([$x, Ops::take(Rows::aligned($x, $y, partial: true), $new)]);
    };
}

/** Append all rows of y. */
function rows_append(Table $y): Closure
{
    return fn (Table $x) => Frame::concat([$x, Rows::aligned($x, $y, partial: true)]);
}

/**
 * Overwrite x's values with y's for matching keys. unmatched: 'error'
 * (default) or 'ignore' for y keys missing from x.
 */
function rows_update(Table $y, string|array|null $by = null, string $unmatched = 'error'): Closure
{
    return fn (Table $x) => Rows::modify($x, $y, Rows::by($y, $by), 'rows_update', $unmatched, patch: false);
}

/** Like rows_update(), but only fills x's NA values. */
function rows_patch(Table $y, string|array|null $by = null, string $unmatched = 'error'): Closure
{
    return fn (Table $x) => Rows::modify($x, $y, Rows::by($y, $by), 'rows_patch', $unmatched, patch: true);
}

/** Update matching keys and insert new ones. */
function rows_upsert(Table $y, string|array|null $by = null): Closure
{
    return function (Table $x) use ($y, $by) {
        $by = Rows::by($y, $by);
        $updated = Rows::modify($x, $y, $by, 'rows_upsert', 'ignore', patch: false);

        return rows_insert($y, $by, conflict: 'ignore')($updated);
    };
}

/** Delete rows of x whose keys appear in y. unmatched: 'error' or 'ignore'. */
function rows_delete(Table $y, string|array|null $by = null, string $unmatched = 'error'): Closure
{
    return function (Table $x) use ($y, $by, $unmatched) {
        $by = Rows::by($y, $by);
        $drop = Rows::keys($y, $by);
        if ($unmatched === 'error') {
            $present = Rows::keys($x, $by);
            foreach (array_keys($drop) as $k) {
                if (! isset($present[$k])) {
                    throw new \UnexpectedValueException("rows_delete(): y has keys that aren't in x. Use unmatched: 'ignore'.");
                }
            }
        }

        return Rows::keep($x, $drop, false, $by);
    };
}
