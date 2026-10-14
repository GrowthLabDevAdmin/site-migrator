# Site Migrator

WordPress plugin to migrate content from external (non-WordPress) sites: it extracts text as clean HTML, uploads images to the Media Library and creates pages or posts.

The interface is available in **English** and **Spanish**, depending on the WordPress language (Settings → General → Site Language, or the user's own language in their profile). Any Spanish variant (es_MX, es_AR, es_CO…) uses the Spanish translation.

## Installation

Upload the `site-migrator` folder to `wp-content/plugins/` (or the zip from **Plugins → Add New → Upload Plugin**) and activate it. Requires WordPress 6.0+ and PHP 7.4+ with the DOM and mbstring extensions.

## Migration modes

| Mode | Creates pages | Images |
|---|---|---|
| **Content and images** (default) | Yes | Uploaded to the Media Library and replaced in the content |
| **Content only** | Yes | Removed from the content, or kept pointing to the original site (configurable) |
| **Images only** | No | Uploaded to the Media Library, unattached. Only those inside the content container, or every image on the page (configurable) |

In **Images only** mode, background images defined in CSS are also imported (can be turned off). It scans `style` attributes and lazy-load attributes (`data-bg`, `data-background`, `data-background-image`); with "every image on the page" it also scans `<style>` tags, which are not filtered by **Remove elements**. CSS from external `.css` files is not scanned, and SVG backgrounds are skipped with a warning.

Images already in the library (matched by original URL) are never duplicated, so the modes can be combined in separate runs. With "every image on the page", use **Remove elements** (e.g. `header, footer, .social`) to leave out logos and icons.

## Usage from the admin

Under **Tools → Site Migrator**:

1. Choose what to migrate. Enter the old site's sitemap URL (or paste a list of URLs).
2. Enter the CSS selector of the content container (`main`, `#content`, `.entry-content`…). Use the browser inspector on the old site to find it.
3. Click **Get URLs** and review the list; you can remove or add URLs.
4. Generate a **preview** of several different pages. Adjust the selector and **Remove elements** until only the content is extracted.
5. Click **Start migration**. It runs one page per request and one image per request, so there are no timeout issues. Download the CSV report when it finishes.

## Usage from WP-CLI

```bash
# Test without writing anything
wp migrate-site run --sitemap=https://old-site.com/sitemap.xml --selector="main" --dry-run --limit=3

# Real migration
wp migrate-site run --sitemap=https://old-site.com/sitemap.xml --selector="main" \
  --exclude-urls="/blog/" --seo=yoast --report=redirects.csv --user=1

# Images only, every image on the page except header and footer
wp migrate-site run --sitemap=https://old-site.com/sitemap.xml --mode=images --image-scope=page \
  --exclude-selector="header, footer" --user=1

# Content only, removing images from the text
wp migrate-site run --sitemap=https://old-site.com/sitemap.xml --selector="main" --mode=content --user=1

# Blog separately, as posts
wp migrate-site run --sitemap=https://old-site.com/sitemap.xml --selector="article" \
  --include-urls="/blog/" --post-type=post --user=1
```

`wp help migrate-site run` lists every option. Runtime messages follow the site language; the `wp help` text is always in English because WP-CLI reads it from the code's docblock.

## What it does with the content

- Keeps `p`, `h1–h6`, lists, `strong/em/b/i/u`, `br`, links (`href`, `title`), tables, `blockquote`, `figure` and images. Removes all classes, styles and IDs.
- Removes `div`, `section`, `main`, etc. while keeping their content, and wraps loose text in `<p>`.
- Removes `script`, `style`, `nav`, `header`, `footer`, forms and SVG.
- Uses the first H1 as the post title and removes it from the content.
- Images: detects lazy loading (`data-src`, `data-lazy-src`) and picks the largest `srcset` candidate. Images repeated across pages are not duplicated.
- YouTube and Vimeo iframes become a bare URL so WordPress embeds them.
- Internal links become relative paths.
- Child pages are assigned to their parent based on the URL structure.
- Each post stores its original URL in `_sm_source_url`. Running again skips what was already migrated, unless the update option is enabled.

## Known limitations

- **JavaScript-rendered sites** (Wix, React/Angular SPAs): PHP only receives the initial HTML, so the selector finds no content.
- CSS background images are only imported in **Images only** mode, and only from `style` attributes and `<style>` tags, not external `.css` files. Sliders or galleries loaded with JavaScript and SVG files are not migrated.
- Images that fail keep pointing to the old site and are listed in the report. Review them before the old site goes offline.
- Content is imported as a Classic block. Use **Convert to blocks** in the editor.
- The CSS selector supports tag, `#id`, `.class`, `[attribute]`, `[attribute=value]`, descendant, `>` and commas. Use the XPath field for anything more complex.

## Translations

Source strings are in English. Translation files live in `languages/`:

- `site-migrator.pot`: template with every string.
- `site-migrator-es_ES.po` / `.mo`: Spanish.

To add a language or edit the Spanish one, open the `.pot` (or the `.po`) in [Poedit](https://poedit.net/) or the Loco Translate plugin, translate, and save as `site-migrator-{locale}.po`; the editor generates the `.mo` automatically. If you add new strings to the code, regenerate the `.pot` with `wp i18n make-pot . languages/site-migrator.pot`.

## Undo a test migration

```bash
wp post delete $(wp post list --post_type=page --meta_key=_sm_source_url --format=ids) --force
wp post delete $(wp post list --post_type=attachment --meta_key=_sm_source_url --format=ids) --force
```

## Structure

```
site-migrator/
├── site-migrator.php                    # Bootstrap and translation loading
├── uninstall.php
├── includes/
│   ├── class-site-migrator.php          # Engine (extraction, cleanup, import)
│   ├── class-site-migrator-admin.php    # Screen and AJAX endpoints
│   └── class-site-migrator-cli.php      # WP-CLI command
├── assets/
│   ├── admin.js
│   └── admin.css
└── languages/
    ├── site-migrator.pot
    ├── site-migrator-es_ES.po
    └── site-migrator-es_ES.mo
```
