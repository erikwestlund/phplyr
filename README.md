# phplyr

A dplyr-style grammar for [phibble](https://github.com/erikwestlund/phibble) tables, built for the PHP 8.5 pipe operator.

```php
use Phibble\Frame;
use function Phplyr\{filter, mutate, group_by, summarise, arrange, desc, n, mean};

$out = Frame::fromCsv('sales.csv')
    |> filter(fn ($r) => $r->price > 0)
    |> mutate(log_price: fn ($r) => log($r->price))
    |> group_by('brand')
    |> summarise(n: n(), avg: mean('price'))
    |> arrange(desc('avg'));
```

Each verb returns a closure that takes one `Phibble\Contracts\Table`, and verbs delegate to the table. Any backend that implements the contract works unchanged, including the in-memory Frame and later Polars or SQL tables.

## Verbs

| Verb | Example |
| --- | --- |
| `filter` | `filter(fn ($r) => $r->x > 0, fn ($r) => $r->y !== null)`. All predicates must be true, and null counts as false. |
| `select` | `select('a', 'b')` |
| `mutate` | `mutate(z: fn ($r) => $r->x * 2, id: [1, 2, 3], src: 'pos')` |
| `rename` | `rename(new_name: 'old_name')` |
| `arrange` | `arrange('a', desc('b'))` |
| `group_by` / `ungroup` | `group_by('brand', 'store')` |
| `summarise` / `summarize` | `summarise(n: n(), avg: mean('x'), top: fn ($g) => ...)` |
| `count` | `count('brand', sort: true, name: 'rows')` |
| `distinct` | `distinct('brand')` |
| `slice_head` | `slice_head(3)`. Takes the first rows of each group. |
| `head` / `slice` | `head(6)`, `slice(10, 5)`. These ignore groups. |
| `pull` | `pull('x')`, `pull()` (the last column), `pull(0)` |

Row closures receive `(Phibble\Row $r, Table $group)`. In a grouped table, `$group` is the row's group, so grouped mutate and filter work:

```php
$t |> group_by('school')
   |> mutate(centered: fn ($r, $g) => $r->score - (mean('score'))($g));
```

## Summary helpers

`n()`, `n_distinct(col)`, `sum`, `mean`, `median`, `sd`, `variance`, `quantile(col, p)`, `min`, `max`, `first`, `last`.

- Missing values follow R: any null makes the result null unless you pass `na_rm: true`.
- `sd` and `variance` use n - 1. `quantile` uses R's type 7.
- When the `php_stats` extension is loaded, the helpers use `stats_mean`, `stats_sd`, `stats_var`, `stats_median` and `stats_quantile`. Without it, they fall back to pure PHP.
- The variance helper is called `variance()` because `var` is a reserved word in PHP.

Inside a file that imports them, `count`, `min` and `max` shadow PHP's built-ins. Write `\count()` when you need the built-in.

## Models

`lm('y ~ x + g')` and `glm('y ~ x', family: 'binomial')` are thin adapters that pass `$table->toColumns()` to php_stats' `stats_lm` / `stats_glm`. They need a php_stats version that provides those functions.

## Development

```sh
composer install
bin/test                        # uses .ext/libphp_stats.dylib when present
NO_EXT=1 bin/test               # pure-PHP fallback
PHP=/path/to/php85 bin/test
Rscript tests/r/reference.R     # regenerate the dplyr reference outputs
```

phibble is installed from GitHub (`dev-main`) through a VCS repository in composer.json.
