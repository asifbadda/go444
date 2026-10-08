/* ==========================================================================
   BBC99 member centre — behaviour (upstream look).
   Paints mobile/member.html from GET /api/memberSummary.php via
   PXAPI.memberSummary. Money-movement and record pages stay proxied
   upstream in phase 1; this page is only the dashboard shell.
   ========================================================================== */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var firstLoad = true;

  function toast(message, kind) {
    if (!message) return;
    var wrap = $('.toast-wrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'toast-wrap';
      document.body.appendChild(wrap);
    }
    var el = document.createElement('div');
    el.className = 'toast' + (kind ? ' toast--' + kind : '');
    el.textContent = message;
    wrap.appendChild(el);
    setTimeout(function () {
      el.style.opacity = '0';
      el.style.transition = 'opacity .2s';
      setTimeout(function () { el.remove(); }, 220);
    }, 3200);
  }

  function fmt(n) {
    var v = Number(n);
    if (!isFinite(v)) return '0.00';
    return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function badge(el, n) {
    if (!el) return;
    n = Number(n) || 0;
    if (n > 0) {
      el.hidden = false;
      el.textContent = n > 99 ? '99+' : String(n);
    } else {
      el.hidden = true;
    }
  }

  function needLogin() {
    location.replace('/m/login');
  }

  /* ---------- paint ---------- */
  function paint(d, wasFirst) {
    var m = d.member || {};
    var b = d.balance || {};
    var v = d.vip || {};
    var r = d.referral || {};

    var nick = m.nickname || m.account || 'Member';
    var avatar = $('#member-avatar');
    if (avatar) avatar.textContent = (nick.trim().charAt(0) || '–').toUpperCase();

    var vipEl = $('#member-vip');
    if (vipEl) vipEl.textContent = m.vip || v.current || 'VIP0';
    var accEl = $('#member-account');
    if (accEl) accEl.textContent = m.account || m.mobile || '—';
    var nameEl = $('#member-name');
    if (nameEl) nameEl.textContent = m.nickname || '—';
    var joinEl = $('#member-joined');
    if (joinEl) joinEl.textContent = m.joined || '';

    var sym = $('#member-symbol');
    if (sym && b.symbol) sym.textContent = b.symbol;
    var bal = $('#member-balance');
    if (bal) bal.textContent = fmt(b.avail != null ? b.avail : b.sum);

    // Badges with real data behind them: inbox unread on Internal Message.
    // Rewards / Mission badges stay hidden — no count source yet.
    badge($('#member-unread'), d.unread);

    var loading = $('#member-loading');
    if (loading) loading.hidden = true;
    var err = $('#member-error');
    if (err) err.hidden = true;
    var body = $('#member-body');
    if (body) body.hidden = false;

    if (d.partial && wasFirst) {
      toast('Some sections are unavailable right now.', 'error');
    }
  }

  function fail(message) {
    var loading = $('#member-loading');
    if (loading) loading.hidden = true;
    var body = $('#member-body');
    if (body) body.hidden = true;
    var err = $('#member-error');
    if (err) {
      err.hidden = false;
      var t = $('#member-error-text');
      if (t && message) t.textContent = message;
    }
  }

  function load() {
    if (!window.PXAPI || !PXAPI.memberSummary) {
      fail('Could not reach the account service.');
      return;
    }
    // Background refreshes must not flash the skeleton over a painted
    // dashboard — only the first visit gets the loading state.
    var loading = $('#member-loading');
    if (loading) loading.hidden = !firstLoad;
    PXAPI.memberSummary().then(function (d) {
      var wasFirst = firstLoad;
      firstLoad = false;
      if (!d || d.success === false) {
        if (d && (d.needLogin || d.status === 401)) { needLogin(); return; }
        // Silent on refresh (dashboard already painted); loud on first load.
        if ($('#member-body').hidden) {
          fail((d && (d.error || d.message)) || 'Could not load your account.');
        }
        return;
      }
      paint(d, wasFirst);
    }).catch(function (e) {
      if (e && (e.status === 401 || (e.data && e.data.needLogin))) { needLogin(); return; }
      if ($('#member-body').hidden) {
        fail((e && e.message) || 'Could not load your account.');
      }
    });
  }

  /* ---------- actions ---------- */
  function go(url) { location.href = url; }

  function copyText(text) {
    var done = function () { toast('Copied.', 'ok'); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, done);
      return;
    }
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) { /* noop */ }
    ta.remove();
    done();
  }

  function wireActions() {
    var back = $('#member-back');
    // Upstream goes back to /m/home; replace keeps the dashboard out of
    // history so Back never lands on a stale copy.
    if (back) back.addEventListener('click', function () { location.replace('/m/home'); });
    var copy = $('#member-copy');
    if (copy) copy.addEventListener('click', function () {
      var acc = $('#member-account');
      var text = acc ? acc.textContent.trim() : '';
      if (text && text !== '—') copyText(text);
    });
    var refresh = $('#member-refresh');
    if (refresh) refresh.addEventListener('click', load);
    var dep = $('#member-deposit');
    if (dep) dep.addEventListener('click', function () { go('/m/voucherCenter'); });
    var wd = $('#member-withdraw');
    if (wd) wd.addEventListener('click', function () { go('/m/withdraw'); });
    var bank = $('#member-bank');
    // BINDCARD entrance is off for this merchant (whitelabel
    // BINDCARDENTRANCE3=0), so the cards page bounces — the withdraw page
    // owns card management and is always a valid destination.
    if (bank) bank.addEventListener('click', function () { go('/m/withdraw'); });
    var logout = $('#member-logout');
    if (logout) logout.addEventListener('click', function () {
      if (!window.PXAPI) { go('/m/login'); return; }
      PXAPI.logout().catch(function () {}).then(function () { go('/m/login'); });
    });
    var retry = $('#member-retry');
    if (retry) retry.addEventListener('click', load);
    var navDeposit = $('#nav-deposit');
    if (navDeposit) navDeposit.addEventListener('click', function (e) { e.preventDefault(); go('/m/voucherCenter'); });
    var navPromo = $('#nav-promo');
    if (navPromo) navPromo.addEventListener('click', function (e) { e.preventDefault(); go('/m/activity'); });
    var navShare = $('#nav-share');
    if (navShare) navShare.addEventListener('click', function (e) {
      e.preventDefault();
      var url = location.origin + '/';
      if (navigator.share) {
        navigator.share({ title: document.title, text: 'Play on BBC99', url: url }).catch(function () {});
        return;
      }
      copyText(url);
    });
  }

  function init() {
    document.body.classList.add('is-guest');
    wireActions();
    load();
    // Keep balance / badges live while the dashboard sits open: poll
    // quietly, and refresh the moment the tab is visible again (back from
    // deposit, withdraw, or a game — when money just moved).
    setInterval(function () {
      if (!document.hidden && !$('#member-body').hidden) load();
    }, 30000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && !$('#member-body').hidden) load();
    });
    window.addEventListener('focus', function () {
      if (!$('#member-body').hidden) load();
    });
    window.addEventListener('pageshow', function () {
      if (!$('#member-body').hidden) load();
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
