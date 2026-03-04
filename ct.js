/*
 * Clima Tracker v2.0 — Unified Tracking Script
 * Hosted on: https://clima-dashboard.web.app/ct.js
 *
 * Config via window._ct = { type, fb, oid, ... }
 * Types: "landing", "checkout", "upsell", "thankyou"
 */
(function(){
  var C = window._ct;
  if (!C || !C.type || !C.fb) return;
  var FB = C.fb;
  var P = new URLSearchParams(location.search);
  var WORKER = 'https://ipqs-proxy.labibahmed32.workers.dev';

  /* Shared: detect affiliate params from any URL */
  function _detectAff() {
    return P.get('aff_id') || P.get('affid') || P.get('hop') || P.get('affiliate') || P.get('vendor') || P.get('tid') || P.get('affiliate_id') || '';
  }
  /* Shared: capture all URL params as object */
  function _allParams() {
    var obj = {};
    P.forEach(function(v, k) { if (v) obj[k] = v.substr(0, 200); });
    return obj;
  }
  /* Shared: extract BuyGoods URL parameters */
  function _extractBGData() {
    return {
      email: P.get('emailaddress') || '',
      name: P.get('creditcards_name') || '',
      address: P.get('address') || '',
      city: P.get('city') || '',
      zip: P.get('zip') || '',
      phone: P.get('phone') || '',
      country: P.get('country') || '',
      orderId: P.get('order_id') || '',
      orderIdGlobal: P.get('order_id_global') || '',
      total: P.get('total') || ''
    };
  }
  /* Generate stable ID from email (same email = same ID) */
  function _stableHash(str) {
    var hash = 0;
    for (var i = 0; i < str.length; i++) {
      var char = str.charCodeAt(i);
      hash = ((hash << 5) - hash) + char;
      hash = hash & hash;
    }
    return Math.abs(hash).toString(36);
  }

  /* ===== LANDING PAGE ===== */
  if (C.type === 'landing') {
    var OID = C.oid || '';
    var VCODES = C.vcodes || '';
    var VMAP = C.vmap || {};
    var linkDomain = C.linkDomain || '';
    var sid = localStorage.getItem('_ct_sid');
    if (!sid) { sid = 'v' + Date.now().toString(36) + Math.random().toString(36).substr(2, 5); localStorage.setItem('_ct_sid', sid); }
    var aff = P.get('aff_id') || P.get('affid') || localStorage.getItem('_ct_aff') || '';
    if (aff) localStorage.setItem('_ct_aff', aff);
    var sub1 = P.get('sub1') || P.get('subid') || '', sub2 = P.get('sub2') || '';
    var ua = navigator.userAgent, mob = /Mobile|Android|iPhone/i.test(ua);
    var br = (/Chrome/i.test(ua) ? 'Chrome' : /Firefox/i.test(ua) ? 'Firefox' : /Safari/i.test(ua) ? 'Safari' : 'Other');
    var os = (/Windows/i.test(ua) ? 'Windows' : /Mac/i.test(ua) ? 'Mac' : /Android/i.test(ua) ? 'Android' : /iPhone|iPad/i.test(ua) ? 'iOS' : /Linux/i.test(ua) ? 'Linux' : 'Other');
    var scrollMax = 0, startTime = Date.now(), buyClicked = false, variant = '';
    var today = new Date(), ds = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    var ipData = { ip: '', ipv4: '', ipv6: '', country: '', city: '', isp: '', countryCode: '', region: '', zip: '', lat: 0, lon: 0, timezone: '', asn: '', languages: '' };
    function _setGeo(d) {
      ipData.ip = d.ip || ipData.ip; ipData.country = d.country || ''; ipData.countryCode = d.countryCode || d.country_code || '';
      ipData.region = d.region || ''; ipData.city = d.city || ''; ipData.zip = d.zip || d.postal || '';
      ipData.lat = d.lat || d.latitude || 0; ipData.lon = d.lon || d.longitude || 0;
      ipData.timezone = d.timezone || ((d.timezone && d.timezone.id) ? d.timezone.id : '');
      ipData.isp = d.isp || d.org || ''; ipData.asn = d.asn || '';
      if (d.ip && d.ip.indexOf(':') > -1) ipData.ipv6 = d.ip; else if (d.ip) ipData.ipv4 = d.ip;
    }
    /* PRIMARY: Cloudflare Worker geo (own server, free, unlimited, worldwide) */
    var PROXY = C.proxy || '';
    fetch(WORKER + '?geo=1').then(function(r) { return r.json() }).then(function(d) {
      if (d.ip && d.country) { _setGeo(d); _ctSend(); return; }
      throw 'no geo';
    }).catch(function() {
      /* Fallback: ipwho.is */
      fetch('https://ipwho.is/').then(function(r) { return r.json() }).then(function(d) {
        if (d.ip && d.success !== false) {
          ipData.ip = d.ip; ipData.country = d.country || ''; ipData.countryCode = d.country_code || '';
          ipData.region = d.region || ''; ipData.city = d.city || '';
          ipData.timezone = (d.timezone && d.timezone.id) ? d.timezone.id : '';
          ipData.isp = (d.connection && d.connection.isp) ? d.connection.isp : '';
          ipData.asn = (d.connection && d.connection.asn) ? 'AS' + d.connection.asn : '';
          if (d.ip.indexOf(':') > -1) ipData.ipv6 = d.ip; else ipData.ipv4 = d.ip;
        }
        _ctSend();
      }).catch(function() { _ctSend(); });
    });
    /* Capture both IPv4 and IPv6 in parallel */
    fetch('https://api.ipify.org?format=json').then(function(r) { return r.json() }).then(function(d) {
      ipData.ipv4 = d.ip || ''; if (!ipData.ip) ipData.ip = d.ip || '';
    }).catch(function() {});
    fetch('https://api64.ipify.org?format=json').then(function(r) { return r.json() }).then(function(d) {
      if (d.ip && d.ip.indexOf(':') > -1) ipData.ipv6 = d.ip;
      else if (!ipData.ipv4) ipData.ipv4 = d.ip || '';
      if (!ipData.ip) ipData.ip = d.ip || '';
    }).catch(function() {});
    var _fp = { tz: (typeof Intl !== 'undefined') ? Intl.DateTimeFormat().resolvedOptions().timeZone : '',
      lang: navigator.language || '', scr: screen.width + 'x' + screen.height,
      plat: navigator.platform || '', cores: navigator.hardwareConcurrency || 0,
      touch: 'ontouchstart' in window, cookies: navigator.cookieEnabled, dnt: navigator.doNotTrack || '' };
    window.addEventListener('scroll', function() {
      var h = document.documentElement, b = document.body;
      var st = h.scrollTop || b.scrollTop, sh = h.scrollHeight || b.scrollHeight, ch = h.clientHeight;
      var pct = Math.round(st / (sh - ch) * 100) || 0;
      if (pct > scrollMax) scrollMax = pct;
    });
    var platform = '';
    function _detectPlatform(href) {
      if (/buygoods\.com/i.test(href)) return 'buygoods';
      if (/digistore24?\./i.test(href)) return 'digistore';
      if (/clickbank\.(com|net)/i.test(href)) return 'clickbank';
      return '';
    }
    document.addEventListener('click', function(e) {
      var t = e.target.closest('a,button');
      if (!t) return;
      var txt = (t.textContent || '').trim().toLowerCase();
      var href = t.getAttribute('href') || '';
      if (linkDomain && href.indexOf(linkDomain) > -1) {
        buyClicked = true;
        platform = _detectPlatform(href);
        if (platform) localStorage.setItem('_ct_platform', platform);
        if (VCODES) {
          var m = href.match(/checkout\/([^\/?]+)/) || href.match(/product_codename=([^&]+)/);
          if (m && VCODES.indexOf(m[1]) > -1) {
            variant = m[1]; localStorage.setItem('_ct_var', variant);
            if (VMAP[variant]) localStorage.setItem('_ct_amount', VMAP[variant].amount || 0);
          }
        }
        localStorage.setItem('_ct_oid', OID);
      } else if (!linkDomain && /buy|order|get yours|add to cart|rush my/i.test(txt)) {
        buyClicked = true;
        platform = _detectPlatform(href);
        if (platform) localStorage.setItem('_ct_platform', platform);
        localStorage.setItem('_ct_oid', OID);
      }
      _ctLog('click', { el: t.tagName, text: txt.substr(0, 50), href: href.substr(0, 120) });
    });
    function _ctLog(action, extra) {
      var d = { action: action, ts: Date.now() };
      if (extra) for (var k in extra) d[k] = extra[k];
      fetch(FB + '/tracker/visitors/' + ds + '/' + sid + '/clicks.json', { method: 'POST', body: JSON.stringify(d) });
    }
    if (linkDomain) {
      setTimeout(function() {
        var links = document.querySelectorAll('a[href*="' + linkDomain + '"]');
        for (var i = 0; i < links.length; i++) {
          var h = links[i].getAttribute('href');
          if (h.indexOf('subid=') === -1) { links[i].setAttribute('href', h + (h.indexOf('?') === -1 ? '?' : '&') + 'subid=' + sid); }
        }
      }, 2000);
    }
    function _ctSend() {
      var d = { ua: ua.substr(0, 200), device: mob ? 'mobile' : 'desktop', browser: br, os: os,
        ref: document.referrer.substr(0, 200), url: location.href.substr(0, 300),
        affId: aff, subId: sid, sub1: sub1, sub2: sub2, offerId: OID,
        scrollMax: scrollMax, timeOnPage: Math.round((Date.now() - startTime) / 1000),
        buyClicked: buyClicked, variant: variant, fingerprint: _fp, ts: Date.now() };
      if (platform) d.platform = platform;
      if (ipData.ip) d.ip = ipData.ip;
      if (ipData.ipv4) d.ipv4 = ipData.ipv4;
      if (ipData.ipv6) d.ipv6 = ipData.ipv6;
      if (ipData.country) d.country = ipData.country;
      if (ipData.countryCode) d.countryCode = ipData.countryCode;
      if (ipData.region) d.region = ipData.region;
      if (ipData.city) d.city = ipData.city;
      if (ipData.zip) d.zip = ipData.zip;
      if (ipData.lat) d.lat = ipData.lat;
      if (ipData.lon) d.lon = ipData.lon;
      if (ipData.timezone) d.timezone = ipData.timezone;
      if (ipData.isp) d.isp = ipData.isp;
      if (ipData.asn) d.asn = ipData.asn;
      fetch(FB + '/tracker/visitors/' + ds + '/' + sid + '.json', { method: 'PATCH', body: JSON.stringify(d), keepalive: true });
    }
    _ctSend();
    document.addEventListener('visibilitychange', function() { if (document.visibilityState === 'hidden') _ctSend(); });
    window.addEventListener('pagehide', function() { _ctSend(); });
  }

  /* ===== CHECKOUT PAGE ===== */
  if (C.type === 'checkout') {
    var PROXY = C.proxy || '', IPQS_KEY = C.ipqsKey || '', PC_KEY = C.pcKey || '', ABUSE_KEY = C.abuseKey || '';
    var sid = C.sid || P.get('subid') || P.get('sub_id') || P.get('aff_sub') || '';
    if (!sid) sid = 'chk' + Date.now().toString(36) + Math.random().toString(36).substr(2, 4);
    /* Save sid to localStorage for upsell/thankyou pages on same domain */
    try { localStorage.setItem('_ct_sid', sid); } catch(e) {}
    var startT = Date.now();
    var data = { ts: Date.now(), purchaseClicked: false, urlParams: _allParams() };
    var fraud = { ts: Date.now() };
    var fp = { tz: (typeof Intl !== 'undefined') ? Intl.DateTimeFormat().resolvedOptions().timeZone : '',
      lang: navigator.language || '', scr: screen.width + 'x' + screen.height,
      plat: navigator.platform || '', cores: navigator.hardwareConcurrency || 0,
      touch: 'ontouchstart' in window, cookies: navigator.cookieEnabled, dnt: navigator.doNotTrack || '' };
    data.fingerprint = fp; fraud.fingerprint = fp;
    /* Detect affiliate from URL params */
    var urlAff = _detectAff();
    if (urlAff) { data.affFromUrl = urlAff; try { localStorage.setItem('_ct_aff', urlAff); } catch(e) {} }
    /* Extract BuyGoods data from URL parameters */
    var bgData = _extractBGData();
    if (bgData.email) data.email = bgData.email;
    if (bgData.name) data.name = bgData.name;
    if (bgData.address) data.address = bgData.address;
    if (bgData.city) data.city = bgData.city;
    if (bgData.zip) data.zip = bgData.zip;
    if (bgData.phone) data.phone = bgData.phone;
    if (bgData.country) data.billingCountry = bgData.country;
    var ipGeo = { ipv4: '', ipv6: '' };
    function _runIPChecks() {
      if (!ipGeo.ip) return;
      var roll = Math.floor(Math.random() * 10) + 1;
      var doIPQS = roll <= 4 || roll >= 9, doPC = (roll >= 5 && roll <= 6) || roll >= 9, doAbuse = (roll >= 7 && roll <= 8) || roll >= 9;
      fraud.checkMode = roll >= 9 ? 'full' : roll <= 4 ? 'ipqs' : roll <= 6 ? 'proxycheck' : 'abuseipdb';
      if (doIPQS && PROXY && IPQS_KEY) {
        fetch(PROXY + '?key=' + IPQS_KEY + '&ip=' + ipGeo.ip).then(function(r) { return r.json() }).then(function(d) {
          fraud.ipqs = d; _saveFraud();
        }).catch(function() {});
      }
      if (doPC && PC_KEY) {
        fetch('https://proxycheck.io/v2/' + ipGeo.ip + '?key=' + PC_KEY + '&vpn=1&asn=1&risk=1')
          .then(function(r) { return r.json() }).then(function(pc) {
            fraud.proxyCheck = pc[ipGeo.ip] || {}; _saveFraud();
          }).catch(function() {});
      }
      if (doAbuse && ABUSE_KEY && PROXY) {
        fetch(PROXY + '?abusekey=' + ABUSE_KEY + '&abuseip=' + ipGeo.ip)
          .then(function(r) { return r.json() }).then(function(d) {
            if (d && d.data) fraud.abuseipdb = d.data; _saveFraud();
          }).catch(function() {});
      }
    }
    function _setGeoChk(d) {
      ipGeo.ip = d.ip || ipGeo.ip; ipGeo.country = d.country || ''; ipGeo.countryCode = d.countryCode || d.country_code || '';
      ipGeo.region = d.region || ''; ipGeo.city = d.city || ''; ipGeo.zip = d.zip || d.postal || '';
      ipGeo.lat = d.lat || d.latitude || 0; ipGeo.lon = d.lon || d.longitude || 0;
      ipGeo.timezone = d.timezone || ((d.timezone && d.timezone.id) ? d.timezone.id : '');
      ipGeo.isp = d.isp || d.org || ''; ipGeo.asn = d.asn || '';
      if (d.ip && d.ip.indexOf(':') > -1) ipGeo.ipv6 = d.ip; else if (d.ip) ipGeo.ipv4 = d.ip;
    }
    /* PRIMARY: Cloudflare Worker geo — runs IMMEDIATELY on page load */
    fetch(WORKER + '?geo=1').then(function(r) { return r.json() }).then(function(d) {
      if (d.ip && d.country) { _setGeoChk(d); data.ipGeo = ipGeo; fraud.ipGeo = ipGeo; _runIPChecks(); _ctSend(); return; }
      throw 'no geo';
    }).catch(function() {
      fetch('https://ipwho.is/').then(function(r) { return r.json() }).then(function(d) {
        if (d.ip && d.success !== false) {
          ipGeo.ip = d.ip; ipGeo.country = d.country || ''; ipGeo.countryCode = d.country_code || '';
          ipGeo.region = d.region || ''; ipGeo.city = d.city || '';
          ipGeo.timezone = (d.timezone && d.timezone.id) ? d.timezone.id : '';
          ipGeo.isp = (d.connection && d.connection.isp) ? d.connection.isp : '';
          ipGeo.asn = (d.connection && d.connection.asn) ? 'AS' + d.connection.asn : '';
          if (d.ip.indexOf(':') > -1) ipGeo.ipv6 = d.ip; else ipGeo.ipv4 = d.ip;
        }
        data.ipGeo = ipGeo; fraud.ipGeo = ipGeo; _runIPChecks(); _ctSend();
      }).catch(function() { _runIPChecks(); });
    });
    /* Capture both IPv4 and IPv6 in parallel */
    fetch('https://api.ipify.org?format=json').then(function(r) { return r.json() }).then(function(d) {
      ipGeo.ipv4 = d.ip || ''; if (!ipGeo.ip) { ipGeo.ip = d.ip || ''; data.ipGeo = ipGeo; fraud.ipGeo = ipGeo; }
    }).catch(function() {});
    fetch('https://api64.ipify.org?format=json').then(function(r) { return r.json() }).then(function(d) {
      if (d.ip && d.ip.indexOf(':') > -1) ipGeo.ipv6 = d.ip;
      else if (!ipGeo.ipv4) ipGeo.ipv4 = d.ip || '';
      if (!ipGeo.ip) { ipGeo.ip = d.ip || ''; data.ipGeo = ipGeo; fraud.ipGeo = ipGeo; }
    }).catch(function() {});
    function _ctGrab() {
      var fields = document.querySelectorAll('input,select');
      for (var i = 0; i < fields.length; i++) {
        var f = fields[i], n = (f.name || f.id || '').toLowerCase(), v = f.value || '';
        if (!v) continue;
        if (n.indexOf('name') > -1 && !data.name) data.name = v.substr(0, 60);
        if ((n.indexOf('first') > -1 && n.indexOf('name') > -1) || n === 'firstname') data.firstName = v.substr(0, 40);
        if ((n.indexOf('last') > -1 && n.indexOf('name') > -1) || n === 'lastname') data.lastName = v.substr(0, 40);
        if (n.indexOf('email') > -1 && v.indexOf('@') > -1) data.email = v.substr(0, 80);
        if (n.indexOf('phone') > -1 || n.indexOf('tel') > -1) data.phone = v.substr(0, 20);
        if (n.indexOf('address') > -1 || n.indexOf('street') > -1) data.address = v.substr(0, 100);
        if (n.indexOf('city') > -1) data.city = v.substr(0, 40);
        if (n.indexOf('state') > -1 || n.indexOf('province') > -1) data.state = v.substr(0, 30);
        if (n.indexOf('zip') > -1 || n.indexOf('postal') > -1) data.zip = v.substr(0, 10);
        if (n.indexOf('country') > -1 && v.length <= 3) data.billingCountry = v.toUpperCase();
        if (n.indexOf('card') > -1 && n.indexOf('number') > -1 && v.length >= 12) {
          var digits = v.replace(/\D/g, '');
          data.cardBin = digits.substr(0, 6);
          data.cardLast3 = digits.substr(-3);
          var first = digits.charAt(0);
          data.cardType = first === '4' ? 'Visa' : first === '5' ? 'Mastercard' : first === '3' ? 'Amex' : 'Other';
        }
        if (n.indexOf('card') > -1 && (n.indexOf('exp') > -1 || n.indexOf('month') > -1 || n.indexOf('year') > -1)) {
          if (!data.cardExp) data.cardExp = '';
          data.cardExp += v + '/';
        }
      }
      /* Auto-build full name from firstName + lastName */
      if (!data.name && (data.firstName || data.lastName)) {
        data.name = ((data.firstName || '') + ' ' + (data.lastName || '')).trim();
      }
    }
    function _runEmailPhoneCheck() {
      if (!PROXY || !IPQS_KEY) return;
      if (data.email && !fraud._emailChecked) {
        fraud._emailChecked = true;
        fetch(PROXY + '?key=' + IPQS_KEY + '&email=' + encodeURIComponent(data.email)).then(function(r) { return r.json() }).then(function(d) {
          fraud.ipqsEmail = d; _saveFraud();
        }).catch(function() {});
      }
      if (data.phone && !fraud._phoneChecked) {
        fraud._phoneChecked = true;
        fetch(PROXY + '?key=' + IPQS_KEY + '&phone=' + encodeURIComponent(data.phone)).then(function(r) { return r.json() }).then(function(d) {
          fraud.ipqsPhone = d; _saveFraud();
        }).catch(function() {});
      }
    }
    function _saveFraud() {
      fetch(FB + '/tracker/fraud-checks/' + sid + '.json', { method: 'PATCH', body: JSON.stringify(fraud), keepalive: true });
    }
    /* Buy button click handler */
    document.addEventListener('click', function(e) {
      var t = e.target.closest('button,a,input[type=submit]');
      if (!t) return;
      var txt = (t.textContent || t.value || '').toLowerCase();
      if (/buy|complete|place order|submit|pay|rush my order/i.test(txt)) {
        data.purchaseClicked = true;
        data.purchaseClickTs = Date.now();
        data.checkoutDuration = Math.round((Date.now() - startT) / 1000);
        try { localStorage.setItem('_ct_checkout_done', '1'); } catch(e2) {}
        _ctGrab();
        /* Capture fresh URL params at buy moment */
        data.urlParams = _allParams();
        _ctSend();
        _runEmailPhoneCheck();
        _saveFraud();
      }
    });
    function _ctSend() {
      _ctGrab();
      fetch(FB + '/tracker/checkout-sessions/' + sid + '.json', { method: 'PATCH', body: JSON.stringify(data), keepalive: true });
    }
    /* Stream form data every 5 seconds as user fills fields */
    var _streamInterval = setInterval(function() {
      _ctGrab();
      if (data.name || data.email || data.phone) {
        _ctSend();
        /* Run email/phone fraud checks as soon as we have them */
        _runEmailPhoneCheck();
      }
    }, 5000);
    /* Initial grab after 3s */
    setTimeout(function() { _ctGrab(); _ctSend(); }, 3000);
    /* Save on page leave */
    document.addEventListener('visibilitychange', function() { if (document.visibilityState === 'hidden') { clearInterval(_streamInterval); _ctSend(); _saveFraud(); } });
    window.addEventListener('pagehide', function() { clearInterval(_streamInterval); _ctSend(); _saveFraud(); });
  }

  /* ===== UPSELL PAGE ===== */
  if (C.type === 'upsell') {
    var UPSELL_NUM = C.unum || 1;
    var VMAP = C.vmap || {};
    var sid = localStorage.getItem('_ct_sid') || '';
    var bgData = _extractBGData();
    var email = bgData.email;
    /* Fallback: use email as stable identifier (same email = same sid) */
    if (!sid && email) {
      sid = 'em_' + _stableHash(email);
      try { localStorage.setItem('_ct_sid', sid); } catch(e) {}
    }
    if (!sid) sid = P.get('subid') || '';
    var orderId = P.get('order_id_global') || P.get('order_id') || P.get('cbreceipt') || '';
    if (!sid) return;
    /* Save BuyGoods data to checkout session if we have it */
    if (bgData.email || bgData.name || bgData.phone || bgData.address) {
      var chkData = {};
      if (bgData.email) chkData.email = bgData.email;
      if (bgData.name) chkData.name = bgData.name;
      if (bgData.address) chkData.address = bgData.address;
      if (bgData.city) chkData.city = bgData.city;
      if (bgData.zip) chkData.zip = bgData.zip;
      if (bgData.phone) chkData.phone = bgData.phone;
      if (bgData.country) chkData.billingCountry = bgData.country;
      if (bgData.total) chkData.total = bgData.total;
      if (orderId) chkData.orderId = orderId;
      fetch(FB + '/tracker/checkout-sessions/' + sid + '.json', { method: 'PATCH', body: JSON.stringify(chkData), keepalive: true });
    }
    /* Detect affiliate from URL */
    var urlAff = _detectAff();
    if (urlAff) { try { localStorage.setItem('_ct_aff', urlAff); } catch(e) {} }
    /* Reaching upsell1 = purchase confirmed. orderId is optional (BuyGoods may not pass it). */
    if (UPSELL_NUM == 1) {
      var saleKey = localStorage.getItem('_ct_saleKey') || '';
      /* If no saleKey but we have email, try to find existing sale by email */
      if (!saleKey && email) {
        try { saleKey = localStorage.getItem('_ct_saleKey_' + email) || ''; } catch(e) {}
      }
      if (!saleKey) {
        /* Create new sale only if no existing sale found */
        var aff = localStorage.getItem('_ct_aff') || '';
        var oid = localStorage.getItem('_ct_oid') || '';
        var variant = localStorage.getItem('_ct_var') || '';
        var amount = 0;
        if (variant && VMAP[variant]) amount = VMAP[variant].amount || 0;
        if (!amount) amount = parseFloat(localStorage.getItem('_ct_amount')) || 0;
        if (!amount && bgData.total) amount = parseFloat(bgData.total) || 0;
        if (!amount) amount = parseFloat(P.get('total')) || 0;
        var today = new Date(), ds = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
        saleKey = 'S' + Date.now().toString(36) + Math.random().toString(36).substr(2, 4);
        var plat = localStorage.getItem('_ct_platform') || '';
        var sale = { subId: sid, affId: aff, offerId: oid, variant: variant,
          orderId: orderId, amount: amount, platform: plat, date: ds, ts: Date.now(),
          status: 'approved', source: 'script' };
        if (urlAff) sale.affFromUrl = urlAff;
        fetch(FB + '/tracker/sales/' + saleKey + '.json', { method: 'PUT', body: JSON.stringify(sale) });
        try {
          localStorage.setItem('_ct_sale_' + sid, '1');
          localStorage.setItem('_ct_saleKey', saleKey);
          if (email) localStorage.setItem('_ct_saleKey_' + email, saleKey);
        } catch(e) {}
      }
    }
    /* Update sale with orderId if we get it on later upsell pages */
    var sk = localStorage.getItem('_ct_saleKey') || '';
    /* Re-save saleKey on every upsell to prevent localStorage loss */
    if (sk) {
      try {
        localStorage.setItem('_ct_saleKey', sk);
        if (email) localStorage.setItem('_ct_saleKey_' + email, sk);
      } catch(e) {}
    }
    if (sk && orderId) {
      fetch(FB + '/tracker/sales/' + sk + '.json', { method: 'PATCH', body: JSON.stringify({ orderId: orderId }), keepalive: true });
    }
    /* Track upsell page load with all data */
    var upsellData = { num: UPSELL_NUM, ts: Date.now(), loaded: true };
    if (orderId) upsellData.orderId = orderId;
    upsellData.urlParams = _allParams();
    fetch(FB + '/tracker/upsell-events/' + sid + '/' + UPSELL_NUM + '.json', { method: 'PATCH', body: JSON.stringify(upsellData), keepalive: true });
    /* Click handler for accept/decline */
    document.addEventListener('click', function(e) {
      var t = e.target.closest('a,button');
      if (!t) return;
      var href = (t.getAttribute('href') || '').toLowerCase();
      var txt = (t.textContent || '').trim().toLowerCase();
      var action = '';
      if (href.indexOf('buygoods.com/secure/upsell') > -1 || /yes|accept|upgrade|add|buy/i.test(txt)) action = 'accept';
      else if (/no thank|skip|decline|no,? thank/i.test(txt)) action = 'decline';
      if (!action) return;
      /* Capture orderId from the clicked link if present */
      var clickHref = t.getAttribute('href') || '';
      var clickOrderId = '';
      try {
        var clickParams = new URLSearchParams(clickHref.split('?')[1] || '');
        clickOrderId = clickParams.get('order_id') || clickParams.get('order_id_global') || '';
      } catch(e2) {}
      var d = { num: UPSELL_NUM, action: action, ts: Date.now() };
      if (orderId) d.orderId = orderId;
      if (clickOrderId) d.clickOrderId = clickOrderId;
      fetch(FB + '/tracker/upsell-events/' + sid + '/' + UPSELL_NUM + '.json', { method: 'PATCH', body: JSON.stringify(d), keepalive: true });
      /* Also update sale with upsell info */
      if (sk) {
        var patch = {}; patch['upsell' + UPSELL_NUM] = action;
        if (orderId) patch['upsell' + UPSELL_NUM + 'OrderId'] = orderId;
        if (clickOrderId) patch['upsell' + UPSELL_NUM + 'OrderId'] = clickOrderId;
        fetch(FB + '/tracker/sales/' + sk + '.json', { method: 'PATCH', body: JSON.stringify(patch), keepalive: true });
      }
    });
  }

  /* ===== THANK YOU PAGE ===== */
  if (C.type === 'thankyou') {
    var VMAP = C.vmap || {};
    var sid = localStorage.getItem('_ct_sid') || C.sid || P.get('subid') || '';
    var bgData = _extractBGData();
    var email = bgData.email || P.get('emailaddress') || '';
    /* Fallback: use email as stable identifier (same email = same sid) */
    if (!sid && email) {
      sid = 'em_' + _stableHash(email);
      try { localStorage.setItem('_ct_sid', sid); } catch(e) {}
    }
    if (!sid) sid = 'ty' + Date.now().toString(36) + Math.random().toString(36).substr(2, 4);
    var aff = localStorage.getItem('_ct_aff') || '';
    var oid = localStorage.getItem('_ct_oid') || '';
    var variant = localStorage.getItem('_ct_var') || '';
    if (!variant) variant = P.get('product_codename') || '';
    var orderId = P.get('order_id_global') || P.get('order_id') || P.get('cbreceipt') || '';
    /* Detect affiliate from URL */
    var urlAff = _detectAff();
    if (urlAff) { aff = urlAff; try { localStorage.setItem('_ct_aff', urlAff); } catch(e) {} }
    /* Save BuyGoods data to checkout session if we have it */
    if (bgData.email || bgData.name || bgData.phone || bgData.address) {
      var chkData = {};
      if (bgData.email) chkData.email = bgData.email;
      if (bgData.name) chkData.name = bgData.name;
      if (bgData.address) chkData.address = bgData.address;
      if (bgData.city) chkData.city = bgData.city;
      if (bgData.zip) chkData.zip = bgData.zip;
      if (bgData.phone) chkData.phone = bgData.phone;
      if (bgData.country) chkData.billingCountry = bgData.country;
      if (bgData.total) chkData.total = bgData.total;
      if (orderId) chkData.orderId = orderId;
      fetch(FB + '/tracker/checkout-sessions/' + sid + '.json', { method: 'PATCH', body: JSON.stringify(chkData), keepalive: true });
    }
    /* Capture ALL page text — receipt details, order info, amounts etc */
    var pageText = '';
    try {
      setTimeout(function() {
        pageText = (document.body.innerText || '').substr(0, 8000);
        /* Extract useful data from page text */
        var tyData = { sid: sid, affId: aff, offerId: oid, variant: variant, orderId: orderId,
          ts: Date.now(), completed: true, urlParams: _allParams(), pageText: pageText };
        if (urlAff) tyData.affFromUrl = urlAff;
        /* Try to extract receipt/order IDs from page text */
        var receiptMatch = pageText.match(/receipt[:\s#]*([A-Z0-9]+)/i);
        if (receiptMatch) tyData.receiptId = receiptMatch[1];
        var totalMatch = pageText.match(/total[:\s$]*(\d+\.?\d*)/i);
        if (totalMatch) tyData.pageTotal = parseFloat(totalMatch[1]) || 0;
        fetch(FB + '/tracker/thankyou-events/' + sid + '.json', { method: 'PUT', body: JSON.stringify(tyData), keepalive: true });
      }, 2000);
    } catch(e) {}
    /* Update existing sale with orderId + thankyou data (immediate) */
    var sk = localStorage.getItem('_ct_saleKey') || '';
    /* If no saleKey but we have email, try to find existing sale by email */
    if (!sk && email) {
      try { sk = localStorage.getItem('_ct_saleKey_' + email) || ''; } catch(e) {}
    }
    /* Re-save saleKey to prevent loss */
    if (sk) {
      try {
        localStorage.setItem('_ct_saleKey', sk);
        localStorage.setItem('_ct_sale_' + sid, '1');
        if (email) {
          localStorage.setItem('_ct_saleKey_' + email, sk);
          localStorage.setItem('_ct_sale_' + email, '1');
        }
      } catch(e) {}
    }
    if (sk) {
      var patch = { thankyouTs: Date.now(), thankyouCompleted: true };
      if (orderId) patch.orderId = orderId;
      if (bgData.total) patch.bgTotal = parseFloat(bgData.total) || 0;
      if (bgData.email) patch.bgEmail = bgData.email;
      if (bgData.name) patch.bgName = bgData.name;
      if (bgData.phone) patch.bgPhone = bgData.phone;
      if (bgData.address) patch.bgAddress = bgData.address;
      if (bgData.city) patch.bgCity = bgData.city;
      if (bgData.zip) patch.bgZip = bgData.zip;
      if (bgData.country) patch.bgCountry = bgData.country;
      if (bgData.orderIdGlobal) patch.bgOrderIdGlobal = bgData.orderIdGlobal;
      if (bgData.orderId) patch.bgOrderId = bgData.orderId;
      if (P.get('product_codename')) patch.bgProduct = P.get('product_codename');
      if (P.get('account_id')) patch.bgAccountId = P.get('account_id');
      if (urlAff) patch.affFromUrl = urlAff;
      /* Capture all BuyGoods URL params into sale */
      patch.thankyouParams = _allParams();
      fetch(FB + '/tracker/sales/' + sk + '.json', { method: 'PATCH', body: JSON.stringify(patch), keepalive: true });
    }
    /* Smart fallback: If no saleKey in localStorage, try to find and update existing sale */
    if (!sk && sid) {
      /* Try to find sale by querying Firebase for today's sales with matching subId */
      var today = new Date(), ds = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
      fetch(FB + '/tracker/sales.json?orderBy="subId"&equalTo="' + sid + '"&limitToLast=1')
        .then(function(r) { return r.json(); })
        .then(function(sales) {
          if (sales && typeof sales === 'object') {
            var saleKeys = Object.keys(sales);
            if (saleKeys.length > 0) {
              /* Found existing sale! Update it */
              var foundSaleKey = saleKeys[0];
              var patch = { thankyouTs: Date.now(), thankyouCompleted: true };
              if (orderId) patch.orderId = orderId;
              if (bgData.total) patch.bgTotal = parseFloat(bgData.total) || 0;
              if (bgData.email) patch.bgEmail = bgData.email;
              if (bgData.name) patch.bgName = bgData.name;
              if (bgData.phone) patch.bgPhone = bgData.phone;
              if (bgData.address) patch.bgAddress = bgData.address;
              if (bgData.city) patch.bgCity = bgData.city;
              if (bgData.zip) patch.bgZip = bgData.zip;
              if (bgData.country) patch.bgCountry = bgData.country;
              if (bgData.orderIdGlobal) patch.bgOrderIdGlobal = bgData.orderIdGlobal;
              if (bgData.orderId) patch.bgOrderId = bgData.orderId;
              if (P.get('product_codename')) patch.bgProduct = P.get('product_codename');
              if (P.get('account_id')) patch.bgAccountId = P.get('account_id');
              if (urlAff) patch.affFromUrl = urlAff;
              patch.thankyouParams = _allParams();
              fetch(FB + '/tracker/sales/' + foundSaleKey + '.json', { method: 'PATCH', body: JSON.stringify(patch), keepalive: true });
              /* Save the found saleKey to localStorage for future use */
              try {
                localStorage.setItem('_ct_saleKey', foundSaleKey);
                if (email) localStorage.setItem('_ct_saleKey_' + email, foundSaleKey);
              } catch(e) {}
              if (typeof console !== 'undefined' && console.log) {
                console.log('[Clima Tracker] Found and updated existing sale via Firebase query:', foundSaleKey);
              }
            } else if (typeof console !== 'undefined' && console.warn) {
              console.warn('[Clima Tracker] No existing sale found for subId:', sid);
            }
          }
        })
        .catch(function(err) {
          if (typeof console !== 'undefined' && console.error) {
            console.error('[Clima Tracker] Failed to query Firebase for existing sale:', err);
          }
        });
    }
    /* Also send basic thankyou event immediately (page text comes 2s later) */
    var d = { sid: sid, affId: aff, offerId: oid, variant: variant, orderId: orderId, ts: Date.now(), completed: true, urlParams: _allParams() };
    if (urlAff) d.affFromUrl = urlAff;
    fetch(FB + '/tracker/thankyou-events/' + sid + '.json', { method: 'PATCH', body: JSON.stringify(d), keepalive: true });
  }

})();
