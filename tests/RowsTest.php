<?php

declare(strict_types=1);

use Phibble\DataFrame;

use function Phplyr\{case_match, group_by, group_cols, intersect, mutate, pipe, rows_append, rows_delete, rows_insert, rows_patch, rows_update, rows_upsert, select, setdiff, setequal, symdiff, ungroup, union, union_all};

function rowsReference(string $key): mixed
{
    static $json;
    $json ??= json_decode(file_get_contents(fixturePath('rows-reference.json')), true);

    return $json[$key];
}

function setA(): DataFrame
{
    return DataFrame::fromColumns(['id' => [1.0, 2.0, 3.0, 3.0, null], 'v' => ['a', 'b', 'c', 'c', 'n']]);
}

function setB(): DataFrame
{
    return DataFrame::fromColumns(['id' => [3.0, 4.0, null], 'v' => ['c', 'd', 'n']]);
}

function rowsX(): DataFrame
{
    return DataFrame::fromColumns(['id' => [1.0, 2.0, 3.0], 'v' => ['a', null, 'c'], 'w' => [10.0, 20.0, 30.0]]);
}

function rowsY(): DataFrame
{
    return DataFrame::fromColumns(['id' => [2.0, 4.0], 'v' => ['B', 'D']]);
}

it('matches dplyr set operations', function () {
    expectRowsClose((pipe(setA(), union_all(setB())))->toRows(), rowsReference('union_all'));
    expectRowsClose((pipe(setA(), union(setB())))->toRows(), rowsReference('union'));
    expectRowsClose((pipe(setA(), intersect(setB())))->toRows(), rowsReference('intersect'));
    expectRowsClose((pipe(setA(), setdiff(setB())))->toRows(), rowsReference('setdiff'));
    expectRowsClose((pipe(setA(), symdiff(setB())))->toRows(), rowsReference('symdiff'));
    expect(pipe(setA(), setequal(pipe(setA(), Phplyr\arrange(Phplyr\desc('v'))))))->toBeTrue();
    expect(pipe(setA(), setequal(setB())))->toBeFalse();
});

it('matches dplyr rows_*()', function () {
    expectRowsClose((pipe(rowsX(), rows_insert(rowsY(), by: 'id', conflict: 'ignore')))->toRows(), rowsReference('insert'));
    expectRowsClose((pipe(rowsX(), rows_append(rowsY())))->toRows(), rowsReference('append'));
    expectRowsClose((pipe(rowsX(), rows_update(rowsY(), by: 'id', unmatched: 'ignore')))->toRows(), rowsReference('update'));
    expectRowsClose((pipe(rowsX(), rows_patch(rowsY(), by: 'id', unmatched: 'ignore')))->toRows(), rowsReference('patch'));
    expectRowsClose((pipe(rowsX(), rows_upsert(rowsY(), by: 'id')))->toRows(), rowsReference('upsert'));
    expectRowsClose((pipe(rowsX(), rows_delete(rowsY(), by: 'id', unmatched: 'ignore')))->toRows(), rowsReference('delete'));

    expect(fn () => pipe(rowsX(), rows_insert(rowsY(), by: 'id')))->toThrow(UnexpectedValueException::class);
    expect(fn () => pipe(rowsX(), rows_update(rowsY(), by: 'id')))->toThrow(UnexpectedValueException::class);
});

it('matches case_match and group_cols', function () {
    $out = pipe(panel(), mutate(kind: case_match('label', [['x', 'y'], 'xy'], ['z', 'zed'], default: 'other')), select('id', 'kind'));
    expectRowsClose($out->toRows(), rowsReference('case_match'));
    $out = pipe(panel(), group_by('g', 'label'), select(group_cols(), 'score'), ungroup());
    expect($out->columns())->toBe(rowsReference('group_cols'));
});

it('matches dplyr 1.2 additions', function () {
    $cases = [
        'filter_out' => fn () => pipe(panel(), Phplyr\filter_out(fn ($r) => $r->value === null ? null : $r->value > 5, fn ($r) => $r->score > 3), select('id')),
        'filter_when_any' => fn () => pipe(panel(), Phplyr\filter(Phplyr\when_any(fn ($r) => $r->value === null ? null : $r->value > 7, fn ($r) => $r->score > 8)), select('id')),
        'when' => fn () => pipe(
            panel(),
            mutate(
                any: Phplyr\when_any(fn ($r) => $r->value === null ? null : $r->value > 5, fn ($r) => $r->score > 5),
                all: Phplyr\when_all(fn ($r) => $r->value === null ? null : $r->value > 5, fn ($r) => $r->score > 5),
                all_rm: Phplyr\when_all(fn ($r) => $r->value === null ? null : $r->value > 5, fn ($r) => $r->score > 5, na_rm: true),
            ),
            select('id', 'any', 'all', 'all_rm'),
        ),
        'recode' => fn () => pipe(
            panel(),
            mutate(
                r: Phplyr\recode_values('label', ['x', 'ex'], [['y', 'z'], 'late'], default: '?'),
                lk: Phplyr\recode_values('score', from: [1, 2, 3], to: ['one', 'two', 'three']),
            ),
            select('id', 'r', 'lk'),
        ),
        'replace' => fn () => pipe(
            panel(),
            mutate(rv: Phplyr\replace_values('label', ['z', 'zed']), rw: Phplyr\replace_when('score', [fn ($r) => $r->score > 7, 7])),
            select('id', 'rv', 'rw'),
        ),
    ];
    foreach ($cases as $key => $case) {
        expectRowsClose($case()->toRows(), rowsReference($key));
    }
});
