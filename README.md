# Load Media from Production

WordPress plugin that fixes missing images on local and staging sites by
loading media from the live site, so the uploads folder doesn't need copying.

- Rewrites uploads URLs in WordPress output (attachments, srcset, page
  builders, theme templates, inline CSS and JSON-escaped URLs).
- Redirects requests for missing uploads files to production, or downloads
  them into the local uploads folder.
- Leaves files that exist locally alone.
- Never runs on a site explicitly marked as production, never points a site
  at itself, and pauses when the database moves to another domain.

The wp.org listing text is in [`readme.txt`](readme.txt).

## Development

The repo root is the plugin folder. Build the release zip from the committed
code; files marked `export-ignore` in `.gitattributes` are left out:

    git archive --format=zip --prefix=load-media-from-production/ -o ../load-media-from-production.zip HEAD

wp.org listing images (icon, banner, screenshots) are in `.wordpress-org/`.
The icon and banner are rendered from the HTML in `.wordpress-org/src/`.

wp-config constants: `LMFP_PRODUCTION_URL`, `LMFP_UPLOADS_URL`,
`LMFP_DISABLE`. Filter: `lmfp_should_proxy( bool $proxy, string $file )`.

## Support

Found a bug or have an idea? [Open an issue](https://github.com/AJM-Media/load-media-from-production/issues).

The plugin is free. If it saves you time, you can
[buy me a coffee](https://ko-fi.com/ajmmedia).

## Licence

GPLv2 or later.
