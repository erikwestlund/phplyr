<?php

declare(strict_types=1);

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;

/**
 * across(): apply functions to a selection of columns inside mutate() or
 * summarise(). Grouping columns are never selected.
 *
 * A function may be:
 * - a phplyr helper as a first-class callable, such as mean(...) or
 *   lag(...). It is called with the column name.
 * - in summarise(), any closure taking the column's values as a list.
 * - in mutate(), a closure taking one value (applied to each value), or a
 *   closure whose first parameter is typed array (given the whole column,
 *   returning a list or one value).
 */
final readonly class Across
{
    /**
     * @param array<int|string, mixed> $cols select()-style arguments.
     * @param Closure|array<int|string, Closure>|null $fns
     */
    public function __construct(
        public array $cols,
        public Closure|array|null $fns,
        public ?string $names,
    ) {
    }

    /** @return list<string> */
    public function columns(Table $t): array
    {
        return array_values(array_diff(Selector::select($this->cols, $t), $t->groups()));
    }

    /**
     * Expand into name => Vec definitions.
     *
     * @param 'mutate'|'summarise' $verb
     * @return array<string, Vec>
     */
    public function expand(Table $t, string $verb): array
    {
        $columns = $this->columns($t);
        if ($this->fns === null) {
            $fns = [null];
            $template = $this->names ?? '{col}';
        } elseif ($this->fns instanceof Closure) {
            $fns = [$this->fns];
            $template = $this->names ?? '{col}';
        } else {
            $fns = $this->fns;
            $template = $this->names ?? '{col}_{fn}';
        }

        $out = [];
        foreach ($columns as $col) {
            $i = 0;
            foreach ($fns as $fnName => $fn) {
                $i++;
                $name = strtr($template, [
                    '{col}' => $col, '{.col}' => $col,
                    '{fn}' => \is_int($fnName) ? (string) $i : $fnName,
                    '{.fn}' => \is_int($fnName) ? (string) $i : $fnName,
                ]);
                $out[$name] = self::apply($fn, $col, $verb);
            }
        }

        return $out;
    }

    private static function apply(?Closure $fn, string $col, string $verb): Vec
    {
        if ($fn === null) {
            return col($col);
        }
        if (self::isHelper($fn)) {
            $def = $fn($col);

            return $def instanceof Vec ? $def : new Vec($def);
        }
        if ($verb === 'summarise' || self::takesArray($fn)) {
            return new Vec(fn (Table $g) => $fn($g->column($col)));
        }

        return new Vec(fn (Table $g) => array_map($fn, $g->column($col)));
    }

    /** A first-class callable of a phplyr function, such as mean(...). */
    public static function isHelper(Closure $fn): bool
    {
        $name = (new \ReflectionFunction($fn))->getName();

        return str_starts_with($name, 'Phplyr\\') && ! str_contains($name, '{closure');
    }

    private static function takesArray(Closure $fn): bool
    {
        $params = (new \ReflectionFunction($fn))->getParameters();
        $type = $params === [] ? null : $params[0]->getType();

        return $type instanceof \ReflectionNamedType && $type->getName() === 'array';
    }
}
