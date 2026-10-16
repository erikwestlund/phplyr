<?php

declare(strict_types=1);

namespace Phplyr;

/**
 * cur_group_id() / cur_group_rows(): values that depend on which group is
 * being evaluated. mutate(), summarise() and reframe() resolve them against
 * the whole table.
 */
final readonly class CurGroup
{
    /** @param 'id'|'rows' $kind */
    public function __construct(public string $kind)
    {
    }

    /**
     * Per-row values for mutate(): each row's 1-based group number, or its
     * 1-based row number in the table.
     *
     * @return list<int>
     */
    public function perRow(\Phibble\Contracts\Table $t): array
    {
        if ($this->kind === 'rows') {
            return $t->nrow() === 0 ? [] : range(1, $t->nrow());
        }

        return group_indices()($t);
    }

    /**
     * A closure for summarise() / reframe() that finds the group from its
     * key values: the group number, or the group's row numbers.
     */
    public function perGroup(\Phibble\Contracts\Table $t): \Closure
    {
        $groups = $t->groups();
        $index = [];
        foreach (Ops::groupRows($t) as $i => $g) {
            $key = implode("\x1f", array_map(Internal::key(...), array_values($g['key'])));
            $index[$key] = $this->kind === 'id' ? $i + 1 : array_map(fn ($r) => $r + 1, $g['rows']);
        }

        return function (\Phibble\Contracts\Table $g) use ($groups, $index) {
            $key = implode("\x1f", array_map(fn ($c) => Internal::key($g->column($c)[0] ?? null), $groups));

            return $index[$key] ?? ($this->kind === 'id' ? 1 : []);
        };
    }
}
