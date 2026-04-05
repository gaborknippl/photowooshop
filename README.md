# photowooshop

Photowooshop WordPress plugin for WooCommerce custom photo montage products.

## Automatic updates

This plugin includes an external release update checker.

- It queries the latest published version from the release source
- If no release exists yet, it falls back to the latest tag automatically
- If a newer release tag is available than the plugin `Version`, WordPress shows an update notice
- The updater installs the published source zip automatically

## Important release rules

To make WordPress updates work reliably:

1. Keep `Version` in `photowooshop.php` in sync with your release tag.
2. Create releases with semantic tags, for example:
   - `v1.1.30`
   - `v1.2.0`
3. Ensure each release has a generated source zip.
4. Recommended: always publish a Release object, but tags alone still work as fallback.

## Release checklist

1. Update plugin version in `photowooshop.php`.
2. Commit and push to `main`.
3. Create a new release with matching tag (for example `v1.1.30`).
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
