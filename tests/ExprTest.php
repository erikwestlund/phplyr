<?php

declare(strict_types=1);

use Phibble\Expr\Call;
use Phibble\DataFrame;

use function Phplyr\{abs, col, filter, group_by, lag, lmer, log, mean, mutate, paste0, round, select, str_sub, str_upper, ungroup};

function exprReference(string $key): mixed
{
    static $json;
    $json ??= json_decode(file_get_contents(fixturePath('expr-reference.json')), true);

    return $json[$key];
}

it('builds Phibble expressions when every argument is inspectable', function () {
    expect(mean('x')->expr)->toBeInstanceOf(Call::class);
    expect(mean('x')->expr->toArray())->toBe(['type' => 'call', 'fn' => 'mean', 'args' => [['type' => 'col', 'name' => 'x']]]);
    expect(lag('x', 2, order_by: Phplyr\desc('t'))->expr->toArray()['named']['order_by']['type'])->toBe('desc');
    expect(col('price')->mul(1.1)->gt(100)->expr->columns())->toBe(['price']);
    expect(mean(fn ($r) => $r->x * 2)->expr)->toBeNull();
});

it('evaluates closure arguments with the same semantics', function () {
    $t = panel() |> group_by('g');
    $a = $t |> mutate(m: mean('score'), c: Phplyr\cumsum('score'));
    $b = $t |> mutate(m: mean(fn ($r) => $r->score), c: Phplyr\cumsum(fn ($r) => $r->score));
    expect($b->toRows())->toBe($a->toRows());
});

it('matches dplyr for mutate _keep used and unused', function () {
    $p = panel();
    expectRowsClose(($p |> mutate(z: col('score')->mul(2), w: col('value')->add(col('year')), _keep: 'used'))->toRows(), exprReference('keep_used'));
    expectRowsClose(($p |> mutate(z: col('score')->mul(2), _keep: 'unused'))->toRows(), exprReference('keep_unused'));
    expectRowsClose(
        ($p |> group_by('g') |> mutate(dev: col('score')->sub(mean('score')), _keep: 'used') |> ungroup())->toRows(),
        exprReference('keep_used_grouped'),
    );
    expect(fn () => $p |> mutate(z: fn ($r) => $r->score, _keep: 'used'))->toThrow(InvalidArgumentException::class, 'expression');
});

it('matches R for scalar expression helpers', function () {
    $out = panel() |> mutate(
        r: round(col('value')->div(3), 2),
        up: str_upper('label'),
        s: str_sub('label', 1, 1),
        p: paste0('g', '-', col('id')),
        l: log('score'),
        ab: abs(col('score')->sub(5)),
    ) |> select('id', 'r', 'up', 's', 'p', 'l', 'ab');
    expectRowsClose($out->toRows(), exprReference('expressions'));
});

it('fits mixed models like lme4', function () {
    $fit = DataFrame::fromCsv(fixturePath('schools.csv')) |> lmer('score ~ study_hours + prior_score + (1 | school)');
    expectCoefficients($fit['coefficients'], exprReference('lmer_fixef'));
    expect($fit['sigma'])->toEqualWithDelta(exprReference('lmer_sigma'), 1e-5);
})->skip(fn () => ! function_exists('stats_lmer'), 'needs php_stats with stats_lmer');
