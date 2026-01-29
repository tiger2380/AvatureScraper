<?php

require 'Database.php';

$db = new Database();
$jobs = $db->getAllJobs();

$type = $argv[1] ?? 'json';

if ($type === 'csv') {
    $fp = fopen('output/jobs.csv', 'w');
    fputcsv($fp, array_keys($jobs[0]));
    foreach ($jobs as $job) fputcsv($fp, $job);
    fclose($fp);
    echo "CSV export done\n";
} else {
    file_put_contents(
        'output/jobs.json',
        json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    echo "JSON export done\n";
}
