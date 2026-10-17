<?php

declare(strict_types=1);

/*
 * The method chain, phplyr($t)->verb(...), and pipe($t, verb(...)) give the
 * same result as applying each verb's closure in turn, which is what the
 * PHP 8.5 pipe operator does. tests/Pipe compares them with |> itself.
 */

use Phibble\Contracts\Table;
use Phibble\DataFrame;
use Phplyr\Chain;

use function Phplyr\{arrange, desc, filter, group_by, inner_join, mean, mutate, n, phplyr, pipe, pull, summarise};

/**
 * Pipelines as [verb, args] steps, so each runs three ways: as closures
 * applied in turn, through pipe(), and as a method chain.
 */
dataset('chain pipelines', fn () => [
    'filter and arrange' => [[
        ['filter', [fn ($r) => $r->score > 3]],
        ['arrange', [desc('score'), 'id']],
    ]],
    'grouped summary' => [[
        ['group_by', ['g']],
        ['summarise', ['n' => n(), 'm' => mean('score', na_rm: true)]],
    ]],
    'named options' => [[
        ['mutate', ['z' => fn ($r) => $r->score * 2, '_before' => 'id']],
        ['slice_max', ['score', 'n' => 2, '_by' => 'g']],
    ]],
    'join and count' => [[
        ['inner_join', [panel()->select(['id', 'g'])->rename(['g2' => 'g']), 'by' => 'id']],
        ['count', ['g', 'g2', 'sort' => true]],
    ]],
    'pivot' => [[
        ['select', ['id', 'g', 'year', 'score']],
        ['pivot_wider', ['names_from' => 'year', 'values_from' => 'score', 'id_cols' => 'g', 'values_fn' => fn (array $v) => array_sum($v)]],
    ]],
    'non-table result' => [[
        ['group_by', ['g']],
        ['summarise', ['n' => n()]],
        ['pull', ['n']],
    ]],
    'grouping helper' => [[
        ['group_by', ['g']],
        ['group_vars', []],
    ]],
]);

function stepClosure(array $step): Closure
{
    [$name, $args] = $step;

    return ('Phplyr\\'.$name)(...$args);
}

function expectSameResult(mixed $actual, mixed $expected): void
{
    if ($expected instanceof Table) {
        expect($actual)->toBeInstanceOf(Table::class);
        expect($actual->columns())->toBe($expected->columns());
        expect($actual->groups())->toBe($expected->groups());
        expect($actual->toRows())->toBe($expected->toRows());

        return;
    }
    expect($actual)->toBe($expected);
}

it('gives the same result as applying the verbs in turn for', function (array $steps) {
    $expected = panel();
    foreach ($steps as $step) {
        $expected = stepClosure($step)($expected);
    }

    expectSameResult(pipe(panel(), ...array_map(stepClosure(...), $steps)), $expected);

    $chain = phplyr(panel());
    foreach ($steps as [$name, $args]) {
        $chain = $chain->{$name}(...$args);
    }
    expectSameResult($chain instanceof Chain ? $chain->get() : $chain, $expected);
})->with('chain pipelines');

it('runs a written-out chain', function () {
    $out = phplyr(panel())
        ->filter(fn ($r) => $r->score !== null)
        ->group_by('g')
        ->summarise(n: n(), m: mean('score'))
        ->arrange(desc('m'))
        ->get();

    $expected = pipe(
        panel(),
        filter(fn ($r) => $r->score !== null),
        group_by('g'),
        summarise(n: n(), m: mean('score')),
        arrange(desc('m')),
    );
    expectSameResult($out, $expected);
});

it('continues the chain while results are tables and returns other results plainly', function () {
    expect(phplyr(panel())->group_by('g'))->toBeInstanceOf(Chain::class);
    expect(phplyr(panel())->group_by('g')->get())->toBeInstanceOf(DataFrame::class);
    expect(phplyr(panel())->pull('id'))->toBe(panel()->column('id'));
    expect(phplyr(panel())->group_by('g')->n_groups())->toBe(3);
    expect(phplyr(panel())->setequal(panel()))->toBeTrue();
});

it('applies closures with pipe() and ->pipe()', function () {
    $double = fn (Table $t) => mutate(score: fn ($r) => $r->score === null ? null : $r->score * 2)($t);
    $first = fn (Table $t) => $t->column('score')[0];

    expect(phplyr(panel())->pipe($double)->get()->column('score'))->toBe(pipe(panel(), $double)->column('score'));
    expect(phplyr(panel())->pipe($double, $first))->toBe(pipe(panel(), $double, $first));
    expect(pipe(3, fn ($x) => $x + 1, fn ($x) => $x * 2))->toBe(8);
    expect(pipe('unchanged'))->toBe('unchanged');
});

it('prints the table, and accepts a chain', function () {
    $chain = phplyr(panel())->head(3);
    expect((string) $chain)->toBe((string) $chain->get());
    expect(phplyr($chain))->toBe($chain);
});

it('runs joins with a table argument', function () {
    $y = panel()->select(['id', 'g']);
    expectSameResult(phplyr(panel())->inner_join($y, by: ['id', 'g'])->get(), pipe(panel(), inner_join($y, by: ['id', 'g'])));
});

it('rejects names that are not verbs', function (string $name) {
    expect(fn () => phplyr(panel())->{$name}('score'))->toThrow(BadMethodCallException::class, "{$name}() is not a phplyr verb");
})->with(['mean', 'starts_with', 'nonexistent', 'phplyr', 'pipe_x']);

it('lists every verb in its @method docblock', function () {
    $php = escapeshellarg(PHP_BINARY);
    exec("{$php} ".escapeshellarg(dirname(__DIR__).'/bin/chain-docblock').' --check 2>&1', $out, $status);
    expect($status)->toBe(0, implode("\n", $out));
});
