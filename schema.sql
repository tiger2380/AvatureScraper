CREATE TABLE IF NOT EXISTS tenants (
    domain TEXT PRIMARY KEY,
    discovered_at TEXT
);

CREATE TABLE IF NOT EXISTS jobs (
    job_id TEXT,
    tenant TEXT,
    title TEXT,
    description TEXT,
    location TEXT,
    date_posted TEXT,
    apply_url TEXT,
    metadata TEXT,
    scraped_at TEXT,
    PRIMARY KEY (job_id, tenant)
);

CREATE TABLE IF NOT EXISTS crawl_state (
    tenant TEXT PRIMARY KEY,
    last_offset INTEGER DEFAULT 0,
    completed INTEGER DEFAULT 0
);
