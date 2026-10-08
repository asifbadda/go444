/* ==========================================================================
   BBC99 mobile homepage — behaviour.

   The markup/style is our own (see index.html + home.css) and depends on no
   external stylesheet or icon CDN. This script only fills the dynamic parts
   from the go444 backend through PXAPI (games, categories, balance, banners).
   ========================================================================== */
(function () {
  'use strict';

  var $  = function (s, r) { return (r || document).querySelector(s); };

  /* ---------- toast (home has no app.js; keep a local helper) ---------- */
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

  // Category menu: exact order/classes the reference uses. `type` maps to the
  // backend gameType filter; '' means all games.
  var GROUPS = [
    { cls: 'hot',      type: '',        icon: 'HOT',      name: 'গরম' },
    { cls: 'rng',      type: 'RNG',     icon: 'RNG',      name: 'স্লট' },
    { cls: 'live',     type: 'LIVE',    icon: 'LIVE',     name: 'লাইভ' },
    { cls: 'pvp',      type: 'PVP',     icon: 'PVP',      name: 'পোকার' },
    { cls: 'sports',   type: 'SPORTS',  icon: 'SPORTS',   name: 'স্পোর্টস' },
    { cls: 'fish',     type: 'FISH',    icon: 'FISH',     name: 'ফিশিং' },
    { cls: 'elott',    type: 'ELOTT',   icon: 'ELOTT',    name: 'লটারি' },
    { cls: 'esports',  type: 'ESPORTS', icon: 'ESPORTS',  name: 'ই-স্পোর্টস' }
  ];

  var META = {
    merchant:   window.PX_MERCHANT || '',
    platform:   'html5',
    clientType: 2,
    language:   'en'
  };
  var PAGE_SIZE = 24;
  var gamesEnabled = true;
  var state = {
    type: '',          // game-type filter from the category menu ('' = all)
    vassalage: '',     // provider filter (backend field: vassalage)
    q: '',             // name filter (backend field: gameName)
    page: 1, pages: 1, total: 0, shown: 0,
    rows: [], queued: null,   // a filter change that arrived mid-request
    lanes: null,       // provider lanes from GCSGAME_newGameVendor
    loading: false
  };

  var menuEl   = $('#game-menu-list');
  var listEl   = $('#game-list');
  var titleEl  = $('#game-title');
  var winnerEl = $('#winner-list');
  var stripEl  = $('#provider-strip');
  var filtersEl = $('.game-filters');
  var searchEl = $('#game-search');
  var clearEl  = $('#game-search-clear');
  var moreWrap = $('#game-list-more');
  var moreBtn  = $('#game-list-more-btn');
  var loadingEl = $('#game-list-loading');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function val(o, names) {
    for (var i = 0; i < names.length; i++) { if (o && o[names[i]] != null && o[names[i]] !== '') return o[names[i]]; }
    return '';
  }
  function logo(g)  { return val(g, ['defaultIcon', 'showIcon', 'imgUrl', 'imageUrl', 'icon', 'cover']); }
  function name(g)  { return val(g, ['gameName', 'defaultLanguage', 'nodeName', 'name']); }

  function withRetry(fn, attempts) {
    attempts = attempts || 4;
    return new Promise(function (resolve, reject) {
      (function attempt(n) {
        fn().then(resolve).catch(function (err) {
          if (n < attempts) setTimeout(function () { attempt(n + 1); }, 500 * n);
          else reject(err);
        });
      })(1);
    });
  }

  /* ---------- category menu ---------- */
  function renderMenu() {
    if (!menuEl) return;
    // The panel decides which category tabs exist. 'গরম' (no game type) is the
    // unfiltered list and always stays; a disabled category is simply not built.
    var types = (window.PXAPI && PXAPI.gameSettings && PXAPI.gameSettings.types) || {};
    var allowed = (window.PXAPI && PXAPI.settingsAllowed) ? PXAPI.settingsAllowed : function () { return true; };
    var items = GROUPS.filter(function (g) { return g.type === '' || allowed(types, g.type); });
    if (!items.length) items = [GROUPS[0]];
    menuEl.innerHTML = items.map(function (g, i) {
      return '<div class="game-menu-item list-item-' + g.cls + (i === 0 ? ' on' : '') + '" data-type="' + esc(g.type) + '" data-name="' + esc(g.name) + '">' +
        '<div class="game-icon-wrap"><i class="game-menu-icon ' + g.icon + '"></i></div>' +
        '<span class="game-menu-name">' + esc(g.name) + '</span></div>';
    }).join('');

    Array.prototype.forEach.call(menuEl.querySelectorAll('.game-menu-item'), function (item) {
      item.addEventListener('click', function () {
        var type = item.getAttribute('data-type') || '';
        if (type === state.type) return;
        state.type = type;
        // The provider set is per game type, so a provider chosen under the old
        // type has to go back to "All" instead of silently matching nothing.
        state.vassalage = '';
        // 'গরম' (no game type) carries no filter row at all, so any search has to
        // go with it - otherwise an invisible query would keep filtering a list
        // that shows no filter UI to explain why.
        if (!state.type) {
          state.q = '';
          if (searchEl) searchEl.value = '';
          if (clearEl) clearEl.hidden = true;
        }
        Array.prototype.forEach.call(menuEl.querySelectorAll('.game-menu-item'), function (n) { n.classList.remove('on'); });
        item.classList.add('on');
        if (titleEl) titleEl.textContent = item.getAttribute('data-name') || 'All';
        renderProviders();
        syncFilters();
        loadGames(true);
      });
    });
  }

  /* ---------- provider filter + search by name ----------
     Both are real filters on the game relay: GCSGAME_gameList takes
     `vassalage` (provider code) and `gameName` (substring of the indexed game
     name), so the filtering happens on the backend rather than on one page of
     results. The provider list itself comes from the vendor feed
     (GCSGAME_newGameVendor), whose lanes are keyed by game type. */
  function providersFor(type) {
    var out = [], seen = {};
    (state.lanes || []).forEach(function (lane) {
      if (type && val(lane, ['title', 'gameType', 'displayName']) !== type) return;
      (lane.cards || []).forEach(function (c) {
        var code = val(c, ['vassalage', 'accountTypeName']);
        if (!code || seen[code]) return;
        // A vendor switched off in the panel is not offered as a filter either.
        if (window.PXAPI && PXAPI.settingsAllowed
            && !PXAPI.settingsAllowed(PXAPI.gameSettings.vendors || {}, code)) return;
        var icon = val(c, ['smallIcon', 'mobileSmallIcon', 'h5Icon', 'lagerIcon']);
        if (!icon) return;
        seen[code] = 1;
        out.push({ code: code, name: val(c, ['displayName', 'title']) || code, icon: icon });
      });
    });
    return out;
  }

  /* The filter row belongs to the category tabs only: 'গরম' is the unfiltered
     "hot" list, so it shows neither providers nor search. */
  function syncFilters() {
    if (!filtersEl) return;
    filtersEl.hidden = !state.type;
  }

  function renderProviders() {
    if (!stripEl) return;
    if (!state.type) { stripEl.innerHTML = ''; stripEl.hidden = true; return; }
    var list = providersFor(state.type);
    if (!list.length) { stripEl.innerHTML = ''; stripEl.hidden = true; return; }

    var all = '<button type="button" class="provider-chip provider-chip--all' +
      (state.vassalage === '' ? ' is-active' : '') + '" data-vendor="" title="All providers">All</button>';
    var rest = list.map(function (p) {
      return '<button type="button" class="provider-chip' + (state.vassalage === p.code ? ' is-active' : '') +
        '" data-vendor="' + esc(p.code) + '" title="' + esc(p.name) + '" aria-label="' + esc(p.name) + '">' +
        '<img src="' + esc(p.icon) + '" alt="' + esc(p.name) + '" loading="lazy" referrerpolicy="no-referrer"></button>';
    }).join('');
    stripEl.innerHTML = all + rest;
    stripEl.hidden = false;

    stripEl.onclick = function (e) {
      var chip = e.target && e.target.closest ? e.target.closest('.provider-chip') : null;
      if (!chip) return;
      var code = chip.getAttribute('data-vendor') || '';
      if (code === state.vassalage) return;
      state.vassalage = code;
      Array.prototype.forEach.call(stripEl.querySelectorAll('.provider-chip'), function (b) {
        b.classList.remove('is-active');
      });
      chip.classList.add('is-active');
      loadGames(true);
    };
  }

  function wireSearch() {
    if (!searchEl) return;
    var timer = null;
    function run() {
      state.q = searchEl.value.trim();
      if (clearEl) clearEl.hidden = !state.q;
      loadGames(true);
    }
    searchEl.addEventListener('input', function () {
      if (clearEl) clearEl.hidden = !searchEl.value;
      if (timer) clearTimeout(timer);
      timer = setTimeout(run, 350);
    });
    searchEl.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      if (timer) clearTimeout(timer);
      run();
    });
    if (clearEl) {
      clearEl.addEventListener('click', function () {
        searchEl.value = '';
        if (timer) clearTimeout(timer);
        run();
      });
    }
  }

  /* ---------- games ---------- */
  /* The reference's loading state: the grid holds shimmering placeholder tiles
     while the first page is on its way, and a rotating icon sits under the
     grid while any request is in flight. */
  function renderSkeletons(n) {
    if (!listEl) return;
    var out = '';
    for (var i = 0; i < n; i++) {
      out += '<div class="game-list-item is-skeleton" aria-hidden="true"><div class="game-background"></div></div>';
    }
    listEl.innerHTML = out;
  }
  function showLoading(on) {
    if (loadingEl) loadingEl.hidden = !on;
  }

  function renderGames(list, append) {
    if (!listEl) return;
    if (!list || !list.length) {
      if (!append) {
        listEl.innerHTML = '<p class="game-empty">' +
          (state.q ? 'No games found for “' + esc(state.q) + '”.'
                   : 'No games in this selection.') + '</p>';
      }
      return;
    }
    var html = list.map(function (g) {
      var img = logo(g);
      return '<div class="game-list-item" data-node="' + esc(val(g, ['nodeId', 'gameId', 'id'])) + '" ' +
        'data-gtype="' + esc(val(g, ['gameType'])) + '" data-vendor="' + esc(val(g, ['vassalage', 'vendorCode'])) + '">' +
        '<div class="game-background shine">' +
          '<span class="lazy-load-image-background blur lazy-load-image-loaded android" style="color:transparent;display:inline-block;">' +
            (img ? '<img src="' + esc(img) + '" class="img-loading" loading="lazy" referrerpolicy="no-referrer">' : '') +
          '</span>' +
        '</div>' +
        '<div class="game-item-name">' + esc(name(g)) + '</div></div>';
    }).join('');

    if (append) listEl.insertAdjacentHTML('beforeend', html);
    else listEl.innerHTML = html;
  }

  function loadGames(reset) {
    if (!listEl || !window.PXAPI) return;
    // Dropping a filter change that lands mid-flight leaves the grid showing
    // the previous filter's games, which reads as a broken search. Queue the
    // latest request instead and run it as soon as this one settles.
    if (state.loading) { state.queued = reset; return; }
    state.loading = true;
    var append = !reset;

    if (reset) {
      state.page = 1; state.rows = []; state.shown = 0;
      renderSkeletons(6);
    }
    showLoading(true);
    if (moreBtn) moreBtn.disabled = true;

    withRetry(function () {
      var params = {
        merchant: META.merchant, platform: META.platform, clientType: META.clientType,
        pageNo: state.page, pageSize: PAGE_SIZE,
        gameType: state.type || '',       // '' = every game type
        vassalage: state.vassalage || '', // provider, e.g. JL
        gameName: state.q || ''           // name search
      };
      // `language` narrows the relay's name index, and with it in the query the
      // provider and the name stop combining: go444 then answers an empty list
      // even for a provider that owns matching games. Leaving it out of a search
      // lets both filters apply at once, and the rows come back carrying the
      // indexed (English) game names, which is what was typed.
      if (!params.gameName) params.language = META.language;
      return PXAPI.gameList(params);
    }, 4).then(function (res) {
      var d = (res && res.value) || res || {};
      var games = Array.isArray(d.games) ? d.games : [];
      // Hidden games are dropped here, before they reach state.rows, so what is
      // counted is what is shown.
      var hidden = (window.PXAPI && PXAPI.gameSettings && PXAPI.gameSettings.hidden) || [];
      if (hidden.length) {
        var hid = {};
        hidden.forEach(function (x) { hid[String(x)] = 1; });
        games = games.filter(function (g) { return !hid[String(val(g, ['nodeId', 'gameId', 'id']))]; });
      }
      state.pages = Number(d.totalPages || 1) || 1;
      state.total = Number(d.totalCount || 0) || 0;
      // Append only the page just fetched: re-appending the accumulated rows
      // would duplicate everything already on screen.
      state.rows = append ? state.rows.concat(games) : games;
      state.shown = state.rows.length;
      renderGames(games, append);
    }).catch(function () {
      if (!append) renderGames([]);
    }).then(function () {
      state.loading = false;
      showLoading(false);
      if (moreBtn) moreBtn.disabled = false;
      if (moreWrap) moreWrap.hidden = !(state.page < state.pages && state.shown < state.total);
      var queued = state.queued;
      state.queued = null;
      if (queued !== null && queued !== undefined) loadGames(queued);
    });
  }

  if (moreBtn) {
    moreBtn.addEventListener('click', function () {
      if (state.loading || state.page >= state.pages) return;
      state.page += 1;
      loadGames(false);
    });
  }

  /* ---------- app-download: make the download items actionable ---------- */
  // Two elements carry .app-download-wrap: the notice-bar APP chip, and the real
  // download bar that holds the store badges. querySelector() returned the chip,
  // so the handler was attached to an element that never contains a badge and the
  // badges did nothing. Point at the bar by id, and delegate so the badges work
  // wherever they end up in the markup.
  var dlWrap = document.querySelector('#app-download-bar');
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!t || !t.closest) return;
    var it = t.closest('.download-item');
    if (!it) return;
    if (it.classList.contains('android')) {
      /* Android store link — wired to the real APK download route when ready */
      toast('Android download coming soon.', 'ok');
    } else if (it.classList.contains('ios')) {
      toast('iOS App Store link coming soon.', 'ok');
    }
  });

  /* ---------- banner ---------- */
  function bannerImage(it) {
    if (Array.isArray(it.announcementImages) && it.announcementImages.length) {
      var im = it.announcementImages[0];
      return im.imageUrl || im.url || im.imgUrl || '';
    }
    var m = String(it.content || '').match(/src="([^"]+)"/);
    return m ? m[1] : '';
  }
  function renderBanners(list) {
    var wrap = $('#home-banner-wrapper');
    if (!wrap) return;
    var imgs = (list || []).map(bannerImage).filter(Boolean).slice(0, 6);
    if (!imgs.length) return;
    wrap.innerHTML = imgs.map(function (u, i) {
      return '<div class="swiper-slide' + (i === 0 ? ' swiper-slide-active' : '') + '" data-swiper-slide-index="' + i + '" style="width:100%;">' +
        '<div class="swiper-inner"><img src="' + esc(u) + '" alt=""></div></div>';
    }).join('');
    // Dots for the carousel. The real swiper library is not on this page, so the
    // strip stays decorative - but an empty pagination slot would collapse and
    // take the whole banner height with it.
    var dots = document.querySelector('.home-banner .swiper-pagination');
    if (dots && !dots.children.length) {
      dots.innerHTML = imgs.map(function (u, i) {
        return '<span class="swiper-pagination-bullet' + (i === 0 ? ' swiper-pagination-bullet-active' : '') + '"></span>';
      }).join('');
    }
  }
  function loadBanners() {
    if (!window.PXAPI) return;
    withRetry(function () { return PXAPI.announcements({ types: 'PR', platform: 'M' }); }, 3)
      .then(function (res) {
        var v = (res && res.value) || res || {};
        renderBanners(v.promotion || v.list || v.announcements || []);
      }).catch(function () {});
  }

  /* ---------- winner board (real go444 feed: GCSGAME_getRankList) ---------- */
  function renderWinners(list) {
    if (!winnerEl) return;
    // The relay wraps every row as { vo: {...} }; tolerate a bare row too.
    var rows = (list || []).map(function (it) { return it && it.vo ? it.vo : it; })
                          .filter(function (r) { return r && (r.gameName != null || r.customerName != null); });
    if (!rows.length) { winnerEl.innerHTML = ''; return; }
    winnerEl.innerHTML = rows.map(function (r) {
      var icon = val(r, ['iconUrl', 'mobileSmallIcon', 'smallIcon', 'h5Icon']);
      var amount = r.winAmount != null
        ? Number(r.winAmount).toLocaleString('en-US', { maximumFractionDigits: 2 })
        : '';
      return '<div class="winner-item swiper-slide" data-node="' + esc(val(r, ['nodeId', 'gameId'])) + '" ' +
        'data-gtype="' + esc(val(r, ['gameType'])) + '" data-vendor="' + esc(val(r, ['vendor', 'vassalage'])) + '">' +
        '<div class="swiper-inner">' +
          '<div class="winner-game">' +
            (icon ? '<img class="game-icon" src="' + esc(icon) + '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '') +
          '</div>' +
          '<div class="winner-info">' +
            '<div class="game-name">' + esc(val(r, ['gameName', 'nodeName'])) + '</div>' +
            '<span class="just-won"><span>' + esc(val(r, ['customerName', 'account'])) + '</span> just won</span>' +
            '<div class="winner-amount"><span class="symbol">৳</span>' + esc(amount) + '</div>' +
          '</div>' +
          '<div class="play-btn">খেলুন</div>' +
        '</div></div>';
    }).join('');
  }
  function loadWinners() {
    if (!winnerEl || !window.PXAPI) return;
    withRetry(function () {
      return PXAPI.winnerBoard({ gameCategory: 'ALL', language: META.language, limitNum: 50 });
    }, 3).then(function (res) {
      var d = (res && res.value) || res || {};
      renderWinners(d.list || d.records || d.rankList || []);
    }).catch(function () { renderWinners([]); });
  }

  /* ---------- game providers (real go444 feed: GCSGAME_newGameVendor) ---------- */
  function renderVendors(lanes) {
    var wrap = $('#vendor-icons');
    if (!wrap) return;
    var section = wrap.closest ? wrap.closest('.footer-vendor') : null;
    var seen = {};
    var out = [];
    // The feed returns every provider on the platform (80+ marks). The footer is
    // a strip, not a catalogue: keep the first MAX_VENDOR_ICONS so the page does
    // not end in a wall of logos.
    var MAX_VENDOR_ICONS = 24;
    (lanes || []).forEach(function (lane) {
      if (out.length >= MAX_VENDOR_ICONS) return;
      ((lane && lane.cards) || []).forEach(function (c) {
        if (out.length >= MAX_VENDOR_ICONS) return;
        var code = val(c, ['vassalage', 'accountTypeName', 'id']);
        if (!code || seen[code]) return;
        // Footer logos: prefer the small vendor mark (what the reference footer
        // uses), fall back to the bigger game art only if that is all we get.
        var icon = val(c, ['smallIcon', 'mobileSmallIcon', 'h5Icon', 'lagerIcon', 'mobileLargeIcon']);
        if (!icon) return;
        seen[code] = 1;
        // onerror: a dead/blocked logo must not leave a broken-image glyph +
        // alt text sitting in the footer.
        out.push('<img class="vendor-img" src="' + esc(icon) + '" alt="' +
          esc(val(c, ['displayName', 'title'])) + '" loading="lazy" referrerpolicy="no-referrer" ' +
          'onerror="this.style.display=&quot;none&quot;">');
      });
    });
    wrap.innerHTML = out.join('');
    // No providers at all -> hide the section instead of an empty heading.
    if (section) section.classList.toggle('is-empty', out.length === 0);
  }
  function loadVendors() {
    var wrap = $('#vendor-icons');
    if (!wrap || !window.PXAPI) return;
    withRetry(function () {
      return PXAPI.gameVendors({
        merchant: META.merchant, platform: META.platform,
        clientType: META.clientType, language: META.language
      });
    }, 3).then(function (res) {
      var d = (res && res.value) || res || {};
      var lanes = (d.data && d.data.lanes) || d.lanes || [];
      state.lanes = lanes;
      renderProviders();
      renderVendors(lanes);
    }).catch(function () {});
  }

  /* ---------- session (guest / auth) ---------- */
  // The bottom-nav account tab ships href="/m/login" for guests; a signed-in
  // visitor belongs in the member centre, so keep the href in step with the
  // session instead of sending members back to a login form.
  function syncAccountLink() {
    var authed = document.body.classList.contains('is-auth');
    var link = $('.footer-member');
    if (link) link.setAttribute('href', authed ? (link.getAttribute('data-auth-href') || '/m/member/home') : '/m/login');
    // Log out is only meaningful with a session.
    var out = $('#px-menu-logout');
    if (out) out.hidden = !authed;
  }
  function setGuest() {
    document.body.classList.add('is-guest');
    document.body.classList.remove('is-auth');
    syncAccountLink();
  }
  function setAuth() {
    document.body.classList.remove('is-guest');
    document.body.classList.add('is-auth');
    syncAccountLink();
  }
  // The upstream answers /wps/member/info with success even for anonymous
  // visitors (an empty/placeholder payload). Only treat the visitor as logged
  // in when the payload actually carries a member identity - otherwise a
  // guest was shown the logged-in header with a bogus 0.00 balance.
  function hasIdentity(d) {
    if (!d || typeof d !== 'object') return false;
    var probe = d.memberInfo || d.member || d.userInfo || d;
    if (!probe || typeof probe !== 'object') return false;
    var keys = ['memberId', 'customerId', 'userId', 'memberCode', 'memberNo',
      'customerNo', 'memberAccount', 'loginId', 'loginName', 'username',
      'nickname', 'account', 'mobile', 'mobileNum', 'phone', 'email'];
    for (var i = 0; i < keys.length; i++) {
      var v = probe[keys[i]];
      if (v != null && v !== '' && v !== 0 && v !== '0') return true;
    }
    return false;
  }
  function hydrateSession() {
    if (!window.PXAPI) return;
    PXAPI.memberInfo().then(function (res) {
      var d = (res && res.value) || res || {};
      if (!hasIdentity(d)) { setGuest(); return; }
      setAuth();
      var nEl = $('#hdr-name'); var nm = d.nickname || d.username || d.mobile || '';
      if (nEl && nm) nEl.textContent = nm;
      refreshBalance();
    }).catch(setGuest);
  }

  /* ---------- live balance ----------
     member/info carries identity, not money — the amount lives on the funds
     endpoints. hydrateSession() runs once per load, so without this the
     header froze at whatever it first painted (or 0.00) until a reload.
     Poll the dedicated balance endpoint while signed in: on an interval,
     and immediately when the page becomes visible again (back from a game,
     vendor tab, or bfcache restore — exactly when the balance moved). */
  var BALANCE_EVERY_MS = 30000;
  var balanceTimer = null;
  var balanceBusy = false;

  // Both shapes the backend uses: wallets/balance
  // (value.sumBalance + balance[accountTypeId=2].availBalance) and
  // funds/consolidated fallbacks — first numeric hit wins.
  function extractBalance(res) {
    var v = (res && res.value) || res || {};
    function num(x) { return (typeof x === 'number' && isFinite(x)) ? x : null; }
    var direct = num(v.availBalance) != null ? num(v.availBalance)
      : num(v.availableBalance) != null ? num(v.availableBalance)
      : num(v.sumBalance) != null ? num(v.sumBalance)
      : num(v.sum) != null ? num(v.sum)
      : num(v.totalBalance) != null ? num(v.totalBalance)
      : num(v.balance) != null ? num(v.balance) : null;
    if (direct != null) return direct;
    var list = v.balance;
    if (Array.isArray(list)) {
      for (var i = 0; i < list.length; i++) {
        var b = list[i] || {};
        if ((b.accountTypeId === 2 || b.accountTypeId === '2') && num(b.availBalance) != null) {
          return num(b.availBalance);
        }
      }
      for (var j = 0; j < list.length; j++) {
        var c = list[j] || {};
        var hit = num(c.availBalance) != null ? num(c.availBalance)
          : num(c.availableBalance) != null ? num(c.availableBalance)
          : num(c.balance);
        if (hit != null) return hit;
      }
    } else if (list && typeof list === 'object') {
      var o = num(list.availBalance) != null ? num(list.availBalance)
        : num(list.availableBalance) != null ? num(list.availableBalance)
        : num(list.balance);
      if (o != null) return o;
    }
    return null;
  }

  function paintBalance(amount) {
    if (amount == null) return;
    var bEl = $('#hdr-balance');
    if (bEl) bEl.textContent = Number(amount).toLocaleString('en-US', { maximumFractionDigits: 2 });
  }

  function refreshBalance() {
    if (!window.PXAPI || !PXAPI.balance) return;
    // Guests have no balance; a request already in flight must not stack.
    if (!document.body.classList.contains('is-auth') || balanceBusy) return;
    if (document.hidden) return;
    balanceBusy = true;
    PXAPI.balance().then(function (res) {
      paintBalance(extractBalance(res));
    }).catch(function () {
      /* keep the last painted value; the next tick retries */
    }).then(function () {
      balanceBusy = false;
    });
  }

  function startBalancePolling() {
    stopBalancePolling();
    balanceTimer = setInterval(refreshBalance, BALANCE_EVERY_MS);
  }
  function stopBalancePolling() {
    if (balanceTimer) { clearInterval(balanceTimer); balanceTimer = null; }
  }

  /* ---------- wiring ---------- */
  function go(url) { location.href = url; }

  /* ---------- slide-in menu (the header hamburger) ----------
     The menu is our own, but its items are the upstream's own pages: the proxy
     renders them with the same HTML/backend, so this is the bridge from the
     custom home into the real site. */
  var menuPanel = $('#px-menu'), menuScrim = $('#px-menu-scrim');
  function openMenu() {
    if (!menuPanel) return;
    menuPanel.hidden = false;
    if (menuScrim) menuScrim.hidden = false;
    document.body.classList.add('px-menu-open');
  }
  function closeMenu() {
    if (!menuPanel || menuPanel.hidden) return;
    menuPanel.hidden = true;
    if (menuScrim) menuScrim.hidden = true;
    document.body.classList.remove('px-menu-open');
  }
  // Both header variants (guest and member) carry a hamburger; only one is ever
  // visible, and a toggle means either of them works.
  Array.prototype.forEach.call(document.querySelectorAll('.header-menu'), function (btn) {
    btn.addEventListener('click', function () {
      if (menuPanel && menuPanel.hidden) openMenu(); else closeMenu();
    });
  });
  if (menuScrim) menuScrim.addEventListener('click', closeMenu);
  var menuClose = $('#px-menu-close');
  if (menuClose) menuClose.addEventListener('click', closeMenu);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });

  /* ---------- promotions ----------
     The upstream owns its own promotion/activity page (/m/activity) and the
     proxy serves it with the same backend this page reads its banners from, so
     send the visitor there instead of to a placeholder on this page. */
  function goPromotions() {
    closeMenu();
    go('/m/activity');
  }

  /* ---------- share ---------- */
  function copyText(text, done) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { /* nothing else to try */ }
    ta.remove();
  }
  function shareSite() {
    closeMenu();
    var url = location.origin + '/';
    if (navigator.share) {
      navigator.share({ title: document.title, text: 'Play on BBC99', url: url }).catch(function () {});
      return;
    }
    var done = function () { toast('Link copied. Share it with friends!', 'ok'); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(done, function () { copyText(url, done); });
    } else {
      copyText(url, done);
    }
  }
  var menuShare = $('#px-menu-share');
  if (menuShare) menuShare.addEventListener('click', shareSite);

  /* ---------- log out ---------- */
  function logout() {
    closeMenu();
    PXAPI.logout().catch(function () {}).then(function () { location.href = '/m/login'; });
  }
  var menuLogout = $('#px-menu-logout');
  if (menuLogout) menuLogout.addEventListener('click', logout);

  var loginBtn = $('#hdr-login'), regBtn = $('#hdr-register');
  if (loginBtn) loginBtn.addEventListener('click', function () { go('/m/login'); });
  if (regBtn)   regBtn.addEventListener('click',   function () { go('/m/register'); });

  // Where an account action leads depends on the session: a guest has to log in
  // first, a member goes straight into the upstream member centre, which is the
  // proxied page that owns deposit, withdraw, cards and records. Resolved at
  // click time, after the session has been hydrated.
  function requireSession(url) {
    return document.body.classList.contains('is-auth') ? url : '/m/login';
  }

  var dep = $('#hdr-deposit'), wd = $('#hdr-withdraw');
  if (dep) dep.addEventListener('click', function () { go(requireSession('/m/voucherCenter')); });
  if (wd)  wd.addEventListener('click',  function () { go(requireSession('/m/withdraw')); });

  var navDeposit = $('#nav-deposit'), navPromo = $('#nav-promo'), navShare = $('#nav-share');
  if (navDeposit) navDeposit.addEventListener('click', function (e) { e.preventDefault(); go(requireSession('/m/voucherCenter')); });
  if (navPromo)   navPromo.addEventListener('click',   function (e) { e.preventDefault(); goPromotions(); });
  if (navShare)   navShare.addEventListener('click',   function (e) { e.preventDefault(); shareSite(); });

  /* ---------- opening a game ----------
     Two ways, and the admin panel picks which. The in-app window (/m/game) keeps
     the player on the site and is what puts a Back button on every game page;
     the direct hand-off sends the tab straight to the vendor.

     Whichever route runs, the launch relay is called exactly ONCE - by the page
     that owns the game. Launching here first and again in the shell would be
     refused by the backend (#734 "the previous game has not finished"), so the
     in-app route hands over the game's identity and lets the shell launch it. */
  function openGame(nodeId, vendor, gtype, label) {
    if (!nodeId) { toast('Game unavailable.', 'error'); return; }
    var s = (window.PXAPI && PXAPI.gameSettings) || {};
    if (s.in_app !== false) {
      var qs = 'node=' + encodeURIComponent(nodeId);
      if (vendor) qs += '&vendor=' + encodeURIComponent(vendor);
      if (gtype)  qs += '&gtype=' + encodeURIComponent(gtype);
      if (label)  qs += '&name=' + encodeURIComponent(label);
      location.href = '/m/game?' + qs;
      return;
    }
    if (!window.PXAPI) return;
    PXAPI.launchGame({
      gameType: gtype, vassalage: vendor,
      gameId: nodeId, nodeId: nodeId, platform: META.platform
    }).then(function (res) {
      // The play URL is nested under value.content.game_url; PXAPI.gameUrl reads
      // every shape the relay uses.
      var url = (window.PXAPI && PXAPI.gameUrl) ? PXAPI.gameUrl(res) : '';
      if (url) {
        location.href = url;
      } else {
        toast('Could not open the game. Please try again.', 'error');
      }
    }).catch(function (err) {
      // The backend can refuse a launch (a round left open, a maintenance
      // window), and a silent catch left the tap looking broken - report it.
      toast((err && err.message) || 'Could not open the game. Please try again.', 'error');
    });
  }

  // Both the game grid (.game-list-item) and the winner board (.winner-item)
  // carry data-node.
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('.game-list-item, .winner-item') : null;
    if (!a || !window.PXAPI) return;
    // The grid tile carries the game's name; the shell uses it as its title so
    // the open game is identifiable while it loads.
    var nameEl = a.querySelector ? a.querySelector('.game-item-name, .game-name') : null;
    openGame(
      a.getAttribute('data-node'),
      a.getAttribute('data-vendor') || '',
      a.getAttribute('data-gtype') || '',
      nameEl ? nameEl.textContent.trim() : ''
    );
  });

  /* ---------- scroll reveal ----------
     The blocks below the fold arrive as they are reached instead of all being
     there from first paint. Sections the visitor has already scrolled past are
     shown immediately, and a browser without IntersectionObserver just keeps
     everything visible - the reveal is an extra, never a gate. */
  function revealOnScroll() {
    var blocks = Array.prototype.slice.call(document.querySelectorAll(
      '.home-content, .winner-board, #app-download-bar, .home-footer-container'));
    if (!blocks.length) return;

    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var show = function (el) { el.classList.remove('px-reveal'); el.classList.add('is-revealed'); };

    if (reduce || !('IntersectionObserver' in window)) { blocks.forEach(show); return; }

    // Anything already on screen (or within a screen of it) shows right away -
    // only what is genuinely below the fold waits for the visitor.
    var fold = window.innerHeight * 1.05;
    var waiting = blocks.filter(function (el) {
      if (el.getBoundingClientRect().top < fold) { show(el); return false; }
      el.classList.add('px-reveal');
      return true;
    });
    if (!waiting.length) return;

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { show(e.target); io.unobserve(e.target); }
      });
    }, { rootMargin: '0px 0px -6% 0px', threshold: .04 });
    waiting.forEach(function (el) { io.observe(el); });
  }

  /* ---------- jackpot roller (the reference's home/Jackpot + JackpotNum) ----
     A random value from the jackpot range climbs by 100-500 taka every 3-5
     seconds, and every digit strip rolls to its new position over one second.
     Offsets are written in --jp-step (rem) units, so a rotation or resize
     needs no re-measuring. */
  function startJackpot() {
    var root = document.getElementById('home-roll-num');
    if (!root) return;
    var strips = root.querySelectorAll('.scroll-num');
    if (strips.length !== 8) return;

    var MIN = 88888888, MAX = 99999999;
    var value = MIN + Math.floor(Math.random() * (MAX - MIN));

    function paint(n) {
      var s = String(n);
      while (s.length < 8) s = '0' + s;
      for (var i = 0; i < strips.length; i++) {
        strips[i].style.transform =
          'translateY(calc(-' + s.charAt(i) + ' * var(--jp-step)))';
      }
    }

    // The markup ships with a value already in place, so this first paint rolls
    // the strips from it into the session's number.
    paint(value);

    (function tick() {
      setTimeout(function () {
        value += 100 + Math.floor(Math.random() * 401);   // +100..500
        if (value > MAX) value = MIN + (value - MAX);
        paint(value);
        tick();
      }, 3000 + Math.floor(Math.random() * 3) * 1000);   // 3s / 4s / 5s
    })();
  }

  /* ---------- admin panel settings ----------
     Applied once, before anything renders: a disabled category must not flash
     into the tab strip, and a disabled grid must not start loading games. */
  function applySettings() {
    var s = (window.PXAPI && PXAPI.gameSettings) || {};
    if (s.page_size) {
      PAGE_SIZE = Math.max(4, Math.min(120, parseInt(s.page_size, 10) || 24));
    }
    gamesEnabled = s.enabled !== false;
    if (!gamesEnabled) {
      var wrap = document.querySelector('.gameEnter-wrap');
      if (wrap) wrap.hidden = true;
    }
  }

  function boot() {
    renderMenu();
    syncFilters();
    wireSearch();
    loadWinners();
    loadVendors();
    if (gamesEnabled) loadGames(true);
    loadBanners();
    startJackpot();
    hydrateSession();
    startBalancePolling();
    // Back from a game / vendor tab / bfcache: refresh immediately instead
    // of waiting for the next tick — this is when the balance just moved.
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) refreshBalance();
    });
    window.addEventListener('focus', refreshBalance);
    window.addEventListener('pageshow', refreshBalance);
    revealOnScroll();
    if (dlWrap) dlWrap.style.display = '';
  }

  function init() {
    document.body.classList.add('is-guest');
    if (window.PXAPI && PXAPI.loadSettings) {
      PXAPI.loadSettings().then(function () { applySettings(); boot(); });
      return;
    }
    boot();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
