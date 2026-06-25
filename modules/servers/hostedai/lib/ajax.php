<?php
/**
 * ajax.php — OTL (One-Time Login) endpoint for the hosted·ai client area.
 *
 * SECURITY: this endpoint is reachable directly over HTTP, so it MUST
 * authenticate the caller and verify service ownership itself. It must never
 * trust a service id or email supplied in the request body for authorization.
 */

header('Content-Type: application/json');

// ── Bootstrap WHMCS ────────────────────────────────────────────────────────
if (!defined('WHMCS')) {
    // ajax.php lives at modules/servers/hostedai/lib/ — WHMCS root is 5 levels up.
    $initPath = dirname(__FILE__, 5) . '/init.php';
    if (!file_exists($initPath) && !empty($_SERVER['DOCUMENT_ROOT'])) {
        // Fallback for non-standard layouts (e.g. symlinked docroot).
        $initPath = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/init.php';
    }
    if (!file_exists($initPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Initialization error']);
        exit;
    }
    require_once $initPath;
}

use WHMCS\Module\Server\HosteDai\Helper;
use WHMCS\Database\Capsule;
use WHMCS\ClientArea;

// ── Method + action routing ─────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$action = $_POST['action'] ?? '';
if ($action !== 'generate_otl') {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit;
}

generateOTL();

function generateOTL()
{
    $staticLoginUrl = $_POST['static_login_url'] ?? '';

    try {
        // ── 1. Require an authenticated client session ──────────────────────
        $ca = new ClientArea();
        if (!$ca->isLoggedIn()) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Please log in to continue.',
            ]);
            return;
        }
        $loggedInUserId = (int) $ca->getUserID();

        // ── 2. Validate the requested service id ────────────────────────────
        $serviceId = (int) ($_POST['service_id'] ?? 0);
        if ($serviceId <= 0) {
            echo json_encode([
                'success' => false,
                'message' => 'Missing required parameters',
                'fallback_url' => $staticLoginUrl,
            ]);
            return;
        }

        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        if (!$service) {
            echo json_encode([
                'success' => false,
                'message' => 'Service not found',
                'fallback_url' => $staticLoginUrl,
            ]);
            return;
        }

        // ── 3. Ownership check — the service must belong to the caller ───────
        // This is the core defense against IDOR: the caller may only generate
        // a login token for a service they own. The owner id comes from the
        // authenticated session, never from the request body.
        if ((int) $service->userid !== $loggedInUserId) {
            http_response_code(403);
            logActivity(
                "hostedai OTL: client {$loggedInUserId} attempted to access "
                . "service {$serviceId} owned by {$service->userid} — denied"
            );
            echo json_encode([
                'success' => false,
                'message' => 'You are not authorized to access this service.',
            ]);
            return;
        }

        // ── 4. Derive the email server-side — never trust the POSTed value ───
        $client = Capsule::table('tblclients')->where('id', $service->userid)->first();
        if (!$client || empty($client->email)) {
            echo json_encode([
                'success' => false,
                'message' => 'Client account not found',
                'fallback_url' => $staticLoginUrl,
            ]);
            return;
        }
        $userEmail = $client->email;

        // ── 5. Resolve server config for this service ───────────────────────
        $server = Capsule::table('tblservers')->where('id', $service->server)->first();
        if (!$server) {
            echo json_encode([
                'success' => false,
                'message' => 'Server configuration not found',
                'fallback_url' => $staticLoginUrl,
            ]);
            return;
        }

        // ── 6. Generate the one-time login token ────────────────────────────
        $helper = new Helper([
            'serverhostname' => $server->hostname,
            'serverpassword' => decrypt($server->password),
        ]);
        $otlResponse = $helper->createOneTimeLoginToken($userEmail);

        if ($otlResponse && $otlResponse['httpcode'] == 201 && isset($otlResponse['result']->url)) {
            echo json_encode([
                'success' => true,
                'login_url' => $otlResponse['result']->url,
                'message' => 'One-time login link generated successfully',
            ]);
        } else {
            logActivity('hostedai OTL: generation failed for service ' . $serviceId);
            echo json_encode([
                'success' => false,
                'message' => 'Unable to generate secure login link. Using standard login.',
                'fallback_url' => $staticLoginUrl,
            ]);
        }

    } catch (Exception $e) {
        logActivity('hostedai OTL AJAX error: ' . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred. Using standard login.',
            'fallback_url' => $staticLoginUrl,
        ]);
    }
}
