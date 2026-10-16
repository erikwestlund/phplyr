<?php

declare(strict_types=1);

namespace Phibble;

use Closure;
use Phibble\Contracts\Table;
use Phibble\Exception\ColumnNotFound;

/**
 * A small, row-based stand-in for Phibble\Frame so phplyr's tests run
 * before phibble ships. It is loaded only when the real class is missing.
 */
final class Frame implements Table
{
    /**
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     * @param list<string> $groups
     */
    private function __construct(
        private readonly array $columns,
        private readonly array $rows,
        private readonly array $groups = [],
    ) {
    }

    /** @param list<array<string, mixed>> $rows */
    public static function fromRows(array $rows): self
    {
        $columns = $rows === [] ? [] : array_keys($rows[0]);

        return new self($columns, array_map(
            fn (array $row) => array_combine($columns, array_map(fn ($c) => $row[$c] ?? null, $columns)),
            array_values($rows),
        ));
    }

    /** @param array<string, list<mixed>> $columns */
    public static function fromColumns(array $columns): self
    {
        $names = array_keys($columns);
        $n = $names === [] ? 0 : \count($columns[$names[0]]);
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $row = [];
            foreach ($names as $name) {
                $row[$name] = $columns[$name][$i];
            }
            $rows[] = $row;
        }

        return new self($names, $rows);
    }

    public static function fromCsv(string $path): self
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '');
        $raw = [];
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $raw[] = array_combine($header, $line);
        }
        fclose($handle);

        $parsers = [];
        foreach ($header as $col) {
            $values = array_column($raw, $col);
            $nonEmpty = array_filter($values, fn ($v) => $v !== '' && $v !== 'NA');
            $isInt = $nonEmpty !== [] && array_all($nonEmpty, fn ($v) => preg_match('/^-?\d+$/', $v) === 1);
            $isNum = $nonEmpty !== [] && array_all($nonEmpty, fn ($v) => is_numeric($v));
            $parsers[$col] = match (true) {
                $isInt => fn ($v) => (int) $v,
                $isNum => fn ($v) => (float) $v,
                default => fn ($v) => $v,
            };
        }

        $rows = array_map(function (array $row) use ($parsers) {
            foreach ($row as $col => $v) {
                $row[$col] = ($v === '' || $v === 'NA') ? null : $parsers[$col]($v);
            }

            return $row;
        }, $raw);

        return new self($header, $rows);
    }

    public function columns(): array
    {
        return $this->columns;
    }

    public function types(): array
    {
        $types = [];
        foreach ($this->columns as $col) {
            $values = array_filter($this->column($col), fn ($v) => $v !== null);
            $types[$col] = match (true) {
                $values === [] => Type::String,
                array_all($values, 'is_bool') => Type::Bool,
                array_all($values, 'is_int') => Type::Int,
                array_all($values, fn ($v) => is_int($v) || is_float($v)) => Type::Float,
                default => Type::String,
            };
        }

        return $types;
    }

    public function nrow(): int
    {
        return \count($this->rows);
    }

    public function ncol(): int
    {
        return \count($this->columns);
    }

    public function count(): int
    {
        return $this->nrow();
    }

    public function getIterator(): \Generator
    {
        foreach ($this->rows as $row) {
            yield new Row($row);
        }
    }

    public function column(string $name): array
    {
        $this->assertColumns([$name]);

        return array_column($this->rows, $name);
    }

    public function select(array $names): static
    {
        $this->assertColumns($names);
        $rows = array_map(fn ($row) => array_combine($names, array_map(fn ($c) => $row[$c], $names)), $this->rows);

        return new self(array_values($names), $rows, array_values(array_intersect($this->groups, $names)));
    }

    public function filter(Closure $predicate): static
    {
        $keep = [];
        foreach ($this->groupIndexes() as $indexes) {
            $group = $this->subset($indexes);
            foreach ($indexes as $i) {
                if ($predicate(new Row($this->rows[$i]), $group) === true) {
                    $keep[$i] = true;
                }
            }
        }
        ksort($keep);

        return new self($this->columns, array_values(array_intersect_key($this->rows, $keep)), $this->groups);
    }

    public function mutate(array $columns): static
    {
        $frame = $this;
        foreach ($columns as $name => $def) {
            $rows = $frame->rows;
            if ($def instanceof Closure) {
                foreach ($frame->groupIndexes() as $indexes) {
                    $group = $frame->subset($indexes);
                    foreach ($indexes as $i) {
                        $rows[$i][$name] = $def(new Row($frame->rows[$i]), $group);
                    }
                }
            } else {
                foreach (array_values($def) as $i => $value) {
                    $rows[$i][$name] = $value;
                }
            }
            $cols = \in_array($name, $frame->columns, true) ? $frame->columns : [...$frame->columns, $name];
            $frame = new self($cols, $rows, $frame->groups);
        }

        return $frame;
    }

    public function rename(array $map): static
    {
        $this->assertColumns(array_values($map));
        $byOld = array_flip($map);
        $rename = fn (string $c) => $byOld[$c] ?? $c;
        $columns = array_map($rename, $this->columns);
        $rows = array_map(fn ($row) => array_combine($columns, array_values($row)), $this->rows);

        return new self($columns, $rows, array_map($rename, $this->groups));
    }

    public function arrange(array $keys): static
    {
        $keys = array_map(fn ($k) => \is_array($k) ? [$k[0], ($k[1] ?? 'asc') === 'desc'] : [$k, false], $keys);
        $this->assertColumns(array_column($keys, 0));
        $rows = $this->rows;
        usort($rows, function ($a, $b) use ($keys) {
            foreach ($keys as [$col, $desc]) {
                $x = $a[$col];
                $y = $b[$col];
                if ($x === null || $y === null) {
                    $cmp = ($x === null) <=> ($y === null);
                } else {
                    $cmp = $desc ? $y <=> $x : $x <=> $y;
                }
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return 0;
        });

        return new self($this->columns, $rows, $this->groups);
    }

    public function groupBy(array $names): static
    {
        $this->assertColumns($names);

        return new self($this->columns, $this->rows, array_values($names));
    }

    public function ungroup(): static
    {
        return new self($this->columns, $this->rows);
    }

    public function groups(): array
    {
        return $this->groups;
    }

    public function eachGroup(): iterable
    {
        foreach ($this->groupIndexes() as $indexes) {
            $first = $this->rows[$indexes[0]] ?? [];
            $key = [];
            foreach ($this->groups as $g) {
                $key[$g] = $first[$g];
            }
            yield ['key' => $key, 'table' => $this->subset($indexes)];
        }
    }

    public function summarise(array $aggregates): static
    {
        $rows = [];
        foreach ($this->eachGroup() as ['key' => $key, 'table' => $table]) {
            $row = $key;
            foreach ($aggregates as $name => $fn) {
                $row[$name] = $fn($table);
            }
            $rows[] = $row;
        }
        $columns = [...$this->groups, ...array_keys($aggregates)];

        return new self($columns, $rows, \array_slice($this->groups, 0, -1));
    }

    public function distinct(array $names = []): static
    {
        $frame = $names === [] ? $this : $this->select($names);
        $seen = [];
        $rows = [];
        foreach ($frame->rows as $row) {
            $k = serialize($row);
            if (! isset($seen[$k])) {
                $seen[$k] = true;
                $rows[] = $row;
            }
        }

        return new self($frame->columns, $rows, $frame->groups);
    }

    public function slice(int $offset, ?int $length = null): static
    {
        return new self($this->columns, \array_slice($this->rows, $offset, $length), $this->groups);
    }

    public function head(int $n = 6): static
    {
        return $this->slice(0, $n);
    }

    public function toRows(): array
    {
        return $this->rows;
    }

    public function toColumns(): array
    {
        $out = [];
        foreach ($this->columns as $col) {
            $out[$col] = $this->column($col);
        }

        return $out;
    }

    public function collect(): Frame
    {
        return $this;
    }

    /** @return list<list<int>> Row indexes per group, in order of first appearance. */
    private function groupIndexes(): array
    {
        if ($this->groups === []) {
            return [array_keys($this->rows)];
        }
        $out = [];
        foreach ($this->rows as $i => $row) {
            $k = serialize(array_map(fn ($g) => $row[$g], $this->groups));
            $out[$k][] = $i;
        }

        return array_values($out);
    }

    /** @param list<int> $indexes */
    private function subset(array $indexes): self
    {
        return new self($this->columns, array_map(fn ($i) => $this->rows[$i], $indexes));
    }

    /** @param list<string> $names */
    private function assertColumns(array $names): void
    {
        foreach ($names as $name) {
            if (! \in_array($name, $this->columns, true)) {
                throw new ColumnNotFound("Unknown column: {$name}");
            }
        }
    }
}
