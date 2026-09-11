<?php

$url = 'https://apdrc.soest.hawaii.edu/dods/public_data/'
     . 'Reanalysis_Data/ERA5/monthly_3d/'
     . 'Geopotential.ascii?zg[0:1039][3][530][168]';

$context = stream_context_create([
    'http' => [
        'timeout' => 30,
    ],
]);

$text = file_get_contents($url, false, $context);

if ($text === false) {
    exit("Download failed. Check DNS/network connectivity.\n");
}

// Match indexed rows such as:
// [0][0][0], 7644.9766
// Also handles multiple comma-separated values.
preg_match_all(
    '/^\s*(?:\[\d+\])+\s*,\s*([^\r\n]+)/m',
    $text,
    $matches
);

$values = [];

foreach ($matches[1] as $row) {
    foreach (explode(',', $row) as $item) {
        $item = trim($item);

        if (is_numeric($item)) {
            $values[] = (float) $item;
        }
    }
}

if (empty($values)) {
    exit("No indexed values found. Inspect the downloaded ASCII response.\n");
}

// Print ONLY the floating-point values.
foreach ($values as $value) {
    echo $value . PHP_EOL;
}
