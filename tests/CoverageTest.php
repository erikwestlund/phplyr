<?php

declare(strict_types=1);

use Phibble\Frame;
use Phibble\Type;

use function Phplyr\{across, add_row, arrange, build_longer_spec, build_wider_spec, c_across, chop, col, cumsum, cur_column, cur_group, desc, extract, filter, full_seq, gather, group_by, group_data, group_nest, group_rows, group_trim, head, hoist, if_all, if_any, mean, mutate, n, nest_by, nest_join, order_by, pack, pivot_longer, pivot_longer_spec, pivot_wider, pivot_wider_spec, recode, rowwise, select, separate_rows, spread, starts_with, sum, summarise, top_frac, top_n, unchop, ungroup, unnest_longer, unnest_wider, unpack, with_groups};

function coverage(string $key): mixed
{
    static $json;
    $json ??= json_decode(file_get_contents(fixturePath('coverage-reference.json')), true);

    return $json[$key];
}

/** R's comparison: NA when either side is NA. */
function gtNa(?float $x, float $y): ?bool
{
    return $x === null ? null : $x > $y;
}

function p4(): Frame
{
    return panel() |> head(4);
}

describe('across(), if_any(), and if_all()', function () {
    it('names several functions with {.fn} and {.col}, and resolves helpers called on the values', function () {
        $out = p4() |> group_by('g') |> summarise(across(['value', 'score'], ['m' => fn ($v) => mean($v, na_rm: true), 's' => fn ($v) => sum($v)], names: '{.fn}_{.col}'));

        expectRowsClose($out->toRows(), coverage('across_fns'));
    });

    it('gives cur_column() inside across()', function () {
        $out = p4() |> mutate(across(['value', 'score'], fn ($v) => cur_column().' '.($v ?? 'NA')));

        expectRowsClose($out->toRows(), coverage('across_cur_column'));
        expect(fn () => cur_column())->toThrow(LogicException::class);
    });

    it('filters and mutates with if_any() and if_all(), with R NA logic', function () {
        expectRowsClose((p4() |> filter(if_any(['value', 'score'], fn ($v) => gtNa($v, 4))))->toRows(), coverage('if_any_filter'));
        expectRowsClose((p4() |> filter(if_all(['value', 'score'], fn ($v) => gtNa($v, 2))))->toRows(), coverage('if_all_filter'));
        $out = p4() |> mutate(a: if_any(['value', 'score'], fn ($v) => gtNa($v, 4)), b: if_all(['value', 'score'], fn ($v) => gtNa($v, 2)));
        expectRowsClose($out->toRows(), coverage('if_mutate'));
    });

    it('treats an empty selection as FALSE for if_any() and TRUE for if_all()', function () {
        $out = p4() |> mutate(a: if_any(starts_with('zz'), fn ($v) => $v > 4), b: if_all(starts_with('zz'), fn ($v) => $v > 2));
        expectRowsClose($out->toRows(), coverage('if_empty'));
        expectRowsClose((p4() |> summarise(across(starts_with('zz'), mean(...)), n: n()))->toRows(), coverage('across_empty'));
    });
});

describe('group helpers', function () {
    it('lists group rows and group data', function () {
        expect((panel() |> group_by('g') |> group_rows()))->toBe(coverage('group_rows'));
        $data = panel() |> group_by('g') |> group_data();
        expect($data->columns())->toBe(['g', '.rows']);
        expect($data->column('.rows'))->toBe(coverage('group_rows'));
    });

    it('gives each row its group key with cur_group()', function () {
        $out = panel() |> head(6) |> group_by('g') |> mutate(cg: cur_group()) |> ungroup();
        $out = $out->mutate(['cg' => array_map(fn ($k) => $k['g'], $out->column('cg'))]);

        expectRowsClose($out->toRows(), coverage('cur_group'));
    });

    it('sums across columns in a rowwise table with c_across()', function () {
        $out = panel() |> head(6) |> rowwise() |> mutate(tot: sum(c_across(['value', 'score']), na_rm: true)) |> ungroup();

        expectRowsClose($out->toRows(), coverage('c_across'));
    });

    it('nests groups with group_nest() and nest_by()', function () {
        $nested = panel() |> head(6) |> group_by('g') |> group_nest();
        $summary = $nested->mutate(['n' => array_map(fn ($d) => $d->nrow(), $nested->column('data')), 'cols' => array_map(fn ($d) => $d->ncol(), $nested->column('data'))]);
        expectRowsClose($summary->select(['g', 'n', 'cols'])->toRows(), coverage('group_nest_n'));

        $by = panel() |> head(6) |> nest_by('g');
        expect($by->isRowwise())->toBeTrue();
        expect($by->groups())->toBe(['g']);
        $counts = array_map(fn ($d) => $d->nrow(), $by->column('data'));
        expect(array_map(fn ($g, $n) => ['g' => $g, 'n' => $n], $by->column('g'), $counts))->toBe(coverage('nest_by_n'));
    });

    it('runs a verb under other groups with with_groups()', function () {
        $out = panel() |> head(3) |> with_groups('g', mutate(m: mean('score')));

        expectRowsClose($out->toRows(), coverage('with_groups'));
        expect($out->groups())->toBe([]);
    });

    it('drops unused factor levels from groups with group_trim()', function () {
        $t = Frame::fromColumns(['f' => ['a', 'b'], 'x' => [1, 2]])->asFactor('f', ['a', 'b', 'c'])->groupBy(['f'], false);
        expect(\count(group_rows()($t)))->toBe(3);
        expect(\count(group_rows()(group_trim()($t))))->toBe(2);
    });
});

describe('superseded dplyr verbs', function () {
    it('keeps the top rows in table order with top_n() and top_frac()', function () {
        expectRowsClose((panel() |> head(8) |> top_n(2, 'score'))->toRows(), coverage('top_n'));
        expectRowsClose((panel() |> head(8) |> group_by('g') |> top_n(-1, 'value') |> ungroup())->toRows(), coverage('top_n_neg'));
        expectRowsClose((panel() |> head(8) |> top_frac(0.25, 'score'))->toRows(), coverage('top_frac'));
    });

    it('recodes values by name', function () {
        $t = Frame::fromColumns(['x' => ['a', 'b', 'c', null], 'y' => ['x', 'y', 'z', 'x']]);
        expect((mutate(r: recode('x', a: 'A', b: 'B', _default: 'other'))($t))->column('r'))->toBe(coverage('recode'));
        expect(\array_slice((mutate(r: recode('y', x: 'X'))($t))->column('r'), 0, 3))->toBe(coverage('recode_keep'));
        expect((mutate(r: recode('x', ['a' => 1], _missing: 0, _default: -1))($t))->column('r'))->toBe([1, -1, -1, 0]);
    });

    it('evaluates a window function in another order with order_by()', function () {
        expectRowsClose((panel() |> head(5) |> mutate(r: order_by('year', cumsum('score'))))->toRows(), coverage('order_by'));
        expectRowsClose((panel() |> head(5) |> mutate(r: order_by(desc('year'), cumsum('score'))))->toRows(), coverage('order_by_desc'));
    });

    it('adds rows with add_row()', function () {
        $t = Frame::fromColumns(['x' => [1, 2], 'y' => ['a', 'b']]);
        expectRowsClose((add_row(x: 3, y: 'c', _before: 2)($t))->toRows(), coverage('add_row_before'));
        expectRowsClose((add_row(x: [4, 5])($t))->toRows(), coverage('add_row_many'));
        expect(fn () => add_row(z: 1)($t))->toThrow(InvalidArgumentException::class);
    });

    it('nests matching rows with nest_join()', function () {
        $out = Frame::fromColumns(['g' => ['a', 'a', 'b'], 'v' => [1, 2, 3]])
            |> nest_join(Frame::fromColumns(['g' => ['a', 'b', 'b', 'c'], 'w' => [10, 20, 30, 40]]), by: 'g', name: 'y');
        $summary = array_map(fn ($r) => ['g' => $r['g'], 'v' => $r['v'], 'n' => $r['y']->nrow(), 'w' => array_sum($r['y']->column('w'))], $out->toRows());
        expectRowsClose($summary, coverage('nest_join_n'));
        expect($out->column('y')[0]->columns())->toBe(['w']);

        $kept = Frame::fromColumns(['g' => ['a', 'd']]) |> nest_join(Frame::fromColumns(['g' => ['a', 'b'], 'w' => [1, 2]]), by: 'g', keep: true, name: 'm');
        expect($kept->column('m')[0]->columns())->toBe(['g', 'w']);
        expect($kept->column('m')[1]->nrow())->toBe(0);
    });
});

describe('pivot options and specs', function () {
    beforeEach(function () {
        $this->wide = Frame::fromCsv(fixturePath('wide.csv'));
        $this->long = Frame::fromCsv(fixturePath('long.csv'));
        $this->xy = $this->long |> filter(col('key')->ne('z'));
    });

    it('transforms names and values, and varies columns slowest', function () {
        $out = $this->wide |> pivot_longer(starts_with('wk'), names_to: 'week', names_prefix: 'wk', names_transform: intval(...), cols_vary: 'slowest');
        expectRowsClose($out->toRows(), coverage('longer_transform'));
        expect($out->types()['week'])->toBe(Type::Int);

        $out = $this->wide |> pivot_longer(starts_with('wk'), names_to: 'week', values_transform: fn ($x) => $x === null ? null : $x * 2, values_drop_na: true);
        expectRowsClose($out->toRows(), coverage('longer_values_transform'));
    });

    it('varies names slowest, summarises unused columns, and expands names and ids', function () {
        expectRowsClose(($this->xy |> pivot_wider(id_cols: 'id', names_from: 'key', values_from: ['val', 'w'], names_vary: 'slowest'))->toRows(), coverage('wider_vary'));
        expectRowsClose(($this->xy |> pivot_wider(id_cols: 'id', names_from: 'key', values_from: 'val', unused_fn: ['w' => array_sum(...)]))->toRows(), coverage('wider_unused'));

        $factorKey = $this->xy->asFactor('key', ['x', 'y', 'q']);
        expectRowsClose(($factorKey |> pivot_wider(id_cols: 'id', names_from: 'key', values_from: 'val', names_expand: true))->toRows(), coverage('wider_names_expand'));

        $factorId = $this->xy->mutate(['id' => array_map('strval', $this->xy->column('id'))])->asFactor('id', ['1', '2', '3', '4', '5']);
        $out = $factorId |> pivot_wider(id_cols: 'id', names_from: 'key', values_from: 'val', id_expand: true, values_fill: 0);
        $out = $out->mutate(['id' => array_map('intval', $out->column('id'))]);
        expectRowsClose($out->toRows(), coverage('wider_id_expand'));
    });

    it('builds specs and pivots with them', function () {
        expect(($this->long |> build_wider_spec(names_from: 'key', values_from: ['val', 'w']))->toRows())->toBe(coverage('wider_spec'));
        expect(($this->wide |> build_longer_spec(starts_with('wk'), names_to: 'week', names_prefix: 'wk'))->toRows())->toBe(coverage('longer_spec'));

        $spec = $this->xy |> build_wider_spec(names_from: 'key', values_from: 'val');
        expectRowsClose(($this->xy |> pivot_wider_spec($spec, id_cols: 'id'))->toRows(), coverage('pivot_wider_spec'));
        $spec = $this->wide |> build_longer_spec(starts_with('wk'), names_to: 'week');
        expectRowsClose(($this->wide |> pivot_longer_spec($spec))->toRows(), coverage('pivot_longer_spec'));
    });

    it('gathers and spreads', function () {
        expectRowsClose(($this->wide |> gather('week', 'rank', 'wk1', 'wk2', 'wk3'))->toRows(), coverage('gather'));
        expectRowsClose(($this->wide |> gather('week', 'rank', starts_with('wk'), na_rm: true))->toRows(), coverage('gather_na'));
        $kv = $this->xy |> select('id', 'key', 'val');
        expectRowsClose(($kv |> spread('key', 'val'))->toRows(), coverage('spread'));
        expectRowsClose(($kv |> spread('key', 'val', fill: 0))->toRows(), coverage('spread_fill'));
    });
});

describe('rectangling', function () {
    it('widens named and unnamed elements', function () {
        $t = Frame::fromColumns(['id' => [1, 2, 3, 4], 'x' => [['a' => 1, 'b' => 'u'], ['a' => 2], null, ['b' => 'w', 'c' => true]]]);
        expectRowsClose(($t |> unnest_wider('x'))->toRows(), coverage('unnest_wider'));

        $u = Frame::fromColumns(['id' => [1, 2], 'y' => [[1, 2], [3]]]);
        expectRowsClose(($u |> unnest_wider('y', names_sep: '_'))->toRows(), coverage('unnest_wider_sep'));
        expect(fn () => unnest_wider('y')($u))->toThrow(InvalidArgumentException::class, 'names_sep');
        $clash = Frame::fromColumns(['a' => [0], 'x' => [['a' => 1]]]);
        expect(fn () => unnest_wider('x')($clash))->toThrow(InvalidArgumentException::class, 'duplicate');
    });

    it('lengthens elements with indices', function () {
        $named = Frame::fromColumns(['id' => [1, 2], 'v' => [['a' => 1, 'b' => 2], ['c' => 3]]]);
        expectRowsClose(($named |> unnest_longer('v', values_to: 'val', indices_to: 'key'))->toRows(), coverage('unnest_longer_named'));

        $t = Frame::fromColumns(['id' => [1, 2, 3], 'v' => [[1, 2], 3, []]]);
        expectRowsClose(($t |> unnest_longer('v', indices_include: true, keep_empty: true))->toRows(), coverage('unnest_longer_keep'));

        $two = Frame::fromColumns(['id' => [1, 2], 'v' => [[1, 2], [3]], 'w' => [['a', 'b'], 'c']]);
        expectRowsClose(($two |> unnest_longer(['v', 'w']))->toRows(), coverage('unnest_longer_two'));
    });

    it('hoists components by name, position, and path', function () {
        $h = Frame::fromColumns(['id' => [1, 2], 'x' => [['a' => 1, 'b' => ['c' => 5, 'd' => 6], 'e' => 'k'], ['a' => 2, 'b' => ['c' => 7]]]]);
        $out = $h |> hoist('x', first: 1, bc: ['b', 'c'], e: 'e');
        expectRowsClose($out->select(['id', 'first', 'bc', 'e'])->toRows(), coverage('hoist'));
        expect($out->columns())->toBe(['id', 'first', 'bc', 'e', 'x']);
        expect($out->column('x'))->toBe([['b' => ['d' => 6]], ['b' => []]]);
        expect(($h |> hoist('x', bc: ['b', 'c'], _remove: false))->column('x'))->toBe($h->column('x'));
    });

    it('chops and unchops', function () {
        $t = Frame::fromColumns(['g' => [1, 2, 3], 'x' => [[1, 2], null, []]]);
        expectRowsClose(($t |> unchop('x', keep_empty: true))->toRows(), coverage('unchop'));

        $chopped = Frame::fromColumns(['g' => [1, 1, 2], 'x' => [1, 2, 3], 'y' => [4, 5, 6]]) |> chop(['x', 'y']);
        expect($chopped->column('x'))->toBe([[1, 2], [3]]);
        expectRowsClose(($chopped |> unchop(['x', 'y']))->toRows(), coverage('chop_unchop'));
    });

    it('packs and unpacks', function () {
        $t = Frame::fromColumns(['x' => [1, 2, 3], 'a' => [1, 2, 3], 'b' => ['p', 'q', 'r']]);
        $packed = $t |> pack(y: ['a', 'b']);
        expect($packed->columns())->toBe(['x', 'y']);
        expect($packed->column('y')[0])->toBe(['a' => 1, 'b' => 'p']);
        expectRowsClose(($packed |> unpack('y', names_sep: '_'))->toRows(), coverage('unpack'));
        expect(($packed |> unpack('y'))->toRows())->toBe($t->toRows());
    });
});

describe('other tidyr functions', function () {
    it('extracts regex groups', function () {
        expectRowsClose((Frame::fromColumns(['x' => ['a1-2', 'b3-4']]) |> extract('x', ['l', 'n'], '([a-z])(\d)', remove: false, convert: true))->toRows(), coverage('extract'));
        expectRowsClose((Frame::fromColumns(['x' => ['a1-b', 'c2-d', null]]) |> extract('x', ['l', 'n'], '([a-z])(\d)'))->toRows(), coverage('extract_na'));
    });

    it('separates rows on a regex', function () {
        $t = Frame::fromColumns(['x' => [1, 2], 'y' => ['1,2', '3'], 'z' => ['a b', 'c']]);
        expectRowsClose(($t |> separate_rows('y', 'z', convert: true))->toRows(), coverage('separate_rows'));
    });

    it('fills a sequence with full_seq()', function () {
        expect(full_seq([1, 5, 3], 1))->toBe(coverage('full_seq'));
        expect(full_seq([1.5, 2.25, 3], 0.75))->toEqual(array_map('floatval', coverage('full_seq_frac')));
        expect(fn () => full_seq([1, 2.5], 1))->toThrow(InvalidArgumentException::class);
    });
});
