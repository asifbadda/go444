/* ==========================================================================
   PXAPI — thin client for the upstream backend, called through the proxy.
   Endpoints and verbs mirror the upstream registry; encrypted endpoints use the
   upstream-supplied window.reRsaV2() handshake (load /js/encrypt.js first).

   Only API wiring lives here. UI lives in app.js.
   ========================================================================== */
(function (global) {
  'use strict';

  // Same origin: these pages are served by the proxy, so '/wps/...' is proxied
  // to the upstream backend automatically.
  var BASE = '';

  /* ---------- clear the upstream's affiliate referral state ----------
     An earlier build seeded localStorage.reg_info with the site's own
     referral code on every proxied page. The upstream reads that as "this
     visitor arrived through an affiliate" and answers by sending them to that
     affiliate's landing page on a foreign domain, so a visitor who had
     visited a proxied page could be bounced off our site. The proxy no longer
     seeds it; this drops whatever an earlier visit (or a ?r= link) left
     behind, so the state cannot survive on our own pages either. Our register
     form posts referralCode itself. */
  try {
    var _reg = localStorage.getItem('reg_info');
    if (_reg) {
      var _o = JSON.parse(_reg);
      if (!_o || typeof _o !== 'object') {
        localStorage.removeItem('reg_info');
      } else {
        if (_o.referralCode != null) delete _o.referralCode;
        if (_o.affiliateCode != null) delete _o.affiliateCode;
        if (_o.inviteCode != null) delete _o.inviteCode;
        localStorage.setItem('reg_info', JSON.stringify(_o));
      }
    }
  } catch (e) { /* storage unavailable: nothing to clean */ }

  var ENDPOINTS = {
    login:           { link: '/wps/session/login',                   method: 'POST', encrypt: true },
    logout:          { link: '/wps/session/logout',                  method: 'POST' },
    register:        { link: '/wps/member/register',                 method: 'PUT',  encrypt: true },
    registerMobile:  { link: '/wps/member/register/mobile',          method: 'PUT',  encrypt: true },
    registerAuto:    { link: '/wps/member/register/autoUsername',    method: 'PUT' },
    registerSetting: { link: '/wps/system/setting/register',         method: 'GET' },
    countryCode:     { link: '/wps/system/country',                  method: 'GET' },
    sendSms:         { link: '/wps/verification/sms/register',       method: 'POST' },
    sendLoginSms:    { link: '/wps/verification/sms/noLogin',        method: 'POST' },
    captcha:         { link: '/wps/captcha',                          method: 'GET' },
    captchaGeetest:  { link: '/wps/captcha/geetest',                 method: 'GET' },
    memberInfo:      { link: '/wps/member/info',                     method: 'GET' },
    balance:         { link: '/wps/member/info/funds/consolidated',  method: 'GET' },

    // Game catalogue (same relays the upstream app calls).
    // gameList is a GET relay: the upstream reads every filter from the query
    // string (merchant, platform, gameType, pageNo, pageSize, ...).
    gameList:        { link: '/wps/relay/GCSGAME_gameList',          method: 'GET'  },
    hotGames:        { link: '/wps/relay/GCSGAME_hotGamesV2',        method: 'POST' },
    gameVendors:     { link: '/wps/relay/GCSGAME_newGameVendor',     method: 'GET'  },
    // Category menu: the mobile app asks the game relay which vendors exist per
    // game type (RNG/LIVE/...); value.content maps gameType -> { vendorCode: [] }.
    gameMenus:       { link: '/wps/relay/GCSGAME_getVassGameType',   method: 'GET'  },
    gameTypes:       { link: '/wps/relay/GCSGAME_getVassGameType',   method: 'GET'  },
    // Latest-winners feed shown in the home winner board. The upstream home
    // calls getRankList with { gameCategory, language, limitNum }.
    winnerBoard:     { link: '/wps/relay/GCSGAME_getRankList',       method: 'GET'  },
    launchGame:      { link: '/wps/game/launchGame',                 method: 'GET'  },
    announcements:   { link: '/wps/relay/CCSFE_getListAnnouncements', method: 'GET' },
    // Rewards: lists are read through the local aggregator; claims are
    // mutations and go straight to the relay (POST, like upstream).
    claimTicket:     { link: '/wps/relay/PROMOFE_claimTicket',         method: 'POST' },
    claimIssued:     { link: '/wps/relay/MCSFE_claimIssuedPromotion',  method: 'POST' },
    claimLogin:      { link: '/wps/relay/MCSFE_claimLoginPromotion',   method: 'POST' },
    cancelTicket:    { link: '/wps/relay/MCSFE_cancelTicket',          method: 'POST' }
  };

  // The upstream backend expects a "Merchant" context header on most calls.
  // Set it once for the whole site, in priority order:
  //   window.PX_MERCHANT  >  <meta name="px-merchant">  >  localStorage.px_merchant
  function merchant() {
    if (global.PX_MERCHANT) return global.PX_MERCHANT;
    var meta = document.querySelector('meta[name="px-merchant"]');
    if (meta && meta.content) return meta.content;
    try { return localStorage.getItem('px_merchant') || ''; } catch (e) { return ''; }
  }

  /* ---------- auth token (upstream uses Authorization header, not cookies) ----------
     Upstream login answers { success, value: { token, ... } } and stores it as
     sessionStorage "token" (desktop) / "MC_SESSION_INFO" (mobile). Every later
     call sends `Authorization: <token>`. Without this the login succeeds but
     the next memberInfo call is anonymous, so the UI flips straight back to
     guest — the reported "login not working". */
  function getToken() {
    try {
      var t = sessionStorage.getItem('token');
      if (t) return t;
      var info = sessionStorage.getItem('MC_SESSION_INFO');
      if (info) {
        var o = JSON.parse(info);
        if (o && o.token) return o.token;
      }
      var login = sessionStorage.getItem('login');
      if (login) {
        var l = JSON.parse(login);
        if (l && l.token) return l.token;
      }
    } catch (e) {}
    return '';
  }

  function setToken(token, rawValue) {
    try {
      if (!token) {
        sessionStorage.removeItem('token');
        sessionStorage.removeItem('MC_SESSION_INFO');
        sessionStorage.removeItem('login');
        // Clearing the session also ends a "remember me" login: the copy that
        // survives the tab must not outlive the logout that ended it.
        localStorage.removeItem('px_remember_token');
        return;
      }
      sessionStorage.setItem('token', token);
      // Mobile-convention copy so upstream-shaped readers keep working.
      var info = { token: token };
      if (rawValue && typeof rawValue === 'object') {
        if (rawValue.id != null) info.id = rawValue.id;
        if (rawValue.userName != null) info.userName = rawValue.userName;
        if (rawValue.customerType != null) info.type = rawValue.customerType;
        if (rawValue.firstTimeLogin != null) info.firstTimeLogin = rawValue.firstTimeLogin;
      }
      sessionStorage.setItem('MC_SESSION_INFO', JSON.stringify(info));
      sessionStorage.setItem('login', JSON.stringify({ token: token, value: rawValue || {} }));
    } catch (e) {}
  }

  /* ---------- loginDeviceId (upstream anti-fraud device UUID) ----------
     Upstream injects `loginDeviceId` into every login/register payload
     (desktop vendor K() wrapper, mobile le()/de()). It is a persisted UUID
     stored as localStorage "SHELL_deviceId". Without it the backend may
     reject the login or flag the session. */
  function uuid() {
    try {
      if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
    } catch (e) {}
    try {
      if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
        return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, function (c) {
          var n = +c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (+c / 4)));
          return n.toString(16);
        });
      }
    } catch (e) {}
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  function isUuid(s) {
    return typeof s === 'string' && s.length === 36;
  }

  function loginDeviceId() {
    var key = 'SHELL_deviceId';
    try {
      var v = localStorage.getItem(key);
      if (isUuid(v)) return v;
    } catch (e) {}
    var fresh = uuid();
    try { localStorage.setItem(key, fresh); } catch (e) {}
    return fresh;
  }

  function withDeviceId(payload, force) {
    var p = payload || {};
    if (!p.loginDeviceId || force) {
      try { p.loginDeviceId = loginDeviceId(); } catch (e) {}
    }
    return p;
  }

  function headers(extra) {
    var h = {
      'Accept': 'application/json, text/plain, */*',
      'Content-Type': 'application/json;charset=UTF-8'
    };
    var m = merchant();
    // Send exactly ONE Merchant header. Duplicating it (e.g. Merchant + merchant)
    // makes the upstream gateway treat the call as an unauthenticated/foreign
    // domain request and answer 400 "function.not.available", which is what
    // blanked the games menu.
    if (m) { h['Merchant'] = m; }
    // The upstream app always sends these two as well.
    h['Device'] = (global.PX_DEVICE || 'web');
    var lang = 'en';
    try { lang = localStorage.getItem('hisLang') || 'en'; } catch (e) {}
    h['Language'] = lang;
    // Session token: upstream sends Authorization on every authenticated call
    // (vendor z() interceptor reads sessionStorage "token"). Missing it is why
    // a successful login still showed the guest UI.
    var tok = getToken();
    if (tok) { h['Authorization'] = tok; }
    // Upstream also tags requests with the real UA (X-Real-UA interceptor).
    try {
      if (typeof navigator !== 'undefined' && navigator.userAgent && typeof window.btoa === 'function') {
        h['X-Real-UA'] = window.btoa(navigator.userAgent);
      }
    } catch (e) {}
    if (extra) { for (var k in extra) { if (extra[k] != null) h[k] = extra[k]; } }
    return h;
  }

  /**
   * Encrypt a payload exactly like the upstream app:
   *   s = await window.reRsaV2(payload)   ->  { RSA, DES, rsaKey }
   *   header  Encryption: s.RSA, X-Digest: s.DES, X-RSA: s.rsaKey
   *   body    { value: s.DES }
   * (Upstream ae() wrapper in kmXh sends all three headers; sending only
   * Encryption works on some builds but the full set matches every build.)
   * If encrypt.js isn't loaded (e.g. it failed to proxy), fall back to plain
   * JSON so the request still goes out and the backend can report the error.
   * Returns a Promise that resolves to { body, extra }.
   */
  function encryptPayload(payload) {
    if (typeof global.reRsaV2 === 'function') {
      try {
        return global.reRsaV2(payload).then(function (s) {
          if (s && (s.DES || s.RSA)) {
            var extra = { Encryption: s.RSA };
            // Upstream ae() also sends the DES digest + raw RSA key.
            if (s.DES) { extra['X-Digest'] = s.DES; }
            if (s.rsaKey) { extra['X-RSA'] = s.rsaKey; }
            else if (s.RSAKEY) { extra['X-RSA'] = s.RSAKEY; }
            return { body: { value: s.DES }, extra: extra };
          }
          return { body: payload, extra: {} };
        }).catch(function () {
          return { body: payload, extra: {} };
        });
      } catch (e) {
        return Promise.resolve({ body: payload, extra: {} });
      }
    }
    return Promise.resolve({ body: payload, extra: {} });
  }

  /** Serialise a flat params object into a query string (arrays repeat the key). */
  function toQuery(obj) {
    var parts = [];
    for (var k in obj) {
      var v = obj[k];
      if (v == null || v === '') continue;
      if (Array.isArray(v)) {
        for (var i = 0; i < v.length; i++) {
          if (v[i] != null && v[i] !== '') parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v[i]));
        }
      } else {
        parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
      }
    }
    return parts.join('&');
  }

  /* ---------- site settings (admin panel) ----------
     The panel owns the game switches: whether the grid shows, which language a
     game opens in, whether games open in the in-app window (the one with the
     Back button), how many games a page holds, which tabs and vendors are
     offered, and which games are hidden. /api/gameSettings.php is a small local
     JSON endpoint, so this stays a single cheap call per page load and is
     cached in the module afterwards. If it cannot be read at all, the defaults
     below are exactly the previous behaviour - the site must not depend on the
     panel being reachable. */
  var gameSettings = {
    enabled:   true,
    language:  'EN',
    in_app:    true,
    back_url:  '/m/home',
    page_size: 24,
    types:     {},
    vendors:   {},
    hidden:    []
  };
  var settingsPromise = null;

  function loadSettings() {
    if (settingsPromise) return settingsPromise;
    settingsPromise = fetch(BASE + '/api/gameSettings.php', {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    }).then(function (r) {
      return r.ok ? r.json() : null;
    }).catch(function () {
      return null;
    }).then(function (j) {
      var g = (j && (j.games || (j.success ? j.games : null))) || null;
      if (g && typeof g === 'object') {
        for (var k in gameSettings) {
          if (Object.prototype.hasOwnProperty.call(g, k) && g[k] != null) {
            gameSettings[k] = g[k];
          }
        }
      }
      return gameSettings;
    });
    return settingsPromise;
  }

  /** A code is offered unless the settings say otherwise (absent = enabled). */
  function allowed(map, code) {
    if (!map || typeof map !== 'object') return true;
    if (!Object.prototype.hasOwnProperty.call(map, code)) return true;
    return !!map[code];
  }

  function request(ep, payload) {
    if (typeof ep === 'string') ep = ENDPOINTS[ep];
    if (!ep) return Promise.reject(new Error('Unknown endpoint'));

    var body = payload || {};
    var extra = {};
    var epLink = ep.link || '';

    // Normalise auth payloads to what the upstream actually expects.
    // Upstream mobile login sends { username, password, type, loginDeviceId, ... }
    // (see m/app.* handleLogin), NOT { mobile }. Accept `mobile`/`mobileNum` as
    // an alias so old callers keep working, but always send `username`.
    if (epLink === '/wps/session/login') {
      if (body.username == null || body.username === '') {
        var alias = body.mobile != null ? body.mobile : body.mobileNum;
        if (alias != null && alias !== '') body.username = alias;
      }
      if (body.type == null || body.type === '') body.type = 'username';
      body = withDeviceId(body);
    } else if (epLink === '/wps/member/register' || epLink === '/wps/member/register/mobile') {
      body = withDeviceId(body);
    }

    return Promise.resolve().then(function () {
      if (ep.encrypt) {
        return encryptPayload(body).then(function (enc) {
          body = enc.body;
          extra = enc.extra;
        });
      }
    }).then(function () {
      var init = {
        method: ep.method || 'GET',
        headers: headers(extra),
        credentials: 'include'
      };

      var url = BASE + ep.link;
      if (init.method === 'GET') {
        var qs = toQuery(body);
        if (qs) url += (url.indexOf('?') === -1 ? '?' : '&') + qs;
      } else {
        init.body = JSON.stringify(body);
      }

      function handleResponse(res) {
        return res.text().then(function (text) {
          var data;
          try { data = text ? JSON.parse(text) : {}; }
          catch (e) { data = { raw: text }; }

          if (!res.ok) {
            var err = new Error((data && (data.message || data.errorCode)) || ('HTTP ' + res.status));
            err.status = res.status;
            err.data = data;
            throw err;
          }
          if (data && data.success === false) {
            var e2 = new Error(data.message || data.errorCode || 'Request failed');
            e2.data = data;
            throw e2;
          }
          return data;
        });
      }

      return fetch(url, init).then(function (res) {
        // A stored session token upstream no longer accepts (401
        // login.not.session - "logged out from another device") blanks every
        // feed on /m/home: gameList, rank list, vendors and announcements all
        // answer 401 and the page loads forever. Nothing stored can refresh it
        // (no credentials), so drop the dead token and retry ONCE as guest -
        // the public game feeds answer 200 without an Authorization header.
        var staleSession = res.status === 401
          && init.headers && init.headers['Authorization']
          && epLink.indexOf('/session/login') === -1
          && epLink.indexOf('/session/logout') === -1;
        if (staleSession) {
          delete init.headers['Authorization'];
          try { setToken(''); } catch (e) {}
          return fetch(url, init).then(handleResponse);
        }
        return handleResponse(res);
      });
    });
  }

  /* The launch relay hands back the vendor's play URL nested under
     value.content.game_url:
       { success:true, value:{ content:{ game_url:"https://..." } } }
     Older/other builds put it directly on value (url/gameUrl/link/launchUrl).
     Return the first usable URL from any of those shapes. */
  function extractGameUrl(res) {
    if (!res) return '';
    if (typeof res === 'string') return res;
    var v = res.value || res;
    if (typeof v === 'string') return v;
    var c = (v && v.content) || {};
    return v.url || v.gameUrl || v.link || v.launchUrl
        || c.game_url || c.gameUrl || c.url || '';
  }

  global.PXAPI = {
    ENDPOINTS: ENDPOINTS,
    request: request,
    getToken: getToken,
    setToken: setToken,
    loginDeviceId: loginDeviceId,
    login:           function (p) {
      return request('login', p).then(function (data) {
        // Persist the session token exactly like upstream (oe()/loginInfo):
        // value.token -> sessionStorage, sent as Authorization afterwards.
        try {
          var v = (data && data.value) || {};
          if (v.token) setToken(v.token, v);
        } catch (e) {}
        return data;
      });
    },
    logout:          function ()  {
      return request('logout').catch(function (e) { throw e; }).then(
        function (d) { try { setToken(''); } catch (e) {} return d; },
        function (e) { try { setToken(''); } catch (x) {} throw e; }
      );
    },
    register:        function (p) { return request('register', p); },
    registerMobile:  function (p) { return request('registerMobile', p); },
    registerSetting: function ()  { return request('registerSetting'); },
    countryCode:     function ()  { return request('countryCode'); },
    sendSms:         function (p) { return request('sendSms', p); },
    sendLoginSms:    function (p) { return request('sendLoginSms', p); },
    captcha:         function ()  { return request('captcha', { t: Date.now() }); },
    // The upstream answers { success, value: "<base64 png>" } and binds the image
    // to the captchaId cookie it sets, so this must never be served from a cache
    // (the proxy marks the path no-store; the timestamp defeats the browser's).
    // Returns a data URL ready for an <img src>, or '' when there is nothing
    // usable - some builds nest the payload under value.img / value.image.
    captchaImage:    function ()  {
      return request('captcha', { t: Date.now() }).then(function (d) {
        var v = d && d.value;
        var raw = typeof v === 'string' ? v
                : (v && (v.img || v.image || v.base64 || v.captcha)) || '';
        if (!raw) return '';
        return raw.indexOf('data:') === 0 ? raw : 'data:image/png;base64,' + raw;
      });
    },
    captchaGeetest:  function ()  { return request('captchaGeetest'); },
    memberInfo:      function ()  { return request('memberInfo'); },
    balance:         function ()  { return request('balance'); },
    /* Member dashboard aggregator (local, same-origin): one call fans out
       server-side to the upstream calls the member page needs. Sends the
       session (Authorization + cookies) like every other PXAPI call; a 401
       means guest or dead session. A stored token the aggregator rejects
       is dropped and retried once as guest, mirroring request(). */
    memberSummary:   function ()  {
      function call(withAuth) {
        var h = headers();
        if (!withAuth) { delete h['Authorization']; }
        return fetch(BASE + '/api/memberSummary.php', {
          headers: h,
          credentials: 'include'
        }).then(function (res) {
          return res.text().then(function (text) {
            var data;
            try { data = text ? JSON.parse(text) : {}; }
            catch (e) { data = {}; }
            if (res.status === 401 && withAuth && h['Authorization']) {
              try { setToken(''); } catch (e) {}
              return call(false);
            }
            if (!res.ok) {
              var err = new Error((data && (data.error || data.message)) || ('HTTP ' + res.status));
              err.status = res.status;
              err.data = data;
              throw err;
            }
            return data;
          });
        });
      }
      return call(true);
    },
    gameList:        function (p) { return request('gameList', p); },
    hotGames:        function (p) { return request('hotGames', p); },
    gameVendors:     function (p) { return request('gameVendors', p); },
    gameMenus:       function (p) { return request('gameMenus', p); },
    gameTypes:       function (p) { return request('gameTypes', p); },
    winnerBoard:     function (p) { return request('winnerBoard', p); },
    // The launch relay answers HTTP 500 "#727 system busy" for every game when
    // the request omits `launchMode` - the one field whose absence made every
    // tap look broken while the backend was perfectly healthy. `accountType`
    // then selects the real-money wallet (1) over the free-play one (0); the
    // upstream client always sends both, so default them here for every caller.
    launchGame:      function (p) {
      var q = p || {};
      if (q.launchMode == null) q.launchMode = 'GLS';
      if (q.accountType == null) q.accountType = 1;
      // Vendors localise the game from this field; omit it and most of them
      // open in Chinese (JILI answers lang=zh-CN). Default to the language the
      // panel selected (English out of the box); a caller can still override.
      if (q.language == null) q.language = gameSettings.language || 'EN';
      return request('launchGame', q);
    },
    gameUrl:         extractGameUrl,
    loadSettings:    loadSettings,
    settingsAllowed: allowed,
    gameSettings:    gameSettings,
    announcements:   function (p) { return request('announcements', p); },
    /* Rewards dashboard aggregator (local, same-origin). Same session +
       stale-token handling as memberSummary. */
    rewardsSummary:  function ()  {
      function call(withAuth) {
        var h = headers();
        if (!withAuth) { delete h['Authorization']; }
        return fetch(BASE + '/api/rewardsSummary.php', {
          headers: h,
          credentials: 'include'
        }).then(function (res) {
          return res.text().then(function (text) {
            var data;
            try { data = text ? JSON.parse(text) : {}; }
            catch (e) { data = {}; }
            if (res.status === 401 && withAuth && h['Authorization']) {
              try { setToken(''); } catch (e) {}
              return call(false);
            }
            if (!res.ok) {
              var err = new Error((data && (data.error || data.message)) || ('HTTP ' + res.status));
              err.status = res.status;
              err.data = data;
              throw err;
            }
            return data;
          });
        });
      }
      return call(true);
    },
    claimTicket:     function (p) { return request('claimTicket', p); },
    claimIssued:     function (p) { return request('claimIssued', p); },
    claimLogin:      function (p) { return request('claimLogin', p); },
    cancelTicket:    function (p) { return request('cancelTicket', p); },
    /* Daily sign-in aggregator (local, same-origin). Same session +
       stale-token handling as memberSummary. */
    signinSummary:   function ()  {
      function call(withAuth) {
        var h = headers();
        if (!withAuth) { delete h['Authorization']; }
        return fetch(BASE + '/api/signinSummary.php', {
          headers: h,
          credentials: 'include'
        }).then(function (res) {
          return res.text().then(function (text) {
            var data;
            try { data = text ? JSON.parse(text) : {}; }
            catch (e) { data = {}; }
            if (res.status === 401 && withAuth && h['Authorization']) {
              try { setToken(''); } catch (e) {}
              return call(false);
            }
            if (!res.ok) {
              var err = new Error((data && (data.error || data.message)) || ('HTTP ' + res.status));
              err.status = res.status;
              err.data = data;
              throw err;
            }
            return data;
          });
        });
      }
      return call(true);
    }
  };
})(window);
