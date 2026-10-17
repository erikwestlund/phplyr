<?php

declare(strict_types=1);

use Phibble\DataFrame;

use function Phplyr\{arrange, complete, crossing, desc, drop_na, everything, except, expand, fill, filter, group_by, head, nest, nesting, pivot_longer, pivot_wider, replace_na, select, separate, separate_longer_delim, separate_longer_position, separate_wider_delim, separate_wider_position, separate_wider_regex, starts_with, uncount, ungroup, unite, unnest};

function tidyFixture(string $name): DataFrame
{
    return DataFrame::fromCsv(fixturePath($name));
}

function tidyrReference(string $key): array
{
    static $json;
    $json ??= json_decode(file_get_contents(fixturePath('tidyr-reference.json')), true);

    return $json[$key];
}

dataset('tidyr', function () {
    $wide = fn () => tidyFixture('wide.csv');
    $long = fn () => tidyFixture('long.csv');
    $noZ = fn () => $long() |> filter(fn ($r) => $r->key !== 'z');
    $strings = fn () => tidyFixture('strings.csv');
    $cases = [
        'longer' => fn () => $wide() |> pivot_longer(starts_with('wk'), names_to: 'week', values_to: 'rank'),
        'longer_drop' => fn () => $wide() |> pivot_longer(['wk1', 'wk2', 'wk3'], names_to: 'week', names_prefix: 'wk', values_drop_na: true),
        'longer_sep' => fn () => tidyFixture('who.csv') |> pivot_longer(except('country'), names_to: ['diag', 'sex_age'], names_prefix: 'new_', names_sep: '_'),
        'longer_pattern' => fn () => tidyFixture('who.csv') |> pivot_longer(except('country'), names_to: ['diag', 'sex', 'age'], names_pattern: 'new_(.*)_(.)(.*)'),
        'longer_value' => fn () => tidyFixture('family.csv') |> pivot_longer(except('family'), names_to: ['.value', 'child'], names_sep: '_', values_drop_na: true),
        'wider' => fn () => $noZ() |> pivot_wider(id_cols: 'id', names_from: 'key', values_from: 'val'),
        'wider_fill' => fn () => $noZ() |> pivot_wider(id_cols: 'id', names_from: 'key', values_from: 'val', values_fill: 0, names_prefix: 'k_'),
        'wider_fn' => fn () => $long() |> pivot_wider(id_cols: 'id', names_from: 'key', values_from: 'val', values_fn: fn ($v) => array_sum($v)),
        'wider_multi' => fn () => $noZ() |> pivot_wider(names_from: 'key', values_from: ['val', 'w']),
        'wider_sort' => fn () => $noZ() |> arrange(desc('key')) |> pivot_wider(id_cols: 'id', names_from: 'key', values_from: 'val', names_sort: true),
        'wider_glue' => fn () => $noZ() |> pivot_wider(id_cols: 'id', names_from: 'key', values_from: 'val', names_glue: '{key}_{.value}'),
        'complete' => fn () => $long() |> select('id', 'key', 'val') |> filter(fn ($r) => $r->key !== 'z' || $r->val == 5) |> complete('id', 'key'),
        'complete_fill' => fn () => $long() |> select('id', 'key', 'w') |> filter(fn ($r) => $r->key !== 'z' || $r->w === 50) |> complete('id', 'key', fill: ['w' => 0]),
        'complete_nesting' => fn () => panel() |> filter(fn ($r) => $r->id <= 6) |> select('g', 'label', 'year') |> complete(nesting('g', 'label'), year: [2001, 2002]) |> head(8),
        'complete_grouped' => fn () => $long() |> select('id', 'key', 'w') |> filter(fn ($r) => $r->key !== 'z' || $r->w === 50) |> group_by('id') |> complete(key: ['x', 'y']) |> ungroup(),
        'expand' => fn () => $long() |> expand('id', 'key'),
        'expand_nesting' => fn () => $long() |> expand(nesting('id', 'key')),
        'crossing' => fn () => crossing(x: [2, 1, 2], y: ['b', 'a']),
        'fill_down' => fn () => $wide() |> fill('wk1', 'wk2'),
        'fill_up' => fn () => $wide() |> fill('wk1', 'wk2', _direction: 'up'),
        'fill_grouped' => fn () => panel() |> select('id', 'g', 'value') |> group_by('g') |> fill('value', _direction: 'downup') |> ungroup(),
        'drop_na_all' => fn () => $wide() |> drop_na(),
        'drop_na_col' => fn () => $wide() |> drop_na('wk1'),
        'replace_na' => fn () => $wide() |> replace_na(['wk1' => 0, 'wk2' => -1]),
        'uncount' => fn () => $strings() |> select('id', 'n') |> uncount('n', _id: 'copy'),
        'unite' => fn () => panel() |> head(3) |> unite('gl', 'g', 'label', sep: ':'),
        'unite_keep' => fn () => panel() |> head(3) |> unite('gv', 'g', 'value', remove: false),
        'separate' => fn () => $strings() |> select('id', 's') |> separate('s', ['a', 'b'], sep: '-', extra: 'drop', fill: 'right'),
        'separate_merge' => fn () => $strings() |> select('id', 's') |> separate('s', ['a', 'b'], sep: '-', extra: 'merge', fill: 'left'),
        'separate_pos' => fn () => $strings() |> select('id', 'code') |> separate('code', ['letters', 'digits'], sep: 2, convert: true),
        'wider_delim' => fn () => $strings() |> select('id', 's') |> separate_wider_delim('s', '-', names: ['p1', 'p2', 'p3'], too_few: 'align_start'),
        'wider_delim_sep' => fn () => $strings() |> select('id', 's') |> separate_wider_delim('s', '-', names_sep: '_', too_few: 'align_end'),
        'wider_position' => fn () => $strings() |> select('id', 'code') |> separate_wider_position('code', ['state' => 2, 1, 'digits' => 2], too_few: 'align_start'),
        'wider_regex' => fn () => $strings() |> select('id', 'code') |> separate_wider_regex('code', ['letters' => '[A-Z]+', 'digits' => '\d+']),
        'longer_delim' => fn () => $strings() |> select('id', 'tags') |> separate_longer_delim('tags', ','),
        'longer_position' => fn () => $strings() |> select('id', 'code') |> separate_longer_position('code', 2),
    ];

    return array_combine(array_keys($cases), array_map(fn ($k, $c) => [$k, $c], array_keys($cases), $cases));
});

it('matches tidyr', function (string $key, Closure $pipeline) {
    $out = $pipeline();
    expectRowsClose(($out instanceof DataFrame ? $out : $out->collect())->toRows(), tidyrReference($key));
})->with('tidyr');

it('errors on ambiguous widening and ragged splits', function () {
    // Duplicate cells become list-columns with tidyr's warning.
    $warnings = [];
    set_error_handler(function (int $level, string $message) use (&$warnings) {
        $warnings[] = $message;

        return true;
    }, E_USER_WARNING);
    try {
        $wide = tidyFixture('long.csv') |> pivot_wider(id_cols: 'id', names_from: 'key', values_from: 'val');
    } finally {
        restore_error_handler();
    }
    expect($warnings[0] ?? '')->toStartWith('Values from `val` are not uniquely identified; output will contain list-cols.');
    expect($wide->toRows()[2]['z'])->toBe([5.0, 6.0]);
    expect($wide->toRows()[0]['x'])->toBe([1.5]);
    expect(fn () => tidyFixture('strings.csv') |> separate_wider_delim('s', '-', names: ['a', 'b']))
        ->toThrow(UnexpectedValueException::class, 'too_many');
});

it('nests and unnests', function () {
    $nested = panel() |> select('g', 'id', 'score') |> group_by('g') |> nest();
    expect($nested->nrow())->toBe(3);
    $back = $nested |> unnest('data');
    expect($back->nrow())->toBe(12);
    $named = panel() |> select('g', 'id', 'score') |> nest(rows: ['id', 'score']);
    expect($named->columns())->toBe(['g', 'rows']);
});
