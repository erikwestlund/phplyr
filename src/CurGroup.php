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
     * A closure for summarise() / reframe(). Tables visit groups in
     * eachGroup() order, so the n-th call is the n-th group: its number, or
     * its row numbers.
     */
    public function perGroup(\Phibble\Contracts\Table $t): \Closure
    {
        $groups = Ops::groupIndex($t);
        $i = 0;

        return function () use ($groups, &$i) {
            $g = $groups[$i++] ?? null;

            return $this->kind === 'id' ? $i : array_map(fn ($r) => $r + 1, $g['rows'] ?? []);
        };
    }
}
