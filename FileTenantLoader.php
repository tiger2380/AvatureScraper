<?php

class FileTenantLoader
{
    private string $filePath;
    private array $tenants = [];

    public function __construct(string $filePath)
    {
        if (!is_readable($filePath)) {
            throw new RuntimeException("Cannot read file: {$filePath}");
        }

        $this->filePath = $filePath;
    }

    /**
     * Load and normalize Avature tenants from file
     *
     * @return array<string> List of base tenant URLs
     */
    public function load(): array
    {
        $handle = fopen($this->filePath, 'r');

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') continue;

            $tenant = $this->extractTenant($line);
            if ($tenant) {
                $this->tenants[] = $tenant;
            }
        }

        fclose($handle);

        return array_values(array_unique($this->tenants));
    }

    /**
     * Extract base tenant URL from any Avature link
     *
     * @param string $url
     * @return string|null
     */
    private function extractTenant(string $url): ?string
    {
        if (!preg_match('#https?://([a-z0-9\-]+\.avature\.net)#i', $url, $m)) {
            return null;
        }

        return 'https://' . strtolower($m[1]);
    }
}
