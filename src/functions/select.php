<?php

declare(strict_types=1);

/*
 * tidyselect helpers and across(). Selectors work anywhere a verb takes
 * columns: select, relocate, rename_with, arrange, distinct, group_by,
 * count, across, pick.
 */

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;
use Phibble\Type;

/** Columns whose names start with a prefix (case-insensitive by default). */
function starts_with(string|array $prefix, bool $ignore_case = true): Selector
{
    return Internal::nameMatcher((array) $prefix, $ignore_case, fn ($name, $p) => str_starts_with($name, $p));
}

/** Columns whose names end with a suffix (case-insensitive by default). */
function ends_with(string|array $suffix, bool $ignore_case = true): Selector
{
    return Internal::nameMatcher((array) $suffix, $ignore_case, fn ($name, $s) => str_ends_with($name, $s));
}

/** Columns whose names contain a string (case-insensitive by default). */
function contains(string|array $needle, bool $ignore_case = true): Selector
{
    return Internal::nameMatcher((array) $needle, $ignore_case, fn ($name, $s) => str_contains($name, $s));
}

/**
 * Columns whose names match a regular expression. Bare patterns are
 * wrapped in delimiters; case-insensitive by default.
 */
function matches(string $pattern, bool $ignore_case = true): Selector
{
    $regex = '/'.str_replace('/', '\/', $pattern).'/'.($ignore_case ? 'i' : '');

    return new Selector(fn (Table $t) => array_values(array_filter(
        $t->columns(),
        fn (string $c) => preg_match($regex, $c) === 1,
    )));
}

/** Columns named prefix + number for each number: num_range('x', 1, 3). */
function num_range(string $prefix, int $from, int $to, int $width = 0): Selector
{
    return new Selector(function (Table $t) use ($prefix, $from, $to, $width) {
        $wanted = array_map(fn ($i) => $prefix.str_pad((string) $i, $width, '0', STR_PAD_LEFT), range($from, $to));

        return array_values(array_intersect($t->columns(), $wanted));
    });
}

/** Every column. */
function everything(): Selector
{
    return new Selector(fn (Table $t) => $t->columns());
}

/** The last column, or the one $offset before it. */
function last_col(int $offset = 0): Selector
{
    return new Selector(function (Table $t) use ($offset) {
        $columns = $t->columns();
        $i = \count($columns) - 1 - $offset;

        return $i >= 0 ? [$columns[$i]] : throw new \OutOfRangeException("last_col(): offset {$offset} is out of range.");
    });
}

/** Exactly these columns; all must exist. */
function all_of(array $names): Selector
{
    return new Selector(function (Table $t) use ($names) {
        Selector::select([array_values($names)], $t);

        return array_values($names);
    });
}

/** These columns, skipping any that don't exist. */
function any_of(array $names): Selector
{
    return new Selector(fn (Table $t) => array_values(array_filter($names, fn ($n) => \in_array($n, $t->columns(), true))));
}

/**
 * Columns for which a predicate holds. The predicate receives
 * (list $values, Type $type, string $name). Shortcuts: a Phibble\Type, or
 * one of 'numeric', 'integer', 'double', 'character', 'logical', 'factor', 'date'.
 */
function where(Closure|Type|string $predicate): Selector
{
    if (! $predicate instanceof Closure) {
        $types = match (true) {
            $predicate instanceof Type => [$predicate],
            $predicate === 'numeric' => [Type::Int, Type::Float],
            $predicate === 'integer' => [Type::Int],
            $predicate === 'double' => [Type::Float],
            $predicate === 'character' => [Type::String],
            $predicate === 'logical' => [Type::Bool],
            $predicate === 'factor' => [Type::Factor],
            $predicate === 'date' => [Type::Date],
            default => throw new \InvalidArgumentException("where(): unknown type '{$predicate}'."),
        };
        $predicate = fn ($values, Type $type) => \in_array($type, $types, true);
    }

    return new Selector(function (Table $t) use ($predicate) {
        $out = [];
        foreach ($t->types() as $name => $type) {
            if ($predicate($t->column($name), $type, $name) === true) {
                $out[] = $name;
            }
        }

        return $out;
    });
}

/** Everything except these columns: select(except('id')) is dplyr's select(-id). */
function except(mixed ...$columns): Selector
{
    return new Selector(fn (Table $t) => Selector::select($columns, $t), negated: true);
}

/** Columns matched by any of the selections (union). */
function any_selected(mixed ...$selections): Selector
{
    return new Selector(fn (Table $t) => Selector::select($selections, $t));
}

/** Columns matched by every selection (intersection, like a & b in tidyselect). */
function all_selected(mixed ...$selections): Selector
{
    return new Selector(function (Table $t) use ($selections) {
        $sets = array_map(fn ($s) => Selector::select([$s], $t), $selections);

        return array_values(array_intersect(...$sets));
    });
}

/**
 * Apply functions to columns inside mutate() or summarise():
 * summarise(across(starts_with('x'), mean(...))),
 * mutate(across(where('numeric'), fn ($v) => $v * 2)),
 * summarise(across(['a', 'b'], ['avg' => mean(...), 'sd' => sd(...)])).
 *
 * Names default to '{col}' for one function and '{col}_{fn}' for a named list.
 */
function across(mixed $cols = null, Closure|array|null $fns = null, ?string $names = null): Across
{
    return new Across($cols === null ? [everything()] : [$cols], $fns, $names);
}

/**
 * Per-row test that a predicate holds for any of the columns, for filter():
 * filter(if_any(starts_with('x'), fn ($v) => $v > 0)).
 */
function if_any(mixed $cols, Closure $predicate): Vec
{
    return Internal::ifColumns($cols, $predicate, any: true);
}

/** Per-row test that a predicate holds for all of the columns. */
function if_all(mixed $cols, Closure $predicate): Vec
{
    return Internal::ifColumns($cols, $predicate, any: false);
}

/**
 * The selected columns of the current group as a table, for use in
 * summarise() closures or with vec(): fn ($g) => pick('a', 'b')($g)->toRows().
 */
function pick(mixed ...$columns): Closure
{
    return fn (Table $g) => $g->select(Selector::select($columns, $g));
}

/** The grouping columns. */
function group_cols(): Selector
{
    return new Selector(fn (Table $t) => $t->groups());
}
