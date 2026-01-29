<?php

require 'Database.php';

$db = new Database();
$jobs = $db->getAllJobs();

$fp = fopen('output/jobs.csv', 'w');

// Header
if (!empty($jobs)) {
    fputcsv($fp, array_keys($jobs[0]));
}

// Rows
foreach ($jobs as $job) {
    fputcsv($fp, $job);
}

fclose($fp);

echo "Exported " . count($jobs) . " jobs to output/jobs.csv\n";
