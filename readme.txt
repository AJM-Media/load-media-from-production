=== Load Media from Production ===
Contributors: ajmmedia
Donate link: https://ko-fi.com/ajmmedia
Tags: images, media, staging, local development, missing images
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fix missing images on local and staging sites by loading media from your live site.

== Description ==

Working on a local or staging copy of a WordPress site? Copying the uploads folder can take hours and gigabytes. Load Media from Production skips that: any image or file missing from your copy loads straight from your live site.

= How it works =

* **Rewrites media URLs** in featured images, galleries, srcset, page builders, theme templates and inline CSS backgrounds, so they point at your live site.
* **Redirects missing files**: when the browser asks for a file your copy doesn't have, it's sent to the same file on your live site, or downloaded into your copy so it keeps working offline.
* **Local files come first**: images you upload on your copy keep working. Only files missing locally come from production.

= Built to be safe =

* Never runs on a site marked as production with `WP_ENVIRONMENT_TYPE`.
* Never points a site at itself.
* Pauses itself if the database is copied to a different domain, until you check and save the settings again.
* Shows "Media: production" in the admin bar while it's on, so you always know where images are coming from.
* Only reads from your live site. It never uploads, changes or deletes anything there.

= Works with your local setup =

Choose your tool (Local, DevKinsta, DDEV, Lando, Laragon, MAMP, XAMPP, wp-env, Laravel Valet or Herd, or Docker) and the settings page shows any server change needed. A built-in test checks that it works.

= Settings in wp-config.php =

Teams can set the plugin in `wp-config.php`, so it survives database pulls and never needs setting up in the admin:

`define( 'LMFP_PRODUCTION_URL', 'https://example.com' );`

* `LMFP_PRODUCTION_URL`: your live site. Setting it turns the plugin on.
* `LMFP_UPLOADS_URL`: full uploads URL on your live site, if its uploads path differs from your copy's.
* `LMFP_DISABLE`: set to `true` to turn the plugin off.

Developers can use the `lmfp_should_proxy` filter to decide file by file whether a file loads from production.

Looking for a replacement for BE Media from Production or Uploads by Proxy, which are no longer available? This plugin does the same job, with wider coverage and safety checks.

= Contributing =

Source code and issue tracker: [github.com/AJM-Media/load-media-from-production](https://github.com/AJM-Media/load-media-from-production)

= Privacy =

The plugin collects no data and contacts no service other than the production site you enter.

== Installation ==

1. Install and activate the plugin on your local or staging site, not on your live site.
2. Go to Settings > Media from Production.
3. Tick "Load media from production" and enter your live site's address, for example https://example.com.
4. Choose your local environment and save.
5. Under Server setup, click "Test missing-file handling" to check your server passes missing files to WordPress.

== Frequently Asked Questions ==

= Should I install this on my live site? =

No. It's for local and staging copies. If it's left active on a site where `WP_ENVIRONMENT_TYPE` is set to `production`, it does nothing.

= Do I need to change my server settings? =

Usually not. Rewriting media URLs works everywhere. Redirecting missing files needs the web server to pass requests for missing files to WordPress, which Apache does by default. Some nginx setups answer those requests themselves; the settings page shows the block to add and a test to confirm it.

= Images I upload on my local site aren't showing on my live site =

That's expected. This plugin only reads from your live site and never sends files to it.

= Can I keep a local copy of the files? =

Yes. Under "Missing files", choose "Download a copy into this site's uploads folder". Each file is downloaded the first time it's viewed.

= Does it work with media offloaded to S3 or a CDN? =

If your media already loads from S3 or a CDN on every copy of the site, you don't need this plugin.

= The plugin paused itself after I copied the database =

The settings are tied to the site address they were saved on, so a database copied to another domain doesn't load media from the wrong place. Check the settings and save them to turn it back on.

== Screenshots ==

1. Settings: your live site's address, local environment, method, and the server test result.
2. The admin bar shows when media is loading from production.

== Changelog ==

= 1.0.0 =
* First release.
