/* ==========================================================================
   BBC99 bonus receiving — behaviour.
   Lists the unapplied extra rewards + claimable promos from
   /api/rewardsSummary.php as ticket cards with live countdowns.
   Receiving POSTs straight to the relay through PXAPI (claimTicket, with
   claimIssued fallback). QUEST items claim by claimId, others by id —
   exactly like the upstream card.
   ========================================================================== */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var firstLoad = true;
  var timers = [];

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

  function str(o, keys) {
    for (var i = 0; i < keys.length; i++) {
      var v = o && o[keys[i]];
      if (v != null && v !== '' && typeof v !== 'object') return String(v);
    }
    return '';
  }
  function num(o, keys) {
    for (var i = 0; i < keys.length; i++) {
      var v = o && o[keys[i]];
      if (typeof v === 'number' && isFinite(v)) return v;
    }
    return null;
  }

  function needLogin() {
    location.replace('/m/login');
  }

  /* ---------- cards ---------- */
  function isQuest(o) {
    return String(o.promotionType || o.type || '').toUpperCase() === 'QUEST';
  }
  function claimIdOf(o) {
    return isQuest(o)
      ? str(o, ['claimId', 'id'])
      : str(o, ['id', 'ticketId', 'promotionId', 'promoId']);
  }
  function endMs(o) {
    var v = o.endDate || o.expireTime || o.expiredAt || o.endTime;
    if (typeof v === 'number') return v > 1e12 ? v : (v > 1e10 ? v : v * 1000);
    var t = Date.parse(v);
    return isFinite(t) ? t : 0;
  }
  function unavailable(o) {
    var st = str((o && o.tickets) || {}, ['status']) || str(o, ['status']);
    return String(st || '').toUpperCase() === 'UNAVAILABLE';
  }

  function fmt(n) {
    var v = Number(n);
    if (!isFinite(v)) return '0.00';
    return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  /* ---------- hero (name + balance, like upstream) ---------- */
  function paintHero(d) {
    var m = d.member || {};
    var b = d.balance || {};
    var nick = m.nickname || m.account || 'Member';
    var avatar = $('#receive-avatar');
    if (avatar) avatar.textContent = (nick.trim().charAt(0) || '–').toUpperCase();
    var name = $('#receive-name');
    if (name) name.textContent = nick;
    var bal = $('#receive-balance');
    if (bal) bal.textContent = fmt(b.avail != null ? b.avail : b.sum);
  }

  function fmtLeft(ms) {
    if (ms <= 0) return 'Expired';
    var s = Math.floor(ms / 1000);
    var d = Math.floor(s / 86400);
    var h = Math.floor((s % 86400) / 3600);
    var m = Math.floor((s % 3600) / 60);
    var ss = s % 60;
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return (d > 0 ? d + 'd ' : '') + p(h) + ':' + p(m) + ':' + p(ss);
  }

  function card(o) {
    var title = str(o, ['displayName', 'title', 'name', 'promotionName']) || 'Bonus';
    var desc = str(o, ['description', 'desc', 'remark']);
    var amount = num(o, ['amount', 'bonus', 'rewardAmount', 'value']);
    var id = claimIdOf(o);
    var end = endMs(o);
    var dead = unavailable(o) || (end > 0 && end <= Date.now());
    return '<article class="ticket" data-id="' + esc(id) + '"'
      + (end > 0 ? ' data-end="' + end + '"' : '') + '>'
      + '<div class="ticket-top">'
      + '<span class="ticket-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="9" width="16" height="11" rx="1.5"/><path d="M4 6.5h16V9H4zM12 6.5V20M12 6.5S8 6.5 6.5 5A1.6 1.6 0 0 1 8 2.5c1.5 0 3 2.4 4 4zm0 0s4 0 5.5-1.5A1.6 1.6 0 0 0 16 2.5c-1.5 0-3 2.4-4 4z"/></svg></span>'
      + '<span class="ticket-name">' + esc(title) + '</span>'
      + (amount != null ? '<span class="ticket-amount">৳' + esc(String(amount)) + '</span>' : '')
      + '</div>'
      + (desc ? '<p class="ticket-desc">' + esc(desc) + '</p>' : '')
      + '<div class="ticket-foot">'
      + (end > 0
        ? '<span class="ticket-countdown" data-countdown>' + esc(fmtLeft(end - Date.now())) + '</span>'
        : '<span class="ticket-countdown">Permanent</span>')
      + (dead
        ? '<span class="ticket-state">Expired</span>'
        : (id
          ? '<button type="button" class="ticket-receive" data-receive="' + esc(id) + '">Receive</button>'
          : ''))
      + '</div></article>';
  }

  function tick() {
    var now = Date.now();
    Array.prototype.forEach.call(document.querySelectorAll('[data-countdown]'), function (el) {
      var card_ = el.closest ? el.closest('.ticket') : null;
      var end = card_ ? Number(card_.getAttribute('data-end')) : 0;
      if (!end) return;
      var left = end - now;
      el.textContent = fmtLeft(left);
      if (left <= 0) {
        el.classList.add('is-expired');
        var btn = card_ ? card_.querySelector('[data-receive]') : null;
        if (btn) btn.disabled = true;
      }
    });
  }

  function paint(d, wasFirst) {
    var items = [].concat(d.extra || [], d.claimable || [], d.tickets || []);
    var seen = {}, rows = [];
    items.forEach(function (o) {
      var key = claimIdOf(o) || str(o, ['title', 'name']);
      if (!key || seen[key]) return;
      seen[key] = 1;
      rows.push(o);
    });

    var list = $('#receive-list');
    if (list) list.innerHTML = rows.map(card).join('');
    var empty = $('#receive-empty');
    if (empty) empty.hidden = rows.length > 0;

    var loading = $('#receive-loading');
    if (loading) loading.hidden = true;
    var err = $('#receive-error');
    if (err) err.hidden = true;
    var body = $('#receive-body');
    if (body) body.hidden = false;

    if (d.partial && wasFirst) {
      toast('Some bonuses are unavailable right now.', 'error');
    }
  }

  function fail(message) {
    var loading = $('#receive-loading');
    if (loading) loading.hidden = true;
    var body = $('#receive-body');
    if (body) body.hidden = true;
    var err = $('#receive-error');
    if (err) {
      err.hidden = false;
      var t = $('#receive-error-text');
      if (t && message) t.textContent = message;
    }
  }

  function load() {
    if (!window.PXAPI || !PXAPI.rewardsSummary || !PXAPI.memberSummary) {
      fail('Could not reach the bonus service.');
      return;
    }
    var loading = $('#receive-loading');
    if (loading) loading.hidden = !firstLoad;
    PXAPI.memberSummary().then(function (me) {
      if (!me || me.success === false) {
        if (me && (me.needLogin || me.status === 401)) { needLogin(); return null; }
        throw new Error((me && (me.error || me.message)) || 'Profile failed.');
      }
      paintHero(me);
      return PXAPI.rewardsSummary();
    }).then(function (d) {
      if (d === null) return;
      var wasFirst = firstLoad;
      firstLoad = false;
      if (!d || d.success === false) {
        if (d && (d.needLogin || d.status === 401)) { needLogin(); return; }
        if ($('#receive-body').hidden) {
          fail((d && (d.error || d.message)) || 'Could not load your bonuses.');
        }
        return;
      }
      paint(d, wasFirst);
    }).catch(function (e) {
      if (e && (e.status === 401 || (e.data && e.data.needLogin))) { needLogin(); return; }
      if ($('#receive-body').hidden) {
        fail((e && e.message) || 'Could not load your bonuses.');
      }
    });
  }

  /* ---------- receive (same fallback chain as rewards) ---------- */
  function receive(id, btn) {
    if (!id || !window.PXAPI) return;
    if (btn) btn.disabled = true;
    PXAPI.claimTicket({ id: id, ticketId: id }).then(function (d) {
      toast((d && (d.message || d.msg)) || 'Received.', 'ok');
      load();
    }).catch(function () {
      PXAPI.claimIssued({ id: id, promotionId: id }).then(function (d2) {
        toast((d2 && (d2.message || d2.msg)) || 'Received.', 'ok');
        load();
      }).catch(function (e2) {
        if (btn) btn.disabled = false;
        toast((e2 && (e2.message || (e2.data && (e2.data.message || e2.data.errorCode)))) || 'Receive failed.', 'error');
      });
    });
  }

  /* ---------- wiring ---------- */
  function wire() {
    var back = $('#receive-back');
    if (back) back.addEventListener('click', function () { location.replace('/m/rewardCenter'); });
    var retry = $('#receive-retry');
    if (retry) retry.addEventListener('click', load);
    document.addEventListener('click', function (e) {
      var b = e.target && e.target.closest ? e.target.closest('[data-receive]') : null;
      if (!b || b.disabled) return;
      receive(b.getAttribute('data-receive'), b);
    });
    timers.push(setInterval(tick, 1000));
  }

  function init() {
    document.body.classList.add('is-guest');
    wire();
    load();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
