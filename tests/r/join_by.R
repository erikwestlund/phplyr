# Regenerate tests/Fixtures/join-by-reference.json. Run from the repo root.
suppressPackageStartupMessages({
  library(dplyr)
  library(jsonlite)
})
ev <- read.csv("tests/Fixtures/events.csv")
pe <- read.csv("tests/Fixtures/periods.csv")
x <- read.csv("tests/Fixtures/join_x.csv"); y <- read.csv("tests/Fixtures/join_y.csv")
out <- list(
  eq = left_join(x, y, by = join_by(id == key), relationship = "many-to-many"),
  ineq = inner_join(ev, pe, by = join_by(id, t >= start)),
  ineq_left = left_join(ev, pe, by = join_by(id, t >= start, t < end)),
  between = left_join(ev, pe, by = join_by(id, between(t, start, end))),
  closest = left_join(ev, pe, by = join_by(id, closest(t >= start))),
  closest_lt = left_join(ev, pe, by = join_by(closest(t < start))),
  within = inner_join(rename(pe, s2 = start, e2 = end), pe, by = join_by(within(s2, e2, start, end))),
  overlaps = inner_join(rename(pe, s2 = start, e2 = end), pe, by = join_by(overlaps(s2, e2, start, end))),
  semi = semi_join(ev, pe, by = join_by(id, between(t, start, end))),
  anti = anti_join(ev, pe, by = join_by(id, between(t, start, end))),
  keep_true = left_join(ev, pe, by = join_by(id, closest(t >= start)), keep = TRUE)
)
write_json(out, "tests/Fixtures/join-by-reference.json", digits = NA, auto_unbox = TRUE, pretty = TRUE, na = "null")
