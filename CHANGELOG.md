# Changelog

All notable changes to phplyr are recorded here. Versions follow semantic versioning; before 1.0, minor versions may change behavior.

## Unreleased

phplyr runs on PHP 8.3 and later. On 8.3 and 8.4, pipelines are method chains; on 8.5, the pipe operator works as before.

### Added

- `phplyr($t)` starts a method chain: `phplyr($t)->filter(...)->group_by('g')->summarise(...)->get()`. Each method runs the phplyr verb of that name with the same arguments. A verb that returns a table continues the chain, and any other result, such as `pull()`'s values or `lm()`'s model, is returned as it is. `get()` returns the table, `->pipe($closure)` applies a closure of your own, and `echo` prints the table. Generated `@method` lines give IDEs the verbs and their parameters.
- `pipe($t, filter(...), head(5))` applies callables in order, the same as `$t |> filter(...) |> head(5)`.

### Changed

- Composer allows PHP `^8.3` and requires symfony/polyfill-php84. CI tests PHP 8.3, 8.4, and 8.5.
- The test suite calls verbs through `pipe()` so it runs on every version. Tests that use `|>` are in `tests/Pipe/`, which runs on PHP 8.5 only.
- Every documentation example shows a method chain and the pipe version in tabs. `bin/docs-examples` checks that both print the same output.

## 0.2.0 - 2026-10-17

The remaining dplyr 1.1 and tidyr 1.3 exports, and phibble's Polars backend. Requires phibble 0.2.

### Added

- dplyr: `cur_column()`, `cur_group()`, `c_across()`, `group_rows()`, `group_data()`, `group_trim()`, `group_nest()`, `nest_by()`, `with_groups()`, `nest_join()`, `order_by()`, `add_row()`, and the superseded `top_n()`, `top_frac()`, `sample_n()`, `sample_frac()`, and `recode()`.
- tidyr list-columns: `unnest_longer()`, `unnest_wider()`, `hoist()`, `chop()`, `unchop()`, `pack()`, and `unpack()`.
- tidyr pivoting: `names_transform`, `values_transform`, and `cols_vary` for `pivot_longer()`; `names_vary`, `names_expand`, `id_expand`, and `unused_fn` for `pivot_wider()`; `build_longer_spec()`, `build_wider_spec()`, `pivot_longer_spec()`, and `pivot_wider_spec()`; the superseded `gather()` and `spread()`.
- tidyr: `full_seq()` and the superseded `extract()` and `separate_rows()`.
- `transmute()` keeps a column given by bare name, as in dplyr.
- A parity suite (`tests/PolarsTest.php`) runs about 120 pipelines on DataFrame and on phibble's `Phibble\Polars\PolarsTable` and checks that columns, types, groups, and rows match. CI runs it with the phpolars release binary.

### Changed

- Requires phibble 0.2, which renames `Phibble\Frame` to `Phibble\DataFrame` with no alias. Code that imports `Phibble\Frame` must import `Phibble\DataFrame`, and the Laravel macro `toFrame()` is now `toDataFrame()`.
- Verbs that build their result in memory (`reframe()`, `complete()`, `expand()`, `bind_rows()`, `bind_cols()`, the set operations, `rows_insert()`, `rows_append()`, `group_modify()`, `unnest()`, `add_row()`, and `pivot_wider()` with `values_fn`, `id_expand`, or `unused_fn`) return a table on the input's backend when that backend can load tables, as PolarsTable can. Results with list-columns of tables, such as `nest()`, stay DataFrames.
- A closure in `across()` may return a helper's result, as in `fn ($v) => mean($v, na_rm: true)`; it is evaluated on the group.
- Helper arguments that evaluate to some other length than the group, such as `c_across()` in a rowwise table, are evaluated on their own.
- The benchmarks use phibble's `Phibble\Polars\PolarsTable`; `PHPOLARS_DIR` is now optional.

### Known issues

- PolarsTable stores named lists as structs, which would give every element every name. `PolarsTable::fromTable()` throws for a list-column of named lists with different names, or of tables, so pipelines that need one stay on DataFrame.

## 0.1.0 - 2026-10-16

The first release: a port of dplyr 1.2 and tidyr 1.3 to PHP 8.5, working on phibble 0.1 tables through the `|>` pipe operator. Results are checked against R; the reference scripts are in `tests/r/`.

### dplyr verbs

- Rows: `filter`, `filter_out`, `arrange` (with `desc()` and `_by_group`), `distinct` (with `_keep_all`), `slice`, `slice_head`, `slice_tail`, `slice_min`, `slice_max`, `slice_sample`, `head`.
- Columns: `select`, `rename`, `rename_with`, `relocate`, `mutate` (with `_by`, `_keep`, `_before`, `_after`), `transmute`, `pull`, `glimpse`.
- Summaries: `summarise` / `summarize` (with `_by` and `_groups`), `reframe`, `count`, `tally`, `add_count`, `add_tally`.
- Grouping: `group_by` (with `_add` and `_drop`), `ungroup`, `rowwise`, `group_vars`, `n_groups`, `group_size`, `group_indices`, `group_keys`, `group_split`, `group_map`, `group_modify`, `group_walk`, `cur_group_id`, `cur_group_rows`.
- Two tables: `inner_join`, `left_join`, `right_join`, `full_join`, `semi_join`, `anti_join`, `cross_join`, and `join_by()` with equality, inequality, `between()`, `within()`, `overlaps()`, and `closest()` rolling conditions; `bind_rows`, `bind_cols`; `union`, `union_all`, `intersect`, `setdiff`, `symdiff`, `setequal`; `rows_insert`, `rows_append`, `rows_update`, `rows_patch`, `rows_upsert`, `rows_delete`.

### Helpers

- Summaries: `n`, `n_distinct`, `sum`, `mean`, `median`, `sd`, `variance` (R's `var`), `quantile`, `iqr`, `min`, `max`, `first`, `last`, `nth`, `any`, `all`.
- Windows: `lag`, `lead`, `row_number`, `min_rank`, `dense_rank`, `percent_rank`, `cume_dist`, `ntile`, `cumsum`, `cumprod`, `cummin`, `cummax`, `cummean`, `cumall`, `cumany`, `consecutive_id`.
- Conditionals: `if_else`, `case_when`, `replace_when`, `case_match`, `recode_values`, `replace_values`, `when_any`, `when_all`, `coalesce`, `na_if`, `between`, `near`.
- Expressions: `col()`, `lit()`, and operator methods (`add`, `gt`, `isIn`...); scalar functions (`round`, `log`, `is_na`, `as_*`, `paste0`...); stringr functions with regular-expression patterns, `fixed()`, and `regex()`; lubridate parsing, parts, rounding, periods, `difftime`, and time zones.
- tidyselect: `starts_with`, `ends_with`, `contains`, `matches`, `num_range`, `everything`, `last_col`, `all_of`, `any_of`, `where`, `group_cols`, `except`; `across`, `if_any`, `if_all`, `pick`.

### tidyr verbs

- `pivot_longer` and `pivot_wider` (built as tidyr specs and run by the table), `complete`, `expand`, `nesting`, `crossing`, `expand_grid`, `fill`, `drop_na`, `replace_na`, `uncount`, `unite`, `separate`, `separate_wider_delim`, `separate_wider_position`, `separate_wider_regex`, `separate_longer_delim`, `separate_longer_position`, `nest`, `unnest`.

### Backends and models

- Helpers build `Phibble\Expr` trees when their arguments are inspectable, so pipelines run lazily in SQL on `Phibble\tbl()` tables and natively on phpolars' PolarsTable. `show_query()` and `collect()` support lazy tables.
- `lm`, `glm`, `lmer`, and `glmer` pass tables to the php-stats extension's formula functions.

### Documentation

- A Quarto site with getting started, coming from dplyr, reference pages, a gallery, an R-to-PHP porting example, and a performance page. Every example's output comes from running it. Benchmark results are published as `docs/benchmarks/backends-results.json`.
