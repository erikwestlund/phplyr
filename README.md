# phplyr

A port of dplyr for [phibble](https://github.com/erikwestlund/phibble) tables. It runs on PHP 8.3 and later with method chains, and on PHP 8.5 with the pipe operator.

**Method chain (PHP 8.3+)**

```php
use Phibble\DataFrame;
use function Phplyr\{phplyr, desc, n, mean, min_rank};

$out = phplyr(DataFrame::fromCsv('sales.csv'))
    ->filter(fn ($r) => $r->price > 0)
    ->mutate(log_price: fn ($r) => log($r->price))
    ->group_by('brand')
    ->summarise(n: n(), avg: mean('price'))
    ->mutate(rank: min_rank(desc('avg')))
    ->arrange('rank')
    ->get();
```

**Pipe (PHP 8.5)**

```php
use Phibble\DataFrame;
use function Phplyr\{filter, mutate, group_by, summarise, arrange, desc, n, mean, min_rank};

$out = DataFrame::fromCsv('sales.csv')
    |> filter(fn ($r) => $r->price > 0)
    |> mutate(log_price: fn ($r) => log($r->price))
    |> group_by('brand')
    |> summarise(n: n(), avg: mean('price'))
    |> mutate(rank: min_rank(desc('avg')))
    |> arrange('rank');
```

The two give the same table. `phplyr($t)` starts a chain, each method runs the verb of that name with the same arguments, and `get()` returns the table. Verbs that return something other than a table, such as `pull()` or `lm()`, end the chain and return their result. `pipe($t, filter(...), group_by('brand'))` applies verbs in order and also works on every version.

Each verb returns a closure that takes one `Phibble\Contracts\Table`, and verbs delegate to the table, so the same pipeline runs on phibble's in-memory DataFrame, on a database table, and on phpolars' PolarsTable. Results follow dplyr 1.2 and tidyr 1.3. The test suite compares phplyr's output with R's; the reference scripts are in `tests/r/`.

Documentation: <https://erikwestlund.github.io/phplyr/>. Start with [getting started](https://erikwestlund.github.io/phplyr/getting-started.html) or [coming from dplyr](https://erikwestlund.github.io/phplyr/coming-from-dplyr.html).

## Installation

phplyr needs PHP 8.3 or later; the pipe operator needs PHP 8.5. It isn't on Packagist yet, so add the GitHub repositories for phplyr and phibble to your `composer.json`:

```json
"repositories": [
    {"type": "vcs", "url": "https://github.com/erikwestlund/phibble"},
    {"type": "vcs", "url": "https://github.com/erikwestlund/phplyr"}
]
```

Then:

```bash
composer require erikwestlund/phplyr:^0.3
```

Two extensions are optional:

- [php-stats](https://github.com/erikwestlund/php-stats) provides `lm()`, `glm()`, `lmer()`, and `glmer()`, and speeds up the descriptive helpers. Without it, the helpers run in PHP with the same results.
- [phpolars](https://github.com/erikwestlund/phpolars) provides the php_polars extension behind phibble's PolarsTable (`Phibble\Polars\PolarsTable`), a Polars-backed table that runs phplyr pipelines natively.

See [CHANGELOG.md](CHANGELOG.md) for what each release contains.
## Conventions

- **Columns are strings.** Write `'price'` where dplyr takes a bare name.
- **Row closures** receive `(Phibble\Row $r, Table $group)`, so `$r->price` or `$r['price']`. In a grouped table, `$group` is the row's group.
- **Vector helpers** such as `lag('x')`, `cumsum('x')`, `min_rank('x')` and `mean('x')` are evaluated once per group. A summary such as `mean('x')` used in `mutate()` is recycled within each group, as in R.
- **Helper arguments:** the first argument of a helper is its input column. A string there is a column name; it can also be `col('x')`, another helper, or a row closure. Later arguments are values, so a string there is a literal; wrap it in `col()` to use a column.
- **Dot-arguments** are written with a leading underscore: `.by` becomes `_by`, `.keep` becomes `_keep`, and so on: `mutate(z: ..., _by: 'g')`.
- **Positions** are 1-based, as in R: `pull(1)`, `pull(-1)`, `slice(1, 3)`.
- **NA is null.** Summaries return null when a value is missing, unless you pass `na_rm: true`.
- **Shadowed built-ins:** `count`, `min`, `max`, `round`, `abs`, `log`, `str_replace`, `str_pad`, `str_split` and a few others shadow PHP's built-ins in files that import them. Write `\count()` for the built-in.

## Verbs

| Verb | Example |
| --- | --- |
| `filter` | `filter(fn ($r) => $r->x > 0, between('y', 1, 5), _by: 'g')` |
| `select` | `select('id', starts_with('x'), except('tmp'))`, `select(['new' => 'old'], everything())` |
| `mutate` | `mutate(z: fn ($r) => $r->x * 2, prev: lag('x'), avg: mean('x'), drop_me: null)` |
| | Options: `_by`, `_keep: 'none'`, `_before`, `_after` |
| `transmute` | `transmute(z: fn ($r) => $r->x * 2)` |
| `rename` / `rename_with` | `rename(new: 'old')`, `rename_with(strtoupper(...), starts_with('x'))` |
| `relocate` | `relocate('score', _before: 'g')`, `relocate('g', _after: last_col())` |
| `arrange` | `arrange('a', desc('b'), desc(fn ($r) => $r->x * $r->y), _by_group: true)` |
| `group_by` / `ungroup` | `group_by('g', decade: fn ($r) => intdiv($r->year, 10) * 10, _add: true)` |
| `summarise` / `summarize` | `summarise(n: n(), avg: mean('x'), across(...), _by: 'g', _groups: 'drop')` |
| `reframe` | `reframe(q: fn ($g) => [...several values...])` |
| `count` / `tally` | `count('g', 'h', wt: 'w', sort: true, name: 'rows')`, `tally()` |
| `add_count` / `add_tally` | `add_count('g', name: 'size')` |
| `distinct` | `distinct('g')`, `distinct('g', _keep_all: true)` |
| `slice` | `slice(1, 3)`, `slice(-1)`. These are positions within each group. |
| `slice_head` / `slice_tail` | `slice_head(n: 3)`, `slice_tail(prop: 0.1)` |
| `slice_min` / `slice_max` | `slice_max('score', n: 2, with_ties: false)` |
| `slice_sample` | `slice_sample(n: 10, replace: true, weight_by: 'w')`. Seed with `mt_srand()`. |
| `head` | `head(6)`. Ignores groups. |
| `pull` | `pull('x')`, `pull()` (the last column), `pull('x', name: 'id')` |
| `bind_rows` / `bind_cols` | `bind_rows($b, _id: 'src')`, `bind_cols($b)` |
| `glimpse` | `glimpse()`. Prints a summary and passes the table on. |
| Joins | `left_join($y, by: ['id' => 'key'], suffix: ['.x', '.y'], keep:, na_matches:, multiple:, unmatched:, relationship:)`. Also `inner_join`, `right_join`, `full_join`, `semi_join`, `anti_join` and `cross_join`. |
| `rowwise` | `rowwise('id')`. Each row becomes its own group. |

| `filter_out` | `filter_out(fn ($r) => $r->status === 'test')`. Drops rows where every condition is true, keeping NA rows (dplyr 1.2). |
| Set operations | `union`, `union_all`, `intersect`, `setdiff`, `symdiff`, `setequal` |
| `rows_*` | `rows_insert`, `rows_append`, `rows_update`, `rows_patch`, `rows_upsert`, `rows_delete`, with `by:`, `conflict:` and `unmatched:` |

**Group utilities:** `group_cols()` (a selector), `group_vars`, `n_groups`, `group_size`, `group_indices`, `group_keys`, `group_split`, `group_map`, `group_modify`, `group_walk`.

## Vector helpers

- **Summaries:** `n`, `n_distinct`, `sum`, `mean`, `median`, `sd`, `variance`, `quantile(x, p)`, `iqr`, `min`, `max`, `first`, `last`, `nth(x, n)`, `any`, `all`.
- **Windows:** `lag`, `lead` (with `default:` and `order_by:`), `row_number`, `min_rank`, `dense_rank`, `percent_rank`, `cume_dist`, `ntile`, `cumsum`, `cumprod`, `cummin`, `cummax`, `cummean`, `cumall`, `cumany`, `consecutive_id`.
- **Conditionals and NA:** `if_else`, `case_when([cond, value], ..., default: ...)`, `replace_when`, `case_match`, `recode_values` / `replace_values` (cases or `from:` / `to:` lookup tables), `when_any` / `when_all`, `coalesce`, `na_if`, `between`, `near`.
- **Building blocks:** `col('x')`, `vec(fn ($r) => ...)`, and `->map(fn ($value, $r) => ...)` on any helper.

`sd` and `variance` use n - 1, and `quantile` uses R's type 7. The variance helper is called `variance()` because `var` is a reserved word in PHP. When the `php_stats` extension is loaded, the descriptive helpers use it; otherwise they fall back to pure PHP.

## tidyselect and across()

- **Selectors:** `starts_with`, `ends_with`, `contains`, `matches`, `num_range`, `everything`, `last_col`, `all_of`, `any_of`, and `where(fn ($values, $type, $name) => ...)`. `where` also takes a shortcut: `where('numeric')`.
- **Combining:** `except(...)` drops columns (dplyr's `-`). `any_selected` gives the union of selections and `all_selected` the intersection.
- **Where they work:** `select`, `relocate`, `rename_with`, `arrange`, `distinct`, `group_by`, `count` and `across`.

```php
phplyr($t)->group_by('g')->summarise(across(starts_with('x'), ['avg' => mean(...), 'sd' => sd(...)]));
phplyr($t)->mutate(across(where('numeric'), fn ($v) => $v * 2));  // applied to each value
phplyr($t)->mutate(across(['a', 'b'], lag(...)));                 // phplyr helpers by name
phplyr($t)->filter(if_any(['a', 'b'], fn ($v) => $v > 8));

// On PHP 8.5, the same with |>:
$t |> group_by('g') |> summarise(across(starts_with('x'), ['avg' => mean(...), 'sd' => sd(...)]));
```

What an `across()` function receives:

- **A phplyr helper** passed as a first-class callable, such as `mean(...)`, is called with each column's name.
- **In `summarise()`**, any other closure receives the column's values as a list.
- **In `mutate()`**, a closure receives one value at a time, unless its first parameter is typed `array`. Then it receives the whole column.

Names default to `{col}` for one function, or `{col}_{fn}` for a named list.

## tidyr

The tidyr verbs are pipe-able too, with tidyr's argument names. The test suite compares them with tidyr output from `tests/r/tidyr.R`.

| Verb | Example |
| --- | --- |
| `pivot_longer` | `pivot_longer(starts_with('wk'), names_to: 'week', values_to: 'rank', names_prefix: 'wk', values_drop_na: true)` |
| | `names_to` can list several names, split with `names_sep` or `names_pattern`. The name `'.value'` takes the value column's name from that part. |
| `pivot_wider` | `pivot_wider(names_from: 'key', values_from: ['val', 'w'], values_fill: 0, values_fn: fn ($v) => array_sum($v))` |
| | Also takes `id_cols`, `names_prefix`, `names_sep`, `names_glue: '{key}_{.value}'` and `names_sort`. |
| `complete` / `expand` | `complete('id', 'key', fill: ['n' => 0])`, `expand(nesting('g', 'label'), year: [2001, 2002])` |
| `crossing` / `expand_grid` | `crossing(x: [2, 1], y: ['a', 'b'])`. These return a new DataFrame. |
| `fill` | `fill('price', _direction: 'downup')`. Works within groups. |
| `drop_na` / `replace_na` | `drop_na('wk1')`, `replace_na(['wk1' => 0])` |
| `uncount` | `uncount('n', _id: 'copy')` |
| `unite` | `unite('date', 'year', 'month', sep: '-', remove: false, na_rm: true)` |
| `separate` | `separate('s', ['a', 'b'], sep: '-', extra: 'merge', fill: 'left', convert: true)` |
| `separate_wider_*` | `separate_wider_delim('s', '-', names: [...])`, `separate_wider_position('code', ['state' => 2, 1, 'n' => 2])`, `separate_wider_regex('code', ['l' => '[A-Z]+', 'd' => '\d+'])` |
| `separate_longer_*` | `separate_longer_delim('tags', ',')`, `separate_longer_position('code', 2)` |
| `nest` / `unnest` | `nest()` after `group_by('g')`, `nest(data: ['x', 'y'])`, `unnest('data')` |

## Expressions and databases

`col('price')->mul(1.1)->gt(100)` and helpers such as `mean('x')` build inspectable `Phibble\Expr` trees when their arguments are column names, literals, or other helpers. Verbs pass them to the table. The in-memory DataFrame evaluates them, and `Phibble\tbl($pdo, 'sales')` compiles them to SQL (SQLite, PostgreSQL, MySQL). `show_query()` returns the SQL and `collect()` runs it. A PHP closure makes a database table load its rows and continue in memory. `mutate(_keep: 'used' | 'unused')` works with expression arguments.

## Models

`lm('y ~ x + g')`, `glm('y ~ x', family: 'binomial')`, `lmer('y ~ x + (1 | g)')` and `glmer(...)` pass the table to php_stats' formula functions, so factor levels reach the model.

## Development

```sh
composer install
bin/test                        # uses .ext/libphp_stats.dylib when present
NO_EXT=1 bin/test               # pure-PHP fallback
PHP=/path/to/php83 bin/test      # before PHP 8.5, leaves out tests/Pipe (|>)
Rscript tests/r/reference.R     # regenerate the dplyr reference outputs
```

phibble is installed from GitHub through a VCS repository in composer.json. The lock file is resolved for PHP 8.3 (`config.platform`), so it installs on every supported version. After adding or changing a verb, run `bin/chain-docblock` to update the method chain's `@method` list.

The documentation site is in `docs/` (Quarto). `bin/render-docs` refreshes the example output and renders it; see `docs/development.qmd`.
