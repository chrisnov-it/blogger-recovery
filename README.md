# Blogger Recovery Tools

A comprehensive WordPress plugin designed to fix common issues after migrating from Blogger (Blogspot) to WordPress.

## 🚀 Features

- **Issues Detector**: Scans all posts to find broken local images, remaining Blogger image URLs, and legacy HTML patterns (like `tr_bq` classes or deprecated align attributes).
- **Image Recovery**: Automatically detects broken image references, finds the original Blogger source, downloads the images, imports them to your WordPress Media Library, and updates post content.
- **HTML Cleanup**: Modernizes old Blogger HTML markup:
  - Removes AdSense blocks (Tables, Scripts, and Ads).
  - Fixes malformed HTML quotes (e.g., `align=""left""`).
  - Converts deprecated `align` attributes to modern CSS classes.
  - Modernizes table structures.
  - Converts Blogger caption tables into standard WordPress `<figure>` and `<figcaption>` elements.

## 🛠️ Installation

1. Upload the `blogger-recovery` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **Tools > Blogger Recovery** to start the process.

## 📋 Recommended Workflow

1. **Backup your database** before running any automated cleanup.
2. Run the **Issues Detector** to see the extent of migration issues.
3. Run **Image Recovery** to bring all images over to your server.
4. Run **HTML Cleanup** to ensure your posts look modern and clean.

## 📝 Technical Details

- **WPCS Compliant**: 100% adherence to WordPress Coding Standards.
- **AJAX Driven**: Batch processing ensures the plugin can handle thousands of posts without timing out.
- **Safe Processing**: Uses WordPress native functions like `wp_insert_attachment` and `wp_update_post`.

## 👨‍💻 Author

**Reynov Christian** - [Chrisnov IT Solutions](https://chrisnov.com)

## 📄 License

This project is licensed under the GPL v2 or later.
