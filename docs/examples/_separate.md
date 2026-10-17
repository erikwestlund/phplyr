```php
$t = DataFrame::fromRows([
    ['id' => 1, 'name' => 'Ada Lovelace', 'tags' => 'math,code'],
    ['id' => 2, 'name' => 'Grace Hopper', 'tags' => 'navy'],
]);

echo $t |> separate_wider_delim('name', ' ', names: ['first', 'last']);
echo "\n", $t |> select('id', 'tags') |> separate_longer_delim('tags', ',');
echo "\n", $t |> select('id', 'name') |> unite('label', 'id', 'name', sep: ': ');
```

```{.text .output}
# A tibble: 2 × 4
     id first last     tags     
  <int> <chr> <chr>    <chr>    
1     1 Ada   Lovelace math,code
2     2 Grace Hopper   navy     
# A tibble: 3 × 2
     id tags 
  <int> <chr>
1     1 math 
2     1 code 
3     2 navy 
# A tibble: 2 × 1
  label          
  <chr>          
1 1: Ada Lovelace
2 2: Grace Hopper
```
