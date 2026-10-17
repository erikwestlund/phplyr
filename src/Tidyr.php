<?php

declare(strict_types=1);

namespace Phplyr;

use Closure;
use Phibble\Contracts\Table;
use Phibble\DataFrame;
use Phibble\Type;

/** @internal The tidyr verbs' implementations, built on the Table contract. */
final class Tidyr
{
    private const string PRESENT = '.phplyr_present';

    private const string ROW = '.phplyr_row';

    // -----------------------------------------------------------------
    // Pivoting
    // -----------------------------------------------------------------

    /**
     * pivot_longer(): build tidyr's spec, run it on the table, then apply
     * values_transform and cols_vary.
     *
     * @param string|list<?string> $namesTo
     */
    public static function pivotLonger(Table $t, mixed $cols, string|array $namesTo, string $valuesTo, ?string $namesPrefix, ?string $namesSep, ?string $namesPattern, bool $valuesDropNa, Closure|array|null $namesTransform = null, Closure|array|null $valuesTransform = null, string $colsVary = 'fastest'): Table
    {
        $spec = self::longerSpec($t, $cols, $namesTo, $valuesTo, $namesPrefix, $namesSep, $namesPattern, $namesTransform);

        return self::runLongerSpec($t, $spec, $valuesDropNa, $valuesTransform, $colsVary);
    }

    /**
     * Run a longer spec (rows with .name, .value and the names_to columns).
     *
     * @param list<array<string, mixed>> $spec
     */
    public static function runLongerSpec(Table $t, array $spec, bool $valuesDropNa, Closure|array|null $valuesTransform = null, string $colsVary = 'fastest'): Table
    {
        if (! \in_array($colsVary, ['fastest', 'slowest'], true)) {
            throw new \InvalidArgumentException("pivot_longer(): cols_vary must be 'fastest' or 'slowest'.");
        }
        $nameCols = array_values(array_diff(array_keys($spec[0] ?? []), ['.name', '.value']));
        $slowest = $colsVary === 'slowest' && $t->nrow() > 1;
        if ($slowest) {
            $t = $t->mutate([self::ROW => range(0, $t->nrow() - 1)]);
        }
        $out = $t->pivotLongerSpec($spec, $valuesDropNa);
        if ($slowest) {
            // Order by spec position (the names_to combination), then input row.
            $position = [];
            foreach ($spec as $row) {
                $position[self::partsKey(array_map(fn ($c) => $row[$c] ?? null, $nameCols))] ??= \count($position);
            }
            $nameVectors = array_map(fn ($c) => $out->column($c), $nameCols);
            $rows = $out->column(self::ROW);
            $keys = [];
            foreach ($rows as $i => $r) {
                $keys[$i] = [$position[self::partsKey(array_map(fn ($v) => $v[$i], $nameVectors))] ?? 0, $r];
            }
            $order = array_keys($keys);
            usort($order, fn ($a, $b) => $keys[$a] <=> $keys[$b]);
            $out = Ops::take($out, $order);
            $out = $out->select(array_values(array_diff($out->columns(), [self::ROW])));
        }
        if ($valuesTransform !== null) {
            $values = array_values(array_unique(array_column($spec, '.value')));
            $defs = [];
            foreach ($values as $v) {
                $fn = $valuesTransform instanceof Closure ? $valuesTransform : ($valuesTransform[$v] ?? null);
                if ($fn !== null) {
                    $defs[$v] = array_map($fn, $out->column($v));
                }
            }
            $out = $defs === [] ? $out : $out->mutate($defs);
        }

        return $out;
    }

    private static function partsKey(array $parts): string
    {
        return implode("\x1f", array_map(Internal::key(...), $parts));
    }

    /**
     * The spec for pivot_longer() / build_longer_spec().
     *
     * @param string|list<?string> $namesTo
     * @return list<array<string, mixed>>
     */
    public static function longerSpec(Table $t, mixed $cols, string|array $namesTo, string $valuesTo, ?string $namesPrefix, ?string $namesSep, ?string $namesPattern, Closure|array|null $namesTransform = null): array
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
            $parts = $g['parts'];
            foreach ($parts as $to => $v) {
                $fn = $namesTransform instanceof Closure ? $namesTransform : ($namesTransform[$to] ?? null);
                if ($fn !== null) {
                    $parts[$to] = $fn($v);
                }
            }
            foreach ($g['cols'] as $valueName => $col) {
                $spec[] = ['.name' => $col, '.value' => $valueName] + $parts;
            }
        }

        return $spec;
    }
    /**
     * pivot_wider(). The table runs tidyr's spec; values_fn, id_expand and
     * unused_fn are applied here, in PHP, keeping tidyr's first-appearance
     * row order.
     */
    public static function pivotWider(Table $t, mixed $idCols, mixed $namesFrom, mixed $valuesFrom, string $namesPrefix, string $namesSep, ?string $namesGlue, bool $namesSort, mixed $valuesFill, Closure|array|null $valuesFn, string $namesVary = 'fastest', bool $namesExpand = false, bool $idExpand = false, Closure|array|null $unusedFn = null): Table
    {
        $namesFrom = Selector::select([$namesFrom], $t);
        $valuesFrom = Selector::select([$valuesFrom], $t);
        $idCols = $idCols === null
            ? array_values(array_diff($t->columns(), $namesFrom, $valuesFrom))
            : Selector::select([$idCols], $t);
        $spec = self::widerSpec($t, $namesFrom, $valuesFrom, $namesPrefix, $namesSep, $namesGlue, $namesSort, $namesVary, $namesExpand);
        $fill = \is_array($valuesFill)
            ? $valuesFill
            : ($valuesFill === null ? [] : array_fill_keys($valuesFrom, $valuesFill));

        $out = $valuesFn === null
            ? $t->pivotWiderSpec($spec, $idCols, $fill)
            : self::widerWithFn($t, $spec, $idCols, $namesFrom, $fill, $valuesFn);

        if ($idExpand) {
            $out = self::expandIds($t, $out, $idCols, $spec, $fill);
        }
        $unused = array_values(array_diff($t->columns(), $idCols, $namesFrom, $valuesFrom));
        if ($unusedFn !== null && $unused !== []) {
            $out = self::unused($t, $out, $idCols, $unused, $unusedFn);
        }

        return Internal::sameBackend($out, $t);
    }

    /**
     * The spec for pivot_wider() / build_wider_spec(): one row per output
     * column, with .name, .value and the names_from values.
     *
     * @param list<string> $namesFrom
     * @param list<string> $valuesFrom
     * @return list<array<string, mixed>>
     */
    public static function widerSpec(Table $t, array $namesFrom, array $valuesFrom, string $namesPrefix, string $namesSep, ?string $namesGlue, bool $namesSort, string $namesVary, bool $namesExpand): array
    {
        if (! \in_array($namesVary, ['fastest', 'slowest'], true)) {
            throw new \InvalidArgumentException("pivot_wider(): names_vary must be 'fastest' or 'slowest'.");
        }
        $keys = $namesExpand
            ? self::expansion($t, $namesFrom)
            : $t->ungroup()->select($namesFrom)->distinct()->toRows();
        if ($namesSort && ! $namesExpand) {
            usort($keys, self::nameOrder($t, $namesFrom));
        }
        $entry = fn (string $vc, array $key) => ['.name' => self::wideName($vc, array_values($key), $key, $namesFrom, $valuesFrom, $namesPrefix, $namesSep, $namesGlue), '.value' => $vc] + $key;
        $spec = [];
        if ($namesVary === 'fastest') {
            foreach ($valuesFrom as $vc) {
                foreach ($keys as $key) {
                    $spec[] = $entry($vc, $key);
                }
            }
        } else {
            foreach ($keys as $key) {
                foreach ($valuesFrom as $vc) {
                    $spec[] = $entry($vc, $key);
                }
            }
        }

        return $spec;
    }

    /**
     * Every combination of the columns' values (sorted, factors by their
     * levels, NA last), first column varying slowest.
     *
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    private static function expansion(Table $t, array $columns): array
    {
        $rows = [[]];
        foreach ($columns as $c) {
            $values = ($t->types()[$c] ?? null) === Type::Factor
                ? $t->collect()->levels($c)
                : self::sortedUnique($t->column($c));
            if (\in_array(null, $t->column($c), true) && ! \in_array(null, $values, true)) {
                $values[] = null;
            }
            $next = [];
            foreach ($rows as $row) {
                foreach ($values as $v) {
                    $next[] = $row + [$c => $v];
                }
            }
            $rows = $next;
        }

        return $rows;
    }

    /**
     * pivot_wider() with values_fn: cells are gathered and summarised in PHP.
     *
     * @param list<array<string, mixed>> $spec
     * @param list<string> $idCols
     * @param list<string> $namesFrom
     */
    private static function widerWithFn(Table $t, array $spec, array $idCols, array $namesFrom, array $fill, Closure|array $valuesFn): Table
    {
        $valuesFrom = array_values(array_unique(array_column($spec, '.value')));
        $idVectors = array_map(fn ($c) => $t->column($c), $idCols);
        $nameVectors = array_map(fn ($c) => $t->column($c), $namesFrom);
        $valueVectors = array_combine($valuesFrom, array_map(fn ($c) => $t->column($c), $valuesFrom));

        $rows = [];
        $first = [];
        $cells = [];
        for ($i = 0, $n = $t->nrow(); $i < $n; $i++) {
            $rk = implode("\x1f", array_map(fn ($v) => Internal::key($v[$i]), $idVectors));
            if (! isset($rows[$rk])) {
                $rows[$rk] = \count($first);
                $first[] = $i;
            }
            $ck = self::partsKey(array_map(fn ($v) => $v[$i], $nameVectors));
            foreach ($valuesFrom as $vc) {
                $cells[$vc][$rows[$rk]][$ck][] = $valueVectors[$vc][$i];
            }
        }

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
        $out = [];
        foreach ($spec as $entry) {
            $vc = $entry['.value'];
            $fn = \is_array($valuesFn) ? ($valuesFn[$vc] ?? null) : $valuesFn;
            $ck = self::partsKey(array_map(fn ($c) => $entry[$c], $namesFrom));
            $values = [];
            for ($r = 0; $r < $nRows; $r++) {
                $cell = $cells[$vc][$r][$ck] ?? null;
                $values[] = match (true) {
                    $cell === null => $listCols[$vc] ? null : ($fill[$vc] ?? null),
                    $fn !== null => $fn($cell),
                    $listCols[$vc] => $cell,
                    default => $cell[0],
                };
            }
            $out[$entry['.name']] = $values;
        }

        return Ops::take($t->select($idCols), $first)->mutate($out);
    }

    /** id_expand: a row for every combination of the id columns, sorted. */
    private static function expandIds(Table $t, Table $out, array $idCols, array $spec, array $fill): Table
    {
        $ids = DataFrame::fromColumns(array_map(fn ($c) => [], array_combine($idCols, $idCols)));
        $combos = self::expansion($t, $idCols);
        if ($combos !== [] && $idCols !== []) {
            $ids = DataFrame::fromRows($combos);
        }
        foreach ($idCols as $c) {
            if (($t->types()[$c] ?? null) === Type::Factor) {
                $ids = $ids->asFactor($c, $t->collect()->levels($c));
            }
        }
        $joined = $ids->join($out->ungroup()->mutate([self::PRESENT => true]), array_map(fn ($c) => [$c, $c], $idCols), 'left');
        $present = $joined->column(self::PRESENT);
        $defs = [];
        foreach ($spec as $entry) {
            if (\array_key_exists($entry['.value'], $fill)) {
                $f = $fill[$entry['.value']];
                $defs[$entry['.name']] = array_map(fn ($v, $p) => $p === null && $v === null ? $f : $v, $joined->column($entry['.name']), $present);
            }
        }
        $joined = $defs === [] ? $joined : $joined->mutate($defs);

        return $joined->select(array_values(array_diff($joined->columns(), [self::PRESENT])));
    }

    /** unused_fn: summarise the columns pivot_wider() would drop, per output row. */
    private static function unused(Table $t, Table $out, array $idCols, array $unused, Closure|array $unusedFn): Table
    {
        $key = fn (Table $x, int $i, array $vectors) => implode("\x1f", array_map(fn ($v) => Internal::key($v[$i]), $vectors));
        $inVectors = array_map(fn ($c) => $t->column($c), $idCols);
        $outVectors = array_map(fn ($c) => $out->column($c), $idCols);
        $defs = [];
        foreach ($unused as $c) {
            $fn = $unusedFn instanceof Closure ? $unusedFn : ($unusedFn[$c] ?? null);
            if ($fn === null) {
                continue;
            }
            $groups = [];
            foreach ($t->column($c) as $i => $v) {
                $groups[$key($t, $i, $inVectors)][] = $v;
            }
            $defs[$c] = array_map(fn ($i) => isset($groups[$k = $key($out, $i, $outVectors)]) ? $fn($groups[$k]) : null, $out->nrow() === 0 ? [] : range(0, $out->nrow() - 1));
        }

        return $defs === [] ? $out : $out->mutate($defs);
    }

    /**
     * names_sort's order for names_from combinations (positional lists or
     * keyed by column): values ascending, factors by level, NA last.
     */
    private static function nameOrder(Table $t, array $namesFrom): Closure
    {
        $levels = [];
        foreach ($namesFrom as $col) {
            if (($t->types()[$col] ?? null) === Type::Factor) {
                $levels[$col] = array_flip($t->collect()->levels($col));
            }
        }

        return function (array $a, array $b) use ($namesFrom, $levels) {
            $a = array_values($a);
            $b = array_values($b);
            foreach ($namesFrom as $j => $col) {
                [$x, $y] = [$a[$j], $b[$j]];
                if (isset($levels[$col]) && $x !== null && $y !== null) {
                    [$x, $y] = [$levels[$col][$x] ?? -1, $levels[$col][$y] ?? -1];
                }
                $c = $x === null || $y === null ? ($x === null) <=> ($y === null) : Window::compare($x, $y);
                if ($c !== 0) {
                    return $c;
                }
            }

            return 0;
        };
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
            $parts[] = DataFrame::bindCols([$keys, $exp]);
        }
        if ($parts === []) {
            // No groups: the key columns and the expanded columns, with no rows.
            $exp = self::expandOne($t->ungroup(), $items);

            return DataFrame::bindCols([Ops::take($t->ungroup()->select($groups), []), Ops::take($exp, [])])->groupBy($groups);
        }

        return DataFrame::concat($parts)->groupBy($groups);
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
        if ($parts === []) {
            return $t;
        }
        $out = \count($parts) === 1 ? $parts[0] : DataFrame::concat($parts);

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
        $names = [];
        foreach ($items as $key => $item) {
            if ($item instanceof Nesting) {
                $cols = Selector::select($item->columns, $t);
                $names = [...$names, ...$cols];
                $sets[] = self::uniqueRows($t, $cols);
            } elseif (\is_string($key)) {
                $names[] = $key;
                $sets[] = array_map(fn ($v) => [$key => $v], self::sortedUnique(array_values((array) $item)));
            } else {
                // A selection of several columns crosses them one by one.
                foreach (Selector::select([$item], $t) as $c) {
                    $names[] = $c;
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

        return DataFrame::fromColumns($columns, $types);
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
            \is_float($v) => self::double($v),
            $v instanceof \DateTimeInterface => $v->format('Y-m-d'),
            default => (string) $v,
        };
    }

    /**
     * A double as R's as.character() writes it: 15 significant digits, in
     * fixed notation unless scientific notation is shorter.
     */
    public static function double(float $v): string
    {
        if (is_nan($v)) {
            return 'NaN';
        }
        if (is_infinite($v)) {
            return $v > 0 ? 'Inf' : '-Inf';
        }
        if ($v == 0.0) {
            return '0';
        }
        [$mantissa, $exp] = explode('e', sprintf('%.14e', $v));
        $exp = (int) $exp;
        $digits = rtrim(\str_replace(['-', '.'], '', $mantissa), '0');
        $sign = $v < 0 ? '-' : '';
        $n = \strlen($digits);
        $sci = $sign.$digits[0].($n > 1 ? '.'.substr($digits, 1) : '')
            .'e'.($exp < 0 ? '-' : '+').\str_pad((string) \abs($exp), 2, '0', STR_PAD_LEFT);
        if ($exp >= 0) {
            $int = $n > $exp + 1 ? substr($digits, 0, $exp + 1) : \str_pad($digits, $exp + 1, '0');
            $frac = $n > $exp + 1 ? substr($digits, $exp + 1) : '';
        } else {
            $int = '0';
            $frac = str_repeat('0', -$exp - 1).$digits;
        }
        $fixed = $sign.$int.($frac === '' ? '' : '.'.$frac);

        return \strlen($fixed) <= \strlen($sci) ? $fixed : $sci;
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
        // Extra pieces are dropped, as in tidyr.
        $pieces = \array_slice(preg_split(self::regex($sep), $name), 0, $n);

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
