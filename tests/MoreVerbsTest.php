<?php

declare(strict_types=1);

use Phibble\DataFrame;

use function Phplyr\{bind_cols, bind_rows, glimpse, group_by, group_map, group_modify, group_split, group_vars, group_walk, lag, mutate, n, pick, pipe, slice_sample, summarise, ungroup, vec};

function small(): DataFrame
{
    return DataFrame::fromRows([
        ['g' => 'a', 'x' => 1],
        ['g' => 'b', 'x' => 2],
        ['g' => 'a', 'x' => 3],
    ]);
}

it('binds rows, filling missing columns with NA and tagging sources', function () {
    $out = pipe(small(), bind_rows(DataFrame::fromRows([['x' => 9, 'y' => true]]), _id: 'src'));
    expect($out->columns())->toBe(['src', 'g', 'x', 'y']);
    expect($out->column('src'))->toBe(['1', '1', '1', '2']);
    expect($out->column('g'))->toBe(['a', 'b', 'a', null]);
});

it('binds columns and repairs duplicate names', function () {
    $out = pipe(small(), bind_cols(DataFrame::fromRows([['x' => 'p'], ['x' => 'q'], ['x' => 'r']])));
    expect($out->columns())->toBe(['g', 'x...2', 'x...3']);
    expect($out->column('x...3'))->toBe(['p', 'q', 'r']);
    expect(fn () => pipe(small(), bind_cols(DataFrame::fromRows([['z' => 1]]))))->toThrow(LengthException::class);
});

it('splits, maps, modifies and walks groups', function () {
    $grouped = pipe(small(), group_by('g'));
    expect(pipe($grouped, group_vars()))->toBe(['g']);

    $parts = pipe($grouped, group_split());
    expect(array_map(fn ($t) => $t->column('x'), $parts))->toContain([1, 3], [2]);
    expect((pipe($grouped, group_split(_keep: false)))[0]->columns())->toBe(['x']);

    $sums = pipe($grouped, group_map(fn ($t, $key) => $key['g'].'='.array_sum($t->column('x'))));
    expect($sums)->toContain('a=4', 'b=2');

    $out = pipe($grouped, group_modify(fn ($t) => DataFrame::fromRows([['total' => array_sum($t->column('x')), 'rows' => $t->nrow()]])));
    expect($out->groups())->toBe(['g']);
    expect($out->columns())->toBe(['g', 'total', 'rows']);

    $seen = [];
    pipe($grouped, group_walk(function ($t, $key) use (&$seen) { $seen[] = $key['g']; }));
    expect($seen)->toHaveCount(2);
});

it('samples rows reproducibly', function () {
    mt_srand(42);
    $a = pipe(small(), slice_sample(n: 2));
    mt_srand(42);
    $b = pipe(small(), slice_sample(n: 2));
    expect($a->toRows())->toBe($b->toRows());
    expect($a->nrow())->toBe(2);
    expect((pipe(small(), slice_sample(n: 5, replace: true)))->nrow())->toBe(5);
    expect((pipe(small(), slice_sample(n: 5)))->nrow())->toBe(3);
    expect((pipe(small(), slice_sample(n: 1, weight_by: fn ($r) => $r->x === 2 ? 1 : 0)))->column('x'))->toBe([2]);
});

it('keeps or drops groups after summarise', function () {
    $t = pipe(small(), mutate(h: fn ($r) => $r->x > 1), group_by('g', 'h'));
    expect((pipe($t, summarise(n: n())))->groups())->toBe(['g']);
    expect((pipe($t, summarise(n: n(), _groups: 'keep')))->groups())->toBe(['g', 'h']);
    expect((pipe($t, summarise(n: n(), _groups: 'drop')))->groups())->toBe([]);
});

it('rejects summaries that return several values', function () {
    expect(fn () => pipe(small(), summarise(x: lag('x'))))->toThrow(LengthException::class, 'reframe');
});

it('maps vectors and picks columns', function () {
    $out = pipe(small(), mutate(d: lag('x')->map(fn ($prev, $r) => $prev === null ? null : $r->x - $prev)));
    expect($out->column('d'))->toBe([null, 1, 1]);
    $out = pipe(small(), mutate(both: vec(fn ($r) => $r->g.$r->x)));
    expect($out->column('both'))->toBe(['a1', 'b2', 'a3']);
    $out = pipe(small(), summarise(cols: fn ($g) => implode(',', pick('x')($g)->columns())));
    expect($out->column('cols'))->toBe(['x']);
});

it('glimpses a table and passes it through', function () {
    ob_start();
    $out = pipe(small(), glimpse());
    $text = ob_get_clean();
    expect($out->nrow())->toBe(3);
    expect($text)->toContain('Rows: 3')->toContain('$ g <chr> "a", "b", "a"')->toContain('$ x <int> 1, 2, 3');
});

it('rejects unknown options', function () {
    expect(fn () => mutate(x: 1, _nope: true))->toThrow(InvalidArgumentException::class, '_nope');
    expect(fn () => pipe(small(), ungroup()))->not->toThrow(Exception::class);
});

it('matches dplyr cur_group_id() and cur_group_rows()', function () {
    $ref = json_decode(file_get_contents(fixturePath('cur-group-reference.json')), true);
    $mut = pipe(panel(), group_by('label'), mutate(gid: Phplyr\cur_group_id(), row: Phplyr\cur_group_rows()), ungroup(), Phplyr\select('id', 'gid', 'row'));
    expectRowsClose($mut->toRows(), $ref['mut']);
    $summ = pipe(panel(), group_by('g', 'label'), summarise(gid: Phplyr\cur_group_id(), n: n(), _groups: 'drop'));
    expectRowsClose($summ->toRows(), $ref['summ']);
    $refr = pipe(panel(), group_by('g'), Phplyr\reframe(rows: Phplyr\cur_group_rows()));
    expectRowsClose($refr->toRows(), $ref['refr']);
    expect(fn () => pipe(panel(), group_by('g'), summarise(r: Phplyr\cur_group_rows())))->toThrow(LengthException::class, 'reframe');
});

it('keeps empty factor-level groups with _drop: false', function () {
    $ref = json_decode(file_get_contents(fixturePath('drop-reference.json')), true);
    $d = DataFrame::fromColumns(['f' => ['a', 'a', 'c'], 's' => ['x', 'y', 'x'], 'v' => [1.0, 2.0, 3.0]])->asFactor('f', ['a', 'b', 'c']);
    expectRowsClose((pipe($d, group_by('f', _drop: false), summarise(n: n(), total: Phplyr\sum('v'))))->toRows(), $ref['drop']);
    expectRowsClose((pipe($d, group_by('f', _drop: false), Phplyr\count()))->ungroup()->toRows(), $ref['count']);
    expectRowsClose((pipe($d, group_by('f', 's', _drop: false), summarise(n: n(), _groups: 'drop')))->toRows(), $ref['two']);
    expect((pipe($d, group_by('f', _drop: false)))->dropsEmptyGroups())->toBeFalse();
})->skip(fn () => ! method_exists(DataFrame::class, 'dropsEmptyGroups'), 'phibble lacks groupBy drop: false');
