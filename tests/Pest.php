<?php

declare(strict_types=1);

use Phplyr\Stats;

function fixturePath(string $name): string
{
    return __DIR__.'/Fixtures/'.$name;
}

/** Run a test body once with php_stats (when loaded) and once in pure PHP. */
function bothBackends(Closure $body): void
{
    $backends = extension_loaded('php_stats') ? [true, false] : [false];
    try {
        foreach ($backends as $useExtension) {
            Stats::$useExtension = $useExtension;
            $body($useExtension);
        }
    } finally {
        Stats::$useExtension = true;
    }
}

function reference(string $key): mixed
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
            if ($value !== null && $actual[$i][$col] !== null && (is_float($value) || is_float($actual[$i][$col]))) {
                expect($actual[$i][$col])->toEqualWithDelta($value, 1e-9 * \max(1.0, abs($value)));
            } else {
                expect($actual[$i][$col])->toBe($value);
            }
        }
    }
}

/** Column names of an R reference (a length-1 vector arrives unboxed). */
function referenceNames(string $key): array
{
    return (array) reference($key);
}

/** Single-column R references as a flat list. */
function referenceColumn(string $key, string $column): array
{
    return array_column(reference($key), $column);
}

/** True when the installed phibble orders groups like dplyr (sorted keys). */
function phibbleSortsGroups(): bool
{
    $t = Phibble\DataFrame::fromRows([['g' => 'b'], ['g' => 'a']])->groupBy(['g']);

    return $t->summarise([])->column('g') === ['a', 'b'];
}

function panel(): Phibble\DataFrame
{
    return Phibble\DataFrame::fromCsv(fixturePath('panel.csv'));
}
