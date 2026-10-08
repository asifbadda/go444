<?php
/**
 * Storage + auth helpers for the admin panel.
 * JSON stores live in /data (denied from the web by data/.htaccess).
 */

function data_dir(): string
{
    return dirname(__DIR__) . '/data';
}

function store_write(string $file, $data): bool
{
    $dir = data_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $json) === false) {
        return false;
    }
    return @rename($tmp, $file);
}

function store_read(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $d = json_decode((string) @file_get_contents($file), true);
    return is_array($d) ? $d : [];
}

/* --------------------------- content --------------------------- */

/** Default payment channel list (used per method unless overridden). */
function voucher_channel_defaults(): array
{
    return [
        ['label' => 'চ্যানেল 3', 'enabled' => true],
        ['label' => 'চ্যানেল 1', 'enabled' => true],
        ['label' => 'চ্যানেল 75', 'enabled' => true],
        ['label' => 'চ্যানেল 86', 'enabled' => true],
    ];
}

/** Default payment methods shown on the custom voucher-center page. */
function voucher_method_defaults(): array
{
    $ch = voucher_channel_defaults();
    return [
        'NAGAD'   => ['name' => 'Nagad',            'image' => '', 'enabled' => true, 'min' => 100, 'max' => 30000, 'channels' => $ch],
        'BKASH'   => ['name' => 'Bkash',            'image' => '', 'enabled' => true, 'min' => 100, 'max' => 30000, 'channels' => $ch],
        'BKASHSM' => ['name' => 'Send Money Bkash', 'image' => '', 'enabled' => true, 'min' => 100, 'max' => 30000, 'channels' => $ch],
        'NAGADSM' => ['name' => 'Send Money Nagad', 'image' => '', 'enabled' => true, 'min' => 100, 'max' => 30000, 'channels' => $ch],
        'USDT'    => ['name' => 'USDT',             'image' => '', 'enabled' => true, 'min' => 100, 'max' => 30000, 'channels' => $ch],
        'ROCKET'  => ['name' => 'Rocket',           'image' => '', 'enabled' => true, 'min' => 100, 'max' => 30000, 'channels' => $ch],
    ];
}

/* --------------------------- games ---------------------------
   Everything the panel controls about games lives in one place: whether the
   grid shows at all, which language a game opens in, whether a game opens in
   the in-app shell (which is what gives every game page a Back button), how
   many games a page holds, which category tabs and which vendors are offered,
   and which individual games are hidden from the list.

   `types` and `vendors` are code => bool maps. A code that is absent from the
   map counts as enabled, so a fresh install (empty maps) offers everything and
   only explicitly switched-off codes disappear. */
function games_languages(): array
{
    return [
        'EN' => 'English',
        'BN' => 'বাংলা (Bengali)',
        'ID' => 'Indonesia',
        'TH' => 'ไทย (Thai)',
        'VI' => 'Tiếng Việt (Vietnamese)',
        'ZH' => '中文 (Chinese)',
    ];
}

/**
 * Category tabs the home page actually renders, in its order (see the GROUPS
 * list in mobile/assets/home.js). This must stay in step with that list: a code
 * here that the page does not draw would be a switch in the panel that changes
 * nothing.
 */
function games_type_defaults(): array
{
    return [
        'RNG'     => 'Slots',
        'LIVE'    => 'Live Casino',
        'PVP'     => 'Poker / PvP',
        'SPORTS'  => 'Sports',
        'FISH'    => 'Fishing',
        'ELOTT'   => 'E-Lottery',
        'ESPORTS' => 'E-Sports',
    ];
}

function games_defaults(): array
{
    $types = [];
    foreach (games_type_defaults() as $code => $label) {
        $types[$code] = true;
    }
    return [
        'enabled'   => true,
        'language'  => 'EN',
        'in_app'    => true,
        'back_url'  => '/m/home',
        'page_size' => 24,
        'types'     => $types,
        'vendors'   => [],
        'hidden'    => [],
        'updated'   => '',
    ];
}

/** The games config as the frontend consumes it. */
function games_config(): array
{
    $c = content_load();
    $g = $c['games'] ?? games_defaults();
    $def = games_defaults();
    $out = $def;
    foreach ($def as $k => $v) {
        if (array_key_exists($k, $g)) {
            $out[$k] = $g[$k];
        }
    }
    if (!is_array($out['types']))   { $out['types'] = []; }
    if (!is_array($out['vendors'])) { $out['vendors'] = []; }
    if (!is_array($out['hidden']))  { $out['hidden'] = []; }
    // Back target stays a same-origin path: the shell must never be talked into
    // navigating somewhere else by config alone.
    $back = trim((string) $out['back_url']);
    $out['back_url'] = ($back !== '' && $back[0] === '/') ? $back : '/m/home';
    $out['page_size'] = max(4, min(120, (int) $out['page_size']));
    if (!isset(games_languages()[(string) $out['language']])) {
        $out['language'] = 'EN';
    }
    return $out;
}

/** Default configuration for the custom voucher-center page. */
function voucher_defaults(): array
{
    return [
        'enabled'      => false,
        'path'         => '/m/voucherCenter',
        'redirect_url' => '/voucherCenter/',
        'logo'         => '',
        'amounts'      => [100, 200, 300, 500, 1000, 3000, 5000, 10000, 30000],
        'methods'      => voucher_method_defaults(),
    ];
}

function content_defaults(): array
{
    return [
        'banners' => [],
        'marquee' => ['enabled' => false, 'items' => [], 'text' => '', 'bg' => '#111827', 'color' => '#ffffff', 'speed' => 160, 'link' => ''],
        'titles'  => ['web_title' => '', 'mobile_title' => '', 'app_name' => ''],
        'logo'    => ['url' => '', 'width' => 0],
        'favicon' => ['url' => ''],
        'voucher' => voucher_defaults(),
        'games'   => games_defaults(),
    ];
}

function content_load(): array
{
    $d = store_read(data_dir() . '/content.json');
    $def = content_defaults();
    $out = $def;
    foreach ($def as $k => $v) {
        if (isset($d[$k]) && is_array($d[$k])) {
            $out[$k] = array_merge($v, $d[$k]);
        }
    }
    if (!empty($d['banners']) && is_array($d['banners'])) {
        $out['banners'] = array_values($d['banners']);
    }
    // Marquee: normalise to a list of {text, link}. Migrate the old single-text format.
    $mq = $out['marquee'];
    if (empty($mq['items']) || !is_array($mq['items'])) {
        $t = trim((string) ($mq['text'] ?? ''));
        $mq['items'] = $t !== '' ? [['text' => $t, 'link' => (string) ($mq['link'] ?? '')]] : [];
        if ($t !== '' && (int) ($mq['speed'] ?? 0) < 40) {
            $mq['speed'] = 160; // old "seconds" value -> new px/s default
        }
    } else {
        $mq['items'] = array_values(array_filter($mq['items'], function ($x) {
            return is_array($x) && trim((string) ($x['text'] ?? '')) !== '';
        }));
    }
    $out['marquee'] = $mq;
    return $out;
}

function content_save(array $c): bool
{
    return store_write(data_dir() . '/content.json', $c);
}

/* ---------------------------- users ---------------------------- */

function users_load(): array
{
    return array_values(store_read(data_dir() . '/users.json'));
}

function users_save(array $users): bool
{
    return store_write(data_dir() . '/users.json', array_values($users));
}

/** Create users.json from the setup config the first time it is needed. */
function users_seed(array $cfg): void
{
    if (is_file(data_dir() . '/users.json')) {
        return;
    }
    users_save([[
        'id'        => 1,
        'username'  => (string) ($cfg['user'] ?? 'admin'),
        'pass_hash' => (string) ($cfg['pass_hash'] ?? ''),
        'role'      => 'owner',
        'created'   => date('c'),
    ]]);
}

function users_next_id(array $users): int
{
    $max = 0;
    foreach ($users as $u) {
        $max = max($max, (int) ($u['id'] ?? 0));
    }
    return $max + 1;
}

function user_verify(array $users, string $username, string $password): ?array
{
    foreach ($users as $u) {
        if (hash_equals((string) ($u['username'] ?? ''), $username)
            && password_verify($password, (string) ($u['pass_hash'] ?? ''))) {
            return $u;
        }
    }
    return null;
}

/* --------------------------- config ---------------------------- */

function config_load(): array
{
    $f = dirname(__DIR__) . '/config.php';
    return is_file($f) ? (require $f) : [];
}

function config_save(array $cfg): bool
{
    $body = "<?php\n"
        . "/**\n"
        . " * Proxy configuration. Generated/updated by the admin panel / setup installer.\n"
        . " */\n\n"
        . 'return ' . var_export($cfg, true) . ";\n";
    $f = dirname(__DIR__) . '/config.php';
    $tmp = $f . '.tmp';
    if (@file_put_contents($tmp, $body) === false) {
        return false;
    }
    return @rename($tmp, $f);
}

/* ----------------------- orders / payments ----------------------- */

function orders_read(): array
{
    $d = store_read(data_dir() . '/orders.json');
    return $d['orders'] ?? [];
}

function orders_write(array $orders): bool
{
    $d = store_read(data_dir() . '/orders.json');
    $d['orders'] = $orders;
    if (!empty($orders)) {
        $maxId = max(array_column($orders, 'id'));
        $d['nextId'] = max($d['nextId'] ?? 1001, $maxId + 1);
    }
    return store_write(data_dir() . '/orders.json', $d);
}

function orders_next_id(): int
{
    $d = store_read(data_dir() . '/orders.json');
    return ($d['nextId'] ?? 1001);
}

function find_order_by_tracking(string $trackingNumber): ?array
{
    $orders = orders_read();
    foreach ($orders as $order) {
        if (($order['trackingNumber'] ?? '') === $trackingNumber) {
            return $order;
        }
    }
    return null;
}

function update_order(string $trackingNumber, array $updates): bool
{
    $orders = orders_read();
    foreach ($orders as &$order) {
        if (($order['trackingNumber'] ?? '') === $trackingNumber) {
            $order = array_merge($order, $updates);
            return orders_write($orders);
        }
    }
    return false;
}

function payment_methods_data_read(): array
{
    $d = store_read(data_dir() . '/payment-methods.json');
    return is_array($d) ? $d : [];
}

function payment_methods_data_write(array $data): bool
{
    return store_write(data_dir() . '/payment-methods.json', $data);
}

function payment_rotation_read(): array
{
    $d = store_read(data_dir() . '/payment-rotation.json');
    return is_array($d) ? $d : [];
}

function payment_rotation_write(array $data): bool
{
    return store_write(data_dir() . '/payment-rotation.json', $data);
}

/**
 * Round-robin picker for wallet numbers when multiple enabled accounts
 * share the same method+channel. Bumps the counter atomically with flock.
 * Returns the next eligible index [0..count-1].
 */
function payment_rotation_next(string $key, int $count): int
{
    if ($count <= 1) {
        return 0;
    }
    $file = data_dir() . '/payment-rotation.json';
    $dir = data_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $fh = @fopen($file, 'c+');
    if (!$fh) {
        $d = payment_rotation_read();
        $last = (int) ($d[$key] ?? -1);
        $next = ($last + 1) % $count;
        $d[$key] = $next;
        payment_rotation_write($d);
        return $next;
    }
    $locked = @flock($fh, LOCK_EX);
    $size = @filesize($file);
    $d = [];
    if ($size > 0) {
        @rewind($fh);
        $content = @fread($fh, $size);
        $decoded = json_decode((string) $content, true);
        if (is_array($decoded)) {
            $d = $decoded;
        }
    }
    $last = (int) ($d[$key] ?? -1);
    $next = ($last + 1) % $count;
    $d[$key] = $next;
    $json = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        @ftruncate($fh, 0);
        @rewind($fh);
        @fwrite($fh, $json);
        @fflush($fh);
    }
    if ($locked) {
        @flock($fh, LOCK_UN);
    }
    @fclose($fh);
    return $next;
}

function payment_rotation_peek(string $key, int $count): int
{
    if ($count <= 1) {
        return 0;
    }
    $d = payment_rotation_read();
    $last = (int) ($d[$key] ?? -1);
    return ($last + 1) % $count;
}

function payment_settings_read(): array
{
    $d = store_read(data_dir() . '/settings.json');
    return is_array($d) ? $d : [];
}

function payment_settings_write(array $data): bool
{
    return store_write(data_dir() . '/settings.json', $data);
}

/* ----------------------- withdraw approvals ----------------------- */

function withdraw_approvals_file(): string
{
    return data_dir() . '/withdraw-approvals.json';
}

function withdraw_approvals_read(): array
{
    $d = store_read(withdraw_approvals_file());
    if (isset($d['items']) && is_array($d['items'])) {
        return array_values($d['items']);
    }
    return is_array($d) && array_keys($d) === range(0, count($d) - 1) ? array_values($d) : [];
}

function withdraw_approvals_write(array $items): bool
{
    $items = array_values($items);
    $d = store_read(withdraw_approvals_file());
    if (!is_array($d)) {
        $d = [];
    }
    $d['items'] = $items;
    if (!empty($items)) {
        $maxId = 0;
        foreach ($items as $it) {
            $maxId = max($maxId, (int) ($it['id'] ?? 0));
        }
        $d['nextId'] = max((int) ($d['nextId'] ?? 1001), $maxId + 1);
    }
    return store_write(withdraw_approvals_file(), $d);
}

function withdraw_approvals_next_id(): int
{
    $d = store_read(withdraw_approvals_file());
    return (int) ($d['nextId'] ?? 1001);
}

function withdraw_approval_find_usable(string $sessionKey): ?array
{
    if ($sessionKey === '') {
        return null;
    }
    $now = time();
    foreach (withdraw_approvals_read() as $it) {
        if (($it['sessionKey'] ?? '') !== $sessionKey) {
            continue;
        }
        if (($it['status'] ?? '') !== 'Approved') {
            continue;
        }
        if (!empty($it['consumedAt'])) {
            continue;
        }
        $exp = (int) ($it['expiresAt'] ?? 0);
        if ($exp > 0 && $exp < $now) {
            continue;
        }
        return $it;
    }
    return null;
}

function withdraw_approval_consume(int $id): bool
{
    $items = withdraw_approvals_read();
    foreach ($items as &$it) {
        if ((int) ($it['id'] ?? 0) === $id) {
            if (!empty($it['consumedAt'])) {
                return true;
            }
            $it['consumedAt'] = date('c');
            return withdraw_approvals_write($items);
        }
    }
    return false;
}

function withdraw_active_hold_sum(string $sessionKey): float
{
    if ($sessionKey === '') {
        return 0.0;
    }
    $now = time();
    $sum = 0.0;
    foreach (withdraw_approvals_read() as $it) {
        if (($it['sessionKey'] ?? '') !== $sessionKey) {
            continue;
        }
        $st = (string) ($it['status'] ?? '');
        if ($st !== 'Pending' && $st !== 'Approved') {
            continue;
        }
        if ($st === 'Approved' && !empty($it['consumedAt'])) {
            continue;
        }
        $exp = (int) ($it['expiresAt'] ?? 0);
        if ($exp > 0 && $exp < $now) {
            continue;
        }
        $sum += max(0.0, (float) ($it['amount'] ?? 0));
    }
    return round($sum, 2);
}

function withdraw_approvals_for_session(string $sessionKey): array
{
    if ($sessionKey === '') {
        return [];
    }
    $out = [];
    foreach (withdraw_approvals_read() as $it) {
        if (($it['sessionKey'] ?? '') === $sessionKey) {
            $out[] = $it;
        }
    }
    usort($out, function ($a, $b) {
        return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
    });
    return $out;
}

/* ----------------------- support URL ----------------------- */

// All customer-service / live-chat links point here.
if (!defined('SUPPORT_URL')) {
    define('SUPPORT_URL', 'https://support.bbc99.bet');
}

/** JSON keys whose string values are support links (case-insensitive). */
function support_url_keys(): array
{
    return [
        'customerservice', 'customer_service', 'customer-service',
        'livechat', 'live_chat', 'live-chat',
        'onlineservice', 'online_service', 'online-service', 'onlinekefu',
        'kefu', 'kefuurl', 'kefu_url', 'chaturl', 'chat_url',
        'csurl', 'cs_url', 'supporturl', 'support_url',
        'serviceaddress', 'service_address',
    ];
}

/** True when an absolute URL is a customer-service / live-chat link. */
function is_support_url(string $u): bool
{
    if (!preg_match('#^https?://#i', $u)) {
        return false;
    }
    return (bool) preg_match('#open_chat\.cgi|live-?chat|live800|miduoke|aican|udesk|qiyukf|easemob|tawk\.to|crisp\.chat|meiqia|kf5|53kf|zoho\.|intercom|freshchat|customer-?service|online-?service|chatlink|mqqwpa|bizqq|kefu|live-?help|help-?desk|support-?chat|chat-?support|//cs\.|/cs/|[\.\-]cs[\.\-/]#i', $u);
}

/** Rewrite every support/chat URL in text to SUPPORT_URL. */
function support_url_replace(string $body): string
{
    if ($body === '' || stripos($body, 'http') === false) {
        return $body;
    }
    return (string) preg_replace_callback(
        '#https?://[^\s"\'<>()]+#i',
        function ($m) {
            return is_support_url($m[0]) ? SUPPORT_URL : $m[0];
        },
        $body
    );
}

/* ----------------------- brand assets (local) ----------------------- */

function brand_asset_dir(): string
{
    // Docroot /images/brand — served as-is by root .htaccess (real file).
    $dir = dirname(dirname(__DIR__)) . '/images/brand';
    // Fallback when admin lives elsewhere (tests / subfolder installs).
    if (strpos($dir, 'images/brand') === false) {
        $dir = dirname(__DIR__) . '/images/brand';
    }
    return $dir;
}

function brand_asset_allowed_ext(string $ext): bool
{
    return in_array(strtolower($ext), ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico'], true);
}

function brand_asset_mime_to_ext(string $mime): string
{
    $map = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];
    $mime = strtolower(trim(explode(';', $mime)[0]));
    return $map[$mime] ?? '';
}

function brand_asset_clear(string $type): void
{
    $dir = brand_asset_dir();
    foreach (glob($dir . '/' . $type . '.*') ?: [] as $f) {
        @unlink($f);
    }
}

function brand_asset_save_bytes(string $bytes, string $type, string $hintExt = ''): string|false
{
    if ($bytes === '' || strlen($bytes) > 3 * 1024 * 1024) {
        return false;
    }
    // Detect image type from bytes (getimagesizefromstring handles png/jpg/gif/webp/ico;
    // svg has no bitmap header so sniff for <svg).
    $ext = '';
    $info = @getimagesizefromstring($bytes);
    if (is_array($info) && isset($info['mime'])) {
        $ext = brand_asset_mime_to_ext((string) $info['mime']);
    }
    if ($ext === '' && stripos($bytes, '<svg') !== false) {
        $ext = 'svg';
    }
    if ($ext === '' && brand_asset_allowed_ext($hintExt)) {
        $ext = strtolower($hintExt);
    }
    if ($ext === '' || !brand_asset_allowed_ext($ext)) {
        return false;
    }
    $dir = brand_asset_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    brand_asset_clear($type);
    $file = $dir . '/' . $type . '.' . $ext;
    if (@file_put_contents($file, $bytes) === false) {
        return false;
    }
    // Cache-bust so browsers + FB scrapers pick the new file immediately.
    return '/images/brand/' . $type . '.' . $ext . '?v=' . filemtime($file);
}

function brand_asset_save_upload(array $file, string $type): string|false
{
    if (empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
        return false;
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return false;
    }
    $bytes = @file_get_contents((string) $file['tmp_name']);
    if ($bytes === false) {
        return false;
    }
    $hint = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    return brand_asset_save_bytes($bytes, $type, $hint);
}

function brand_asset_save_url(string $url, string $type): string|false
{
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return false;
    }
    // Already local — keep as-is (strip cache-buster for storage).
    if (strpos($url, '/images/brand/') === 0) {
        return preg_replace('/\?.*$/', '', $url);
    }
    $hint = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    $bytes = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (BBC99 brand fetch)',
        ]);
        $bytes = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($bytes === false || $code < 200 || $code >= 400) {
            return false;
        }
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 12, 'follow_location' => 1]]);
        $bytes = @file_get_contents($url, false, $ctx);
        if ($bytes === false) {
            return false;
        }
    }
    return brand_asset_save_bytes((string) $bytes, $type, $hint);
}
