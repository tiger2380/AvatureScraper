<?php

require 'Database.php';

$db = new Database();
$jobs = $db->getAllJobs();

file_put_contents(
    'output/jobs.json',
    json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

echo "Exported " . count($jobs) . " jobs to output/jobs.json\n";
