<?php

require 'utils.php';

class Database
{
    protected PDO $pdo;

    public function __construct(string $file = 'scraper.db')
    {
        $this->pdo = new PDO("sqlite:$file");
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function execFile(string $file): void
    {
        $this->pdo->exec(file_get_contents($file));
    }

    /* ---------- TENANTS ---------- */

    public function saveTenant(string $domain): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT OR IGNORE INTO tenants VALUES (?, ?)"
        );
        $stmt->execute([$domain, date('c')]);
    }

    public function getTenants(): array
    {
        return $this->pdo
            ->query("SELECT domain FROM tenants")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /* ---------- JOBS ---------- */

    public function jobExists(string $jobId, string $tenant): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM jobs WHERE job_id=? AND tenant=?"
        );
        $stmt->execute([$jobId, $tenant]);
        return (bool)$stmt->fetchColumn();
    }

    public function saveJobsBatch(array $jobs): void
    {
        $this->pdo->beginTransaction();
        try {
            foreach ($jobs as $job) {
                $this->saveJob($job);
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function saveJob(array $job): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT OR IGNORE INTO jobs
            (job_id, tenant, title, description, location, date_posted, apply_url, metadata, scraped_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $metadata = $job['metadata'] ?? [];
        
        // Find date from multiple possible field names
        $datePosted = find_in_array($metadata, 'date') 
            ?? find_in_array($metadata, 'posted') 
            ?? find_in_array($metadata, 'created') 
            ?? null;
        
        // Find location from multiple possible field names
        $location = find_in_array($metadata, 'location') 
            ?? find_in_array($metadata, 'place') 
            ?? find_in_array($metadata, 'city') 
            ?? find_in_array($metadata, 'country') 
            ?? null;
        
        $stmt->execute([
            $job['job_id'],
            $job['tenant'],
            $job['metadata']['job_title'] ?? $job['title'] ?? null,
            $job['description_html'] ?? null,
            $location,
            $datePosted,
            $job['job_url'] ?? null,
            json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            date('c')
        ]);
    }

    /* ---------- RESUME SUPPORT ---------- */

    public function getState(string $tenant): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT last_offset, completed FROM crawl_state WHERE tenant=?"
        );
        $stmt->execute([$tenant]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['last_offset' => 0, 'completed' => 0];
    }

    public function updateState(string $tenant, int $offset, bool $done = false): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO crawl_state (tenant, last_offset, completed)
             VALUES (?, ?, ?)
             ON CONFLICT(tenant) DO UPDATE SET
             last_offset=excluded.last_offset,
             completed=excluded.completed"
        );

        $stmt->execute([$tenant, $offset, $done ? 1 : 0]);
    }

    /* ---------- EXPORT ---------- */

    public function getAllJobs(): array
    {
        return $this->pdo
            ->query("SELECT * FROM jobs ORDER BY scraped_at DESC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }
}