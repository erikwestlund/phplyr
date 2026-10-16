# Regenerate tests/Fixtures/models-reference.json. Run from the repo root.
library(jsonlite)
schools <- read.csv("tests/Fixtures/schools.csv")
learning <- read.csv("tests/Fixtures/learning.csv")
out <- list(
  lm = unname(coef(lm(score ~ study_hours + prior_score + school, schools))),
  lm_filtered = unname(coef(lm(score ~ study_hours, subset(schools, prior_score > 60)))),
  glm = unname(coef(glm(passed ~ study_hours + practice_score, binomial, learning)))
)
write_json(out, "tests/Fixtures/models-reference.json", digits = NA, pretty = TRUE)
