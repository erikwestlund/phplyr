<?php

declare(strict_types=1);

use Phibble\DataFrame;
use Phplyr\Stats;
use Phplyr\Vec;

use function Phplyr\{anti_join, cross_join, full_join, head, inner_join, left_join, mutate, rename, right_join, rowwise, select, semi_join, summarise, ungroup};

function joinX(): DataFrame
{
    return DataFrame::fromCsv(fixturePath('join_x.csv'));
}

function joinY(): DataFrame
{
    return DataFrame::fromCsv(fixturePath('join_y.csv'));
}

function joinReference(string $key): array
{
    static $json;
    $json ??= json_decode(file_get_contents(fixturePath('joins-reference.json')), true);

    return $json[$key];
}

dataset('joins', function () {
    $by = ['id' => 'key'];
    $mm = 'many-to-many';

    $cases = [
        'left' => [fn () => joinX() |> left_join(joinY(), by: $by, relationship: $mm)],
        'inner' => [fn () => joinX() |> inner_join(joinY(), by: $by, relationship: $mm)],
        'right' => [fn () => joinX() |> right_join(joinY(), by: $by, relationship: $mm)],
        'full' => [fn () => joinX() |> full_join(joinY(), by: $by, relationship: $mm)],
        'keep' => [fn () => joinX() |> left_join(joinY(), by: $by, keep: true, relationship: $mm)],
        'first' => [fn () => joinX() |> left_join(joinY(), by: $by, multiple: 'first')],
        'last' => [fn () => joinX() |> left_join(joinY(), by: $by, multiple: 'last')],
        'never' => [fn () => joinX() |> left_join(joinY(), by: $by, na_matches: 'never', relationship: $mm)],
        'semi' => [fn () => joinX() |> semi_join(joinY(), by: $by)],
        'anti' => [fn () => joinX() |> anti_join(joinY(), by: $by)],
        'anti_never' => [fn () => joinX() |> anti_join(joinY(), by: $by, na_matches: 'never')],
        'cross' => [fn () => (joinX() |> select('id')) |> cross_join(joinY() |> select('w'))],
        'suffix' => [fn () => joinX() |> inner_join(joinY(), by: $by, suffix: ['_l', '_r'], relationship: $mm)],
        'by_k' => [fn () => joinX() |> inner_join(joinY(), by: 'k')],
        'natural' => [fn () => joinX() |> inner_join(joinY() |> rename(id: 'key'))],
        'two_keys' => [fn () => joinX() |> left_join(joinY() |> rename(id: 'key'), by: ['id', 'k'])],
        'key_clash' => [fn () => joinX() |> left_join(joinY() |> mutate(id: fn ($r) => $r->key === null ? null : $r->key * 10), by: 'k')],
        'rowwise_sum' => [fn () => panel()
            |> rowwise('id')
            |> summarise(total: fn ($g) => Stats::sum([...$g->column('value'), ...$g->column('score')], naRm: true))
            |> head(4)],
        'rowwise_mutate' => [fn () => panel()
            |> rowwise()
            |> mutate(m: new Vec(fn ($g) => Stats::max([...$g->column('value'), ...$g->column('score')], naRm: true)))
            |> ungroup()
            |> select('id', 'm')
            |> head(4)],
    ];

    return array_map(fn ($key, $case) => [$key, $case[0]], array_keys($cases), $cases) |> (fn ($rows) => array_combine(array_column($rows, 0), $rows));
});

it('matches dplyr joins', function (string $key, Closure $pipeline) {
    expectRowsClose($pipeline()->toRows(), joinReference($key));
})->with('joins');

it('keeps grouping and errors like dplyr', function () {
    $out = joinX() |> Phplyr\group_by('k') |> left_join(joinY(), by: ['id' => 'key'], relationship: 'many-to-many');
    // dplyr drops a grouping column that the join renames.
    expect($out->groups())->toBe([]);
    $out = joinX() |> Phplyr\group_by('v') |> left_join(joinY(), by: ['id' => 'key'], relationship: 'many-to-many');
    expect($out->groups())->toBe(['v']);

    expect(fn () => joinX() |> inner_join(joinY(), by: ['id' => 'key'], unmatched: 'error', relationship: 'many-to-many'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => joinX() |> left_join(joinY(), by: ['id' => 'key'], relationship: 'one-to-one'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => joinX() |> inner_join(DataFrame::fromRows([['zzz' => 1]])))
        ->toThrow(InvalidArgumentException::class, 'cross_join');
});

it('marks rowwise summaries as grouped by the id columns', function () {
    $out = panel() |> rowwise('id') |> summarise(n: Phplyr\n());
    expect($out->groups())->toBe(['id']);
    expect($out->nrow())->toBe(12);
});
