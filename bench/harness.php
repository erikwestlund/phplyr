<?php

/*
 * phibble's shared benchmark workloads, run through phplyr's verbs, so the
 * numbers line up with phibble's bin/bench. Each workload also runs as the
 * equivalent direct Table method calls ("direct") to show phplyr's own cost.
 *
 * Usage: php bench/harness.php [--backend=frame|sqlite|polars] [--runs=3] [workload ...]
 * Data: php bench/generate.php [rows]
 *
 * Prints one JSON line per workload and form: backend, form, workload, rows,
 * median and min seconds, peak memory in MB.
 */

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Phibble\Contracts\Table;
use Phibble\DataFrame;
use Phibble\Sql\SqlTable;

use function Phplyr\{arrange, col, cumsum, desc, filter, group_by, lag, left_join, mean, mutate, n, row_number, sum, summarise};

ini_set('memory_limit', '-1');

$options = getopt('', ['backend:', 'runs:'], $rest);
$backendName = $options['backend'] ?? 'frame';
$runs = (int) ($options['runs'] ?? 3);
$only = array_slice($argv, $rest);
$data = __DIR__.'/data';
if (! is_file("{$data}/events.csv")) {
    fwrite(STDERR, "No data. Run: php bench/generate.php [rows]\n");
    exit(1);
}

$load = match ($backendName) {
    'frame' => fn (string $path) => DataFrame::fromCsv($path),
    'sqlite' => (function () {
        $pdo = new PDO('sqlite::memory:');

        return function (string $path) use ($pdo) {
            $frame = DataFrame::fromCsv($path);
            $table = basename($path, '.csv');
            $columns = $frame->columns();
            // Typed columns: PDO binds strings, so untyped columns would store TEXT
            // and SQLite would compare numbers as text.
            $sqlType = fn (Phibble\Type $t) => match ($t) {
                Phibble\Type::Int, Phibble\Type::Bool => 'INTEGER',
                Phibble\Type::Float => 'REAL',
                default => 'TEXT',
            };
            $types = $frame->types();
            $pdo->exec("CREATE TABLE \"{$table}\" (".implode(', ', array_map(fn ($c) => "\"{$c}\" ".$sqlType($types[$c]), $columns)).')');
            $insert = $pdo->prepare("INSERT INTO \"{$table}\" VALUES (".implode(', ', array_fill(0, \count($columns), '?')).')');
            $pdo->beginTransaction();
            foreach ($frame->toRows() as $row) {
                $insert->execute(array_map(fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, array_values($row)));
            }
            $pdo->commit();
            $pdo->exec("CREATE INDEX \"{$table}_key\" ON \"{$table}\" (\"key\")");

            return SqlTable::table($pdo, $table);
        };
    })(),
    'polars' => fn (string $path) => Phibble\Polars\PolarsTable::fromTable(DataFrame::fromCsv($path)),
    default => throw new InvalidArgumentException("Unknown backend {$backendName}"),
};

$workloads = [
    'group_summarise' => [
        'phplyr' => fn (Table $ev) => $ev
            |> group_by('key')
            |> summarise(n: n(), mean_x: mean('x', na_rm: true), sum_x: sum('x', na_rm: true)),
        'direct' => fn (Table $ev) => $ev
            ->groupBy(['key'])
            ->summarise(['n' => Phibble\Expr\n(), 'mean_x' => Phibble\Expr\mean(Phibble\Expr\col('x'), na_rm: true), 'sum_x' => Phibble\Expr\sum(Phibble\Expr\col('x'), na_rm: true)]),
    ],
    'group_summarise_closure' => [
        'phplyr' => fn (Table $ev) => $ev
            |> group_by('key')
            |> summarise(n: fn (Table $g) => $g->nrow(), sum_x: fn (Table $g) => array_sum(array_filter($g->column('x'), fn ($v) => $v !== null))),
        'direct' => fn (Table $ev) => $ev
            ->groupBy(['key'])
            ->summarise(['n' => fn (Table $g) => $g->nrow(), 'sum_x' => fn (Table $g) => array_sum(array_filter($g->column('x'), fn ($v) => $v !== null))]),
    ],
    'grouped_window' => [
        'phplyr' => fn (Table $ev) => $ev
            |> group_by('key')
            |> arrange('t')
            |> mutate(prev: lag('x'), run: cumsum('id'), rn: row_number()),
        'direct' => fn (Table $ev) => $ev
            ->groupBy(['key'])
            ->arrange(['t'])
            ->mutate(['prev' => Phibble\Expr\lag(Phibble\Expr\col('x')), 'run' => Phibble\Expr\cumsum(Phibble\Expr\col('id')), 'rn' => Phibble\Expr\row_number()]),
    ],
    'filter_expr' => [
        'phplyr' => fn (Table $ev) => $ev |> filter(col('x')->gt(0)->and(col('g')->isIn(['alpha', 'beta', 'gamma']))),
        'direct' => fn (Table $ev) => $ev->filter(Phibble\Expr\col('x')->gt(0)->and(Phibble\Expr\col('g')->isIn(['alpha', 'beta', 'gamma']))),
    ],
    'filter_closure' => [
        'phplyr' => fn (Table $ev) => $ev |> filter(fn ($r) => $r->x > 0 && \in_array($r->g, ['alpha', 'beta', 'gamma'], true)),
        'direct' => fn (Table $ev) => $ev->filter(fn ($r) => $r->x > 0 && \in_array($r->g, ['alpha', 'beta', 'gamma'], true)),
    ],
    'mutate_expr' => [
        'phplyr' => fn (Table $ev) => $ev |> mutate(y: col('x')->mul(2)->add(col('id')), big: col('x')->gt(50)),
        'direct' => fn (Table $ev) => $ev->mutate(['y' => Phibble\Expr\col('x')->mul(2)->add(Phibble\Expr\col('id')), 'big' => Phibble\Expr\col('x')->gt(50)]),
    ],
    'join' => [
        'phplyr' => fn (Table $ev, Table $d) => $ev |> left_join($d, by: 'key'),
        'direct' => fn (Table $ev, Table $d) => $ev->join($d, ['key'], 'left'),
    ],
    'arrange' => [
        'phplyr' => fn (Table $ev) => $ev |> arrange(desc('x'), 'id'),
        'direct' => fn (Table $ev) => $ev->arrange([['x', 'desc'], 'id']),
    ],
];

$events = $load("{$data}/events.csv");
$dims = $load("{$data}/dims.csv");

foreach ($workloads as $name => $forms) {
    if ($only !== [] && ! \in_array($name, $only, true)) {
        continue;
    }
    foreach ($forms as $form => $work) {
        $times = [];
        $rows = 0;
        for ($i = 0; $i < $runs; $i++) {
            gc_collect_cycles();
            $start = hrtime(true);
            $rows = $work($events, $dims)->collect()->nrow();
            $times[] = (hrtime(true) - $start) / 1e9;
        }
        sort($times);
        echo json_encode([
            'backend' => $backendName,
            'form' => $form,
            'workload' => $name,
            'rows' => $rows,
            'median_s' => round($times[intdiv(\count($times), 2)], 3),
            'min_s' => round($times[0], 3),
            'peak_mb' => round(memory_get_peak_usage(true) / 1e6),
        ]), "\n";
    }
}
