<?php

declare(strict_types=1);

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;

/**
 * A vectorised expression: evaluated once per group, it returns a list with
 * one value per row of the group, or a single value that is recycled.
 *
 * Window helpers (lag, cumsum, min_rank...) and summary helpers (mean, n...)
 * return a Vec. mutate() and filter() evaluate it per group, and summarise()
 * uses its single value. A Vec is invokable, so vec($group) evaluates it.
 */
final readonly class Vec
{
    /** @param Closure(Table): mixed $fn */
    public function __construct(public Closure $fn)
    {
    }

    public function __invoke(Table $group): mixed
    {
        return ($this->fn)($group);
    }

    /**
     * Transform each value: $f receives (value, Row $r, Table $group).
     * For example lag('x')->map(fn ($prev, $r) => $r->x - $prev).
     */
    public function map(Closure $f): self
    {
        return new self(function (Table $g) use ($f) {
            $values = self::values($this, $g);
            $out = [];
            $i = 0;
            foreach ($g as $row) {
                $out[] = $f($values[$i++], $row, $g);
            }

            return $out;
        });
    }

    /**
     * Evaluate an argument to one value per row of $g.
     *
     * - Vec: evaluated on the group; a single value is recycled.
     * - Closure: a row closure, called with (Row $r, Table $group).
     * - list array: used as is (one value per row).
     * - string: a column name when $stringIsColumn, otherwise a literal.
     * - anything else: a literal, recycled.
     *
     * @return list<mixed>
     */
    public static function values(mixed $arg, Table $g, bool $stringIsColumn = false): array
    {
        $n = $g->nrow();
        if ($arg instanceof self) {
            return self::recycle($arg($g), $n, 'expression');
        }
        if ($arg instanceof Closure) {
            $out = [];
            foreach ($g as $row) {
                $out[] = $arg($row, $g);
            }

            return $out;
        }
        if (\is_string($arg) && $stringIsColumn) {
            return $g->column($arg);
        }
        if (\is_array($arg) && array_is_list($arg)) {
            return self::recycle($arg, $n, 'list');
        }

        return array_fill(0, $n, $arg);
    }

    /**
     * Recycle a scalar (or length-1 list) to $n values; check list lengths.
     *
     * @return list<mixed>
     */
    public static function recycle(mixed $value, int $n, string $what): array
    {
        if (! \is_array($value)) {
            return array_fill(0, $n, $value);
        }
        $value = array_values($value);
        if (\count($value) === $n) {
            return $value;
        }
        if (\count($value) === 1) {
            return array_fill(0, $n, $value[0]);
        }

        throw new \LengthException("The {$what} has ".\count($value)." values; expected {$n} or 1.");
    }
}
