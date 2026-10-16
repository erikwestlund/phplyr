<?php

declare(strict_types=1);

use Phibble\Frame;

use function Phplyr\{glm, lm};

it('explains when stats_lm is missing', function () {
    $t = Frame::fromRows([['y' => 1.0, 'x' => 2.0]]);
    expect(fn () => $t |> lm('y ~ x'))->toThrow(RuntimeException::class, 'stats_lm');
    expect(fn () => $t |> glm('y ~ x', family: 'binomial'))->toThrow(RuntimeException::class, 'stats_glm');
})->skip(fn () => function_exists('stats_lm'), 'stats_lm is available');
