<?php

declare(strict_types=1);

use Phibble\DataFrame;
use Phibble\Sql\SqlTable;

use function Phibble\tbl;
use function Phplyr\{arrange, col, collect, count, desc, filter, group_by, inner_join, lag, mean, min_rank, mutate, n, pipe, select, show_query, summarise, ungroup};

function sqlitePanel(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE panel (id INTEGER, g TEXT, year INTEGER, value REAL, score INTEGER, label TEXT)');
    $insert = $pdo->prepare('INSERT INTO panel VALUES (?, ?, ?, ?, ?, ?)');
    foreach (panel()->toRows() as $row) {
        $insert->execute(array_values($row));
    }
    $pdo->exec('CREATE TABLE groups (g TEXT, region TEXT)');
    $pdo->exec("INSERT INTO groups VALUES ('a', 'north'), ('b', 'south')");

    return $pdo;
}

it('keeps expression pipelines lazy and matches the in-memory result', function () {
    $pdo = sqlitePanel();
    $pipeline = fn ($t) => pipe(
        $t,
        filter(col('score')->ge(3), col('value')->isNa()->not()),
        mutate(double: col('score')->mul(2)),
        group_by('g'),
        summarise(rows: n(), avg: mean('value'), top: Phplyr\max('double')),
        arrange(desc('avg')),
    );

    $lazy = $pipeline(tbl($pdo, 'panel'));
    expect($lazy)->toBeInstanceOf(SqlTable::class);
    expect(pipe($lazy, show_query()))->toContain('SELECT');
    expectRowsClose((pipe($lazy, collect()))->toRows(), $pipeline(panel())->toRows());
});

it('runs grouped windows, counts and joins in SQL', function () {
    $pdo = sqlitePanel();
    $windows = fn ($t) => pipe(
        $t,
        arrange('id'),
        group_by('g'),
        mutate(prev: lag('score'), rank: min_rank(desc('score'))),
        ungroup(),
        select('id', 'g', 'prev', 'rank'),
        arrange('id'),
    );
    $lazy = $windows(tbl($pdo, 'panel'));
    expect($lazy)->toBeInstanceOf(SqlTable::class);
    expectRowsClose((pipe($lazy, collect()))->toRows(), $windows(panel())->toRows());

    $counted = pipe(tbl($pdo, 'panel'), count('g', sort: true));
    expect($counted)->toBeInstanceOf(SqlTable::class);
    expect((pipe($counted, collect()))->toRows())->toBe([['g' => 'a', 'n' => 4], ['g' => 'b', 'n' => 4], ['g' => 'c', 'n' => 4]]);

    $joined = pipe(tbl($pdo, 'panel'), inner_join(tbl($pdo, 'groups'), by: 'g'), count('region'));
    expect($joined)->toBeInstanceOf(SqlTable::class);
    expect((pipe($joined, collect()))->toRows())->toBe([['region' => 'north', 'n' => 4], ['region' => 'south', 'n' => 4]]);
});

it('falls back to memory for closures', function () {
    $out = pipe(tbl(sqlitePanel(), 'panel'), filter(fn ($r) => $r->score > 8));
    expect($out)->toBeInstanceOf(DataFrame::class);
    expect(pipe($out, Phplyr\pull('id')))->toBe([5, 7]);
});

it('accepts Phibble\Expr nodes directly and stays lazy', function () {
    $t = tbl(sqlitePanel(), 'panel');
    $filtered = pipe($t, filter(Phibble\Expr\col('score')->ge(3)));
    expect($filtered)->toBeInstanceOf(SqlTable::class);
    $summary = pipe($t, group_by('g'), summarise(rows: Phibble\Expr\n(), m: Phibble\Expr\mean(Phibble\Expr\col('score'))));
    expect($summary)->toBeInstanceOf(SqlTable::class);
    expect((pipe($summary, collect()))->column('m'))->toBe([4.5, 5.25, 5.5]);
});
