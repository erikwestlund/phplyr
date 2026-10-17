<?php

declare(strict_types=1);

use Phplyr\Stats;

use function Phplyr\{across, add_count, add_tally, any_of, arrange, between, case_when, coalesce, col, consecutive_id, contains, count, cumall, cumany, cume_dist, cummax, cummean, cummin, cumsum, dense_rank, desc, distinct, everything, except, filter, group_by, group_indices, group_keys, group_size, if_all, if_any, if_else, lag, last_col, lead, matches, max, mean, min, min_rank, mutate, n, n_groups, na_if, near, ntile, percent_rank, pipe, pull, quantile, reframe, relocate, rename_with, row_number, select, slice, slice_head, slice_max, slice_min, slice_tail, starts_with, summarise, tally, transmute, ungroup, where};

dataset('window', [
    'windows' => fn () => pipe(
        panel(),
        group_by('g'),
        mutate(
            lag1: lag('value'), lead1: lead('value'), lag2: lag('score', 2, default: 0),
            lag_by_year: lag('score', order_by: 'year'),
            rn: row_number(), rn_score: row_number('score'),
            mr: min_rank('score'), mr_desc: min_rank(desc('score')), dr: dense_rank('score'),
            pr: percent_rank('score'), cd: cume_dist('score'), nt: ntile('score', 3),
            cs: cumsum('value'), cmax: cummax('score'), cmin: cummin('score'), cmean: cummean('score'),
            call: cumall(fn ($r) => $r->score > 2), cany: cumany(fn ($r) => $r->score > 6),
        ),
        ungroup(),
        select(except('year', 'label')),
    ),
    'ntile_all' => fn () => pipe(
        panel(),
        mutate(nt5: ntile('score', 5), nt_rows: ntile(n: 5)),
        select('id', 'nt5', 'nt_rows'),
    ),
    'vectors' => fn () => pipe(
        panel(),
        mutate(
            ie: if_else(fn ($r) => $r->score > 5, 'hi', 'lo'),
            ie_na: if_else(fn ($r) => $r->value === null ? null : $r->value > 4, 'big', 'small', missing: 'none'),
            cw: case_when([fn ($r) => $r->score < 3, 'low'], [fn ($r) => $r->score < 7, 'mid'], default: 'high'),
            co: coalesce('value', 0),
            nai: na_if('score', 4),
            bt: between('score', 3, 6),
            nr: near('value', 4.5),
            cid: consecutive_id('label'),
            grp_mean: mean('score'), grp_n: n(),
        ),
        select('id', 'ie', 'ie_na', 'cw', 'co', 'nai', 'bt', 'nr', 'cid', 'grp_mean', 'grp_n'),
    ),
    'filter_group_max' => fn () => pipe(
        panel(),
        group_by('g'),
        filter(fn ($r, $g) => $r->score === max('score')($g)),
        ungroup(),
        select('id', 'g', 'score'),
    ),
    'filter_between' => fn () => pipe(panel(), filter(between('score', 2, 4), fn ($r) => $r->value !== null), select('id')),
    'filter_if_any' => fn () => pipe(panel(), filter(if_any(['value', 'score'], fn ($v) => $v === null ? null : $v > 8)), select('id')),
    'filter_if_all' => fn () => pipe(panel(), filter(if_all(['value', 'score'], fn ($v) => $v === null ? null : $v > 3)), select('id')),
    'filter_by' => fn () => pipe(panel(), filter(fn ($r, $g) => $r->score > mean('score')($g), _by: 'g'), select('id')),
    'across_mean' => fn () => pipe(
        panel(),
        group_by('g'),
        summarise(across(['value', 'score'], fn (array $x) => Stats::mean($x, naRm: true))),
    ),
    'across_list' => fn () => pipe(
        panel(),
        group_by('g'),
        summarise(across(['value', 'score'], [
            'avg' => fn (array $x) => Stats::mean($x, naRm: true),
            'top' => fn (array $x) => Stats::max($x, naRm: true),
        ])),
    ),
    'across_names' => fn () => pipe(
        panel(),
        group_by('g'),
        summarise(across(starts_with('sc'), ['lo' => min(...), 'hi' => max(...)], names: '{fn}_{col}')),
    ),
    'across_mutate' => fn () => pipe(
        panel(),
        mutate(across(['value', 'score'], fn ($x) => $x === null ? null : $x * 2)),
        select('id', 'value', 'score'),
    ),
    'across_mutate_window' => fn () => pipe(
        panel(),
        group_by('g'),
        mutate(across(['score', 'year'], lag(...))),
        ungroup(),
        select('id', 'score', 'year'),
    ),
    'transmute' => fn () => pipe(panel(), group_by('g'), transmute(double: fn ($r) => $r->score * 2, id: col('id')), ungroup()),
    'keep_none' => fn () => pipe(panel(), mutate(z: fn ($r) => $r->score * 2, score: fn ($r) => $r->score + 1, _keep: 'none')),
    'add_count' => fn () => pipe(panel(), add_count('g', name: 'size'), select('id', 'size')),
    'tally' => fn () => pipe(panel(), group_by('g'), tally()),
    'count_wt' => fn () => pipe(panel(), count('g', wt: 'score')),
    'add_tally' => fn () => pipe(panel(), group_by('label'), add_tally(), ungroup(), select('id', 'n')),
    'slice_min' => fn () => pipe(panel(), group_by('g'), slice_min('score', n: 1), ungroup(), select('id')),
    'slice_min_noties' => fn () => pipe(panel(), group_by('g'), slice_min('score', n: 1, with_ties: false), ungroup(), select('id')),
    'slice_max' => fn () => pipe(panel(), group_by('g'), slice_max('value', n: 2), ungroup(), select('id')),
    'slice_max_prop' => fn () => pipe(panel(), slice_max('score', prop: 0.25), select('id')),
    'slice_tail' => fn () => pipe(panel(), group_by('g'), slice_tail(n: 2), ungroup(), select('id')),
    'slice_pos' => fn () => pipe(panel(), group_by('g'), slice(1, 3), ungroup(), select('id')),
    'slice_neg' => fn () => pipe(panel(), group_by('g'), slice(-1), ungroup(), select('id')),
    'slice_head_by' => fn () => pipe(panel(), slice_head(n: 1, _by: 'g'), select('id')),
    'distinct_keep_all' => fn () => pipe(panel(), distinct('label', _keep_all: true), select('id', 'label')),
    'distinct_computed' => fn () => pipe(panel(), distinct(hi: fn ($r) => $r->score > 5), arrange('hi')),
    'reframe' => fn () => pipe(
        panel(),
        group_by('g'),
        reframe(p: [0.25, 0.75], q: fn ($g) => [quantile('score', 0.25)($g), quantile('score', 0.75)($g)]),
    ),
    'summarise_by' => fn () => pipe(panel(), summarise(m: mean('score'), n: n(), _by: 'g')),
    'arrange_expr' => fn () => pipe(panel(), arrange(desc(fn ($r) => $r->score * 2), 'id'), select('id')),
    'arrange_by_group' => fn () => pipe(panel(), group_by('g'), arrange(desc('score'), _by_group: true), ungroup(), select('id')),
]);

it('matches dplyr', function (Closure $pipeline) {
    $key = $this->dataName();
    expectRowsClose($pipeline()->toRows(), reference(trim(explode(' ', $key)[1] ?? $key, '"()')));
})->with('window');

it('matches dplyr on output that depends on sorted group keys', function () {
    expectRowsClose((pipe(panel(), group_by('g', early: fn ($r) => $r->year < 2003), summarise(n: n(), _groups: 'drop')))->toRows(), reference('group_computed'));
    expectRowsClose((pipe(panel(), group_by('g', 'label'), group_keys()))->toRows(), reference('group_keys'));
    expectRowsClose((pipe(panel(), count('g', 'label')))->toRows(), reference('count_multi'));
})->skip(fn () => ! phibbleSortsGroups(), 'phibble orders groups by first appearance (contract v0)');

it('selects columns like tidyselect', function () {
    $p = panel();
    expect((pipe($p, select(starts_with('s'))))->columns())->toBe(referenceNames('select_starts'));
    expect((pipe($p, select(except('id', 'label'))))->columns())->toBe(referenceNames('select_minus'));
    expect((pipe($p, select(where('numeric'))))->columns())->toBe(referenceNames('select_where'));
    expect((pipe($p, select(['ident' => 'id'], everything())))->columns())->toBe(referenceNames('select_rename'));
    expect((pipe($p, select(last_col(1), last_col())))->columns())->toBe(referenceNames('select_last'));
    expect((pipe($p, select(contains('E'))))->columns())->toBe(referenceNames('select_contains'));
    expect((pipe($p, select(matches('^(id|g)$'))))->columns())->toBe(referenceNames('select_matches'));
    expect((pipe($p, select(any_of(['score', 'nope', 'id']))))->columns())->toBe(referenceNames('select_any_of'));
    expect((pipe($p, rename_with(strtoupper(...), starts_with('s'))))->columns())->toBe(referenceNames('rename_with'));
    expect((pipe($p, relocate('score', _before: 'g')))->columns())->toBe(referenceNames('relocate_before'));
    expect((pipe($p, relocate('g', 'id', _after: last_col())))->columns())->toBe(referenceNames('relocate_after'));
    expect((pipe($p, relocate('label')))->columns())->toBe(referenceNames('relocate_front'));
    expect((pipe($p, mutate(z: fn ($r) => $r->score * 2, _before: 'g')))->columns())->toBe(referenceNames('mutate_before'));
    expect((pipe($p, mutate(z: fn ($r) => $r->score * 2, w: 1, _after: 'id')))->columns())->toBe(referenceNames('mutate_after'));
    expect((pipe($p, mutate(label: null, year: null)))->columns())->toBe(referenceNames('mutate_drop'));
});

it('reports groups like dplyr', function () {
    $byLabel = pipe(panel(), group_by('label'));
    expect(pipe($byLabel, group_size()))->toBe(reference('group_size'));
    expect(pipe($byLabel, group_indices()))->toBe(reference('group_indices'));
    expect(pipe($byLabel, n_groups()))->toBe(reference('n_groups'));
    expect(pipe(panel(), pull('score', name: 'id')))->toBe(reference('pull_named'));
});
