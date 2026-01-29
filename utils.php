<?php

function find_in_array(array $arr, string $needle): ?string
{
    $val = null;
    foreach ($arr as $key => $value) {
        if (stripos($key, $needle) !== false) {
            $val = $value;
            break;
        }
    }
    return $val;
}