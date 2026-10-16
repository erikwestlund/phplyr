<?php

declare(strict_types=1);

use Phibble\Frame;

use function Phplyr\{arrange, count, desc, distinct, filter, group_by, max, mean, median, min, mutate, n, quantile, rename, sd, select, summarise, ungroup, variance};

function reference(string $key): array
{
    static $json;
    $json ??= json_decode(file_get_contents(fixturePath('dplyr-reference.json')), true);

    return $json[$key];
}

function expectRowsClose(array $actual, array $expected): void
{
    expect(\count($actual))->toBe(\count($expected));
    foreach ($expected as $i => $row) {
        expect(array_keys($actual[$i]))->toBe(array_keys($row));
        foreach ($row as $col => $value) {
            if (is_float($value) || is_float($actual[$i][$col])) {
                expect($actual[$i][$col])->toEqualWithDelta($value, 1e-9 * \max(1.0, abs($value)));
            } else {
                expect($actual[$i][$col])->toBe($value);
            }
        }
    }
}

it('matches dplyr for a grouped summary', function () {
    bothBackends(function () {
        $out = Frame::fromCsv(fixturePath('schools.csv'))
            |> filter(fn ($r) => $r->study_hours > 5)
            |> mutate(gain: fn ($r) => $r->score - $r->prior_score)
            |> group_by('school')
            |> summarise(
                n: n(),
                mean_score: mean('score'),
                sd_score: sd('score'),
                var_gain: variance('gain'),
                median_hours: median('study_hours'),
                q90_score: quantile('score', 0.9),
                min_prior: min('prior_score'),
                max_prior: max('prior_score'),
            )
            |> arrange(desc('mean_score'));

        expectRowsClose($out->toRows(), reference('school_summary'));
    });
});

it('matches dplyr count with sort', function () {
    $out = Frame::fromCsv(fixturePath('schools.csv'))
        |> mutate(band: fn ($r) => $r->study_hours >= 8 ? 'high' : 'low')
        |> count('band', sort: true);

    expectRowsClose($out->toRows(), reference('hours_band_count'));
    expect($out->groups())->toBe([]);
});

it('matches dplyr for a grouped mutate', function () {
    $out = Frame::fromCsv(fixturePath('schools.csv'))
        |> group_by('school')
        |> mutate(centered: fn ($r, $g) => $r->score - (mean('score'))($g))
        |> ungroup()
        |> filter(fn ($r) => $r->centered > 10)
        |> select('school', 'score', 'centered')
        |> arrange('school', desc('centered'));

    expectRowsClose($out->toRows(), reference('grouped_center'));
});

it('matches dplyr for mutate, rename, select and arrange', function () {
    $out = Frame::fromCsv(fixturePath('housing.csv'))
        |> mutate(rent_per_m2: fn ($r) => $r->monthly_rent / $r->area_m2)
        |> rename(rooms: 'bedrooms')
        |> select('rooms', 'area_m2', 'rent_per_m2')
        |> arrange('rooms', desc('rent_per_m2'));

    expectRowsClose($out->toRows(), reference('housing_rent'));
});

it('matches dplyr distinct', function () {
    $out = Frame::fromCsv(fixturePath('housing.csv'))
        |> distinct('bedrooms')
        |> arrange('bedrooms');

    expectRowsClose($out->toRows(), reference('housing_rooms'));
});
