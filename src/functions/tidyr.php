<?php

declare(strict_types=1);

/*
 * tidyr verbs, pipe-able like the dplyr verbs. Arguments follow tidyr's
 * names; tidyr's dot-arguments use a leading underscore (_remove, _id).
 */

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;
use Phibble\DataFrame;

/**
 * Lengthen: turn columns into name/value rows.
 * pivot_longer(starts_with('wk'), names_to: 'week', values_to: 'rank').
 * names_to may list several names (with names_sep or names_pattern); the
 * name '.value' takes the value column's name from that part.
 * names_transform and values_transform are a closure, or name => closure;
 * cols_vary: 'slowest' keeps each source column's rows together.
 */
function pivot_longer(mixed $cols, string|array $names_to = 'name', string $values_to = 'value', ?string $names_prefix = null, ?string $names_sep = null, ?string $names_pattern = null, bool $values_drop_na = false, Closure|array|null $names_transform = null, Closure|array|null $values_transform = null, string $cols_vary = 'fastest'): Closure
{
    return fn (Table $t) => Tidyr::pivotLonger($t, $cols, $names_to, $values_to, $names_prefix, $names_sep, $names_pattern, $values_drop_na, $names_transform, $values_transform, $cols_vary);
}

/**
 * Widen: spread name/value pairs into columns.
 * pivot_wider(names_from: 'week', values_from: 'rank', values_fill: 0).
 * Duplicate cells need values_fn, a closure taking the list of values.
 * names_vary: 'slowest' groups each name's value columns together;
 * names_expand and id_expand add every combination of the names_from and
 * id_cols values (sorted, factors by level); unused_fn summarises the
 * columns that are in none of id_cols, names_from and values_from.
 */
function pivot_wider(mixed $names_from = 'name', mixed $values_from = 'value', mixed $id_cols = null, string $names_prefix = '', string $names_sep = '_', ?string $names_glue = null, bool $names_sort = false, mixed $values_fill = null, Closure|array|null $values_fn = null, string $names_vary = 'fastest', bool $names_expand = false, bool $id_expand = false, Closure|array|null $unused_fn = null): Closure
{
    return fn (Table $t) => Tidyr::pivotWider($t, $id_cols, $names_from, $values_from, $names_prefix, $names_sep, $names_glue, $names_sort, $values_fill, $values_fn, $names_vary, $names_expand, $id_expand, $unused_fn);
}

/**
 * The spec pivot_longer() would use, as a table with .name, .value and the
 * names_to columns; edit it and run it with pivot_longer_spec().
 */
function build_longer_spec(mixed $cols, string|array $names_to = 'name', string $values_to = 'value', ?string $names_prefix = null, ?string $names_sep = null, ?string $names_pattern = null, Closure|array|null $names_transform = null): Closure
{
    return fn (Table $t) => DataFrame::fromRows(Tidyr::longerSpec($t, $cols, $names_to, $values_to, $names_prefix, $names_sep, $names_pattern, $names_transform));
}

/** Lengthen with a spec from build_longer_spec() (a table or a list of rows). */
function pivot_longer_spec(Table|array $spec, bool $values_drop_na = false, Closure|array|null $values_transform = null, string $cols_vary = 'fastest'): Closure
{
    return fn (Table $t) => Tidyr::runLongerSpec($t, $spec instanceof Table ? $spec->toRows() : array_values($spec), $values_drop_na, $values_transform, $cols_vary);
}

/**
 * The spec pivot_wider() would use, as a table with .name, .value and the
 * names_from columns; edit it and run it with pivot_wider_spec().
 */
function build_wider_spec(mixed $names_from = 'name', mixed $values_from = 'value', string $names_prefix = '', string $names_sep = '_', ?string $names_glue = null, bool $names_sort = false, string $names_vary = 'fastest', bool $names_expand = false): Closure
{
    return fn (Table $t) => DataFrame::fromRows(Tidyr::widerSpec($t, Selector::select([$names_from], $t), Selector::select([$values_from], $t), $names_prefix, $names_sep, $names_glue, $names_sort, $names_vary, $names_expand));
}

/**
 * Widen with a spec from build_wider_spec(). id_cols defaults to the
 * columns the spec doesn't use.
 */
function pivot_wider_spec(Table|array $spec, mixed $id_cols = null, mixed $values_fill = null): Closure
{
    return function (Table $t) use ($spec, $id_cols, $values_fill) {
        $rows = $spec instanceof Table ? $spec->toRows() : array_values($spec);
        $used = [...array_column($rows, '.value'), ...array_diff(array_keys($rows[0] ?? []), ['.name', '.value'])];
        $ids = $id_cols === null ? array_values(array_diff($t->columns(), $used)) : Selector::select([$id_cols], $t);
        $fill = \is_array($values_fill) ? $values_fill : ($values_fill === null ? [] : array_fill_keys(array_unique(array_column($rows, '.value')), $values_fill));

        return $t->pivotWiderSpec($rows, $ids, $fill);
    };
}

/**
 * Gather columns into key-value pairs (superseded by pivot_longer()):
 * gather('week', 'rank', 'wk1', 'wk2'). Rows run column by column. Options:
 * na_rm, convert.
 */
function gather(string $key = 'key', string $value = 'value', mixed ...$cols): Closure
{
    $naRm = $cols['na_rm'] ?? false;
    $convert = $cols['convert'] ?? false;
    unset($cols['na_rm'], $cols['convert']);

    return function (Table $t) use ($key, $value, $cols, $naRm, $convert) {
        $selected = $cols === [] ? array_values(array_diff($t->columns(), $t->groups())) : Selector::select(array_values($cols), $t);
        $out = Tidyr::pivotLonger($t, $selected, $key, $value, null, null, null, $naRm, colsVary: 'slowest');

        return $convert ? $out->mutate([$key => Tidyr::convert($out->column($key))]) : $out;
    };
}

/**
 * Spread a key-value pair across columns (superseded by pivot_wider()):
 * spread('key', 'value', fill: 0). Columns and rows are sorted.
 */
function spread(string $key, string $value, mixed $fill = null, bool $convert = false): Closure
{
    return function (Table $t) use ($key, $value, $fill, $convert) {
        $out = Tidyr::pivotWider($t, null, $key, $value, '', '_', null, true, $fill, null);
        $ids = array_values(array_diff($t->columns(), [$key, $value]));
        $out = Internal::arrange($out, $ids, false);
        if ($convert) {
            $new = array_values(array_diff($out->columns(), $ids));
            $out = $out->mutate(array_combine($new, array_map(fn ($c) => Tidyr::convert(array_map(fn ($v) => $v === null ? null : Tidyr::str($v), $out->column($c))), $new)));
        }

        return $out;
    };
}

/** Only the combinations that occur in the data, for complete() and expand(). */
function nesting(mixed ...$columns): Nesting
{
    return new Nesting(array_values($columns));
}

/**
 * Every combination of the given columns, per group: expand('year', 'type'),
 * expand(nesting('id', 'name'), 'year'), expand(year: range(2000, 2005)).
 */
function expand(mixed ...$items): Closure
{
    return fn (Table $t) => Internal::sameBackend(Tidyr::expand($t, $items), $t);
}

/**
 * Add missing combinations, filling other columns with NA (or with fill).
 * complete('year', 'type', fill: ['n' => 0]). Option: explicit (default
 * true; false fills only the added rows).
 */
function complete(mixed ...$items): Closure
{
    $fill = $items['fill'] ?? [];
    $explicit = $items['explicit'] ?? true;
    unset($items['fill'], $items['explicit']);

    return fn (Table $t) => Internal::sameBackend(Tidyr::complete($t, $items, $fill, (bool) $explicit), $t);
}

/** Every combination of unique values (sorted), as a new table: crossing(x: [1, 2], y: ['a', 'b']). */
function crossing(array ...$columns): DataFrame
{
    return expand_grid(...array_map(Tidyr::sortedUnique(...), $columns));
}

/** Every combination of the values as given, first column varying slowest. */
function expand_grid(array ...$columns): DataFrame
{
    $rows = [[]];
    foreach ($columns as $name => $values) {
        $next = [];
        foreach ($rows as $row) {
            foreach (array_values($values) as $v) {
                $next[] = $row + [$name => $v];
            }
        }
        $rows = $next;
    }
    $out = [];
    foreach (array_keys($columns) as $name) {
        $out[$name] = array_column($rows, $name);
    }

    return DataFrame::fromColumns($out);
}

/**
 * Fill NA with the previous (or next) value, within groups:
 * fill('price', _direction: 'down'|'up'|'downup'|'updown').
 */
function fill(mixed ...$cols): Closure
{
    [$cols, $o] = Internal::options($cols, ['_direction' => 'down'], 'fill');

    return function (Table $t) use ($cols, $o) {
        $defs = [];
        foreach (Selector::select($cols, $t) as $c) {
            $defs[$c] = function (Table $g) use ($c, $o) {
                $v = $g->column($c);
                $down = function (array $v) {
                    $last = null;
                    foreach ($v as $i => $x) {
                        $x === null || (\is_float($x) && is_nan($x)) ? $v[$i] = $last : $last = $x;
                    }

                    return $v;
                };
                $up = fn (array $v) => array_reverse($down(array_reverse($v)));

                return match ($o['_direction']) {
                    'down' => $down($v),
                    'up' => $up($v),
                    'downup' => $up($down($v)),
                    'updown' => $down($up($v)),
                    default => throw new \InvalidArgumentException("fill(): unknown _direction '{$o['_direction']}'."),
                };
            };
        }

        return Ops::mutateGroups($t, $defs);
    };
}

/** Drop rows with NA in any of the columns (any column when none are given). */
function drop_na(mixed ...$cols): Closure
{
    return function (Table $t) use ($cols) {
        $names = $cols === [] ? $t->columns() : Selector::select($cols, $t);

        return $t->filter(function ($r) use ($names) {
            foreach ($names as $c) {
                if ($r[$c] === null || (\is_float($r[$c]) && is_nan($r[$c]))) {
                    return false;
                }
            }

            return true;
        });
    };
}

/** Replace NA per column: replace_na(['n' => 0, 'label' => 'unknown']). */
function replace_na(array $replace): Closure
{
    return function (Table $t) use ($replace) {
        $defs = [];
        foreach ($replace as $col => $value) {
            $defs[$col] = array_map(fn ($v) => $v === null || (\is_float($v) && is_nan($v)) ? $value : $v, $t->column($col));
        }

        return $t->mutate($defs);
    };
}

/**
 * Repeat each row by a weight column: uncount('n'). Options: _remove (drop
 * the weight column, default true), _id (add a 1-based copy number).
 */
function uncount(mixed $weights, bool $_remove = true, ?string $_id = null): Closure
{
    return function (Table $t) use ($weights, $_remove, $_id) {
        $w = Vec::values($weights, $t->ungroup(), true);
        $index = [];
        $ids = [];
        foreach ($w as $i => $count) {
            if ($count === null || $count < 0) {
                throw new \InvalidArgumentException('uncount(): weights must be non-negative and not NA.');
            }
            for ($k = 1; $k <= (int) $count; $k++) {
                $index[] = $i;
                $ids[] = $k;
            }
        }
        $out = Ops::take($t, $index);
        if ($_remove && \is_string($weights)) {
            $out = $out->select(array_values(array_diff($out->columns(), [$weights])));
        }

        return $_id === null ? $out : $out->mutate([$_id => $ids]);
    };
}

/**
 * Paste columns together into one: unite('date', 'year', 'month', sep: '-').
 * Options: sep ('_'), remove (true), na_rm (false; NA otherwise prints "NA").
 */
function unite(string $col, mixed ...$cols): Closure
{
    $sep = $cols['sep'] ?? '_';
    $remove = $cols['remove'] ?? true;
    $naRm = $cols['na_rm'] ?? false;
    unset($cols['sep'], $cols['remove'], $cols['na_rm']);

    return function (Table $t) use ($col, $cols, $sep, $remove, $naRm) {
        $names = $cols === [] ? $t->columns() : Selector::select(array_values($cols), $t);
        $vectors = array_map(fn ($c) => $t->column($c), $names);
        $values = [];
        for ($i = 0, $n = $t->nrow(); $i < $n; $i++) {
            $parts = array_map(fn ($v) => $v[$i], $vectors);
            if ($naRm) {
                $parts = array_filter($parts, fn ($p) => $p !== null);
            }
            $values[] = implode($sep, array_map(Tidyr::str(...), $parts));
        }
        $out = Tidyr::insertAt($t, $names[0], [$col => $values], false, newFirst: true);

        return $remove
            ? $out->select(array_values(array_diff($out->columns(), array_diff($names, [$col]))))
            : $out;
    };
}

/**
 * Split a string column into several (superseded in tidyr, still common):
 * separate('date', ['year', 'month'], sep: '-'). sep is a regular expression,
 * or an int / list of ints for positions. extra: 'warn'|'drop'|'merge';
 * fill: 'warn'|'right'|'left'; convert: R's type.convert().
 */
function separate(string $col, array $into, string|int|array $sep = '[^[:alnum:]]+', bool $remove = true, bool $convert = false, string $extra = 'warn', string $fill = 'warn'): Closure
{
    return function (Table $t) use ($col, $into, $sep, $remove, $convert, $extra, $fill) {
        $k = \count($into);
        $pieces = [];
        foreach ($t->column($col) as $v) {
            if ($v === null) {
                $pieces[] = array_fill(0, $k, null);

                continue;
            }
            $v = Tidyr::str($v);
            if (\is_string($sep)) {
                $p = preg_split(Tidyr::regex($sep), $v, $extra === 'merge' ? $k : -1);
            } else {
                $cuts = array_map(fn ($c) => $c < 0 ? \max(0, mb_strlen($v) + $c) : $c, (array) $sep);
                $p = [];
                $start = 0;
                foreach ($cuts as $cut) {
                    $p[] = mb_substr($v, $start, \max(0, $cut - $start));
                    $start = $cut;
                }
                $p[] = mb_substr($v, $start);
            }
            $p = \array_slice($p, 0, $k);
            if (\count($p) < $k) {
                $pad = array_fill(0, $k - \count($p), null);
                $p = $fill === 'left' ? [...$pad, ...$p] : [...$p, ...$pad];
            }
            $pieces[] = $p;
        }
        $new = [];
        foreach ($into as $j => $name) {
            if ($name === null) {
                continue;
            }
            $values = array_column($pieces, $j);
            $new[$name] = $convert ? Tidyr::convert($values) : $values;
        }

        return Tidyr::insertAt($t, $col, $new, $remove);
    };
}

/**
 * Split columns on a fixed delimiter into new columns:
 * separate_wider_delim('name', ' ', names: ['first', 'last']).
 * Without names, give names_sep to name them col{sep}1, col{sep}2...
 * too_few: 'error'|'align_start'|'align_end'; too_many: 'error'|'drop'|'merge'.
 */
function separate_wider_delim(mixed $cols, string $delim, ?array $names = null, ?string $names_sep = null, string $too_few = 'error', string $too_many = 'error', bool $cols_remove = true): Closure
{
    return function (Table $t) use ($cols, $delim, $names, $names_sep, $too_few, $too_many, $cols_remove) {
        foreach (Selector::select([$cols], $t) as $col) {
            $split = array_map(fn ($v) => $v === null ? null : explode($delim, Tidyr::str($v)), $t->column($col));
            $k = $names === null ? \max(array_map(fn ($p) => $p === null ? 0 : \count($p), $split) ?: [0]) : \count($names);
            if ($names === null && $names_sep === null) {
                throw new \InvalidArgumentException('separate_wider_delim(): give names or names_sep.');
            }
            $targets = $names ?? array_map(fn ($i) => $col.$names_sep.($i + 1), range(0, $k - 1));
            $rows = [];
            foreach ($split as $i => $p) {
                if ($p === null) {
                    $rows[] = array_fill(0, $k, null);

                    continue;
                }
                if (\count($p) > $k) {
                    $p = match ($too_many) {
                        'drop' => \array_slice($p, 0, $k),
                        'merge' => [...\array_slice($p, 0, $k - 1), implode($delim, \array_slice($p, $k - 1))],
                        default => throw new \UnexpectedValueException("separate_wider_delim(): row ".($i + 1)." of '{$col}' has ".\count($p)." pieces; expected {$k}. Use too_many."),
                    };
                }
                if (\count($p) < $k) {
                    $pad = array_fill(0, $k - \count($p), null);
                    $p = match ($too_few) {
                        'align_start' => [...$p, ...$pad],
                        'align_end' => [...$pad, ...$p],
                        default => throw new \UnexpectedValueException("separate_wider_delim(): row ".($i + 1)." of '{$col}' has ".\count($p)." pieces; expected {$k}. Use too_few."),
                    };
                }
                $rows[] = $p;
            }
            $new = [];
            foreach ($targets as $j => $name) {
                if ($name !== null) {
                    $new[$name] = array_column($rows, $j);
                }
            }
            $t = Tidyr::insertAt($t, $col, $new, $cols_remove);
        }

        return $t;
    };
}

/**
 * Split columns by character widths: separate_wider_position('code',
 * ['state' => 2, 1, 'county' => 3]); unnamed widths are skipped.
 */
function separate_wider_position(mixed $cols, array $widths, string $too_few = 'error', bool $cols_remove = true): Closure
{
    return function (Table $t) use ($cols, $widths, $too_few, $cols_remove) {
        $total = array_sum($widths);
        foreach (Selector::select([$cols], $t) as $col) {
            $new = array_fill_keys(array_filter(array_keys($widths), 'is_string'), []);
            foreach ($t->column($col) as $i => $v) {
                $v = $v === null ? null : Tidyr::str($v);
                if ($v !== null && mb_strlen($v) < $total && $too_few === 'error') {
                    throw new \UnexpectedValueException("separate_wider_position(): row ".($i + 1)." of '{$col}' is shorter than {$total} characters. Use too_few: 'align_start'.");
                }
                $pos = 0;
                foreach ($widths as $name => $w) {
                    if (\is_string($name)) {
                        $piece = $v === null ? null : mb_substr($v, $pos, $w);
                        $new[$name][] = $piece === '' ? null : $piece;
                    }
                    $pos += $w;
                }
            }
            $t = Tidyr::insertAt($t, $col, $new, $cols_remove);
        }

        return $t;
    };
}

/**
 * Split columns with regular expressions: separate_wider_regex('id',
 * ['letters' => '[a-z]+', '-', 'digits' => '\d+']). Unnamed patterns match
 * but are not kept. Rows that don't match error unless too_few: 'align_start'.
 */
function separate_wider_regex(mixed $cols, array $patterns, string $too_few = 'error', bool $cols_remove = true): Closure
{
    $piece = fn ($name, $p) => \is_string($name) ? '('.\str_replace('/', '\/', $p).')' : '(?:'.\str_replace('/', '\/', $p).')';
    $keys = array_keys($patterns);
    // The full pattern, then shorter prefixes of it for too_few: 'align_start'.
    $regexes = [];
    for ($k = \count($patterns); $k >= 1; $k--) {
        $regexes[] = '/^'.implode('', array_map($piece, \array_slice($keys, 0, $k), \array_slice($patterns, 0, $k))).'$/u';
    }
    $names = array_values(array_filter($keys, 'is_string'));

    return function (Table $t) use ($cols, $regexes, $names, $too_few, $cols_remove) {
        foreach (Selector::select([$cols], $t) as $col) {
            $new = array_fill_keys($names, []);
            foreach ($t->column($col) as $i => $v) {
                $m = [];
                if ($v !== null) {
                    $text = Tidyr::str($v);
                    foreach ($too_few === 'align_start' ? $regexes : [$regexes[0]] as $regex) {
                        if (preg_match($regex, $text, $m) === 1) {
                            break;
                        }
                        $m = [];
                    }
                    if ($m === [] && $too_few === 'error') {
                        throw new \UnexpectedValueException('separate_wider_regex(): row '.($i + 1)." of '{$col}' doesn't match. Use too_few: 'align_start'.");
                    }
                }
                foreach ($names as $j => $name) {
                    $new[$name][] = $m[$j + 1] ?? null;
                }
            }
            $t = Tidyr::insertAt($t, $col, $new, $cols_remove);
        }

        return $t;
    };
}

/**
 * Split columns on a fixed delimiter into rows: separate_longer_delim('tags', ',').
 * Several columns must split into the same number of pieces (or one).
 */
function separate_longer_delim(mixed $cols, string $delim): Closure
{
    return fn (Table $t) => Internal::longer($t, $cols, fn (string $v) => explode($delim, $v));
}

/** Split columns into rows of $width characters. */
function separate_longer_position(mixed $cols, int $width): Closure
{
    return fn (Table $t) => Internal::longer($t, $cols, fn (string $v) => $v === '' ? [''] : mb_str_split($v, $width));
}

/**
 * Nest columns into a list-column of tables: nest(data: ['x', 'y']), or
 * nest() on a grouped table to nest everything but the groups.
 */
function nest(mixed ...$spec): Closure
{
    return function (Table $t) use ($spec) {
        if ($spec === []) {
            return $t->collect()->nest();
        }
        if (\count($spec) !== 1 || ! \is_string(array_key_first($spec))) {
            throw new \InvalidArgumentException('nest() takes one named selection: nest(data: [...]).');
        }
        $into = array_key_first($spec);

        return $t->collect()->nest(Selector::select([$spec[$into]], $t), $into);
    };
}

/** Unnest list-columns of tables back into rows. */
function unnest(string|array $cols, bool $keep_empty = false): Closure
{
    return fn (Table $t) => Internal::sameBackend($t->collect()->unnest($cols, $keep_empty), $t);
}

/**
 * Pull regular-expression groups into new columns (superseded by
 * separate_wider_regex()): extract('x', ['letter', 'digit'], '([a-z])(\d)').
 * A null name skips a group. Rows that don't match get NA.
 */
function extract(string $col, array $into, string $regex = '([[:alnum:]]+)', bool $remove = true, bool $convert = false): Closure
{
    return function (Table $t) use ($col, $into, $regex, $remove, $convert) {
        $pattern = Tidyr::regex($regex);
        $new = array_fill_keys(array_filter($into, fn ($n) => $n !== null), []);
        foreach ($t->column($col) as $v) {
            $m = [];
            if ($v !== null) {
                preg_match($pattern, Tidyr::str($v), $m);
            }
            foreach ($into as $j => $name) {
                if ($name !== null) {
                    $piece = $m[$j + 1] ?? null;
                    $new[$name][] = $piece === '' && $m === [] ? null : $piece;
                }
            }
        }
        if ($convert) {
            $new = array_map(Tidyr::convert(...), $new);
        }

        return Tidyr::insertAt($t, $col, $new, $remove);
    };
}

/**
 * Split columns on a regular expression into rows (superseded by
 * separate_longer_delim()): separate_rows('tags', sep: ','). Options: sep
 * (default '[^[:alnum:].]+'), convert.
 */
function separate_rows(mixed ...$cols): Closure
{
    $sep = $cols['sep'] ?? '[^[:alnum:].]+';
    $convert = $cols['convert'] ?? false;
    unset($cols['sep'], $cols['convert']);

    return function (Table $t) use ($cols, $sep, $convert) {
        $out = Internal::longer($t, array_values($cols), fn (string $v) => preg_split(Tidyr::regex($sep), $v));
        if (! $convert) {
            return $out;
        }
        $names = Selector::select(array_values($cols), $t);

        return $out->mutate(array_combine($names, array_map(fn ($c) => Tidyr::convert($out->column($c)), $names)));
    };
}

/**
 * Every value from the smallest to the largest of x in steps of period:
 * full_seq([1, 5, 3], 1) is [1, 2, 3, 4, 5]. Values off the grid (beyond
 * tol) are an error.
 */
function full_seq(array $x, float|int $period, float $tol = 1e-6): array
{
    $x = array_values(array_filter($x, fn ($v) => $v !== null));
    if ($x === []) {
        return [];
    }
    $lo = \min($x);
    $hi = \max($x);
    foreach ($x as $v) {
        $r = fmod($v - $lo, $period);
        if (\min(\abs($r), \abs($period - $r)) > $tol) {
            throw new \InvalidArgumentException('full_seq(): x is not a regular sequence.');
        }
    }
    $n = (int) \round(($hi - $lo) / $period);
    $out = [];
    for ($i = 0; $i <= $n; $i++) {
        $out[] = $lo + $i * $period;
    }

    return array_all($x, fn ($v) => \is_int($v)) && \is_int($period) ? array_map('intval', $out) : array_map('floatval', $out);
}
