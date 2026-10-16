<?php

declare(strict_types=1);

use Phibble\Frame;

use function Phplyr\{bind_cols, bind_rows, glimpse, group_by, group_map, group_modify, group_split, group_vars, group_walk, inner_join, lag, mutate, n, pick, rowwise, slice_sample, summarise, ungroup, vec};

function small(): Frame
{
    return Frame::fromRows([
        ['g' => 'a', 'x' => 1],
        ['g' => 'b', 'x' => 2],
        ['g' => 'a', 'x' => 3],
    ]);
}

it('binds rows, filling missing columns with NA and tagging sources', function () {
    $out = small() |> bind_rows(Frame::fromRows([['x' => 9, 'y' => true]]), _id: 'src');
    expect($out->columns())->toBe(['src', 'g', 'x', 'y']);
    expect($out->column('src'))->toBe(['1', '1', '1', '2']);
    expect($out->column('g'))->toBe(['a', 'b', 'a', null]);
});

it('binds columns and repairs duplicate names', function () {
    $out = small() |> bind_cols(Frame::fromRows([['x' => 'p'], ['x' => 'q'], ['x' => 'r']]));
    expect($out->columns())->toBe(['g', 'x...2', 'x...3']);
    expect($out->column('x...3'))->toBe(['p', 'q', 'r']);
    expect(fn () => small() |> bind_cols(Frame::fromRows([['z' => 1]])))->toThrow(LengthException::class);
});

it('splits, maps, modifies and walks groups', function () {
    $grouped = small() |> group_by('g');
    expect($grouped |> group_vars())->toBe(['g']);

    $parts = $grouped |> group_split();
    expect(array_map(fn ($t) => $t->column('x'), $parts))->toContain([1, 3], [2]);
    expect(($grouped |> group_split(_keep: false))[0]->columns())->toBe(['x']);

    $sums = $grouped |> group_map(fn ($t, $key) => $key['g'].'='.array_sum($t->column('x')));
    expect($sums)->toContain('a=4', 'b=2');

    $out = $grouped |> group_modify(fn ($t) => Frame::fromRows([['total' => array_sum($t->column('x')), 'rows' => $t->nrow()]]));
    expect($out->groups())->toBe(['g']);
    expect($out->columns())->toBe(['g', 'total', 'rows']);

    $seen = [];
    $grouped |> group_walk(function ($t, $key) use (&$seen) { $seen[] = $key['g']; });
    expect($seen)->toHaveCount(2);
});

it('samples rows reproducibly', function () {
    mt_srand(42);
    $a = small() |> slice_sample(n: 2);
    mt_srand(42);
    $b = small() |> slice_sample(n: 2);
    expect($a->toRows())->toBe($b->toRows());
    expect($a->nrow())->toBe(2);
    expect((small() |> slice_sample(n: 5, replace: true))->nrow())->toBe(5);
    expect((small() |> slice_sample(n: 5))->nrow())->toBe(3);
    expect((small() |> slice_sample(n: 1, weight_by: fn ($r) => $r->x === 2 ? 1 : 0))->column('x'))->toBe([2]);
});

it('keeps or drops groups after summarise', function () {
    $t = small() |> mutate(h: fn ($r) => $r->x > 1) |> group_by('g', 'h');
    expect(($t |> summarise(n: n()))->groups())->toBe(['g']);
    expect(($t |> summarise(n: n(), _groups: 'keep'))->groups())->toBe(['g', 'h']);
    expect(($t |> summarise(n: n(), _groups: 'drop'))->groups())->toBe([]);
});

it('rejects summaries that return several values', function () {
    expect(fn () => small() |> summarise(x: lag('x')))->toThrow(LengthException::class, 'reframe');
});

it('maps vectors and picks columns', function () {
    $out = small() |> mutate(d: lag('x')->map(fn ($prev, $r) => $prev === null ? null : $r->x - $prev));
    expect($out->column('d'))->toBe([null, 1, 1]);
    $out = small() |> mutate(both: vec(fn ($r) => $r->g.$r->x));
    expect($out->column('both'))->toBe(['a1', 'b2', 'a3']);
    $out = small() |> summarise(cols: fn ($g) => implode(',', pick('x')($g)->columns()));
    expect($out->column('cols'))->toBe(['x']);
});

it('glimpses a table and passes it through', function () {
    ob_start();
    $out = small() |> glimpse();
    $text = ob_get_clean();
    expect($out->nrow())->toBe(3);
    expect($text)->toContain('Rows: 3')->toContain('$ g <chr> "a", "b", "a"')->toContain('$ x <int> 1, 2, 3');
});

it('explains when joins or rowwise need contract v1', function () {
    expect(fn () => small() |> inner_join(small()))->toThrow(LogicException::class, 'join');
    expect(fn () => small() |> rowwise())->toThrow(LogicException::class, 'rowwise');
})->skip(fn () => method_exists(Frame::class, 'join'), 'the table supports joins');

it('rejects unknown options', function () {
    expect(fn () => mutate(x: 1, _nope: true))->toThrow(InvalidArgumentException::class, '_nope');
    expect(fn () => small() |> ungroup())->not->toThrow(Exception::class);
});
