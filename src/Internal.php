<?php

declare(strict_types=1);

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;
use Phibble\Expr;
use Phibble\DataFrame;

/** @internal Helpers shared by the verb functions. */
final class Internal
{
    /**
     * An argument as a Phibble expression, or null when it can't be one
     * (a PHP closure, or a Vec built from one). Strings are columns when
     * $column, literals otherwise.
     */
    public static function toExpr(mixed $arg, bool $column): ?Expr\Expr
    {
        return match (true) {
            $arg instanceof Vec => $arg->expr,
            $arg instanceof Expr\Expr => $arg,
            $arg instanceof Desc => ($inner = self::toExpr($arg->x, true)) === null ? null : new Expr\Desc($inner),
            \is_string($arg) => $column ? new Expr\Col($arg) : new Expr\Lit($arg),
            $arg === null, \is_int($arg), \is_float($arg), \is_bool($arg), $arg instanceof \DateTimeInterface => new Expr\Lit($arg),
            default => null,
        };
    }

    /**
     * A registered function as a Vec. When every argument converts to an
     * expression, the Vec carries the Phibble\Expr\Call. Otherwise the
     * arguments that can't (closures) are evaluated per group into
     * temporary columns and the same call runs on those, so both paths
     * share phibble's semantics.
     *
     * @param list<mixed> $args
     * @param array<string, mixed> $named
     * @param list<int> $columns Positions whose strings are column names.
     */
    public static function call(string $fn, array $args, array $named = [], array $columns = [0]): Vec
    {
        $exprs = [];
        $deferred = [];
        foreach ($args as $i => $arg) {
            [$inner, $desc] = self::direction($arg);
            $isColumn = \in_array($i, $columns, true);
            $e = self::toExpr($inner, $isColumn);
            if ($e === null) {
                $deferred['.phplyr_arg_'.$i] = [$inner, $isColumn];
                $e = new Expr\Col('.phplyr_arg_'.$i);
            }
            $exprs[] = $desc ? new Expr\Desc($e) : $e;
        }
        $namedExprs = [];
        foreach ($named as $key => $arg) {
            [$inner, $desc] = self::direction($arg);
            $e = self::toExpr($inner, $key === 'order_by');
            if ($e === null) {
                $deferred['.phplyr_arg_'.$key] = [$inner, $key === 'order_by'];
                $e = new Expr\Col('.phplyr_arg_'.$key);
            }
            $namedExprs[$key] = $desc ? new Expr\Desc($e) : $e;
        }
        $call = new Expr\Call($fn, $exprs, $namedExprs);
        if ($deferred === []) {
            return Vec::of($call);
        }

        return new Vec(function (Table $g) use ($call, $deferred) {
            $raw = [];
            foreach ($deferred as $name => [$arg, $column]) {
                $raw[$name] = $arg instanceof Vec ? $arg($g) : Vec::values($arg, $g, $column);
            }
            // Arguments that all have some other length, such as c_across()
            // in a rowwise table, are evaluated on their own.
            $lengths = array_unique(array_map(fn ($v) => \is_array($v) && array_is_list($v) ? \count($v) : -1, $raw));
            if (\count($lengths) === 1 && ! \in_array($lengths[array_key_first($lengths)], [-1, 1, $g->nrow()], true)
                && array_diff($call->columns(), array_keys($raw)) === []) {
                return Vec::evaluate($call, \Phibble\DataFrame::fromColumns($raw));
            }
            $temp = array_map(fn ($v) => Vec::recycle($v, $g->nrow(), 'expression'), $raw);

            return Vec::evaluate($call, $g->ungroup()->mutate($temp));
        });
    }

    /** AND several conditions into one expression, or null if any is a closure. */
    public static function allExpr(array $conditions): ?Expr\Expr
    {
        $out = null;
        foreach ($conditions as $c) {
            $e = self::toExpr($c, true);
            if ($e === null) {
                return null;
            }
            $out = $out === null ? $e : new Expr\Call('&', [$out, $e]);
        }

        return $out;
    }

    /** Verbs that name their outputs need named arguments (across() may be positional). */
    public static function assertNamed(array $args, string $verb): void
    {
        foreach ($args as $key => $arg) {
            if (\is_int($key) && ! $arg instanceof Across) {
                throw new \InvalidArgumentException(
                    "{$verb}() needs named arguments, for example {$verb}(total: ...)."
                );
            }
        }
    }

    /**
     * Split dplyr's dot-arguments, written here with a leading underscore
     * (_by, _keep...), from the rest.
     *
     * @param array<int|string, mixed> $args
     * @param array<string, mixed> $defaults Allowed options and their defaults.
     * @return array{0: array<int|string, mixed>, 1: array<string, mixed>}
     */
    public static function options(array $args, array $defaults, string $verb): array
    {
        $options = $defaults;
        foreach ($args as $key => $value) {
            if (\is_string($key) && str_starts_with($key, '_')) {
                if (! \array_key_exists($key, $defaults)) {
                    $allowed = implode(', ', array_keys($defaults)) ?: 'none';
                    throw new \InvalidArgumentException("{$verb}(): unknown option '{$key}'. Allowed: {$allowed}.");
                }
                $options[$key] = $value;
                unset($args[$key]);
            }
        }

        return [$args, $options];
    }

    /**
     * Run $fn with the table temporarily grouped by $by (dplyr's .by),
     * returning an ungrouped result.
     *
     * @param list<string>|string|Selector|null $by
     * @param Closure(Table): Table $fn
     */
    public static function withBy(Table $t, mixed $by, Closure $fn): Table
    {
        if ($by === null || $by === []) {
            return $fn($t);
        }
        if ($t->groups() !== []) {
            throw new \LogicException("Can't use _by on a grouped table; ungroup() first.");
        }

        return $fn($t->groupBy(Selector::select([$by], $t)))->ungroup();
    }

    /**
     * Split a desc()-wrapped argument.
     *
     * @return array{0: mixed, 1: bool}
     */
    public static function direction(mixed $x): array
    {
        if ($x instanceof Desc) {
            return [$x->x, true];
        }

        return [$x, false];
    }

    /**
     * A stable key for matching values: 1 and 1.0 match, NA matches NA.
     */
    public static function key(mixed $value): string
    {
        if (\is_string($value)) {
            return 's'.$value;
        }
        if (\is_int($value)) {
            return 'n'.$value.'.0';
        }

        return match (true) {
            $value === null => "\0NA",
            \is_bool($value) => 'b'.(int) $value,
            \is_int($value), \is_float($value) => 'n'.var_export((float) $value == 0.0 ? 0.0 : (float) $value, true),
            \is_string($value) => 's'.$value,
            default => 'o'.serialize($value),
        };
    }

    /** @param list<string> $needles */
    public static function nameMatcher(array $needles, bool $ignoreCase, Closure $test): Selector
    {
        return new Selector(fn (Table $t) => array_values(array_filter($t->columns(), function (string $name) use ($needles, $ignoreCase, $test) {
            foreach ($needles as $needle) {
                if ($ignoreCase ? $test(strtolower($name), strtolower($needle)) : $test($name, $needle)) {
                    return true;
                }
            }

            return false;
        })));
    }

    /** Row-wise any/all of an elementwise predicate over selected columns, with NA logic. */
    public static function ifColumns(mixed $cols, Closure $predicate, bool $any): Vec
    {
        return new Vec(function (Table $g) use ($cols, $predicate, $any) {
            $columns = array_values(array_diff(Selector::select([$cols], $g), $g->groups()));
            $out = array_fill(0, $g->nrow(), ! $any);
            foreach ($columns as $c) {
                foreach ($g->column($c) as $i => $v) {
                    $r = $predicate($v);
                    $r = $r === null ? null : (bool) $r;
                    $out[$i] = $any
                        ? ($out[$i] === true || $r === true ? true : ($out[$i] === null || $r === null ? null : false))
                        : ($out[$i] === false || $r === false ? false : ($out[$i] === null || $r === null ? null : true));
                }
            }

            return $out;
        });
    }

    /**
     * Column order after moving $moved before or after an anchor selection
     * (to the front when neither is given).
     *
     * @param list<string> $columns
     * @param list<string> $moved
     * @return list<string>
     */
    public static function relocated(array $columns, array $moved, mixed $before, mixed $after, Table $t): array
    {
        if ($before !== null && $after !== null) {
            throw new \InvalidArgumentException('Give _before or _after, not both.');
        }
        $rest = array_values(array_diff($columns, $moved));
        if ($before === null && $after === null) {
            return [...$moved, ...$rest];
        }
        $anchor = array_values(array_diff(Selector::select([$before ?? $after], $t), $moved));
        if ($anchor === []) {
            return $before !== null ? [...$moved, ...$rest] : [...$rest, ...$moved];
        }
        $positions = array_map(fn ($a) => array_search($a, $rest, true), $anchor);
        $at = $before !== null ? \min($positions) : \max($positions) + 1;

        return [...\array_slice($rest, 0, $at), ...$moved, ...\array_slice($rest, $at)];
    }

    /** @param list<mixed> $keys */
    public static function arrange(Table $t, array $keys, bool $byGroup): Table
    {
        $groups = $t->groups();
        if ($byGroup) {
            $keys = [...$groups, ...$keys];
        }
        $work = $t->ungroup();
        $sort = [];
        $temp = [];
        foreach ($keys as $key) {
            [$x, $desc] = self::direction($key);
            if (\is_array($x) && array_is_list($x) && \count($x) === 2 && \in_array($x[1], ['asc', 'desc'], true)) {
                [$x, $d] = $x;
                $desc = $desc xor $d === 'desc';
            }
            if ($x instanceof Across) {
                $x = any_selected(...$x->cols);
            }
            $expr = $x instanceof Vec ? $x->expr : ($x instanceof Expr\Expr ? $x : null);
            if ($expr !== null) {
                $sort[] = $desc ? new Expr\Desc($expr) : $expr;

                continue;
            }
            if (\is_string($x) || $x instanceof Selector) {
                foreach (Selector::select([$x], $work) as $c) {
                    $sort[] = [$c, $desc ? 'desc' : 'asc'];
                }

                continue;
            }
            $name = '.phplyr_sort_'.\count($temp);
            $work = Mutate::run($work, [$name => $x], ['_keep' => 'all', '_before' => null, '_after' => null]);
            $temp[] = $name;
            $sort[] = [$name, $desc ? 'desc' : 'asc'];
        }
        if ($temp === []) {
            return $t->arrange($sort);
        }
        $out = $work->arrange($sort);
        $out = $out->select(array_values(array_diff($out->columns(), $temp)));

        return Ops::regroupLike($out, $t);
    }

    /**
     * Turn summarise() arguments into closures returning one value per group.
     *
     * @param array<int|string, mixed> $aggregates
     * @return array<string, Closure(Table): mixed>
     */
    public static function summaries(Table $t, array $aggregates, string $verb): array
    {
        $out = [];
        foreach ($aggregates as $key => $def) {
            if ($def instanceof Across) {
                foreach ($def->expand($t, 'summarise') as $name => $vec) {
                    $out[$name] = $vec->expr ?? self::single($vec, $name, $verb);
                }

                continue;
            }
            if (\is_int($key)) {
                throw new \InvalidArgumentException("{$verb}() needs named arguments, for example {$verb}(total: ...), or across(...).");
            }
            $expr = $def instanceof Vec ? $def->expr : ($def instanceof Expr\Expr ? $def : null);
            $refs = $expr === null ? [] : array_values(array_intersect($expr->columns(), array_keys($out)));
            if ($refs !== []) {
                $out[$key] = self::dependent($expr, array_intersect_key($out, array_flip($refs)), $key, $verb);

                continue;
            }
            $out[$key] = match (true) {
                $def instanceof CurGroup => $def->kind !== 'rows'
                    ? $def->perGroup($t)
                    : throw new \LengthException("{$verb}(): cur_group_rows() gives several values per group. Use reframe()."),
                $def instanceof Vec && $def->expr !== null => $def->expr,
                $def instanceof Expr\Expr => $def,
                $def instanceof Vec, $def instanceof Closure => self::single($def, $key, $verb),
                default => fn () => $def,
            };
        }

        return $out;
    }

    /**
     * A summary that refers to earlier summaries in the same call, such as
     * summarise(s: sum('x'), t: col('s')->mul(2)). The earlier values are
     * computed for the group and added as columns before $expr is evaluated.
     *
     * @param array<string, Expr\Expr|Closure> $earlier
     */
    private static function dependent(Expr\Expr $expr, array $earlier, string $name, string $verb): Closure
    {
        return function (Table $g) use ($expr, $earlier, $name, $verb) {
            $values = [];
            foreach ($earlier as $col => $def) {
                $values[$col] = $def instanceof Expr\Expr ? Vec::evaluate($def, $g) : $def($g);
            }
            $value = Vec::evaluate($expr, $g->ungroup()->mutate($values));
            if (! \is_array($value)) {
                return $value;
            }
            $unique = array_unique(array_map(self::key(...), $value));

            return match (true) {
                $value === [] => null,
                \count($unique) === 1 => $value[0],
                default => throw new \LengthException("{$verb}(): '{$name}' must give one value per group, got ".\count($value).'. Use reframe() for several.'),
            };
        };
    }

    private static function single(Vec|Closure $def, string $name, string $verb): Closure
    {
        return function (Table $g) use ($def, $name, $verb) {
            $value = $def($g);
            if (\is_array($value)) {
                if (\count($value) !== 1) {
                    throw new \LengthException("{$verb}(): '{$name}' must give one value per group, got ".\count($value).'. Use reframe() for several.');
                }
                $value = array_values($value)[0];
            }

            return $value;
        };
    }

    /** @param array<int|string, mixed> $aggregates */
    public static function reframe(Table $t, array $aggregates): Table
    {
        $groups = $t->groups();
        $parts = [];
        $perGroup = [];
        foreach (Ops::groupRows($t) as ['key' => $groupKey, 'table' => $g]) {
            $values = [];
            foreach ($aggregates as $key => $def) {
                if ($def instanceof Across) {
                    foreach ($def->expand($t, 'summarise') as $name => $vec) {
                        $values[$name] = (array) $vec($g);
                    }

                    continue;
                }
                if (\is_int($key)) {
                    throw new \InvalidArgumentException('reframe() needs named arguments, or across(...).');
                }
                $values[$key] = match (true) {
                    $def instanceof CurGroup => ($perGroup[$key] ??= $def->perGroup($t))($g),
                    $def instanceof Vec, $def instanceof Closure => $def($g),
                    default => $def,
                };
                $values[$key] = \is_array($values[$key]) ? array_values($values[$key]) : [$values[$key]];
            }
            // dplyr's size rule: results of length 0 and 1 combine to 0 rows.
            $lengths = array_values(array_unique(array_map('count', $values)));
            $len = match (true) {
                $lengths === [] => 1,
                \in_array(0, $lengths, true) && array_all($lengths, fn ($l) => $l <= 1) => 0,
                default => \max($lengths),
            };
            foreach ($values as $name => $v) {
                $values[$name] = Vec::recycle($v, $len, "reframe() result '{$name}'");
            }
            if ($groups === []) {
                $parts[] = DataFrame::fromColumns($values);

                continue;
            }
            $keys = $g->nrow() > 0
                ? Ops::take($g->select($groups), array_fill(0, $len, 0))
                : DataFrame::fromColumns(array_map(fn ($v) => array_fill(0, $len, $v), $groupKey), array_intersect_key($t->types(), $groupKey));
            $parts[] = $keys->ungroup()->mutate($values);
        }
        if ($parts === []) {
            $names = [];
            foreach ($aggregates as $key => $def) {
                $names = [...$names, ...($def instanceof Across ? array_keys($def->expand($t, 'summarise')) : [$key])];
            }

            return Ops::take($t->ungroup()->select($groups), [])->mutate(array_fill_keys($names, []));
        }

        return DataFrame::concat($parts)->ungroup();
    }

    /**
     * @param array<int|string, mixed> $args
     * @return array{0: list<mixed>, 1: mixed, 2: bool, 3: ?string}
     */
    public static function countArgs(array $args, string $verb): array
    {
        $wt = null;
        $sort = false;
        $name = null;
        $columns = [];
        foreach ($args as $key => $value) {
            match (true) {
                $key === 'name' => $name = (string) $value,
                $key === 'sort' => $sort = (bool) $value,
                $key === 'wt' => $wt = $value,
                \is_int($key) => $columns[] = $value,
                default => throw new \InvalidArgumentException("{$verb}(): unknown argument '{$key}'."),
            };
        }

        return [$columns, $wt, $sort, $name];
    }

    /**
     * dplyr's default count column: n, or nn... when n is taken. count()
     * and tally() only avoid the output's grouping columns ($taken);
     * add_count() and add_tally() avoid every column.
     */
    public static function countName(Table $t, ?string $name, ?array $taken = null): string
    {
        if ($name !== null) {
            return $name;
        }
        $taken ??= $t->columns();
        $name = 'n';
        while (\in_array($name, $taken, true)) {
            $name .= 'n';
        }

        return $name;
    }

    /** The count for a group: rows, or the sum of $wt (NA dropped). */
    public static function tallyOf(mixed $wt): Vec
    {
        return $wt === null ? n() : sum($wt, na_rm: true);
    }

    public static function glimpse(Table $t, int $width): string
    {
        $lines = ['Rows: '.number_format($t->nrow()), 'Columns: '.$t->ncol()];
        if ($t->groups() !== []) {
            $lines[] = 'Groups: '.implode(', ', $t->groups());
        }
        $types = $t->types();
        $nameWidth = \max(array_map('strlen', [...$t->columns(), '']));
        foreach ($t->columns() as $name) {
            $abbr = match ($types[$name]->value) {
                'float' => '<dbl>', 'int' => '<int>', 'bool' => '<lgl>', 'string' => '<chr>',
                'factor' => '<fct>', 'date' => '<date>', default => '<'.$types[$name]->value.'>',
            };
            $prefix = '$ '.\str_pad($name, $nameWidth).' '.$abbr.' ';
            $values = [];
            $used = \strlen($prefix);
            foreach ($t->column($name) as $v) {
                $s = match (true) {
                    $v === null => 'NA',
                    \is_bool($v) => $v ? 'TRUE' : 'FALSE',
                    \is_string($v) => '"'.$v.'"',
                    $v instanceof \DateTimeInterface => $v->format('Y-m-d'),
                    default => (string) $v,
                };
                if ($used + \strlen($s) + 2 > $width) {
                    $values[] = '…';
                    break;
                }
                $values[] = $s;
                $used += \strlen($s) + 2;
            }
            $lines[] = $prefix.implode(', ', $values);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Split columns into rows (separate_longer_*). Each row's pieces must
     * have the same length across columns, or length one (recycled).
     *
     * @param Closure(string): list<string> $split
     */
    public static function longer(Table $t, mixed $cols, Closure $split): Table
    {
        $names = Selector::select([$cols], $t);
        $vectors = array_map(fn ($c) => $t->column($c), array_combine($names, $names));
        $index = [];
        $out = array_fill_keys($names, []);
        for ($i = 0, $n = $t->nrow(); $i < $n; $i++) {
            $pieces = array_map(fn ($v) => $v[$i] === null ? [null] : $split(Tidyr::str($v[$i])), $vectors);
            $len = \max(array_map('count', $pieces));
            foreach ($pieces as $c => $p) {
                $p = Vec::recycle($p, $len, "split of '{$c}' in row ".($i + 1));
                array_push($out[$c], ...$p);
            }
            array_push($index, ...array_fill(0, $len, $i));
        }

        return Ops::take($t, $index)->mutate($out);
    }

    /**
     * A result built as a DataFrame, moved back to $like's backend when that
     * backend can load tables (PolarsTable::fromTable()). Lazy SQL tables
     * can't, so their results stay in memory, and so do tables with
     * list-columns of tables.
     */
    public static function sameBackend(Table $result, Table $like): Table
    {
        if (! $result instanceof DataFrame || $like instanceof DataFrame || ! method_exists($like, 'fromTable')) {
            return $result;
        }
        foreach ($result->types() as $name => $type) {
            if ($type === \Phibble\Type::List && array_any($result->column($name), fn ($v) => $v instanceof Table)) {
                return $result;
            }
        }

        return $like::fromTable($result);
    }

    /** Group by $names, keeping $like's _drop: false setting. */
    public static function regroup(Table $t, array $names, Table $like): Table
    {
        if ($names === []) {
            return $t->ungroup();
        }
        if (method_exists($like, 'dropsEmptyGroups') && ! $like->dropsEmptyGroups()) {
            return $t->groupBy($names, false);
        }

        return $t->groupBy($names);
    }
    /**
     * Values for ordering: a factor's labels become level positions, so
     * factors sort by level as in R. Other columns are returned as given.
     *
     * @param list<mixed> $values
     * @return list<mixed>
     */
    public static function ordinal(Table $t, string $column, array $values): array
    {
        if (($t->types()[$column] ?? null) !== \Phibble\Type::Factor) {
            return $values;
        }
        $levels = array_flip($t->collect()->levels($column));

        return array_map(fn ($v) => $v === null ? null : ($levels[$v] ?? null), $values);
    }
    /** na_rm as a named option, only when set. */
    public static function naRm(bool $naRm): array
    {
        return $naRm ? ['na_rm' => true] : [];
    }

    /** Options for first(), last() and nth(). */
    public static function positionOptions(mixed $default, mixed $orderBy, bool $naRm): array
    {
        return array_filter(['default' => $default, 'order_by' => $orderBy], fn ($v) => $v !== null) + self::naRm($naRm);
    }

    /** Options for lag() and lead(). */
    public static function shiftOptions(int $n, mixed $default, mixed $orderBy): array
    {
        return ($n === 1 ? [] : ['n' => $n]) + array_filter(['default' => $default, 'order_by' => $orderBy], fn ($v) => $v !== null);
    }
    /** when_any() / when_all() with R's three-valued logic. */
    public static function when(array $conditions, bool $naRm, bool $any): Vec
    {
        if (! $naRm && $conditions !== []) {
            $out = array_shift($conditions);
            foreach ($conditions as $c) {
                $out = self::call($any ? '|' : '&', [$out, $c], columns: [0, 1]);
            }

            return $out instanceof Vec ? $out : self::call($any ? '|' : '&', [$out, $out], columns: [0, 1]);
        }
        return new Vec(function (Table $g) use ($conditions, $naRm, $any) {
            $out = array_fill(0, $g->nrow(), ! $any);
            foreach ($conditions as $c) {
                foreach (Vec::values($c, $g, true) as $i => $v) {
                    if ($v === null && $naRm) {
                        continue;
                    }
                    $cur = $out[$i];
                    $out[$i] = $any
                        ? ($cur === true || $v === true ? true : ($cur === null || $v === null ? null : false))
                        : ($cur === false || $v === false ? false : ($cur === null || $v === null ? null : true));
                }
            }

            return $out;
        });
    }

    /** recode_values() / replace_values(). */
    public static function recode(mixed $x, array $cases, bool $replace): Vec
    {
        $from = $cases['from'] ?? null;
        $to = $cases['to'] ?? null;
        $default = $cases['default'] ?? null;
        $unmatched = $cases['unmatched'] ?? 'default';
        unset($cases['from'], $cases['to'], $cases['default'], $cases['unmatched']);
        if ($from !== null) {
            if ($cases !== []) {
                throw new \InvalidArgumentException('Give cases or from/to, not both.');
            }
            $to = \is_array($to) ? array_values($to) : array_fill(0, \count($from), $to);
            $cases = array_map(fn ($f, $t) => [$f, $t], array_values($from), $to);
        }

        return new Vec(function (Table $g) use ($x, $cases, $default, $unmatched, $replace) {
            $values = Vec::values($x, $g, true);
            $out = $replace ? $values : Vec::values($default, $g);
            $done = array_fill(0, \count($values), false);
            foreach ($cases as [$match, $result]) {
                $keys = array_flip(array_map(self::key(...), \is_array($match) ? $match : [$match]));
                $results = Vec::values($result, $g);
                foreach ($values as $i => $v) {
                    if (! $done[$i] && isset($keys[self::key($v)])) {
                        $out[$i] = $results[$i];
                        $done[$i] = true;
                    }
                }
            }
            if (! $replace && $unmatched === 'error' && \in_array(false, $done, true)) {
                $i = array_search(false, $done, true);
                throw new \UnexpectedValueException('recode_values(): value at row '.($i + 1).' is unmatched.');
            }

            return $out;
        });
    }
    public static function callStats(string $function, mixed ...$args): mixed
    {
        if (! \function_exists($function)) {
            throw new \RuntimeException(
                "{$function}() is not available. Install or update the php_stats extension (erikwestlund/php-stats)."
            );
        }

        return $function(...$args);
    }
}
