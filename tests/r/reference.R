# Regenerate tests/Fixtures/dplyr-reference.json from the CSV fixtures.
# Run from the repo root: Rscript tests/r/reference.R
suppressPackageStartupMessages({
  library(dplyr)
  library(jsonlite)
})

schools <- read.csv("tests/Fixtures/schools.csv")
housing <- read.csv("tests/Fixtures/housing.csv")

out <- list(
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

  housing_rooms = housing |> distinct(bedrooms) |> arrange(bedrooms)
)

write_json(out, "tests/Fixtures/dplyr-reference.json", digits = NA, auto_unbox = TRUE, pretty = TRUE)
