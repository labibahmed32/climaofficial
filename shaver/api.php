<?php
/**
 * Multi-Tenant Shaver API
 *
 * REST API for domain management, session management, tracking, and analytics
 */

require_once __DIR__ . '/config.php';

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json');

if ($method === 'OPTIONS') {
    exit(0);
}

// Get action from GET or POST body
$request = isset($_GET['action']) ? $_GET['action'] : '';
if (empty($request) && $method === 'POST') {
    $postData = json_decode(file_get_contents('php://input'), true);
    $request = $postData['action'] ?? '';
}

try {
    switch ($request) {
        // Domain management
        case 'register_domain':   registerDomain($pdo); break;
        case 'get_domains':       getDomains($pdo); break;
        case 'get_domain':        getDomain($pdo); break;
        case 'update_domain':     updateDomain($pdo); break;
        case 'delete_domain':     deleteDomain($pdo); break;

        // Session management
        case 'get_sessions':      getSessions($pdo); break;
        case 'create_session':    createSession($pdo); break;
        case 'stop_session':      stopSession($pdo); break;
        case 'get_history':       getHistory($pdo); break;
        case 'delete_history':    deleteHistory($pdo); break;

        // Tracking
        case 'track_visit':       trackVisit($pdo); break;
        case 'track_click':       trackClick($pdo); break;
        case 'log_traffic':       logTraffic($pdo); break;
        case 'log_behavior_event': logBehaviorEvent($pdo); break;
        case 'update_session_metrics': updateSessionMetrics($pdo); break;

        // Analytics
        case 'get_analytics':     getAnalytics($pdo); break;
        case 'get_traffic_log':   getTrafficLog($pdo); break;
        case 'get_traffic_chart': getTrafficChart($pdo); break;
        case 'get_behavior_details': getBehaviorDetails($pdo); break;
        case 'get_dashboard_stats': getDashboardStats($pdo); break;

        default:
            http_response_code(404);
            echo json_encode(['error' => 'Invalid endpoint: ' . $request]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ================================================================
// DOMAIN MANAGEMENT
// ================================================================

function registerDomain($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);

    $label = trim($data['label'] ?? '');
    $domainUrl = trim($data['domain_url'] ?? '');
    $bgAccountId = trim($data['bg_account_id'] ?? '');
    $bgProductCodes = trim($data['bg_product_codes'] ?? '');
    $bgConversionToken = trim($data['bg_conversion_token'] ?? '');
    $bgTrackingScript = $data['bg_tracking_script'] ?? '';
    $bgIframeScript = $data['bg_iframe_script'] ?? '';

    if (empty($label) || empty($domainUrl) || empty($bgTrackingScript)) {
        http_response_code(400);
        echo json_encode(['error' => 'Label, domain URL, and BG tracking script are required']);
        return;
    }

    // Generate domain_key from URL
    $domainKey = generateDomainKey($domainUrl);

    // Check for duplicate - allow re-registration if previously deleted
    $stmt = $pdo->prepare("SELECT id, status FROM domains WHERE domain_key = ?");
    $stmt->execute([$domainKey]);
    $existing = $stmt->fetch();
    if ($existing) {
        if ($existing['status'] === 'deleted') {
            // Hard-delete the old record so we can re-register
            $pdo->prepare("DELETE FROM domains WHERE id = ?")->execute([$existing['id']]);
        } else {
            http_response_code(409);
            echo json_encode(['error' => 'Domain already registered with key: ' . $domainKey]);
            return;
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO domains (domain_key, domain_url, label, bg_account_id, bg_product_codes, bg_conversion_token, bg_tracking_script, bg_iframe_script)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$domainKey, $domainUrl, $label, $bgAccountId, $bgProductCodes, $bgConversionToken, $bgTrackingScript, $bgIframeScript]);

    echo json_encode([
        'success' => true,
        'domain_id' => $pdo->lastInsertId(),
        'domain_key' => $domainKey
    ]);
}

function getDomains($pdo) {
    $stmt = $pdo->query("SELECT id, domain_key, domain_url, label, bg_account_id, bg_product_codes, status, created_at FROM domains WHERE status != 'deleted' ORDER BY created_at DESC");
    $domains = $stmt->fetchAll();

    echo json_encode(['success' => true, 'domains' => $domains]);
}

function getDomain($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $domainId = $data['domain_id'] ?? $_GET['domain_id'] ?? '';
    $domainKey = $data['domain_key'] ?? $_GET['domain_key'] ?? '';

    if (!empty($domainId)) {
        $stmt = $pdo->prepare("SELECT * FROM domains WHERE id = ?");
        $stmt->execute([$domainId]);
    } elseif (!empty($domainKey)) {
        $stmt = $pdo->prepare("SELECT * FROM domains WHERE domain_key = ?");
        $stmt->execute([$domainKey]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'domain_id or domain_key required']);
        return;
    }

    $domain = $stmt->fetch();
    if (!$domain) {
        http_response_code(404);
        echo json_encode(['error' => 'Domain not found']);
        return;
    }

    echo json_encode(['success' => true, 'domain' => $domain]);
}

function updateDomain($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $domainId = $data['domain_id'] ?? '';

    if (empty($domainId)) {
        http_response_code(400);
        echo json_encode(['error' => 'domain_id required']);
        return;
    }

    $fields = [];
    $params = [];

    if (isset($data['label'])) { $fields[] = 'label = ?'; $params[] = $data['label']; }
    if (isset($data['domain_url'])) { $fields[] = 'domain_url = ?'; $params[] = $data['domain_url']; }
    if (isset($data['bg_account_id'])) { $fields[] = 'bg_account_id = ?'; $params[] = $data['bg_account_id']; }
    if (isset($data['bg_product_codes'])) { $fields[] = 'bg_product_codes = ?'; $params[] = $data['bg_product_codes']; }
    if (isset($data['bg_conversion_token'])) { $fields[] = 'bg_conversion_token = ?'; $params[] = $data['bg_conversion_token']; }
    if (isset($data['bg_tracking_script'])) { $fields[] = 'bg_tracking_script = ?'; $params[] = $data['bg_tracking_script']; }
    if (isset($data['bg_iframe_script'])) { $fields[] = 'bg_iframe_script = ?'; $params[] = $data['bg_iframe_script']; }
    if (isset($data['status'])) { $fields[] = 'status = ?'; $params[] = $data['status']; }

    if (empty($fields)) {
        echo json_encode(['success' => true, 'message' => 'Nothing to update']);
        return;
    }

    $params[] = $domainId;
    $sql = "UPDATE domains SET " . implode(', ', $fields) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    echo json_encode(['success' => true]);
}

function deleteDomain($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $domainId = $data['domain_id'] ?? '';

    if (empty($domainId)) {
        http_response_code(400);
        echo json_encode(['error' => 'domain_id required']);
        return;
    }

    $stmt = $pdo->prepare("UPDATE domains SET status = 'deleted' WHERE id = ?");
    $stmt->execute([$domainId]);

    // Also stop all active sessions for this domain
    $stmt = $pdo->prepare("UPDATE shaving_sessions SET active = 0, stop_time = NOW() WHERE domain_id = ? AND active = 1");
    $stmt->execute([$domainId]);

    echo json_encode(['success' => true]);
}

// ================================================================
// SESSION MANAGEMENT
// ================================================================

function getSessions($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $domainId = $data['domain_id'] ?? $_GET['domain_id'] ?? '';

    $sql = "
        SELECT s.*,
            COALESCE(SUM(CASE WHEN t.event_type = 'visit' THEN 1 ELSE 0 END), 0) as visits,
            COALESCE(SUM(CASE WHEN t.event_type = 'click' THEN 1 ELSE 0 END), 0) as clicks
        FROM shaving_sessions s
        LEFT JOIN shaving_tracking t ON s.id = t.session_id
        WHERE s.active = 1
    ";
    $params = [];

    if (!empty($domainId)) {
        $sql .= " AND s.domain_id = ?";
        $params[] = $domainId;
    }

    $sql .= " GROUP BY s.id ORDER BY s.start_time DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sessions = $stmt->fetchAll();

    $formatted = array_map(function($s) {
        return [
            'id' => $s['id'],
            'domainId' => (int)$s['domain_id'],
            'affId' => $s['aff_id'],
            'subId' => $s['sub_id'],
            'mode' => $s['mode'],
            'replaceAffId' => $s['replace_aff_id'],
            'replaceSubId' => $s['replace_sub_id'],
            'startTime' => strtotime($s['start_time']) * 1000,
            'active' => (bool)$s['active'],
            'notes' => $s['notes'],
            'visits' => (int)$s['visits'],
            'clicks' => (int)$s['clicks']
        ];
    }, $sessions);

    echo json_encode(['success' => true, 'data' => $formatted]);
}

function createSession($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);

    $domainId = $data['domain_id'] ?? '';
    $affId = $data['aff_id'] ?? '';
    $subId = $data['sub_id'] ?? '';
    $mode = $data['mode'] ?? 'remove';
    $replaceAffId = $data['replace_aff_id'] ?? '';
    $replaceSubId = $data['replace_sub_id'] ?? '';
    $notes = $data['notes'] ?? '';
    $id = uniqid('session_', true);

    if (empty($domainId) || empty($affId)) {
        http_response_code(400);
        echo json_encode(['error' => 'domain_id and aff_id are required']);
        return;
    }

    // Check duplicate
    $stmt = $pdo->prepare("SELECT id FROM shaving_sessions WHERE domain_id = ? AND aff_id = ? AND (sub_id = ? OR (sub_id IS NULL AND ? = '')) AND active = 1");
    $stmt->execute([$domainId, $affId, $subId, $subId]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'Active session already exists for this affiliate on this domain']);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO shaving_sessions (id, domain_id, aff_id, sub_id, mode, replace_aff_id, replace_sub_id, notes, start_time)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$id, $domainId, $affId, $subId ?: null, $mode, $replaceAffId ?: null, $replaceSubId ?: null, $notes ?: null]);

    echo json_encode(['success' => true, 'sessionId' => $id]);
}

function stopSession($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $sessionId = $data['session_id'] ?? '';

    if (empty($sessionId)) {
        http_response_code(400);
        echo json_encode(['error' => 'session_id required']);
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM shaving_sessions WHERE id = ?");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();

    if (!$session) {
        http_response_code(404);
        echo json_encode(['error' => 'Session not found']);
        return;
    }

    // Count visits/clicks
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN event_type = 'visit' THEN 1 ELSE 0 END) as visits,
            SUM(CASE WHEN event_type = 'click' THEN 1 ELSE 0 END) as clicks
        FROM shaving_tracking WHERE session_id = ?
    ");
    $stmt->execute([$sessionId]);
    $stats = $stmt->fetch();

    $stopTime = date('Y-m-d H:i:s');
    $duration = time() - strtotime($session['start_time']);

    // Archive to history
    $stmt = $pdo->prepare("
        INSERT INTO shaving_history (session_id, domain_id, aff_id, sub_id, mode, replace_aff_id, replace_sub_id, start_time, stop_time, total_visits, total_clicks, duration, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $sessionId, $session['domain_id'], $session['aff_id'], $session['sub_id'],
        $session['mode'], $session['replace_aff_id'], $session['replace_sub_id'],
        $session['start_time'], $stopTime,
        $stats['visits'] ?? 0, $stats['clicks'] ?? 0, $duration, $session['notes']
    ]);

    // Deactivate
    $stmt = $pdo->prepare("UPDATE shaving_sessions SET active = 0, stop_time = NOW() WHERE id = ?");
    $stmt->execute([$sessionId]);

    echo json_encode(['success' => true]);
}

function getHistory($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $domainId = $data['domain_id'] ?? $_GET['domain_id'] ?? '';

    $sql = "SELECT * FROM shaving_history";
    $params = [];

    if (!empty($domainId)) {
        $sql .= " WHERE domain_id = ?";
        $params[] = $domainId;
    }

    $sql .= " ORDER BY stop_time DESC LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $history = $stmt->fetchAll();

    $formatted = array_map(function($item) {
        return [
            'id' => $item['session_id'],
            'domainId' => (int)$item['domain_id'],
            'affId' => $item['aff_id'],
            'subId' => $item['sub_id'],
            'mode' => $item['mode'],
            'replaceAffId' => $item['replace_aff_id'],
            'replaceSubId' => $item['replace_sub_id'],
            'startTime' => strtotime($item['start_time']) * 1000,
            'stopTime' => strtotime($item['stop_time']) * 1000,
            'visits' => (int)$item['total_visits'],
            'clicks' => (int)$item['total_clicks'],
            'duration' => (int)$item['duration'],
            'notes' => $item['notes']
        ];
    }, $history);

    echo json_encode(['success' => true, 'data' => $formatted]);
}

function deleteHistory($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $sessionId = $data['session_id'] ?? '';

    if (empty($sessionId)) {
        http_response_code(400);
        echo json_encode(['error' => 'session_id required']);
        return;
    }

    $stmt = $pdo->prepare("DELETE FROM shaving_history WHERE session_id = ?");
    $stmt->execute([$sessionId]);

    echo json_encode(['success' => true]);
}

// ================================================================
// TRACKING ENDPOINTS
// ================================================================

function trackVisit($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);

    $sessionId = $data['session_id'] ?? '';
    $domainId = $data['domain_id'] ?? 0;
    $affId = $data['aff_id'] ?? '';
    $subId = $data['sub_id'] ?? '';
    $page = $data['page'] ?? '';
    $referrer = $data['referrer'] ?? '';

    $stmt = $pdo->prepare("
        INSERT INTO shaving_tracking (session_id, domain_id, aff_id, sub_id, event_type, page, referrer, timestamp)
        VALUES (?, ?, ?, ?, 'visit', ?, ?, NOW())
    ");
    $stmt->execute([$sessionId, $domainId, $affId, $subId, $page, $referrer]);

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM shaving_tracking WHERE session_id = ? AND event_type = 'visit'");
    $stmt->execute([$sessionId]);
    $result = $stmt->fetch();

    echo json_encode(['success' => true, 'totalVisits' => (int)$result['total']]);
}

function trackClick($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);

    $sessionId = $data['session_id'] ?? '';
    $domainId = $data['domain_id'] ?? 0;
    $affId = $data['aff_id'] ?? '';
    $subId = $data['sub_id'] ?? '';
    $page = $data['page'] ?? '';

    $stmt = $pdo->prepare("
        INSERT INTO shaving_tracking (session_id, domain_id, aff_id, sub_id, event_type, page, timestamp)
        VALUES (?, ?, ?, ?, 'click', ?, NOW())
    ");
    $stmt->execute([$sessionId, $domainId, $affId, $subId, $page]);

    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM shaving_tracking WHERE session_id = ? AND event_type = 'click'");
    $stmt->execute([$sessionId]);
    $result = $stmt->fetch();

    echo json_encode(['success' => true, 'totalClicks' => (int)$result['total']]);
}

function logTraffic($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);

    $domainId = $data['domain_id'] ?? 0;
    $affId = $data['aff_id'] ?? '';
    $subId = $data['sub_id'] ?? '';
    $pageUrl = $data['page_url'] ?? '';
    $referrer = $data['referrer'] ?? '';
    $userAgent = $data['user_agent'] ?? '';
    $wasShaved = $data['was_shaved'] ?? false;
    $shavingSessionId = $data['shaving_session_id'] ?? null;
    $sessionUUID = $data['session_uuid'] ?? null;
    $screenWidth = $data['screen_width'] ?? null;
    $screenHeight = $data['screen_height'] ?? null;
    $viewportWidth = $data['viewport_width'] ?? null;
    $viewportHeight = $data['viewport_height'] ?? null;

    if (empty($affId)) {
        echo json_encode(['success' => true, 'skipped' => true]);
        return;
    }

    $ip = getClientIP();
    $browserInfo = parseBrowserInfo($userAgent);
    $geoInfo = getGeoInfo($ip);

    $stmt = $pdo->prepare("
        INSERT INTO affiliate_traffic
        (domain_id, aff_id, sub_id, page_url, referrer, user_agent, browser, device, ip_address, country, country_code,
         was_shaved, shaving_session_id, session_uuid, screen_width, screen_height, viewport_width, viewport_height)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $domainId, $affId, $subId, $pageUrl, $referrer, $userAgent,
        $browserInfo['browser'], $browserInfo['device'],
        $ip, $geoInfo['country'], $geoInfo['countryCode'],
        $wasShaved ? 1 : 0, $shavingSessionId, $sessionUUID,
        $screenWidth, $screenHeight, $viewportWidth, $viewportHeight
    ]);

    echo json_encode(['success' => true, 'traffic_id' => $pdo->lastInsertId()]);
}

function logBehaviorEvent($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);

    $domainId = $data['domain_id'] ?? 0;
    $trafficId = $data['traffic_id'] ?? null;
    $sessionUUID = $data['session_uuid'] ?? null;
    $eventType = $data['event_type'] ?? '';
    $eventData = $data['event_data'] ?? [];
    $timestamp = $data['timestamp'] ?? date('Y-m-d H:i:s');

    if (empty($trafficId) || empty($eventType)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields']);
        return;
    }

    $validEvents = ['page_view', 'scroll', 'click', 'hover', 'checkout_reached', 'tab_hidden', 'tab_visible'];
    if (!in_array($eventType, $validEvents)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid event type']);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO user_behavior_events (traffic_id, domain_id, session_uuid, event_type, event_data, timestamp)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$trafficId, $domainId, $sessionUUID, $eventType, json_encode($eventData), $timestamp]);

    echo json_encode(['success' => true, 'event_id' => $pdo->lastInsertId()]);
}

function updateSessionMetrics($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);

    $trafficId = $data['traffic_id'] ?? null;
    if (empty($trafficId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing traffic_id']);
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE affiliate_traffic SET
            session_duration = ?, max_scroll_depth = ?, total_clicks = ?,
            reached_checkout = ?, checkout_url = ?,
            time_to_first_click = ?, time_to_checkout = ?,
            screen_width = ?, screen_height = ?,
            viewport_width = ?, viewport_height = ?,
            page_load_time = ?, bounce = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $data['session_duration'] ?? null,
        $data['max_scroll_depth'] ?? 0,
        $data['total_clicks'] ?? 0,
        $data['reached_checkout'] ?? 0,
        $data['checkout_url'] ?? null,
        $data['time_to_first_click'] ?? null,
        $data['time_to_checkout'] ?? null,
        $data['screen_width'] ?? null,
        $data['screen_height'] ?? null,
        $data['viewport_width'] ?? null,
        $data['viewport_height'] ?? null,
        $data['page_load_time'] ?? null,
        $data['bounce'] ?? 1,
        $trafficId
    ]);

    echo json_encode(['success' => true]);
}

// ================================================================
// ANALYTICS ENDPOINTS
// ================================================================

function getAnalytics($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $domainId = $data['domain_id'] ?? $_GET['domain_id'] ?? '';
    $period = $data['period'] ?? $_GET['period'] ?? 'today';

    $timeFilter = getTimeFilter($period);
    $domainFilter = !empty($domainId) ? " AND domain_id = " . (int)$domainId : "";

    // Total stats
    $stmt = $pdo->query("
        SELECT
            COUNT(*) as total_visits,
            COUNT(DISTINCT aff_id) as unique_affiliates,
            SUM(was_shaved) as shaved_visits,
            COUNT(DISTINCT ip_address) as unique_visitors,
            AVG(NULLIF(max_scroll_depth, 0)) as avg_scroll_depth,
            AVG(NULLIF(session_duration, 0)) as avg_session_duration,
            SUM(COALESCE(total_clicks, 0)) as total_clicks,
            SUM(reached_checkout) as checkout_count,
            SUM(bounce) as bounce_count
        FROM affiliate_traffic
        WHERE $timeFilter $domainFilter
    ");
    $totals = $stmt->fetch();

    // Top affiliates
    $stmt = $pdo->query("
        SELECT aff_id, COUNT(*) as visits, SUM(was_shaved) as shaved, COUNT(DISTINCT ip_address) as unique_ips
        FROM affiliate_traffic
        WHERE $timeFilter $domainFilter
        GROUP BY aff_id ORDER BY visits DESC LIMIT 20
    ");
    $topAffiliates = $stmt->fetchAll();

    // Browser breakdown
    $stmt = $pdo->query("
        SELECT browser, COUNT(*) as count FROM affiliate_traffic
        WHERE $timeFilter $domainFilter AND browser IS NOT NULL AND browser != ''
        GROUP BY browser ORDER BY count DESC LIMIT 10
    ");
    $browsers = $stmt->fetchAll();

    // Device breakdown
    $stmt = $pdo->query("
        SELECT device, COUNT(*) as count FROM affiliate_traffic
        WHERE $timeFilter $domainFilter AND device IS NOT NULL AND device != ''
        GROUP BY device ORDER BY count DESC
    ");
    $devices = $stmt->fetchAll();

    // Country breakdown
    $stmt = $pdo->query("
        SELECT country, country_code, COUNT(*) as count FROM affiliate_traffic
        WHERE $timeFilter $domainFilter AND country IS NOT NULL AND country != ''
        GROUP BY country, country_code ORDER BY count DESC LIMIT 15
    ");
    $countries = $stmt->fetchAll();

    // Top referrers
    $stmt = $pdo->query("
        SELECT
            CASE WHEN referrer = '' OR referrer IS NULL OR referrer = 'direct' THEN 'Direct'
            ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(referrer, 'https://', ''), 'http://', ''), '/', 1), '?', 1)
            END as source,
            COUNT(*) as count
        FROM affiliate_traffic
        WHERE $timeFilter $domainFilter
        GROUP BY source ORDER BY count DESC LIMIT 10
    ");
    $referrers = $stmt->fetchAll();

    // Landing pages
    $stmt = $pdo->query("
        SELECT
            SUBSTRING_INDEX(SUBSTRING_INDEX(page_url, '?', 1), '/', -2) as landing_page,
            COUNT(*) as count
        FROM affiliate_traffic
        WHERE $timeFilter $domainFilter AND page_url IS NOT NULL AND page_url != ''
        GROUP BY landing_page ORDER BY count DESC LIMIT 10
    ");
    $landingPages = $stmt->fetchAll();

    $totalVisits = (int)$totals['total_visits'];
    $shavedVisits = (int)($totals['shaved_visits'] ?? 0);
    $bounceCount = (int)($totals['bounce_count'] ?? 0);
    $checkoutCount = (int)($totals['checkout_count'] ?? 0);

    // Format affiliates with 'count' key for frontend
    $formattedAffiliates = array_map(function($a) {
        return ['aff_id' => $a['aff_id'], 'count' => (int)$a['visits'], 'shaved' => (int)$a['shaved'], 'unique_ips' => (int)$a['unique_ips']];
    }, $topAffiliates);

    echo json_encode([
        'success' => true,
        'totalVisits' => $totalVisits,
        'shavedVisits' => $shavedVisits,
        'uniqueAffiliates' => (int)$totals['unique_affiliates'],
        'uniqueVisitors' => (int)$totals['unique_visitors'],
        'avgScrollDepth' => round((float)($totals['avg_scroll_depth'] ?? 0), 1),
        'avgSessionDuration' => round((float)($totals['avg_session_duration'] ?? 0), 1),
        'checkoutRate' => $totalVisits > 0 ? round(($checkoutCount / $totalVisits) * 100, 1) : 0,
        'bounceRate' => $totalVisits > 0 ? round(($bounceCount / $totalVisits) * 100, 1) : 0,
        'totalClicks' => (int)($totals['total_clicks'] ?? 0),
        'topAffiliates' => $formattedAffiliates,
        'topBrowsers' => $browsers,
        'topDevices' => $devices,
        'topCountries' => $countries,
        'topReferrers' => $referrers,
        'topLandingPages' => $landingPages
    ]);
}

function getTrafficLog($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $domainId = $data['domain_id'] ?? $_GET['domain_id'] ?? '';
    $limit = min((int)($data['limit'] ?? $_GET['limit'] ?? 50), 200);
    $offset = (int)($data['offset'] ?? $_GET['offset'] ?? 0);
    $affId = $data['aff_id'] ?? $_GET['aff_id'] ?? '';
    $period = $data['period'] ?? $_GET['period'] ?? '';

    $whereConditions = ["1=1"];
    $params = [];

    if (!empty($domainId)) {
        $whereConditions[] = "domain_id = ?";
        $params[] = $domainId;
    }
    if (!empty($affId)) {
        $whereConditions[] = "aff_id = ?";
        $params[] = $affId;
    }
    $wasShaved = $data['was_shaved'] ?? $_GET['was_shaved'] ?? '';
    if ($wasShaved !== '') {
        $whereConditions[] = "was_shaved = ?";
        $params[] = (int)$wasShaved;
    }

    $where = implode(' AND ', $whereConditions);

    // Add period filter (uses raw SQL, not parameterized - safe since getTimeFilter generates it)
    if (!empty($period)) {
        $timeFilter = getTimeFilter($period);
        $where .= " AND $timeFilter";
    }

    // Get total count for pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM affiliate_traffic WHERE $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['total'];

    $sql = "SELECT * FROM affiliate_traffic WHERE $where ORDER BY timestamp DESC LIMIT $limit OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $traffic = $stmt->fetchAll();

    $formatted = array_map(function($item) {
        return [
            'id' => $item['id'],
            'domainId' => (int)$item['domain_id'],
            'affId' => $item['aff_id'],
            'subId' => $item['sub_id'],
            'pageUrl' => $item['page_url'],
            'referrer' => $item['referrer'],
            'browser' => $item['browser'],
            'device' => $item['device'],
            'ip' => $item['ip_address'],
            'country' => $item['country'],
            'countryCode' => $item['country_code'],
            'wasShaved' => (bool)$item['was_shaved'],
            'reachedCheckout' => (bool)$item['reached_checkout'],
            'timestamp' => $item['timestamp'],
            'sessionDuration' => $item['session_duration'],
            'maxScrollDepth' => $item['max_scroll_depth'],
            'totalClicks' => $item['total_clicks'],
            'bounce' => (bool)$item['bounce']
        ];
    }, $traffic);

    echo json_encode(['success' => true, 'data' => $formatted, 'total' => $total]);
}

function getTrafficChart($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $domainId = $data['domain_id'] ?? $_GET['domain_id'] ?? '';
    $period = $data['period'] ?? $_GET['period'] ?? 'today';
    $domainFilter = !empty($domainId) ? " AND domain_id = " . (int)$domainId : "";

    // Determine grouping and interval based on period
    if (in_array($period, ['today', 'yesterday'])) {
        $timeFilter = getTimeFilter($period);
        $groupBy = "DATE_FORMAT(timestamp, '%H:00')";
        $orderBy = "DATE_FORMAT(timestamp, '%H:00')";
    } else {
        $timeFilter = getTimeFilter($period);
        $groupBy = "DATE(timestamp)";
        $orderBy = "DATE(timestamp)";
    }

    $stmt = $pdo->query("
        SELECT
            $groupBy as label,
            COUNT(*) as visits,
            SUM(was_shaved) as shaved
        FROM affiliate_traffic
        WHERE $timeFilter $domainFilter
        GROUP BY label ORDER BY $orderBy ASC
    ");
    $rows = $stmt->fetchAll();

    $labels = [];
    $totalData = [];
    $shavedData = [];

    foreach ($rows as $row) {
        $labels[] = $row['label'];
        $totalData[] = (int)$row['visits'];
        $shavedData[] = (int)($row['shaved'] ?? 0);
    }

    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'totalData' => $totalData,
        'shavedData' => $shavedData
    ]);
}

function getBehaviorDetails($pdo) {
    $trafficId = $_GET['traffic_id'] ?? null;

    if (empty($trafficId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing traffic_id']);
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM affiliate_traffic WHERE id = ?");
    $stmt->execute([$trafficId]);
    $sessionInfo = $stmt->fetch();

    if (!$sessionInfo) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        return;
    }

    $stmt = $pdo->prepare("SELECT event_type, event_data, timestamp FROM user_behavior_events WHERE traffic_id = ? ORDER BY timestamp ASC");
    $stmt->execute([$trafficId]);
    $events = $stmt->fetchAll();

    foreach ($events as &$event) {
        $event['event_data'] = json_decode($event['event_data'], true);
    }

    echo json_encode(['success' => true, 'session' => $sessionInfo, 'events' => $events]);
}

function getDashboardStats($pdo) {
    $data = json_decode(file_get_contents('php://input'), true);
    $domainId = $data['domain_id'] ?? '';
    $domainFilter = !empty($domainId) ? " AND domain_id = " . (int)$domainId : "";

    // Active sessions count
    $sql = "SELECT COUNT(*) as count FROM shaving_sessions WHERE active = 1";
    if (!empty($domainId)) $sql .= " AND domain_id = " . (int)$domainId;
    $activeSessions = $pdo->query($sql)->fetch()['count'];

    // Today's visits
    $todayFilter = getTimeFilter('today');
    $stmt = $pdo->query("
        SELECT COUNT(*) as total, SUM(was_shaved) as shaved
        FROM affiliate_traffic WHERE $todayFilter $domainFilter
    ");
    $todayStats = $stmt->fetch();

    // Registered domains count
    $totalDomains = $pdo->query("SELECT COUNT(*) as count FROM domains WHERE status = 'active'")->fetch()['count'];

    echo json_encode([
        'success' => true,
        'activeSessions' => (int)$activeSessions,
        'visitsToday' => (int)$todayStats['total'],
        'shavedToday' => (int)($todayStats['shaved'] ?? 0),
        'totalDomains' => (int)$totalDomains
    ]);
}

// ================================================================
// HELPER FUNCTIONS
// ================================================================

function generateDomainKey($url) {
    $url = preg_replace('#^https?://#', '', $url);
    $url = rtrim($url, '/');
    $key = preg_replace('/[^a-zA-Z0-9]+/', '-', $url);
    $key = strtolower(trim($key, '-'));
    if (strlen($key) > 60) $key = substr($key, 0, 60);
    return $key;
}

function getTimeFilter($period) {
    $tz = new DateTimeZone('Asia/Karachi');
    $now = new DateTime('now', $tz);

    switch ($period) {
        case 'today':
            $start = clone $now; $start->setTime(0, 0, 0);
            $end = clone $now; $end->setTime(23, 59, 59);
            break;
        case 'yesterday':
            $start = clone $now; $start->modify('-1 day')->setTime(0, 0, 0);
            $end = clone $now; $end->modify('-1 day')->setTime(23, 59, 59);
            break;
        case 'this_week':
        case 'thisweek':
            $dayOfWeek = $now->format('N');
            $start = clone $now; $start->modify('-' . ($dayOfWeek - 1) . ' days')->setTime(0, 0, 0);
            $end = clone $start; $end->modify('+6 days')->setTime(23, 59, 59);
            break;
        case 'last_week':
        case 'lastweek':
            $dayOfWeek = $now->format('N');
            $start = clone $now; $start->modify('-' . ($dayOfWeek + 6) . ' days')->setTime(0, 0, 0);
            $end = clone $start; $end->modify('+6 days')->setTime(23, 59, 59);
            break;
        case 'this_month':
        case 'thismonth':
            $start = clone $now; $start->modify('first day of this month')->setTime(0, 0, 0);
            $end = clone $now; $end->modify('last day of this month')->setTime(23, 59, 59);
            break;
        case 'all':
            return "1=1";
        default:
            $start = clone $now; $start->setTime(0, 0, 0);
            $end = clone $now; $end->setTime(23, 59, 59);
    }

    return "timestamp >= '" . $start->format('Y-m-d H:i:s') . "' AND timestamp <= '" . $end->format('Y-m-d H:i:s') . "'";
}

function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return trim($_SERVER['HTTP_CLIENT_IP']);
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) return trim($_SERVER['HTTP_X_REAL_IP']);
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function parseBrowserInfo($userAgent) {
    $browser = 'Unknown';
    $device = 'Desktop';

    if (preg_match('/Edg/i', $userAgent)) $browser = 'Edge';
    elseif (preg_match('/Firefox/i', $userAgent)) $browser = 'Firefox';
    elseif (preg_match('/Chrome/i', $userAgent) && !preg_match('/Edg/i', $userAgent)) $browser = 'Chrome';
    elseif (preg_match('/Safari/i', $userAgent) && !preg_match('/Chrome/i', $userAgent)) $browser = 'Safari';
    elseif (preg_match('/Opera|OPR/i', $userAgent)) $browser = 'Opera';
    elseif (preg_match('/MSIE|Trident/i', $userAgent)) $browser = 'IE';

    if (preg_match('/Mobile|Android|iPhone|iPod|webOS|BlackBerry|IEMobile/i', $userAgent)) {
        $device = preg_match('/iPad|Tablet/i', $userAgent) ? 'Tablet' : 'Mobile';
    }

    return ['browser' => $browser, 'device' => $device];
}

function getGeoInfo($ip) {
    if ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        return ['country' => 'Local', 'countryCode' => 'LO'];
    }

    $context = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
    $response = @file_get_contents("http://ip-api.com/json/{$ip}?fields=country,countryCode", false, $context);

    if ($response) {
        $data = json_decode($response, true);
        if ($data && isset($data['country'])) {
            return ['country' => $data['country'], 'countryCode' => $data['countryCode'] ?? 'XX'];
        }
    }

    return ['country' => 'Unknown', 'countryCode' => 'XX'];
}
?>
