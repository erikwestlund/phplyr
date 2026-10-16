```php
$x = Frame::fromRows([['id' => 1, 'v' => 'a'], ['id' => 2, 'v' => 'b'], ['id' => null, 'v' => 'c']]);
$y = Frame::fromRows([['id' => 2, 'v' => 'B'], ['id' => 2, 'v' => 'B2'], ['id' => null, 'v' => 'C']]);

// Clashing columns get suffixes; NA keys match each other, as in dplyr.
echo $x |> left_join($y, by: 'id');

// Keep only the first match, and never match NA keys.
echo "\n", $x |> left_join($y, by: 'id', multiple: 'first', na_matches: 'never', suffix: ['_x', '_y']);
```

```{.text .output}
# A phibble: 4 × 3
  id    v.x   v.y
  <int> <chr> <chr>
1 1     a     NA
2 2     b     B
3 2     b     B2
4 NA    c     C
# A phibble: 3 × 3
  id    v_x   v_y
  <int> <chr> <chr>
1 1     a     NA
2 2     b     B
3 NA    c     NA
```
