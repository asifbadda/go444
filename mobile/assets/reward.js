/* ==========================================================================
   BBC99 rewards — behaviour (upstream look).
   Profile card paints from PXAPI.memberSummary; promo tiles paint from
   PXAPI.rewardsSummary. Tile tap claims when the promo is claimable,
   otherwise opens the promotion page. Matches /m/activity by title.
   ========================================================================== */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var firstLoad = true;

  // Tile theme per promo kind (upstream: green Bonus, blue Sign In,
  // orange Rescue, pink Invite, red Coupon).
  // Fixed category tiles, always visible — destinations are the upstream
  // pages for each category (verified in their route table).
  var TILES = [
    { key: 'bonus', label: 'Bonus', href: '/m/receivingCenter' },
    { key: 'signin', label: 'Sign In', href: '/m/activity/signIn' },
    { key: 'rescue', label: 'Rescue fund', href: '/m/activity/rescue' },
    { key: 'invite', label: 'Invite Friends', href: '/m/inviteFriends' },
    { key: 'coupon', label: 'Temu Coupon', href: '/m/temuTicket' },
  ];
  var ICONS = {
    bonus: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="9" width="16" height="11" rx="1.5"/><path d="M4 6.5h16V9H4zM12 6.5V20M12 6.5S8 6.5 6.5 5A1.6 1.6 0 0 1 8 2.5c1.5 0 3 2.4 4 4zm0 0s4 0 5.5-1.5A1.6 1.6 0 0 0 16 2.5c-1.5 0-3 2.4-4 4z"/></svg>',
    signin: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="16" rx="2.5"/><path d="M3.5 9.5h17M9.5 14.5l2 2 3.5-4"/></svg>',
    rescue: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><ellipse cx="12" cy="6.5" rx="6.5" ry="2.5"/><path d="M5.5 6.5v6c0 1.4 2.9 2.5 6.5 2.5s6.5-1.1 6.5-2.5v-6M5.5 12.5v5c0 1.4 2.9 2.5 6.5 2.5s6.5-1.1 6.5-2.5v-5M12 12.5V20"/></svg>',
    invite: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="10" cy="8.5" r="3"/><path d="M4 19.5a6 6 0 0 1 12 0M18.5 9v6M15.5 12h6"/></svg>',
    coupon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M3 10.5h5M3 14.5h5M21 10.5h-5M21 14.5h-5M15 7v13"/></svg>',
    other: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8.6h16V6.4H4zM5.5 10v8.4A2.1 2.1 0 0 0 7.6 20.5h8.8a2.1 2.1 0 0 0 2.1-2.1V10z"/></svg>',
  };

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

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function fmt(n) {
    var v = Number(n);
    if (!isFinite(v)) return '0.00';
    return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function needLogin() {
    location.replace('/m/login');
  }

  /* ---------- profile card (from memberSummary) ---------- */
  function paintProfile(d) {
    var m = d.member || {};
    var b = d.balance || {};
    var v = d.vip || {};

    var nick = m.nickname || m.account || 'Member';
    var avatar = $('#reward-avatar');
    if (avatar) avatar.textContent = (nick.trim().charAt(0) || '–').toUpperCase();
    var acc = $('#reward-account');
    if (acc) acc.textContent = m.account || m.mobile || '—';
    var name = $('#reward-name');
    if (name) name.textContent = m.nickname || '—';
    var bal = $('#reward-balance');
    if (bal) bal.textContent = fmt(b.avail != null ? b.avail : b.sum);
    var vip = $('#reward-vip');
    if (vip) vip.textContent = m.vip || v.current || 'VIP0';

    var fill = $('#reward-progress-fill');
    var pnum = $('#reward-progress-num');
    if (v.turnoverExpected > 0) {
      if (fill) fill.style.width = Math.max(0, Math.min(100, (v.turnoverActual / v.turnoverExpected) * 100)) + '%';
      if (pnum) pnum.textContent = fmt(v.turnoverActual) + ' / ' + fmt(v.turnoverExpected);
    } else if (pnum) {
      pnum.textContent = '';
    }
  }

  /* ---------- tiles: fixed set, badges from live data ---------- */
  function tileStatic(t, badge) {
    return '<a class="rw-tile rw-tile--' + t.key + '" href="' + t.href + '">'
      + '<span class="rw-tile-ico">' + ICONS[t.key] + '</span>'
      + '<span class="rw-tile-label">' + esc(t.label) + '</span>'
      + (badge > 0 ? '<span class="rw-tile-badge">' + esc(String(badge > 99 ? '99+' : badge)) + '</span>' : '')
      + '</a>';
  }

  function paintTiles(d, wasFirst) {
    var wrap = $('#reward-tiles');
    if (!wrap) return;
    // Claimable count drives the Bonus badge; tickets drive the Coupon badge.
    var bonusBadge = (d.claimable || []).length + (d.promotions || []).length;
    var couponBadge = (d.tickets || []).length + (d.discounts || []).length;
    var badges = { bonus: bonusBadge, coupon: couponBadge };
    wrap.innerHTML = TILES.map(function (t) {
      return tileStatic(t, badges[t.key] || 0);
    }).join('');
    var empty = $('#reward-empty');
    if (empty) empty.hidden = true;

    var loading = $('#reward-loading');
    if (loading) loading.hidden = true;
    var err = $('#reward-error');
    if (err) err.hidden = true;
    var body = $('#reward-body');
    if (body) body.hidden = false;

    if (d.partial && wasFirst) {
      toast('Some rewards are unavailable right now.', 'error');
    }
  }

  function fail(message) {
    var loading = $('#reward-loading');
    if (loading) loading.hidden = true;
    var body = $('#reward-body');
    if (body) body.hidden = true;
    var err = $('#reward-error');
    if (err) {
      err.hidden = false;
      var t = $('#reward-error-text');
      if (t && message) t.textContent = message;
    }
  }

  function load() {
    if (!window.PXAPI || !PXAPI.rewardsSummary || !PXAPI.memberSummary) {
      fail('Could not reach the rewards service.');
      return;
    }
    var loading = $('#reward-loading');
    if (loading) loading.hidden = !firstLoad;
    PXAPI.memberSummary().then(function (me) {
      if (!me || me.success === false) {
        if (me && (me.needLogin || me.status === 401)) { needLogin(); return null; }
        throw new Error((me && (me.error || me.message)) || 'Profile failed.');
      }
      paintProfile(me);
      return PXAPI.rewardsSummary();
    }).then(function (d) {
      if (d === null) return;
      var wasFirst = firstLoad;
      firstLoad = false;
      if (!d || d.success === false) {
        if (d && (d.needLogin || d.status === 401)) { needLogin(); return; }
        if ($('#reward-body').hidden) {
          fail((d && (d.error || d.message)) || 'Could not load your rewards.');
        }
        return;
      }
      paintTiles(d, wasFirst);
    }).catch(function (e) {
      if (e && (e.status === 401 || (e.data && e.data.needLogin))) { needLogin(); return; }
      if ($('#reward-body').hidden) {
        fail((e && e.message) || 'Could not load your rewards.');
      }
    });
  }

  /* ---------- wiring ---------- */
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

  function wire() {
    var copy = $('#reward-copy');
    if (copy) copy.addEventListener('click', function () {
      var acc = $('#reward-account');
      var text = acc ? acc.textContent.trim() : '';
      if (text && text !== '—') copyText(text);
    });
    var refresh = $('#reward-refresh');
    if (refresh) {
      refresh.addEventListener('click', function () {
        refresh.classList.add('loading');
        load();
        setTimeout(function () { refresh.classList.remove('loading'); }, 1500);
      });
    }
    var retry = $('#reward-retry');
    if (retry) retry.addEventListener('click', load);
  }

  function init() {
    document.body.classList.add('is-guest');
    wire();
    load();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
