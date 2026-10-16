<?php

declare(strict_types=1);

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;
use Phibble\Frame;
use Phibble\Type;

/** @internal The tidyr verbs' implementations, built on the Table contract. */
final class Tidyr
{
    private const string PRESENT = '.phplyr_present';

    // -----------------------------------------------------------------
    // Pivoting
    // -----------------------------------------------------------------

    /** @param string|list<?string> $namesTo */
    public static function pivotLonger(Table $t, mixed $cols, string|array $namesTo, string $valuesTo, ?string $namesPrefix, ?string $namesSep, ?string $namesPattern, bool $valuesDropNa): Table
    {
        $cols = Selector::select([$cols], $t);
        if ($cols === []) {
            throw new \InvalidArgumentException('pivot_longer(): cols selected no columns.');
        }
        $ids = array_values(array_diff($t->columns(), $cols));
        $namesTo = (array) $namesTo;
        if (\count($namesTo) > 1 && $namesSep === null && $namesPattern === null) {
            throw new \InvalidArgumentException('pivot_longer(): several names_to need names_sep or names_pattern.');
        }

        // Parse each source column name into name parts and a value column.
        $groups = [];
        foreach ($cols as $col) {
            $name = $namesPrefix === null ? $col : (string) preg_replace(self::regex('^(?:'.$namesPrefix.')'), '', $col);
            $pieces = match (true) {
                $namesPattern !== null => self::patternPieces($name, $namesPattern, \count($namesTo)),
                $namesSep !== null => self::sepPieces($name, $namesSep, \count($namesTo)),
                default => [$name],
            };
            $parts = [];
            $valueName = $valuesTo;
            foreach ($namesTo as $j => $to) {
                if ($to === '.value') {
                    $valueName = (string) $pieces[$j];
                } elseif ($to !== null) {
                    $parts[$to] = $pieces[$j] ?? null;
                }
            }
            $key = serialize($parts);
            $groups[$key] ??= ['parts' => $parts, 'cols' => []];
            $groups[$key]['cols'][$valueName] = $col;
        }
        $groups = array_values($groups);

        // The table runs tidyr's pivot_longer_spec().
        $spec = [];
        foreach ($groups as $g) {
            foreach ($g['cols'] as $valueName => $col) {
                $spec[] = ['.name' => $col, '.value' => $valueName] + $g['parts'];
            }
        }

        return $t->pivotLongerSpec($spec, $valuesDropNa);
    }
    /**
     * @param list<string> $namesFrom
     * @param list<string> $valuesFrom
     */
    public static function pivotWider(Table $t, mixed $idCols, mixed $namesFrom, mixed $valuesFrom, string $namesPrefix, string $namesSep, ?string $namesGlue, bool $namesSort, mixed $valuesFill, Closure|array|null $valuesFn): Table
    {
        $namesFrom = Selector::select([$namesFrom], $t);
        $valuesFrom = Selector::select([$valuesFrom], $t);
        $idCols = $idCols === null
            ? array_values(array_diff($t->columns(), $namesFrom, $valuesFrom))
            : Selector::select([$idCols], $t);

        // The table runs tidyr's pivot_wider_spec(). values_fn is applied here,
        // in PHP, keeping tidyr's first-appearance row order.
        if ($valuesFn === null) {
            $keys = $t->ungroup()->select($namesFrom)->distinct()->toRows();
            if ($namesSort) {
                usort($keys, function (array $a, array $b) {
                    foreach ($a as $col => $x) {
                        $y = $b[$col];
                        $c = $x === null || $y === null ? ($x === null) <=> ($y === null) : Window::compare($x, $y);
                        if ($c !== 0) {
                            return $c;
                        }
                    }

                    return 0;
                });
            }
            $spec = [];
            foreach ($valuesFrom as $vc) {
                foreach ($keys as $key) {
                    $parts = array_values($key);
                    $spec[] = ['.name' => self::wideName($vc, $parts, $key, $namesFrom, $valuesFrom, $namesPrefix, $namesSep, $namesGlue), '.value' => $vc] + $key;
                }
            }
            $fill = \is_array($valuesFill)
                ? $valuesFill
                : ($valuesFill === null ? [] : array_fill_keys($valuesFrom, $valuesFill));

            return $t->pivotWiderSpec($spec, $idCols, $fill);
        }

        $idVectors = array_map(fn ($c) => $t->column($c), $idCols);
        $nameVectors = array_map(fn ($c) => $t->column($c), $namesFrom);
        $valueVectors = array_combine($valuesFrom, array_map(fn ($c) => $t->column($c), $valuesFrom));

        $rows = [];
        $first = [];
        $colKeys = [];
        $cells = [];
        for ($i = 0, $n = $t->nrow(); $i < $n; $i++) {
            $rk = implode("\x1f", array_map(fn ($v) => Internal::key($v[$i]), $idVectors));
            if (! isset($rows[$rk])) {
                $rows[$rk] = \count($first);
                $first[] = $i;
            }
            $parts = array_map(fn ($v) => $v[$i], $nameVectors);
            $ck = implode("\x1f", array_map(fn ($v) => Internal::key($v), $parts));
            $colKeys[$ck] ??= $parts;
            foreach ($valuesFrom as $vc) {
                $cells[$vc][$rows[$rk]][$ck][] = $valueVectors[$vc][$i];
            }
        }
        if ($namesSort) {
            uasort($colKeys, function (array $a, array $b) {
                foreach ($a as $j => $x) {
                    $c = $x === null || $b[$j] === null ? ($x === null) <=> ($b[$j] === null) : Window::compare($x, $b[$j]);
                    if ($c !== 0) {
                        return $c;
                    }
                }

                return 0;
            });
        }

        $out = [];
        $nRows = \count($first);
        // Like tidyr, duplicate cells (without values_fn) turn that value column
        // into list-columns, with tidyr's warning.
        $listCols = [];
        foreach ($valuesFrom as $vc) {
            $fn = \is_array($valuesFn) ? ($valuesFn[$vc] ?? null) : $valuesFn;
            $listCols[$vc] = $fn === null && array_any($cells[$vc] ?? [], fn ($row) => array_any($row, fn ($cell) => \count($cell) > 1));
            if ($listCols[$vc]) {
                trigger_error("Values from `{$vc}` are not uniquely identified; output will contain list-cols.", E_USER_WARNING);
            }
        }
        foreach ($valuesFrom as $vc) {
            $fn = \is_array($valuesFn) ? ($valuesFn[$vc] ?? null) : $valuesFn;
            $fill = \is_array($valuesFill) ? ($valuesFill[$vc] ?? null) : $valuesFill;
            foreach ($colKeys as $ck => $parts) {
                $name = self::wideName($vc, $parts, array_combine($namesFrom, $parts), $namesFrom, $valuesFrom, $namesPrefix, $namesSep, $namesGlue);

                $values = [];
                for ($r = 0; $r < $nRows; $r++) {
                    $cell = $cells[$vc][$r][$ck] ?? null;
                    $values[] = match (true) {
                        $cell === null => $listCols[$vc] ? null : $fill,
                        $fn !== null => $fn($cell),
                        $listCols[$vc] => $cell,
                        default => $cell[0],
                    };
                }
                $out[$name] = $values;
            }
        }

        return Ops::take($t->select($idCols), $first)->mutate($out);
    }

    /** A pivot_wider() column name, as tidyr builds it. */
    private static function wideName(string $vc, array $parts, array $key, array $namesFrom, array $valuesFrom, string $namesPrefix, string $namesSep, ?string $namesGlue): string
    {
        $label = implode($namesSep, array_map(fn ($p) => self::str($p), $parts));

        return match (true) {
            $namesGlue !== null => self::glue($namesGlue, $key + ['.value' => $vc]),
            \count($valuesFrom) > 1 => $vc.$namesSep.$namesPrefix.$label,
            default => $namesPrefix.$label,
        };
    }

    // -----------------------------------------------------------------
    // Completing and expanding
    // -----------------------------------------------------------------

    /**
     * The expansion of the items, per group. Items are column names,
     * nesting(...) markers, or name => list of values.
     *
     * @param array<int|string, mixed> $items
     */
    public static function expand(Table $t, array $items): Table
    {
        $groups = $t->groups();
        if ($groups === []) {
            return self::expandOne($t, $items);
        }
        $parts = [];
        foreach (Ops::groupRows($t) as ['table' => $g]) {
            $exp = self::expandOne($g, $items);
            $keys = Ops::take($g->select($groups), array_fill(0, $exp->nrow(), 0));
            $parts[] = Frame::bindCols([$keys, $exp]);
        }

        return Frame::concat($parts)->groupBy($groups);
    }

    /**
     * @param array<int|string, mixed> $items
     * @param array<string, mixed> $fill
     */
    public static function complete(Table $t, array $items, array $fill, bool $explicit): Table
    {
        $groups = $t->groups();
        $parts = [];
        foreach ($groups === [] ? [['table' => $t]] : Ops::groupRows($t) as ['table' => $g]) {
            $exp = self::expandOne($g, $items);
            $keys = $exp->columns();
            $data = $g->mutate([self::PRESENT => true]);
            $joined = $exp->join($data, array_map(fn ($c) => [$c, $c], $keys), 'full');
            if ($groups !== []) {
                $joined = $joined->mutate(array_map(fn ($c) => fn () => $g->column($c)[0], array_combine($groups, $groups)));
            }
            $parts[] = $joined;
        }
        $out = \count($parts) === 1 ? $parts[0] : Frame::concat($parts);

        $present = $out->column(self::PRESENT);
        $replace = [];
        foreach ($fill as $col => $value) {
            $replace[$col] = array_map(
                fn ($v, $p) => $v === null && ($explicit || $p === null) ? $value : $v,
                $out->column($col),
                $present,
            );
        }

        return $out->mutate($replace)->select($t->columns())->groupBy($groups);
    }

    /** @param array<int|string, mixed> $items */
    private static function expandOne(Table $t, array $items): Table
    {
        $sets = [];
        foreach ($items as $key => $item) {
            if ($item instanceof Nesting) {
                $sets[] = self::uniqueRows($t, Selector::select($item->columns, $t));
            } elseif (\is_string($key)) {
                $sets[] = array_map(fn ($v) => [$key => $v], self::sortedUnique(array_values((array) $item)));
            } else {
                // A selection of several columns crosses them one by one.
                foreach (Selector::select([$item], $t) as $c) {
                    $sets[] = array_map(fn ($v) => [$c => $v], self::levelsOrValues($t, $c));
                }
            }
        }
        $rows = [[]];
        foreach ($sets as $set) {
            $next = [];
            foreach ($rows as $row) {
                foreach ($set as $tuple) {
                    $next[] = $row + $tuple;
                }
            }
            $rows = $next;
        }
        $names = array_keys($rows[0] ?? []);
        $columns = [];
        foreach ($names as $name) {
            $columns[$name] = array_column($rows, $name);
        }
        $types = [];
        foreach ($names as $name) {
            if (\in_array($name, $t->columns(), true) && $t->types()[$name] !== Type::Factor) {
                $types[$name] = $t->types()[$name];
            }
        }

        return Frame::fromColumns($columns, $types);
    }

    /** Unique combinations of columns, sorted. @return list<array<string, mixed>> */
    private static function uniqueRows(Table $t, array $cols): array
    {
        $rows = $t->select($cols)->distinct()->arrange($cols)->toRows();

        return array_map(fn ($r) => array_intersect_key($r, array_flip($cols)), $rows);
    }

    /** Sorted unique values, NA last; a factor's levels in level order. */
    private static function levelsOrValues(Table $t, string $col): array
    {
        $frame = $t->collect();
        if ($frame->types()[$col] === Type::Factor) {
            $levels = $frame->levels($col);
            $hasNa = \in_array(null, $frame->column($col), true);

            return $hasNa ? [...$levels, null] : $levels;
        }

        return self::sortedUnique($t->column($col));
    }

    /** @param list<mixed> $values */
    public static function sortedUnique(array $values): array
    {
        $unique = [];
        foreach ($values as $v) {
            $unique[Internal::key($v)] = $v;
        }
        $unique = array_values($unique);
        $order = Window::order($unique);
        $out = array_map(fn ($i) => $unique[$i], $order);
        if (\in_array(null, $unique, true)) {
            $out[] = null;
        }

        return $out;
    }

    // -----------------------------------------------------------------
    // Separating and uniting
    // -----------------------------------------------------------------

    /**
     * Insert new columns where $at was, optionally removing it.
     *
     * @param array<string, list<mixed>> $new
     */
    public static function insertAt(Table $t, string $at, array $new, bool $remove, bool $newFirst = false): Table
    {
        $columns = $t->columns();
        $pos = array_search($at, $columns, true);
        $names = array_keys($new);
        $keep = fn (array $cols) => array_values(array_filter($cols, fn ($c) => ! \in_array($c, $names, true)));
        $order = [
            ...$keep(\array_slice($columns, 0, $pos)),
            ...($newFirst ? $names : []),
            ...($remove || \in_array($at, $names, true) ? [] : [$at]),
            ...($newFirst ? [] : $names),
            ...$keep(\array_slice($columns, $pos + 1)),
        ];
        $out = $t->mutate($new);
        return $out->select($order);
    }

    /** R's type.convert() on a column of strings: logical, integer, double or string. */
    public static function convert(array $values): array
    {
        $present = array_filter($values, fn ($v) => $v !== null);
        $all = fn (Closure $test) => array_all($present, fn ($v) => \is_string($v) && $test($v));
        if ($present === []) {
            return $values;
        }
        if ($all(fn ($v) => $v === 'TRUE' || $v === 'FALSE' || $v === 'T' || $v === 'F')) {
            return array_map(fn ($v) => $v === null ? null : $v[0] === 'T', $values);
        }
        if ($all(fn ($v) => preg_match('/^\s*[-+]?\d+\s*$/', $v) === 1)) {
            return array_map(fn ($v) => $v === null ? null : (int) $v, $values);
        }
        if ($all(fn ($v) => is_numeric(trim($v)))) {
            return array_map(fn ($v) => $v === null ? null : (float) $v, $values);
        }

        return $values;
    }

    /** A value as R's as.character() would print it. */
    public static function str(mixed $v): string
    {
        return match (true) {
            $v === null => 'NA',
            \is_bool($v) => $v ? 'TRUE' : 'FALSE',
            \is_float($v) => fmod($v, 1.0) === 0.0 && \abs($v) < 1e15 ? (string) (int) $v : (string) $v,
            $v instanceof \DateTimeInterface => $v->format('Y-m-d'),
            default => (string) $v,
        };
    }

    /** Wrap a bare pattern in delimiters. */
    public static function regex(string $pattern): string
    {
        return '/'.\str_replace('/', '\/', $pattern).'/u';
    }

    private static function glue(string $template, array $values): string
    {
        return (string) preg_replace_callback('/\{([^}]+)\}/', fn ($m) => self::str($values[$m[1]] ?? ''), $template);
    }

    /** @return list<?string> */
    private static function sepPieces(string $name, string $sep, int $n): array
    {
        $pieces = preg_split(self::regex($sep), $name, $n);

        return array_pad($pieces, $n, null);
    }

    /** @return list<?string> */
    private static function patternPieces(string $name, string $pattern, int $n): array
    {
        if (preg_match(self::regex($pattern), $name, $m) !== 1) {
            return array_fill(0, $n, null);
        }

        return array_pad(\array_slice($m, 1, $n), $n, null);
    }
}
