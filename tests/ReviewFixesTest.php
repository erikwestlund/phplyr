<?php

declare(strict_types=1);

/*
 * Regression tests for the v0.1.0 review. Each expectation is R's output
 * (dplyr 1.2.1, tidyr 1.3.2) for the same input.
 */

use Phibble\Frame;
use Phibble\Type;

use function Phplyr\{arrange, bind_cols, col, count, cur_group_id, desc, drop_na, expand, fill, group_by, group_split, mutate, n, n_groups, pivot_longer, pivot_wider, reframe, replace_na, rowwise, separate_wider_position, separate_longer_position, separate_wider_regex, slice_max, slice_min, slice_sample, sum, summarise, tally, ungroup, unite};

function emptyGx(): Frame
{
    return Frame::fromColumns(['g' => [], 'x' => []], ['g' => Type::String, 'x' => Type::Float]);
}

function dropFactor(): Frame
{
    // g has an unused level 'a'.
    return Frame::fromColumns(['g' => ['b', 'c', 'c'], 'v' => [3, 1, 2]])->asFactor('g', ['a', 'b', 'c']);
}

it('keeps rowwise and _drop: false through arrange() and ungroup()', function () {
    $df = Frame::fromColumns(['id' => ['p', 'q', 'q'], 'a' => [1, 2, 3]]);
    expect($df |> rowwise() |> arrange(desc('a')) |> mutate(s: sum('a')) |> Phplyr\pull('s'))->toBe([3, 2, 1]);
    $out = $df |> rowwise('id') |> arrange(desc('a')) |> summarise(s: sum('a'));
    expect($out->toRows())->toBe([['id' => 'q', 's' => 3], ['id' => 'q', 's' => 2], ['id' => 'p', 's' => 1]]);

    $kept = dropFactor() |> group_by('g', _drop: false) |> arrange('v') |> summarise(n: n());
    expect($kept |> Phplyr\pull('n'))->toBe([0, 1, 2]);
    $partial = dropFactor() |> mutate(h: 1) |> group_by('g', 'h', _drop: false) |> ungroup('h') |> summarise(n: n());
    expect($partial->column('g'))->toBe(['a', 'b', 'c']);
});

it('handles zero-row tables in grouped verbs', function () {
    expect((emptyGx() |> reframe(m: Phplyr\mean('x')))->nrow())->toBe(1);
    $grouped = emptyGx() |> group_by('g') |> reframe(m: Phplyr\mean('x'));
    expect($grouped->columns())->toBe(['g', 'm'])->and($grouped->nrow())->toBe(0);
    expect(emptyGx() |> group_by('g') |> group_split())->toBe([]);
    expect(emptyGx() |> group_by('g') |> n_groups())->toBe(0);
    $expanded = emptyGx() |> group_by('g') |> expand('x');
    expect($expanded->columns())->toBe(['g', 'x'])->and($expanded->nrow())->toBe(0);
});

it('recycles zero-length reframe() results to zero rows', function () {
    $out = Frame::fromColumns(['x' => [1, 2]]) |> reframe(a: fn ($g) => [], b: 1);
    expect($out->columns())->toBe(['a', 'b'])->and($out->nrow())->toBe(0);
});

it('orders factors by level in slice_min(), slice_max() and pivot_wider(names_sort:)', function () {
    $f = Frame::fromColumns(['k' => ['lo', 'hi', 'mid']])->asFactor('k', ['lo', 'mid', 'hi']);
    expect($f |> slice_min('k', n: 1) |> Phplyr\pull('k'))->toBe(['lo']);
    expect($f |> slice_max('k', n: 1) |> Phplyr\pull('k'))->toBe(['hi']);
    $w = Frame::fromColumns(['g' => ['c', 'b'], 'k' => ['x', 'y'], 'v' => [1, 2]])->asFactor('k', ['y', 'x']);
    expect(($w |> pivot_wider(names_from: 'k', values_from: 'v', names_sort: true))->columns())->toBe(['g', 'y', 'x']);
});

it('accepts desc() in slice_min() and slice_max()', function () {
    $t = Frame::fromColumns(['x' => [3, null, 1, 1, 1]]);
    expect($t |> slice_min(desc('x'), n: 1) |> Phplyr\pull('x'))->toBe([3]);
    expect($t |> slice_max(desc('x'), n: 1) |> Phplyr\pull('x'))->toBe([1, 1, 1]);
});

it('numbers rowwise and empty groups by visit order in cur_group_id()', function () {
    $df = Frame::fromColumns(['a' => [1, 2, 3]]);
    expect($df |> rowwise() |> summarise(id: cur_group_id()) |> Phplyr\pull('id'))->toBe([1, 2, 3]);
    $out = dropFactor() |> group_by('g', _drop: false) |> summarise(id: cur_group_id(), n: n());
    expect($out->toRows())->toBe([['g' => 'a', 'id' => 1, 'n' => 0], ['g' => 'b', 'id' => 2, 'n' => 1], ['g' => 'c', 'id' => 3, 'n' => 2]]);
});

it('lets summarise() use summaries defined earlier in the call', function () {
    $df = Frame::fromColumns(['g' => ['a', 'b', 'a'], 'x' => [1, 2, 3]]);
    $out = $df |> group_by('g') |> summarise(s: sum('x'), t: col('s')->mul(2), u: sum('x')->div(col('t')));
    expect($out->toRows())->toBe([['g' => 'a', 's' => 4, 't' => 8, 'u' => 0.5], ['g' => 'b', 's' => 2, 't' => 4, 'u' => 0.5]]);
});

it('names counts n when only a non-grouping column is called n', function () {
    $df = Frame::fromColumns(['g' => ['a', 'b', 'a'], 'n' => [1, 2, 3]]);
    expect(($df |> count('g'))->columns())->toBe(['g', 'n']);
    expect(($df |> group_by('g') |> tally())->columns())->toBe(['g', 'n']);
    expect(($df |> Phplyr\add_count('g'))->columns())->toBe(['g', 'n', 'nn']);
});

it('treats NaN as missing in drop_na(), replace_na() and fill()', function () {
    $t = Frame::fromColumns(['x' => [1.0, NAN, null, 4.0]]);
    expect($t |> drop_na() |> Phplyr\pull('x'))->toBe([1.0, 4.0]);
    expect($t |> replace_na(['x' => 0.0]) |> Phplyr\pull('x'))->toBe([1.0, 0.0, 0.0, 4.0]);
    expect($t |> fill('x') |> Phplyr\pull('x'))->toBe([1.0, 1.0, 1.0, 4.0]);
});

it('keeps partial matches in separate_wider_regex(too_few: align_start)', function () {
    $out = Frame::fromColumns(['s' => ['ab12', 'zz', '12']])
        |> separate_wider_regex('s', ['l' => '[a-z]+', 'd' => '[0-9]+'], too_few: 'align_start');
    expect($out->toRows())->toBe([['l' => 'ab', 'd' => '12'], ['l' => 'zz', 'd' => null], ['l' => null, 'd' => null]]);
});

it('drops extra names_sep pieces in pivot_longer()', function () {
    $out = Frame::fromColumns(['id' => [1], 'a_b_c' => [1], 'a_d_e' => [2]])
        |> pivot_longer(['a_b_c', 'a_d_e'], names_to: ['p', 'q'], names_sep: '_');
    expect($out |> Phplyr\pull('q'))->toBe(['b', 'd']);
});

it('counts characters, not bytes, when splitting by position', function () {
    $t = Frame::fromColumns(['s' => ['éab']]);
    expect(($t |> separate_wider_position('s', ['a' => 1, 'b' => 2]))->toRows())->toBe([['a' => 'é', 'b' => 'ab']]);
    expect($t |> separate_longer_position('s', 1) |> Phplyr\pull('s'))->toBe(['é', 'a', 'b']);
});

it('writes doubles as R does in unite()', function () {
    $out = Frame::fromColumns(['a' => [0.3, 1 / 3, 1e20, 100000.0], 'b' => ['w', 'x', 'y', 'z']]) |> unite('ab', 'a', 'b');
    expect($out |> Phplyr\pull('ab'))->toBe(['0.3_w', '0.333333333333333_x', '1e+20_y', '1e+05_z']);
});

it('samples with replacement beyond the group size and never draws zero weights', function () {
    $t = Frame::fromColumns(['x' => [1, 2, 3], 'w' => [0, 0, 1]]);
    expect(($t |> slice_sample(prop: 2.0, replace: true))->nrow())->toBe(6);
    expect($t |> slice_sample(n: 1, weight_by: 'w') |> Phplyr\pull('x'))->toBe([3]);
    expect(fn () => $t |> slice_sample(n: 3, weight_by: 'w'))->toThrow(InvalidArgumentException::class, 'positive weight');
});

it('drops grouping when bind_cols() renames a grouping column', function () {
    $df = Frame::fromColumns(['g' => ['a', 'b'], 'w' => [1, 2]]) |> group_by('g');
    $out = $df |> bind_cols(Frame::fromColumns(['g' => [1, 2]]));
    expect($out->columns())->toBe(['g...1', 'w', 'g...3'])->and($out->groups())->toBe([]);
});
