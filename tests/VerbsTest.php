<?php

declare(strict_types=1);

use Phibble\Frame;

use function Phplyr\{arrange, count, desc, filter, first, group_by, head, last, mean, mutate, n, n_distinct, pull, rename, select, slice, slice_head, sum, summarise, summarize, ungroup};

function sales(): Frame
{
    return Frame::fromRows([
        ['brand' => 'a', 'store' => 'x', 'price' => 10.0, 'qty' => 1],
        ['brand' => 'b', 'store' => 'x', 'price' => 0.0, 'qty' => 3],
        ['brand' => 'a', 'store' => 'y', 'price' => 30.0, 'qty' => 2],
        ['brand' => 'b', 'store' => 'y', 'price' => 20.0, 'qty' => null],
        ['brand' => 'a', 'store' => 'x', 'price' => 50.0, 'qty' => 5],
    ]);
}

it('runs the target pipeline', function () {
    $out = sales()
        |> filter(fn ($r) => $r->price > 0)
        |> mutate(log_price: fn ($r) => log($r->price))
        |> group_by('brand')
        |> summarise(n: n(), avg: mean('price'))
        |> arrange(desc('avg'));

    expect($out->toRows())->toBe([
        ['brand' => 'a', 'n' => 3, 'avg' => 30.0],
        ['brand' => 'b', 'n' => 1, 'avg' => 20.0],
    ]);
});

it('ANDs several filter predicates and treats null as false', function () {
    $out = sales()
        |> filter(fn ($r) => $r->brand === 'a', fn ($r) => $r->qty > 1)
        |> pull('price');
    expect($out)->toBe([30.0, 50.0]);

    $out = sales() |> filter(fn ($r) => $r->qty === null ? null : true) |> pull('qty');
    expect($out)->toBe([1, 3, 2, 5]);
});

it('filters within groups', function () {
    $out = sales()
        |> group_by('brand')
        |> filter(fn ($r, $g) => $r->price === \max($g->column('price')))
        |> pull('price');

    expect($out)->toBe([20.0, 50.0]);
});

it('mutates with closures, lists and scalars, in order', function () {
    $out = sales()
        |> mutate(
            total: fn ($r) => $r->price * ($r->qty ?? 0),
            double: fn ($r) => $r->total * 2,
            id: [1, 2, 3, 4, 5],
            source: 'pos',
        )
        |> head(2);

    expect($out->toRows())->toBe([
        ['brand' => 'a', 'store' => 'x', 'price' => 10.0, 'qty' => 1, 'total' => 10.0, 'double' => 20.0, 'id' => 1, 'source' => 'pos'],
        ['brand' => 'b', 'store' => 'x', 'price' => 0.0, 'qty' => 3, 'total' => 0.0, 'double' => 0.0, 'id' => 2, 'source' => 'pos'],
    ]);
});

it('requires named arguments where outputs are named', function () {
    expect(fn () => mutate(fn ($r) => 1))->toThrow(InvalidArgumentException::class, 'named arguments');
    expect(fn () => summarise(n()))->toThrow(InvalidArgumentException::class, 'named arguments');
});

it('selects, renames and pulls', function () {
    $t = sales() |> select('price', 'brand') |> rename(cost: 'price');
    expect($t->columns())->toBe(['cost', 'brand']);
    expect($t |> pull())->toBe(['a', 'b', 'a', 'b', 'a']);
    expect($t |> pull(0))->toBe([10.0, 0.0, 30.0, 20.0, 50.0]);
});

it('arranges by several keys', function () {
    $out = sales() |> arrange('store', desc('price')) |> pull('price');
    expect($out)->toBe([50.0, 10.0, 0.0, 30.0, 20.0]);
});

it('summarises with several groups, dropping the last level', function () {
    $out = sales()
        |> group_by('brand', 'store')
        |> summarise(qty: sum('qty', na_rm: true), revenue: fn ($g) => array_sum($g->column('price')));

    expect($out->groups())->toBe(['brand']);
    expect($out->toRows())->toBe([
        ['brand' => 'a', 'store' => 'x', 'qty' => 6, 'revenue' => 60.0],
        ['brand' => 'b', 'store' => 'x', 'qty' => 3, 'revenue' => 0.0],
        ['brand' => 'a', 'store' => 'y', 'qty' => 2, 'revenue' => 30.0],
        ['brand' => 'b', 'store' => 'y', 'qty' => 0, 'revenue' => 20.0],
    ]);
    expect((sales() |> summarize(n: n()))->toRows())->toBe([['n' => 5]]);
});

it('propagates NA in summaries unless na_rm is set', function () {
    $out = sales() |> summarise(q: sum('qty'), q_rm: sum('qty', na_rm: true), m: mean('qty'));
    expect($out->toRows())->toBe([['q' => null, 'q_rm' => 11, 'm' => null]]);
});

it('summarises with n_distinct, first and last', function () {
    $out = sales()
        |> group_by('brand')
        |> summarise(stores: n_distinct('store'), first: first('price'), last: last('price'));

    expect($out->toRows())->toBe([
        ['brand' => 'a', 'stores' => 2, 'first' => 10.0, 'last' => 50.0],
        ['brand' => 'b', 'stores' => 2, 'first' => 0.0, 'last' => 20.0],
    ]);
});

it('counts within existing groups and keeps them', function () {
    $out = sales() |> group_by('brand') |> count('store', name: 'rows');

    expect($out->groups())->toBe(['brand']);
    expect($out->toRows())->toBe([
        ['brand' => 'a', 'store' => 'x', 'rows' => 2],
        ['brand' => 'b', 'store' => 'x', 'rows' => 1],
        ['brand' => 'a', 'store' => 'y', 'rows' => 1],
        ['brand' => 'b', 'store' => 'y', 'rows' => 1],
    ]);
    expect((sales() |> count())->toRows())->toBe([['n' => 5]]);
    expect(fn () => count('brand', bogus: true))->toThrow(InvalidArgumentException::class);
});

it('slices the head of each group', function () {
    $out = sales() |> group_by('brand') |> slice_head(1);
    expect($out->groups())->toBe(['brand']);
    expect($out |> pull('price'))->toBe([10.0, 0.0]);

    expect(sales() |> slice_head(2) |> pull('price'))->toBe([10.0, 0.0]);
    expect(sales() |> group_by('brand') |> head(3) |> pull('price'))->toBe([10.0, 0.0, 30.0]);
    expect(sales() |> slice(1, 2) |> pull('price'))->toBe([0.0, 30.0]);
});

it('ungroups', function () {
    expect((sales() |> group_by('brand') |> ungroup())->groups())->toBe([]);
});

it('leaves the input table unchanged', function () {
    $t = sales();
    $t |> filter(fn ($r) => false) |> mutate(x: 1);
    expect($t->nrow())->toBe(5);
    expect($t->columns())->toBe(['brand', 'store', 'price', 'qty']);
});
