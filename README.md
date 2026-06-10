# Blogger Recovery Tools

A WordPress recovery plugin built specifically around the Blogger migration issues found on ranalino.co.

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

## 🛠️ Installation

1. Upload the `blogger-recovery` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Tools > Blogger Recovery** to start the process.

## 📋 Recommended Workflow

1. **Backup your database** before running any automated cleanup.
2. Run the **Issues Detector** to see the extent of migration issues.
3. Run **Image Recovery** to bring all images over to your server.
4. Run **HTML Cleanup** to ensure your posts look modern and clean.
5. Review unresolved `.html` links, then migrate valid Redirection rules to Yoast Premium.

## 📝 Technical Details

- **AJAX Driven**: Batch processing ensures the plugin can handle thousands of posts without timing out.
- **Deterministic Batches**: Published posts are processed in ascending post ID order.
- **WordPress APIs**: Uses native attachment, post update, nonce, capability, and Yoast redirect APIs.

## 👨‍💻 Author

**Reynov Christian** - [Chrisnov IT Solutions](https://chrisnov.com)

## 📄 License

This project is licensed under the GPL v2 or later.
