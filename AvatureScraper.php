<?php

class AvatureScraper
{
    protected string $tenant;
    protected Database $db;
    protected int $perPage = 50;

    public function __construct(string $tenant, Database $db)
    {
        $this->tenant = rtrim($tenant, '/');
        $this->db = $db;
    }

    public function scrape(): void
    {
        $state = $this->db->getState($this->tenant);
        $offset = (int)$state['last_offset'];

        if ($state['completed']) {
            echo "✔ Skipping completed tenant {$this->tenant}\n";
            return;
        }

        echo "    Starting from offset $offset\n";

        do {
            $url = "{$this->tenant}/careers/SearchJobs/?jobRecordsPerPage={$this->perPage}&jobOffset={$offset}";
            $html = $this->fetchHtml($url);

            if (!$html) {
                echo "    ✘ Failed to fetch listings page at offset $offset\n";
                break;
            }

            $jobs = $this->parseListings($html);

            if (empty($jobs)) {
                break;
            }

            // Fetch job details in parallel
            $details = $this->fetchJobDetailsBatch($jobs);
            
            // Prepare batch for database
            $jobsToSave = [];
            foreach ($details as $idx => $detail) {
                if ($detail) {
                    $jobsToSave[] = array_merge($jobs[$idx], $detail, [
                        'tenant' => $this->tenant
                    ]);
                }
            }
            
            // Batch insert to database
            if (!empty($jobsToSave)) {
                $this->db->saveJobsBatch($jobsToSave);
            }

            $offset += $this->perPage;
            $this->db->updateState($this->tenant, $offset);

        } while (count($jobs) === $this->perPage);

        $this->db->updateState($this->tenant, $offset, true);
    }

    /**
     * Check if a URL is an Avature portal by looking for the meta tag
     */
    protected function isAvaturePortal($html): bool
    {
        // Look for <meta name="avature.portal.name" content="External Careers">
        return (bool)preg_match('/<meta\s+name=["\']avature\.portal\.name["\']\s+/', $html);
    }

    function parseListings($html) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        $jobs = [];
        $articles = $xpath->query("//article[contains(@class,'article--result')]");

        foreach ($articles as $article) {
            $titleNode = $xpath->query(".//h3//a", $article)->item(0);
            if (!$titleNode) continue;

            $jobUrl = $titleNode->getAttribute('href');
            preg_match('#/(\d+)$#', $jobUrl, $m);

            $jobs[] = [
                'job_id'  => $m[1] ?? null,
                'title'   => trim($titleNode->textContent),
                'job_url' => $jobUrl
            ];
        }
        return $jobs;
    }

    /**
     * Fetch job details in parallel using curl_multi
     */
    protected function fetchJobDetailsBatch(array $jobs): array
    {
        $mh = curl_multi_init();
        $handles = [];
        
        foreach ($jobs as $idx => $job) {
            $url = strpos($job['job_url'], 'http') === 0 
                ? $job['job_url'] 
                : $this->tenant . $job['job_url'];
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Avature Job Scraper)',
                CURLOPT_TIMEOUT => 30
            ]);
            
            curl_multi_add_handle($mh, $ch);
            $handles[$idx] = $ch;
        }
        
        // Execute all requests in parallel
        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh);
        } while ($active);
        
        // Collect and parse results
        $results = [];
        foreach ($handles as $idx => $ch) {
            $html = curl_multi_getcontent($ch);
            $results[$idx] = $html ? $this->parseJobDetails($html) : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($mh);
        return $results;
    }

    /**
     * Parse job details from HTML (title, description, location, date, etc)
     */
    protected function parseJobDetails(string $html): ?array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);

        // Title - try multiple selectors with fallbacks
        $title = null;
        
        // Try og:title meta tag first
        $ogTitleNode = $xpath->query("//meta[@property='og:title']")->item(0);
        if ($ogTitleNode) {
            $title = $ogTitleNode->getAttribute('content');
        }
        
        // Try various h2/h1 selectors
        if (!$title) {
            $titleNode = $xpath->query("//div[contains(@class,'banner--main')]//h2[contains(@class,'__title')]")->item(0);
            if (!$titleNode) {
                $titleNode = $xpath->query("//h2[contains(@class,'banner__text__title')]")->item(0);
            }
            if (!$titleNode) {
                $titleNode = $xpath->query("//section[contains(@class,'section--jobdetail')]//h2[contains(@class,'section__header__text__title')]")->item(0);
            }
            if (!$titleNode) {
                $titleNode = $xpath->query("//h1")->item(0);
            }
            
            if ($titleNode) {
                $title = trim($titleNode->textContent);
            }
        }

        // ===== JSON-LD STRUCTURED DATA =====
        // Extract datePosted from JSON-LD if available
        if (preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $jsonMatch)) {
            $jsonData = json_decode($jsonMatch[1], true);
            if (isset($jsonData['datePosted'])) {
                $datePosted = $jsonData['datePosted'];
            }
        }

        // ===== GENERAL INFORMATION METADATA =====
        $metadata = [];

        $generalInfoArticle = $xpath->query(
            "//*[contains(@class,'article--details')][.//h3[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'general information')]]"
        )->item(0);

        if ($generalInfoArticle) {
            $fields = $xpath->query(
                ".//div[contains(@class,'article__content__view__field')]",
                $generalInfoArticle
            );

            foreach ($fields as $field) {
                $labelNode = $xpath->query(
                    ".//div[contains(@class,'__field__label')]",
                    $field
                )->item(0);

                $valueNode = $xpath->query(
                    ".//div[contains(@class,'__field__value')]",
                    $field
                )->item(0);

                if (!$labelNode || !$valueNode) continue;

                $label = strtolower(trim($labelNode->textContent));
                $label = preg_replace('/\s+/', '_', $label); // normalize keys
                $label = preg_replace('/[^a-z0-9_]/', '', $label); // remove special chars

                $value = trim(preg_replace('/\s+/', ' ', $valueNode->textContent));

                $metadata[$label] = $value;
            }
        }
        
        // Use JSON-LD date if available and not already in metadata
        if (isset($datePosted) && empty($metadata['date_published'])) {
            $metadata['date_published'] = $datePosted;
        }

        /* =============================
        DESCRIPTION & REQUIREMENTS
        ============================= */
        $descriptionHtml = null;
        $descriptionText = null;

        $descriptionArticles = $xpath->query(
            "//*[contains(@class,'article--details')][not(.//h3[contains(normalize-space(.),'General information') or contains(normalize-space(.),'General Information')])]"
        );
        $htmlParts = [];
        foreach ($descriptionArticles as $article) {
            $htmlParts[] = $this->innerHTML($article);
        }
        $descriptionHtml = implode("\n\n", $htmlParts);
        $descriptionText = trim(
                preg_replace('/\s+/', ' ', strip_tags($descriptionHtml))
            );

        return [
            'title' => $title,
            'description_html' => $descriptionHtml,
            'description_text' => $descriptionText,
            'metadata' => $metadata
        ];
    }

    protected function fetchHtml($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Avature Job Scraper)',
            CURLOPT_TIMEOUT => 30
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
        return $html;
    }

    protected function innerHTML(DOMNode $node) {
        $doc = $node->ownerDocument;
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }
        return $html;
    }
}
