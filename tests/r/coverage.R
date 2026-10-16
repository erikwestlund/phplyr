# Regenerate tests/Fixtures/coverage-reference.json: the dplyr 1.1 and
# tidyr 1.3 functions added after phplyr 0.1.0. Run from the repo root.
suppressPackageStartupMessages({
  library(dplyr)
  library(tidyr)
  library(jsonlite)
})

f <- function(name) read.csv(file.path("tests/Fixtures", name), stringsAsFactors = FALSE)
wide <- f("wide.csv"); long <- f("long.csv"); panel <- f("panel.csv")
p4 <- head(panel, 4)
nj <- tibble(g = c("a", "a", "b"), v = 1:3) |>
  nest_join(tibble(g = c("a", "b", "b", "c"), w = c(10, 20, 30, 40)), by = "g", name = "y")
h <- tibble(id = 1:2, x = list(list(a = 1, b = list(c = 5, d = 6), e = "k"), list(a = 2, b = list(c = 7))))

out <- list(
  # across(), if_any(), if_all()
  across_fns = p4 |> group_by(g) |> summarise(across(c(value, score), list(m = ~ mean(.x, na.rm = TRUE), s = ~ sum(.x)), .names = "{.fn}_{.col}")),
  across_cur_column = p4 |> mutate(across(c(value, score), ~ paste(cur_column(), .x))),
  if_any_filter = p4 |> filter(if_any(c(value, score), ~ .x > 4)),
  if_all_filter = p4 |> filter(if_all(c(value, score), ~ .x > 2)),
  if_mutate = p4 |> mutate(a = if_any(c(value, score), ~ .x > 4), b = if_all(c(value, score), ~ .x > 2)),
  if_empty = p4 |> mutate(a = if_any(starts_with("zz"), ~ .x > 4), b = if_all(starts_with("zz"), ~ .x > 2)),
  across_empty = p4 |> summarise(across(starts_with("zz"), mean), n = n()),
  # Group helpers
  group_rows = panel |> group_by(g) |> group_rows() |> lapply(as.integer),
  cur_group = head(panel, 6) |> group_by(g) |> mutate(cg = cur_group()$g) |> ungroup(),
  c_across = head(panel, 6) |> rowwise() |> mutate(tot = sum(c_across(c(value, score)), na.rm = TRUE)) |> ungroup(),
  group_nest_n = head(panel, 6) |> group_by(g) |> group_nest() |> mutate(n = sapply(data, nrow), cols = sapply(data, ncol)) |> select(-data),
  nest_by_n = head(panel, 6) |> nest_by(g) |> mutate(n = nrow(data)) |> ungroup() |> select(-data),
  with_groups = head(panel, 3) |> with_groups(g, mutate, m = mean(score)),
  # Superseded dplyr verbs
  top_n = head(panel, 8) |> top_n(2, score),
  top_n_neg = head(panel, 8) |> group_by(g) |> top_n(-1, value) |> ungroup(),
  top_frac = head(panel, 8) |> top_frac(0.25, score),
  recode = recode(c("a", "b", "c", NA), a = "A", b = "B", .default = "other"),
  recode_keep = recode(c("x", "y", "z"), x = "X"),
  order_by = head(panel, 5) |> mutate(r = order_by(year, cumsum(score))),
  order_by_desc = head(panel, 5) |> mutate(r = order_by(desc(year), cumsum(score))),
  add_row_before = tibble(x = 1:2, y = c("a", "b")) |> add_row(x = 3, y = "c", .before = 2),
  add_row_many = tibble(x = 1:2, y = c("a", "b")) |> add_row(x = 4:5),
  nest_join_n = nj |> mutate(n = sapply(y, nrow), w = sapply(y, function(d) sum(d$w))) |> select(-y),
  # Pivot options and specs
  longer_transform = wide |> pivot_longer(starts_with("wk"), names_to = "week", names_prefix = "wk", names_transform = as.integer, cols_vary = "slowest"),
  longer_values_transform = wide |> pivot_longer(starts_with("wk"), names_to = "week", values_transform = function(x) x * 2, values_drop_na = TRUE),
  wider_vary = long |> filter(key != "z") |> pivot_wider(id_cols = id, names_from = key, values_from = c(val, w), names_vary = "slowest"),
  wider_unused = long |> filter(key != "z") |> pivot_wider(id_cols = id, names_from = key, values_from = val, unused_fn = list(w = sum)),
  wider_names_expand = long |> filter(key != "z") |> mutate(key = factor(key, levels = c("x", "y", "q"))) |> pivot_wider(id_cols = id, names_from = key, values_from = val, names_expand = TRUE),
  wider_id_expand = long |> filter(key != "z") |> mutate(id = factor(id, levels = 1:5)) |> pivot_wider(id_cols = id, names_from = key, values_from = val, id_expand = TRUE, values_fill = 0) |> mutate(id = as.integer(as.character(id))),
  wider_spec = long |> build_wider_spec(names_from = key, values_from = c(val, w)),
  longer_spec = wide |> build_longer_spec(starts_with("wk"), names_to = "week", names_prefix = "wk"),
  pivot_wider_spec = long |> filter(key != "z") |> pivot_wider_spec(build_wider_spec(long |> filter(key != "z"), names_from = key, values_from = val), id_cols = id),
  pivot_longer_spec = wide |> pivot_longer_spec(build_longer_spec(wide, starts_with("wk"), names_to = "week")),
  gather = wide |> gather("week", "rank", wk1:wk3),
  gather_na = wide |> gather("week", "rank", wk1:wk3, na.rm = TRUE),
  spread = long |> filter(key != "z") |> select(id, key, val) |> spread(key, val),
  spread_fill = long |> filter(key != "z") |> select(id, key, val) |> spread(key, val, fill = 0),
  # Rectangling
  unnest_wider = tibble(id = 1:4, x = list(list(a = 1, b = "u"), list(a = 2), NULL, list(b = "w", c = TRUE))) |> unnest_wider(x),
  unnest_wider_sep = tibble(id = 1:2, y = list(1:2, 3)) |> unnest_wider(y, names_sep = "_"),
  unnest_longer_named = tibble(id = 1:2, v = list(c(a = 1, b = 2), c(c = 3))) |> unnest_longer(v, values_to = "val", indices_to = "key"),
  unnest_longer_keep = tibble(id = 1:3, v = list(1:2, 3L, integer(0))) |> unnest_longer(v, indices_include = TRUE, keep_empty = TRUE),
  unnest_longer_two = tibble(id = 1:2, v = list(1:2, 3L), w = list(c("a", "b"), "c")) |> unnest_longer(c(v, w)),
  hoist = h |> hoist(x, first = 1L, bc = c("b", "c"), e = "e") |> select(-x),
  unchop = tibble(g = 1:3, x = list(1:2, NULL, integer(0))) |> unchop(x, keep_empty = TRUE),
  chop_unchop = tibble(g = c(1, 1, 2), x = 1:3, y = 4:6) |> chop(c(x, y)) |> unchop(c(x, y)),
  unpack = tibble(x = 1:3, a = c(1, 2, 3), b = c("p", "q", "r")) |> pack(y = c(a, b)) |> unpack(y, names_sep = "_"),
  # Other tidyr
  extract = tibble(x = c("a1-2", "b3-4")) |> extract(x, c("l", "n"), "([a-z])(\\d)", remove = FALSE, convert = TRUE),
  extract_na = tibble(x = c("a1-b", "c2-d", NA)) |> extract(x, c("l", "n"), "([a-z])(\\d)"),
  separate_rows = tibble(x = 1:2, y = c("1,2", "3"), z = c("a b", "c")) |> separate_rows(y, z, convert = TRUE),
  full_seq = full_seq(c(1, 5, 3), 1),
  full_seq_frac = full_seq(c(1.5, 2.25, 3), 0.75)
)

write_json(out, "tests/Fixtures/coverage-reference.json", digits = NA, auto_unbox = TRUE, pretty = TRUE, na = "null")
