<?php

declare(strict_types=1);

/*
 * The PHP 8.5 pipe operator, the method chain, and pipe() give the same
 * results. This directory only parses on PHP 8.5, so phpunit.xml keeps it
 * in its own test suite, which bin/test and CI skip on older versions.
 */

use Phibble\Contracts\Table;
use Phibble\DataFrame;

use function Phplyr\{arrange, count, desc, filter, group_by, head, left_join, mean, mutate, n, phplyr, pipe, pivot_longer, pull, select, slice_max, starts_with, summarise};

function expectSameTables(Table $a, Table $b): void
{
    expect($a->columns())->toBe($b->columns());
    expect($a->groups())->toBe($b->groups());
    expect($a->toRows())->toBe($b->toRows());
}

it('gives the same table with |>, a method chain, and pipe()', function () {
    $operator = panel()
        |> filter(fn ($r) => $r->score !== null)
        |> group_by('g')
        |> summarise(n: n(), m: mean('score'))
        |> arrange(desc('m'));

    $chain = phplyr(panel())
        ->filter(fn ($r) => $r->score !== null)
        ->group_by('g')
        ->summarise(n: n(), m: mean('score'))
        ->arrange(desc('m'))
        ->get();

    $function = pipe(
        panel(),
        filter(fn ($r) => $r->score !== null),
        group_by('g'),
        summarise(n: n(), m: mean('score')),
        arrange(desc('m')),
    );

    expectSameTables($chain, $operator);
    expectSameTables($function, $operator);
});

it('passes named options the same way', function () {
    $operator = panel()
        |> mutate(z: fn ($r) => $r->year * 2, _before: 'id')
        |> slice_max('score', n: 2, _by: 'g')
        |> count('g', sort: true);

    $chain = phplyr(panel())
        ->mutate(z: fn ($r) => $r->year * 2, _before: 'id')
        ->slice_max('score', n: 2, _by: 'g')
        ->count('g', sort: true)
        ->get();

    expectSameTables($chain, $operator);
});

it('joins and pivots the same way', function () {
    $wide = DataFrame::fromCsv(fixturePath('wide.csv'));
    $ids = panel() |> select('id', 'g') |> head(4);

    $operator = $wide
        |> pivot_longer(starts_with('wk'), names_to: 'week', values_to: 'rank')
        |> left_join($ids, by: 'id');

    $chain = phplyr($wide)
        ->pivot_longer(starts_with('wk'), names_to: 'week', values_to: 'rank')
        ->left_join($ids, by: 'id')
        ->get();

    expectSameTables($chain, $operator);
});

it('returns non-table results the same way', function () {
    $operator = panel() |> group_by('g') |> summarise(n: n()) |> pull('n');

    expect(phplyr(panel())->group_by('g')->summarise(n: n())->pull('n'))->toBe($operator);
    expect(pipe(panel(), group_by('g'), summarise(n: n()), pull('n')))->toBe($operator);
});

it('mixes |> with a chain and plain closures', function () {
    $double = fn (Table $t) => $t |> mutate(score: fn ($r) => $r->score === null ? null : $r->score * 2);

    $operator = panel() |> $double |> head(3);
    $chain = phplyr(panel())->pipe($double)->head(3)->get();
    $mixed = phplyr(panel() |> $double)->head(3)->get() |> select('id', 'score');

    expectSameTables($chain, $operator);
    expectSameTables($mixed, $operator |> select('id', 'score'));
});
