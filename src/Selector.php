<?php

declare(strict_types=1);

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;
use Phibble\Exception\ColumnNotFound;

/**
 * A tidyselect selection: resolves to column names against a table.
 * Built by starts_with(), where(), everything() and friends.
 */
final readonly class Selector
{
    /** @param Closure(Table): list<string> $resolver */
    public function __construct(
        public Closure $resolver,
        public bool $negated = false,
    ) {
    }

    /** @return list<string> */
    public function columns(Table $t): array
    {
        return array_values(($this->resolver)($t));
    }

    public function negate(): self
    {
        return new self($this->resolver, ! $this->negated);
    }

    /**
     * Resolve select()-style arguments to an ordered map of output name =>
     * source column. Arguments are column names, lists of names, or
     * selectors. A string key renames: ['new' => 'old']. A string key on a
     * selection of several columns numbers them: new1, new2...
     * A negated selector (except()) removes columns; when it comes first,
     * the selection starts from every column, as with dplyr's select(-x).
     * An associative array renames too, and can sit before positional
     * arguments: select(['ident' => 'id'], everything()).
     *
     * @param array<int|string, mixed> $args
     * @return array<string, string>
     */
    public static function resolve(array $args, Table $t): array
    {
        $out = [];
        $first = true;
        $expanded = [];
        foreach ($args as $key => $arg) {
            if (\is_array($arg) && $arg !== [] && ! array_is_list($arg)) {
                foreach ($arg as $k => $v) {
                    \is_string($k) ? $expanded[] = [$k, $v] : $expanded[] = [null, $v];
                }
            } else {
                $expanded[] = [\is_string($key) ? $key : null, $arg];
            }
        }
        foreach ($expanded as [$key, $arg]) {
            if ($arg instanceof self && $arg->negated) {
                if ($first) {
                    $out = self::identity($t->columns());
                }
                $drop = $arg->columns($t);
                $out = array_filter($out, fn (string $source) => ! \in_array($source, $drop, true));
                $first = false;

                continue;
            }
            $first = false;
            $columns = self::names($arg, $t);
            if (\is_string($key)) {
                if (\count($columns) === 1) {
                    $out = self::withoutSource($out, $columns[0]);
                    $out[$key] = $columns[0];
                } else {
                    foreach ($columns as $i => $col) {
                        $out = self::withoutSource($out, $col);
                        $out[$key.($i + 1)] = $col;
                    }
                }

                continue;
            }
            foreach ($columns as $col) {
                if (! \in_array($col, $out, true)) {
                    $out[$col] = $col;
                }
            }
        }

        return $out;
    }

    /**
     * The column names selected by the arguments, renames ignored.
     *
     * @param array<int|string, mixed> $args
     * @return list<string>
     */
    public static function select(array $args, Table $t): array
    {
        return array_values(self::resolve($args, $t));
    }

    /** True when an argument is something select() understands. */
    public static function isSelection(mixed $arg): bool
    {
        return \is_string($arg) || $arg instanceof self || (\is_array($arg) && array_is_list($arg) && array_all($arg, fn ($c) => \is_string($c)));
    }

    /** @return list<string> */
    private static function names(mixed $arg, Table $t): array
    {
        $all = $t->columns();
        if ($arg instanceof self) {
            return $arg->columns($t);
        }
        if (\is_string($arg)) {
            $arg = [$arg];
        }
        if (\is_array($arg)) {
            foreach ($arg as $name) {
                if (! \in_array($name, $all, true)) {
                    throw ColumnNotFound::named($name, $all);
                }
            }

            return array_values($arg);
        }

        throw new \InvalidArgumentException('Cannot select columns with '.get_debug_type($arg).'.');
    }

    /** @param list<string> $columns */
    private static function identity(array $columns): array
    {
        return array_combine($columns, $columns);
    }

    private static function withoutSource(array $out, string $source): array
    {
        return array_filter($out, fn (string $s) => $s !== $source);
    }
}
