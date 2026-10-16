<?php

declare(strict_types=1);

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;
use Phibble\Frame;

/**
 * @internal Group-vector primitives. Uses the table's native method when the
 * backend has one (Table contract v1) and a fallback built on v0 otherwise.
 */
final class Ops
{
    private const string ROW = '.phplyr_row';

    /**
     * Add or replace columns, each computed once per group by a closure
     * returning one value per row of the group, or one value to recycle.
     * Later entries see earlier ones.
     *
     * @param array<string, Closure(Table): mixed> $columns
     */
    public static function mutateGroups(Table $t, array $columns): Table
    {
        if ($columns === []) {
            return $t;
        }
        $checked = array_map(
            fn (Closure $fn) => fn (Table $g) => Vec::recycle($fn($g), $g->nrow(), 'expression'),
            $columns,
        );
        if (method_exists($t, 'mutateGroups')) {
            return $t->mutateGroups($checked);
        }

        foreach ($checked as $name => $fn) {
            $t = $t->mutate([$name => self::scatter($t, $fn)]);
        }

        return $t;
    }

    /**
     * Keep rows where the per-group mask is true (null counts as false).
     *
     * @param Closure(Table): list<?bool> $mask
     */
    public static function filterGroups(Table $t, Closure $mask): Table
    {
        $checked = fn (Table $g) => Vec::recycle($mask($g), $g->nrow(), 'filter condition');
        if (method_exists($t, 'filterGroups')) {
            return $t->filterGroups($checked);
        }
        $keep = self::scatter($t, $checked);

        return self::take($t, array_keys(array_filter($keep, fn ($v) => $v === true)));
    }

    /**
     * Rows by 0-based index, in the given order; a null index is a row of NA.
     * Keeps types and grouping.
     *
     * @param list<int|null> $indices
     */
    public static function take(Table $t, array $indices): Table
    {
        $indices = array_values($indices);
        if (method_exists($t, 'take') && (new \ReflectionMethod($t, 'take'))->isPublic()) {
            return $t->take($indices);
        }
        $frame = $t->collect();
        $hasNull = \in_array(null, $indices, true);
        if ($hasNull) {
            // Append one all-NA row and point null indices at it.
            $na = Frame::fromColumns(array_fill_keys($frame->columns(), [null]));
            $n = $frame->nrow();
            $frame = Frame::concat([$frame, $na])->groupBy($frame->groups());
            $indices = array_map(fn ($i) => $i ?? $n, $indices);
        }

        // Frame::take() is private in contract v0; call it in scope.
        return (fn (array $idx) => $this->take($idx, $this->groups))->call($frame, $indices);
    }

    /**
     * Each group's 0-based row indexes in the table, in group order.
     *
     * @return list<array{key: array<string, mixed>, table: Table, rows: list<int>}>
     */
    public static function groupRows(Table $t): array
    {
        $tagged = $t->mutate([self::ROW => range(0, \max(0, $t->nrow() - 1))]);
        if ($t->nrow() === 0) {
            return [];
        }
        $out = [];
        foreach ($tagged->eachGroup() as ['key' => $key, 'table' => $g]) {
            $out[] = [
                'key' => $key,
                'table' => $g->select(array_values(array_diff($g->columns(), [self::ROW]))),
                'rows' => $g->column(self::ROW),
            ];
        }

        return $out;
    }

    /**
     * Evaluate a per-group closure and put its values back in row order.
     *
     * @return list<mixed>
     */
    private static function scatter(Table $t, Closure $fn): array
    {
        $out = array_fill(0, $t->nrow(), null);
        foreach (self::groupRows($t) as ['table' => $g, 'rows' => $rows]) {
            foreach ($fn($g) as $j => $value) {
                $out[$rows[$j]] = $value;
            }
        }

        return $out;
    }
}
