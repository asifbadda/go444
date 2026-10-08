/* ==========================================================================
   BBC99 daily sign-in — behaviour.
   Hero paints from PXAPI.memberSummary; stats, headline, conditions and day
   cards paint from PXAPI.signinSummary. Day check-ins POST
   MCSFE_claimLoginPromotion through PXAPI.claimLogin.
   ========================================================================== */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var firstLoad = true;

  var CASH_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8h18v9H3z" fill="rgba(14,169,104,.12)"/><path d="M3 8h18v9H3z"/><path d="M6.5 8v9M17.5 8v9M12 10.5a2 2 0 1 0 0 .01M9 12.5h6"/></svg>';

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

  /* ---------- hero (from memberSummary) ---------- */
  function paintHero(d) {
    var m = d.member || {};
    var b = d.balance || {};
    var nick = m.nickname || m.account || 'Member';
    var avatar = $('#signin-avatar');
    if (avatar) avatar.textContent = (nick.trim().charAt(0) || '–').toUpperCase();
    var name = $('#signin-name');
    if (name) name.textContent = nick;
    var bal = $('#signin-balance');
    if (bal) bal.textContent = fmt(b.avail != null ? b.avail : b.sum);
  }

  /* ---------- state (from signinSummary) ---------- */
  function dayNum(o, i) {
    var n = num(o, ['day', 'dayNo', 'dayNum', 'index', 'sort']);
    return n != null ? Math.round(n) : (i + 1);
  }
  function dayBonus(o) {
    return num(o, ['bonus', 'amount', 'rewardAmount', 'value', 'money']);
  }
  function dayId(o) {
    return str(o, ['id', 'configId', 'promotionId', 'dayId']);
  }
  function dayState(o, i, checkedToday, activeIdx) {
    // signed: explicitly claimed/signed, or any day before the active one.
    var st = String(o.status || o.state || o.claimStatus || '').toUpperCase();
    if (st === 'SIGNED' || st === 'CLAIMED' || st === 'DONE' || o.signed === true || o.claimed === true) {
      return 'signed';
    }
    if (i < activeIdx) return 'signed';
    if (i === activeIdx) return (checkedToday ? 'signed' : 'today');
    return 'locked';
  }

  function paintState(d) {
    var last = $('#signin-last');
    if (last) last.textContent = d.lastSignIn != null ? String(Math.round(d.lastSignIn)) : '—';
    var total = $('#signin-total');
    if (total) total.textContent = d.totalBonus != null ? String(Math.round(d.totalBonus)) : '—';

    var mq = $('#signin-marquee'), mqc = $('#signin-marquee-content');
    var marquee = d.headline || d.description;
    if (mq && mqc && marquee) {
      mq.hidden = false;
      mqc.innerHTML = '<span>' + esc(marquee) + '</span><span aria-hidden="true">' + esc(marquee) + '</span>';
    }

    var promo = $('#signin-promo');
    if (promo && (d.headline || d.description)) {
      promo.hidden = false;
      var h = $('#signin-headline');
      if (h) h.textContent = d.headline || d.description;
      var cs = $('#signin-checkstate');
      if (cs) {
        var done = !!d.checkedToday;
        cs.className = 'si-check-state' + (done ? ' is-done' : '');
        cs.innerHTML = esc(done ? 'Checked in today' : 'Not checked in today') + ' <span class="si-q">?</span>';
      }
    }

    var cond = $('#signin-conditions');
    if (cond && (d.minDeposit != null || d.bettingConditions != null)) {
      cond.hidden = false;
      var md = $('#signin-mindep');
      if (md) md.textContent = d.minDeposit != null ? fmt(d.minDeposit) : '—';
      var bc = $('#signin-betcond');
      if (bc) bc.textContent = d.bettingConditions != null ? fmt(d.bettingConditions) : '—';
    }

    // Day cards. The active (check-in-able) card is the first one that is
    // neither signed nor expired; everything before it reads as signed.
    var days = (d.days || []).slice(0, 14);
    var activeIdx = -1;
    days.forEach(function (o, i) {
      var st = String(o.status || o.state || o.claimStatus || '').toUpperCase();
      if (activeIdx === -1 && st !== 'SIGNED' && st !== 'CLAIMED' && st !== 'DONE'
          && o.signed !== true && o.claimed !== true) {
        activeIdx = i;
      }
    });
    if (activeIdx === -1 && days.length) activeIdx = days.length;
    if (d.checkedToday) activeIdx = days.length; // today already done

    var wrap = $('#signin-days');
    if (wrap) {
      wrap.innerHTML = days.map(function (o, i) {
        var n = dayNum(o, i);
        var b = dayBonus(o);
        var st = dayState(o, i, !!d.checkedToday, activeIdx);
        var note = str(o, ['note', 'remark', 'subTitle']);
        var btn = st === 'signed'
          ? '<button type="button" class="si-day-btn" disabled>Signed in</button>'
          : st === 'today'
            ? '<button type="button" class="si-day-btn" data-checkin="' + esc(dayId(o) || n) + '">Sign In</button>'
            : '<button type="button" class="si-day-btn" disabled>Sign In</button>';
        return '<div class="si-day">'
          + '<div class="si-day-head">Day ' + n + '</div>'
          + '<div class="si-day-body">'
          + '<span class="si-day-img">' + CASH_SVG + '</span>'
          + '<span class="si-day-bonus"><span>Bonus</span><b>৳ ' + (b != null ? fmt(b) : '—') + '</b></span>'
          + (note ? '<span class="si-day-note">' + esc(note) + '</span>' : '')
          + btn
          + '</div></div>';
      }).join('');
    }

    var loading = $('#signin-loading');
    if (loading) loading.hidden = true;
    var err = $('#signin-error');
    if (err) err.hidden = true;
    var body = $('#signin-body');
    if (body) body.hidden = false;

    if (d.partial && firstLoad) {
      toast('Some check-in data is unavailable.', 'error');
    }
  }

  function fail(message) {
    var loading = $('#signin-loading');
    if (loading) loading.hidden = true;
    var body = $('#signin-body');
    if (body) body.hidden = true;
    var err = $('#signin-error');
    if (err) {
      err.hidden = false;
      var t = $('#signin-error-text');
      if (t && message) t.textContent = message;
    }
  }

  function load() {
    if (!window.PXAPI || !PXAPI.signinSummary || !PXAPI.memberSummary) {
      fail('Could not reach the check-in service.');
      return;
    }
    var loading = $('#signin-loading');
    if (loading) loading.hidden = !firstLoad;
    PXAPI.memberSummary().then(function (me) {
      if (!me || me.success === false) {
        if (me && (me.needLogin || me.status === 401)) { needLogin(); return null; }
        throw new Error((me && (me.error || me.message)) || 'Profile failed.');
      }
      paintHero(me);
      return PXAPI.signinSummary();
    }).then(function (d) {
      if (d === null) return;
      var wasFirst = firstLoad;
      firstLoad = false;
      if (!d || d.success === false) {
        if (d && (d.needLogin || d.status === 401)) { needLogin(); return; }
        if ($('#signin-body').hidden) {
          fail((d && (d.error || d.message)) || 'Could not load the check-in.');
        }
        return;
      }
      paintState(d, wasFirst);
    }).catch(function (e) {
      if (e && (e.status === 401 || (e.data && e.data.needLogin))) { needLogin(); return; }
      if ($('#signin-body').hidden) {
        fail((e && e.message) || 'Could not load the check-in.');
      }
    });
  }

  /* ---------- check in (POST, like upstream) ---------- */
  function checkin(id, btn) {
    if (!window.PXAPI) return;
    if (btn) btn.disabled = true;
    PXAPI.claimLogin({ id: id }).then(function (d) {
      toast((d && (d.message || d.msg)) || 'Signed in.', 'ok');
      load();
    }).catch(function (e) {
      if (btn) btn.disabled = false;
      toast((e && (e.message || (e.data && (e.data.message || e.data.errorCode)))) || 'Sign-in failed.', 'error');
    });
  }

  /* ---------- wiring ---------- */
  function wire() {
    var back = $('#signin-back');
    if (back) back.addEventListener('click', function () { location.replace('/m/rewardCenter'); });
    var retry = $('#signin-retry');
    if (retry) retry.addEventListener('click', load);
    document.addEventListener('click', function (e) {
      var b = e.target && e.target.closest ? e.target.closest('[data-checkin]') : null;
      if (!b || b.disabled) return;
      checkin(b.getAttribute('data-checkin'), b);
    });
  }

  function init() {
    document.body.classList.add('is-guest');
    wire();
    load();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
