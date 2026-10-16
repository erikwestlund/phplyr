# Regenerate tests/Fixtures/tidyr-reference.json. Run from the repo root.
suppressPackageStartupMessages({
  library(dplyr)
  library(tidyr)
  library(jsonlite)
})

f <- function(name) read.csv(file.path("tests/Fixtures", name), stringsAsFactors = FALSE)
wide <- f("wide.csv"); long <- f("long.csv"); who <- f("who.csv")
family <- f("family.csv"); strings <- f("strings.csv"); panel <- f("panel.csv")

out <- list(
  longer = wide |> pivot_longer(starts_with("wk"), names_to = "week", values_to = "rank"),
  longer_drop = wide |> pivot_longer(wk1:wk3, names_to = "week", names_prefix = "wk", values_drop_na = TRUE),
  longer_sep = who |> pivot_longer(-country, names_to = c("diag", "sex_age"), names_prefix = "new_", names_sep = "_"),
  longer_pattern = who |> pivot_longer(-country, names_to = c("diag", "sex", "age"), names_pattern = "new_(.*)_(.)(.*)"),
  longer_value = family |> pivot_longer(-family, names_to = c(".value", "child"), names_sep = "_", values_drop_na = TRUE),
  wider = long |> filter(key != "z") |> pivot_wider(id_cols = id, names_from = key, values_from = val),
  wider_fill = long |> filter(key != "z") |> pivot_wider(id_cols = id, names_from = key, values_from = val, values_fill = 0, names_prefix = "k_"),
  wider_fn = long |> pivot_wider(id_cols = id, names_from = key, values_from = val, values_fn = sum),
  wider_multi = long |> filter(key != "z") |> pivot_wider(names_from = key, values_from = c(val, w)),
  wider_sort = long |> filter(key != "z") |> arrange(desc(key)) |> pivot_wider(id_cols = id, names_from = key, values_from = val, names_sort = TRUE),
  wider_glue = long |> filter(key != "z") |> pivot_wider(id_cols = id, names_from = key, values_from = val, names_glue = "{key}_{.value}"),
  complete = long |> select(id, key, val) |> filter(key != "z" | val == 5) |> complete(id, key),
  complete_fill = long |> select(id, key, w) |> filter(key != "z" | w == 50) |> complete(id, key, fill = list(w = 0)),
  complete_nesting = panel |> filter(id <= 6) |> select(g, label, year) |> complete(nesting(g, label), year = 2001:2002) |> head(8),
  complete_grouped = long |> select(id, key, w) |> filter(key != "z" | w == 50) |> group_by(id) |> complete(key = c("x", "y")) |> ungroup(),
  expand = long |> expand(id, key),
  expand_nesting = long |> expand(nesting(id, key)),
  crossing = crossing(x = c(2, 1, 2), y = c("b", "a")),
  fill_down = wide |> fill(wk1, wk2),
  fill_up = wide |> fill(wk1, wk2, .direction = "up"),
  fill_grouped = panel |> select(id, g, value) |> group_by(g) |> fill(value, .direction = "downup") |> ungroup(),
  drop_na_all = wide |> drop_na(),
  drop_na_col = wide |> drop_na(wk1),
  replace_na = wide |> replace_na(list(wk1 = 0, wk2 = -1)),
  uncount = strings |> select(id, n) |> uncount(n, .id = "copy"),
  unite = panel |> head(3) |> unite("gl", g, label, sep = ":"),
  unite_keep = panel |> head(3) |> unite("gv", g, value, remove = FALSE),
  separate = strings |> select(id, s) |> separate(s, c("a", "b"), sep = "-", extra = "drop", fill = "right"),
  separate_merge = strings |> select(id, s) |> separate(s, c("a", "b"), sep = "-", extra = "merge", fill = "left"),
  separate_pos = strings |> select(id, code) |> separate(code, c("letters", "digits"), sep = 2, convert = TRUE),
  wider_delim = strings |> select(id, s) |> separate_wider_delim(s, "-", names = c("p1", "p2", "p3"), too_few = "align_start"),
  wider_delim_sep = strings |> select(id, s) |> separate_wider_delim(s, "-", names_sep = "_", too_few = "align_end"),
  wider_position = strings |> select(id, code) |> separate_wider_position(code, c(state = 2, 1, digits = 2), too_few = "align_start"),
  wider_regex = strings |> select(id, code) |> separate_wider_regex(code, c(letters = "[A-Z]+", digits = "\\d+")),
  longer_delim = strings |> select(id, tags) |> separate_longer_delim(tags, ","),
  longer_position = strings |> select(id, code) |> separate_longer_position(code, 2)
)

write_json(out, "tests/Fixtures/tidyr-reference.json", digits = NA, auto_unbox = TRUE, pretty = TRUE, na = "null")
