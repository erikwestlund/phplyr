<?php

declare(strict_types=1);

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;
use Phibble\Expr\Call;
use Phibble\Expr\Col;
use Phibble\Expr\Evaluator;
use Phibble\Expr\Expr;
use Phibble\Expr\Lit;
use Phibble\Expr\Scalar;
use Phibble\Frame;

/**
 * A vectorised expression: evaluated once per group, it returns a list with
 * one value per row of the group, or a single value that is recycled.
 *
 * When every input can be inspected (column names, literals, other
 * expressions), a Vec also carries a Phibble\Expr. Verbs then pass that
 * expression to the table, so database backends can compile it. Vecs built
 * from PHP closures carry no expression and are evaluated in PHP.
 *
 * A Vec is invokable: $vec($group) evaluates it on a table.
 */
final readonly class Vec
{
    /** @param Closure(Table): mixed $fn */
    public function __construct(
        public Closure $fn,
        public ?Expr $expr = null,
    ) {
    }

    /** A Vec for an expression, evaluated in memory with phibble's evaluator. */
    public static function of(Expr $expr): self
    {
        return new self(fn (Table $g) => self::evaluate($expr, $g), $expr);
    }

    public function __invoke(Table $group): mixed
    {
        return ($this->fn)($group);
    }

    /** Evaluate an expression on a table: a list, or one value for an aggregate. */
    public static function evaluate(Expr $expr, Table $g): mixed
    {
        $result = (new Evaluator($g))->eval($expr);

        return $result instanceof Scalar ? $result->value : $result;
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

    // Operators. A non-Vec operand is a literal; wrap columns in col().

    public function add(mixed $y): self { return self::op('+', $this, $y); }
    public function sub(mixed $y): self { return self::op('-', $this, $y); }
    public function mul(mixed $y): self { return self::op('*', $this, $y); }
    public function div(mixed $y): self { return self::op('/', $this, $y); }
    public function mod(mixed $y): self { return self::op('%%', $this, $y); }
    public function pow(mixed $y): self { return self::op('^', $this, $y); }
    public function eq(mixed $y): self { return self::op('==', $this, $y); }
    public function ne(mixed $y): self { return self::op('!=', $this, $y); }
    public function lt(mixed $y): self { return self::op('<', $this, $y); }
    public function le(mixed $y): self { return self::op('<=', $this, $y); }
    public function gt(mixed $y): self { return self::op('>', $this, $y); }
    public function ge(mixed $y): self { return self::op('>=', $this, $y); }
    public function and(mixed $y): self { return self::op('&', $this, $y); }
    public function or(mixed $y): self { return self::op('|', $this, $y); }
    public function neg(): self { return self::op('neg', $this); }
    public function not(): self { return self::op('!', $this); }
    public function isNa(): self { return self::op('is_na', $this); }
    public function isIn(array $values): self { return self::op('in', $this, new Lit(array_values($values))); }
    public function between(mixed $low, mixed $high): self { return self::op('between', $this, $low, $high); }
    public function desc(): Desc { return new Desc($this); }

    private static function op(string $fn, mixed ...$args): self
    {
        return Internal::call($fn, $args, [], columns: [0]);
    }

    /**
     * Evaluate an argument to one value per row of $g.
     *
     * - Vec: evaluated on the group; a single value is recycled.
     * - Phibble\Expr: evaluated on the group.
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
        if ($arg instanceof Expr) {
            return self::recycle(self::evaluate($arg, $g), $n, 'expression');
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
