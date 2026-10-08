<?php
/**
 * Admin panel: layout, sections and router.
 * Included by admin/index.php. Direct access is blocked by .htaccess.
 *
 * -------------------------------------------------------------------------
 * "Console" — presentation layer only.
 *   The shell was redesigned from scratch: a floating frosted app bar, a
 *   grouped horizontal navigation with dropdown menus, a keyboard command
 *   palette, and a paper/ink visual system with a teal signature.
 *
 *   Everything below the presentation layer is unchanged: handleAdmin() at the
 *   bottom still owns every route, every POST action performs exactly the same
 *   reads/writes, and every form field keeps its original name. Storage lives
 *   in store.php and is untouched.
 * -------------------------------------------------------------------------
 */

require_once __DIR__ . '/store.php';

/* --------------------------- small helpers --------------------------- */

function admin_home_url(string $base): string
{
    return ($base === '' ? '' : $base) . '/admin';
}

function admin_redirect_home(string $base): void
{
    header('Location: ' . ($base === '' ? '/' : $base . '/'), true, 302);
    exit;
}

function admin_boot(string $cookie): void
{
    header('X-Admin-Boot: 1');
    session_name($cookie !== '' ? $cookie : 'px_sid');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => $secure, 'samesite' => 'Lax']);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Referrer-Policy: no-referrer');
}

function admin_authed(): bool
{
    return !empty($_SESSION['px_admin']);
}

function admin_key_match(array $cfg): bool
{
    $k = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
    return $k !== '' && hash_equals((string) ($cfg['key'] ?? ''), $k);
}

function admin_unlocked(array $cfg): bool
{
    return admin_authed() || !empty($_SESSION['px_key']) || admin_key_match($cfg);
}

function admin_csrf(): string
{
    if (empty($_SESSION['px_csrf'])) {
        $_SESSION['px_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['px_csrf'];
}

function admin_csrf_ok(): bool
{
    return isset($_POST['csrf'], $_SESSION['px_csrf']) && hash_equals((string) $_SESSION['px_csrf'], (string) $_POST['csrf']);
}

/** True when a panel form was posted via fetch and expects JSON back. */
function admin_wants_json(): bool
{
    $xrw = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return $xrw === 'fetch' || $xrw === 'xmlhttprequest'
        || strpos($accept, 'application/json') !== false
        || (($_POST['_ajax'] ?? '') === '1');
}

function admin_dir_size(string $dir): array
{
    $count = 0;
    $bytes = 0;
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $count++;
                $bytes += $f->getSize();
            }
        }
    }
    return [$count, $bytes];
}

function admin_purge_cache(string $dir): int
{
    $n = 0;
    if (!is_dir($dir)) {
        return 0;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        if ($f->isFile()) {
            @unlink($f->getPathname());
            $n++;
        } elseif ($f->isDir()) {
            @rmdir($f->getPathname());
        }
    }
    return $n;
}

/* --------------------------- presentation helpers --------------------------- */

/** Inline line-icon set. Returns a bare <svg> sized by the surrounding CSS. */
function admin_icon(string $name): string
{
    $paths = [
        'dashboard'  => '<path d="M4 13h6V4H4z"/><path d="M14 20h6v-9h-6z"/><path d="M14 8h6V4h-6z"/><path d="M4 20h6v-5H4z"/>',
        'titles'     => '<path d="M4 7V5h16v2"/><path d="M10 19h4"/><path d="M12 5v14"/>',
        'logo'       => '<rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="2"/><path d="M21 16l-4.5-4.5L11 17l-2-2-6 6"/>',
        'favicon'    => '<path d="M12 3l2.5 5.6 6 .8-4.4 4.3 1.1 6-5.2-2.9-5.2 2.9 1.1-6L3.5 9.4l6-.8z"/>',
        'tag'        => '<path d="M20.6 13.4 12.6 5.4A2 2 0 0 0 11.2 4H5a1 1 0 0 0-1 1v6.2a2 2 0 0 0 .6 1.4l8 8a1 1 0 0 0 1.4 0l6.6-6.6a1 1 0 0 0 0-1.4Z"/><circle cx="7.8" cy="7.8" r="1.4"/>',
        'banners'    => '<rect x="3" y="4" width="18" height="16" rx="3"/><path d="M3 15l4-4 4 4 3-3 4 4"/><circle cx="8.5" cy="8.5" r="1.5"/>',
        'marquee'    => '<path d="M3 9h12"/><path d="M3 15h12"/><path d="M18 6l3 6-3 6"/><path d="M15 12h-3"/>',
        'games'      => '<rect x="2.5" y="7" width="19" height="11" rx="4"/><path d="M7 10.5v3"/><path d="M5.5 12h3"/><circle cx="16" cy="11" r="1"/><circle cx="18" cy="14" r="1"/>',
        'voucher'    => '<path d="M3 9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v1a2.5 2.5 0 0 0 0 5v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1a2.5 2.5 0 0 0 0-5z"/><path d="M14 8v8"/>',
        'payment_settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'withdrawals' => '<path d="M12 3v12"/><path d="M8 11l4 4 4-4"/><rect x="3" y="17" width="18" height="4" rx="2"/>',
        'users'      => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/><path d="M16.5 5.6a3.2 3.2 0 0 1 0 6.3"/><path d="M17.5 14.2A5.5 5.5 0 0 1 21 19.5"/>',
        'settings'   => '<path d="M4 7h10"/><path d="M18 7h2"/><circle cx="16" cy="7" r="2.2"/><path d="M4 17h6"/><path d="M14 17h6"/><circle cx="12" cy="17" r="2.2"/>',
        'tools'      => '<path d="M14.5 6.2a4 4 0 0 0 5.3 5.3l-8.4 8.4a2.2 2.2 0 0 1-3.1-3.1z"/><path d="M6.5 6.5 4 4"/>',
        'search'     => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'chevron'    => '<path d="M6 9l6 6 6-6"/>',
        'home'       => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'external'   => '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'logout'     => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 12H3"/><path d="M7 8l-4 4 4 4"/>',
        'plus'       => '<path d="M12 5v14"/><path d="M5 12h14"/>',
        'trash'      => '<path d="M4 7h16"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13"/>',
        'check'      => '<path d="M20 6 9 17l-5-5"/>',
        'alert'      => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16h.01"/>',
        'server'     => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01"/><path d="M7 16.5h.01"/>',
        'cache'      => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        'code'       => '<path d="M8 9l-4 3 4 3"/><path d="M16 9l4 3-4 3"/><path d="M13 7l-2 10"/>',
        'menu'       => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>',
        'shield'     => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
        'palette'    => '<path d="M12 21a9 9 0 1 1 0-18c5 0 9 3.4 9 7.5 0 2.5-2 4.5-4.5 4.5H15a2 2 0 0 0-1.4 3.4A1.9 1.9 0 0 1 12 21z"/><circle cx="8" cy="11" r="1"/><circle cx="12" cy="8" r="1"/><circle cx="16" cy="11" r="1"/>',
    ];
    $d = $paths[$name] ?? $paths['dashboard'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

/** Rounded toggle switch. Pass a $name to make it a real checkbox input. */
function admin_switch(string $name, bool $on, string $label = '', string $dataOn = 'On', string $dataOff = 'Off', string $value = '1'): string
{
    $cls = $on ? 'ax-switch ax-switch--on' : 'ax-switch';
    $txt = $label !== ''
        ? '<span class="ax-switch__txt" data-on="' . htmlspecialchars($dataOn, ENT_QUOTES) . '" data-off="' . htmlspecialchars($dataOff, ENT_QUOTES) . '">' . htmlspecialchars($on ? $dataOn : $dataOff) . '</span>'
        : '';
    return '<label class="' . $cls . '"><input type="checkbox" name="' . htmlspecialchars($name, ENT_QUOTES) . '" value="' . htmlspecialchars($value, ENT_QUOTES) . '"' . ($on ? ' checked' : '') . '><span class="ax-switch__track"></span>' . $txt . '</label>';
}

function admin_alert(string $type, string $msg): string
{
    $types = ['ok', 'err', 'warn', 'info'];
    $type = in_array($type, $types, true) ? $type : 'info';
    $ico = $type === 'ok' ? 'check' : 'alert';
    return '<div class="ax-alert ax-alert--' . $type . '"><span class="ax-alert__ico">' . admin_icon($ico) . '</span><span>' . htmlspecialchars($msg) . '</span></div>';
}

function admin_notice_html(string $notice): string
{
    $h = '';
    if (!empty($GLOBALS['admin_error'])) {
        $h .= admin_alert('err', (string) $GLOBALS['admin_error']);
    }
    if ($notice !== '') {
        $h .= admin_alert('ok', $notice);
    }
    return $h;
}

/** Navigation model: ordered groups of [key, label] sections. */
function admin_nav_data(string $base): array
{
    $groups = [
        'Overview'   => [['dashboard', 'Dashboard']],
        'Appearance' => [['titles', 'Titles'], ['logo', 'Logo'], ['favicon', 'Favicon'], ['appname', 'App name']],
        'Content'    => [['banners', 'Banners'], ['marquee', 'Marquee'], ['games', 'Games'], ['voucher', 'Voucher & Payments']],
        'Payments'   => [['payment_settings', 'Pay Settings'], ['withdrawals', 'Withdrawals']],
        'Access'     => [['users', 'Users']],
        'System'     => [['settings', 'Settings'], ['tools', 'Tools']],
    ];
    $groupIcons = [
        'Overview'   => 'dashboard',
        'Appearance' => 'palette',
        'Content'    => 'banners',
        'Payments'   => 'payment_settings',
        'Access'     => 'users',
        'System'     => 'settings',
    ];
    $home = admin_home_url($base);

    $pending = 0;
    if (function_exists('withdraw_approvals_read')) {
        foreach (withdraw_approvals_read() as $it) {
            if (($it['status'] ?? '') === 'Pending') {
                $pending++;
            }
        }
    }

    $out = [];
    foreach ($groups as $label => $items) {
        $list = [];
        foreach ($items as $pair) {
            [$key, $text] = $pair;
            $list[] = [
                'key'   => $key,
                'text'  => $text,
                'icon'  => $key === 'appname' ? 'tag' : $key,
                'url'   => $home . ($key === 'dashboard' ? '' : '/' . $key),
                'badge' => ($key === 'withdrawals' && $pending > 0) ? ($pending > 99 ? '99+' : (string) $pending) : '',
            ];
        }
        $out[] = ['label' => $label, 'icon' => $groupIcons[$label] ?? 'dashboard', 'items' => $list];
    }
    return $out;
}

/** /payment_methods renders the merged Voucher & Payments page. */
function admin_is_active(string $key, string $active): bool
{
    return $key === $active || ($key === 'voucher' && $active === 'payment_methods');
}

/** Sidebar navigation: every section grouped under a label, one click away. */
function admin_nav(string $base, string $active): string
{
    $out = '<nav class="ax-side__nav" aria-label="Admin sections">';
    foreach (admin_nav_data($base) as $group) {
        $out .= '<span class="ax-side__label">' . htmlspecialchars($group['label']) . '</span>';
        foreach ($group['items'] as $it) {
            $on = admin_is_active($it['key'], $active);
            $out .= '<a class="ax-sidelink' . ($on ? ' on' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="' . htmlspecialchars($it['url'], ENT_QUOTES) . '">'
                . admin_icon($it['icon'])
                . '<span class="ax-sidelink__txt">' . htmlspecialchars($it['text']) . '</span>'
                . ($it['badge'] !== '' ? '<span class="ax-pill">' . htmlspecialchars($it['badge']) . '</span>' : '')
                . '</a>';
        }
    }
    return $out . '</nav>';
}

function admin_palette(string $base): string
{
    $blocks = '';
    foreach (admin_nav_data($base) as $group) {
        $items = '';
        foreach ($group['items'] as $it) {
            $items .= '<a class="ax-palette__item" role="menuitem" href="' . htmlspecialchars($it['url'], ENT_QUOTES) . '"'
                . ' data-search="' . htmlspecialchars(strtolower($it['text'] . ' ' . $group['label'] . ' ' . $it['key']), ENT_QUOTES) . '">'
                . admin_icon($it['icon']) . '<span>' . htmlspecialchars($it['text']) . '</span>'
                . '<small>' . htmlspecialchars($group['label']) . '</small></a>';
        }
        $blocks .= '<div class="ax-palette__block"><div class="ax-palette__group">' . htmlspecialchars($group['label']) . '</div>' . $items . '</div>';
    }
    return '<div class="ax-palette" id="ax-palette" role="dialog" aria-modal="true" aria-label="Search menu">'
        . '<div class="ax-palette__box">'
        . '<div class="ax-palette__search">' . admin_icon('search')
        . '<input id="ax-palette-input" type="text" placeholder="Jump to a section…" autocomplete="off" spellcheck="false">'
        . '<kbd>esc</kbd></div>'
        . '<div class="ax-palette__list" id="ax-palette-list">' . $blocks
        . '<div class="ax-palette__empty" id="ax-palette-empty" style="display:none">No sections match your search.</div>'
        . '</div></div></div>';
}

/** The top bar already shows the breadcrumb, so the page head is just the title.
    The optional subtitle argument is kept for call-site compatibility. */
function admin_page_header(string $base, string $title, string $sub = ''): string
{
    return '<header class="ax-pagehead"><h1>' . htmlspecialchars($title) . '</h1></header>';
}

/* --------------------------- styles --------------------------- */

function admin_css(): string
{
    return <<<'CSS'
*,*::before,*::after{box-sizing:border-box}
:root{
--ax-bg:#f1f4f7;--ax-surface:#ffffff;--ax-surface-2:#f6f8fa;
--ax-ink:#0a0f18;--ax-ink-2:#39424f;--ax-muted:#68727f;--ax-faint:#98a1ad;
--ax-line:#e3e8ee;--ax-line-2:#cfd7e0;
--ax-brand:#0f766e;--ax-brand-2:#115e59;--ax-brand-soft:#e8f5f2;
--ax-ink-btn:#101827;
--ax-side-bg:#0b1220;--ax-side-line:rgba(255,255,255,.09);--ax-side-ink:#c6cfdc;--ax-side-muted:#7b8798;--ax-side-hover:rgba(255,255,255,.06);--ax-side-on:#5eead4;
--ax-ok:#166534;--ax-ok-bg:#eefaf1;--ax-ok-line:#bfe8cb;
--ax-warn:#9a5b06;--ax-warn-bg:#fdf6ea;--ax-warn-line:#f2dfb6;
--ax-danger:#b4232d;--ax-danger-bg:#fdf0f1;--ax-danger-line:#f5ccd0;
--ax-focus:rgba(15,118,110,.30);
--ax-r:14px;--ax-r-sm:10px;
--ax-shadow:0 1px 2px rgba(10,15,24,.05),0 12px 26px -18px rgba(10,15,24,.35);
--ax-pop:0 26px 54px -24px rgba(10,15,24,.5);
}
html{-webkit-text-size-adjust:100%}
body{margin:0;display:flex;font-family:Inter,"Segoe UI",system-ui,-apple-system,"Helvetica Neue",Arial,sans-serif;font-size:14.5px;line-height:1.6;color:var(--ax-ink);background:var(--ax-bg);-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
button{font:inherit}
svg{display:block}
img{max-width:100%}
.is-hidden{display:none!important}

/* sidebar */
.ax-side{position:sticky;top:0;flex:0 0 248px;width:248px;height:100vh;display:flex;flex-direction:column;background:linear-gradient(180deg,#0c1424,#0b1220 42%,#0a101d);color:var(--ax-side-ink);z-index:60}
.ax-side__brand{display:flex;align-items:center;gap:11px;padding:18px 16px;border-bottom:1px solid var(--ax-side-line)}
.ax-side__brand .ax-brand__mark{width:36px;height:36px;border-radius:11px;background:linear-gradient(140deg,#14b8a6,#0f766e);color:#fff;display:grid;place-items:center;font-weight:800;font-size:15px;overflow:hidden;flex:0 0 36px}
.ax-side__brand .ax-brand__mark img{width:100%;height:100%;object-fit:cover;display:block}
.ax-side__brand b{display:block;color:#fff;font-size:14px;letter-spacing:-.01em;line-height:1.25}
.ax-side__brand small{color:var(--ax-side-muted);font-size:11.5px;letter-spacing:.02em}
.ax-side__nav{flex:1;overflow-y:auto;padding:14px 10px 18px;scrollbar-width:thin}
.ax-side__label{display:block;padding:14px 10px 7px;font-size:10.6px;font-weight:750;letter-spacing:.1em;text-transform:uppercase;color:var(--ax-side-muted)}
.ax-sidelink{position:relative;display:flex;align-items:center;gap:11px;padding:9px 11px;border-radius:10px;font-size:13.6px;font-weight:600;color:var(--ax-side-ink);transition:.14s}
.ax-sidelink:hover{background:var(--ax-side-hover);color:#fff}
.ax-sidelink.on{background:rgba(20,184,166,.14);color:#fff}
.ax-sidelink.on::before{content:"";position:absolute;left:0;top:9px;bottom:9px;width:3px;border-radius:0 3px 3px 0;background:var(--ax-side-on)}
.ax-sidelink svg{width:17px;height:17px;flex:0 0 17px;color:var(--ax-side-muted);transition:.14s}
.ax-sidelink:hover svg,.ax-sidelink.on svg{color:var(--ax-side-on)}
.ax-sidelink__txt{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ax-side__foot{padding:12px 12px 16px;border-top:1px solid var(--ax-side-line)}
.ax-side__who{display:flex;align-items:center;gap:10px;padding:8px 10px 12px;min-width:0}
.ax-side__who b{display:block;color:#fff;font-size:13.2px;line-height:1.25;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ax-side__who small{color:var(--ax-side-muted);font-size:11.4px}
.ax-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(140deg,#14b8a6,#0f766e);color:#fff;display:grid;place-items:center;font-weight:800;font-size:12.5px;flex:0 0 30px}
.ax-sidelink--quiet{font-size:13px;font-weight:550;color:var(--ax-side-muted)}
.ax-sidelink--quiet:hover{color:#fff}
.ax-sidelink--danger:hover{background:rgba(244,63,94,.14);color:#ffe4e6}
.ax-sidelink--danger:hover svg{color:#fda4af}
.ax-pill{min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#e11d48;color:#fff;font-size:11px;font-weight:800;display:inline-grid;place-items:center}
.ax-scrim{position:fixed;inset:0;background:rgba(8,12,20,.5);opacity:0;pointer-events:none;transition:.2s;z-index:55}
.ax-scrim.on{opacity:1;pointer-events:auto}

/* shell + top bar */
.ax-shell{flex:1;min-width:0;display:flex;flex-direction:column;min-height:100vh}
.ax-topbar{position:sticky;top:0;z-index:40;display:flex;align-items:center;gap:12px;padding:11px 26px;background:rgba(255,255,255,.86);backdrop-filter:blur(12px) saturate(140%);border-bottom:1px solid var(--ax-line)}
.ax-hamb{display:none;width:38px;height:38px;border-radius:11px;border:1px solid var(--ax-line);background:#fff;color:var(--ax-ink-2);place-items:center;cursor:pointer}
.ax-hamb svg{width:18px;height:18px}
.ax-topbar__crumb{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--ax-muted);min-width:0}
.ax-topbar__crumb a:hover{color:var(--ax-brand)}
.ax-topbar__crumb i{color:var(--ax-faint);font-style:normal}
.ax-topbar__crumb b{color:var(--ax-ink);font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ax-topbar__search{margin-left:auto;display:inline-flex;align-items:center;gap:9px;padding:8px 12px;border-radius:11px;border:1px solid var(--ax-line);background:var(--ax-surface-2);color:var(--ax-muted);cursor:pointer;transition:.14s;white-space:nowrap}
.ax-topbar__search:hover{border-color:var(--ax-line-2);color:var(--ax-ink-2);background:#fff}
.ax-topbar__search svg{width:16px;height:16px;flex:0 0 16px}
.ax-topbar__search span{font-size:13.4px}
.ax-topbar__search kbd{font:inherit;font-size:11px;padding:2px 7px;border-radius:6px;border:1px solid var(--ax-line-2);background:#fff;color:var(--ax-faint)}
.ax-live{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:650;color:var(--ax-ink-2);padding:7px 12px;border-radius:999px;background:var(--ax-surface-2);border:1px solid var(--ax-line);white-space:nowrap}
.ax-live i{width:7px;height:7px;border-radius:50%;background:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.18)}

/* page */
.ax-main{flex:1;width:100%;max-width:1180px;margin:0 auto;padding:24px 26px 38px}
.ax-pagehead{margin:0 0 18px}
.ax-pagehead h1{margin:0;font-size:23px;letter-spacing:-.028em;font-weight:800}
.ax-lede{margin:6px 0 0;color:var(--ax-muted);font-size:13.6px;max-width:760px}
.ax-muted{color:var(--ax-muted)}
.ax-spacer{margin-left:auto}
.ax-c{text-align:center}

/* cards */
.ax-card{background:var(--ax-surface);border:1px solid var(--ax-line);border-radius:var(--ax-r);box-shadow:var(--ax-shadow);margin-bottom:18px;overflow:hidden}
.ax-card__head{display:flex;align-items:center;gap:14px;justify-content:space-between;flex-wrap:wrap;padding:16px 18px;border-bottom:1px solid var(--ax-line);background:linear-gradient(180deg,#fff,#fbfcfe)}
.ax-card__head h2{margin:0;font-size:15.5px;font-weight:750;letter-spacing:-.01em}
.ax-card__head h3{margin:0;font-size:15.5px;font-weight:750;letter-spacing:-.01em}
.ax-card__desc{margin:3px 0 0;color:var(--ax-muted);font-size:13px;font-weight:450}
.ax-card__body{padding:18px}
.ax-card__foot{padding:14px 18px;border-top:1px solid var(--ax-line);background:var(--ax-surface-2);display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.ax-card__foot .ax-hint{margin:0}

/* stats + quick links */
.ax-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin-bottom:18px}
.ax-stat{position:relative;background:#fff;border:1px solid var(--ax-line);border-radius:var(--ax-r);padding:17px 18px;box-shadow:var(--ax-shadow);overflow:hidden}
.ax-stat__k{display:flex;align-items:center;gap:8px;color:var(--ax-muted);font-size:11.5px;text-transform:uppercase;letter-spacing:.07em;font-weight:750}
.ax-stat__k svg{width:15px;height:15px;color:var(--ax-brand)}
.ax-stat__v{margin-top:9px;font-size:20px;font-weight:800;letter-spacing:-.02em;word-break:break-word}
.ax-stat::after{content:"";position:absolute;left:0;right:0;bottom:0;height:3px;background:linear-gradient(90deg,var(--ax-brand),#34d399);opacity:0;transition:.2s}
.ax-stat:hover::after{opacity:1}
.ax-quick{display:flex;flex-wrap:wrap;gap:9px}
.ax-quick a{display:inline-flex;align-items:center;gap:8px;padding:9px 13px;border-radius:11px;border:1px solid var(--ax-line);background:var(--ax-surface-2);font-size:13.2px;font-weight:650;color:var(--ax-ink-2);transition:.15s}
.ax-quick a:hover{background:#fff;border-color:var(--ax-line-2);color:var(--ax-ink);transform:translateY(-1px)}
.ax-quick svg{width:15px;height:15px;color:var(--ax-brand)}

/* buttons */
.ax-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 16px;border-radius:11px;border:1px solid transparent;background:var(--ax-ink-btn);color:#fff;font-size:13.6px;font-weight:650;cursor:pointer;white-space:nowrap;box-shadow:0 1px 2px rgba(12,16,22,.18);transition:.15s}
.ax-btn:hover{background:#000}
.ax-btn svg{width:16px;height:16px}
.ax-btn--brand{background:var(--ax-brand);box-shadow:0 8px 18px -12px rgba(15,118,110,.95)}
.ax-btn--brand:hover{background:var(--ax-brand-2)}
.ax-btn--ghost{background:#fff;border-color:var(--ax-line-2);color:var(--ax-ink-2);box-shadow:none}
.ax-btn--ghost:hover{background:var(--ax-surface-2);color:var(--ax-ink);border-color:var(--ax-muted)}
.ax-btn--danger{background:var(--ax-danger);box-shadow:0 8px 18px -14px rgba(180,35,45,.95)}
.ax-btn--danger:hover{background:#8f1c24}
.ax-btn--sm{padding:7px 12px;font-size:12.6px;border-radius:9px}
.ax-btn--sm svg{width:14px;height:14px}
.ax-actions{display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin-top:16px}

/* forms */
.ax-field{margin-top:15px}
.ax-field:first-child{margin-top:0}
.ax-label{display:block;font-size:12.6px;font-weight:650;color:var(--ax-ink-2);margin-bottom:6px}
.ax-hint{margin:6px 0 0;font-size:12.3px;color:var(--ax-muted)}
.ax-input,.ax-select,.ax-textarea{width:100%;padding:10px 13px;border-radius:11px;border:1px solid var(--ax-line-2);background:#fff;color:var(--ax-ink);font:inherit;font-size:14px;transition:.15s;box-shadow:inset 0 1px 1px rgba(12,16,22,.03)}
.ax-input::placeholder,.ax-textarea::placeholder{color:var(--ax-faint)}
.ax-input:focus,.ax-select:focus,.ax-textarea:focus{outline:none;border-color:var(--ax-brand);box-shadow:0 0 0 4px var(--ax-focus)}
.ax-textarea{min-height:92px;resize:vertical}
.ax-select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2369727f' stroke-width='2' stroke-linecap='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;background-size:15px;padding-right:36px}
.ax-row{display:flex;gap:14px;flex-wrap:wrap}
.ax-row>.ax-field{flex:1;min-width:190px;margin-top:0}
.ax-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}
.ax-switch{position:relative;display:inline-flex;align-items:center;gap:9px;cursor:pointer;user-select:none;font-size:13px;color:var(--ax-ink-2);font-weight:650}
.ax-switch input{position:absolute;opacity:0;width:0;height:0}
.ax-switch__track{position:relative;width:42px;height:24px;border-radius:999px;background:#cbd2dc;transition:.18s;flex:0 0 42px}
.ax-switch__track::after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(12,16,22,.3);transition:.18s}
.ax-switch input:checked+.ax-switch__track{background:var(--ax-brand)}
.ax-switch input:checked+.ax-switch__track::after{transform:translateX(18px)}
.ax-switch input:focus-visible+.ax-switch__track{box-shadow:0 0 0 4px var(--ax-focus)}
.ax-switch--on .ax-switch__txt{color:var(--ax-brand-2)}
.ax-check{display:flex;align-items:center;gap:9px;font-size:13.4px;color:var(--ax-ink-2);font-weight:600;cursor:pointer;padding:9px 12px;border:1px solid var(--ax-line);border-radius:11px;background:#fff;transition:.14s}
.ax-check:hover{background:var(--ax-surface-2);border-color:var(--ax-line-2)}
.ax-check input{width:17px;height:17px;accent-color:var(--ax-brand);margin:0;flex:0 0 17px}
.ax-check code{font-size:11.5px;margin-left:auto}
.ax-check--bare{padding:0;border:0;background:transparent;justify-content:center}
.ax-check--bare:hover{background:transparent;border:0}

/* tables */
.ax-tablewrap{margin:16px 0 0;border:1px solid var(--ax-line);border-radius:13px;overflow:auto;background:#fff}
.ax-table{width:100%;border-collapse:collapse;min-width:620px}
.ax-table th{text-align:left;padding:11px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;font-weight:750;color:var(--ax-muted);background:var(--ax-surface-2);border-bottom:1px solid var(--ax-line);white-space:nowrap}
.ax-table td{padding:10px 14px;border-bottom:1px solid #eef1f6;vertical-align:middle;font-size:13.7px}
.ax-table tbody tr:last-child td{border-bottom:0}
.ax-table tbody tr:hover td{background:#fafbfd}
.ax-table .ax-input{padding:8px 11px;font-size:13.4px;border-radius:9px}
.ax-table--compact th,.ax-table--compact td{padding:7px 9px}
.ax-table--compact .ax-input{padding:7px 9px;font-size:13px}
.ax-table__new td{background:var(--ax-surface-2)}
.ax-table--compact .ax-tablewrap{margin-top:10px;border-radius:11px}

/* badges + alerts */
.ax-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:999px;font-size:11.8px;font-weight:700;background:var(--ax-surface-2);border:1px solid var(--ax-line);color:var(--ax-ink-2);white-space:nowrap}
.ax-badge--ok{background:var(--ax-ok-bg);border-color:var(--ax-ok-line);color:var(--ax-ok)}
.ax-badge--warn{background:var(--ax-warn-bg);border-color:var(--ax-warn-line);color:var(--ax-warn)}
.ax-badge--danger{background:var(--ax-danger-bg);border-color:var(--ax-danger-line);color:var(--ax-danger)}
.ax-badge--brand{background:var(--ax-brand-soft);border-color:#bde5de;color:var(--ax-brand-2)}
.ax-badge__dot{width:6px;height:6px;border-radius:50%;background:currentColor}
.ax-alert{display:flex;align-items:flex-start;gap:11px;padding:13px 15px;border-radius:13px;border:1px solid;font-size:13.5px;margin-bottom:16px}
.ax-alert__ico svg{width:18px;height:18px;flex:0 0 18px;margin-top:1px}
.ax-alert--ok{background:var(--ax-ok-bg);border-color:var(--ax-ok-line);color:var(--ax-ok)}
.ax-alert--err{background:var(--ax-danger-bg);border-color:var(--ax-danger-line);color:var(--ax-danger)}
.ax-alert--warn{background:var(--ax-warn-bg);border-color:var(--ax-warn-line);color:var(--ax-warn)}
.ax-alert--info{background:var(--ax-surface-2);border-color:var(--ax-line);color:var(--ax-ink-2)}
code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.4px;background:var(--ax-surface-2);border:1px solid var(--ax-line);border-radius:7px;padding:2px 7px;color:var(--ax-ink-2)}
.ax-listgrid{display:grid;gap:10px}
.ax-preview{display:flex;align-items:center;gap:12px;margin-top:14px;padding:12px;border:1px dashed var(--ax-line-2);border-radius:12px;background:var(--ax-surface-2)}
.ax-preview img{max-height:52px;background:#0b1a1a;border:1px solid var(--ax-line);border-radius:8px;padding:6px}

/* toggle lists (games) */
.ax-togglelist{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px;max-height:440px;overflow:auto;padding:4px;margin-top:12px}

/* sticky save bar */
.ax-savebar{position:sticky;bottom:16px;z-index:20;display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:13px 16px;border-radius:15px;background:rgba(255,255,255,.94);backdrop-filter:blur(10px);border:1px solid var(--ax-line);box-shadow:var(--ax-shadow)}

/* payment methods (MFS): logo tile, channel-first cards, wallet list */
.ax-mfs__head{display:flex;align-items:center;gap:14px;padding:16px 18px;border-bottom:1px solid var(--ax-line);background:linear-gradient(180deg,#fff,#fbfcfe);flex-wrap:wrap}
.ax-mfs__logo{width:46px;height:46px;border-radius:13px;display:grid;place-items:center;overflow:hidden;flex:0 0 46px;background:#fff;border:1px solid var(--ax-line);font-weight:800;font-size:18px;color:var(--ax-ink-2)}
.ax-mfs__logo img{width:100%;height:100%;object-fit:contain;background:#fff;padding:4px}
.ax-mfs__id{min-width:0}
.ax-mfs__id h3{margin:0;font-size:16px;font-weight:750;letter-spacing:-.01em;display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.ax-mfs__id .ax-card__desc{margin-top:2px}
.ax-mfs__head>.ax-switch{margin-left:auto}
.ax-mfs__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}
.ax-mfs__grid .ax-label{margin-top:0}
.ax-mfs__section{border-top:1px solid var(--ax-line)}
.ax-section-head{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:12px}
.ax-section-head h4{margin:0;font-size:12px;text-transform:uppercase;letter-spacing:.08em;font-weight:750;color:var(--ax-muted)}
.ax-section-head .ax-hint{margin:0;max-width:620px}
.ax-channels{display:flex;flex-direction:column;gap:12px}
.ax-ch{border:1px solid var(--ax-line);border-radius:14px;overflow:hidden;background:#fff}
.ax-ch__head{display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--ax-surface-2);border-bottom:1px solid var(--ax-line);flex-wrap:wrap}
.ax-ch__name{flex:1;min-width:180px;font-weight:650}
.ax-ch .ax-tablewrap{margin:0;border:0;border-radius:0}
.ax-ch .ax-table{min-width:520px}
.ax-ch .ax-table td:first-child{min-width:180px}
.ax-ch__empty{margin:0;padding:14px;color:var(--ax-muted);font-size:13px;border:1px dashed var(--ax-line-2);border-radius:13px;background:var(--ax-surface-2)}
.ax-wallets{display:flex;flex-direction:column;gap:9px}
.ax-w{display:flex;align-items:center;gap:9px;flex-wrap:wrap;border:1px solid var(--ax-line);border-radius:12px;padding:10px;background:var(--ax-surface-2)}
.ax-w__num{flex:1;min-width:180px;font-weight:650}
.ax-w__name{flex:1;min-width:150px}
.ax-w>.ax-switch{margin-left:auto}
.ax-wallet-tag{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px;font-weight:650;display:block}
.ax-addbtn{display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border-radius:11px;border:1px dashed var(--ax-line-2);background:transparent;color:var(--ax-ink-2);font-size:13px;font-weight:650;cursor:pointer;transition:.15s}
.ax-addbtn:hover{border-color:var(--ax-brand);color:var(--ax-brand-2);background:var(--ax-brand-soft)}
.ax-addbtn svg{width:15px;height:15px}
.ax-ch-add,.ax-w-add{margin-top:12px}
/* payment methods: overview of every MFS -> click one to open its settings */
.ax-pm-list__head{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;padding:15px 18px;border-bottom:1px solid var(--ax-line);background:linear-gradient(180deg,#fff,#fbfcfe)}
.ax-pm-list__head h3{margin:0;font-size:15.5px;font-weight:750;letter-spacing:-.01em}
.ax-pm-list__head .ax-hint{margin:0}
.ax-mfs-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(255px,1fr));gap:12px;padding:16px 18px}
.ax-mfs-tile{display:flex;align-items:center;gap:12px;width:100%;text-align:left;font:inherit;padding:13px;border:1px solid var(--ax-line);border-radius:14px;background:#fff;cursor:pointer;transition:.15s}
.ax-mfs-tile:hover{border-color:var(--ax-brand);background:var(--ax-brand-soft);transform:translateY(-1px)}
.ax-mfs-tile__logo{width:44px;height:44px;border-radius:13px;display:grid;place-items:center;overflow:hidden;flex:0 0 44px;background:#fff;border:1px solid var(--ax-line);font-weight:800;font-size:17px;color:var(--ax-ink-2)}
.ax-mfs-tile__logo img{width:100%;height:100%;object-fit:contain;background:#fff;padding:4px}
.ax-mfs-tile__body{min-width:0;flex:1}
.ax-mfs-tile__row{display:flex;align-items:center;gap:10px;justify-content:space-between}
.ax-mfs-tile__name{font-size:14.2px;font-weight:700;color:var(--ax-ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ax-mfs-tile__meta{display:block;margin-top:3px;font-size:12.2px;color:var(--ax-muted)}
.ax-pm[data-pm-view=grid] [data-pm-detail]{display:none}
.ax-pm[data-pm-view=detail] .ax-pm-list{display:none}
.ax-pm[data-pm-view=detail] .ax-mfs__panel{display:none}
.ax-pm[data-pm-view=detail] .ax-mfs__panel.is-active{display:block}
.ax-detailbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:13px 18px;border-bottom:1px solid var(--ax-line);background:linear-gradient(180deg,#fff,#fbfcfe)}
.ax-backbtn{display:inline-flex;align-items:center;gap:8px;padding:8px 13px;border-radius:11px;border:1px solid var(--ax-line);background:#fff;color:var(--ax-ink-2);font:inherit;font-size:13px;font-weight:650;cursor:pointer;transition:.15s}
.ax-backbtn:hover{border-color:var(--ax-brand);color:var(--ax-brand-2);background:var(--ax-brand-soft)}
.ax-backbtn svg{width:15px;height:15px;transform:rotate(90deg)}
.ax-detailbar__crumb{font-size:13.6px;font-weight:700;color:var(--ax-ink)}
.ax-iconbtn{width:32px;height:32px;border-radius:10px;border:1px solid var(--ax-line);background:#fff;color:var(--ax-muted);display:inline-grid;place-items:center;cursor:pointer;transition:.15s;flex:0 0 32px}
.ax-iconbtn:hover{color:var(--ax-danger);border-color:var(--ax-danger-line);background:var(--ax-danger-bg)}
.ax-iconbtn svg{width:15px;height:15px}

/* command palette */
.ax-palette{position:fixed;inset:0;z-index:80;display:none;align-items:flex-start;justify-content:center;padding:11vh 16px 16px;background:rgba(12,16,22,.42);backdrop-filter:blur(3px)}
.ax-palette.open{display:flex}
.ax-palette__box{width:100%;max-width:600px;background:#fff;border:1px solid var(--ax-line);border-radius:18px;box-shadow:var(--ax-pop);overflow:hidden;animation:axrise .18s ease}
.ax-palette__search{display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--ax-line)}
.ax-palette__search svg{width:18px;height:18px;color:var(--ax-faint);flex:0 0 18px}
.ax-palette__search input{flex:1;border:0;outline:none;font:inherit;font-size:15.5px;background:transparent;color:var(--ax-ink)}
.ax-palette__search kbd{font:inherit;font-size:11px;padding:3px 8px;border-radius:6px;border:1px solid var(--ax-line-2);color:var(--ax-faint)}
.ax-palette__list{max-height:52vh;overflow:auto;padding:8px}
.ax-palette__group{padding:10px 12px 4px;font-size:10.8px;text-transform:uppercase;letter-spacing:.09em;color:var(--ax-faint);font-weight:750}
.ax-palette__item{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:11px;font-size:14px;color:var(--ax-ink-2);cursor:pointer}
.ax-palette__item svg{width:17px;height:17px;color:var(--ax-faint);flex:0 0 17px}
.ax-palette__item.sel,.ax-palette__item:hover{background:var(--ax-brand-soft);color:var(--ax-brand-2)}
.ax-palette__item.sel svg,.ax-palette__item:hover svg{color:var(--ax-brand)}
.ax-palette__item small{margin-left:auto;color:var(--ax-faint);font-size:11.5px}
.ax-palette__empty{padding:26px;text-align:center;color:var(--ax-muted);font-size:13.5px}

/* toasts */
.ax-toasts{position:fixed;top:16px;right:16px;z-index:90;display:flex;flex-direction:column;gap:10px;max-width:min(92vw,380px)}
.ax-toast{padding:12px 15px;border-radius:13px;border:1px solid var(--ax-line);background:#fff;box-shadow:var(--ax-pop);font-size:13.6px;font-weight:600;opacity:0;transform:translateY(-8px);transition:.22s}
.ax-toast.show{opacity:1;transform:none}
.ax-toast--ok{border-color:var(--ax-ok-line);background:var(--ax-ok-bg);color:var(--ax-ok)}
.ax-toast--err{border-color:var(--ax-danger-line);background:var(--ax-danger-bg);color:var(--ax-danger)}
.ax-btn.is-busy{opacity:.72;pointer-events:none}
.ax-btn.is-busy::after{content:"";width:13px;height:13px;border-radius:50%;border:2px solid currentColor;border-top-color:transparent;animation:axspin .7s linear infinite}
@keyframes axspin{to{transform:rotate(360deg)}}

/* footer */
.ax-foot{max-width:1180px;width:100%;margin:0 auto;padding:0 26px 34px;color:var(--ax-faint);font-size:12.2px}

/* login */
.ax-login{min-height:100vh;width:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:22px;padding:40px 20px;background:radial-gradient(760px 420px at 50% -10%,#123047 0%,transparent 62%),linear-gradient(180deg,#0b1220,#0a101d)}
.ax-login__card{width:100%;max-width:390px;background:var(--ax-surface);border:1px solid rgba(255,255,255,.08);border-radius:18px;box-shadow:var(--ax-pop);padding:28px 26px 26px}
.ax-login__mark{width:44px;height:44px;border-radius:13px;background:linear-gradient(140deg,#14b8a6,#0f766e);color:#fff;display:grid;place-items:center;font-weight:800;font-size:17px;overflow:hidden;margin-bottom:14px}
.ax-login__mark img{width:100%;height:100%;object-fit:cover;display:block}
.ax-login__card h1{margin:0;font-size:21px;letter-spacing:-.025em;font-weight:800}
.ax-login__card .ax-lede{margin:5px 0 20px;font-size:13.4px}
.ax-login__card .ax-field{margin-top:14px}
.ax-login__foot{font-size:12px;color:rgba(255,255,255,.42);text-align:center}

@keyframes axpop{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
@keyframes axrise{from{opacity:0;transform:translateY(10px) scale(.99)}to{opacity:1;transform:none}}

@media(max-width:1024px){
body{display:block}
.ax-side{position:fixed;left:0;top:0;bottom:0;height:100%;transform:translateX(-101%);transition:transform .22s ease;box-shadow:var(--ax-pop)}
.ax-side.open{transform:none}
.ax-hamb{display:grid}
.ax-shell{min-height:100vh}
}
@media(max-width:640px){
.ax-topbar{padding:10px 14px;gap:9px}
.ax-topbar__search span{display:none}
.ax-live{display:none}
.ax-main{padding:18px 14px 34px}
.ax-foot{padding:0 14px 26px}
.ax-pagehead h1{font-size:21px}
.ax-grid{grid-template-columns:1fr 1fr}
.ax-mfs__grid{grid-template-columns:1fr}
.ax-ch__name{min-width:120px}
.ax-mfs__head>.ax-switch{margin-left:0}
}
@media(prefers-reduced-motion:reduce){
*{animation-duration:.001ms!important;transition-duration:.001ms!important}
}
CSS;
}

/* --------------------------- behaviour --------------------------- */

function admin_js(): string
{
    return <<<'JS'
(function(){
  var d = document;
  function q(s){ return Array.prototype.slice.call(d.querySelectorAll(s)); }

  /* sidebar drawer: slides in on small screens, static rail on desktop */
  var side = d.querySelector('.ax-side');
  var scrim = d.querySelector('.ax-scrim');
  var hamb = d.querySelector('[data-ax-drawer]');
  function drawer(open){
    if (!side){ return; }
    side.classList.toggle('open', open);
    if (scrim){ scrim.classList.toggle('on', open); }
    if (hamb){ hamb.setAttribute('aria-expanded', open ? 'true' : 'false'); }
  }
  d.addEventListener('click', function(e){
    var t = e.target;
    if (!t || !t.closest){ return; }
    if (t.closest('[data-ax-drawer]')){
      e.preventDefault();
      drawer(!side.classList.contains('open'));
      return;
    }
    if (t.closest('[data-ax-drawer-close]')){ drawer(false); return; }
    var lo = t.closest('[data-ax-logout]');
    if (lo){
      e.preventDefault();
      var u = lo.getAttribute('href');
      var back = lo.getAttribute('data-ax-login') || u;
      fetch(u, {method:'GET', credentials:'same-origin'}).catch(function(){}).finally(function(){ location.href = back; });
    }
  });
  d.addEventListener('keydown', function(e){ if (e.key === 'Escape'){ drawer(false); } });

  /* switches */
  function syncSwitch(cb){
    var label = cb.closest('.ax-switch');
    if (label){ label.classList.toggle('ax-switch--on', cb.checked); }
    var txt = label ? label.querySelector('.ax-switch__txt') : null;
    if (txt){ txt.textContent = cb.checked ? (txt.getAttribute('data-on') || 'On') : (txt.getAttribute('data-off') || 'Off'); }
  }
  d.addEventListener('change', function(e){
    if (e.target && e.target.type === 'checkbox' && e.target.closest && e.target.closest('.ax-switch')){ syncSwitch(e.target); }
  });

  /* keep wide tables scrollable */
  q('table').forEach(function(tb){
    if (tb.closest('.ax-tablewrap')){ return; }
    var w = d.createElement('div');
    w.className = 'ax-tablewrap';
    tb.parentNode.insertBefore(w, tb);
    w.appendChild(tb);
  });

  /* command palette */
  var pal = d.getElementById('ax-palette');
  var pin = d.getElementById('ax-palette-input');
  var empty = d.getElementById('ax-palette-empty');
  if (pal && pin){
    var items = q('.ax-palette__item');
    var sel = -1;
    function vis(){ return items.filter(function(i){ return !i.classList.contains('is-hidden'); }); }
    function mark(){
      items.forEach(function(i){ i.classList.remove('sel'); });
      var v = vis();
      if (sel >= 0 && sel < v.length){ v[sel].classList.add('sel'); v[sel].scrollIntoView({block:'nearest'}); }
    }
    function filter(){
      var s = pin.value.toLowerCase().trim();
      items.forEach(function(it){
        var hit = !s || (it.getAttribute('data-search') || '').indexOf(s) !== -1;
        it.classList.toggle('is-hidden', !hit);
      });
      var total = 0;
      q('.ax-palette__block').forEach(function(b){
        var shown = b.querySelectorAll('.ax-palette__item:not(.is-hidden)').length;
        b.classList.toggle('is-hidden', shown === 0);
        total += shown;
      });
      if (empty){ empty.style.display = total ? 'none' : 'block'; }
      sel = -1;
    }
    function open(){ pal.classList.add('open'); pin.value = ''; filter(); setTimeout(function(){ pin.focus(); }, 20); }
    function close(){ pal.classList.remove('open'); }
    q('[data-ax-palette-open]').forEach(function(b){ b.addEventListener('click', function(){ open(); }); });
    pal.addEventListener('click', function(e){ if (e.target === pal){ close(); } });
    pin.addEventListener('input', filter);
    pin.addEventListener('keydown', function(e){
      var v = vis();
      if (e.key === 'ArrowDown'){ sel = Math.min(sel + 1, v.length - 1); mark(); e.preventDefault(); }
      else if (e.key === 'ArrowUp'){ sel = Math.max(sel - 1, 0); mark(); e.preventDefault(); }
      else if (e.key === 'Enter'){ var pick = v[sel >= 0 ? sel : 0]; if (pick){ location.href = pick.getAttribute('href'); } e.preventDefault(); }
      else if (e.key === 'Escape'){ close(); }
    });
    d.addEventListener('keydown', function(e){
      var tag = (e.target && e.target.tagName) || '';
      var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(tag) || (e.target && e.target.isContentEditable);
      if (!typing && (e.key === '/' || ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')))){
        e.preventDefault();
        open();
      }
    });
  }

  /* ---------- async saving (no page reload) ---------- */
  var toastHost = d.getElementById('ax-toasts');
  function toast(type, msg){
    if (!toastHost || !msg){ return; }
    var el = d.createElement('div');
    el.className = 'ax-toast ' + (type === 'ok' ? 'ax-toast--ok' : 'ax-toast--err');
    el.textContent = msg;
    toastHost.appendChild(el);
    requestAnimationFrame(function(){ el.classList.add('show'); });
    setTimeout(function(){ el.classList.remove('show'); setTimeout(function(){ el.remove(); }, 260); }, 4200);
  }
  function qa(root, sel){ return Array.prototype.slice.call(root.querySelectorAll(sel)); }
  function statusBadge(st){
    var cls = st === 'Approved' ? 'ax-badge--ok' : (st === 'Rejected' ? 'ax-badge--danger' : 'ax-badge--warn');
    return '<span class="ax-badge ' + cls + '"><span class="ax-badge__dot"></span>' + st + '</span>';
  }
  function reindexNewRow(row, idx){
    qa(row, 'input').forEach(function(inp){
      inp.setAttribute('name', inp.getAttribute('name').replace(/\[\d+\]/g, '[' + idx + ']'));
      if (inp.type === 'checkbox'){ inp.checked = inp.getAttribute('name').indexOf('[active]') !== -1; }
      else { inp.value = ''; }
    });
  }
  /* A repeater's add-row becomes a real row once it has content, and a fresh
     empty add-row is appended, so saving twice in a row never drops entries. */
  function promoteNewRow(form, keyField){
    var newRow = form.querySelector('tr.ax-table__new');
    if (!newRow){ return; }
    var first = newRow.querySelector('input[name$="[' + keyField + ']"]');
    if (!first || first.value.trim() === ''){ return; }
    var nextIdx = 0;
    qa(form, 'tbody tr').forEach(function(tr){
      var f = tr.querySelector('input[name$="[' + keyField + ']"]');
      var m = f ? f.getAttribute('name').match(/\[(\d+)\]/) : null;
      if (m){ nextIdx = Math.max(nextIdx, parseInt(m[1], 10) + 1); }
    });
    newRow.classList.remove('ax-table__new');
    var cells = newRow.children;
    var last = cells[cells.length - 1];
    if (last){
      last.className = 'ax-c';
      last.textContent = '';
      var lab = d.createElement('label');
      lab.className = 'ax-check ax-check--bare';
      var cb = d.createElement('input');
      cb.type = 'checkbox';
      cb.value = '1';
      cb.setAttribute('name', first.getAttribute('name').replace(/\[[a-z]+\]$/, '') + '[remove]');
      lab.appendChild(cb);
      last.appendChild(lab);
    }
    if (form.__newTpl && form.querySelector('tbody')){
      var fresh = form.__newTpl.cloneNode(true);
      reindexNewRow(fresh, nextIdx);
      form.querySelector('tbody').appendChild(fresh);
    }
  }
  function applyDom(act, form, res){
    if (act === 'save_banners' || act === 'save_marquee'){
      qa(form, 'tbody tr').forEach(function(tr){
        var rm = tr.querySelector('input[name$="[remove]"]');
        if (rm && rm.checked){ tr.remove(); }
        else if (rm){ rm.checked = false; }
      });
      promoteNewRow(form, act === 'save_banners' ? 'image' : 'text');
    }
    if (act === 'delete_user'){
      var dtr = form.closest('tr');
      if (dtr){ dtr.remove(); }
    }
    if (act === 'add_user' && res.user){
      var tb = null;
      var card = form.closest('.ax-card');
      if (card){ tb = card.querySelector('table tbody'); }
      if (!tb){ tb = d.querySelector('.ax-main table tbody'); }
      if (tb){
        var ntr = d.createElement('tr');
        ntr.innerHTML = '<td><strong></strong></td><td><span class="ax-badge"></span></td><td class="ax-muted"></td><td class="ax-c"><span class="ax-muted">—</span></td>';
        ntr.children[0].querySelector('strong').textContent = res.user.username;
        ntr.children[1].querySelector('.ax-badge').textContent = res.user.role;
        ntr.children[2].textContent = res.user.created || '';
        tb.appendChild(ntr);
      }
      qa(form, 'input').forEach(function(inp){ if (inp.type !== 'checkbox'){ inp.value = ''; } });
    }
    if (act === 'withdraw_decide'){
      var row = form.closest('tr');
      if (row){
        var cell = row.querySelector('[data-ax-status]');
        if (cell && res.decision){ cell.innerHTML = statusBadge(res.decision); }
        var ops = row.querySelector('[data-ax-ops]');
        if (ops){ ops.innerHTML = '<span class="ax-muted">—</span>'; }
      }
    }
    if (act === 'purge' && res.cache){
      var desc = d.querySelector('[data-ax-cache]');
      if (desc){ desc.textContent = res.cache.files + ' cached files · ' + (res.cache.bytes / 1048576).toFixed(2) + ' MB'; }
      var cnt = d.querySelector('[data-ax-cache-count]');
      if (cnt){ cnt.textContent = res.cache.files + ' files'; }
    }
    if (res.asset){
      var prev = d.querySelector('[data-ax-preview="' + res.asset.type + '"]');
      if (prev && res.asset.url){
        prev.setAttribute('src', res.asset.url);
        var wrap = prev.closest('.ax-preview');
        if (wrap){ wrap.style.display = ''; }
      }
      if (res.asset.type === 'logo' && res.asset.url){
        var mark = d.querySelector('.ax-brand__mark');
        if (mark){
          var mimg = mark.querySelector('img');
          if (!mimg){ mark.innerHTML = '<img alt="logo">'; mimg = mark.querySelector('img'); }
          mimg.setAttribute('src', res.asset.url);
        }
      }
      if (res.asset.type === 'favicon' && res.asset.url){
        var link = d.querySelector('link[rel="icon"]');
        if (!link){ link = d.createElement('link'); link.rel = 'icon'; d.head.appendChild(link); }
        link.setAttribute('href', res.asset.url);
      }
    }
  }
  /* Capture each repeater's empty add-row so a fresh one can be rebuilt later. */
  q('form').forEach(function(f){
    var nr = f.querySelector('tr.ax-table__new');
    if (nr){ f.__newTpl = nr.cloneNode(true); }
  });
  d.addEventListener('submit', function(e){
    var form = e.target;
    if (!form || String(form.method).toLowerCase() !== 'post'){ return; }
    if (!form.closest('.ax-main')){ return; }
    e.preventDefault();
    var btn = e.submitter || form.querySelector('button[type="submit"]');
    var msg = (e.submitter && e.submitter.getAttribute('data-ax-confirm')) || form.getAttribute('data-ax-confirm');
    if (msg && !window.confirm(msg)){ return; }
    var fd = new FormData(form);
    if (e.submitter && e.submitter.name){ fd.append(e.submitter.name, e.submitter.value); }
    var act = (form.querySelector('input[name="action"]') || {}).value || '';
    var url = form.getAttribute('action') || location.href;
    if (btn){ btn.disabled = true; btn.classList.add('is-busy'); }
    fetch(url, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    }).then(function(r){ return r.json(); }).then(function(res){
      if (res && res.ok){ toast('ok', res.notice || 'Saved.'); applyDom(act, form, res); }
      else { toast('err', (res && res.error) || 'Could not save. Please try again.'); }
    }).catch(function(){
      toast('err', 'Network error — nothing was saved.');
    }).finally(function(){
      if (btn){ btn.disabled = false; btn.classList.remove('is-busy'); }
    });
  });
})();
JS;
}

/* --------------------------- shell --------------------------- */

function admin_layout(string $base, string $active, string $title, string $content, string $user = ''): void
{
    $cc = content_load();
    $fav = trim((string) ($cc['favicon']['url'] ?? ''));
    $logoUrl = trim((string) ($cc['logo']['url'] ?? ''));

    $brandPlain = (defined('BRAND_TO') && BRAND_TO !== '') ? BRAND_TO : 'Proxy';
    $brand = htmlspecialchars($brandPlain, ENT_QUOTES);
    $name = $user !== '' ? $user : 'admin';
    $u = htmlspecialchars($name, ENT_QUOTES);
    $initial = strtoupper(substr($name, 0, 1));
    $initial = htmlspecialchars($initial !== '' ? $initial : 'A', ENT_QUOTES);
    $role = htmlspecialchars((string) ($_SESSION['px_role'] ?? 'admin'), ENT_QUOTES);

    $home = admin_home_url($base);
    $homeE = htmlspecialchars($home, ENT_QUOTES);
    $siteE = htmlspecialchars($base === '' ? '/' : $base . '/', ENT_QUOTES);
    $loginE = htmlspecialchars($home . '/login', ENT_QUOTES);
    $logoutE = htmlspecialchars($home . '/logout', ENT_QUOTES);

    $t = htmlspecialchars($title, ENT_QUOTES);
    $favTag = $fav !== '' ? '<link rel="icon" href="' . htmlspecialchars($fav, ENT_QUOTES) . '">' : '';
    $logoInner = $logoUrl !== ''
        ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES) . '" alt="' . $brand . '">'
        : '<span>' . htmlspecialchars(strtoupper(substr($brandPlain, 0, 1)), ENT_QUOTES) . '</span>';

    $icoSearch = admin_icon('search');
    $icoMenu = admin_icon('menu');
    $icoExternal = admin_icon('external');
    $icoLogout = admin_icon('logout');

    $nav = admin_nav($base, $active);
    $palette = admin_palette($base);
    $css = admin_css();
    $js = admin_js();

    header('X-Proxy-Panel: new');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow,noarchive">
<meta name="viewport" content="width=device-width,initial-scale=1">
$favTag
<title>$t · $brand Console</title>
<style>$css</style>
</head>
<body>
<aside class="ax-side" id="ax-side">
  <a class="ax-side__brand" href="$homeE">
    <span class="ax-brand__mark">$logoInner</span>
    <span><b>$brand Console</b><small>Control center</small></span>
  </a>
  $nav
  <div class="ax-side__foot">
    <div class="ax-side__who">
      <span class="ax-avatar">$initial</span>
      <span><b>$u</b><small>$role</small></span>
    </div>
    <a class="ax-sidelink ax-sidelink--quiet" href="$siteE" target="_blank" rel="noopener">$icoExternal<span class="ax-sidelink__txt">View site</span></a>
    <a class="ax-sidelink ax-sidelink--quiet ax-sidelink--danger" href="$logoutE" data-ax-logout data-ax-login="$loginE">$icoLogout<span class="ax-sidelink__txt">Log out</span></a>
  </div>
</aside>
<div class="ax-scrim" data-ax-drawer-close></div>
<div class="ax-shell">
  <header class="ax-topbar">
    <button type="button" class="ax-hamb" data-ax-drawer aria-controls="ax-side" aria-expanded="false" aria-label="Open menu">$icoMenu</button>
    <div class="ax-topbar__crumb"><a href="$homeE">Console</a><i>/</i><b>$t</b></div>
    <button type="button" class="ax-topbar__search" data-ax-palette-open aria-label="Search menu">$icoSearch<span>Search</span><kbd>/</kbd></button>
    <span class="ax-live"><i></i>Live</span>
  </header>
  <main class="ax-main">$content</main>
  <div class="ax-foot">$brand Console · private admin area</div>
</div>
<div class="ax-toasts" id="ax-toasts" role="status" aria-live="polite"></div>
$palette
<script>$js</script>
</body>
</html>
HTML;
}

/* --------------------------- login --------------------------- */

function admin_render_login(string $base, string $error): void
{
    $cc = content_load();
    $fav = trim((string) ($cc['favicon']['url'] ?? ''));
    $logoUrl = trim((string) ($cc['logo']['url'] ?? ''));
    $brandPlain = (defined('BRAND_TO') && BRAND_TO !== '') ? BRAND_TO : 'Proxy';
    $brand = htmlspecialchars($brandPlain, ENT_QUOTES);
    $initial = htmlspecialchars(strtoupper(substr($brandPlain, 0, 1)), ENT_QUOTES);
    $logoInner = $logoUrl !== ''
        ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES) . '" alt="' . $brand . '">'
        : '<span>' . $initial . '</span>';
    $favTag = $fav !== '' ? '<link rel="icon" href="' . htmlspecialchars($fav, ENT_QUOTES) . '">' : '';
    $action = htmlspecialchars(admin_home_url($base) . '/login', ENT_QUOTES);
    $csrf = htmlspecialchars(admin_csrf(), ENT_QUOTES);
    $err = $error !== '' ? admin_alert('err', $error) : '';
    $css = admin_css();

    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow,noarchive">
<meta name="viewport" content="width=device-width,initial-scale=1">
$favTag
<title>Sign in · $brand Console</title>
<style>$css</style>
</head>
<body>
<div class="ax-login">
  <div class="ax-login__card">
    <span class="ax-login__mark">$logoInner</span>
    <h1>$brand Console</h1>
    <p class="ax-lede">Sign in to continue.</p>
    $err
    <form method="post" action="$action">
      <input type="hidden" name="csrf" value="$csrf">
      <div class="ax-field"><label class="ax-label" for="ax-user">Username</label><input class="ax-input" id="ax-user" name="username" autocomplete="username" autofocus></div>
      <div class="ax-field"><label class="ax-label" for="ax-pass">Password</label><input class="ax-input" id="ax-pass" type="password" name="password" autocomplete="current-password"></div>
      <button class="ax-btn ax-btn--brand" type="submit" style="width:100%;margin-top:16px">Sign in</button>
    </form>
  </div>
  <div class="ax-login__foot">$brand Console · private admin area</div>
</div>
</body>
</html>
HTML;
}

/* --------------------------- sections --------------------------- */

function admin_render_dashboard(string $base, string $notice = ''): void
{
    [$files, $bytes] = admin_dir_size(CACHE_DIR);
    $users = users_load();
    $content = content_load();

    $stats = [
        ['Upstream', UPSTREAM !== '' ? UPSTREAM : '—', 'server'],
        ['Brand', (BRAND_FROM !== '' ? BRAND_FROM . ' → ' : '') . BRAND_TO, 'tag'],
        ['Cache', number_format($files) . ' files · ' . number_format($bytes / 1048576, 2) . ' MB', 'cache'],
        ['Admin users', (string) count($users), 'users'],
        ['Banners', (string) count($content['banners']), 'banners'],
        ['PHP', PHP_VERSION, 'code'],
    ];
    $cards = '';
    foreach ($stats as $s) {
        $cards .= '<div class="ax-stat"><span class="ax-stat__k">' . admin_icon($s[2]) . htmlspecialchars($s[0]) . '</span>'
            . '<span class="ax-stat__v">' . htmlspecialchars((string) $s[1]) . '</span></div>';
    }

    $home = admin_home_url($base);
    $quick = [
        ['voucher', 'Voucher & Payments', 'voucher'],
        ['banners', 'Manage banners', 'banners'],
        ['games', 'Games', 'games'],
        ['withdrawals', 'Withdrawals', 'withdrawals'],
        ['users', 'Add admin user', 'users'],
        ['tools', 'Purge cache', 'tools'],
    ];
    $links = '';
    foreach ($quick as $qq) {
        $links .= '<a href="' . htmlspecialchars($home . '/' . $qq[0], ENT_QUOTES) . '">' . admin_icon($qq[2]) . htmlspecialchars($qq[1]) . '</a>';
    }

    $ok = admin_notice_html($notice);
    $head = admin_page_header($base, 'Dashboard', 'Live system status and shortcuts into the sections you use most.');
    $body = $ok . $head
        . '<div class="ax-grid">' . $cards . '</div>'
        . '<div class="ax-card"><div class="ax-card__head"><div><h2>Quick actions</h2><p class="ax-card__desc">Jump straight to the sections you reach for most.</p></div></div>'
        . '<div class="ax-card__body"><div class="ax-quick">' . $links . '</div></div></div>';
    admin_layout($base, 'dashboard', 'Dashboard', $body, $_SESSION['px_user'] ?? '');
}

function admin_render_banners(string $base, string $notice = ''): void
{
    $banners = content_load()['banners'];
    $rows = '';
    foreach ($banners as $i => $b) {
        $rows .= '<tr>'
            . '<td><input class="ax-input" name="b[' . $i . '][image]" value="' . htmlspecialchars((string) ($b['image'] ?? '')) . '" placeholder="https://…/banner.png"></td>'
            . '<td><input class="ax-input" name="b[' . $i . '][link]" value="' . htmlspecialchars((string) ($b['link'] ?? '')) . '" placeholder="https://…"></td>'
            . '<td><input class="ax-input" name="b[' . $i . '][title]" value="' . htmlspecialchars((string) ($b['title'] ?? '')) . '" placeholder="Title"></td>'
            . '<td class="ax-c"><label class="ax-check ax-check--bare"><input type="checkbox" name="b[' . $i . '][active]" value="1"' . (!empty($b['active']) ? ' checked' : '') . '></label></td>'
            . '<td class="ax-c"><label class="ax-check ax-check--bare"><input type="checkbox" name="b[' . $i . '][remove]" value="1"></label></td>'
            . '</tr>';
    }
    $n = count($banners);
    $rows .= '<tr class="ax-table__new">'
        . '<td><input class="ax-input" name="b[' . $n . '][image]" placeholder="Add image URL"></td>'
        . '<td><input class="ax-input" name="b[' . $n . '][link]" placeholder="Link"></td>'
        . '<td><input class="ax-input" name="b[' . $n . '][title]" placeholder="Title"></td>'
        . '<td class="ax-c"><label class="ax-check ax-check--bare"><input type="checkbox" name="b[' . $n . '][active]" value="1" checked></label></td>'
        . '<td class="ax-c ax-muted">new</td></tr>';

    $ok = admin_notice_html($notice);
    $head = admin_page_header($base, 'Banners', 'Drives the storefront&rsquo;s own game-banner carousel. Each active image becomes a slide; use the last row to add one and tick Remove to delete.');
    $action = htmlspecialchars(admin_home_url($base) . '/banners', ENT_QUOTES);
    $csrf = htmlspecialchars(admin_csrf(), ENT_QUOTES);
    $body = <<<HTML
$ok$head
<form method="post" action="$action">
<input type="hidden" name="csrf" value="$csrf">
<input type="hidden" name="action" value="save_banners">
<div class="ax-card">
  <div class="ax-card__head"><div><h2>Banner slides</h2><p class="ax-card__desc">Order top to bottom. Inactive slides stay saved but are not shown.</p></div></div>
  <div class="ax-card__body">
    <div class="ax-tablewrap">
      <table class="ax-table">
        <thead><tr><th>Image URL</th><th>Link</th><th>Title</th><th class="ax-c">Active</th><th class="ax-c">Remove</th></tr></thead>
        <tbody>$rows</tbody>
      </table>
    </div>
  </div>
  <div class="ax-card__foot"><span class="ax-spacer"></span><button class="ax-btn ax-btn--brand" type="submit">Save banners</button></div>
</div>
</form>
HTML;
    admin_layout($base, 'banners', 'Banners', $body, $_SESSION['px_user'] ?? '');
}

function admin_render_marquee(string $base, string $notice = ''): void
{
    $m = content_load()['marquee'];
    $items = $m['items'];
    $rows = '';
    foreach ($items as $i => $it) {
        $rows .= '<tr>'
            . '<td><input class="ax-input" name="m[' . $i . '][text]" value="' . htmlspecialchars((string) ($it['text'] ?? '')) . '" placeholder="Message text"></td>'
            . '<td><input class="ax-input" name="m[' . $i . '][link]" value="' . htmlspecialchars((string) ($it['link'] ?? '')) . '" placeholder="https://… (optional)"></td>'
            . '<td class="ax-c"><label class="ax-check ax-check--bare"><input type="checkbox" name="m[' . $i . '][remove]" value="1"></label></td>'
            . '</tr>';
    }
    $n = count($items);
    $rows .= '<tr class="ax-table__new">'
        . '<td><input class="ax-input" name="m[' . $n . '][text]" placeholder="Add message"></td>'
        . '<td><input class="ax-input" name="m[' . $n . '][link]" placeholder="Link (optional)"></td>'
        . '<td class="ax-c ax-muted">new</td></tr>';

    $ok = admin_notice_html($notice);
    $head = admin_page_header($base, 'Marquee', 'Drives the site&rsquo;s own scrolling notice bar. Add multiple messages and each scrolls in turn.');
    $action = htmlspecialchars(admin_home_url($base) . '/marquee', ENT_QUOTES);
    $csrf = htmlspecialchars(admin_csrf(), ENT_QUOTES);
    $switch = admin_switch('enabled', !empty($m['enabled']), 'Enabled');
    $bg = htmlspecialchars((string) $m['bg'], ENT_QUOTES);
    $color = htmlspecialchars((string) $m['color'], ENT_QUOTES);
    $speed = (int) $m['speed'];
    $body = <<<HTML
$ok$head
<form method="post" action="$action">
<input type="hidden" name="csrf" value="$csrf">
<input type="hidden" name="action" value="save_marquee">
<input type="hidden" name="enabled" value="0">
<div class="ax-card">
  <div class="ax-card__head"><div><h2>Notice bar</h2><p class="ax-card__desc">Messages scroll one after another across the storefront.</p></div>$switch</div>
  <div class="ax-card__body">
    <div class="ax-tablewrap" style="margin-top:0">
      <table class="ax-table">
        <thead><tr><th>Text</th><th>Link (optional)</th><th class="ax-c">Remove</th></tr></thead>
        <tbody>$rows</tbody>
      </table>
    </div>
    <div class="ax-row" style="margin-top:16px">
      <div class="ax-field"><label class="ax-label">Background</label><input class="ax-input" name="bg" value="$bg"></div>
      <div class="ax-field"><label class="ax-label">Text color</label><input class="ax-input" name="color" value="$color"></div>
      <div class="ax-field"><label class="ax-label">Scroll speed (higher = faster)</label><input class="ax-input" type="number" name="speed" min="20" max="800" value="$speed"></div>
    </div>
  </div>
  <div class="ax-card__foot"><span class="ax-spacer"></span><button class="ax-btn ax-btn--brand" type="submit">Save marquee</button></div>
</div>
</form>
HTML;
    admin_layout($base, 'marquee', 'Marquee', $body, $_SESSION['px_user'] ?? '');
}

function admin_render_voucher(string $base, string $notice = ''): void
{
    $all = content_load();
    $v = is_array($all['voucher'] ?? null) ? $all['voucher'] : voucher_defaults();
    $e = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    };

    $ok = admin_notice_html($notice);
    $head = admin_page_header($base, 'Voucher & Payments');
    $action = htmlspecialchars(admin_home_url($base) . '/voucher', ENT_QUOTES);
    $csrf = htmlspecialchars(admin_csrf(), ENT_QUOTES);
    $switch = admin_switch('enabled', !empty($v['enabled']), 'Enabled');
    $path = $e($v['path'] ?? '/m/voucherCenter');
    $redirect = $e($v['redirect_url'] ?? '/voucherCenter/');

    $body = <<<HTML
$ok$head
<form method="post" action="$action">
<input type="hidden" name="csrf" value="$csrf">
<input type="hidden" name="action" value="save_voucher">
<input type="hidden" name="enabled" value="0">
<div class="ax-card">
  <div class="ax-card__head"><div><h2>Voucher redirect</h2></div>$switch</div>
  <div class="ax-card__body">
    <div class="ax-row">
      <div class="ax-field"><label class="ax-label">Source path</label><input class="ax-input" name="path" value="$path" placeholder="/m/voucherCenter"></div>
      <div class="ax-field"><label class="ax-label">Redirect URL</label><input class="ax-input" name="redirect_url" value="$redirect" placeholder="/voucherCenter/"></div>
    </div>
  </div>
  <div class="ax-card__foot"><span class="ax-spacer"></span><button class="ax-btn ax-btn--brand" type="submit">Save</button></div>
</div>
</form>
HTML;
    $body .= admin_payment_methods_block(admin_home_url($base) . '/payment_methods', admin_csrf());
    // Payment settings live here as well, at the end of the page.
    $body .= admin_payment_settings_block(admin_home_url($base) . '/payment_settings', admin_csrf());
    admin_layout($base, 'voucher', 'Voucher & Payments', $body, $_SESSION['px_user'] ?? '');
}

function admin_render_titles(string $base, string $notice = ''): void
{
    $t = content_load()['titles'];
    $ok = admin_notice_html($notice);
    $head = admin_page_header($base, 'Titles', 'The browser tab title for web and the title used by the mobile app.');
    $action = htmlspecialchars(admin_home_url($base) . '/titles', ENT_QUOTES);
    $csrf = htmlspecialchars(admin_csrf(), ENT_QUOTES);
    $web = htmlspecialchars((string) $t['web_title'], ENT_QUOTES);
    $mobile = htmlspecialchars((string) $t['mobile_title'], ENT_QUOTES);
    $body = <<<HTML
$ok$head
<div class="ax-card">
  <div class="ax-card__head"><div><h2>Page titles</h2><p class="ax-card__desc">Shown in the browser tab and mobile app header.</p></div></div>
  <form method="post" action="$action">
  <input type="hidden" name="csrf" value="$csrf">
  <input type="hidden" name="action" value="save_titles">
  <div class="ax-card__body">
    <div class="ax-row">
      <div class="ax-field"><label class="ax-label">Web app title (browser tab)</label><input class="ax-input" name="web_title" value="$web" placeholder="My Brand"></div>
      <div class="ax-field"><label class="ax-label">Mobile app title</label><input class="ax-input" name="mobile_title" value="$mobile" placeholder="My Brand App"></div>
    </div>
  </div>
  <div class="ax-card__foot"><span class="ax-spacer"></span><button class="ax-btn ax-btn--brand" type="submit">Save titles</button></div>
  </form>
</div>
HTML;
    admin_layout($base, 'titles', 'Titles', $body, $_SESSION['px_user'] ?? '');
}

function admin_render_logo(string $base, string $notice = ''): void
{
    $l = content_load()['logo'];
    $ok = admin_notice_html($notice);
    $head = admin_page_header($base, 'Logo', 'Stored locally and served from this origin — no external load.');
    $action = htmlspecialchars(admin_home_url($base) . '/logo', ENT_QUOTES);
    $csrf = htmlspecialchars(admin_csrf(), ENT_QUOTES);
    $url = htmlspecialchars((string) $l['url'], ENT_QUOTES);
    $width = (int) $l['width'];
    $logoVal = htmlspecialchars((string) $l['url'], ENT_QUOTES);
    $preview = '<div class="ax-preview"' . ((string) $l['url'] === '' ? ' style="display:none"' : '') . '>'
        . '<img data-ax-preview="logo"' . ($logoVal !== '' ? ' src="' . $logoVal . '"' : '') . ' alt="logo preview">'
        . '<span class="ax-muted">Current logo</span></div>';
    $body = <<<HTML
$ok$head
<div class="ax-card">
  <div class="ax-card__head"><div><h2>Brand logo</h2><p class="ax-card__desc">Saved to <code>/images/brand/logo.*</code>. Upload a file, or paste an image URL and it is downloaded on save. Leave blank to keep the current logo.</p></div></div>
  <form method="post" action="$action" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="$csrf">
  <input type="hidden" name="action" value="save_logo">
  <div class="ax-card__body">
    <div class="ax-field"><label class="ax-label">Upload logo (png/jpg/webp/svg, max 3MB)</label><input class="ax-input" type="file" name="file" accept="image/*,.ico,.svg"></div>
    <div class="ax-field"><label class="ax-label">…or paste an image URL (auto-downloaded to local)</label><input class="ax-input" name="url" value="$url" placeholder="https://…/logo.png"></div>
    <div class="ax-field"><label class="ax-label">Max width (px, optional)</label><input class="ax-input" type="number" name="width" min="0" max="2000" value="$width" style="max-width:220px"></div>
    $preview
  </div>
  <div class="ax-card__foot"><span class="ax-spacer"></span><button class="ax-btn ax-btn--brand" type="submit">Save logo</button></div>
  </form>
</div>
HTML;
    admin_layout($base, 'logo', 'Logo', $body, $_SESSION['px_user'] ?? '');
}

function admin_render_favicon(string $base, string $notice = ''): void
{
    $f = content_load()['favicon'];
    $ok = admin_notice_html($notice);
    $head = admin_page_header($base, 'Favicon', 'Stored locally and served from this origin. Use png, ico or svg.');
    $action = htmlspecialchars(admin_home_url($base) . '/favicon', ENT_QUOTES);
    $csrf = htmlspecialchars(admin_csrf(), ENT_QUOTES);
    $url = htmlspecialchars((string) $f['url'], ENT_QUOTES);
    $favVal = htmlspecialchars((string) $f['url'], ENT_QUOTES);
    $preview = '<div class="ax-preview" style="max-width:max-content' . ((string) $f['url'] === '' ? ';display:none' : '') . '">'
        . '<img data-ax-preview="favicon"' . ($favVal !== '' ? ' src="' . $favVal . '"' : '') . ' alt="favicon preview" style="max-height:32px;width:32px;object-fit:contain">'
        . '<span class="ax-muted">Current favicon</span></div>';
    $body = <<<HTML
$ok$head
<div class="ax-card">
  <div class="ax-card__head"><div><h2>Site favicon</h2><p class="ax-card__desc">Saved to <code>/images/brand/favicon.*</code>. Upload a file, or paste an image URL and it is downloaded on save.</p></div></div>
  <form method="post" action="$action" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="$csrf">
  <input type="hidden" name="action" value="save_favicon">
  <div class="ax-card__body">
    <div class="ax-field"><label class="ax-label">Upload favicon (png/ico/svg, max 3MB)</label><input class="ax-input" type="file" name="file" accept="image/*,.ico,.svg"></div>
    <div class="ax-field"><label class="ax-label">…or paste an image URL (auto-downloaded to local)</label><input class="ax-input" name="url" value="$url" placeholder="https://…/favicon.png"></div>
    $preview
  </div>
  <div class="ax-card__foot"><span class="ax-spacer"></span><button class="ax-btn ax-btn--brand" type="submit">Save favicon</button></div>
  </form>
</div>
HTML;
    admin_layout($base, 'favicon', 'Favicon', $body, $_SESSION['px_user'] ?? '');
}

function admin_render_appname(string $base, string $notice = ''): void
{
    $t = content_load()['titles'];
    $ok = admin_notice_html($notice);
    $head = admin_page_header($base, 'App name', 'The name used by the installable app (PWA manifest) and app headers.');
    $action = htmlspecialchars(admin_home_url($base) . '/appname', ENT_QUOTES);
    $csrf = htmlspecialchars(admin_csrf(), ENT_QUOTES);
    $name = htmlspecialchars((string) $t['app_name'], ENT_QUOTES);
    $body = <<<HTML
$ok$head
<div class="ax-card">
  <div class="ax-card__head"><div><h2>App name</h2><p class="ax-card__desc">Shown when the site is installed to a home screen.</p></div></div>
  <form method="post" action="$action">
  <input type="hidden" name="csrf" value="$csrf">
  <input type="hidden" name="action" value="save_appname">
  <div class="ax-card__body">
    <div class="ax-field"><label class="ax-label">App name</label><input class="ax-input" name="app_name" value="$name" placeholder="My Brand"></div>
  </div>
  <div class="ax-card__foot"><span class="ax-spacer"></span><button class="ax-btn ax-btn--brand" type="submit">Save app name</button></div>
  </form>
</div>
HTML;
    admin_layout($base, 'appname', 'App name', $body, $_SESSION['px_user'] ?? '');
}

function admin_render_users(string $base, string $notice = ''): void
{
    $users = users_load();
    $me = (string) ($_SESSION['px_user'] ?? '');
    $delAction = htmlspecialchars(admin_home_url($base) . '/users', ENT_QUOTES);
    $csrf = htmlspecialchars(admin_csrf(), ENT_QUOTES);

    $rows = '';
    foreach ($users as $u) {
        $name = (string) ($u['username'] ?? '');
        $role = (string) ($u['role'] ?? 'admin');
        $badge = $role === 'owner'
            ? '<span class="ax-badge ax-badge--brand"><span class="ax-badge__dot"></span>owner</span>'
            : '<span class="ax-badge">' . htmlspecialchars($role) . '</span>';
        $del = ($role === 'owner' || $name === $me)
            ? '<span class="ax-muted">—</span>'
            : '<form method="post" action="' . $delAction . '" data-ax-confirm="Delete this user?" style="display:inline">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '">'
                . '<input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="' . (int) $u['id'] . '">'
                . '<button class="ax-btn ax-btn--danger ax-btn--sm" type="submit">Delete</button></form>';
        $rows .= '<tr><td><strong>' . htmlspecialchars($name) . '</strong></td><td>' . $badge . '</td>'
            . '<td class="ax-muted">' . htmlspecialchars((string) ($u['created'] ?? '')) . '</td><td class="ax-c">' . $del . '</td></tr>';
    }

    $ok = admin_notice_html($notice);
    $head = admin_page_header($base, 'Users', 'Accounts that can sign in to this panel.');
    $action = htmlspecialchars(admin_home_url($base) . '/users', ENT_QUOTES);
    $body = <<<HTML
$ok$head
<div class="ax-card">
  <div class="ax-card__head"><div><h2>Admin users</h2><p class="ax-card__desc">The owner account and your own account cannot be deleted.</p></div></div>
  <div class="ax-card__body">
    <div class="ax-tablewrap" style="margin-top:0">
      <table class="ax-table">
        <thead><tr><th>Username</th><th>Role</th><th>Created</th><th class="ax-c"></th></tr></thead>
        <tbody>$rows</tbody>
      </table>
    </div>
  </div>
</div>

<div class="ax-row">
  <div class="ax-card" style="flex:1;min-width:300px">
    <div class="ax-card__head"><div><h2>Add user</h2><p class="ax-card__desc">Create another admin account.</p></div></div>
    <form method="post" action="$action">
    <input type="hidden" name="csrf" value="$csrf">
    <input type="hidden" name="action" value="add_user">
    <div class="ax-card__body">
      <div class="ax-field"><label class="ax-label">Username</label><input class="ax-input" name="username" required></div>
      <div class="ax-field"><label class="ax-label">Password</label><input class="ax-input" type="password" name="password" required></div>
      <div class="ax-field"><label class="ax-label">Role</label><select class="ax-select" name="role"><option value="admin">admin</option><option value="editor">editor</option></select></div>
    </div>
    <div class="ax-card__foot"><span class="ax-spacer"></span><button class="ax-btn ax-btn--brand" type="submit">Add user</button></div>
    </form>
  </div>

  <div class="ax-card" style="flex:1;min-width:300px">
    <div class="ax-card__head"><div><h2>Reset a password</h2><p class="ax-card__desc">Set a new password for an existing account.</p></div></div>
    <form method="post" action="$action">
    <input type="hidden" name="csrf" value="$csrf">
    <input type="hidden" name="action" value="reset_password">
    <div class="ax-card__body">
      <div class="ax-field"><label class="ax-label">Username</label><input class="ax-input" name="username" required></div>
      <div class="ax-field"><label class="ax-label">New password</label><input class="ax-input" type="password" name="password" required></div>
    </div>
    <div class="ax-card__foot"><span class="ax-spacer"></span><button class="ax-btn ax-btn--ghost" type="submit">Update password</button></div>
    </form>
  </div>
</div>
HTML;
    admin_layout($base, 'users', 'Users', $body, $me);
}

function admin_render_settings(string $base, string $notice = ''): void
{
    $app = config_load();
    $admin = $app['admin'] ?? [];
    $ok = admin_notice_html($notice);
    $head = admin_page_header($base, 'Settings', 'Core proxy behaviour. Saving writes config.php.');
    $action = htmlspecialchars(admin_home_url($base) . '/settings', ENT_QUOTES);
    $csrf = htmlspecialchars(admin_csrf(), ENT_QUOTES);
    $upstream = htmlspecialchars((string) ($app['upstream'] ?? ''), ENT_QUOTES);
    $brandFrom = htmlspecialchars((string) ($app['brand_from'] ?? ''), ENT_QUOTES);
    $brandTo = htmlspecialchars((string) ($app['brand_to'] ?? ''), ENT_QUOTES);
    $ua = htmlspecialchars((string) ($app['user_agent'] ?? ''), ENT_QUOTES);
    $ttl = (int) ($app['cache_ttl'] ?? 3600);
    $key = htmlspecialchars((string) ($admin['key'] ?? ''), ENT_QUOTES);
    $cookie = htmlspecialchars((string) ($admin['cookie'] ?? 'px_sid'), ENT_QUOTES);
    $body = <<<HTML
$ok$head
<form method="post" action="$action">
<input type="hidden" name="csrf" value="$csrf">
<input type="hidden" name="action" value="save_settings">

<div class="ax-card">
  <div class="ax-card__head"><div><h2>Connection</h2><p class="ax-card__desc">Where this proxy sends its upstream traffic, and how it caches responses.</p></div></div>
  <div class="ax-card__body">
    <div class="ax-field"><label class="ax-label">Upstream URL</label><input class="ax-input" name="upstream" value="$upstream"></div>
    <div class="ax-field"><label class="ax-label">User agent</label><input class="ax-input" name="user_agent" value="$ua"></div>
    <div class="ax-field" style="max-width:260px"><label class="ax-label">Cache TTL (seconds)</label><input class="ax-input" type="number" name="cache_ttl" min="0" value="$ttl"></div>
  </div>
</div>

<div class="ax-card">
  <div class="ax-card__head"><div><h2>Branding rewrite</h2><p class="ax-card__desc">Upstream brand strings are rewritten to your brand on the way out.</p></div></div>
  <div class="ax-card__body">
    <div class="ax-row">
      <div class="ax-field"><label class="ax-label">Brand from</label><input class="ax-input" name="brand_from" value="$brandFrom"></div>
      <div class="ax-field"><label class="ax-label">Brand to</label><input class="ax-input" name="brand_to" value="$brandTo"></div>
    </div>
  </div>
</div>

<div class="ax-card">
  <div class="ax-card__head"><div><h2>Access</h2><p class="ax-card__desc">The URL secret and session cookie name for this panel.</p></div></div>
  <div class="ax-card__body">
    <div class="ax-row">
      <div class="ax-field"><label class="ax-label">Admin key (URL secret)</label><input class="ax-input" name="admin_key" value="$key"></div>
      <div class="ax-field"><label class="ax-label">Session cookie</label><input class="ax-input" name="cookie" value="$cookie"></div>
    </div>
  </div>
  <div class="ax-card__foot"><span class="ax-spacer"></span><button class="ax-btn ax-btn--brand" type="submit">Save settings</button></div>
</div>
</form>
HTML;
    admin_layout($base, 'settings', 'Settings', $body, $_SESSION['px_user'] ?? '');
}

function admin_render_tools(string $base, string $notice = ''): void
{
    [$files, $bytes] = admin_dir_size(CACHE_DIR);
    $ok = admin_notice_html($notice);
    $head = admin_page_header($base, 'Tools', 'Maintenance actions for the proxy.');
    $action = htmlspecialchars(admin_home_url($base) . '/tools', ENT_QUOTES);
    $csrf = htmlspecialchars(admin_csrf(), ENT_QUOTES);
    $size = number_format($files) . ' cached files · ' . number_format($bytes / 1048576, 2) . ' MB';
    $body = <<<HTML
$ok$head
<div class="ax-card">
  <div class="ax-card__head"><div><h2>Cache</h2><p class="ax-card__desc" data-ax-cache>$size</p></div><span class="ax-badge ax-badge--brand" data-ax-cache-count>{$files} files</span></div>
  <form method="post" action="$action">
  <input type="hidden" name="csrf" value="$csrf">
  <input type="hidden" name="action" value="purge">
  <div class="ax-card__body">
    <p class="ax-hint" style="margin:0 0 4px">Purge removes every cached upstream response. The next visit rebuilds the cache from the upstream.</p>
  </div>
  <div class="ax-card__foot"><span class="ax-spacer"></span><button class="ax-btn ax-btn--danger" type="submit">Purge cache</button></div>
  </form>
</div>
HTML;
    admin_layout($base, 'tools', 'Tools', $body, $_SESSION['px_user'] ?? '');
}

/* -------------------- payments: payment methods -------------------- */

/* Shared payment-methods block, used by the merged Voucher & Payments page.
   Builds the methods/accounts/channels manager form. Returns HTML (no layout). */
function admin_payment_methods_block(string $formActionUrl, string $csrf): string
{
    $pmData = payment_methods_data_read();
    $methods = $pmData['methods'] ?? [];
    $action = htmlspecialchars($formActionUrl, ENT_QUOTES);
    $csrf = htmlspecialchars($csrf, ENT_QUOTES);
    $e = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    };

    /* One row inside a channel card: a wallet number plus its On/min/max for
       that channel. "On" is what puts the wallet on the channel. */
    $pairRow = function (string $keyE, int $ci, int $wi, string $number, string $label, bool $on, int $min, int $max) use ($e): string {
        $p = 'm[' . $keyE . '][channels][' . $ci . '][wallets][' . $wi . ']';
        return '<tr data-pair>'
            . '<td data-pair-wallet><span class="ax-wallet-tag">' . $e($number !== '' ? $number : '—') . '</span><small class="ax-muted">' . $e($label) . '</small></td>'
            . '<td class="ax-c"><label class="ax-check ax-check--bare"><input type="checkbox" data-pair-on name="' . $p . '[enabled]" value="1"' . ($on ? ' checked' : '') . '></label></td>'
            . '<td><input class="ax-input" type="number" name="' . $p . '[min]" value="' . $min . '"></td>'
            . '<td><input class="ax-input" type="number" name="' . $p . '[max]" value="' . $max . '"></td>'
            . '</tr>';
    };

    $panels = '';
    $tiles = '';
    foreach ($methods as $key => $m) {
        $keyE = $e($key);
        $keySlug = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) $key));
        $name = (string) ($m['name'] ?? $key);
        $enabled = !empty($m['enabled']);
        $color = (string) ($m['color'] ?? '');
        $logo = (string) ($m['logo'] ?? '');
        $accounts = is_array($m['accounts'] ?? null) ? $m['accounts'] : [];

        // Channels are the primary entity: the union of channel names across the
        // method's accounts, in first-seen order.
        $chNames = [];
        $wallets = [];
        foreach ($accounts as $ai => $acc) {
            $wallets[$ai] = [
                'number'  => (string) ($acc['number'] ?? ''),
                'name'    => (string) ($acc['name'] ?? ''),
                'enabled' => !empty($acc['enabled']),
            ];
            foreach ((array) ($acc['channels'] ?? []) as $ch) {
                $cname = trim((string) ($ch['name'] ?? ''));
                if ($cname !== '' && !array_key_exists($cname, $chNames)) {
                    $chNames[$cname] = true;
                }
            }
        }
        // pair values: channel name => wallet index => [on, min, max]
        $pairs = [];
        foreach (array_keys($chNames) as $cname) {
            $pairs[$cname] = [];
        }
        foreach ($accounts as $ai => $acc) {
            foreach ((array) ($acc['channels'] ?? []) as $ch) {
                $cname = trim((string) ($ch['name'] ?? ''));
                if ($cname === '' || !array_key_exists($cname, $pairs)) {
                    continue;
                }
                $pairs[$cname][$ai] = [
                    'on'  => !empty($ch['enabled']),
                    'min' => (int) ($ch['min'] ?? 100),
                    'max' => (int) ($ch['max'] ?? 30000),
                ];
            }
        }

        $walletRows = '';
        foreach ($wallets as $wi => $w) {
            $walletRows .= '<div class="ax-w" data-wallet-row>'
                . '<input class="ax-input ax-w__num" name="m[' . $keyE . '][wallets][' . $wi . '][number]" value="' . $e($w['number']) . '" placeholder="01XXXXXXXXX">'
                . '<input class="ax-input ax-w__name" name="m[' . $keyE . '][wallets][' . $wi . '][name]" value="' . $e($w['name']) . '" placeholder="Label">'
                . admin_switch('m[' . $keyE . '][wallets][' . $wi . '][enabled]', $w['enabled'])
                . '<button type="button" class="ax-iconbtn ax-w-del" title="Remove wallet" aria-label="Remove wallet">' . admin_icon('trash') . '</button>'
                . '</div>';
        }

        $chCards = '';
        $ci = 0;
        foreach (array_keys($chNames) as $cname) {
            $rowHtml = '';
            $onCount = 0;
            foreach ($wallets as $wi => $w) {
                $pv = $pairs[$cname][$wi] ?? ['on' => false, 'min' => 100, 'max' => 30000];
                if (!empty($pv['on'])) {
                    $onCount++;
                }
                $rowHtml .= $pairRow($keyE, $ci, $wi, $w['number'], $w['name'], !empty($pv['on']), (int) $pv['min'], (int) $pv['max']);
            }
            $chCards .= '<article class="ax-ch" data-ch>'
                . '<div class="ax-ch__head">'
                . '<input class="ax-input ax-ch__name" name="m[' . $keyE . '][channels][' . $ci . '][name]" value="' . $e($cname) . '" placeholder="Channel name">'
                . '<span class="ax-badge" data-ch-count>' . $onCount . ' on</span>'
                . '<button type="button" class="ax-iconbtn ax-ch-del" title="Remove channel" aria-label="Remove channel">' . admin_icon('trash') . '</button>'
                . '</div>'
                . '<table class="ax-table ax-table--compact"><thead><tr><th>Wallet</th><th class="ax-c">On</th><th>Min</th><th>Max</th></tr></thead><tbody>' . $rowHtml . '</tbody></table>'
                . '</article>';
            $ci++;
        }
        if ($chCards === '') {
            $chCards = '<p class="ax-ch__empty">No channels yet.</p>';
        }

        $logoTile = $logo !== ''
            ? '<img src="' . $e($logo) . '" alt="' . $e($name) . '">'
            : '<span>' . $e(strtoupper(substr($name !== '' ? $name : (string) $key, 0, 1))) . '</span>';
        $chCount = count($chNames);
        $wCount = count($wallets);
        $meta = $chCount . ' channel' . ($chCount === 1 ? '' : 's') . ' · ' . $wCount . ' wallet number' . ($wCount === 1 ? '' : 's');

        // Overview tile: every method shows its logo, name and a live count.
        $tiles .= '<button type="button" class="ax-mfs-tile" data-mfs-open="' . $keyE . '">'
            . '<span class="ax-mfs-tile__logo" data-tile-logo>' . $logoTile . '</span>'
            . '<span class="ax-mfs-tile__body">'
            . '<span class="ax-mfs-tile__row"><span class="ax-mfs-tile__name">' . $e($name) . '</span>'
            . '<span class="ax-badge ' . ($enabled ? 'ax-badge--ok' : 'ax-badge--warn') . '" data-tile-state><span class="ax-badge__dot"></span>' . ($enabled ? 'On' : 'Off') . '</span></span>'
            . '<span class="ax-mfs-tile__meta" data-tile-meta>' . $meta . '</span>'
            . '</span>'
            . '</button>';

        $panels .= '<section class="ax-card ax-mfs__panel" data-mfs="' . $keyE . '">'
            . '<div class="ax-mfs__head">'
            . '<span class="ax-mfs__logo" data-logo-tile>' . $logoTile . '</span>'
            . '<div class="ax-mfs__id"><h3>' . $e($name) . ' <code>' . $keyE . '</code></h3>'
            . '<p class="ax-card__desc" data-mfs-meta>' . $meta . '</p></div>'
            . admin_switch('m[' . $keyE . '][enabled]', $enabled, 'Enabled', 'On', 'Off')
            . '</div>'
            . '<div class="ax-card__body">'
            . '<div class="ax-mfs__grid">'
            . '<label class="ax-label">Name<input class="ax-input" name="m[' . $keyE . '][name]" value="' . $e($name) . '"></label>'
            . '<label class="ax-label">Color<input class="ax-input" name="m[' . $keyE . '][color]" value="' . $e($color) . '" placeholder="#E2136E"></label>'
            . '<label class="ax-label">Logo URL<input class="ax-input" name="m[' . $keyE . '][logo]" value="' . $e($logo) . '" placeholder="/images/brand/mfs_' . $e($keySlug) . '.png"></label>'
            . '<label class="ax-label">Upload<input class="ax-input" type="file" name="m[' . $keyE . '][logo_file]" accept="image/*,.ico,.svg" data-logo-input></label>'
            . '</div>'
            . '<p class="ax-hint">Shown on the voucher page · square PNG/SVG, max 3MB.</p>'
            . '</div>'
            . '<div class="ax-card__body ax-mfs__section">'
            . '<div class="ax-section-head"><h4>Channels</h4></div>'
            . '<div class="ax-channels" data-channels data-seq="' . $ci . '">' . $chCards . '</div>'
            . '<button type="button" class="ax-addbtn ax-ch-add">' . admin_icon('plus') . 'Add channel</button>'
            . '</div>'
            . '<div class="ax-card__body ax-mfs__section">'
            . '<div class="ax-section-head"><h4>Wallet numbers</h4></div>'
            . '<div class="ax-wallets" data-wallets>' . $walletRows . '</div>'
            . '<button type="button" class="ax-addbtn ax-w-add">' . admin_icon('plus') . 'Add wallet number</button>'
            . '</div>'
            . '</section>';
    }

    if ($methods === []) {
        $panels = '<div class="ax-card"><div class="ax-card__head"><div><h2>Payment methods</h2></div></div><div class="ax-card__body"><p class="ax-muted" style="margin:0">No payment methods are configured yet.</p></div></div>';
        $tiles = '';
    }

    // Two views, one form: the overview lists every MFS, and picking one swaps
    // the card below in place (no reload, so nothing typed is ever lost). All
    // panels stay in the form, so saving always carries every method.
    $enabledCount = 0;
    foreach ($methods as $mRow) {
        if (!empty($mRow['enabled'])) {
            $enabledCount++;
        }
    }
    $views = $panels;
    if ($tiles !== '') {
        $views = '<div class="ax-pm" data-pm-view="grid">'
            . '<div class="ax-pm-list" data-pm-list>'
            . '<div class="ax-pm-list__head"><h3>Payment methods</h3>'
            . '<span class="ax-hint">' . count($methods) . ' methods · ' . $enabledCount . ' on</span></div>'
            . '<div class="ax-mfs-tiles">' . $tiles . '</div>'
            . '</div>'
            . '<div data-pm-detail>'
            . '<div class="ax-detailbar"><button type="button" class="ax-backbtn" data-mfs-back>' . admin_icon('chevron') . 'Back</button>'
            . '<span class="ax-detailbar__crumb" data-mfs-crumb></span></div>'
            . $panels
            . '</div>'
            . '</div>';
    }

    $tpl = '<template id="ax-tpl-ch">'
        . '<article class="ax-ch" data-ch>'
        . '<div class="ax-ch__head">'
        . '<input class="ax-input ax-ch__name" name="m[__K__][channels][__CI__][name]" value="" placeholder="Channel name">'
        . '<span class="ax-badge" data-ch-count>0 on</span>'
        . '<button type="button" class="ax-iconbtn ax-ch-del" title="Remove channel" aria-label="Remove channel">' . admin_icon('trash') . '</button>'
        . '</div>'
        . '<table class="ax-table ax-table--compact"><thead><tr><th>Wallet</th><th class="ax-c">On</th><th>Min</th><th>Max</th></tr></thead><tbody></tbody></table>'
        . '</article></template>'
        . '<template id="ax-tpl-pair">'
        . '<tr data-pair>'
        . '<td data-pair-wallet><span class="ax-wallet-tag"></span><small class="ax-muted"></small></td>'
        . '<td class="ax-c"><label class="ax-check ax-check--bare"><input type="checkbox" data-pair-on name="m[__K__][channels][__CI__][wallets][__WI__][enabled]" value="1"></label></td>'
        . '<td><input class="ax-input" type="number" name="m[__K__][channels][__CI__][wallets][__WI__][min]" value="100"></td>'
        . '<td><input class="ax-input" type="number" name="m[__K__][channels][__CI__][wallets][__WI__][max]" value="30000"></td>'
        . '</tr></template>'
        . '<template id="ax-tpl-w">'
        . '<div class="ax-w" data-wallet-row>'
        . '<input class="ax-input ax-w__num" name="m[__K__][wallets][__WI__][number]" placeholder="01XXXXXXXXX">'
        . '<input class="ax-input ax-w__name" name="m[__K__][wallets][__WI__][name]" placeholder="Label">'
        . '<label class="ax-switch ax-switch--on"><input type="checkbox" name="m[__K__][wallets][__WI__][enabled]" value="1" checked><span class="ax-switch__track"></span></label>'
        . '<button type="button" class="ax-iconbtn ax-w-del" title="Remove wallet" aria-label="Remove wallet">' . admin_icon('trash') . '</button>'
        . '</div></template>';

    $script = '<script>(function(){'
        . 'var d=document;'
        . 'function qa(r,s){return Array.prototype.slice.call(r.querySelectorAll(s));}'
        . 'function cardOf(el){return el&&el.closest?el.closest("[data-mfs]"):null;}'
        . 'function tpl(id){var t=d.getElementById(id);return t?t.content.firstElementChild.cloneNode(true):null;}'
        . 'function fill(el,rep){var h=el.innerHTML;for(var k in rep){h=h.split(k).join(rep[k]);}el.innerHTML=h;return el;}'
        . 'function keyOf(card){return card?card.getAttribute("data-mfs"):"";}'
        . 'function tileOf(key){return key?d.querySelector("[data-mfs-open=\\""+key+"\\"]"):null;}'
        . 'function tileLogo(card,src){var tile=tileOf(keyOf(card));if(!tile)return;var box=tile.querySelector("[data-tile-logo]");if(!box)return;box.innerHTML="";if(!src)return;var img=d.createElement("img");img.alt="";img.src=src;box.appendChild(img);}'
        . 'function tileState(card){var key=keyOf(card),tile=tileOf(key);if(!tile)return;var b=tile.querySelector("[data-tile-state]");if(!b)return;var sw=card.querySelector("input[name=\\"m["+key+"][enabled]\\"]");var on=!!(sw&&sw.checked);b.className="ax-badge "+(on?"ax-badge--ok":"ax-badge--warn");b.innerHTML="<span class=\\"ax-badge__dot\\"></span>"+(on?"On":"Off");}'
        . 'function pmRoot(){return d.querySelector("[data-pm-view]");}'
        . 'function showMethodList(){var w=pmRoot();if(!w)return;w.setAttribute("data-pm-view","grid");qa(d,"[data-mfs]").forEach(function(c){c.classList.remove("is-active");});var top=w.getBoundingClientRect().top+window.pageYOffset-70;window.scrollTo(0,top>0?top:0);}'
        . 'function openMethod(key){var w=pmRoot();if(!w)return;w.setAttribute("data-pm-view","detail");var found=null;qa(d,"[data-mfs]").forEach(function(c){var on=c.getAttribute("data-mfs")===key;c.classList.toggle("is-active",on);if(on)found=c;});var crumb=d.querySelector("[data-mfs-crumb]");if(crumb&&found){var f=found.querySelector("input[name$=\\"[name]\\"]");crumb.textContent=(f&&f.value.trim())||key;}if(found){var top=found.getBoundingClientRect().top+window.pageYOffset-80;window.scrollTo(0,top>0?top:0);}}'
        . 'function wallets(card){return qa(card,"[data-wallets] [data-wallet-row]");}'
        . 'function channels(card){return qa(card,".ax-channels [data-ch]");}'
        . 'function info(row){var n=row.querySelector(".ax-w__num"),l=row.querySelector(".ax-w__name");return {num:n?n.value.trim():"",name:l?l.value.trim():""};}'
        . 'function paint(tr,w){var tag=tr.querySelector(".ax-wallet-tag"),sm=tr.querySelector("[data-pair-wallet] small");if(tag)tag.textContent=w.num||"—";if(sm)sm.textContent=w.name||"";}'
        . 'function counts(card){if(!card)return;qa(card,".ax-ch").forEach(function(ch){var on=qa(ch,"[data-pair-on]").filter(function(c){return c.checked;}).length;var b=ch.querySelector("[data-ch-count]");if(b)b.textContent=on+" on";});var c=channels(card).length,w=wallets(card).length,txt=c+" channel"+(c===1?"":"s")+" · "+w+" wallet number"+(w===1?"":"s");var meta=card.querySelector("[data-mfs-meta]");if(meta)meta.textContent=txt;var tile=tileOf(keyOf(card));if(tile){var tm=tile.querySelector("[data-tile-meta]");if(tm)tm.textContent=txt;tileState(card);}}'
        . 'function reindex(card){var key=keyOf(card);wallets(card).forEach(function(row,wi){qa(row,"input").forEach(function(inp){var kind=inp.name.indexOf("[number]")!==-1?"number":(inp.name.indexOf("[name]")!==-1?"name":"enabled");inp.name="m["+key+"][wallets]["+wi+"]["+kind+"]";});});channels(card).forEach(function(ch,ci){var nm=ch.querySelector(".ax-ch__name");if(nm)nm.name="m["+key+"][channels]["+ci+"][name]";qa(ch,"tbody [data-pair]").forEach(function(tr,wi){qa(tr,"input").forEach(function(inp){var kind=inp.type==="checkbox"?"enabled":(inp.name.indexOf("[min]")!==-1?"min":"max");inp.name="m["+key+"][channels]["+ci+"][wallets]["+wi+"]["+kind+"]";});});});}'
        . 'function addPair(card,ch,wi,w){var tr=tpl("ax-tpl-pair");if(!tr)return;tr.setAttribute("data-wi",wi);fill(tr,{__K__:keyOf(card),__CI__:"0",__WI__:String(wi)});paint(tr,w);var tb=ch.querySelector("tbody");if(tb)tb.appendChild(tr);}'
        . 'function addChannel(card){var holder=card.querySelector(".ax-channels");if(!holder)return;var ci=parseInt(holder.getAttribute("data-seq")||"0",10);holder.setAttribute("data-seq",ci+1);var empty=holder.querySelector(".ax-ch__empty");if(empty)empty.remove();var ch=tpl("ax-tpl-ch");if(!ch)return;fill(ch,{__K__:keyOf(card),__CI__:String(ci)});holder.appendChild(ch);wallets(card).forEach(function(row,wi){addPair(card,ch,wi,info(row));});reindex(card);counts(card);var nm=ch.querySelector(".ax-ch__name");if(nm)nm.focus();}'
        . 'function addWallet(card){var holder=card.querySelector("[data-wallets]");if(!holder)return;var w=tpl("ax-tpl-w");if(!w)return;holder.appendChild(w);var info2=info(w);channels(card).forEach(function(ch){addPair(card,ch,wallets(card).length-1,info2);});reindex(card);counts(card);var n=w.querySelector(".ax-w__num");if(n)n.focus();}'
        . 'function delWallet(card,row){var wi=wallets(card).indexOf(row);channels(card).forEach(function(ch){var tr=qa(ch,"tbody [data-pair]")[wi];if(tr)tr.remove();});row.remove();reindex(card);counts(card);}'
        . 'function syncLabels(card){var infos=wallets(card).map(info);channels(card).forEach(function(ch){qa(ch,"tbody [data-pair]").forEach(function(tr,wi){if(infos[wi])paint(tr,infos[wi]);});});}'
        . 'd.addEventListener("click",function(ev){var el=ev.target;if(!el||!el.closest)return;'
        . 'var op=el.closest("[data-mfs-open]");if(op){openMethod(op.getAttribute("data-mfs-open"));return;}'
        . 'var bk=el.closest("[data-mfs-back]");if(bk){showMethodList();return;}'
        . 'var a1=el.closest(".ax-ch-add");if(a1){addChannel(cardOf(a1));return;}'
        . 'var a2=el.closest(".ax-w-add");if(a2){addWallet(cardOf(a2));return;}'
        . 'var d1=el.closest(".ax-ch-del");if(d1){var ch=d1.closest(".ax-ch"),c1=cardOf(d1);if(ch)ch.remove();reindex(c1);counts(c1);return;}'
        . 'var d2=el.closest(".ax-w-del");if(d2){delWallet(cardOf(d2),d2.closest("[data-wallet-row]"));return;}'
        . '});'
        . 'd.addEventListener("input",function(ev){var t=ev.target;if(!t||!t.classList)return;if(t.classList.contains("ax-w__num")||t.classList.contains("ax-w__name")){syncLabels(cardOf(t));return;}if(t.name&&/\[logo\]$/.test(t.name)){var lc=cardOf(t);if(lc)tileLogo(lc,t.value.trim());}});'
        . 'd.addEventListener("change",function(ev){var t=ev.target;if(!t||!t.matches)return;if(t.matches("[data-pair-on]")){counts(cardOf(t));return;}if(/\[enabled\]$/.test(t.name||"")){var sc=cardOf(t);if(sc)tileState(sc);}});'
        . 'd.addEventListener("change",function(ev){var t=ev.target;if(t&&t.matches&&t.matches("[data-logo-input]")&&t.files&&t.files[0]){var card=cardOf(t),src=URL.createObjectURL(t.files[0]);if(!card)return;var tile=card.querySelector("[data-logo-tile]");if(tile){tile.innerHTML="";var img=d.createElement("img");img.alt="";img.src=src;tile.appendChild(img);}tileLogo(card,src);}});'
        . 'qa(d,"[data-mfs]").forEach(function(card){reindex(card);counts(card);});'
        . '})();</script>';

    return '<form method="post" action="' . $action . '" enctype="multipart/form-data">'
        . '<input type="hidden" name="csrf" value="' . $csrf . '">'
        . '<input type="hidden" name="action" value="save_payment_methods">'
        . $views . $tpl
        . '<div class="ax-savebar"><span class="ax-spacer"></span>'
        . '<button class="ax-btn ax-btn--brand" type="submit">Save</button></div>'
        . '</form>' . $script;
}

function admin_render_payment_methods(string $base, string $notice = ''): void
{
    $ok = admin_notice_html($notice);
    $head = admin_page_header($base, 'Payment Methods', 'Enable methods and manage their accounts, channels and limits.');
    $body = $ok . $head . admin_payment_methods_block(admin_home_url($base) . '/payment_methods', admin_csrf());
    admin_layout($base, 'payment_methods', 'Payment Methods', $body, $_SESSION['px_user'] ?? '');
}

/* -------------------- payments: settings -------------------- */

/** Shared payment-settings form. It is the Payment Settings route itself and
    also sits at the end of the Voucher & Payments page; field names unchanged. */
function admin_payment_settings_block(string $formActionUrl, string $csrf, string $title = 'Payment settings'): string
{
    $s = payment_settings_read();
    $e = function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES);
    };
    $action = htmlspecialchars($formActionUrl, ENT_QUOTES);
    $csrfE = htmlspecialchars($csrf, ENT_QUOTES);
    $langBn = ($s['language'] ?? '') === 'bn' ? ' selected' : '';
    $langEn = ($s['language'] ?? '') === 'en' ? ' selected' : '';
    $testOff = empty($s['isTest']) ? ' selected' : '';
    $testOn = !empty($s['isTest']) ? ' selected' : '';

    return '<div class="ax-card">'
        . '<div class="ax-card__head"><div><h2>' . $e($title) . '</h2></div></div>'
        . '<form method="post" action="' . $action . '">'
        . '<input type="hidden" name="csrf" value="' . $csrfE . '">'
        . '<input type="hidden" name="action" value="save_payment_settings">'
        . '<div class="ax-card__body"><div class="ax-row">'
        . '<div class="ax-field"><label class="ax-label">Platform name</label><input class="ax-input" name="platformName" value="' . $e($s['platformName'] ?? 'VoucherCenter') . '"></div>'
        . '<div class="ax-field"><label class="ax-label">Brand name</label><input class="ax-input" name="brandName" value="' . $e($s['brandName'] ?? '') . '"></div>'
        . '<div class="ax-field"><label class="ax-label">Currency</label><input class="ax-input" name="currency" value="' . $e($s['currency'] ?? 'BDT') . '"></div>'
        . '<div class="ax-field"><label class="ax-label">Symbol</label><input class="ax-input" name="currencySymbol" value="' . $e($s['currencySymbol'] ?? '') . '"></div>'
        . '<div class="ax-field"><label class="ax-label">Time zone</label><input class="ax-input" type="number" name="timeZone" value="' . (int) ($s['timeZone'] ?? 6) . '"></div>'
        . '<div class="ax-field"><label class="ax-label">Language</label><select class="ax-select" name="language"><option value="bn"' . $langBn . '>Bengali</option><option value="en"' . $langEn . '>English</option></select></div>'
        . '<div class="ax-field"><label class="ax-label">Test mode</label><select class="ax-select" name="isTest"><option value="0"' . $testOff . '>Off (Live)</option><option value="1"' . $testOn . '>On (Test)</option></select></div>'
        . '</div></div>'
        . '<div class="ax-card__foot"><span class="ax-spacer"></span><button class="ax-btn ax-btn--brand" type="submit">Save</button></div>'
        . '</form>'
        . '</div>';
}

function admin_render_payment_settings(string $base, string $notice = ''): void
{
    $ok = admin_notice_html($notice);
    $head = admin_page_header($base, 'Payment Settings');
    $body = $ok . $head . admin_payment_settings_block(admin_home_url($base) . '/payment_settings', admin_csrf());
    admin_layout($base, 'payment_settings', 'Payment Settings', $body, $_SESSION['px_user'] ?? '');
}

/* -------------------- payments: withdrawals -------------------- */

function admin_render_withdrawals(string $base, string $notice = ''): void
{
    $ok = admin_notice_html($notice);
    $items = withdraw_approvals_read();
    usort($items, function ($a, $b) {
        return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
    });

    $action = htmlspecialchars(admin_home_url($base) . '/withdrawals', ENT_QUOTES);
    $csrf = htmlspecialchars(admin_csrf(), ENT_QUOTES);
    $e = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    };

    $badgeFor = function (string $st): string {
        if ($st === 'Approved') {
            return '<span class="ax-badge ax-badge--ok"><span class="ax-badge__dot"></span>Approved</span>';
        }
        if ($st === 'Rejected') {
            return '<span class="ax-badge ax-badge--danger"><span class="ax-badge__dot"></span>Rejected</span>';
        }
        return '<span class="ax-badge ax-badge--warn"><span class="ax-badge__dot"></span>' . htmlspecialchars($st) . '</span>';
    };

    $pending = 0;
    $rows = '';
    foreach ($items as $it) {
        $id = (int) ($it['id'] ?? 0);
        $st = (string) ($it['status'] ?? 'Pending');
        if ($st === 'Pending') {
            $pending++;
        }
        $amt = number_format((float) ($it['amount'] ?? 0), 2);
        $hint = trim((string) ($it['cardHint'] ?? ''));
        $sess = substr((string) ($it['sessionKey'] ?? ''), 0, 10) . '…';
        $created = (string) ($it['createdAt'] ?? '');
        $consumed = !empty($it['consumedAt']) ? '<div class="ax-hint">used ' . $e((string) $it['consumedAt']) . '</div>' : '';
        $ops = $st === 'Pending'
            ? '<form method="post" action="' . $action . '" style="display:inline-flex;gap:8px;flex-wrap:wrap">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '">'
                . '<input type="hidden" name="action" value="withdraw_decide">'
                . '<input type="hidden" name="id" value="' . $id . '">'
                . '<button class="ax-btn ax-btn--sm" type="submit" name="decision" value="Approved">Approve</button>'
                . '<button class="ax-btn ax-btn--danger ax-btn--sm" type="submit" name="decision" value="Rejected" data-ax-confirm="Reject this withdraw?">Reject</button></form>'
            : '<span class="ax-muted">—</span>';
        $rows .= '<tr><td><strong>#' . $id . '</strong></td><td>' . $amt . '</td><td>' . $e($hint) . '</td>'
            . '<td><code>' . $e($sess) . '</code></td><td data-ax-status>' . $badgeFor($st) . $consumed . '</td>'
            . '<td class="ax-muted">' . $e($created) . '</td><td data-ax-ops>' . $ops . '</td></tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="7" class="ax-muted ax-c">No withdraw requests yet.</td></tr>';
    }

    $pill = $pending > 0 ? '<span class="ax-badge ax-badge--warn"><span class="ax-badge__dot"></span>' . $pending . ' pending</span>' : '<span class="ax-badge ax-badge--ok"><span class="ax-badge__dot"></span>All clear</span>';
    $head = admin_page_header($base, 'Withdrawals', 'The first withdraw POST is held as Pending. Approve once — the user then retries on /m/withdraw and that single retry passes to upstream. Reject restores the visible balance.');
    $body = <<<HTML
$ok$head
<div class="ax-card">
  <div class="ax-card__head"><div><h2>Withdraw approvals</h2><p class="ax-card__desc">Upstream is not debited while a request is Pending.</p></div>$pill</div>
  <div class="ax-card__body">
    <div class="ax-tablewrap" style="margin-top:0">
      <table class="ax-table">
        <thead><tr><th>ID</th><th>Amount</th><th>Card</th><th>Session</th><th>Status</th><th>Created</th><th></th></tr></thead>
        <tbody>$rows</tbody>
      </table>
    </div>
  </div>
</div>
HTML;
    admin_layout($base, 'withdrawals', 'Withdrawals', $body, $_SESSION['px_user'] ?? '');
}

/* --------------------------- games ---------------------------
   Everything about games in one screen: the master switch, the language a game
   opens in, the in-app window (which is what puts a Back button on every game
   page), how many games a page holds, which category tabs and which vendors are
   offered, and which individual games stay hidden. */

/** Merchant code: proxy config first, then the frontend config.js. */
function admin_games_merchant(): string
{
    $cfg = config_load();
    $m = trim((string) ($cfg['merchant'] ?? ''));
    if ($m !== '') {
        return $m;
    }
    $js = dirname(dirname(__DIR__)) . '/mobile/assets/config.js';
    if (is_file($js)) {
        $src = (string) @file_get_contents($js);
        if (preg_match("/PX_MERCHANT\\s*=\\s*['\"]([A-Za-z0-9_]+)['\"]/", $src, $mm)) {
            return $mm[1];
        }
    }
    return '';
}

/**
 * Live vendor list from the game relay, so the toggles describe the catalogue
 * that actually exists instead of a list typed in by hand. Cached briefly; when
 * the relay cannot be reached the last good copy is kept, and codes already
 * saved in the config are always merged in so a toggle can never vanish.
 */
function admin_games_vendor_list(): array
{
    $cache = data_dir() . '/games-vendors.json';
    $c = store_read($cache);
    $cached = (is_array($c['list'] ?? null) ? $c['list'] : []);
    if (!empty($c['at']) && (time() - (int) $c['at']) < 600 && $cached) {
        return $cached;
    }

    $merchant = admin_games_merchant();
    $upstream = defined('UPSTREAM') ? (string) UPSTREAM : '';
    $list = [];
    if ($merchant !== '' && $upstream !== '' && function_exists('curl_init')) {
        $ch = curl_init($upstream . '/wps/relay/GCSGAME_newGameVendor?platform=html5&clientType=2&language=en');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Merchant: ' . $merchant, 'Device: 2', 'Language: en'],
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (BBC99 admin)',
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw !== false && $code >= 200 && $code < 400) {
            $j = json_decode((string) $raw, true);
            $lanes = $j['data']['lanes'] ?? ($j['value']['data']['lanes'] ?? []);
            foreach ((array) $lanes as $lane) {
                $type = trim((string) ($lane['title'] ?? $lane['gameType'] ?? ''));
                foreach ((array) ($lane['cards'] ?? []) as $card) {
                    $vcode = trim((string) ($card['vassalage'] ?? ($card['accountTypeName'] ?? '')));
                    if ($vcode === '') {
                        continue;
                    }
                    $vname = trim((string) ($card['displayName'] ?? ($card['title'] ?? '')));
                    $list[$vcode] = ['code' => $vcode, 'name' => $vname !== '' ? $vname : $vcode, 'type' => $type];
                }
            }
        }
    }

    $list = array_values($list);
    if ($list) {
        usort($list, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        store_write($cache, ['at' => time(), 'list' => $list]);
        return $list;
    }
    return $cached;
}

function admin_render_games(string $base, string $notice = ''): void
{
    $g = games_config();
    $action = admin_home_url($base) . '/games';
    $csrf = admin_csrf();
    $e = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES);
    };

    // --- language options ---------------------------------------------------
    $langOpts = '';
    foreach (games_languages() as $code => $label) {
        $langOpts .= '<option value="' . $e($code) . '"' . ($g['language'] === $code ? ' selected' : '') . '>'
            . $e($label) . ' (' . $e($code) . ')</option>';
    }

    // --- category tabs ------------------------------------------------------
    $typeRows = '';
    foreach (games_type_defaults() as $code => $label) {
        $on = array_key_exists($code, $g['types']) ? !empty($g['types'][$code]) : true;
        $typeRows .= '<label class="ax-check"><input type="checkbox" name="types[' . $e($code) . ']" value="1"'
            . ($on ? ' checked' : '') . '><span>' . $e($label) . '</span><code class="ax-muted">' . $e($code) . '</code></label>';
    }

    // --- vendors (live list + anything already saved) -----------------------
    $vendors = admin_games_vendor_list();
    $known = [];
    foreach ($vendors as $v) {
        $known[(string) $v['code']] = (string) $v['name'];
    }
    foreach (array_keys($g['vendors']) as $code) {
        if (!isset($known[$code])) {
            $known[$code] = $code;
        }
    }
    ksort($known);
    // A browser only posts CHECKED boxes, so "switched off" would be
    // indistinguishable from "never rendered" and the code would come back on.
    // The full list travels alongside the boxes and the save treats a missing
    // box as off.
    $vendorHidden = '';
    foreach (array_keys($known) as $code) {
        $vendorHidden .= '<input type="hidden" name="all_vendors[]" value="' . $e($code) . '">';
    }
    $vendorRows = '';
    foreach ($known as $code => $vname) {
        $on = array_key_exists($code, $g['vendors']) ? !empty($g['vendors'][$code]) : true;
        $vendorRows .= '<label class="ax-check"><input type="checkbox" name="vendors[' . $e($code) . ']" value="1"'
            . ($on ? ' checked' : '') . '><span>' . $e($vname) . '</span><code class="ax-muted">' . $e($code) . '</code></label>';
    }
    if (!$known) {
        $vendorRows = '<p class="ax-muted" style="margin:0">Vendor list unavailable right now — save once the relay is reachable, or type codes below.</p>';
    }

    $hiddenText = $e(implode("\n", array_map('strval', (array) $g['hidden'])));
    $ok = admin_notice_html($notice);
    $head = admin_page_header($base, 'Games', 'Control the home-page game grid, how games open, and which categories, vendors and individual games are offered.');

    $switchEnabled = admin_switch('enabled', !empty($g['enabled']), 'Show games on the home page');
    $switchInApp = admin_switch('in_app', !empty($g['in_app']), 'Open games in the in-app window');
    $pageSize = (int) $g['page_size'];
    $backUrl = $e($g['back_url']);
    $vendorCount = count($known);

    $body = <<<HTML
$ok$head
<form method="post" action="$action">
<input type="hidden" name="csrf" value="$csrf">
<input type="hidden" name="action" value="save_games">

<div class="ax-card">
  <div class="ax-card__head"><div><h2>Games on the home page</h2><p class="ax-card__desc">Turning this off removes the game grid, the category tabs and the search from the custom home page; the rest of the page is unaffected.</p></div></div>
  <div class="ax-card__body">
    $switchEnabled
    <div class="ax-field" style="max-width:260px"><label class="ax-label">Games per page</label><input class="ax-input" type="number" name="page_size" min="4" max="120" step="4" value="$pageSize"></div>
    <p class="ax-hint">How many games the grid loads per request — “see more” adds another batch.</p>
  </div>
</div>

<div class="ax-card">
  <div class="ax-card__head"><div><h2>How a game opens</h2><p class="ax-card__desc">The in-app window keeps the game inside the site and gives every game page a Back button that returns to the address below — vendors that do not offer their own back control included.</p></div></div>
  <div class="ax-card__body">
    $switchInApp
    <div class="ax-field"><label class="ax-label">Back button returns to</label><input class="ax-input" type="text" name="back_url" value="$backUrl" placeholder="/m/home" style="max-width:340px"></div>
    <p class="ax-hint">A path on this site, e.g. <code>/m/home</code>. Anything else falls back to <code>/m/home</code>.</p>
  </div>
</div>

<div class="ax-card">
  <div class="ax-card__head"><div><h2>Default game language</h2><p class="ax-card__desc">Sent to the vendor on every launch. Left unset, most vendors open the game in Chinese, so this is normally English.</p></div></div>
  <div class="ax-card__body">
    <div class="ax-field" style="max-width:320px"><label class="ax-label">Language</label><select class="ax-select" name="language">$langOpts</select></div>
  </div>
</div>

<div class="ax-card">
  <div class="ax-card__head"><div><h2>Category tabs</h2><p class="ax-card__desc">Which categories the home page offers. “গরম” (all games) always stays.</p></div></div>
  <div class="ax-card__body">
    <div class="ax-togglelist">$typeRows</div>
  </div>
</div>

<div class="ax-card">
  <div class="ax-card__head"><div><h2>Vendors</h2><p class="ax-card__desc">Games from a vendor switched off here are removed from the home grid and from the provider filter.</p></div><span class="ax-badge">$vendorCount known</span></div>
  <div class="ax-card__body">
    $vendorHidden
    <div class="ax-togglelist">$vendorRows</div>
    <div class="ax-field"><label class="ax-label">Additional vendor codes (one per line)</label><textarea class="ax-textarea" name="extra_vendors" placeholder="One vendor code per line"></textarea></div>
    <p class="ax-hint">Codes typed here are added to the list above as switched on, so they can be toggled later even when the relay is unreachable.</p>
  </div>
</div>

<div class="ax-card">
  <div class="ax-card__head"><div><h2>Hidden games</h2><p class="ax-card__desc">One game node ID per line. Those games are filtered out of the grid and search.</p></div></div>
  <div class="ax-card__body">
    <textarea class="ax-textarea" name="hidden" placeholder="One game node ID per line">$hiddenText</textarea>
  </div>
  <div class="ax-card__foot"><span class="ax-spacer"></span><button class="ax-btn ax-btn--brand" type="submit">Save games settings</button></div>
</div>
</form>
HTML;

    admin_layout($base, 'games', 'Games', $body, $_SESSION['px_user'] ?? '');
}

/* --------------------------- router --------------------------- */

function handleAdmin(string $base, string $sub, array $cfg): void
{
    header('X-Test-Panel: 1');
    admin_boot((string) ($cfg['cookie'] ?? 'px_sid'));
    users_seed($cfg);

    if (admin_key_match($cfg)) {
        $_SESSION['px_key'] = true;
    }
    $sub = '/' . trim($sub, '/');
    $home = admin_home_url($base);
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $notice = '';

    if ($sub === '/logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        header('Location: ' . $home . '/login', true, 302);
        exit;
    }

    if ($sub === '/login') {
        if ($method === 'POST') {
            $_SESSION['px_tries'] = ($_SESSION['px_tries'] ?? 0) + 1;
            $user = (string) ($_POST['username'] ?? '');
            $pass = (string) ($_POST['password'] ?? '');
            $found = admin_csrf_ok() && $_SESSION['px_tries'] <= 10 ? user_verify(users_load(), $user, $pass) : null;
            if ($found) {
                session_regenerate_id(true);
                $_SESSION['px_admin'] = true;
                $_SESSION['px_key'] = true;
                $_SESSION['px_user'] = (string) $found['username'];
                $_SESSION['px_role'] = (string) ($found['role'] ?? 'admin');
                unset($_SESSION['px_tries']);
                header('Location: ' . $home, true, 302);
                exit;
            }
            usleep(400000);
            admin_render_login($base, 'Invalid credentials.');
            exit;
        }
        if (admin_authed()) {
            header('Location: ' . $home, true, 302);
            exit;
        }
        admin_render_login($base, '');
        exit;
    }

    if (!admin_unlocked($cfg)) {
        admin_redirect_home($base);
    }

    if (!admin_authed()) {
        admin_render_login($base, '');
        exit;
    }

    // ----- authenticated area: POST actions -----
    $postAction = (string) ($_POST['action'] ?? '');

    if ($method === 'POST' && admin_csrf_ok()) {
        switch ($postAction) {
            case 'save_banners':
                $list = [];
                foreach ((array) ($_POST['b'] ?? []) as $row) {
                    if (!empty($row['remove']) || trim((string) ($row['image'] ?? '')) === '') {
                        continue;
                    }
                    $list[] = [
                        'image'  => trim((string) $row['image']),
                        'link'   => trim((string) ($row['link'] ?? '')),
                        'title'  => trim((string) ($row['title'] ?? '')),
                        'active' => !empty($row['active']),
                    ];
                }
                $c = content_load();
                $c['banners'] = $list;
                content_save($c);
                $notice = 'Banners saved.';
                break;

            case 'save_marquee':
                $items = [];
                foreach ((array) ($_POST['m'] ?? []) as $row) {
                    if (!empty($row['remove'])) {
                        continue;
                    }
                    $t = trim((string) ($row['text'] ?? ''));
                    if ($t === '') {
                        continue;
                    }
                    $items[] = ['text' => $t, 'link' => trim((string) ($row['link'] ?? ''))];
                }
                $c = content_load();
                $c['marquee'] = [
                    'enabled' => ($_POST['enabled'] ?? '0') === '1',
                    'items'   => $items,
                    'text'    => $items[0]['text'] ?? '',
                    'link'    => $items[0]['link'] ?? '',
                    'bg'      => trim((string) ($_POST['bg'] ?? '#111827')),
                    'color'   => trim((string) ($_POST['color'] ?? '#ffffff')),
                    'speed'   => max(20, min(800, (int) ($_POST['speed'] ?? 160))),
                ];
                content_save($c);
                $notice = 'Marquee saved.';
                break;

            case 'save_games':
                // Category tabs: saved as a complete map so a tab can never be
                // silently re-enabled (or dropped) by a partial post.
                $types = [];
                $tIn = (array) ($_POST['types'] ?? []);
                foreach (games_type_defaults() as $code => $label) {
                    $types[$code] = !empty($tIn[$code]);
                }

                // Vendors: every code that was offered, plus any typed in. A code
                // with no matching checked box is switched OFF - which is why the
                // full list is posted separately (see the Games render).
                $offered = [];
                foreach ((array) ($_POST['all_vendors'] ?? []) as $code) {
                    $code = trim((string) $code);
                    if ($code !== '') {
                        $offered[$code] = true;
                    }
                }
                foreach (preg_split('/[\s,]+/', (string) ($_POST['extra_vendors'] ?? '')) as $code) {
                    $code = trim($code);
                    if ($code !== '') {
                        $offered[$code] = true;
                    }
                }
                $checked = (array) ($_POST['vendors'] ?? []);
                $vendors = [];
                foreach (array_keys($offered) as $code) {
                    $vendors[$code] = array_key_exists($code, $checked);
                }

                $hidden = [];
                foreach (preg_split('/[\s,]+/', (string) ($_POST['hidden'] ?? '')) as $id) {
                    $id = trim($id);
                    if ($id !== '') {
                        $hidden[] = $id;
                    }
                }

                $back = trim((string) ($_POST['back_url'] ?? ''));
                if ($back === '' || $back[0] !== '/') {
                    $back = '/m/home';
                }
                $lang = (string) ($_POST['language'] ?? 'EN');
                if (!isset(games_languages()[$lang])) {
                    $lang = 'EN';
                }

                $c = content_load();
                $c['games'] = [
                    'enabled'   => !empty($_POST['enabled']),
                    'language'  => $lang,
                    'in_app'    => !empty($_POST['in_app']),
                    'back_url'  => $back,
                    'page_size' => max(4, min(120, (int) ($_POST['page_size'] ?? 24))),
                    'types'     => $types,
                    'vendors'   => $vendors,
                    'hidden'    => array_values(array_unique($hidden)),
                    'updated'   => date('c'),
                ];
                content_save($c);
                $notice = 'Games settings saved.';
                break;

            case 'save_voucher':
                $src = trim((string) ($_POST['path'] ?? ''));
                if ($src === '') {
                    $src = '/m/voucherCenter';
                }
                if ($src[0] !== '/') {
                    $src = '/' . $src;
                }

                $c = content_load();
                $oldVoucher = is_array($c['voucher'] ?? null) ? $c['voucher'] : voucher_defaults();
                $defaults = voucher_defaults();

                // Full method/channel form is no longer rendered; only rebuild
                // methods when those fields are actually posted, otherwise keep
                // the stored config so saving redirect never wipes it.
                if (array_key_exists('m', $_POST) || array_key_exists('mc', $_POST)) {
                    $methods = [];
                    $mIn  = (array) ($_POST['m'] ?? []);
                    $mcIn = (array) ($_POST['mc'] ?? []);
                    foreach (voucher_method_defaults() as $key => $def) {
                        $row = is_array($mIn[$key] ?? null) ? $mIn[$key] : [];
                        $channels = [];
                        foreach ((array) ($mcIn[$key] ?? []) as $crow) {
                            if (!empty($crow['remove'])) {
                                continue;
                            }
                            $label = trim((string) ($crow['label'] ?? ''));
                            if ($label === '') {
                                continue;
                            }
                            $channels[] = ['label' => $label, 'enabled' => !empty($crow['enabled'])];
                        }
                        if (!$channels) {
                            $channels = voucher_channel_defaults();
                        }
                        $methods[$key] = [
                            'name'     => trim((string) ($row['name'] ?? $def['name'])) ?: $def['name'],
                            'image'    => trim((string) ($row['image'] ?? '')),
                            'enabled'  => !empty($row['enabled']),
                            'min'      => max(0, (int) ($row['min'] ?? 0)),
                            'max'      => max(0, (int) ($row['max'] ?? 0)),
                            'channels' => $channels,
                        ];
                    }
                } else {
                    $methods = $oldVoucher['methods'] ?? $defaults['methods'];
                }

                if (array_key_exists('amounts', $_POST)) {
                    $amounts = [];
                    foreach (preg_split('/[\s,]+/', (string) ($_POST['amounts'] ?? '')) as $a) {
                        $n = (int) preg_replace('/[^\d]/', '', $a);
                        if ($n > 0) {
                            $amounts[] = $n;
                        }
                    }
                    $amounts = array_values(array_unique($amounts));
                    if (!$amounts) {
                        $amounts = $defaults['amounts'];
                    }
                } else {
                    $amounts = $oldVoucher['amounts'] ?? $defaults['amounts'];
                }

                $logo = array_key_exists('logo', $_POST)
                    ? trim((string) ($_POST['logo'] ?? ''))
                    : trim((string) ($oldVoucher['logo'] ?? ''));

                $c['voucher'] = [
                    'enabled'      => ($_POST['enabled'] ?? '0') === '1',
                    'path'         => $src,
                    'redirect_url' => trim((string) ($_POST['redirect_url'] ?? '')),
                    'logo'         => $logo,
                    'amounts'      => $amounts,
                    'methods'      => $methods,
                ];
                content_save($c);
                $notice = 'Voucher Center settings saved.';
                break;

            case 'save_titles':
                $c = content_load();
                $c['titles']['web_title'] = trim((string) ($_POST['web_title'] ?? ''));
                $c['titles']['mobile_title'] = trim((string) ($_POST['mobile_title'] ?? ''));
                content_save($c);
                $notice = 'Titles saved.';
                break;

            case 'save_logo':
                $c = content_load();
                $newUrl = null;
                if (!empty($_FILES['file']['tmp_name'])) {
                    $saved = brand_asset_save_upload($_FILES['file'], 'logo');
                    if ($saved !== false) {
                        $newUrl = $saved;
                    } else {
                        $GLOBALS['admin_error'] = 'Logo upload failed (use png/jpg/webp/svg under 3MB).';
                    }
                }
                if ($newUrl === null) {
                    $pasted = trim((string) ($_POST['url'] ?? ''));
                    if ($pasted === '') {
                        // blank + no file = keep upstream original only if no local file yet,
                        // otherwise keep current local file.
                        $cur = trim((string) ($c['logo']['url'] ?? ''));
                        $newUrl = $cur !== '' ? $cur : '';
                        if ($pasted === '' && empty($_FILES['file']['tmp_name']) && $cur === '') {
                            $newUrl = '';
                        }
                        // explicit clear: if user erased a remote URL to blank, clear it.
                        if ($pasted === '' && strpos($cur, 'http') === 0) {
                            $newUrl = '';
                        }
                    } elseif (preg_match('#^https?://#i', $pasted)) {
                        $saved = brand_asset_save_url($pasted, 'logo');
                        if ($saved !== false) {
                            $newUrl = $saved;
                        } else {
                            $GLOBALS['admin_error'] = 'Could not download that logo URL — saved the URL as-is.';
                            $newUrl = $pasted;
                        }
                    } else {
                        $newUrl = preg_replace('/\?.*$/', '', $pasted);
                    }
                }
                if ($newUrl !== null) {
                    $c['logo']['url'] = $newUrl;
                }
                $c['logo']['width'] = max(0, min(2000, (int) ($_POST['width'] ?? 0)));
                content_save($c);
                $notice = empty($GLOBALS['admin_error']) ? 'Logo saved locally.' : '';
                break;

            case 'save_appname':
                $c = content_load();
                $c['titles']['app_name'] = trim((string) ($_POST['app_name'] ?? ''));
                content_save($c);
                $notice = 'App name saved.';
                break;

            case 'save_favicon':
                $c = content_load();
                $newFav = null;
                if (!empty($_FILES['file']['tmp_name'])) {
                    $saved = brand_asset_save_upload($_FILES['file'], 'favicon');
                    if ($saved !== false) {
                        $newFav = $saved;
                    } else {
                        $GLOBALS['admin_error'] = 'Favicon upload failed (use png/ico/svg under 3MB).';
                    }
                }
                if ($newFav === null) {
                    $pasted = trim((string) ($_POST['url'] ?? ''));
                    if ($pasted === '') {
                        $cur = trim((string) ($c['favicon']['url'] ?? ''));
                        $newFav = $cur !== '' ? $cur : '';
                        if ($pasted === '' && strpos($cur, 'http') === 0) {
                            $newFav = '';
                        }
                    } elseif (preg_match('#^https?://#i', $pasted)) {
                        $saved = brand_asset_save_url($pasted, 'favicon');
                        if ($saved !== false) {
                            $newFav = $saved;
                        } else {
                            $GLOBALS['admin_error'] = 'Could not download that favicon URL — saved the URL as-is.';
                            $newFav = $pasted;
                        }
                    } else {
                        $newFav = preg_replace('/\?.*$/', '', $pasted);
                    }
                }
                if ($newFav !== null) {
                    $c['favicon']['url'] = $newFav;
                }
                content_save($c);
                $notice = empty($GLOBALS['admin_error']) ? 'Favicon saved locally.' : '';
                break;

            case 'add_user':
                $users = users_load();
                $name = trim((string) ($_POST['username'] ?? ''));
                $pass = (string) ($_POST['password'] ?? '');
                $role = in_array(($_POST['role'] ?? 'admin'), ['admin', 'editor'], true) ? $_POST['role'] : 'admin';
                if ($name === '' || strlen($pass) < 6) {
                    $notice = '';
                    $GLOBALS['admin_error'] = 'Username required and password must be at least 6 characters.';
                } else {
                    $exists = false;
                    foreach ($users as $u) {
                        if (strcasecmp((string) $u['username'], $name) === 0) {
                            $exists = true;
                        }
                    }
                    if ($exists) {
                        $GLOBALS['admin_error'] = 'That username already exists.';
                    } else {
                        $users[] = ['id' => users_next_id($users), 'username' => $name, 'pass_hash' => password_hash($pass, PASSWORD_DEFAULT), 'role' => $role, 'created' => date('c')];
                        users_save($users);
                        $notice = 'User "' . $name . '" added.';
                    }
                }
                break;

            case 'reset_password':
                $users = users_load();
                $name = trim((string) ($_POST['username'] ?? ''));
                $pass = (string) ($_POST['password'] ?? '');
                $done = false;
                if (strlen($pass) >= 6) {
                    foreach ($users as &$u) {
                        if (strcasecmp((string) $u['username'], $name) === 0) {
                            $u['pass_hash'] = password_hash($pass, PASSWORD_DEFAULT);
                            $done = true;
                        }
                    }
                    unset($u);
                }
                if ($done) {
                    users_save($users);
                    $notice = 'Password updated for "' . $name . '".';
                } else {
                    $GLOBALS['admin_error'] = 'User not found or password too short (min 6).';
                }
                break;

            case 'delete_user':
                $users = users_load();
                $id = (int) ($_POST['id'] ?? 0);
                $kept = [];
                foreach ($users as $u) {
                    $isOwner = ($u['role'] ?? '') === 'owner';
                    if ((int) $u['id'] === $id && !$isOwner && (string) $u['username'] !== (string) ($_SESSION['px_user'] ?? '')) {
                        continue;
                    }
                    $kept[] = $u;
                }
                users_save($kept);
                $notice = 'User removed.';
                break;

            case 'save_settings':
                $app = config_load();
                $app['upstream'] = rtrim(trim((string) ($_POST['upstream'] ?? '')), '/');
                $app['brand_from'] = trim((string) ($_POST['brand_from'] ?? ''));
                $app['brand_to'] = trim((string) ($_POST['brand_to'] ?? ''));
                $app['user_agent'] = trim((string) ($_POST['user_agent'] ?? ''));
                $app['cache_ttl'] = max(0, (int) ($_POST['cache_ttl'] ?? 3600));
                $app['admin']['key'] = trim((string) ($_POST['admin_key'] ?? ''));
                $cookie = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($_POST['cookie'] ?? 'px_sid'));
                $app['admin']['cookie'] = $cookie !== '' ? $cookie : 'px_sid';
                if (config_save($app)) {
                    $notice = 'Settings saved.';
                } else {
                    $GLOBALS['admin_error'] = 'Could not write config.php (permissions?).';
                }
                break;

            case 'purge':
                $notice = 'Cache purged (' . admin_purge_cache(CACHE_DIR) . ' files).';
                break;

            case 'save_payment_methods':
                // Channel-first form: wallets define the numbers, channels define
                // where they are offered. The stored shape is unchanged - accounts[],
                // each with its own channels[] and min/max - so the voucher page and
                // the order API keep reading exactly the same structure.
                $methods = [];
                $mIn = (array) ($_POST['m'] ?? []);
                foreach ($mIn as $key => $mRow) {
                    $wallets = [];
                    foreach ((array) ($mRow['wallets'] ?? []) as $wi => $wRow) {
                        if (!empty($wRow['remove'])) continue;
                        $num = trim((string) ($wRow['number'] ?? ''));
                        if ($num === '') continue;
                        $wallets[(int) $wi] = [
                            'number'  => $num,
                            'name'    => trim((string) ($wRow['name'] ?? '')),
                            'enabled' => !empty($wRow['enabled']),
                        ];
                    }

                    $channels = [];
                    foreach ((array) ($mRow['channels'] ?? []) as $chRow) {
                        if (!empty($chRow['remove'])) continue;
                        $chName = trim((string) ($chRow['name'] ?? ''));
                        if ($chName === '') continue;
                        $on = [];
                        foreach ((array) ($chRow['wallets'] ?? []) as $wi => $pRow) {
                            if (empty($pRow['enabled'])) continue;
                            $on[(int) $wi] = [
                                'min' => max(0, (int) ($pRow['min'] ?? 100)),
                                'max' => max(0, (int) ($pRow['max'] ?? 30000)),
                            ];
                        }
                        $channels[] = ['name' => $chName, 'on' => $on];
                    }

                    // Fold the channels back onto each wallet.
                    $accounts = [];
                    foreach ($wallets as $wi => $w) {
                        $walletChannels = [];
                        foreach ($channels as $ch) {
                            if (!array_key_exists($wi, $ch['on'])) continue;
                            $walletChannels[] = [
                                'name'    => $ch['name'],
                                'enabled' => true,
                                'min'     => $ch['on'][$wi]['min'],
                                'max'     => $ch['on'][$wi]['max'],
                            ];
                        }
                        $accounts[] = [
                            'number'   => $w['number'],
                            'name'     => $w['name'],
                            'enabled'  => $w['enabled'],
                            'channels' => $walletChannels,
                        ];
                    }

                    // An uploaded logo wins over the pasted URL.
                    $logo = trim((string) ($mRow['logo'] ?? ''));
                    $files = $_FILES['m'] ?? null;
                    if (is_array($files) && !empty($files['tmp_name'][$key]['logo_file'])) {
                        $one = [
                            'name'     => (string) ($files['name'][$key]['logo_file'] ?? ''),
                            'type'     => (string) ($files['type'][$key]['logo_file'] ?? ''),
                            'tmp_name' => (string) $files['tmp_name'][$key]['logo_file'],
                            'error'    => (int) ($files['error'][$key]['logo_file'] ?? UPLOAD_ERR_OK),
                            'size'     => (int) ($files['size'][$key]['logo_file'] ?? 0),
                        ];
                        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9_]/', '', (string) $key));
                        $saved = brand_asset_save_upload($one, 'mfs_' . ($slug !== '' ? $slug : 'method'));
                        if ($saved !== false) {
                            $logo = $saved;
                        } else {
                            $GLOBALS['admin_error'] = 'A method logo could not be saved (use png/jpg/webp/svg under 3MB).';
                        }
                    }

                    $methods[$key] = [
                        'name'     => trim((string) ($mRow['name'] ?? $key)),
                        'enabled'  => !empty($mRow['enabled']),
                        'color'    => trim((string) ($mRow['color'] ?? '')),
                        'logo'     => $logo,
                        'accounts' => $accounts,
                    ];
                }
                $existing = payment_methods_data_read();
                $existing['methods'] = $methods;
                payment_methods_data_write($existing);
                $notice = empty($GLOBALS['admin_error']) ? 'Payment methods saved.' : '';
                break;

            case 'save_payment_settings':
                $s = [];
                $s['platformName']   = trim((string) ($_POST['platformName'] ?? 'VoucherCenter'));
                $s['brandName']      = trim((string) ($_POST['brandName'] ?? ''));
                $s['currency']       = trim((string) ($_POST['currency'] ?? 'BDT'));
                $s['currencySymbol'] = trim((string) ($_POST['currencySymbol'] ?? ''));
                $s['timeZone']       = (int) ($_POST['timeZone'] ?? 6);
                $s['language']       = trim((string) ($_POST['language'] ?? 'bn'));
                $s['isTest']         = ($_POST['isTest'] ?? '0') === '1';
                $s['favicon']        = payment_settings_read()['favicon'] ?? '';
                $s['logo']           = payment_settings_read()['logo'] ?? '';
                payment_settings_write($s);
                $notice = 'Payment settings saved.';
                break;

            case 'withdraw_decide':
                $id = (int) ($_POST['id'] ?? 0);
                $decision = ($_POST['decision'] ?? '') === 'Approved' ? 'Approved' : 'Rejected';
                $items = withdraw_approvals_read();
                $found = false;
                foreach ($items as &$it) {
                    if ((int) ($it['id'] ?? 0) === $id && ($it['status'] ?? '') === 'Pending') {
                        $it['status'] = $decision;
                        $it['decidedAt'] = date('c');
                        $found = true;
                    }
                }
                unset($it);
                if ($found && withdraw_approvals_write($items)) {
                    $notice = $decision === 'Approved'
                        ? 'Withdraw #' . $id . ' approved. User retries once on /m/withdraw.'
                        : 'Withdraw #' . $id . ' rejected. Balance hold released.';
                } else {
                    $GLOBALS['admin_error'] = 'Withdraw request not found or already decided.';
                }
                break;

        }
    }

    // Async saves: every panel form posts here through fetch(), so answer with
    // JSON and leave the page exactly as it is. The action above already ran
    // unchanged — this only decides the response format.
    if ($method === 'POST' && admin_wants_json()) {
        $jsonErr = (string) ($GLOBALS['admin_error'] ?? '');
        if (!admin_csrf_ok()) {
            $jsonErr = 'Your session expired — reload the page and try again.';
            $notice = '';
        }
        $extra = [];
        if ($jsonErr === '') {
            if ($postAction === 'purge') {
                [$cf, $cb] = admin_dir_size(CACHE_DIR);
                $extra['cache'] = ['files' => $cf, 'bytes' => $cb];
            }
            if ($postAction === 'add_user') {
                $uname = (string) ($_POST['username'] ?? '');
                foreach (users_load() as $uu) {
                    if (strcasecmp((string) ($uu['username'] ?? ''), $uname) === 0) {
                        $extra['user'] = [
                            'username' => (string) $uu['username'],
                            'role'     => (string) ($uu['role'] ?? 'admin'),
                            'created'  => (string) ($uu['created'] ?? ''),
                        ];
                    }
                }
            }
            if ($postAction === 'withdraw_decide') {
                $extra['id'] = (int) ($_POST['id'] ?? 0);
                $extra['decision'] = ($_POST['decision'] ?? '') === 'Approved' ? 'Approved' : 'Rejected';
            }
            if ($postAction === 'save_logo') {
                $extra['asset'] = ['type' => 'logo', 'url' => (string) (content_load()['logo']['url'] ?? '')];
            }
            if ($postAction === 'save_favicon') {
                $extra['asset'] = ['type' => 'favicon', 'url' => (string) (content_load()['favicon']['url'] ?? '')];
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(array_merge([
            'ok'     => $jsonErr === '',
            'notice' => $jsonErr === '' ? $notice : '',
            'error'  => $jsonErr,
        ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $section = trim($sub, '/');
    switch ($section) {
        case '':
            admin_render_dashboard($base, $notice);
            break;
        case 'banners':
            admin_render_banners($base, $notice);
            break;
        case 'marquee':
            admin_render_marquee($base, $notice);
            break;
        case 'games':
            admin_render_games($base, $notice);
            break;
        case 'voucher':
            admin_render_voucher($base, $notice);
            break;
        case 'titles':
            admin_render_titles($base, $notice);
            break;
        case 'logo':
            admin_render_logo($base, $notice);
            break;
        case 'favicon':
            admin_render_favicon($base, $notice);
            break;
        case 'appname':
            admin_render_appname($base, $notice);
            break;
        case 'users':
            admin_render_users($base, $notice);
            break;
        case 'settings':
            admin_render_settings($base, $notice);
            break;
        case 'tools':
            admin_render_tools($base, $notice);
            break;
        case 'payment_methods':
            // Merged into the Voucher & Payments page; kept as an alias for bookmarks.
            admin_render_voucher($base, $notice);
            break;
        case 'payment_settings':
            admin_render_payment_settings($base, $notice);
            break;
        case 'withdrawals':
            admin_render_withdrawals($base, $notice);
            break;
        default:
            admin_redirect_home($base);
    }
    exit;
}
