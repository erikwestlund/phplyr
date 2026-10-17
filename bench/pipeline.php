<?php

/*
 * Time representative pipelines on a synthetic sales table, through phplyr
 * and through the equivalent phibble method calls, to separate phplyr's own
 * overhead from the backend's.
 *
 * Usage: php bench/pipeline.php [rows=100000] [backend=frame|sqlite]
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Phibble\Contracts\Table;
use Phibble\DataFrame;

use function Phibble\Expr\{col as ecol, desc as edesc, mean as emean, min_rank as emin_rank, n as en, sum as esum, lag as elag, cumsum as ecumsum};
use function Phplyr\{arrange, col, cumsum, desc, filter, group_by, lag, left_join, mean, min_rank, mutate, n, sum, summarise};

$rows = (int) ($argv[1] ?? 100_000);
$backend = $argv[2] ?? 'frame';

mt_srand(42);
$columns = ['id' => [], 'store' => [], 'product' => [], 'day' => [], 'units' => [], 'price' => []];
for ($i = 0; $i < $rows; $i++) {
    $columns['id'][] = $i + 1;
    $columns['store'][] = 's'.mt_rand(1, 100);
    $columns['product'][] = 'p'.mt_rand(1, 1000);
    $columns['day'][] = mt_rand(1, 365);
    $u = mt_rand(1, 100);
    $columns['units'][] = $u <= 2 ? null : ($u <= 7 ? 0 : mt_rand(1, 20));
    $columns['price'][] = mt_rand(100, 10000) / 100;
}
$sales = DataFrame::fromColumns($columns);
$stores = DataFrame::fromColumns([
    'store' => array_map(fn ($i) => 's'.$i, range(1, 100)),
    'region' => array_map(fn ($i) => 'r'.($i % 5), range(1, 100)),
]);

if ($backend === 'sqlite') {
    $pdo = new PDO('sqlite::memory:');
    foreach (['sales' => $sales, 'stores' => $stores] as $name => $frame) {
        $cols = $frame->columns();
        $pdo->exec("CREATE TABLE {$name} (".implode(', ', $cols).')');
        $insert = $pdo->prepare("INSERT INTO {$name} VALUES (".implode(', ', array_fill(0, \count($cols), '?')).')');
        $pdo->beginTransaction();
        foreach ($frame->toRows() as $row) {
            $insert->execute(array_values($row));
        }
        $pdo->commit();
    }
    $sales = Phibble\tbl($pdo, 'sales');
    $stores = Phibble\tbl($pdo, 'stores');
}

$pipelines = [
    'A summary+join+rank (phplyr)' => fn () => $sales
        |> filter(col('units')->gt(0))
        |> left_join($stores, by: 'store')
        |> group_by('region', 'product')
        |> summarise(n: n(), revenue: sum(col('units')->mul(col('price'))), avg_price: mean('price'))
        |> group_by('region')
        |> mutate(rank: min_rank(desc('revenue')))
        |> filter(col('rank')->le(10))
        |> arrange('region', 'rank'),
    'A summary+join+rank (phibble)' => fn () => $sales
        ->filter(ecol('units')->gt(0))
        ->join($stores, [['store', 'store']], 'left')
        ->groupBy(['region', 'product'])
        ->summarise(['n' => en(), 'revenue' => esum(ecol('units')->mul(ecol('price'))), 'avg_price' => emean(ecol('price'))])
        ->groupBy(['region'])
        ->mutate(['rank' => emin_rank(edesc(ecol('revenue')))])
        ->filter(ecol('rank')->le(10))
        ->arrange(['region', 'rank']),
    'B grouped windows (phplyr)' => fn () => $sales
        |> group_by('store')
        |> mutate(prev: lag('units', order_by: 'day'), running: cumsum('units'), share: col('units')->div(sum('units', na_rm: true))),
    'B grouped windows (phibble)' => fn () => $sales
        ->groupBy(['store'])
        ->mutate([
            'prev' => elag(ecol('units'), order_by: ecol('day')),
            'running' => ecumsum(ecol('units')),
            'share' => ecol('units')->div(esum(ecol('units'), na_rm: true)),
        ]),
    'C closures (phplyr)' => fn () => $sales
        |> filter(fn ($r) => $r->units > 0)
        |> left_join($stores, by: 'store')
        |> group_by('region', 'product')
        |> summarise(n: n(), revenue: fn ($g) => array_sum(array_map(fn ($u, $p) => $u * $p, $g->column('units'), $g->column('price'))), avg_price: mean('price'))
        |> group_by('region')
        |> mutate(rank: min_rank(desc('revenue')))
        |> filter(fn ($r) => $r->rank <= 10)
        |> arrange('region', 'rank'),
];

printf("%s, %s rows\n", $backend, number_format($rows));
foreach ($pipelines as $name => $run) {
    $start = hrtime(true);
    $out = $run();
    $out = $out->collect();
    $ms = (hrtime(true) - $start) / 1e6;
    printf("  %-34s %9.1f ms  (%d rows out)\n", $name, $ms, $out->nrow());
}
