# Regenerate tests/Fixtures/joins-reference.json. Run from the repo root.
suppressPackageStartupMessages({
  library(dplyr)
  library(jsonlite)
})

x <- read.csv("tests/Fixtures/join_x.csv")
y <- read.csv("tests/Fixtures/join_y.csv")
by <- c(id = "key")
mm <- "many-to-many"
panel <- read.csv("tests/Fixtures/panel.csv")

out <- list(
  left = left_join(x, y, by = by, relationship = mm),
  inner = inner_join(x, y, by = by, relationship = mm),
  right = right_join(x, y, by = by, relationship = mm),
  full = full_join(x, y, by = by, relationship = mm),
  keep = left_join(x, y, by = by, keep = TRUE, relationship = mm),
  first = left_join(x, y, by = by, multiple = "first"),
  last = left_join(x, y, by = by, multiple = "last"),
  never = left_join(x, y, by = by, na_matches = "never", relationship = mm),
  semi = semi_join(x, y, by = by),
  anti = anti_join(x, y, by = by),
  anti_never = anti_join(x, y, by = by, na_matches = "never"),
  cross = cross_join(select(x, id), select(y, w)),
  suffix = inner_join(x, y, by = by, suffix = c("_l", "_r"), relationship = mm),
  by_k = inner_join(x, y, by = "k"),
  natural = inner_join(x, rename(y, id = key)),
  two_keys = left_join(x, rename(y, id = key), by = c("id", "k")),
  key_clash = left_join(x, mutate(y, id = key * 10), by = "k"),
  rowwise_sum = panel |> rowwise(id) |> summarise(total = sum(c(value, score), na.rm = TRUE)) |> head(4),
  rowwise_mutate = panel |> rowwise() |> mutate(m = max(c(value, score), na.rm = TRUE)) |> ungroup() |> select(id, m) |> head(4)
)

write_json(out, "tests/Fixtures/joins-reference.json", digits = NA, auto_unbox = TRUE, pretty = TRUE, na = "null")
