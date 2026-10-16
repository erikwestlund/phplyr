```r
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
```

```{.text .output}
# A tibble: 2 × 3
  test   mean    sd
  <chr> <dbl> <dbl>
1 final  72.0  10.3
2 prior  59.8  12.4
# A tibble: 3 × 4
  school     high   low   mid
  <chr>     <int> <int> <int>
1 school-01     7     4     7
2 school-02     8     8     8
3 school-03     5     3     7
# A tibble: 3 × 3
  school        n mean_gain
  <chr>     <int>     <dbl>
1 school-07    19      24.3
2 school-10    25      14.1
3 school-02    24      13.6
(Intercept) study_hours prior_score 
    45.3014      1.5691      0.2730 
(Intercept) study_hours prior_score 
    -6.9865      0.5688      0.0395 
(Intercept) study_hours prior_score 
    46.6627      1.6141      0.2453
```
