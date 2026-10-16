<?php

declare(strict_types=1);

namespace Phplyr;

use Phibble\Expr\Call;
use Phibble\Expr\Col;
use Phibble\Expr\Lit;

/**
 * A join specification built by join_by(). Each condition is one of the
 * Table contract's by shapes: [x, y] (equality), [x, op, y] (inequality),
 * or ['closest', x, op, y] (rolling).
 */
final readonly class JoinBy
{
    private const array OPS = ['==', '>=', '>', '<=', '<'];

    /** @param list<array<int, string>> $conditions */
    public function __construct(public array $conditions)
    {
    }

    /** True when every condition is an equality. */
    public function isEquality(): bool
    {
        return array_all($this->conditions, fn (array $c) => \count($c) === 2);
    }

    /**
     * Parse one join_by() argument into conditions.
     *
     * @return list<array<int, string>>
     */
    public static function parse(mixed $arg, int|string $key = 0): array
    {
        if (\is_string($key)) {
            return [[$key, self::name($arg)]];
        }
        if (\is_string($arg)) {
            return [[$arg, $arg]];
        }
        if ($arg instanceof Closest) {
            $inner = self::parse($arg->condition);
            if (\count($inner) !== 1 || \count($inner[0]) !== 3 || $inner[0][1] === '==') {
                throw new \InvalidArgumentException('closest() takes one inequality, such as closest(col(\'t\')->ge(col(\'start\'))).');
            }

            return [['closest', ...$inner[0]]];
        }
        if (\is_array($arg) && array_is_list($arg) && $arg !== [] && array_all($arg, fn ($c) => \is_array($c))) {
            // A list of conditions, as within() and overlaps() return.
            return array_merge(...array_map(fn ($c) => self::parse($c), $arg));
        }
        if (\is_array($arg) && array_is_list($arg)) {
            return match (true) {
                \count($arg) === 2 => [[self::name($arg[0]), self::name($arg[1])]],
                \count($arg) === 3 && \in_array($arg[1], self::OPS, true) => [self::condition(self::name($arg[0]), $arg[1], self::name($arg[2]))],
                default => throw new \InvalidArgumentException('join_by() conditions are [x, y] or [x, op, y].'),
            };
        }
        if (\is_array($arg)) {
            $out = [];
            foreach ($arg as $x => $y) {
                $out = [...$out, ...self::parse($y, $x)];
            }

            return $out;
        }
        $expr = $arg instanceof Vec ? $arg->expr : $arg;
        if ($expr instanceof Call) {
            if (\in_array($expr->fn, self::OPS, true) && \count($expr->args) === 2) {
                return [self::condition(self::name($expr->args[0]), $expr->fn, self::name($expr->args[1]))];
            }
            if ($expr->fn === 'between' && \count($expr->args) === 3) {
                [$x, $lo, $hi] = array_map(self::name(...), $expr->args);

                return [[$x, '>=', $lo], [$x, '<=', $hi]];
            }
        }

        throw new \InvalidArgumentException('join_by() takes column names, comparisons such as col(\'t\')->ge(col(\'start\')), between(), within(), overlaps() or closest().');
    }

    /** @return array<int, string> */
    private static function condition(string $x, string $op, string $y): array
    {
        return $op === '==' ? [$x, $y] : [$x, $op, $y];
    }

    /** A column name from a string, col(), or a literal string (between()'s bounds). */
    private static function name(mixed $arg): string
    {
        $arg = $arg instanceof Vec ? $arg->expr : $arg;

        return match (true) {
            \is_string($arg) => $arg,
            $arg instanceof Col => $arg->name,
            $arg instanceof Lit && \is_string($arg->value) => $arg->value,
            default => throw new \InvalidArgumentException('join_by() conditions compare columns; got '.get_debug_type($arg).'.'),
        };
    }
}
