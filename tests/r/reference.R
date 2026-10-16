# Regenerate tests/Fixtures/dplyr-reference.json from the CSV fixtures.
# Run from the repo root: Rscript tests/r/reference.R
suppressPackageStartupMessages({
  library(dplyr)
  library(jsonlite)
})

schools <- read.csv("tests/Fixtures/schools.csv")
housing <- read.csv("tests/Fixtures/housing.csv")
panel <- read.csv("tests/Fixtures/panel.csv")

out <- list(
  # --- v0.1 verbs -----------------------------------------------------------
  school_summary = schools |>
    filter(study_hours > 5) |>
    mutate(gain = score - prior_score) |>
    group_by(school) |>
    summarise(
      n = n(),
      mean_score = mean(score),
      sd_score = sd(score),
      var_gain = var(gain),
      median_hours = median(study_hours),
      q90_score = quantile(score, 0.9, names = FALSE),
      min_prior = min(prior_score),
      max_prior = max(prior_score)
    ) |>
    arrange(desc(mean_score)),

  hours_band_count = schools |>
    mutate(band = ifelse(study_hours >= 8, "high", "low")) |>
    count(band, sort = TRUE),

  grouped_center = schools |>
    group_by(school) |>
    mutate(centered = score - mean(score)) |>
    ungroup() |>
    filter(centered > 10) |>
    select(school, score, centered) |>
    arrange(school, desc(centered)),

  housing_rent = housing |>
    mutate(rent_per_m2 = monthly_rent / area_m2) |>
    rename(rooms = bedrooms) |>
    select(rooms, area_m2, rent_per_m2) |>
    arrange(rooms, desc(rent_per_m2)),

  housing_rooms = housing |> distinct(bedrooms) |> arrange(bedrooms),

  # --- window functions -------------------------------------------------------
  windows = panel |>
    group_by(g) |>
    mutate(
      lag1 = lag(value), lead1 = lead(value), lag2 = lag(score, 2, default = 0),
      lag_by_year = lag(score, order_by = year),
      rn = row_number(), rn_score = row_number(score),
      mr = min_rank(score), mr_desc = min_rank(desc(score)), dr = dense_rank(score),
      pr = percent_rank(score), cd = cume_dist(score), nt = ntile(score, 3),
      cs = cumsum(value), cmax = cummax(score), cmin = cummin(score), cmean = cummean(score),
      call = cumall(score > 2), cany = cumany(score > 6)
    ) |>
    ungroup() |>
    select(-year, -label),

  ntile_all = panel |> mutate(nt5 = ntile(score, 5), nt_rows = ntile(n = 5)) |> select(id, nt5, nt_rows),

  # --- vector helpers ---------------------------------------------------------
  vectors = panel |>
    mutate(
      ie = if_else(score > 5, "hi", "lo"),
      ie_na = if_else(value > 4, "big", "small", missing = "none"),
      cw = case_when(score < 3 ~ "low", score < 7 ~ "mid", .default = "high"),
      co = coalesce(value, 0),
      nai = na_if(score, 4),
      bt = between(score, 3, 6),
      nr = near(value, 4.5),
      cid = consecutive_id(label),
      grp_mean = mean(score), grp_n = n()
    ) |>
    select(id, ie, ie_na, cw, co, nai, bt, nr, cid, grp_mean, grp_n),

  # --- filter ------------------------------------------------------------------
  filter_group_max = panel |> group_by(g) |> filter(score == max(score)) |> ungroup() |> select(id, g, score),
  filter_between = panel |> filter(between(score, 2, 4), !is.na(value)) |> select(id),
  filter_if_any = panel |> filter(if_any(c(value, score), \(x) x > 8)) |> select(id),
  filter_if_all = panel |> filter(if_all(c(value, score), \(x) x > 3)) |> select(id),
  filter_by = panel |> filter(score > mean(score), .by = g) |> select(id),

  # --- tidyselect and across ------------------------------------------------
  select_starts = panel |> select(starts_with("s")) |> names(),
  select_minus = panel |> select(-id, -label) |> names(),
  select_where = panel |> select(where(is.numeric)) |> names(),
  select_rename = panel |> select(ident = id, everything()) |> names(),
  select_last = panel |> select(last_col(1), last_col()) |> names(),
  select_contains = panel |> select(contains("E")) |> names(),
  select_matches = panel |> select(matches("^(id|g)$")) |> names(),
  select_any_of = panel |> select(any_of(c("score", "nope", "id"))) |> names(),
  rename_with = panel |> rename_with(toupper, starts_with("s")) |> names(),
  relocate_before = panel |> relocate(score, .before = g) |> names(),
  relocate_after = panel |> relocate(g, id, .after = last_col()) |> names(),
  relocate_front = panel |> relocate(label) |> names(),

  across_mean = panel |> group_by(g) |>
    summarise(across(c(value, score), \(x) mean(x, na.rm = TRUE))),
  across_list = panel |> group_by(g) |>
    summarise(across(c(value, score), list(avg = \(x) mean(x, na.rm = TRUE), top = \(x) max(x, na.rm = TRUE)))),
  across_names = panel |> group_by(g) |>
    summarise(across(starts_with("sc"), list(lo = min, hi = max), .names = "{.fn}_{.col}")),
  across_mutate = panel |> mutate(across(c(value, score), \(x) x * 2)) |> select(id, value, score),
  across_mutate_window = panel |> group_by(g) |> mutate(across(c(score, year), lag)) |> ungroup() |> select(id, score, year),

  # --- more verbs ---------------------------------------------------------------
  transmute = panel |> group_by(g) |> transmute(double = score * 2, id) |> ungroup(),
  keep_none = panel |> mutate(z = score * 2, score = score + 1, .keep = "none"),
  mutate_before = panel |> mutate(z = score * 2, .before = g) |> names(),
  mutate_after = panel |> mutate(z = score * 2, w = 1, .after = id) |> names(),
  mutate_drop = panel |> mutate(label = NULL, year = NULL) |> names(),
  add_count = panel |> add_count(g, name = "size") |> select(id, size),
  tally = panel |> group_by(g) |> tally(),
  count_wt = panel |> count(g, wt = score),
  count_multi = panel |> count(g, label),
  add_tally = panel |> group_by(label) |> add_tally() |> ungroup() |> select(id, n),
  slice_min = panel |> group_by(g) |> slice_min(score, n = 1) |> ungroup() |> select(id),
  slice_min_noties = panel |> group_by(g) |> slice_min(score, n = 1, with_ties = FALSE) |> ungroup() |> select(id),
  slice_max = panel |> group_by(g) |> slice_max(value, n = 2) |> ungroup() |> select(id),
  slice_max_prop = panel |> slice_max(score, prop = 0.25) |> select(id),
  slice_tail = panel |> group_by(g) |> slice_tail(n = 2) |> ungroup() |> select(id),
  slice_pos = panel |> group_by(g) |> slice(c(1, 3)) |> ungroup() |> select(id),
  slice_neg = panel |> group_by(g) |> slice(-1) |> ungroup() |> select(id),
  slice_head_by = panel |> slice_head(n = 1, by = g) |> select(id),
  distinct_keep_all = panel |> distinct(label, .keep_all = TRUE) |> select(id, label),
  distinct_computed = panel |> distinct(hi = score > 5) |> arrange(hi),
  reframe = panel |> group_by(g) |> reframe(p = c(0.25, 0.75), q = quantile(score, c(0.25, 0.75), names = FALSE)),
  summarise_by = panel |> summarise(m = mean(score), n = n(), .by = g),
  group_computed = panel |> group_by(g, early = year < 2003) |> summarise(n = n(), .groups = "drop"),
  arrange_expr = panel |> arrange(desc(score * 2), id) |> select(id),
  arrange_by_group = panel |> group_by(g) |> arrange(desc(score), .by_group = TRUE) |> ungroup() |> select(id),
  group_size = panel |> group_by(label) |> group_size(),
  group_keys = panel |> group_by(g, label) |> group_keys(),
  group_indices = panel |> group_by(label) |> group_indices(),
  n_groups = panel |> group_by(label) |> n_groups(),
  pull_named = as.list(panel |> pull(score, name = id))
)

write_json(out, "tests/Fixtures/dplyr-reference.json", digits = NA, auto_unbox = TRUE, pretty = TRUE, na = "null")
