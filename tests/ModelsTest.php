<?php

declare(strict_types=1);

use Phibble\Frame;

use function Phplyr\{filter, glm, lm};

function modelsReference(string $key): array
{
    return json_decode(file_get_contents(fixturePath('models-reference.json')), true)[$key];
}

function expectCoefficients(array $actual, array $expected): void
{
    expect(\count($actual))->toBe(\count($expected));
    foreach ($expected as $i => $value) {
        expect($actual[$i])->toEqualWithDelta($value, 1e-6 * \max(1.0, abs($value)));
    }
}

it('explains when stats_lm is missing', function () {
    $t = Frame::fromRows([['y' => 1.0, 'x' => 2.0]]);
    expect(fn () => $t |> lm('y ~ x'))->toThrow(RuntimeException::class, 'stats_lm');
    expect(fn () => $t |> glm('y ~ x', family: 'binomial'))->toThrow(RuntimeException::class, 'stats_glm');
})->skip(fn () => function_exists('stats_lm'), 'stats_lm is available');

it('fits models like R', function () {
    $schools = Frame::fromCsv(fixturePath('schools.csv'));
    expectCoefficients(($schools |> lm('score ~ study_hours + prior_score + school'))['coefficients'], modelsReference('lm'));
    expectCoefficients(
        ($schools |> filter(fn ($r) => $r->prior_score > 60) |> lm('score ~ study_hours'))['coefficients'],
        modelsReference('lm_filtered'),
    );
    $learning = Frame::fromCsv(fixturePath('learning.csv'));
    expectCoefficients(
        ($learning |> glm('passed ~ study_hours + practice_score', family: 'binomial'))['coefficients'],
        modelsReference('glm'),
    );
})->skip(fn () => ! function_exists('stats_lm'), 'needs php_stats with stats_lm');
