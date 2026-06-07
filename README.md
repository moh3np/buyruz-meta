# Buyruz Metadata & Taxonomy Snapshots

> Static snapshots of the Buyruz e-commerce taxonomy (brands, tags, categories, attributes, and products) optimized for LLMs, autonomous agents, and search engines.

Live URL: **[meta.buyruz.com](https://meta.buyruz.com)**

---

## 🌟 Overview
This repository manages and distributes static snapshots of the Buyruz taxonomy. It bridges the gap between Google Sheets (where taxonomy is managed by humans) and autonomous agents/machines requiring structured data.

### Key Features
- **Auto-Sync:** A scheduled GitHub Action automatically pulls data from Google Sheets, sanitizes Persian text, URL-decodes cells, and publishes updates every 6 hours.
- **Agent-First Design:** Includes a unified `taxonomy.json` containing metadata, epoch timestamps for freshness validation, and raw arrays.
- **Human-Readable Tables:** Styled static HTML pages with sticky headers and responsive layouts.
- **Custom CDN Integration:** Served securely via ParsPack CDN and GitHub Pages on `meta.buyruz.com`.

---

## 📂 Repository Structure

| File | Type | Description | Link |
|---|---|---|---|
| `taxonomy.json` | JSON | Unified snapshot containing all datasets, counts, and metadata. | [View](https://meta.buyruz.com/taxonomy.json) |
| `products.html` / `.json` | HTML / JSON | WooCommerce products, short names, brands, and IDs. | [HTML](https://meta.buyruz.com/products.html) / [JSON](https://meta.buyruz.com/products.json) |
| `brands.html` / `.json` | HTML / JSON | Brand names and clean URL-friendly slugs. | [HTML](https://meta.buyruz.com/brands.html) / [JSON](https://meta.buyruz.com/brands.json) |
| `categories.html` / `.json` | HTML / JSON | Hierarchical category tree and sidebar filter requirements. | [HTML](https://meta.buyruz.com/categories.html) / [JSON](https://meta.buyruz.com/categories.json) |
| `attributes.html` / `.json` | HTML / JSON | Product attributes (colors, sizes, age groups) with parsed options. | [HTML](https://meta.buyruz.com/attributes.html) / [JSON](https://meta.buyruz.com/attributes.json) |
| `tags.html` / `.json` | HTML / JSON | Product tags and IDs. | [HTML](https://meta.buyruz.com/tags.html) / [JSON](https://meta.buyruz.com/tags.json) |
| `refresh.php` | PHP | Core CLI/Web script that fetches Google Sheets, normalizes cells, and builds static files. | [Source](refresh.php) |
| `index.html` | HTML | Navigation landing page with statistics and usage guides. | [View](https://meta.buyruz.com/index.html) |

---

## ⚙️ Automated Workflow
The automation is powered by GitHub Actions:
- **Location:** `.github/workflows/cron-refresh.yml`
- **Schedule:** Every 6 hours (`0 */6 * * *`)
- **Process:** Checks out repository ➡️ Sets up PHP ➡️ Runs `php refresh.php` ➡️ Commits changes ➡️ Deploys to GitHub Pages (`meta.buyruz.com`).

To trigger a manual build, navigate to the **Actions** tab in GitHub, select **Scheduled Taxonomy Refresh**, and click **Run workflow**.

---

## 🤖 Guidelines for AI Agents & LLMs
When writing product descriptions, mapping listings, or updating metadata:
1. **Prefer `taxonomy.json`:** Use it as the single source of truth.
2. **Freshness Check:** Check `meta.stale_after_seconds` or compare `updated_at_epoch_seconds_utc` to ensure the snapshot isn't outdated.
3. **Strict Constraints:**
   - Do **NOT** create new categories or brands. Only map to existing ones.
   - Do **NOT** create new tags.
   - You may suggest new product attributes only when they do not overlap with existing attributes (e.g., do not create "تعداد تکه‌ها" if "تعداد قطعات" is already present).
4. **IDs:** Never alter or ignore WooCommerce IDs or Parent IDs when mapping tags, categories, or products.

---

## 🛠 Manual Execution
You can run the generator script manually for local testing:
```bash
php refresh.php
```
When running locally via CLI, authentication is bypassed. To trigger via browser, append the secret parameter:
```
http://localhost/refresh.php?secret=YOUR_SECRET
```

---
*Created and maintained automatically by Buyruz Development Team.*
