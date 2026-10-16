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
        $used = [];
        foreach ($columns as $key => $def) {
            if ($def instanceof Across) {
                foreach ($def->expand($t, 'mutate') as $name => $vec) {
                    $used = [...$used, ...self::used($vec, $name, $o['_keep'])];
                    $t = self::one($t, $name, $vec);
                    $made[] = $name;
                }

                continue;
            }
            if (\is_int($key)) {
                throw new \InvalidArgumentException('mutate() needs named arguments, for example mutate(total: ...), or across(...).');
            }
            $used = [...$used, ...self::used($def, $key, $o['_keep'])];
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
            'used', 'unused' => $t->select(array_values(array_filter(
                $t->columns(),
                fn ($c) => \in_array($c, $t->groups(), true) || \in_array($c, $made, true)
                    || ! \in_array($c, $before, true)
                    || (\in_array($c, $used, true) === ($o['_keep'] === 'used')),
            ))),
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
            return $def->expr !== null
                ? $t->mutate([$name => $def->expr])
                : Ops::mutateGroups($t, [$name => $def->fn]);
        }
        if ($def instanceof \Phibble\Expr\Expr) {
            return $t->mutate([$name => $def]);
        }
        if ($def instanceof Closure || (\is_array($def) && array_is_list($def))) {
            return $t->mutate([$name => $def]);
        }
        if (\is_array($def)) {
            throw new \InvalidArgumentException("mutate(): '{$name}' must be a closure, a vector helper, a list or a scalar.");
        }

        return $t->mutate([$name => $def]);
    }

    /**
     * Columns an argument reads, for _keep 'used' / 'unused'. Only
     * expressions can be inspected; closures can't.
     *
     * @return list<string>
     */
    private static function used(mixed $def, string $name, string $keep): array
    {
        if ($keep !== 'used' && $keep !== 'unused') {
            return [];
        }
        $expr = $def instanceof Vec ? $def->expr : ($def instanceof \Phibble\Expr\Expr ? $def : null);
        if ($expr !== null) {
            return $expr->columns();
        }
        if ($def === null || \is_scalar($def) || (\is_array($def) && array_is_list($def))) {
            return [];
        }

        throw new \InvalidArgumentException("mutate(): _keep '{$keep}' requires expression arguments; '{$name}' is a PHP closure. Use helpers such as col('x')->mul(2).");
    }
}
