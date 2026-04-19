<?php
/**
 * Shaver - Cookie-Poll-Clear-Redirect (v3)
 * Loaded as: <script src="https://plan1.climaofficial.com/shaver/check.php?d=DOMAIN_KEY">
 */
header('Content-Type: application/javascript; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/config.php';

$domainKey = $_GET['d'] ?? '';
if (empty($domainKey)) {
    echo "console.warn('[Shaver] No domain key provided. Usage: check.php?d=DOMAIN_KEY');";
    exit;
}

try {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM domains WHERE domain_key = ? AND status = 'active'");
    $stmt->execute([$domainKey]);
    $domain = $stmt->fetch();
    if (!$domain) {
        echo "console.warn('[Shaver] Domain not found or inactive: " . addslashes($domainKey) . "');";
        exit;
    }
    $stmt = $pdo->prepare("SELECT id, aff_id, sub_id, mode, replace_aff_id, replace_sub_id FROM shaving_sessions WHERE domain_id = ? AND active = 1");
    $stmt->execute([$domain['id']]);
    $sessions = $stmt->fetchAll();
} catch (PDOException $e) {
    echo "console.error('[Shaver] DB error: " . addslashes($e->getMessage()) . "');";
    exit;
}

$domainId       = (int)$domain['id'];
$bgAccountId    = addslashes($domain['bg_account_id'] ?? '');
$bgProductCodes = addslashes($domain['bg_product_codes'] ?? '');
$bgConvToken    = addslashes($domain['bg_conversion_token'] ?? '');

$sessionsJson = json_encode(array_map(function($s) {
    return [
        'id'           => $s['id'],
        'affId'        => $s['aff_id'],
        'subId'        => $s['sub_id'] ?? '',
        'replaceMode'  => ($s['mode'] === 'replace'),
        'replaceAffId' => $s['replace_aff_id'] ?? '',
        'replaceSubId' => $s['replace_sub_id'] ?? '',
    ];
}, $sessions));

$proto  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$apiUrl = $proto . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['SCRIPT_NAME']) . '/api.php';
?>
(function () {
'use strict';

if (window.__shavingLoaded) return;
window.__shavingLoaded = true;

/* ── CONFIG ── */
var DOMAIN_ID   = <?php echo $domainId; ?>;
var DOMAIN_KEY  = '<?php echo addslashes($domainKey); ?>';
var API_URL     = '<?php echo $apiUrl; ?>';
var BG_ACCOUNT  = '<?php echo $bgAccountId; ?>';
var BG_PRODUCTS = '<?php echo $bgProductCodes; ?>';
var BG_TOKEN    = '<?php echo $bgConvToken; ?>';
var SESSIONS    = <?php echo $sessionsJson; ?>;

var POLL_MS     = 500;
var STABLE_SECS = 8;
var MAX_WAIT_MS = 15000;
var SHAVER_FLAG = '_shaver_cleaned';

console.log('[Shaver] Loaded', SESSIONS.length, 'sessions for', DOMAIN_KEY);

/* ── HELPERS ── */
function ReadCookie(name) {
    var n = name + '=', parts = document.cookie.split(/;\s*/);
    for (var i = 0; i < parts.length; i++) {
        if (parts[i].indexOf(n) === 0) return parts[i].substring(n.length);
    }
    return '';
}
window.ReadCookie = ReadCookie;

function ss(k, v)  { try { v === null ? sessionStorage.removeItem(k) : sessionStorage.setItem(k, String(v)); } catch(e){} }
function sg(k)     { try { return sessionStorage.getItem(k); } catch(e){ return null; } }

function getParams() {
    var p = {}, s = window.location.search.slice(1);
    if (!s) return p;
    s.split('&').forEach(function(pair) {
        var kv = pair.split('=');
        p[decodeURIComponent(kv[0])] = kv[1] ? decodeURIComponent(kv[1].replace(/\+/g,' ')) : '';
    });
    return p;
}

function isUpsellPage() {
    return /\/(upsell|thankyou|thank-you|thank_you|confirmation|order-confirmation)/i.test(window.location.pathname);
}

function xhrPost(action, data) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', API_URL, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.send(JSON.stringify(Object.assign({ action: action, domain_id: DOMAIN_ID }, data || {})));
    return xhr;
}

/* ── SESSION MATCH ── */
function findSession(affId, subId) {
    for (var i = 0; i < SESSIONS.length; i++) {
        var s = SESSIONS[i];
        if (s.affId === affId) {
            if (s.subId && s.subId !== subId) continue;
            return s;
        }
    }
    return null;
}

/* ── BOT DETECTION ── */
function getBotFlags() {
    var f = [];
    if (navigator.webdriver === true) f.push('webdriver');
    if (navigator.plugins.length === 0) f.push('no_plugins');
    if (!navigator.languages || !navigator.languages.length) f.push('no_languages');
    if (/Chrome/.test(navigator.userAgent) && !window.chrome) f.push('missing_chrome');
    if (/HeadlessChrome/.test(navigator.userAgent)) f.push('headless_chrome');
    if (window.callPhantom || window._phantom) f.push('phantomjs');
    return f;
}

/* ── BUYGOODS TRACKING ── */
function buildBGSrc(affId) {
    var sessid2 = ReadCookie('sessid2');
    return 'https://tracking.buygoods.com/track/?a=' + encodeURIComponent(BG_ACCOUNT)
        + '&firstcookie=' + (sessid2 ? '0' : '1')
        + '&aff_id=' + encodeURIComponent(affId || '')
        + '&referrer=' + encodeURIComponent(document.referrer)
        + '&sessid2=' + encodeURIComponent(sessid2)
        + '&product=' + encodeURIComponent(BG_PRODUCTS)
        + '&vid1=&vid2=&vid3='
        + '&caller_url=' + encodeURIComponent(window.location.href);
}

function injectBGTracking(affId) {
    if (!BG_ACCOUNT || !BG_PRODUCTS) return;
    var el = document.createElement('script');
    el.type = 'text/javascript'; el.src = buildBGSrc(affId);
    document.head.appendChild(el);
    console.log('[Shaver] BG tracking injected aff_id:', affId || '(none)');
}

/* Inject BG tracking and call callback once script loads (or times out after 5s) */
function injectBGTrackingThenWait(affId, callback) {
    if (!BG_ACCOUNT || !BG_PRODUCTS) { callback(); return; }
    var el = document.createElement('script');
    el.type = 'text/javascript'; el.src = buildBGSrc(affId);
    var done = false;
    var fallback = setTimeout(function() {
        if (!done) { done = true; console.log('[Shaver] BG tracking load timeout, proceeding'); callback(); }
    }, 5000);
    el.onload = el.onerror = function() {
        if (!done) { done = true; clearTimeout(fallback); console.log('[Shaver] BG tracking loaded'); callback(); }
    };
    document.head.appendChild(el);
    console.log('[Shaver] BG tracking injected aff_id:', affId || '(none)', '— waiting for load...');
}

function injectConversionIframe() {
    if (!BG_ACCOUNT || !BG_TOKEN) return;
    setTimeout(function () {
        var f = document.createElement('iframe');
        f.async = true; f.style.display = 'none';
        f.src = 'https://buygoods.com/affiliates/go/conversion/iframe/bg?a=' + BG_ACCOUNT + '&t=' + BG_TOKEN + '&s=' + ReadCookie('sessid2');
        document.body.appendChild(f);
    }, 1000);
}

/* ── SESSID2 LINK WATCHER ── */
function ensureSessid2OnLinks() {
    var sid = ReadCookie('sessid2') || new URLSearchParams(window.location.search).get('sessid2') || '';
    if (!sid) return;
    document.querySelectorAll('a[href*="buygoods.com"]').forEach(function(a) {
        try { var u = new URL(a.href); u.searchParams.set('sessid2', sid); a.href = u.toString(); } catch(e){}
    });
}

function startSessid2Watcher() {
    [300, 1500, 3000].forEach(function(ms) { setTimeout(ensureSessid2OnLinks, ms); });
    if (window.MutationObserver) {
        var obs = new MutationObserver(ensureSessid2OnLinks);
        obs.observe(document.body, { childList: true, subtree: true });
        setTimeout(function() { obs.disconnect(); }, 10000);
    }
    document.addEventListener('click', function(e) {
        var t = e.target;
        while (t && t !== document.body) {
            if (t.href && t.href.indexOf('buygoods.com') !== -1) { ensureSessid2OnLinks(); break; }
            t = t.parentElement;
        }
    });
}

/* ── COOKIE CLEAR ── */
function clearAllCookies() {
    var host = window.location.hostname;
    var parts = host.split('.');
    var domains = ['', host, '.' + host];
    for (var i = 1; i < parts.length; i++) { domains.push('.' + parts.slice(i).join('.')); }
    var paths = ['/', window.location.pathname, ''];
    var survived = [];
    document.cookie.split(';').map(function(c) { return c.trim().split('=')[0]; }).filter(Boolean).forEach(function(name) {
        var cleared = false;
        domains.forEach(function(d) {
            paths.forEach(function(p) {
                var str = name + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=' + (p||'/');
                if (d) str += '; domain=' + d;
                document.cookie = str;
                if (!ReadCookie(name)) cleared = true;
            });
        });
        if (!cleared) survived.push(name);
    });
    if (survived.length) console.log('[Shaver] Cookies survived:', survived.join(', '));
}

/* ── COOKIE POLL ── */
function waitForCookiesThenShave(session, affId, subId) {
    var start = Date.now(), lastCount = -1, stableStart = null;
    function check() {
        var count = document.cookie.split(';').filter(function(c){ return c.trim(); }).length;
        var now = Date.now();
        if (count !== lastCount) { lastCount = count; stableStart = now; }
        var stableMs = now - (stableStart || now);
        if (stableMs >= STABLE_SECS * 1000 || now - start >= MAX_WAIT_MS) {
            doShave(session, affId, subId);
        } else {
            setTimeout(check, POLL_MS);
        }
    }
    setTimeout(check, POLL_MS);
}

/* ── SNAPSHOT HELPER ── */
function getAllCookiesObj() {
    var obj = {};
    document.cookie.split(';').forEach(function(c) {
        var kv = c.trim().split('=');
        if (kv[0]) obj[kv[0]] = kv.slice(1).join('=');
    });
    return obj;
}
function getAllParams() {
    var p = {};
    try { new URLSearchParams(window.location.search).forEach(function(v,k){ p[k]=v; }); } catch(e){}
    return p;
}

/* ── DO SHAVE ── */
function doShave(session, affId, subId) {
    /* Before snapshot */
    xhrPost('log_shave_snapshot', {
        phase: 'before', session_id: session.id,
        aff_id: affId, sub_id: subId,
        mode: session.replaceMode ? 'replace' : 'remove',
        replace_aff_id: session.replaceAffId, replace_sub_id: session.replaceSubId,
        url: window.location.href, sessid2: ReadCookie('sessid2'),
        cookies: getAllCookiesObj(),
        cookie_count: document.cookie.split(';').filter(function(c){ return c.trim(); }).length,
        url_params: getAllParams()
    });

    /* Clear all cookies */
    clearAllCookies();

    /* Set loop prevention */
    ss(SHAVER_FLAG, '1');

    /* Build redirect URL */
    var url = new URL(window.location.href);
    if (session.replaceMode) {
        url.searchParams.set('aff_id', session.replaceAffId);
        if (session.replaceSubId) url.searchParams.set('subid', session.replaceSubId);
        else url.searchParams.delete('subid');
        console.log('[Shaver] REPLACE → aff_id:', session.replaceAffId);
    } else {
        ['aff_id','affid','subid','sub_id'].forEach(function(k){ url.searchParams.delete(k); });
        console.log('[Shaver] REMOVE → clean URL');
    }

    window.location.href = url.toString();
}

/* ── POST-REDIRECT FLOW ── */
function handlePostRedirect() {
    ss(SHAVER_FLAG, null);
    console.log('[Shaver] Post-redirect clean visit');

    setTimeout(function() {
        xhrPost('log_shave_snapshot', {
            phase: 'after',
            aff_id: sg('_shaver_aff_id') || '',
            sub_id: sg('_shaver_sub_id') || '',
            url: window.location.href,
            sessid2: ReadCookie('sessid2'),
            cookie_count: document.cookie.split(';').filter(function(c){ return c.trim(); }).length
        });
    }, 5000);

    var prParams = getParams();
    injectBGTracking(prParams.aff_id || prParams.affid || '');
    startSessid2Watcher();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', injectConversionIframe);
    else injectConversionIframe();
}

/* ── BEHAVIOR & TRAFFIC TRACKING ── */
var _bt = {
    sessionUUID: (function(){ var u=sg('_behavior_session_id'); if(!u){u='sess_'+Date.now()+'_'+Math.random().toString(36).substr(2,9); ss('_behavior_session_id',u);} return u; })(),
    trafficId: null, landedAt: Date.now(),
    maxScrollDepth: 0, clickCount: 0,
    hasCheckout: false, checkoutUrl: null, checkoutTime: null,
    firstClickTime: null, eventQueue: [],
    pageLoadTime: window.performance ? (window.performance.timing.loadEventEnd - window.performance.timing.navigationStart) : null
};
window.__behaviorTracking = _bt;

function logBehaviorEvent(type, data) {
    if (!_bt.trafficId) { _bt.eventQueue.push({type:type,data:data}); return; }
    xhrPost('log_behavior_event', { traffic_id:_bt.trafficId, session_uuid:_bt.sessionUUID, event_type:type, event_data:data, timestamp: new Date().toISOString() });
}

function logTraffic(affId, subId, wasShaved, sessionId) {
    if (!affId) return;
    var botFlags = getBotFlags();
    var xhr = new XMLHttpRequest();
    xhr.open('POST', API_URL, true);
    xhr.setRequestHeader('Content-Type','application/json');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var r = JSON.parse(xhr.responseText);
                if (r.success && r.traffic_id) {
                    _bt.trafficId = r.traffic_id;
                    _bt.eventQueue.forEach(function(ev){ logBehaviorEvent(ev.type, ev.data); });
                    _bt.eventQueue = [];
                }
            } catch(e){}
        }
    };
    xhr.send(JSON.stringify({ action:'log_traffic', domain_id:DOMAIN_ID, aff_id:affId, sub_id:subId,
        page_url:window.location.href, referrer:document.referrer||'direct', user_agent:navigator.userAgent,
        was_shaved:wasShaved?1:0, shaving_session_id:sessionId||null, session_uuid:_bt.sessionUUID,
        screen_width:window.screen.width, screen_height:window.screen.height,
        viewport_width:window.innerWidth, viewport_height:window.innerHeight,
        is_bot:botFlags.length>0?1:0, bot_flags:botFlags.join(',')||null,
        is_iframe:window.self!==window.top?1:0
    }));
}

function updateSessionMetrics() {
    if (!_bt.trafficId) return;
    var payload = { action:'update_session_metrics', traffic_id:_bt.trafficId,
        session_duration: Math.floor((Date.now()-_bt.landedAt)/1000),
        max_scroll_depth: _bt.maxScrollDepth, total_clicks: _bt.clickCount,
        reached_checkout: _bt.hasCheckout?1:0, checkout_url: _bt.checkoutUrl||null,
        time_to_first_click: _bt.firstClickTime ? Math.floor((_bt.firstClickTime-_bt.landedAt)/1000) : null,
        time_to_checkout: _bt.checkoutTime ? Math.floor((_bt.checkoutTime-_bt.landedAt)/1000) : null,
        screen_width:window.screen.width, screen_height:window.screen.height,
        viewport_width:window.innerWidth, viewport_height:window.innerHeight,
        page_load_time:_bt.pageLoadTime, bounce:_bt.clickCount===0?1:0
    };
    xhrPost('update_session_metrics', payload);
    if (navigator.sendBeacon) navigator.sendBeacon(API_URL, JSON.stringify(payload));
}

function setupBehaviorTracking() {
    /* Scroll milestones */
    var scrollTimer, lastDepth = 0;
    window.addEventListener('scroll', function() {
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(function() {
            var d = Math.min(100, Math.floor(((window.scrollY + window.innerHeight) / document.documentElement.scrollHeight) * 100));
            if (d > _bt.maxScrollDepth) {
                _bt.maxScrollDepth = d;
                [25,50,75,90].forEach(function(m){ if(d>=m&&lastDepth<m) logBehaviorEvent('scroll',{scrollDepth:m,milestone:true}); });
                lastDepth = d;
            }
        }, 300);
    });

    /* Click events */
    document.addEventListener('click', function(e) {
        var t = e.target;
        while (t && t !== document.body) {
            if (t.tagName === 'A' || t.tagName === 'BUTTON' ||
               (t.classList && (t.classList.contains('cp-btn') || t.classList.contains('mt-buy-now-btn')))) {
                _bt.clickCount++;
                if (!_bt.firstClickTime) _bt.firstClickTime = Date.now();
                logBehaviorEvent('click', {
                    buttonText:(t.textContent||'').trim().slice(0,100), buttonId:t.id||'',
                    targetUrl:(t.href||'').slice(0,200), clickX:e.clientX, clickY:e.clientY,
                    scrollDepthAtClick:_bt.maxScrollDepth, timeFromLanding:Math.floor((Date.now()-_bt.landedAt)/1000)
                });
                if (t.href && t.href.indexOf('buygoods.com') !== -1 && !_bt.hasCheckout) {
                    _bt.hasCheckout = true; _bt.checkoutUrl = t.href; _bt.checkoutTime = Date.now();
                    logBehaviorEvent('checkout_reached', {
                        checkoutUrl:t.href.slice(0,200),
                        timeToCheckout:Math.floor((Date.now()-_bt.landedAt)/1000),
                        scrollDepthAtCheckout:_bt.maxScrollDepth, clicksBeforeCheckout:_bt.clickCount
                    });
                }
                break;
            }
            t = t.parentElement;
        }
    });

    /* Hover on buy buttons */
    var hoverStart = null, hoveredBtn = null;
    document.addEventListener('mouseover', function(e) {
        var t = e.target;
        while (t && t !== document.body) {
            if (t.classList && (t.classList.contains('cp-btn') || t.classList.contains('mt-buy-now-btn'))) { hoverStart=Date.now(); hoveredBtn=t; break; }
            t = t.parentElement;
        }
    });
    document.addEventListener('mouseout', function() {
        if (hoveredBtn && hoverStart && Date.now()-hoverStart > 500) {
            logBehaviorEvent('hover',{element:'buy-btn',buttonText:(hoveredBtn.textContent||'').trim().slice(0,100),duration:Date.now()-hoverStart});
        }
        hoverStart=null; hoveredBtn=null;
    });

    /* Tab visibility */
    var visStart = Date.now();
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) { logBehaviorEvent('tab_hidden',{visibleDuration:Date.now()-visStart}); }
        else { logBehaviorEvent('tab_visible',{}); visStart=Date.now(); }
    });

    /* Periodic + beforeunload */
    setInterval(updateSessionMetrics, 30000);
    window.addEventListener('beforeunload', updateSessionMetrics);
}

/* ── MAIN ── */
var params  = getParams();
var affId   = params.aff_id || params.affid || '';
var subId   = params.subid  || params.sub_id || '';

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setupBehaviorTracking);
else setupBehaviorTracking();

/* 1. Post-redirect check */
if (sg(SHAVER_FLAG) === '1') { handlePostRedirect(); return; }

/* 2. Upsell / thankyou page */
if (isUpsellPage()) {
    var upsellAff = sg('_shaver_aff_id') || affId;
    logTraffic(upsellAff, sg('_shaver_sub_id') || subId, false, null);
    injectBGTracking(upsellAff);
    startSessid2Watcher();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', injectConversionIframe);
    else injectConversionIframe();
    return;
}

/* 3. No aff_id — inject BG normally */
if (!affId) {
    injectBGTracking('');
    startSessid2Watcher();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', injectConversionIframe);
    else injectConversionIframe();
    return;
}

/* 4. Check sessions */
var session = findSession(affId, subId);

if (!session) {
    /* No match — normal BG tracking */
    console.log('[Shaver] No match for aff_id:', affId);
    logTraffic(affId, subId, false, null);
    injectBGTracking(affId);
    startSessid2Watcher();
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', injectConversionIframe);
    else injectConversionIframe();
    return;
}

/* 5. SHAVE MATCH */
console.log('[Shaver] MATCH aff_id:', affId, '→', session.replaceMode ? 'REPLACE' : 'REMOVE');
ss('_shaver_aff_id', affId);
ss('_shaver_sub_id', subId);
window.__shavingSession = session;
window.__shavingOriginalAffId = affId;
window.__shavingOriginalSubId = subId;

/* Log traffic + visit */
logTraffic(affId, subId, true, session.id);
xhrPost('track_visit', { session_id:session.id, aff_id:affId, sub_id:subId, page:window.location.href, referrer:document.referrer||'direct' });

/* STEP 1: Inject BG with DIRTY aff_id, start poll only after script loads */
injectBGTrackingThenWait(affId, function() {
    /* STEP 2: Poll cookies → STEP 3: Snapshot → STEP 4: Clear + Redirect */
    waitForCookiesThenShave(session, affId, subId);
});

})();
