# Blogger Recovery Tools

A WordPress recovery plugin built specifically around the Blogger migration issues found on ranalino.co.

Current plugin version: **2.2.0**

## 🚀 Features

- **Issues Detector**: Scans published posts for Blogger image wrappers, remote Blogger image sources, embedded AdSense, legacy `.html` links, and old Blogger markup.
- **Image Recovery**: Removes obsolete Blogger link wrappers and imports images whose `src` still points to Blogger.
- **HTML Cleanup**: Modernizes old Blogger HTML markup:
  - Removes AdSense blocks (Tables, Scripts, and Ads).
  - Fixes malformed HTML quotes (e.g., `align=""left""`).
  - Resolves Blogger `.html` links to matching WordPress post slugs.
  - Converts deprecated `align` attributes to modern CSS classes.
  - Modernizes table structures.
  - Converts Blogger caption tables into standard WordPress `<figure>` and `<figcaption>` elements.
- **Redirect Migrator**: Exports active Redirection rules to CSV and imports them through the Yoast SEO Premium redirect API.
- **Dry-run by Default**: Image recovery, HTML cleanup, and redirect migration preview their work without writing files or database changes.
- **Explicit Apply Guard**: Write operations require disabling dry-run and typing `APPLY`.
- **Full Database Backup**: Generates a temporary full SQL dump, downloads it as `.sql.gz`, and removes the server-side temporary file immediately.

## 🛠️ Installation

1. Upload the `blogger-recovery` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Tools > Blogger Recovery** to start the process.

## 📋 Recommended Workflow

1. **Backup your database** before running any automated cleanup.
2. Run the **Issues Detector** to see the extent of migration issues.
3. Download a **Full Database Backup** and store it securely; it contains sensitive user and configuration data.
4. Run each recovery module in **Dry-run** mode and review its log.
5. Run **Image Recovery** in Apply mode only after verifying the image report.
6. Run **HTML Cleanup** in Apply mode; manually authored links such as `BACA JUGA` are resolved from their `href` using current post slugs and active Redirection rules.
7. Review unresolved `.html` links, export redirect CSV, then migrate valid Redirection rules to Yoast Premium.

## 📝 Technical Details

- **AJAX Driven**: Batch processing ensures the plugin can handle thousands of posts without timing out.
- **Deterministic Batches**: Published posts are processed in ascending post ID order.
- **WordPress APIs**: Uses native attachment, post update, nonce, capability, and Yoast redirect APIs.

## 👨‍💻 Author

**Reynov Christian** - [Chrisnov IT Solutions](https://chrisnov.com)

## 📄 License

This project is licensed under the GPL v2 or later.
