<?php

declare(strict_types=1);

use Phibble\Frame;

use function Phplyr\{anti_join, between, closest, col, inner_join, join_by, left_join, overlaps, rename, semi_join, within};

function joinByReference(string $key): array
{
    static $json;
    $json ??= json_decode(file_get_contents(fixturePath('join-by-reference.json')), true);

    return $json[$key];
}

/** phibble supports join_by's inequality shapes once join() takes ?bool $keep. */
function phibbleSupportsInequalityJoins(): bool
{
    return (new ReflectionMethod(Frame::class, 'join'))->getParameters()[4]->allowsNull();
}

it('compiles join_by() conditions to the contract shapes', function () {
    expect(join_by('id', col('t')->ge(col('start')))->conditions)->toBe([['id', 'id'], ['t', '>=', 'start']]);
    expect(join_by(['id' => 'key'], col('a')->eq(col('b')))->conditions)->toBe([['id', 'key'], ['a', 'b']]);
    expect(join_by('id', between('t', 'start', 'end'))->conditions)->toBe([['id', 'id'], ['t', '>=', 'start'], ['t', '<=', 'end']]);
    expect(join_by(closest(col('t')->lt(col('start'))))->conditions)->toBe([['closest', 't', '<', 'start']]);
    expect(join_by(within('a', 'b', 'c', 'd'))->conditions)->toBe([['a', '>=', 'c'], ['b', '<=', 'd']]);
    expect(join_by(overlaps('a', 'b', 'c', 'd'))->conditions)->toBe([['a', '<=', 'd'], ['b', '>=', 'c']]);
    expect(fn () => join_by(closest('id')))->toThrow(InvalidArgumentException::class, 'closest');
});

it('matches dplyr for equality join_by()', function () {
    $out = joinX() |> left_join(joinY(), by: join_by(['id' => 'key']), relationship: 'many-to-many');
    expectRowsClose($out->toRows(), joinByReference('eq'));
});

it('refuses keep: false for non-equality joins', function () {
    expect(fn () => joinX() |> left_join(joinY(), by: join_by(col('id')->ge(col('key'))), keep: false))
        ->toThrow(InvalidArgumentException::class, 'keep = FALSE');
});

dataset('join_by', function () {
    $ev = fn () => Frame::fromCsv(fixturePath('events.csv'));
    $pe = fn () => Frame::fromCsv(fixturePath('periods.csv'));
    $pe2 = fn () => $pe() |> rename(s2: 'start', e2: 'end');
    $cases = [
        'ineq' => fn () => $ev() |> inner_join($pe(), by: join_by('id', col('t')->ge(col('start')))),
        'ineq_left' => fn () => $ev() |> left_join($pe(), by: join_by('id', col('t')->ge(col('start')), col('t')->lt(col('end')))),
        'between' => fn () => $ev() |> left_join($pe(), by: join_by('id', between('t', 'start', 'end'))),
        'closest' => fn () => $ev() |> left_join($pe(), by: join_by('id', closest(col('t')->ge(col('start'))))),
        'closest_lt' => fn () => $ev() |> left_join($pe(), by: join_by(closest(col('t')->lt(col('start'))))),
        'within' => fn () => $pe2() |> inner_join($pe(), by: join_by(within('s2', 'e2', 'start', 'end'))),
        'overlaps' => fn () => $pe2() |> inner_join($pe(), by: join_by(overlaps('s2', 'e2', 'start', 'end'))),
        'semi' => fn () => $ev() |> semi_join($pe(), by: join_by('id', between('t', 'start', 'end'))),
        'anti' => fn () => $ev() |> anti_join($pe(), by: join_by('id', between('t', 'start', 'end'))),
        'keep_true' => fn () => $ev() |> left_join($pe(), by: join_by('id', closest(col('t')->ge(col('start')))), keep: true),
    ];

    return array_combine(array_keys($cases), array_map(fn ($k, $c) => [$k, $c], array_keys($cases), $cases));
});

it('matches dplyr inequality, rolling and overlap joins', function (string $key, Closure $pipeline) {
    expectRowsClose($pipeline()->toRows(), joinByReference($key));
})->with('join_by')->skip(fn () => ! phibbleSupportsInequalityJoins(), 'phibble join() lacks inequality support');
