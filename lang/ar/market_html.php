<?php

$english = require dirname(__DIR__).'/en/market_html.php';

return [
    'phrases' => array_combine(
        array_keys($english['phrases']),
        array_keys($english['phrases'])
    ),
];
