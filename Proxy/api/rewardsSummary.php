<?php
/**
 * Rewards dashboard aggregator (same Option-B pattern as memberSummary.php).
 *
 * One same-origin call fans out server-side to the upstream promotion and
 * ticket feeds the rewards page needs:
 *
 *   MCSFE_getAvailablePromotions, MCSFE_getClaimPromotion,
 *   PROMOFE_getClaimTicketList, PROMOFE_getDiscountTicketList,
 *   PROMOFE_getUnappliedExtraRewardClaimList, MCSFE_getRegisterPromotionList
 *
 * Claim/cancel actions stay direct-proxied POSTs through PXAPI (see
 * mobile/assets/api.js) — this endpoint is read-only.
 *
 * Route: auto-served by Proxy/router.php (/api/*.php) — no router edit.
 * Auth: same session fingerprint as requestSessionKey(). Never cached.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, must-revalidate');
header('Vary: Cookie');
header('X-Content-Type-Options: nosniff');
header('X-Proxy: true');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Merchant, Device, Language, Encryption, X-Gateway-Version, X-Digest, X-RSA');
    http_response_code(204);
    exit;
}
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'GET required']);
    exit;
}

require_once __DIR__ . '/../admin/store.php';

/* ---------------- session ---------------- */

$jar = ($_SERVER['HTTP_COOKIE'] ?? '') . "\n"
    . ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '')) . "\n"
    . ($_SERVER['HTTP_ENCRYPTION'] ?? '') . "\n"
    . ($_SERVER['HTTP_X_GATEWAY_VERSION'] ?? '');
$sessionKey = trim(str_replace("\n", '', $jar)) === '' ? '' : md5($jar);
if ($sessionKey === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'needLogin' => true, 'error' => 'Not logged in']);
    exit;
}

/* ---------------- upstream base ---------------- */

$cfg = is_file(__DIR__ . '/../config.php') ? (require __DIR__ . '/../config.php') : [];
$upstream = rtrim(trim((string) ($cfg['upstream'] ?? '')), '/');
$mf = __DIR__ . '/../upstreams.json';
if (is_file($mf)) {
    $raw = @file_get_contents($mf);
    $data = $raw !== false ? json_decode($raw, true) : null;
    $order = is_array($data) ? ($data['order'] ?? $data) : null;
    if (is_array($order)) {
        foreach ($order as $u) {
            if (is_string($u) && preg_match('#^https?://#i', trim($u))) {
                $upstream = rtrim(trim($u), '/');
                break;
            }
        }
    }
}
if ($upstream === '' || !preg_match('#^https?://#i', $upstream)) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Upstream not configured']);
    exit;
}

/* ---------------- headers to forward ---------------- */

$clientUa = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
$fwd = [
    'Accept: application/json, text/plain, */*',
    'Accept-Language: en-US,en;q=0.9',
];
$add = function (string $name, $v) use (&$fwd) {
    if ($v !== null && $v !== '') {
        $fwd[] = $name . ': ' . $v;
    }
};
$add('Authorization', $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null));
// One Merchant header only — a duplicate makes the gateway answer
// 400 "function.not.available".
$add('Merchant', $_SERVER['HTTP_MERCHANT'] ?? 'go44bdtf5');
$add('Device', $_SERVER['HTTP_DEVICE'] ?? 'web');
$add('Language', $_SERVER['HTTP_LANGUAGE'] ?? 'en');
$add('X-Gateway-Version', $_SERVER['HTTP_X_GATEWAY_VERSION'] ?? null);
$add('Encryption', $_SERVER['HTTP_ENCRYPTION'] ?? null);
$add('X-Digest', $_SERVER['HTTP_X_DIGEST'] ?? null);
$add('X-RSA', $_SERVER['HTTP_X_RSA'] ?? null);
$add('Cookie', $_SERVER['HTTP_COOKIE'] ?? null);
if ($clientUa === '') {
    $clientUa = (string) ($cfg['user_agent'] ?? 'Mozilla/5.0');
}

/**
 * GET one upstream path. 6s cap, one retry on 5xx only.
 * Returns [httpStatus, decodedArray|null]. Status 0 = transport failure.
 */
function rw_get(string $base, string $path, array $headers, string $ua): array
{
    $do = function () use ($base, $path, $headers, $ua) {
        $ch = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false || $body === '') {
            return [$status, null];
        }
        $d = json_decode((string) $body, true);
        return [$status, is_array($d) ? $d : null];
    };
    [$st, $d] = $do();
    if ($st >= 500 && $st < 600) {
        usleep(100000);
        [$st, $d] = $do();
    }
    return [$st, $d];
}

/** Upstream list payloads nest differently per relay — first array wins.
 *  Some relays key arrays by channel (e.g. value.M / value.WEB), so when no
 *  known key hits, merge every array found one level deep. */
function rw_list($decoded): array
{
    if (!is_array($decoded)) {
        return [];
    }
    $v = $decoded['value'] ?? $decoded;
    if (!is_array($v)) {
        return [];
    }
    foreach (['list', 'records', 'rows', 'items', 'data', 'promotions', 'tickets'] as $k) {
        if (isset($v[$k]) && is_array($v[$k])) {
            $a = $v[$k];
            return ($a === [] || array_keys($a) === range(0, count($a) - 1)) ? array_values($a) : [$a];
        }
    }
    if ($v === [] || array_keys($v) === range(0, count($v) - 1)) {
        return array_values($v);
    }
    $merged = [];
    foreach ($v as $item) {
        if (!is_array($item)) {
            continue;
        }
        if ($item === [] || array_keys($item) === range(0, count($item) - 1)) {
            foreach (array_values($item) as $row) {
                $merged[] = $row;
            }
        } else {
            $merged[] = $item;
        }
    }
    return $merged;
}

/* ---------------- fan-out ---------------- */

$legs = [
    'promotions' => '/wps/relay/MCSFE_getAvailablePromotions',
    'claimable'  => '/wps/relay/MCSFE_getClaimPromotion',
    'tickets'    => '/wps/relay/PROMOFE_getClaimTicketList',
    'discounts'  => '/wps/relay/PROMOFE_getDiscountTicketList',
    'extra'      => '/wps/relay/PROMOFE_getUnappliedExtraRewardClaimList',
    'register'   => '/wps/relay/MCSFE_getRegisterPromotionList',
];
$res = [];
foreach ($legs as $k => $p) {
    $res[$k] = rw_get($upstream, $p, $fwd, $clientUa);
}

$failed = 0;
$legStatus = [];
$out = [];
foreach ($legs as $k => $p) {
    [$st, $d] = $res[$k];
    $ok = $st >= 200 && $st < 300 && $d !== null;
    $legStatus[$k] = $st;
    if (!$ok) {
        $failed++;
    }
    $out[$k] = $ok ? rw_list($d) : [];
}
if ($failed >= count($legs)) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Upstream unreachable']);
    exit;
}

echo json_encode([
    'success' => true,
    'partial' => $failed > 0,
    'legs' => $legStatus,
    'promotions' => $out['promotions'],
    'claimable' => $out['claimable'],
    'tickets' => $out['tickets'],
    'discounts' => $out['discounts'],
    'extra' => $out['extra'],
    'register' => $out['register'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
