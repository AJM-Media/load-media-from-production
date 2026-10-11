#!/usr/bin/env bash
set -uo pipefail

PHP_VERSION=${PHP_VERSION:-8.3}
WP_VERSION=${WP_VERSION:-latest}
SERVER=${SERVER:-apache}
INSTALL=${INSTALL:-root}

DIR=$(cd "$(dirname "$0")" && pwd)
REPO=$(dirname "$DIR")
WORK=$(mktemp -d)
SLUG=load-media-from-production
PROD=http://prod.test

PASSES=0
FAILS=0
RESULTS=()

die() { echo "SETUP FAILED: $*"; exit 1; }
pass() { PASSES=$((PASSES + 1)); RESULTS+=("PASS $1"); echo "PASS  $1"; }
fail() { FAILS=$((FAILS + 1)); RESULTS+=("FAIL $1: $2"); echo "FAIL  $1"; echo "      $2"; }
expect() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "expected '$2', got '$3'"; fi; }
expect_has() { if grep -qF -- "$2" <<<"$3"; then pass "$1"; else fail "$1" "missing '$2'"; fi; }
expect_count() { local n; n=$(grep -oF -- "$2" <<<"$3" | wc -l); if [ "$n" "$4" "$5" ]; then pass "$1"; else fail "$1" "'$2' found $n times, expected $4 $5"; fi; }

BASE=""
[ "$INSTALL" = subfolder ] && BASE=/sub
SITE_URL=http://site.test$BASE
WPDIR=/var/www/html$BASE

if [ "$SERVER" = apache ]; then
	IMAGE=wordpress:php$PHP_VERSION-apache
	PHPC=site
else
	IMAGE=wordpress:php$PHP_VERSION-fpm
	PHPC=php
fi

x() { docker exec -u www-data -e WP_CLI_CACHE_DIR=/tmp/wp-cli "$PHPC" "$@"; }
wp() { x php -d memory_limit=1G /usr/local/bin/wp --path="$WPDIR" "$@"; }
get() { docker exec "$PHPC" curl -s "$@"; }
status_of() { get -o /dev/null -w '%{http_code} %{redirect_url}' "$1"; }

make_nginx() {
	local conf=$WORK/nginx/default.conf
	sed "s#__BASE__#$BASE#g" "$DIR/conf/nginx-site.conf" > "$conf.tmp"
	[ "$SERVER" = nginx-static ] && sed -i "/#STATIC/r $DIR/conf/nginx-static.conf" "$conf.tmp"
	[ "$INSTALL" = multisite ] && sed -i "/#MULTISITE/r $DIR/conf/nginx-multisite.conf" "$conf.tmp"
	[ -n "${1:-}" ] && sed -i "/#SNIPPET/r $1" "$conf.tmp"
	mv "$conf.tmp" "$conf"
}

echo "== PHP $PHP_VERSION, WordPress $WP_VERSION, $SERVER, $INSTALL"

git -C "$REPO" archive --prefix="$SLUG/" HEAD | tar -x -C "$WORK" || die "git archive"
for private in tests .github .wordpress-org README.md; do
	if [ -e "$WORK/$SLUG/$private" ]; then fail "release zip leaves out $private" "present"; else pass "release zip leaves out $private"; fi
done

mkdir -p "$WORK/prod" "$WORK/nginx"
docker network create lmfp >/dev/null || die "network"
mkdir -p "$WORK/html" && chmod 777 "$WORK/html"
docker run -d --name db --network lmfp -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp mariadb:10.11 >/dev/null || die "db"
docker run -d --name prod --network lmfp --network-alias prod.test \
	-v "$DIR/conf/prod.conf:/etc/nginx/conf.d/default.conf:ro" -v "$WORK/prod:/usr/share/nginx/html:ro" \
	nginx:1.27-alpine >/dev/null || die "prod"

if [ "$SERVER" = apache ]; then
	docker run -d --name site --network lmfp --network-alias site.test -v "$WORK/html:/var/www/html" \
		--entrypoint docker-php-entrypoint "$IMAGE" apache2-foreground >/dev/null || die "site"
else
	docker run -d --name php --network lmfp -v "$WORK/html:/var/www/html" \
		--entrypoint docker-php-entrypoint "$IMAGE" php-fpm >/dev/null || die "php"
	make_nginx
	docker run -d --name web --network lmfp --network-alias site.test -v "$WORK/html:/var/www/html" \
		-v "$WORK/nginx:/etc/nginx/conf.d" nginx:1.27-alpine >/dev/null || die "web"
fi

docker exec "$PHPC" sh -c 'curl -sSLo /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar && chmod +x /usr/local/bin/wp' || die "wp-cli"
x mkdir -p "$WPDIR"
WP_TARBALL=https://wordpress.org/wordpress-$WP_VERSION.tar.gz
[ "$WP_VERSION" = latest ] && WP_TARBALL=https://wordpress.org/latest.tar.gz
x sh -c "curl -sSfL --retry 5 --retry-all-errors -o /tmp/wordpress.tar.gz $WP_TARBALL && tar -xzf /tmp/wordpress.tar.gz --strip-components=1 -C $WPDIR" || die "core download"
echo "WordPress $(wp core version)"
wp config create --dbname=wp --dbuser=root --dbpass=root --dbhost=db --skip-check --quiet || die "config"
wp config set WP_DEBUG true --raw --quiet
wp config set WP_DEBUG_LOG true --raw --quiet
wp config set WP_DEBUG_DISPLAY false --raw --quiet
wp config set AUTOMATIC_UPDATER_DISABLED true --raw --quiet
wp config set DISABLE_WP_CRON true --raw --quiet

for i in $(seq 1 30); do
	if [ "$INSTALL" = multisite ]; then
		wp core multisite-install --url=http://site.test --title=LMFP --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet 2>/dev/null && break
	else
		wp core install --url="$SITE_URL" --title=LMFP --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet 2>/dev/null && break
	fi
	sleep 2
done
wp core is-installed --network 2>/dev/null || wp core is-installed || die "install"

if [ "$SERVER" = apache ]; then
	if [ "$INSTALL" = multisite ]; then
		cp "$DIR/conf/htaccess-multisite" "$WORK/htaccess"
	else
		sed "s#__BASE__#$BASE#g" "$DIR/conf/htaccess-single" > "$WORK/htaccess"
	fi
	docker cp "$WORK/htaccess" "$PHPC:$WPDIR/.htaccess" || die "htaccess"
fi

docker cp "$WORK/$SLUG" "$PHPC:$WPDIR/wp-content/plugins/" || die "copy plugin"
docker cp "$DIR/helpers/lmfp.php" "$PHPC:/tmp/lmfp.php" || die "copy helper"
docker exec "$PHPC" chown -R www-data:www-data "$WPDIR/wp-content/plugins/$SLUG"

x php -r '$i = imagecreatetruecolor(1200, 800); imagefill($i, 0, 0, imagecolorallocate($i, 200, 60, 60)); imagepng($i, "/tmp/fixture.png");' || die "fixture"
docker cp "$PHPC:/tmp/fixture.png" "$WORK/prod/fixture.png" && chmod 644 "$WORK/prod/fixture.png"

if [ "$INSTALL" = multisite ]; then
	wp site create --slug=two --quiet || die "site two"
	wp plugin activate "$SLUG" --network --quiet || die "activate"
	TARGET=http://site.test/two
else
	wp plugin activate "$SLUG" --quiet || die "activate"
	TARGET=$SITE_URL
fi

hm() { wp --url="$SITE_URL" eval-file /tmp/lmfp.php "$@" | tail -n 1; }
ht() { wp --url="$TARGET" eval-file /tmp/lmfp.php "$@" | tail -n 1; }
set_both() {
	local out
	out=$(ht set "$@")
	[ "$INSTALL" = multisite ] && hm set "$@" >/dev/null
	echo "$out"
}
ACTIVE=(enabled=1 production_url=$PROD mode=both fallback_method=redirect environment=other)

UPL=$(ht uploads)
UPATH=${UPL#http://site.test}
ID=$(wp --url="$TARGET" media import /tmp/fixture.png --porcelain | tail -n 1)
POST=$(ht make-post "$ID")
FIX=$(wp --url="$TARGET" eval "echo wp_get_original_image_url( $ID );" | tail -n 1)
PAGE="$TARGET/?p=$POST"
echo "uploads $UPL, attachment $ID, post $POST"

expect "plugin active" "active" "$(set_both "${ACTIVE[@]}" local_first=1)"
expect "fixture saved locally" "200" "$(status_of "$FIX" | cut -d' ' -f1)"

if [ "$SERVER" = nginx-static ]; then
	expect "static nginx: missing file not passed to WordPress" "404 " "$(status_of "$UPL/2020/01/static-missing.png")"
	expect "static nginx: server test reports the server" "server 404" "$(hm server-test)"
	wp --url="$TARGET" eval-file /tmp/lmfp.php snippet > "$WORK/snippet.conf"
	make_nginx "$WORK/snippet.conf"
	docker exec web nginx -s reload >/dev/null 2>&1 || die "reload nginx"
	sleep 1
	echo "added snippet:"; cat "$WORK/snippet.conf"
fi

if [ "$SERVER" = apache ] && [ "$INSTALL" = multisite ]; then
	expect "apache multisite: server test fails with stock .htaccess" "server 404" "$(hm server-test)"
	wp --url="$TARGET" eval-file /tmp/lmfp.php apache-snippet > "$WORK/snippet.txt"
	awk -v f="$WORK/snippet.txt" 'index($0, "wp-(content|admin|includes)") && !done { while ((getline l < f) > 0) print l; done = 1 } { print }' "$WORK/htaccess" > "$WORK/htaccess.new"
	docker cp "$WORK/htaccess.new" "$PHPC:$WPDIR/.htaccess" || die "htaccess snippet"
	echo "added to .htaccess:"; cat "$WORK/snippet.txt"
fi

expect "server test reaches WordPress" "reached " "$(hm server-test)"
expect "settings page and admin bar render" "ok" "$(ht admin-check)"

ht set "${ACTIVE[@]}" local_first=0 >/dev/null
[ "$INSTALL" = multisite ] && hm set "${ACTIVE[@]}" local_first=0 >/dev/null
expect_has "attachment URL rewritten" "$PROD$UPATH/" "$(wp --url="$TARGET" eval-file /tmp/lmfp.php attachment-url "$ID" | tail -n 1)"
RAW=$(get -L "$PAGE")
HTML=$(tr -d '\\' <<<"$RAW")
expect_count "local-first off: no local uploads URLs left" "//site.test$UPATH/" "$HTML" -eq 0
expect_count "local-first off: uploads URLs point at production" "//prod.test$UPATH/" "$HTML" -ge 4
expect_has "local-first off: inline CSS rewritten" "url($PROD$UPATH/2020/01/bg-missing.jpg)" "$HTML"
expect_has "JSON-escaped URL rewritten and still escaped" 'prod.test\/' "$RAW"

set_both "${ACTIVE[@]}" local_first=1 >/dev/null
HTML=$(get -L "$PAGE" | tr -d '\\')
expect_count "local-first on: local file left local" "//site.test$UPATH/" "$HTML" -ge 1
expect_count "local-first on: local file not sent to production" "prod.test${FIX#http://site.test}" "$HTML" -eq 0
expect_has "local-first on: missing img rewritten" "$PROD$UPATH/2020/01/missing.png" "$HTML"
expect_has "local-first on: missing CSS background rewritten" "$PROD$UPATH/2020/01/bg-missing.jpg" "$HTML"
expect_has "local-first on: missing JSON URL rewritten" "$PROD$UPATH/2020/01/json-missing.png" "$HTML"

expect "missing file redirects to production" "302 $PROD$UPATH/2020/01/redirect-missing.png" "$(status_of "$UPL/2020/01/redirect-missing.png")"
expect "encoded filename redirects" "302 $PROD$UPATH/2020/01/my%20photo.jpg" "$(status_of "$UPL/2020/01/my%20photo.jpg")"
expect "existing file served locally" "200 " "$(status_of "$FIX")"

set_both "${ACTIVE[@]}" local_first=1 fallback_method=download >/dev/null
expect "download: redirects back to the local copy" "302 $UPL/2020/01/dl.png?lmfp=1" "$(status_of "$UPL/2020/01/dl.png")"
expect "download: file then served locally" "200 image/png" "$(get -o /dev/null -w '%{http_code} %{content_type}' "$UPL/2020/01/dl.png?lmfp=1")"
expect "download: failed download falls back to redirect" "302 $PROD$UPATH/2020/01/not-on-prod.png" "$(status_of "$UPL/2020/01/not-on-prod.png")"
status_of "$UPL/2020/01/evil.php" >/dev/null
UPDIR=$(wp --url="$TARGET" eval 'echo wp_upload_dir( null, false )["basedir"];' | tail -n 1)
if x test -e "$UPDIR/2020/01/evil.php"; then fail "download: PHP file never saved" "evil.php was written"; else pass "download: PHP file never saved"; fi
if x test -e "$UPDIR/2020/01/not-on-prod.png"; then fail "download: failed download leaves no file" "file left behind"; else pass "download: failed download leaves no file"; fi
set_both "${ACTIVE[@]}" local_first=1 >/dev/null

expect "paused: production URL is this site" "same_site" "$(set_both "${ACTIVE[@]}" production_url=http://site.test)"
expect "paused (same_site): missing file not redirected" "404 " "$(status_of "$UPL/2020/01/paused.png")"
expect "paused: database copied to another domain" "site_changed" "$(set_both "${ACTIVE[@]}" fingerprint=bad)"
expect "paused (site_changed): missing file not redirected" "404 " "$(status_of "$UPL/2020/01/paused.png")"
expect "paused (site_changed): admin pages still render" "ok" "$(ht admin-check)"
set_both "${ACTIVE[@]}" >/dev/null
wp config set WP_ENVIRONMENT_TYPE production --quiet && sleep 3
expect "paused: site marked as production" "production" "$(ht status)"
expect "paused (production): missing file not redirected" "404 " "$(status_of "$UPL/2020/01/paused.png")"
wp config delete WP_ENVIRONMENT_TYPE --quiet && sleep 3
expect "active again" "active" "$(ht status)"

NOTICES=$(x sh -c "grep -h '$SLUG' '$WPDIR/wp-content/debug.log' 2>/dev/null" | sort -u | head -n 5)
expect "no PHP notices from the plugin" "" "$NOTICES"

wp user meta update 1 lmfp_donate_dismissed 1 --quiet
if [ "$INSTALL" = multisite ]; then
	wp plugin deactivate "$SLUG" --network --quiet
else
	wp plugin deactivate "$SLUG" --quiet
fi
wp plugin uninstall "$SLUG" --quiet
if wp --url="$SITE_URL" option get lmfp_settings >/dev/null 2>&1; then fail "uninstall removes settings" "lmfp_settings still there"; else pass "uninstall removes settings"; fi
if [ "$INSTALL" = multisite ]; then
	if wp --url="$TARGET" option get lmfp_settings >/dev/null 2>&1; then fail "uninstall removes settings on every site" "lmfp_settings still on site two"; else pass "uninstall removes settings on every site"; fi
fi
expect "uninstall removes user meta" "" "$(wp user meta get 1 lmfp_donate_dismissed 2>/dev/null)"

echo
echo "== $PASSES passed, $FAILS failed"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
	{
		echo "### PHP $PHP_VERSION, WordPress $WP_VERSION, $SERVER, $INSTALL: $PASSES passed, $FAILS failed"
		for r in "${RESULTS[@]}"; do
			case $r in FAIL*) echo "- :x: ${r#FAIL }" ;; esac
		done
	} >> "$GITHUB_STEP_SUMMARY"
fi
if [ "$FAILS" -gt 0 ]; then
	echo "== web server log (last 20 lines)"
	docker logs --tail 20 "$([ "$SERVER" = apache ] && echo site || echo web)" 2>&1
	exit 1
fi
