```php
$schools = Frame::fromCsv('schools.csv');

echo implode(', ', ($schools |> select('school', ends_with('score')))->columns()), "\n";
echo implode(', ', ($schools |> select(except('school')))->columns()), "\n";
echo implode(', ', ($schools |> select(where('numeric')))->columns()), "\n";
echo implode(', ', ($schools |> select(['hours' => 'study_hours'], everything()))->columns()), "\n";
echo implode(', ', ($schools |> relocate('score', _before: 'study_hours'))->columns()), "\n";
echo implode(', ', ($schools |> rename_with(strtoupper(...), contains('score')))->columns()), "\n";
```

```{.text .output}
school, prior_score, score
study_hours, prior_score, score
study_hours, prior_score, score
hours, school, prior_score, score
school, score, study_hours, prior_score
school, study_hours, PRIOR_SCORE, SCORE
```
