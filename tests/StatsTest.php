<?php

declare(strict_types=1);

use Phplyr\Stats;

it('computes descriptives like R', function () {
    bothBackends(function () {
        $x = [2.0, 4.0, 4.0, 5.0, 7.0, 9.0];
        expect(Stats::mean($x))->toEqualWithDelta(5.166666666666667, 1e-12);
        expect(Stats::var($x))->toEqualWithDelta(6.166666666666667, 1e-12);
        expect(Stats::sd($x))->toEqualWithDelta(2.483277404291892, 1e-12);
        expect(Stats::median($x))->toEqualWithDelta(4.5, 1e-12);
        expect(Stats::quantile($x, 0.9))->toEqualWithDelta(8.0, 1e-12);
        expect(Stats::quantile($x, 0.0))->toEqualWithDelta(2.0, 1e-12);
        expect(Stats::quantile($x, 1.0))->toEqualWithDelta(9.0, 1e-12);
        expect(Stats::sum($x))->toBe(31.0);
        expect(Stats::min($x))->toBe(2.0);
        expect(Stats::max($x))->toBe(9.0);
    });
});

it('handles missing values and small inputs', function () {
    bothBackends(function () {
        $x = [1, null, 3];
        expect(Stats::mean($x))->toBeNull();
        expect(Stats::mean($x, naRm: true))->toEqualWithDelta(2.0, 1e-12);
        expect(Stats::sd([1, NAN, 3], naRm: true))->toEqualWithDelta(sqrt(2), 1e-12);
        expect(Stats::var([1.0]))->toBeNull();
        expect(Stats::mean([]))->toBeNull();
        expect(Stats::sum([]))->toBe(0);
        expect(Stats::max([null], naRm: true))->toBeNull();
    });
});

it('rejects non-numeric input and bad probabilities', function () {
    expect(fn () => Stats::mean(['a', 'b']))->toThrow(InvalidArgumentException::class);
    expect(fn () => Stats::quantile([1, 2], 1.5))->toThrow(InvalidArgumentException::class);
});
