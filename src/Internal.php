<?php

declare(strict_types=1);

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;
use Phibble\Frame;

/** @internal Helpers shared by the verb functions. */
final class Internal
{
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

    /** A Vec applying a rank function to $x, honouring desc(). */
    public static function ranked(mixed $x, Closure $rank): Vec
    {
        [$x, $desc] = self::direction($x);

        return new Vec(fn (Table $g) => $rank(Vec::values($x, $g, true), $desc));
    }

    /**
     * A Vec applying a window function to $x after ordering rows by
     * $orderBy, then restoring row order.
     */
    public static function ordered(mixed $x, mixed $orderBy, Closure $window): Vec
    {
        return new Vec(function (Table $g) use ($x, $orderBy, $window) {
            $values = Vec::values($x, $g, true);
            if ($orderBy === null) {
                return $window($values);
            }
            [$key, $desc] = self::direction($orderBy);
            $keys = Vec::values($key, $g, true);
            $order = Window::order($keys, $desc);
            // Rows with NA keys sort last, as in R.
            foreach (array_keys($keys) as $i) {
                if (! \in_array($i, $order, true)) {
                    $order[] = $i;
                }
            }
            $shifted = $window(array_map(fn ($i) => $values[$i], $order));
            $out = [];
            foreach ($order as $pos => $i) {
                $out[$i] = $shifted[$pos];
            }
            ksort($out);

            return array_values($out);
        });
    }

    /**
     * A stable key for matching values: 1 and 1.0 match, NA matches NA.
     */
    public static function key(mixed $value): string
    {
        return match (true) {
            $value === null => "\0NA",
            \is_bool($value) => 'b'.(int) $value,
            \is_int($value), \is_float($value) => 'n'.var_export((float) $value, true),
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
        $out = $work->arrange($sort);
        if ($temp !== []) {
            $out = $out->select(array_values(array_diff($out->columns(), $temp)));
        }

        return $groups === [] ? $out : $out->groupBy($groups);
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
                    $out[$name] = self::single($vec, $name, $verb);
                }

                continue;
            }
            if (\is_int($key)) {
                throw new \InvalidArgumentException("{$verb}() needs named arguments, for example {$verb}(total: ...), or across(...).");
            }
            $out[$key] = match (true) {
                $def instanceof Vec, $def instanceof Closure => self::single($def, $key, $verb),
                default => fn () => $def,
            };
        }

        return $out;
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
        foreach (Ops::groupRows($t) as ['table' => $g]) {
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
                $values[$key] = $def instanceof Vec || $def instanceof Closure ? $def($g) : $def;
                $values[$key] = \is_array($values[$key]) ? array_values($values[$key]) : [$values[$key]];
            }
            $lengths = array_unique(array_map('count', $values));
            $len = \count($lengths) === 0 ? 1 : \max($lengths);
            foreach ($values as $name => $v) {
                $values[$name] = Vec::recycle($v, $len, "reframe() result '{$name}'");
            }
            $keys = Ops::take($g->select($groups), array_fill(0, $len, 0));
            $parts[] = $keys->ungroup()->mutate($values);
        }
        if ($parts === []) {
            return $t->select($groups)->ungroup()->head(0);
        }

        return Frame::concat($parts)->ungroup();
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

    /** dplyr's default count column: n, or nn... when n is taken. */
    public static function countName(Table $t, ?string $name): string
    {
        if ($name !== null) {
            return $name;
        }
        $name = 'n';
        while (\in_array($name, $t->columns(), true)) {
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
            $prefix = '$ '.str_pad($name, $nameWidth).' '.$abbr.' ';
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
