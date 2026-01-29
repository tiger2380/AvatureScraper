<?php

class AutoTenantDiscovery 
{
    protected array $discovered = [];
    protected array $config;

    public function __construct()
    {
        $this->config = require 'config.php';
    }

    /**
     * Main discovery function
     */
    public function discover(array $queries = [], int $googlePages = 2): array
    {
        // 1️⃣ Google search
        foreach ($queries as $query) {
            $this->discoverFromGoogle($query, $googlePages);
        }

        // 2️⃣ Job aggregator fallback
        //$this->discoverFromAggregators();

        return array_values($this->discovered);
    }

    /**
     * Discover Avature tenants via Google search
     */
    protected function discoverFromGoogle(string $query, int $pages = 2)
    {
        for ($p = 0; $p < $pages; $p++) {
            $first = $p * 10;
            //$url = "https://duckduckgo.com/html/?q=" . urlencode($query) . "&first={$first}";
            $url = 'https://google.serper.dev/search';
            $postData = [
                'q' => $query,
                'num' => 10,
                'start' => $first
            ];

            $headers = [
                "Content-Type: application/json",
                "X-API-KEY: " . $this->config['serper_api_key']
            ];

            $json = $this->fetchPage($url, $postData, $headers);
            if (!$json) continue;

            // Extract from organic results
            foreach ($json['organic'] ?? [] as $result) {
                $text = $result['link'] . ' ' . $result['snippet'];
                preg_match_all('/https?:\/\/([a-z0-9\-]+)\.avature\.net/i', $text, $matches);
                
                if (!empty($matches[1])) {
                    foreach ($matches[1] as $domain) {
                        $this->discovered[$domain] = "https://{$domain}.avature.net";
                    }
                }
            }

            // polite pause
            usleep(100000); // 0.1 seconds
        }
    }

    /**
     * Discover Avature links from job aggregators
     */
    protected function discoverFromAggregators()
    {
        $aggregatorUrls = [
            'https://www.indeed.com/jobs?q=Avature',
            'https://www.glassdoor.com/Job/Avature-jobs-SRCH_KO0,7.htm'
        ];

        foreach ($aggregatorUrls as $url) {
            $html = $this->fetchPage($url);
            if (!$html) continue;

            preg_match_all('/https?:\/\/([a-z0-9\-]+)\.avature\.net/i', $html, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $domain) {
                    $this->discovered[$domain] = "https://{$domain}.avature.net";
                }
            }

            usleep(100000); // 0.1 seconds
        }
    }

    /**
     * Simple cURL fetch with user-agent
     */
    protected function fetchPage(string $url, array $postData = [], array $headers = []): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => array_merge([
                "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
                "Accept-Language: en-US,en;q=0.9",
                "Accept: text/html",
                "Referer: https://duckduckgo.com/"
            ], $headers),
            CURLOPT_COOKIEJAR => __DIR__ . "/cookies.txt",
            CURLOPT_COOKIEFILE => __DIR__ . "/cookies.txt",
        ]);

        if (!empty($postData)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        }
        $html = curl_exec($ch);
        curl_close($ch);
        return json_decode($html, true) ?: null;
    }
}
