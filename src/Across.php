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
final class Across
{
    /** The column across() is applying a function to, for cur_column(). */
    public static ?string $current = null;

    /**
     * @param array<int|string, mixed> $cols select()-style arguments.
     * @param Closure|array<int|string, Closure>|null $fns
     */
    public function __construct(
        public readonly array $cols,
        public readonly Closure|array|null $fns,
        public readonly ?string $names,
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
            $def = self::at($col, fn () => $fn($col));

            return $def instanceof Vec ? $def : new Vec($def);
        }
        // A closure may return a helper's result, as in fn ($v) => mean($v, na_rm: true).
        $resolve = fn (mixed $r, Table $g) => $r instanceof Vec ? $r($g) : $r;
        if ($verb === 'summarise' || self::takesArray($fn)) {
            return new Vec(fn (Table $g) => self::at($col, fn () => $resolve($fn($g->column($col)), $g)));
        }

        return new Vec(fn (Table $g) => self::at($col, fn () => array_map($fn, $g->column($col))));
    }

    /** Run $body with cur_column() returning $col. */
    private static function at(string $col, Closure $body): mixed
    {
        $previous = self::$current;
        self::$current = $col;
        try {
            return $body();
        } finally {
            self::$current = $previous;
        }
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
