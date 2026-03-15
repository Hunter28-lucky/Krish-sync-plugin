# Content Tracker Sync — WordPress Plugin

Sync WordPress post data (title, slug, Yoast SEO fields, tags) to a Google Sheets editorial tracker with a single click.

---

## Features

- **Manual "Sync to Tracker" button** in the post editor (Classic + Gutenberg)
- **Auto-creates a new row** or **updates existing row** (duplicate prevention via Post ID)
- **Hashtag conversion** — tags are formatted as `#AI #HR #Automation`
- **Yoast SEO support** — retrieves SEO Title, Focus Keyphrase, and Meta Description
- **Zero Composer dependencies** — self-contained JWT-based Google Sheets API v4 client
- **Secure** — nonces, capability checks, input sanitisation, output escaping

---

## Installation

1. Copy the `content-tracker-sync` folder into `wp-content/plugins/`
2. Activate from **WP Admin → Plugins**
3. Go to **Settings → Content Tracker Sync** and configure your Google Sheet

---

## Google API Setup

### Step 1 — Create a Google Cloud Project

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project (or select an existing one)

### Step 2 — Enable the Google Sheets API

1. Navigate to **APIs & Services → Library**
2. Search for **Google Sheets API** and click **Enable**

### Step 3 — Create a Service Account

1. Go to **APIs & Services → Credentials**
2. Click **Create Credentials → Service Account**
3. Give it a name (e.g. `content-tracker-sync`)
4. Click **Done**

### Step 4 — Download the JSON Key

1. Click your new Service Account
2. Go to the **Keys** tab → **Add Key → Create new key → JSON**
3. Save the downloaded `.json` file

### Step 5 — Share Your Google Sheet

1. Open your Google Sheet
2. Click **Share**
3. Add the `client_email` from the JSON file (looks like `name@project.iam.gserviceaccount.com`)
4. Give it **Editor** access

### Step 6 — Configure the Plugin

1. In WordPress, go to **Settings → Content Tracker Sync**
2. Paste the **entire contents** of the JSON key file into the credentials field
3. Enter your **Spreadsheet ID** (from the sheet URL between `/d/` and `/edit`)
4. Enter the **Sheet Name** (tab name, e.g. `Sheet1`)
5. Click **Save Settings**

---

## Google Sheet Format

Set up your sheet columns in this order:

| Column | Header              | Source                          |
|--------|---------------------|---------------------------------|
| A      | Post ID             | WordPress Post ID               |
| B      | Topic               | Post Title                      |
| C      | Post Slug           | URL slug                        |
| D      | SEO Title           | Yoast SEO Title                 |
| E      | Keywords            | Tags (comma-separated)          |
| F      | Keywords with tags  | Tags as `#Tag1 #Tag2`           |
| G      | Meta Description    | Yoast Meta Description          |
| H      | Focus Keyphrase     | Yoast Focus Keyphrase           |

> **Tip:** Add these headers in Row 1 of your sheet before syncing.

---

## Usage

1. Create or edit any post
2. Fill in Yoast SEO fields and assign tags
3. In the sidebar, find the **Content Tracker Sync** panel
4. Click **Sync to Tracker**
5. A green ✓ confirmation appears on success

---

## Security Considerations

- **Nonce verification** on every AJAX request
- **Capability check** — only users with `edit_posts` can sync
- **Input sanitisation** — all settings use `sanitize_text_field`
- **Output escaping** — all rendered strings use `esc_html`, `esc_attr`
- **JSON validation** — credentials are validated before saving
- **No filesystem storage** — credentials stored in `wp_options`, not as files
- **Multi-site safe** — each site has its own settings, nothing is hardcoded

---

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Yoast SEO plugin (for SEO fields; plugin works without it, but SEO columns will be empty)
- Google Cloud project with Sheets API enabled

---

## Future Improvements

- Bulk sync from the Posts list screen
- Custom column mapping via the settings page
- Webhook/notification support
- Sync history log in WordPress
- Support for Custom Post Types selection
- WP-CLI `wp cts sync <post_id>` command
- REST API endpoint alternative to AJAX
