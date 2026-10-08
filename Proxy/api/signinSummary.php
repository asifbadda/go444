<?php
/**
 * Daily sign-in aggregator (same Option-B pattern as memberSummary.php).
 *
 * One same-origin call fans out server-side to the upstream login-promotion
 * feeds the sign-in page needs:
 *
 *   MCSFE_getLoginPromotion, MCSFE_getLoginPromotionConfigHistory
 *
 * Claiming stays a direct-proxied POST through PXAPI
 * (MCSFE_claimLoginPromotion) — this endpoint is read-only.
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
function si_get(string $base, string $path, array $headers, string $ua): array
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

/** First non-empty scalar under any candidate key. */
function si_str(array $d, array $keys): string
{
    foreach ($keys as $k) {
        if (isset($d[$k]) && $d[$k] !== '' && $d[$k] !== null && !is_array($d[$k])) {
            return (string) $d[$k];
        }
    }
    return '';
}

/** First numeric value under any candidate key. */
function si_num(array $d, array $keys, $def = null)
{
    foreach ($keys as $k) {
        if (isset($d[$k]) && is_numeric($d[$k])) {
            return (float) $d[$k];
        }
    }
    return $def;
}

/** List payload, tolerating every nesting seen across relays. */
function si_list($decoded): array
{
    if (!is_array($decoded)) {
        return [];
    }
    $v = $decoded['value'] ?? $decoded;
    if (!is_array($v)) {
        return [];
    }
    foreach (['list', 'records', 'rows', 'items', 'data', 'days', 'detail'] as $k) {
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
    'promo'  => '/wps/relay/MCSFE_getLoginPromotion',
    'config' => '/wps/relay/MCSFE_getLoginPromotionConfigHistory',
];
$res = [];
foreach ($legs as $k => $p) {
    $res[$k] = si_get($upstream, $p, $fwd, $clientUa);
}

$failed = 0;
$legStatus = [];
foreach ($legs as $k => $p) {
    [$st, $d] = $res[$k];
    $ok = $st >= 200 && $st < 300 && $d !== null;
    $legStatus[$k] = $st;
    if (!$ok) {
        $failed++;
    }
}
if ($failed >= count($legs)) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Upstream unreachable']);
    exit;
}

/* ---------------- shape (tolerant — relay schemas drift) ---------------- */

$pv = is_array($res['promo'][1]['value'] ?? null) ? $res['promo'][1]['value'] : [];
$cv = is_array($res['config'][1]['value'] ?? null) ? $res['config'][1]['value'] : [];
$both = $pv + $cv;

$days = si_list($res['promo'][1]);
if (!$days) {
    $days = si_list($res['config'][1]);
}

echo json_encode([
    'success' => true,
    'partial' => $failed > 0,
    'legs' => $legStatus,
    'lastSignIn' => si_num($both, ['lastSignIn', 'signInCount', 'consecutiveDays', 'keepSignIn']),
    'totalBonus' => si_num($both, ['totalBonus', 'signInTotalBonus', 'totalReward']),
    'checkedToday' => (bool) ($both['checkedToday'] ?? $both['todayChecked'] ?? $both['isSignIn'] ?? false),
    'headline' => si_str($both, ['headline', 'title', 'promotionName', 'activityName']),
    'description' => si_str($both, ['description', 'desc', 'remark', 'content']),
    'minDeposit' => si_num($both, ['minDeposit', 'minimumDeposit', 'minDepositAmount']),
    'bettingConditions' => si_num($both, ['bettingConditions', 'betAmount', 'validBet', 'turnoverRequire']),
    'days' => $days,
    'raw' => ['promo' => $pv, 'config' => $cv],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
