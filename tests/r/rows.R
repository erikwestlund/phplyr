# Regenerate tests/Fixtures/rows-reference.json. Run from the repo root.
suppressPackageStartupMessages({
  library(dplyr)
  library(jsonlite)
})

a <- data.frame(id = c(1, 2, 3, 3, NA), v = c("a", "b", "c", "c", "n"))
b <- data.frame(id = c(3, 4, NA), v = c("c", "d", "n"))
x <- data.frame(id = c(1, 2, 3), v = c("a", NA, "c"), w = c(10, 20, 30))
y <- data.frame(id = c(2, 4), v = c("B", "D"))
panel <- read.csv("tests/Fixtures/panel.csv")

out <- list(
  union_all = union_all(a, b),
  union = union(a, b),
  intersect = intersect(a, b),
  setdiff = setdiff(a, b),
  symdiff = symdiff(a, b),
  insert = rows_insert(x, y, by = "id", conflict = "ignore"),
  append = rows_append(x, y),
  update = rows_update(x, y, by = "id", unmatched = "ignore"),
  patch = rows_patch(x, y, by = "id", unmatched = "ignore"),
  upsert = rows_upsert(x, y, by = "id"),
  delete = rows_delete(x, y, by = "id", unmatched = "ignore"),
  case_match = panel |> mutate(kind = case_match(label, c("x", "y") ~ "xy", "z" ~ "zed", .default = "other")) |> select(id, kind),
  group_cols = panel |> group_by(g, label) |> select(group_cols(), score) |> ungroup() |> names()
)

write_json(out, "tests/Fixtures/rows-reference.json", digits = NA, auto_unbox = TRUE, pretty = TRUE, na = "null")

# dplyr 1.2 additions
out2 <- list(
  filter_out = panel |> filter_out(value > 5, score > 3) |> select(id),
  filter_when_any = panel |> filter(when_any(value > 7, score > 8)) |> select(id),
  when = panel |> mutate(any = when_any(value > 5, score > 5), all = when_all(value > 5, score > 5), all_rm = when_all(value > 5, score > 5, na_rm = TRUE)) |> select(id, any, all, all_rm),
  recode = panel |> mutate(r = recode_values(label, "x" ~ "ex", c("y", "z") ~ "late", default = "?"), lk = recode_values(score, from = c(1, 2, 3), to = c("one", "two", "three"))) |> select(id, r, lk),
  replace = panel |> mutate(rv = replace_values(label, "z" ~ "zed"), rw = replace_when(score, score > 7 ~ 7)) |> select(id, rv, rw)
)
write_json(c(out, out2), "tests/Fixtures/rows-reference.json", digits = NA, auto_unbox = TRUE, pretty = TRUE, na = "null")
