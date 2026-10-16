<?php

/*
 * Time three phplyr pipelines on Frame, PolarsTable and SqlTable (SQLite),
 * check that every backend returns the same rows, and write the results with
 * their method metadata to docs/benchmarks/backends-results.json.
 *
 * Adapted from phpolars' bench/phplyr.php (clever-fox): same data, seed,
 * pipelines and result check.
 *
 *   php -d memory_limit=-1 [-d extension=.../libphp_polars.dylib] bench/backends.php \
 *       [--rows=100000,1000000] [--repeats=3] [--out=docs/benchmarks/backends-results.json]
 *
 * PolarsTable runs when the php_polars extension is loaded; its phibble
 * adapter is loaded from PHPOLARS_DIR (a phpolars checkout).
 */

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Composer\InstalledVersions;
use Phibble\Contracts\Table;
use Phibble\Frame;
use Phibble\Sql\SqlTable;
use Phpolars\Phibble\PolarsTable;

use function Phplyr\{arrange, col, cumsum, desc, filter, group_by, lag, left_join, mean, min_rank, mutate, n, sum, summarise};

ini_set('memory_limit', '-1');
$options = getopt('', ['rows:', 'repeats:', 'out:']);
$sizes = array_map('intval', explode(',', $options['rows'] ?? '100000,1000000'));
$repeats = (int) ($options['repeats'] ?? 3);
$root = dirname(__DIR__);
$out = $options['out'] ?? $root.'/docs/benchmarks/backends-results.json';

$polarsDir = getenv('PHPOLARS_DIR') ?: null;
$polars = \extension_loaded('php_polars') && $polarsDir !== null;
if ($polars) {
    spl_autoload_register(function (string $class) use ($polarsDir): void {
        $prefix = 'Phpolars\\Phibble\\';
        if (str_starts_with($class, $prefix)) {
            require $polarsDir.'/phibble/src/'.str_replace('\\', '/', substr($class, \strlen($prefix))).'.php';
        }
    });
}

$pipelines = [
    'A' => ['filter, join, grouped summary, ranked window', fn (Table $s, Table $st) => $s
        |> filter(col('units')->gt(0))
        |> left_join($st, by: 'store')
        |> group_by('region', 'product')
        |> summarise(n: n(), revenue: sum(col('units')->mul(col('price'))), avg_price: mean('price'))
        |> group_by('region')
        |> mutate(rank: min_rank(desc('revenue')))
        |> filter(col('rank')->le(10))
        |> arrange('region', 'rank')],
    'B' => ['per-group windows over every row', fn (Table $s, Table $st) => $s
        |> group_by('store')
        |> mutate(prev: lag('units', order_by: 'day'), running: cumsum('units'), share: col('units')->div(sum('units', na_rm: true)))],
    'C' => ['as A, with a PHP closure in filter()', fn (Table $s, Table $st) => $s
        |> filter(fn ($r) => $r->units > 0)
        |> left_join($st, by: 'store')
        |> group_by('region', 'product')
        |> summarise(n: n(), revenue: sum(col('units')->mul(col('price'))), avg_price: mean('price'))
        |> group_by('region')
        |> mutate(rank: min_rank(desc('revenue')))
        |> filter(col('rank')->le(10))
        |> arrange('region', 'rank')],
];

/** Rows with floats at 9 significant digits, sorted, so float noise and row order don't count. */
function canonical(array $rows): string
{
    $rows = array_map(fn (array $r) => array_map(fn ($v) => \is_float($v) ? sprintf('%.9g', $v) : $v, $r), $rows);
    $lines = array_map('json_encode', $rows);
    sort($lines);

    return md5(implode("\n", $lines));
}

/** The source of each pipeline, for the JSON. */
function pipelineSource(string $id): string
{
    $src = file_get_contents(__FILE__);
    preg_match("/    '{$id}' => \\['[^']*', (fn .*?)\\],\n/s", $src, $m);

    return preg_replace('/^ {8}/m', '    ', $m[1] ?? '');
}

$git = fn (string $dir) => trim((string) shell_exec('git -C '.escapeshellarg($dir).' rev-parse --short HEAD 2>/dev/null')) ?: null;
$sysctl = fn (string $key) => trim((string) shell_exec('sysctl -n '.escapeshellarg($key).' 2>/dev/null')) ?: null;

$results = [];
foreach ($sizes as $rows) {
    mt_srand(7);
    $sales = ['id' => [], 'store' => [], 'product' => [], 'day' => [], 'units' => [], 'price' => []];
    for ($i = 0; $i < $rows; $i++) {
        $sales['id'][] = $i + 1;
        $sales['store'][] = mt_rand(1, 100);
        $sales['product'][] = mt_rand(1, 1000);
        $sales['day'][] = mt_rand(1, 365);
        $r = mt_rand(1, 100);
        $sales['units'][] = $r <= 2 ? null : ($r <= 7 ? 0 : mt_rand(1, 20));
        $sales['price'][] = \round(mt_rand(100, 10000) / 100, 2);
    }
    $stores = ['store' => range(1, 100), 'region' => array_map(fn ($s) => 'region-'.(($s - 1) % 5 + 1), range(1, 100))];
    $frameSales = Frame::fromColumns($sales);
    $frameStores = Frame::fromColumns($stores);

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE sales (id INTEGER, store INTEGER, product INTEGER, day INTEGER, units INTEGER, price REAL)');
    $pdo->exec('CREATE TABLE stores (store INTEGER, region TEXT)');
    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT INTO sales VALUES (?, ?, ?, ?, ?, ?)');
    for ($i = 0; $i < $rows; $i++) {
        $insert->execute([$sales['id'][$i], $sales['store'][$i], $sales['product'][$i], $sales['day'][$i], $sales['units'][$i], $sales['price'][$i]]);
    }
    $insert = $pdo->prepare('INSERT INTO stores VALUES (?, ?)');
    foreach ($stores['store'] as $i => $s) {
        $insert->execute([$s, $stores['region'][$i]]);
    }
    $pdo->commit();
    unset($sales);

    $tables = ['Frame' => [$frameSales, $frameStores]];
    if ($polars) {
        $tables['PolarsTable'] = [PolarsTable::fromTable($frameSales), PolarsTable::fromTable($frameStores)];
    }
    $tables['SqlTable(SQLite)'] = [SqlTable::table($pdo, 'sales'), SqlTable::table($pdo, 'stores')];

    foreach ($pipelines as $id => [$title, $pipeline]) {
        $reference = null;
        foreach ($tables as $backend => [$s, $st]) {
            $times = [];
            $result = null;
            $error = null;
            $before = $backend === 'PolarsTable' ? PolarsTable::$fallbacks : 0;
            for ($k = 0; $k < $repeats; $k++) {
                gc_collect_cycles();
                $t = hrtime(true);
                try {
                    $result = $pipeline($s, $st)->collect();
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                    break;
                }
                $times[] = \round((hrtime(true) - $t) / 1e9, 4);
            }
            $hash = $error === null ? canonical($result->toRows()) : null;
            $reference ??= $hash;
            $entry = [
                'pipeline' => $id,
                'backend' => $backend,
                'rows_in' => $rows,
                'rows_out' => $error === null ? $result->nrow() : null,
                'seconds' => $error === null ? \min($times) : null,
                'seconds_all' => $times,
                'fallbacks' => $backend === 'PolarsTable' ? intdiv(PolarsTable::$fallbacks - $before, $repeats) : null,
                'matches_frame' => $error === null ? $hash === $reference : null,
                'error' => $error,
            ];
            $results[] = $entry;
            fprintf(STDERR, "%-8s %-2s %-18s %9s s  %s\n", number_format($rows), $id, $backend, $entry['seconds'] ?? 'failed', $entry['matches_frame'] === false ? 'RESULT DIFFERS' : '');
        }
    }
}

$report = [
    'schema' => 'phplyr-backends/1',
    'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'method' => [
        'statistic' => 'best',
        'repeats' => $repeats,
        'timing' => 'pipeline through collect(); data preloaded and not timed',
        'verification' => 'rows canonicalised (floats to 9 significant digits, sorted) and hashed, then compared with the reference backend',
        'reference' => 'Frame',
        'polars_threads' => $polars ? Phpolars\thread_pool_size() : null,
        'threads_note' => 'polars_threads applies to PolarsTable only; Frame and SqlTable(SQLite) run single-threaded',
        'machine' => [
            'os' => php_uname('s').' '.php_uname('r').' '.php_uname('m'),
            'cpu' => $sysctl('machdep.cpu.brand_string'),
            'cores' => ($c = $sysctl('hw.ncpu')) === null ? null : (int) $c,
            'memory_gb' => ($m = $sysctl('hw.memsize')) === null ? null : (int) \round($m / 1024 ** 3),
        ],
        'php' => PHP_VERSION,
        'versions' => [
            'phplyr' => $git($root),
            'phibble' => substr((string) InstalledVersions::getReference('erikwestlund/phibble'), 0, 7) ?: null,
            'phpolars' => $polars ? [
                'sha' => $git($polarsDir),
                'extension' => Phpolars\version(),
                'polars' => \function_exists('Phpolars\polars_version') ? Phpolars\polars_version() : null,
                'profile' => getenv('PHPOLARS_PROFILE') ?: 'release',
            ] : null,
            'sqlite' => (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetchColumn(),
        ],
        'data' => [
            'script' => 'bench/backends.php',
            'seed' => 7,
            'tables' => [
                'sales' => 'id, store (100 values), product (1,000), day (1-365), units (about 2% NA, 5% zero), price',
                'stores' => 'store, region (5 values)',
            ],
            'note' => 'no date or datetime columns; datetime columns add about 0.2 s per 1M rows to PolarsTable collect()',
        ],
    ],
    'pipelines' => array_map(
        fn ($id, $p) => ['id' => $id, 'title' => $p[0], 'code' => pipelineSource($id)],
        array_keys($pipelines),
        $pipelines,
    ),
    'results' => $results,
];
@mkdir(\dirname($out), 0777, true);
file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
fwrite(STDERR, "Wrote {$out}\n");
