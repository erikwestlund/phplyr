<?php

declare(strict_types=1);

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;

/** @internal mutate()'s evaluation. */
final class Mutate
{
    /**
     * @param array<int|string, mixed> $columns
     * @param array{_keep: string, _before: mixed, _after: mixed} $o
     * @param list<string> $made Receives the names created or modified, in call order.
     */
    public static function run(Table $t, array $columns, array $o, ?array &$made = []): Table
    {
        $before = $t->columns();
        $made = [];
        foreach ($columns as $key => $def) {
            if ($def instanceof Across) {
                foreach ($def->expand($t, 'mutate') as $name => $vec) {
                    $t = self::one($t, $name, $vec);
                    $made[] = $name;
                }

                continue;
            }
            if (\is_int($key)) {
                throw new \InvalidArgumentException('mutate() needs named arguments, for example mutate(total: ...), or across(...).');
            }
            $t = self::one($t, $key, $def);
            if ($def !== null) {
                $made[] = $key;
            } else {
                $made = array_values(array_diff($made, [$key]));
            }
        }
        $made = array_values(array_unique($made));

        $t = match ($o['_keep']) {
            'all' => $t,
            'none' => $t->select(array_values(array_filter(
                $t->columns(),
                fn ($c) => \in_array($c, $t->groups(), true) || \in_array($c, $made, true),
            ))),
            'used', 'unused' => throw new \InvalidArgumentException("mutate(): _keep '{$o['_keep']}' is not supported yet; use 'all' or 'none'."),
            default => throw new \InvalidArgumentException("mutate(): unknown _keep '{$o['_keep']}'."),
        };

        if ($o['_before'] !== null || $o['_after'] !== null) {
            $new = array_values(array_diff($made, $before));
            $t = $t->select(Internal::relocated($t->columns(), $new, $o['_before'], $o['_after'], $t));
        }

        return $t;
    }

    private static function one(Table $t, string $name, mixed $def): Table
    {
        if ($def === null) {
            if (\in_array($name, $t->groups(), true)) {
                throw new \InvalidArgumentException("mutate(): can't remove grouping column '{$name}'.");
            }

            return \in_array($name, $t->columns(), true)
                ? $t->select(array_values(array_diff($t->columns(), [$name])))
                : $t;
        }
        if ($def instanceof Vec) {
            return Ops::mutateGroups($t, [$name => $def->fn]);
        }
        if ($def instanceof Closure || (\is_array($def) && array_is_list($def))) {
            return $t->mutate([$name => $def]);
        }
        if (\is_array($def)) {
            throw new \InvalidArgumentException("mutate(): '{$name}' must be a closure, a vector helper, a list or a scalar.");
        }

        return $t->mutate([$name => $def]);
    }
}
