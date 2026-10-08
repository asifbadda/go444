<?php
/**
 * Member dashboard aggregator (Option B, see plan-for-member-home.md).
 *
 * One same-origin call fans out server-side to the upstream calls the member
 * dashboard needs, so the custom member page paints from a single JSON
 * instead of ~35 proxied /wps/* round-trips:
 *
 *   member/info, wallets/balance (+funds/consolidated fallback),
 *   MCSFE_getPlayerRankProgress, MCSFE_getReferralDetails,
 *   EMFE_getInboxUnreadCount, MCSFE_getDepositSettings,
 *   MCSFE_getWithdrawSettings
 *
 * Route: auto-served by Proxy/router.php (/api/*.php) — no router edit.
 * Auth: same session fingerprint as Proxy/index.php requestSessionKey().
 * Never cached (per-visitor balances), never logged with token.
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
 * GET one upstream path. 6s cap, one retry on 5xx only (a timeout retry
 * would double how long this request holds a PHP slot).
 * Returns [httpStatus, decodedArray|null]. Status 0 = transport failure.
 */
function ms_get(string $base, string $path, array $headers, string $ua): array
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

/** First non-empty string found under any of the candidate keys. */
function ms_str(array $d, array $keys): string
{
    foreach ($keys as $k) {
        if (isset($d[$k]) && $d[$k] !== '' && $d[$k] !== null && !is_array($d[$k])) {
            return (string) $d[$k];
        }
    }
    return '';
}

/** First numeric value found under any of the candidate keys. */
function ms_num(array $d, array $keys, float $def = 0.0): float
{
    foreach ($keys as $k) {
        if (isset($d[$k]) && is_numeric($d[$k])) {
            return (float) $d[$k];
        }
    }
    return $def;
}

/* ---------------- fan-out ---------------- */

$legs = [
    'member'    => '/wps/member/info',
    'balance'   => '/wps/v2/wallets/balance?typeId=0',
    'balanceFb' => '/wps/member/info/funds/consolidated',
    'rank'      => '/wps/relay/MCSFE_getPlayerRankProgress',
    'referral'  => '/wps/relay/MCSFE_getReferralDetails?autoGenerate=true',
    'unread'    => '/wps/relay/EMFE_getInboxUnreadCount',
    'deposit'   => '/wps/relay/MCSFE_getDepositSettings',
    'withdraw'  => '/wps/relay/MCSFE_getWithdrawSettings?groupId=0',
];
$res = [];
foreach ($legs as $k => $p) {
    $res[$k] = ms_get($upstream, $p, $fwd, $clientUa);
}

$units = ['member', 'balance', 'rank', 'referral', 'unread', 'deposit', 'withdraw'];
$legStatus = [];
$failed = 0;
foreach ($units as $k) {
    $r = $res[$k];
    $ok = $r[0] >= 200 && $r[0] < 300 && $r[1] !== null;
    if (!$ok && $k === 'balance') {
        // balanceFb is a fallback, not an eighth requirement: the balance
        // unit only fails when BOTH shapes are down.
        $fb = $res['balanceFb'];
        if ($fb[0] >= 200 && $fb[0] < 300 && $fb[1] !== null) {
            $ok = true;
        }
        $legStatus['balanceFb'] = $fb[0];
    }
    $legStatus[$k] = $r[0];
    if (!$ok) {
        $failed++;
    }
}
// The identity leg decides auth: upstream 401 here means the stored
// session is dead, so say so instead of painting a guest dashboard.
if ($res['member'][0] === 401) {
    http_response_code(401);
    echo json_encode(['success' => false, 'needLogin' => true, 'error' => 'Session expired']);
    exit;
}
if ($failed >= count($units)) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'Upstream unreachable']);
    exit;
}

/* ---------------- shape ---------------- */

$mv = is_array($res['member'][1]['value'] ?? null) ? $res['member'][1]['value'] : [];
$regMs = (int) ms_num($mv, ['regDate', 'registerDate', 'createTime', 'createdAt']);
$member = [
    'nickname' => ms_str($mv, ['nickname', 'nickName', 'username', 'userName']),
    'account'  => ms_str($mv, ['account', 'memberAccount', 'loginName', 'username', 'userName']),
    'mobile'   => ms_str($mv, ['mobile', 'mobileNum', 'phone']),
    'vip'      => ms_str((array) ($mv['clubLabel'] ?? []), ['labelName', 'name']),
    'joined'   => $regMs > 0 ? date('Y-m-d', (int) ($regMs / 1000)) : '',
];
if ($member['vip'] === '') {
    $member['vip'] = ms_str($mv, ['vip', 'vipLevel', 'rankName', 'playerRankName']);
}

// Primary balance shape first, consolidated-funds shape as fallback.
$bv = is_array($res['balance'][1]['value'] ?? null) ? $res['balance'][1]['value'] : [];
$bf = is_array($res['balanceFb'][1]['value'] ?? null) ? $res['balanceFb'][1]['value'] : [];
$sum = ms_num($bv, ['sumBalance', 'sum', 'totalBalance', 'balance']);
$avail = $sum;
$currency = ms_str($bv, ['currency', 'currencyCode']) ?: ms_str($bf, ['currency', 'currencyCode']);
$symbol = ms_str($bv, ['currencySymbol', 'symbol']) ?: ms_str($bf, ['currencySymbol', 'symbol']);
$bl = $bv['balance'] ?? null;
if (is_array($bl)) {
    $isList = $bl === [] || array_keys($bl) === range(0, count($bl) - 1);
    $row = null;
    if ($isList) {
        foreach ($bl as $b) {
            if (is_array($b) && (int) ($b['accountTypeId'] ?? 0) === 2) {
                $row = $b;
                break;
            }
        }
        if ($row === null && isset($bl[0]) && is_array($bl[0])) {
            $row = $bl[0];
        }
    } else {
        $row = $bl;
    }
    if (is_array($row)) {
        $avail = ms_num($row, ['availBalance', 'availableBalance', 'balance'], $sum);
    }
} elseif ($sum <= 0 && $bf !== []) {
    $sum = ms_num($bf, ['sumBalance', 'sum', 'totalBalance', 'balance', 'availBalance', 'availableBalance']);
    $avail = ms_num($bf, ['availBalance', 'availableBalance', 'sumBalance', 'balance'], $sum);
}
// Optimistic hold: a queued/approved withdraw already belongs to the
// member's pending outflow (same rule as applyWithdrawBalance).
$hold = withdraw_active_hold_sum($sessionKey);
if ($hold > 0) {
    $sum = round(max(0.0, $sum - $hold), 2);
    $avail = round(max(0.0, $avail - $hold), 2);
}
if ($currency === '') {
    $currency = 'BDT';
}
if ($symbol === '') {
    $symbol = '৳';
}

$rv = is_array($res['rank'][1]['value'] ?? null) ? $res['rank'][1]['value'] : [];
$mo = is_array($rv['monthlyTurnover'] ?? null) ? $rv['monthlyTurnover'] : [];
$to = is_array($rv['totalTurnover'] ?? null) ? $rv['totalTurnover'] : [];
$vip = [
    'current' => ms_str($rv, ['playerRankName', 'rankName', 'currentRank']) ?: $member['vip'],
    'next' => ms_str($rv, ['nextRankName', 'nextRank']) ?: ms_str($mo, ['nextRankName']) ?: '',
    'turnoverActual' => ms_num($mo, ['actual', 'actualAmount', 'current'], ms_num($to, ['actual', 'actualAmount', 'current'])),
    'turnoverExpected' => ms_num($mo, ['expected', 'expectedAmount', 'target'], ms_num($to, ['expected', 'expectedAmount', 'target'])),
];

$fv = is_array($res['referral'][1]['value'] ?? null) ? $res['referral'][1]['value'] : [];
$referral = [
    'code' => ms_str($fv, ['referralCode', 'code', 'inviteCode']),
    'invites' => (int) ms_num($fv, ['totalInvitations', 'inviteCount', 'totalInvites']),
];

$uv = $res['unread'][1]['value'] ?? null;
$unread = is_numeric($uv) ? (int) $uv : (int) ms_num(is_array($uv) ? $uv : [], ['count', 'unread', 'total']);

$dv = is_array($res['deposit'][1]['value'] ?? null) ? $res['deposit'][1]['value'] : [];
$allowRaw = $dv['allowDeposit'] ?? ($dv['allow'] ?? null);
$deposit = ['allowed' => $allowRaw === true || $allowRaw === 1 || $allowRaw === 'Y' || $allowRaw === 'y'];

$wv = is_array($res['withdraw'][1]['value'] ?? null) ? $res['withdraw'][1]['value'] : [];
$withdraw = [
    'min' => ms_num($wv, ['minimumAmount', 'minAmount'], 100),
    'max' => ms_num($wv, ['maximumAmount', 'maxAmount'], 50000),
];

echo json_encode([
    'success' => true,
    'partial' => $failed > 0,
    'legs' => $legStatus,
    'member' => $member,
    'balance' => ['sum' => round($sum, 2), 'avail' => round($avail, 2), 'currency' => $currency, 'symbol' => $symbol],
    'vip' => $vip,
    'referral' => $referral,
    'unread' => $unread,
    'deposit' => $deposit,
    'withdraw' => $withdraw,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
