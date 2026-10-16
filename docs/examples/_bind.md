```php
$a = Frame::fromRows([['id' => 1, 'v' => 'a']]);
$b = Frame::fromRows([['id' => 2, 'w' => true]]);

echo $a |> bind_rows($b, _id: 'source');
echo "\n", $a |> bind_cols(Frame::fromRows([['id' => 10]]));
```

```{.text .output}
# A phibble: 2 × 4
  source id    v     w
  <chr>  <int> <chr> <lgl>
1 1      1     a     NA
2 2      2     NA    TRUE
# A phibble: 1 × 3
  id...1 v     id...3
  <int>  <chr> <int>
1 1      a     10
```
