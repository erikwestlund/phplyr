<?php

declare(strict_types=1);

/*
 * Every verb on Phibble\Polars\PolarsTable gives the same table as on
 * DataFrame: the same columns, types, grouping, and rows (floats to 1e-9).
 * Runs when the php_polars extension is loaded.
 */

use Phibble\Contracts\Table;
use Phibble\DataFrame;
use Phibble\Polars\PolarsTable;

use function Phplyr\{across, add_count, add_row, add_tally, anti_join, any_of, arrange, between, bind_cols, bind_rows, build_wider_spec, c_across, case_when, chop, closest, coalesce, col, complete, consecutive_id, count, cross_join, cume_dist, cummax, cummean, cumsum, cur_group, cur_group_id, dense_rank, desc, distinct, drop_na, ends_with, everything, expand, extract, fill, filter, filter_out, first, full_join, gather, group_by, group_data, group_modify, group_nest, head, hoist, if_all, if_any, if_else, inner_join, intersect, join_by, lag, last, lead, left_join, max, mean, median, min, min_rank, mutate, n, n_distinct, na_if, nest, nesting, nth, ntile, order_by, pack, percent_rank, pick, pipe, pivot_longer, pivot_wider, pivot_wider_spec, pull, recode, recode_values, reframe, relocate, rename, rename_with, replace_na, right_join, row_number, rows_update, rows_upsert, rowwise, sd, select, semi_join, separate, separate_longer_delim, separate_rows, separate_wider_delim, setdiff, slice, slice_head, slice_max, slice_min, slice_tail, spread, starts_with, str_detect, str_to_upper, sum, summarise, symdiff, tally, top_frac, top_n, transmute, unchop, uncount, ungroup, union, union_all, unite, unnest, unnest_longer, unnest_wider, unpack, variance, where, with_groups};

if (! extension_loaded('php_polars') || ! class_exists(PolarsTable::class)) {
    test('PolarsTable parity')->skip('php_polars is not loaded');

    return;
}

/**
 * Run $pipeline on the inputs as DataFrames and as PolarsTables and compare.
 * Results with list-columns of tables (nest(), group_nest()) stay DataFrames.
 */
function expectSameOnPolars(Closure $pipeline, array $inputs, string $class = PolarsTable::class): void
{
    $frame = $pipeline(...$inputs);
    $polars = $pipeline(...array_map(PolarsTable::fromTable(...), $inputs));
    if (! $frame instanceof Table) {
        expect($polars)->toEqual($frame);

        return;
    }
    expect($polars)->toBeInstanceOf($class);
    $a = $frame->collect();
    $b = $polars->collect();
    expect($b->columns())->toBe($a->columns());
    expect($b->groups())->toBe($a->groups());
    expect(array_map(fn ($t) => $t->value, $b->types()))->toBe(array_map(fn ($t) => $t->value, $a->types()));
    expectRowsClose($b->toRows(), $a->toRows());
}

function polarsData(): array
{
    static $data;

    return $data ??= [
        'panel' => panel(),
        'schools' => DataFrame::fromCsv(fixturePath('schools.csv')),
        'long' => DataFrame::fromCsv(fixturePath('long.csv')),
        'wide' => DataFrame::fromCsv(fixturePath('wide.csv')),
        'who' => DataFrame::fromCsv(fixturePath('who.csv')),
        'strings' => DataFrame::fromCsv(fixturePath('strings.csv')),
        'x' => DataFrame::fromCsv(fixturePath('join_x.csv')),
        'y' => DataFrame::fromCsv(fixturePath('join_y.csv')),
        'events' => DataFrame::fromCsv(fixturePath('events.csv')),
        'periods' => DataFrame::fromCsv(fixturePath('periods.csv')),
    ];
}

dataset('one table', fn () => [
    // Rows
    'filter expr' => ['panel', fn ($t) => pipe($t, filter(col('score')->gt(4), col('value')->isNa()->not()))],
    'filter closure' => ['panel', fn ($t) => pipe($t, filter(fn ($r) => $r->label !== 'y'))],
    'filter grouped' => ['panel', fn ($t) => pipe($t, group_by('g'), filter(col('score')->ge(mean('score'))))],
    'filter_out' => ['panel', fn ($t) => pipe($t, filter_out(col('label')->eq('x')))],
    'filter if_any' => ['panel', fn ($t) => pipe($t, filter(if_any(['value', 'score'], fn ($v) => $v !== null && $v > 5)))],
    'filter if_all' => ['panel', fn ($t) => pipe($t, filter(if_all(['value', 'score'], fn ($v) => $v !== null && $v > 2)))],
    'arrange' => ['panel', fn ($t) => pipe($t, arrange('g', desc('score'), 'id'))],
    'arrange by group' => ['panel', fn ($t) => pipe($t, group_by('g'), arrange(desc('value'), _by_group: true))],
    'distinct' => ['panel', fn ($t) => pipe($t, distinct('g', 'label'))],
    'distinct keep all' => ['panel', fn ($t) => pipe($t, distinct('label', _keep_all: true))],
    'slice' => ['panel', fn ($t) => pipe($t, slice(1, 3, 5))],
    'slice_head grouped' => ['panel', fn ($t) => pipe($t, group_by('g'), slice_head(n: 2))],
    'slice_tail' => ['panel', fn ($t) => pipe($t, slice_tail(n: 3))],
    'slice_min ties' => ['panel', fn ($t) => pipe($t, group_by('g'), slice_min('score', n: 1))],
    'slice_max prop' => ['panel', fn ($t) => pipe($t, slice_max('value', prop: 0.25))],
    'top_n' => ['panel', fn ($t) => pipe($t, group_by('g'), top_n(2, 'score'))],
    'head' => ['panel', fn ($t) => pipe($t, head(3))],
    'drop_na' => ['panel', fn ($t) => pipe($t, drop_na('value'))],
    // Columns
    'select helpers' => ['panel', fn ($t) => pipe($t, select(starts_with('s'), 'id', ends_with('l')))],
    'select where' => ['panel', fn ($t) => pipe($t, select(where(fn ($v) => is_numeric($v[0] ?? null))))],
    'select rename' => ['panel', fn ($t) => pipe($t, select('g', key: 'id'))],
    'rename' => ['panel', fn ($t) => pipe($t, rename(grp: 'g'))],
    'rename_with' => ['panel', fn ($t) => pipe($t, rename_with(strtoupper(...), starts_with('s')))],
    'relocate' => ['panel', fn ($t) => pipe($t, relocate('label', 'score', _before: 'g'))],
    'mutate expr' => ['panel', fn ($t) => pipe($t, mutate(v2: col('value')->mul(2), s: col('score')->add(col('year'))))],
    'mutate closure' => ['panel', fn ($t) => pipe($t, mutate(tag: fn ($r) => $r->g.$r->id))],
    'mutate grouped' => ['panel', fn ($t) => pipe($t, group_by('g'), mutate(m: mean('score'), d: col('score')->sub(mean('score')), k: n()))],
    'mutate keep used' => ['panel', fn ($t) => pipe($t, mutate(s2: col('score')->mul(2), _keep: 'used'))],
    'mutate before' => ['panel', fn ($t) => pipe($t, mutate(one: 1, _before: 'g'))],
    'mutate by' => ['panel', fn ($t) => pipe($t, mutate(m: max('score'), _by: 'label'))],
    'transmute' => ['panel', fn ($t) => pipe($t, transmute('id', half: col('score')->div(2)))],
    'if_else and case_when' => ['panel', fn ($t) => pipe(
        $t,
        mutate(
        big: if_else(col('score')->gt(5), 'big', 'small'),
        band: case_when([col('score')->lt(3), 'low'], [col('score')->lt(7), 'mid'], default: 'high'),
    ),
    )],
    'coalesce na_if between' => ['panel', fn ($t) => pipe($t, mutate(v: coalesce('value', 0), s: na_if('score', 3), b: between('year', 2001, 2002)))],
    'recode_values' => ['panel', fn ($t) => pipe($t, mutate(l: recode_values('label', ['x', 'ex'], ['y', 'why'], default: 'other')))],
    'windows' => ['panel', fn ($t) => pipe(
        $t,
        group_by('g'),
        mutate(
        prev: lag('score'), next: lead('score'), rn: row_number(), mr: min_rank('score'), dr: dense_rank(desc('score')),
        pr: percent_rank('score'), cd: cume_dist('score'), nt: ntile('score', 2), cs: cumsum('score'), cm: cummax('score'), cmean: cummean('score'),
    ),
    )],
    'lag order_by' => ['panel', fn ($t) => pipe($t, group_by('g'), mutate(prev: lag('score', order_by: 'year')))],
    'consecutive_id' => ['panel', fn ($t) => pipe($t, mutate(run: consecutive_id('label')))],
    'across mutate' => ['panel', fn ($t) => pipe($t, mutate(across(['value', 'score'], fn ($v) => $v === null ? null : $v * 10)))],
    'across helpers' => ['panel', fn ($t) => pipe($t, group_by('g'), mutate(across(['score', 'year'], [min(...), max(...)])))],
    'str helpers' => ['panel', fn ($t) => pipe($t, mutate(up: str_to_upper('label'), hasx: str_detect('label', 'x')))],
    'cur_group_id' => ['panel', fn ($t) => pipe($t, group_by('g'), mutate(gid: cur_group_id()))],
    // Summaries
    'summarise' => ['panel', fn ($t) => pipe($t, group_by('g'), summarise(n: n(), s: sum('score'), m: mean('value', na_rm: true), md: median('score'), sd: sd('score'), v: variance('score')))],
    'summarise first last nth' => ['panel', fn ($t) => pipe($t, group_by('g'), summarise(f: first('score'), l: last('score'), second: nth('score', 2), nd: n_distinct('label')))],
    'summarise closure' => ['panel', fn ($t) => pipe($t, group_by('g'), summarise(labels: fn ($g) => implode(',', $g->column('label'))))],
    'summarise groups keep' => ['panel', fn ($t) => pipe($t, group_by('g', 'label'), summarise(n: n(), _groups: 'keep'))],
    'summarise by' => ['panel', fn ($t) => pipe($t, summarise(n: n(), _by: ['g', 'label']))],
    'summarise across' => ['schools', fn ($t) => pipe($t, group_by('school'), summarise(across(['score', 'prior_score'], ['mean' => mean(...), 'sd' => sd(...)])))],
    'summarise pick' => ['panel', fn ($t) => pipe($t, group_by('g'), summarise(rows: fn ($g) => pick('id', 'score')($g)->nrow()))],
    'reframe' => ['panel', fn ($t) => pipe($t, group_by('g'), reframe(score: fn ($g) => array_unique($g->column('score'))))],
    'count' => ['panel', fn ($t) => pipe($t, count('g', 'label', sort: true))],
    'count wt' => ['panel', fn ($t) => pipe($t, count('g', wt: 'score'))],
    'tally' => ['panel', fn ($t) => pipe($t, group_by('label'), tally())],
    'add_count' => ['panel', fn ($t) => pipe($t, add_count('g'))],
    'add_tally' => ['panel', fn ($t) => pipe($t, group_by('g'), add_tally())],
    'rowwise' => ['panel', fn ($t) => pipe($t, rowwise('id'), mutate(big: fn ($r) => \max($r->score, $r->year % 10)))],
    'ungroup' => ['panel', fn ($t) => pipe($t, group_by('g'), mutate(n: n()), ungroup())],
    'group_modify' => ['panel', fn ($t) => pipe($t, group_by('g'), group_modify(fn ($d) => pipe($d, head(1))))],
    'pull' => ['panel', fn ($t) => pipe($t, arrange('id'), pull('score'))],
    'add_row' => ['panel', fn ($t) => pipe($t, head(2), add_row(id: 99, g: 'z', _before: 1))],
    'big pipeline' => ['schools', fn ($t) => pipe(
        $t,
        filter(col('study_hours')->gt(3)),
        mutate(gain: col('score')->sub(col('prior_score'))),
        group_by('school'),
        summarise(n: n(), gain: mean('gain'), best: max('score')),
        mutate(rank: min_rank(desc('gain'))),
        arrange('rank'),
    )],
    // tidyr
    'pivot_longer' => ['wide', fn ($t) => pipe($t, pivot_longer(starts_with('wk'), names_to: 'week', values_to: 'rank', values_drop_na: true))],
    'pivot_longer sep' => ['who', fn ($t) => pipe($t, pivot_longer(starts_with('new'), names_to: ['diag', 'sex_age'], names_prefix: 'new_', names_sep: '_'))],
    'pivot_longer slowest' => ['wide', fn ($t) => pipe($t, pivot_longer(starts_with('wk'), names_to: 'week', cols_vary: 'slowest'))],
    'pivot_wider' => ['long', fn ($t) => pipe($t, filter(col('key')->ne('z')), pivot_wider(id_cols: 'id', names_from: 'key', values_from: 'val', values_fill: 0))],
    'pivot_wider multi' => ['long', fn ($t) => pipe($t, filter(col('key')->ne('z')), pivot_wider(names_from: 'key', values_from: ['val', 'w'], names_vary: 'slowest'))],
    'pivot_wider values_fn' => ['long', fn ($t) => pipe($t, pivot_wider(id_cols: 'id', names_from: 'key', values_from: 'val', values_fn: array_sum(...)))],
    'complete' => ['long', fn ($t) => pipe($t, select('id', 'key', 'w'), complete('id', 'key', fill: ['w' => 0]))],
    'expand nesting' => ['panel', fn ($t) => pipe($t, expand(nesting('g', 'label'), 'year'))],
    'fill' => ['panel', fn ($t) => pipe($t, group_by('g'), fill('value', _direction: 'downup'))],
    'replace_na' => ['panel', fn ($t) => pipe($t, replace_na(['value' => 0.0]))],
    'uncount' => ['strings', fn ($t) => pipe($t, select('id', 'n'), uncount('n', _id: 'copy'))],
    'unite' => ['panel', fn ($t) => pipe($t, unite('gl', 'g', 'label', sep: ':'))],
    'separate' => ['strings', fn ($t) => pipe($t, select('id', 's'), separate('s', ['a', 'b'], sep: '-', extra: 'drop', fill: 'right'))],
    'separate_wider_delim' => ['strings', fn ($t) => pipe($t, select('id', 's'), separate_wider_delim('s', '-', names_sep: '_', too_few: 'align_start'))],
    'separate_longer_delim' => ['strings', fn ($t) => pipe($t, select('id', 'tags'), separate_longer_delim('tags', ','))],
    'separate_rows' => ['strings', fn ($t) => pipe($t, select('id', 'tags'), separate_rows('tags', sep: ','))],
    'extract' => ['strings', fn ($t) => pipe($t, select('id', 'code'), extract('code', ['letters', 'digits'], '([A-Z]+)(\d+)', convert: true))],
    'nest' => ['panel', fn ($t) => pipe($t, nest(data: ['year', 'value', 'score']), mutate(n: fn ($r) => $r->data->nrow()), select('g', 'n')), DataFrame::class],
    'group_nest sizes' => ['panel', fn ($t) => pipe($t, group_by('g'), group_nest(), mutate(n: fn ($r) => $r->data->nrow()), select('g', 'n')), DataFrame::class],
    'unnest' => ['panel', fn ($t) => pipe(PolarsTable::fromTable($t->collect()->nest(['year', 'value', 'score'], 'data')->mutate(['data' => array_map(fn ($d) => $d->toRows(), $t->collect()->nest(['year', 'value', 'score'], 'data')->column('data'))])), unnest('data'))],
    // Added after 0.1.0
    'cur_group' => ['panel', fn ($t) => pipe($t, group_by('g'), mutate(cg: cur_group()), mutate(cg: fn ($r) => $r->cg['g']))],
    'c_across' => ['panel', fn ($t) => pipe($t, rowwise(), mutate(tot: sum(c_across(['value', 'score']), na_rm: true)))],
    'order_by' => ['panel', fn ($t) => pipe($t, mutate(r: order_by('year', cumsum('score'))))],
    'with_groups' => ['panel', fn ($t) => pipe($t, with_groups('g', mutate(m: mean('score'))))],
    'recode' => ['panel', fn ($t) => pipe($t, mutate(l: recode('label', x: 'ex', _default: 'other')))],
    'group_data' => ['panel', fn ($t) => pipe($t, group_by('g'), group_data())],
    'top_frac' => ['panel', fn ($t) => pipe($t, top_frac(0.25, 'score'))],
    'pivot names_transform' => ['wide', fn ($t) => pipe($t, pivot_longer(starts_with('wk'), names_to: 'week', names_prefix: 'wk', names_transform: intval(...)))],
    'pivot unused_fn' => ['long', fn ($t) => pipe($t, filter(col('key')->ne('z')), pivot_wider(id_cols: 'id', names_from: 'key', values_from: 'val', unused_fn: ['w' => array_sum(...)]))],
    'pivot id_expand' => ['long', fn ($t) => pipe($t, filter(col('key')->ne('z')), pivot_wider(id_cols: 'id', names_from: 'key', values_from: 'val', id_expand: true))],
    'pivot specs' => ['long', fn ($t) => pipe($t, filter(col('key')->ne('z')), pivot_wider_spec(pipe($t, filter(col('key')->ne('z')), build_wider_spec(names_from: 'key', values_from: 'val')), id_cols: 'id'))],
    'gather' => ['wide', fn ($t) => pipe($t, gather('week', 'rank', starts_with('wk'), na_rm: true))],
    'spread' => ['long', fn ($t) => pipe($t, filter(col('key')->ne('z')), select('id', 'key', 'val'), spread('key', 'val', fill: 0))],
    'chop unchop' => ['panel', fn ($t) => pipe($t, select('g', 'score', 'year'), chop(['score', 'year']), unchop(['score', 'year']))],
    'chop' => ['panel', fn ($t) => pipe($t, select('g', 'score'), chop('score'))],
    'pack unpack' => ['panel', fn ($t) => pipe($t, pack(y: ['value', 'score']), unpack('y', names_sep: '_'))],
    'unnest_wider' => ['panel', fn ($t) => pipe($t, select('id', 'g', 'score'), mutate(m: fn ($r) => ['s' => $r->score, 'g' => $r->g]), unnest_wider('m', names_sep: '.'))],
    'unnest_longer' => ['panel', fn ($t) => pipe($t, select('id', 'score'), mutate(m: fn ($r) => range(1, $r->score % 3 + 1)), unnest_longer('m', indices_include: true))],
    'hoist' => ['panel', fn ($t) => pipe($t, select('id', 'g', 'score'), mutate(m: fn ($r) => ['s' => $r->score, 'g' => $r->g]), hoist('m', s: 's'))],
]);

// PolarsTable stores named lists as structs, which would give every element
// every name. Since phibble 0.2, fromTable() refuses such list-columns, and
// these pipelines stay on DataFrame.
it('refuses named lists with different names on PolarsTable', function () {
    $t = DataFrame::fromColumns(['id' => [1, 2, 3], 'x' => [['a' => 1, 'b' => 2], ['a' => 3], ['c' => 4]]]);
    expect(fn () => PolarsTable::fromTable($t))->toThrow(InvalidArgumentException::class, 'List-column `x`');
});

it('gives the same result on PolarsTable for', function (string $input, Closure $pipeline, string $class = PolarsTable::class) {
    expectSameOnPolars($pipeline, [polarsData()[$input]], $class);
})->with('one table');

dataset('two tables', fn () => [
    'inner_join' => [fn ($x, $y) => pipe($x, inner_join($y, by: ['id' => 'key'], relationship: 'many-to-many'))],
    'left_join suffix' => [fn ($x, $y) => pipe($x, left_join($y, by: ['id' => 'key'], suffix: ['_l', '_r'], relationship: 'many-to-many'))],
    'right_join' => [fn ($x, $y) => pipe($x, right_join($y, by: 'k'))],
    'full_join' => [fn ($x, $y) => pipe($x, full_join($y, by: ['id' => 'key', 'k']))],
    'semi_join' => [fn ($x, $y) => pipe($x, semi_join($y, by: 'k'))],
    'anti_join' => [fn ($x, $y) => pipe($x, anti_join($y, by: 'k'))],
    'cross_join' => [fn ($x, $y) => pipe($x, head(2), cross_join(pipe($y, head(2))))],
    'join_by inequality' => [fn ($x, $y) => pipe($x, inner_join($y, by: join_by(col('id')->lt(col('key')))))],
    'bind_rows' => [fn ($x, $y) => pipe($x, bind_rows($y, _id: 'src'))],
    'bind_cols' => [fn ($x, $y) => pipe($x, select('id'), bind_cols(pipe($y, select('w'))))],
    'union' => [fn ($x, $y) => pipe($x, select('k'), union(pipe($y, select('k'))))],
    'union_all' => [fn ($x, $y) => pipe($x, select('k'), union_all(pipe($y, select('k'))))],
    'intersect' => [fn ($x, $y) => pipe($x, select('k'), intersect(pipe($y, select('k'))))],
    'setdiff' => [fn ($x, $y) => pipe($x, select('k'), setdiff(pipe($y, select('k'))))],
    'symdiff' => [fn ($x, $y) => pipe($x, select('k'), symdiff(pipe($y, select('k'))))],
    'rows_update' => [fn ($x, $y) => pipe($x, rows_update(pipe($y, filter(col('k')->eq('b')), select('k', id: 'key'), distinct('id', _keep_all: true)), by: 'id'))],
    'rows_upsert' => [fn ($x, $y) => pipe($x, select('id', 'k'), rows_upsert(pipe($y, select('k', id: 'key'), distinct('id', _keep_all: true)), by: 'id'))],
]);

it('joins and binds the same on PolarsTable with', function (Closure $pipeline) {
    expectSameOnPolars($pipeline, [polarsData()['x'], polarsData()['y']]);
})->with('two tables');

it('runs rolling and overlap joins the same on PolarsTable', function () {
    $d = polarsData();
    expectSameOnPolars(fn ($e, $p) => pipe($e, left_join($p, by: join_by('id', closest(col('t')->ge(col('start')))))), [$d['events'], $d['periods']]);
    expectSameOnPolars(fn ($e, $p) => pipe($e, inner_join($p, by: join_by('id', between('t', 'start', 'end')))), [$d['events'], $d['periods']]);
});

it('runs native Expr pipelines without falling back to DataFrame', function () {
    $before = PolarsTable::$fallbacks;
    $out = pipe(
        PolarsTable::fromTable(polarsData()['schools']),
        filter(col('study_hours')->gt(3)),
        mutate(gain: col('score')->sub(col('prior_score'))),
        group_by('school'),
        summarise(n: n(), gain: mean('gain'), best: max('score')),
        mutate(rank: min_rank(desc('gain'))),
        arrange('rank'),
    );
    $out->collect();

    expect(PolarsTable::$fallbacks - $before)->toBe(0);
});
