<?php

require 'Database.php';
require 'TenantGraphDiscovery.php';
require 'AvatureScraper.php';
require 'AutoTenantDiscovery.php';
require 'FileTenantLoader.php';

echo "=== Avature Job Scraper ===\n";

/* ---------------- INIT DB ---------------- */

$db = new Database('scraper.db');
$db->execFile('schema.sql');

/* ---------------- DISCOVERY ---------------- */

$search = new AutoTenantDiscovery();
$queries = [
    'site:avature.net "careers" -inurl:blog -inurl:help -inurl:support',
    'site:avature.net "job openings" -inurl:blog -inurl:help -inurl:support',
    'site:avature.net "join our team" -inurl:blog -inurl:help -inurl:support'
];

$seedCompanies = [
    'bloomberg', 'ibm', 'sony', 'nike', 'oracle', 'intel', 'condenastuk', 'dell',
    'cisco', 'hbo', 'siemens', 'loreal', 'hewlettpackard', 'airbus', 'uber',
    'verizon', 'boeing', 'qualcomm', 'amd', 'salesforce', 'spglobal',
    'schneiderelectric', 'capgemini', 'manpowergroup', 'nielsen', 'marriott', 'abbvie', 'condenast', 'ally',
];
echo "[1/5] Loading tenants from file...\n";

$fileLoader = new FileTenantLoader('Urls.txt');
$fileTenants = $fileLoader->load();

echo "Loaded " . count($fileTenants) . " tenants from file\n";

foreach( $fileTenants as $tenant ) {
    $db->saveTenant($tenant);
}

echo "[2/5] Searching for tenants via queries...\n";
$discovered = $search->discover($queries);
$seedCompanies = array_merge($seedCompanies, $discovered);

echo "Found " . count($discovered) . " potential tenants via search.\n";

echo "[3/5] Discovering Avature tenants...\n";

$graph = new TenantGraphDiscovery();
$tenants = $graph->crawl($seedCompanies);

foreach ($tenants as $tenant) {
    $db->saveTenant($tenant);
}

echo "    Found " . count($tenants) . " tenants\n";

/* ---------------- SCRAPING ---------------- */

echo "[4/5] Scraping jobs...\n";

foreach ($db->getTenants() as $tenant) {
    echo "    → $tenant\n";
    $scraper = new AvatureScraper($tenant, $db);
    $scraper->scrape();
}

/* ---------------- EXPORT ---------------- */

echo "[5/5] Exporting data...\n";

$jobs = $db->getAllJobs();

file_put_contents(
    'output/jobs.json',
    json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

$fp = fopen('output/jobs.csv', 'w');
if (!empty($jobs)) {
    fputcsv($fp, array_keys($jobs[0]));
    foreach ($jobs as $job) {
        fputcsv($fp, $job);
    }
}
fclose($fp);

echo "\n✔ Done. Total jobs: " . count($jobs) . "\n";
