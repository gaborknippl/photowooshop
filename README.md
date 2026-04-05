# photowooshop

Photowooshop WordPress plugin for WooCommerce custom photo montage products.

## Automatic updates from GitHub

This plugin includes a GitHub release update checker.

- It queries the latest release from: `gaborknippl/photowooshop`
- If a newer release tag is available than the plugin `Version`, WordPress shows an update notice
- The updater installs the release zip from GitHub

## Important release rules

To make WordPress updates work reliably:

1. Keep `Version` in `photowooshop.php` in sync with your release tag.
2. Create GitHub releases with semantic tags, for example:
   - `v1.1.28`
   - `v1.2.0`
3. Ensure each release has generated source zip (GitHub does this automatically).

## Release checklist

1. Update plugin version in `photowooshop.php`.
2. Commit and push to `main`.
3. Create a new GitHub Release with matching tag (for example `v1.1.28`).
4. In WordPress admin, go to Dashboard -> Updates and check for updates.

## Local git quick start

```bash
git init
git add .
git commit -m "Initial Photowooshop import"
git branch -M main
git remote add origin https://github.com/gaborknippl/photowooshop.git
git push -u origin main
```
