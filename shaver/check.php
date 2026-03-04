<?php
/**
 * Multi-Tenant Shaving Check Script
 *
 * Loaded by product pages via: <script src="https://bg.climaofficial.com/shaver/check.php?d=DOMAIN_KEY">
 *
 * This PHP file outputs JavaScript that:
 * 1. Contains active shaving sessions for the specified domain
 * 2. Checks if current page's aff_id should be shaved
 * 3. Removes/replaces URL parameters BEFORE BuyGoods tracking runs
 * 4. Injects the domain's BuyGoods tracking script with clean URL
 * 5. Tracks visits, clicks, and user behavior
 */

header('Content-Type: application/javascript; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Database configuration
require_once __DIR__ . '/config.php';

$domainKey = $_GET['d'] ?? '';
$sessions = [];
$domain = null;

if (empty($domainKey)) {
    echo "console.warn('[Shaver] No domain key provided in check.php?d=KEY');";
    exit;
}

try {
    $pdo = getDB();

    // Get domain config
    $stmt = $pdo->prepare("SELECT * FROM domains WHERE domain_key = ? AND status = 'active'");
    $stmt->execute([$domainKey]);
    $domain = $stmt->fetch();

    if (!$domain) {
        echo "console.warn('[Shaver] Domain not found or inactive: " . addslashes($domainKey) . "');";
        exit;
    }

    // Get active sessions for this domain
    $stmt = $pdo->prepare("SELECT id, aff_id, sub_id, mode, replace_aff_id, replace_sub_id FROM shaving_sessions WHERE domain_id = ? AND active = 1");
    $stmt->execute([$domain['id']]);
    $sessions = $stmt->fetchAll();
} catch (PDOException $e) {
    echo "console.error('[Shaver] Database error');";
    exit;
}

// Prepare data for JavaScript
$domainId = (int)$domain['id'];
$sessionsJson = json_encode(array_map(function($s) {
    return [
        'id' => $s['id'],
        'affId' => $s['aff_id'],
        'subId' => $s['sub_id'] ?? '',
        'replaceMode' => ($s['mode'] === 'replace'),
        'replaceAffId' => $s['replace_aff_id'] ?? '',
        'replaceSubId' => $s['replace_sub_id'] ?? ''
    ];
}, $sessions));

$bgAccountId = addslashes($domain['bg_account_id'] ?? '');
$bgProductCodes = addslashes($domain['bg_product_codes'] ?? '');
$bgConversionToken = addslashes($domain['bg_conversion_token'] ?? '');

// Build API URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$path = dirname($_SERVER['SCRIPT_NAME']);
$apiUrl = $protocol . '://' . $host . $path . '/api.php';
?>
/**
 * Multi-Tenant Shaver - check.php
 * Domain: <?php echo addslashes($domain['label']); ?> (<?php echo addslashes($domain['domain_url']); ?>)
 * Generated: <?php echo date('Y-m-d H:i:s'); ?>

 * Active Sessions: <?php echo count($sessions); ?>

 */
(function() {
    'use strict';

    var DOMAIN_ID = <?php echo $domainId; ?>;
    var DOMAIN_KEY = '<?php echo addslashes($domainKey); ?>';
    var sessions = <?php echo $sessionsJson; ?>;
    var API_URL = '<?php echo $apiUrl; ?>';
    var BG_ACCOUNT_ID = '<?php echo $bgAccountId; ?>';
    var BG_PRODUCT_CODES = '<?php echo $bgProductCodes; ?>';
    var BG_CONVERSION_TOKEN = '<?php echo $bgConversionToken; ?>';

    console.log('[Shaver] Loaded', sessions.length, 'active sessions for domain:', DOMAIN_KEY);

    // ============================================================
    // URL PARAMETER PARSING
    // ============================================================
    function getUrlParams() {
        var params = {};
        var search = window.location.search.substring(1);
        if (!search) return params;
        var pairs = search.split('&');
        for (var i = 0; i < pairs.length; i++) {
            var pair = pairs[i].split('=');
            var key = decodeURIComponent(pair[0]);
            var value = pair[1] ? decodeURIComponent(pair[1]) : '';
            params[key] = value;
        }
        return params;
    }

    // ============================================================
    // SESSION MATCHING
    // ============================================================
    function findSession(affId, subId) {
        for (var i = 0; i < sessions.length; i++) {
            var s = sessions[i];
            if (s.affId === affId) {
                if (s.subId && s.subId !== subId) continue;
                return s;
            }
        }
        return null;
    }

    // ============================================================
    // URL MODIFICATION
    // ============================================================
    function modifyUrl(session) {
        var url = new URL(window.location.href);
        if (session.replaceMode) {
            url.searchParams.set('aff_id', session.replaceAffId);
            if (session.replaceSubId) {
                url.searchParams.set('subid', session.replaceSubId);
            } else {
                url.searchParams.delete('subid');
            }
            console.log('[Shaver] Replacing aff_id with:', session.replaceAffId);
        } else {
            url.searchParams.delete('aff_id');
            url.searchParams.delete('affid');
            url.searchParams.delete('subid');
            url.searchParams.delete('sub_id');
            console.log('[Shaver] Removing affiliate parameters');
        }
        window.history.replaceState({}, '', url.toString());
    }

    // ============================================================
    // TRACKING FUNCTIONS
    // ============================================================
    function trackVisit(session, affId, subId) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', API_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.send(JSON.stringify({
            action: 'track_visit',
            session_id: session.id,
            domain_id: DOMAIN_ID,
            aff_id: affId,
            sub_id: subId,
            page: window.location.href,
            referrer: document.referrer || 'direct'
        }));
    }

    function logTraffic(affId, subId, wasShaved, shavingSessionId, source) {
        if (!affId) return;
        var trafficSource = source || document.referrer || 'direct';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', API_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    var result = JSON.parse(xhr.responseText);
                    if (result.success && result.traffic_id && window.__behaviorTracking) {
                        window.__behaviorTracking.trafficId = result.traffic_id;
                        // Process queued events
                        if (window.__behaviorTracking.eventQueue.length > 0) {
                            window.__behaviorTracking.eventQueue.forEach(function(event) {
                                logBehaviorEvent(event.eventType, event.eventData);
                            });
                            window.__behaviorTracking.eventQueue = [];
                        }
                    }
                } catch (e) {}
            }
        };
        xhr.send(JSON.stringify({
            action: 'log_traffic',
            domain_id: DOMAIN_ID,
            aff_id: affId,
            sub_id: subId,
            page_url: window.location.href,
            referrer: trafficSource,
            user_agent: navigator.userAgent,
            was_shaved: wasShaved,
            shaving_session_id: shavingSessionId,
            session_uuid: window.__behaviorTracking ? window.__behaviorTracking.sessionUUID : null,
            screen_width: window.screen.width,
            screen_height: window.screen.height,
            viewport_width: window.innerWidth,
            viewport_height: window.innerHeight
        }));
    }

    function trackClick(session, affId, subId) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', API_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.send(JSON.stringify({
            action: 'track_click',
            session_id: session.id,
            domain_id: DOMAIN_ID,
            aff_id: affId,
            sub_id: subId,
            page: window.location.href
        }));
    }

    // ============================================================
    // BEHAVIOR TRACKING SYSTEM
    // ============================================================
    function getSessionUUID() {
        var uuid = null;
        try { uuid = sessionStorage.getItem('_behavior_session_id'); } catch (e) {}
        if (!uuid) {
            uuid = 'sess_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            try { sessionStorage.setItem('_behavior_session_id', uuid); } catch (e) {}
        }
        return uuid;
    }

    window.__behaviorTracking = {
        sessionUUID: getSessionUUID(),
        trafficId: null,
        landedAt: Date.now(),
        maxScrollDepth: 0,
        clickCount: 0,
        hasReachedCheckout: false,
        eventQueue: [],
        isTabVisible: true,
        lastScrollTime: 0,
        firstClickTime: null,
        checkoutTime: null,
        checkoutUrl: null,
        pageLoadTime: window.performance ? (window.performance.timing.loadEventEnd - window.performance.timing.navigationStart) : null
    };

    function logBehaviorEvent(eventType, eventData) {
        if (!window.__behaviorTracking.trafficId) {
            window.__behaviorTracking.eventQueue.push({ eventType: eventType, eventData: eventData, timestamp: Date.now() });
            return;
        }
        var xhr = new XMLHttpRequest();
        xhr.open('POST', API_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.send(JSON.stringify({
            action: 'log_behavior_event',
            domain_id: DOMAIN_ID,
            traffic_id: window.__behaviorTracking.trafficId,
            session_uuid: window.__behaviorTracking.sessionUUID,
            event_type: eventType,
            event_data: eventData,
            timestamp: new Date().toISOString()
        }));
    }

    function updateSessionMetrics() {
        if (!window.__behaviorTracking.trafficId) return;
        var sessionDuration = Math.floor((Date.now() - window.__behaviorTracking.landedAt) / 1000);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', API_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.send(JSON.stringify({
            action: 'update_session_metrics',
            traffic_id: window.__behaviorTracking.trafficId,
            session_duration: sessionDuration,
            max_scroll_depth: window.__behaviorTracking.maxScrollDepth,
            total_clicks: window.__behaviorTracking.clickCount,
            reached_checkout: window.__behaviorTracking.hasReachedCheckout ? 1 : 0,
            checkout_url: window.__behaviorTracking.checkoutUrl || null,
            time_to_first_click: window.__behaviorTracking.firstClickTime ?
                Math.floor((window.__behaviorTracking.firstClickTime - window.__behaviorTracking.landedAt) / 1000) : null,
            time_to_checkout: window.__behaviorTracking.checkoutTime ?
                Math.floor((window.__behaviorTracking.checkoutTime - window.__behaviorTracking.landedAt) / 1000) : null,
            screen_width: window.screen.width,
            screen_height: window.screen.height,
            viewport_width: window.innerWidth,
            viewport_height: window.innerHeight,
            page_load_time: window.__behaviorTracking.pageLoadTime,
            bounce: window.__behaviorTracking.clickCount === 0 ? 1 : 0
        }));
    }

    // Scroll tracking
    function setupScrollTracking() {
        var scrollTimeout;
        var lastDepth = 0;
        window.addEventListener('scroll', function() {
            clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(function() {
                var scrollY = window.scrollY || window.pageYOffset;
                var docHeight = document.documentElement.scrollHeight;
                var viewportHeight = window.innerHeight;
                var scrollDepth = Math.min(100, Math.floor(((scrollY + viewportHeight) / docHeight) * 100));
                if (scrollDepth > window.__behaviorTracking.maxScrollDepth) {
                    window.__behaviorTracking.maxScrollDepth = scrollDepth;
                    if (scrollDepth >= 25 && lastDepth < 25) logBehaviorEvent('scroll', {scrollDepth: 25, milestone: true});
                    else if (scrollDepth >= 50 && lastDepth < 50) logBehaviorEvent('scroll', {scrollDepth: 50, milestone: true});
                    else if (scrollDepth >= 75 && lastDepth < 75) logBehaviorEvent('scroll', {scrollDepth: 75, milestone: true});
                    else if (scrollDepth >= 90 && lastDepth < 90) logBehaviorEvent('scroll', {scrollDepth: 90, milestone: true});
                    lastDepth = scrollDepth;
                }
            }, 300);
        });
    }

    // Click tracking
    function setupDetailedClickTracking() {
        document.addEventListener('click', function(e) {
            var target = e.target;
            while (target && target !== document.body) {
                if (target.tagName === 'A' || target.tagName === 'BUTTON' ||
                    (target.classList && (target.classList.contains('cp-btn') || target.classList.contains('mt-buy-now-btn')))) {
                    window.__behaviorTracking.clickCount++;
                    if (!window.__behaviorTracking.firstClickTime) window.__behaviorTracking.firstClickTime = Date.now();
                    var buttonText = target.textContent ? target.textContent.trim() : '';
                    logBehaviorEvent('click', {
                        buttonText: buttonText.substring(0, 100),
                        buttonId: target.id || '',
                        targetUrl: (target.href || '').substring(0, 200),
                        clickX: e.clientX, clickY: e.clientY,
                        scrollDepthAtClick: window.__behaviorTracking.maxScrollDepth,
                        timeFromLanding: Math.floor((Date.now() - window.__behaviorTracking.landedAt) / 1000)
                    });
                    break;
                }
                target = target.parentElement;
            }
        });
    }

    // Hover tracking on buy buttons
    function setupHoverTracking() {
        var hoverStartTime = null;
        var hoveredButton = null;
        document.addEventListener('mouseover', function(e) {
            var target = e.target;
            while (target && target !== document.body) {
                if (target.classList && (target.classList.contains('cp-btn') || target.classList.contains('mt-buy-now-btn'))) {
                    hoverStartTime = Date.now();
                    hoveredButton = target;
                    break;
                }
                target = target.parentElement;
            }
        });
        document.addEventListener('mouseout', function(e) {
            if (hoveredButton && hoverStartTime) {
                var duration = Date.now() - hoverStartTime;
                if (duration > 500) {
                    logBehaviorEvent('hover', { element: 'buy-btn', buttonText: (hoveredButton.textContent || '').trim().substring(0, 100), duration: duration });
                }
                hoverStartTime = null;
                hoveredButton = null;
            }
        });
    }

    // Checkout detection
    function setupCheckoutDetection() {
        var originalPushState = history.pushState;
        if (originalPushState) {
            history.pushState = function() {
                if (arguments[2]) checkIfCheckoutReached(arguments[2]);
                return originalPushState.apply(history, arguments);
            };
        }
        window.addEventListener('popstate', function() { checkIfCheckoutReached(window.location.href); });

        document.addEventListener('click', function(e) {
            var target = e.target;
            while (target && target !== document.body) {
                if (target.href && target.href.indexOf('buygoods.com') !== -1) {
                    if (!window.__behaviorTracking.hasReachedCheckout) {
                        window.__behaviorTracking.hasReachedCheckout = true;
                        window.__behaviorTracking.checkoutUrl = target.href;
                        window.__behaviorTracking.checkoutTime = Date.now();
                        logBehaviorEvent('checkout_reached', {
                            checkoutUrl: target.href.substring(0, 200),
                            timeToCheckout: Math.floor((Date.now() - window.__behaviorTracking.landedAt) / 1000),
                            scrollDepthAtCheckout: window.__behaviorTracking.maxScrollDepth,
                            clicksBeforeCheckout: window.__behaviorTracking.clickCount
                        });
                    }
                    break;
                }
                target = target.parentElement;
            }
        });
    }

    function checkIfCheckoutReached(url) {
        if (url && url.indexOf('buygoods.com') !== -1 && !window.__behaviorTracking.hasReachedCheckout) {
            window.__behaviorTracking.hasReachedCheckout = true;
            window.__behaviorTracking.checkoutUrl = url;
            window.__behaviorTracking.checkoutTime = Date.now();
            logBehaviorEvent('checkout_reached', { checkoutUrl: url.substring(0, 200), timeToCheckout: Math.floor((Date.now() - window.__behaviorTracking.landedAt) / 1000) });
        }
    }

    // Tab visibility tracking
    function setupTabVisibilityTracking() {
        var visibleStart = Date.now();
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                logBehaviorEvent('tab_hidden', { hidden: true, visibleDuration: Date.now() - visibleStart });
                window.__behaviorTracking.isTabVisible = false;
            } else {
                logBehaviorEvent('tab_visible', { hidden: false });
                window.__behaviorTracking.isTabVisible = true;
                visibleStart = Date.now();
            }
        });
    }

    // Before unload
    function setupBeforeUnload() {
        window.addEventListener('beforeunload', function() {
            updateSessionMetrics();
            if (navigator.sendBeacon && window.__behaviorTracking.trafficId) {
                navigator.sendBeacon(API_URL, JSON.stringify({
                    action: 'update_session_metrics',
                    traffic_id: window.__behaviorTracking.trafficId,
                    session_duration: Math.floor((Date.now() - window.__behaviorTracking.landedAt) / 1000),
                    max_scroll_depth: window.__behaviorTracking.maxScrollDepth,
                    total_clicks: window.__behaviorTracking.clickCount,
                    reached_checkout: window.__behaviorTracking.hasReachedCheckout ? 1 : 0
                }));
            }
        });
    }

    // Periodic metric updates
    setInterval(updateSessionMetrics, 30000);

    // Initialize behavior tracking
    function initBehaviorTracking() {
        setupScrollTracking();
        setupDetailedClickTracking();
        setupHoverTracking();
        setupCheckoutDetection();
        setupTabVisibilityTracking();
        setupBeforeUnload();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBehaviorTracking);
    } else {
        initBehaviorTracking();
    }

    // ============================================================
    // MAIN LOGIC
    // ============================================================
    var params = getUrlParams();
    var affId = params.aff_id || params.affid || '';
    var subId = params.subid || params.sub_id || '';
    var utmSource = params.utm_source || params.source || params.ref || '';

    if (affId) {
        var session = findSession(affId, subId);
        if (session) {
            console.log('[Shaver] MATCH - aff_id:', affId, 'will be', session.replaceMode ? 'REPLACED' : 'REMOVED');
            logTraffic(affId, subId, true, session.id, utmSource);
            modifyUrl(session);
            trackVisit(session, affId, subId);
            window.__shavingSession = session;
            window.__shavingOriginalAffId = affId;
            window.__shavingOriginalSubId = subId;
        } else {
            console.log('[Shaver] No match for aff_id:', affId);
            logTraffic(affId, subId, false, null, utmSource);
        }
    }

    window.__shavingLoaded = true;

    // ============================================================
    // INJECT BUYGOODS TRACKING SCRIPT
    // ============================================================
    function ReadCookie(name) {
        name += '=';
        var parts = document.cookie.split(/;\s*/);
        for (var i = 0; i < parts.length; i++) {
            var part = parts[i];
            if (part.indexOf(name) === 0) return part.substring(name.length);
        }
        return '';
    }
    window.ReadCookie = ReadCookie;

    if (BG_ACCOUNT_ID && BG_PRODUCT_CODES) {
        var bgSrc = "https://tracking.buygoods.com/track/?a=" + BG_ACCOUNT_ID
            + "&firstcookie=0&tracking_redirect=&referrer=" + encodeURIComponent(document.referrer)
            + "&sessid2=" + ReadCookie('sessid2')
            + "&product=" + BG_PRODUCT_CODES
            + "&vid1=&vid2=&vid3=&caller_url=" + encodeURIComponent(window.location.href);

        var bgScript = document.createElement('script');
        bgScript.type = 'text/javascript';
        bgScript.defer = true;
        bgScript.src = bgSrc;
        document.head.appendChild(bgScript);
        console.log('[Shaver] BuyGoods tracking injected with clean URL');
    }

    // Conversion iframe
    function injectConversionIframe() {
        if (!BG_ACCOUNT_ID || !BG_CONVERSION_TOKEN) return;
        setTimeout(function() {
            var i = document.createElement("iframe");
            i.async = true;
            i.style.display = "none";
            i.setAttribute("src", "https://buygoods.com/affiliates/go/conversion/iframe/bg?a=" + BG_ACCOUNT_ID + "&t=" + BG_CONVERSION_TOKEN + "&s=" + ReadCookie('sessid2'));
            document.body.appendChild(i);
        }, 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectConversionIframe);
    } else {
        injectConversionIframe();
    }

    // ============================================================
    // CLICK TRACKING ON BUY BUTTONS
    // ============================================================
    function setupClickHandlers() {
        if (!window.__shavingSession) return;
        var session = window.__shavingSession;
        var affId = window.__shavingOriginalAffId;
        var subId = window.__shavingOriginalSubId;

        var buttons = document.querySelectorAll('.cp-btn, .mt-buy-now-btn, a[href*="buygoods.com"]');
        buttons.forEach(function(button) {
            button.addEventListener('click', function() {
                trackClick(session, affId, subId);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupClickHandlers);
    } else {
        setupClickHandlers();
    }
})();
