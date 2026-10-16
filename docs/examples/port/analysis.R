suppressPackageStartupMessages({
  library(dplyr)
  library(tidyr)
  library(lme4)
})

schools <- as_tibble(read.csv("schools.csv"))

# Clean: drop incomplete rows, band study time, compute the gain.
clean <- schools |>
  filter(!is.na(score), study_hours > 0) |>
  mutate(
    hours_band = case_when(study_hours < 4 ~ "low", study_hours < 8 ~ "mid", .default = "high"),
    gain = score - prior_score,
    passed = as.integer(score >= 75)
  )

# Reshape: one row per student and test.
long <- clean |>
  mutate(student = row_number()) |>
  select(student, school, prior = prior_score, final = score) |>
  pivot_longer(c(prior, final), names_to = "test", values_to = "points")

long |> group_by(test) |> summarise(mean = mean(points), sd = sd(points))

# Summarise: students per school and band, then the schools with the largest gains.
clean |>
  count(school, hours_band) |>
  pivot_wider(names_from = hours_band, values_from = n, values_fill = 0) |>
  head(3)

clean |>
  group_by(school) |>
  summarise(n = n(), mean_gain = mean(gain)) |>
  arrange(desc(mean_gain)) |>
  head(3)

# Model: linear, logistic, and random-intercept fits.
round(coef(lm(score ~ study_hours + prior_score, data = clean)), 4)
round(coef(glm(passed ~ study_hours + prior_score, family = binomial, data = clean)), 4)
round(fixef(lmer(score ~ study_hours + prior_score + (1 | school), data = clean)), 4)
