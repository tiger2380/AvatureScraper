# Avature Job Scraper

## Overview

This project is a scalable web scraper designed to extract job postings from **Avature-hosted career sites**.
Rather than scraping individual pages manually, the system automatically **discovers Avature tenants**, retrieves the **complete job inventory**, enriches each job with detailed metadata, and persists results in a **resume-safe SQLite database**.

The final dataset can be exported as **JSON**, **CSV**, or accessed through a lightweight **read-only API**.

---

## Key Features

* **Automated Avature tenant discovery**
* **Auto-expanding tenant graph** (no hardcoded domains)
* **Parallel validation for fast coverage**
* **Full job inventory scraping via pagination**
* **Job detail enrichment (title, description, metadata)**
* **SQLite persistence with resume support**
* **Deduplication across runs**
* **Multiple exporters (JSON / CSV / API)**

---

## Architecture



Each stage is isolated and restartable.

---

## How Tenant Discovery Works

1. Start with a small list of seed companies.
2. Probe `{company}.avature.net/careers`.
3. Validate Avature tenants using HTML fingerprints.
4. Extract outbound `*.avature.net` references.
5. Recursively expand the tenant graph with depth and safety limits.

This approach allows discovery of **previously unknown Avature tenants** without relying on a static list.

---

## How Job Scraping Works

For each discovered tenant:

1. Reverse-engineer Avature’s `/careers/SearchJobs` endpoint.
2. Paginate through the full job inventory (50 jobs per page).
3. Extract job IDs and detail URLs from listing pages.
4. **Parallel job detail fetching** using `curl_multi` for performance.
5. **Batch database inserts** to minimize I/O overhead.
6. Parse each job detail page to extract:

   * Job title (from Open Graph meta tags or HTML headers)
   * Job description (HTML and plain text versions)
   * Metadata fields (location, career area, remote status, posted date, reference number, etc.)
   * Application URL

All jobs are deduplicated by `(job_id, tenant)`.

**Parsing Features:**
* Multiple fallback selectors for title extraction
* Flexible HTML structure handling (supports both `<article>` and `<div>` based layouts)
* Case-insensitive metadata field matching
* Extracts structured metadata from "General Information" sections
* Separates job descriptions from metadata sections automatically

---

## Persistence & Resume Support

The scraper uses **SQLite** as its source of truth.

Stored tables:

* `tenants` – discovered Avature domains
* `jobs` – enriched job data
* `crawl_state` – pagination offsets and completion flags

This enables:

* Safe interruption and resume
* Skipping completed tenants
* Incremental re-runs without data loss

You can stop the scraper at any time and re-run it.

---

## Project Structure

```
avature-scraper/
│
├── run.php                  # Main entry point
├── schema.sql               # SQLite schema
├── scraper.db               # SQLite database
├── config.php               # Store Google API key
│
├── Database.php             # SQLite wrapper
├── TenantGraphDiscovery.php # Auto-expanding tenant discovery
├── AvatureScraper.php       # Job inventory + detail scraper
├── AutoTenantDiscovery.php  # Search Google for potential tenants
├── FileTenantLoader.php     # Load the list of URLs
│
├── export_json.php          # JSON exporter
├── export_csv.php           # CSV exporter
├── api.php                  # Read-only API
│
└── output/
    ├── jobs.json
    └── jobs.csv
```

---

## Running the Scraper

### Requirements

* PHP 8.0+
* SQLite enabled
* cURL enabled
* Serper API key (for tenant discovery)

### Setup

1. **Get a Serper API Key:**
   - Visit [serper.dev](https://serper.dev/)
   - Sign up for a free account (includes 2,500 free searches)
   - Go to your dashboard and copy your API key

2. **Configure the API Key:**
   - Open `config.php` in the project root
   - Replace the placeholder with your actual API key:
     ```php
     <?php
     return [
         'serper_api_key' => 'your_actual_api_key_here',
     ];
     ```

### Run Everything

```bash
php run.php
```

This will:

1. Discover Avature tenants
2. Scrape all jobs
3. Persist results
4. Export JSON and CSV files

The scraper is **resume-safe** — rerunning continues where it left off.

---

## Exporting Data

### JSON Export

```bash
php export_json.php
```

### CSV Export

```bash
php export_csv.php
```

### API Access

Start a PHP server:

```bash
php -S localhost:8000
```

Example endpoints:

```
/api.php
/api.php?limit=50
/api.php?tenant=https://bloomberg.avature.net
```

---

## Example Job Output

```json
{
  "job_id": "21581",
  "tenant": "https://amerilife.avature.net",
  "title": "Entry Level Insurance Representative",
  "description_html": "<div>We are looking for driven, enthusiastic...</div>",
  "description_text": "We are looking for driven, enthusiastic, opportunity-seeking people...",
  "metadata": {
    "career_area": "Technology",
    "work_locations": "601 S. Tryon Street, NC",
    "remote": "No",
    "ref": "21581",
    "posted_date": "01-29-26",
    "working_time": "Full time"
  },
  "job_url": "https://amerilife.avature.net/careers/JobDetail/21581",
  "scraped_at": "2026-01-29T15:23:10Z"
}
```

---

## Engineering Decisions

* **Pagination-based scraping** instead of UI scraping for completeness
* **Parallel job detail fetching** using `curl_multi` for 10-20x speedup
* **Batch database inserts** to reduce I/O overhead
* **DOM parsing with XPath** instead of regex for robustness
* **Multiple fallback selectors** to handle HTML variations across tenants
* **Parallel tenant discovery** for performance
* **SQLite persistence** for reliability and resumability
* **Exporter separation** to avoid re-scraping
* **Flexible metadata extraction** with case-insensitive field matching

---

## Performance Optimizations

* **Parallel HTTP requests**: Fetches multiple job details simultaneously
* **Batch database operations**: Inserts multiple jobs in single transactions
* **Resume capability**: Tracks pagination offsets to avoid re-scraping
* **Completion flags**: Skips already-completed tenants automatically

---

## Limitations & Notes

* Some Avature tenants use slightly different HTML structures (scraper includes multiple fallback selectors)
* Metadata field names vary by tenant (normalized to snake_case)
* Rate limiting is intentionally conservative to avoid overload
* Open Graph meta tags are prioritized for title extraction when available

---

## Future Improvements

* ~~Parallel job detail scraping~~ ✅ Implemented
* Location normalization and geocoding
* Retry and exponential backoff strategies
* Crawl metrics dashboard
* Advanced search and filtering API
* Persistent tenant graph scoring
* Incremental updates (detect new jobs only)

---

## Conclusion

This project demonstrates a complete, production-style scraping pipeline:
from automated discovery to data persistence and export.
It emphasizes coverage, reliability, and clean system design over one-off scraping.

