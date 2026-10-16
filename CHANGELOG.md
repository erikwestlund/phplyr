# Changelog

All notable changes to phplyr are recorded here. Versions follow semantic versioning; before 1.0, minor versions may change behavior.

## 0.1.0 - Unreleased

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
