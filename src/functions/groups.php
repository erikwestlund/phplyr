<?php

declare(strict_types=1);

/*
 * Group utilities, binding, and the model adapters.
 */

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;
use Phibble\DataFrame;

/** The grouping column names. */
function group_vars(): Closure
{
    return fn (Table $t) => $t->groups();
}

/** The number of groups (1 for an ungrouped table). */
function n_groups(): Closure
{
    return fn (Table $t) => \count(Ops::groupIndex($t));
}

/** Rows in each group, in group order. */
function group_size(): Closure
{
    return fn (Table $t) => array_map(fn ($g) => \count($g['rows']), Ops::groupIndex($t));
}

/** Each row's 1-based group number. */
function group_indices(): Closure
{
    return function (Table $t) {
        $out = array_fill(0, $t->nrow(), null);
        foreach (Ops::groupIndex($t) as $k => $g) {
            foreach ($g['rows'] as $i) {
                $out[$i] = $k + 1;
            }
        }

        return $out;
    };
}

/** One row per group with the key columns. */
function group_keys(): Closure
{
    return function (Table $t) {
        $groups = $t->groups();

        return $t->summarise([])->ungroup()->select($groups);
    };
}

/** Each group's 1-based row numbers, in group order. */
function group_rows(): Closure
{
    return fn (Table $t) => array_map(fn ($g) => array_map(fn ($r) => $r + 1, $g['rows']), Ops::groupIndex($t));
}

/** group_keys() with a .rows list-column of each group's 1-based row numbers. */
function group_data(): Closure
{
    return fn (Table $t) => Internal::sameBackend(group_keys()($t)->collect()->mutate(['.rows' => group_rows()($t)]), $t);
}

/**
 * Drop unused factor levels from the grouping columns, and with them the
 * empty groups that _drop: false kept.
 */
function group_trim(): Closure
{
    return function (Table $t) {
        $groups = $t->groups();
        $frame = $t->collect();
        foreach ($groups as $g) {
            if ($frame->types()[$g] === \Phibble\Type::Factor) {
                $frame = $frame->fctDrop($g);
            }
        }

        return Internal::sameBackend(Internal::regroup($frame, $groups, $t), $t);
    };
}

/**
 * One row per group: the key columns and a list-column of each group's
 * other columns as a table. Options: _key (the list-column, 'data'), _keep
 * (also keep the key columns in the nested tables).
 */
function group_nest(mixed ...$options): Closure
{
    [$cols, $o] = Internal::options($options, ['_key' => 'data', '_keep' => false], 'group_nest');

    return function (Table $t) use ($cols, $o) {
        if ($cols !== []) {
            $t = $t->groupBy(Selector::select($cols, $t));
        }
        $groups = $t->groups();
        $data = array_map(
            fn ($g) => $o['_keep'] ? $g['table']->collect() : $g['table']->select(array_values(array_diff($g['table']->columns(), $groups)))->collect(),
            Ops::groupRows($t),
        );

        return group_keys()($t)->collect()->mutate([$o['_key'] => $data]);
    };
}

/**
 * Nest by the given columns into a rowwise table: one row per group, with a
 * list-column of tables. Options: _key ('data'), _keep (keep the grouping
 * columns in the nested tables).
 */
function nest_by(mixed ...$cols): Closure
{
    [$cols, $o] = Internal::options($cols, ['_key' => 'data', '_keep' => false], 'nest_by');

    return function (Table $t) use ($cols, $o) {
        $groups = $cols === [] ? $t->groups() : Selector::select($cols, $t);

        return group_nest(_key: $o['_key'], _keep: $o['_keep'])($t->groupBy($groups))->rowwise($groups);
    };
}

/**
 * Run a verb on the table grouped by _groups, then restore the original
 * grouping (superseded in dplyr by _by): with_groups('g', mutate(m: mean('x'))).
 * A null _groups runs it ungrouped.
 */
function with_groups(mixed $_groups, Closure $verb): Closure
{
    return function (Table $t) use ($_groups, $verb) {
        $grouped = $_groups === null ? $t->ungroup() : $t->groupBy(Selector::select([$_groups], $t));

        return Ops::regroupLike($verb($grouped), $t);
    };
}

/**
 * Split into a list of ungrouped tables, one per group. With _keep: false
 * the grouping columns are dropped. Option _by groups first.
 */
function group_split(mixed ...$options): Closure
{
    [, $o] = Internal::options($options, ['_keep' => true, '_by' => null], 'group_split');

    return function (Table $t) use ($o) {
        if ($o['_by'] !== null) {
            $t = $t->groupBy(Selector::select([$o['_by']], $t));
        }
        $groups = $t->groups();

        return array_map(
            fn ($g) => $o['_keep'] ? $g['table'] : $g['table']->select(array_values(array_diff($g['table']->columns(), $groups))),
            Ops::groupRows($t),
        );
    };
}

/**
 * Call fn (Table $group, array $key) for each group and return the list of
 * results. With _keep: true the group table keeps the grouping columns.
 */
function group_map(Closure $fn, bool $_keep = false): Closure
{
    return function (Table $t) use ($fn, $_keep) {
        $groups = $t->groups();

        return array_map(
            fn ($g) => $fn($_keep ? $g['table'] : $g['table']->select(array_values(array_diff($g['table']->columns(), $groups))), $g['key']),
            Ops::groupRows($t),
        );
    };
}

/**
 * Replace each group with the table fn (Table $group, array $key) returns,
 * then put the key columns back in front. The result stays grouped.
 */
function group_modify(Closure $fn): Closure
{
    return function (Table $t) use ($fn) {
        $groups = $t->groups();
        $parts = [];
        foreach (Ops::groupRows($t) as $g) {
            $data = $g['table']->select(array_values(array_diff($g['table']->columns(), $groups)));
            $result = $fn($data, $g['key']);
            if (! $result instanceof Table) {
                throw new \UnexpectedValueException('group_modify(): the function must return a table.');
            }
            $keys = Ops::take($g['table']->select($groups), array_fill(0, $result->nrow(), 0));
            $parts[] = bind_cols($result)($keys);
        }

        return $parts === [] ? $t : Internal::sameBackend(DataFrame::concat($parts)->groupBy($groups), $t);
    };
}

/** Call fn (Table $group, array $key) for each group; return the table unchanged. */
function group_walk(Closure $fn): Closure
{
    return function (Table $t) use ($fn) {
        group_map($fn)($t);

        return $t;
    };
}

/**
 * Stack tables under the piped one, matching columns by name; missing
 * columns are NA: $a |> bind_rows($b, $c). Option _id adds a column with
 * each row's source (1 for the piped table, 2 for the next...).
 */
function bind_rows(mixed ...$tables): Closure
{
    [$tables, $o] = Internal::options($tables, ['_id' => null], 'bind_rows');

    return function (Table $x) use ($tables, $o) {
        $all = [$x, ...array_values($tables)];
        if ($o['_id'] !== null) {
            $all = array_map(
                fn (Table $t, int $i) => $t->mutate([$o['_id'] => (string) ($i + 1)])->select([$o['_id'], ...$t->columns()]),
                $all,
                array_keys($all),
            );
        }

        return Internal::sameBackend(DataFrame::concat($all), $x);
    };
}

/**
 * Put tables side by side with the piped one; all must have the same number
 * of rows. Duplicate names are made unique as name...position, like dplyr.
 */
function bind_cols(Table ...$tables): Closure
{
    return function (Table $x) use ($tables) {
        $all = [$x, ...$tables];
        $names = array_merge(...array_map(fn (Table $t) => $t->columns(), $all));
        $counts = array_count_values($names);
        $frames = [];
        $pos = 0;
        foreach ($all as $t) {
            if ($t->nrow() !== $x->nrow()) {
                throw new \LengthException("bind_cols(): tables have {$x->nrow()} and {$t->nrow()} rows.");
            }
            $map = [];
            foreach ($t->columns() as $c) {
                $pos++;
                if ($counts[$c] > 1) {
                    $map[$c.'...'.$pos] = $c;
                }
            }
            $frames[] = $map === [] ? $t->collect() : $t->collect()->rename($map);
        }
        if (method_exists(DataFrame::class, 'bindCols')) {
            return Internal::sameBackend(Ops::regroupLike(DataFrame::bindCols($frames), $x), $x);
        }
        $out = array_shift($frames);
        foreach ($frames as $f) {
            foreach ($f->columns() as $c) {
                $out = $out->withColumn($c, $f->storage($c));
            }
        }

        return $out;
    };
}

// ---------------------------------------------------------------------------
// Models: thin adapters over php_stats' formula API (stats_lm, stats_glm).
// The table itself is passed, so factor levels reach the model matrix.
// ---------------------------------------------------------------------------

/** Linear regression: $t |> lm('y ~ a + b'). Needs stats_lm from php_stats. */
function lm(string $formula, mixed ...$options): Closure
{
    return fn (Table $t) => Internal::callStats('stats_lm', $formula, $t, ...$options);
}

/**
 * Generalised linear model: $t |> glm('y ~ x', family: 'binomial').
 * Needs stats_glm from php_stats.
 */
function glm(string $formula, string $family = 'gaussian', mixed ...$options): Closure
{
    return fn (Table $t) => Internal::callStats('stats_glm', $formula, $t, $family, ...$options);
}

/**
 * Linear mixed model with lme4-style random effects:
 * $t |> lmer('score ~ study_hours + (1 | school)'). REML by default
 * (reml: false for maximum likelihood). Needs stats_lmer from php_stats.
 */
function lmer(string $formula, mixed ...$options): Closure
{
    return fn (Table $t) => Internal::callStats('stats_lmer', $formula, $t, ...$options);
}

/**
 * Generalised linear mixed model: $t |> glmer('y ~ x + (1 | g)', family: 'binomial').
 * Needs stats_glmer from php_stats.
 */
function glmer(string $formula, string $family = 'binomial', mixed ...$options): Closure
{
    return fn (Table $t) => Internal::callStats('stats_glmer', $formula, $t, $family, ...$options);
}

/** The SQL a lazy table would run (database backends only). */
function show_query(): Closure
{
    return function (Table $t) {
        if (! method_exists($t, 'showQuery')) {
            throw new \LogicException('show_query() needs a database-backed table, such as Phibble\tbl().');
        }

        return $t->showQuery();
    };
}

/** Run a lazy table's query and return an in-memory Phibble\DataFrame. */
function collect(): Closure
{
    return fn (Table $t) => $t->collect();
}

/** The current group's 1-based number, inside mutate(), summarise() or reframe(). */
function cur_group_id(): CurGroup
{
    return new CurGroup('id');
}

/**
 * The current group's 1-based row numbers in the table. In mutate() each row
 * gets its own row number; in reframe() each group gets the list.
 */
function cur_group_rows(): CurGroup
{
    return new CurGroup('rows');
}

/**
 * The current group's key, as an array of grouping column => value (dplyr
 * returns a one-row tibble). In mutate() each row gets its group's key.
 */
function cur_group(): CurGroup
{
    return new CurGroup('key');
}
