<?php

class TenantGraphDiscovery
{
    protected int $concurrency = 20;
    protected int $timeout = 8;
    protected int $maxDepth = 3;
    protected int $maxTenants = 500;

    protected array $visited = [];
    protected array $validTenants = [];

    /**
     * Entry point
     */
    public function crawl(array $seedCompanies): array
    {
        $queue = [];

        foreach ($seedCompanies as $seed) {
            $queue[] = [
                'company' => strtolower($seed),
                'depth' => 0
            ];
        }

        while (!empty($queue) && count($this->validTenants) < $this->maxTenants) {
            $batch = array_splice($queue, 0, $this->concurrency);

            $results = $this->probeBatch($batch);

            foreach ($results as $result) {
                if (!$result['isValid']) {
                    continue;
                }

                $domain = $result['domain'];

                if (isset($this->visited[$domain])) {
                    continue;
                }

                $this->visited[$domain] = true;
                $this->validTenants[] = $domain;

                if ($result['depth'] + 1 < $this->maxDepth) {
                    foreach ($result['discovered'] as $child) {
                        if (!isset($this->visited[$child])) {
                            $queue[] = [
                                'company' => $child,
                                'depth' => $result['depth'] + 1
                            ];
                        }
                    }
                }
            }
        }

        return $this->validTenants;
    }

    /**
     * Parallel probe batch
     */
    protected function probeBatch(array $batch): array
    {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($batch as $item) {
            $company = $item['company'];
            $url = "https://{$company}.avature.net/careers";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_USERAGENT => 'AvatureGraphCrawler/1.0'
            ]);

            curl_multi_add_handle($multi, $ch);
            $handles[(int)$ch] = [$ch, $item];
        }

        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi);
        } while ($running > 0);

        $results = [];

        foreach ($handles as [$ch, $item]) {
            $html = curl_multi_getcontent($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $company = $item['company'];

            $isValid = ($status >= 200 && $status < 300 && $this->isAvature($html));

            $results[] = [
                'domain' => "https://{$company}.avature.net",
                'isValid' => $isValid,
                'depth' => $item['depth'],
                'discovered' => $isValid ? $this->extractTenants($html) : []
            ];

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);

        return $results;
    }

    /**
     * Detect Avature fingerprint
     */
    protected function isAvature(string $html): bool
    {
        if (strlen($html) < 500) return false;

        foreach (['SearchJobs', 'JobDetail', 'avature'] as $sig) {
            if (stripos($html, $sig) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract outbound avature tenants
     */
    protected function extractTenants(string $html): array
    {
        preg_match_all(
            '/https?:\/\/([a-z0-9\-]+)\.avature\.net/i',
            $html,
            $matches
        );

        return array_unique($matches[1] ?? []);
    }
}
