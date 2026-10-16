# Regenerate tests/Fixtures/expr-reference.json. Run from the repo root.
suppressPackageStartupMessages({
  library(dplyr)
  library(lme4)
  library(jsonlite)
})
panel <- read.csv("tests/Fixtures/panel.csv")
schools <- read.csv("tests/Fixtures/schools.csv")
m <- lmer(score ~ study_hours + prior_score + (1 | school), schools)
out <- list(
  keep_used = panel |> mutate(z = score * 2, w = value + year, .keep = "used"),
  keep_unused = panel |> mutate(z = score * 2, .keep = "unused"),
  keep_used_grouped = panel |> group_by(g) |> mutate(dev = score - mean(score), .keep = "used") |> ungroup(),
  expressions = panel |> mutate(
    r = round(value / 3, 2), up = toupper(label), s = substr(label, 1, 1),
    p = paste0(g, "-", id), l = log(score), ab = abs(score - 5)
  ) |> select(id, r, up, s, p, l, ab),
  lmer_fixef = unname(fixef(m)),
  lmer_sigma = sigma(m)
)
write_json(out, "tests/Fixtures/expr-reference.json", digits = NA, auto_unbox = TRUE, pretty = TRUE, na = "null")
