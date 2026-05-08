<?php
/**
 * Equipment Tracker — API Test Suite
 *
 * Run from CLI:
 *   php tests/api_test.php
 *   php tests/api_test.php http://localhost/equipment-tracker   (custom URL)
 *
 * Requires: PHP cURL extension, XAMPP running, database seeded (seed_admin.php)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Run from CLI only: php tests/api_test.php');
}

if (!function_exists('curl_init')) {
    exit("ERROR: PHP cURL extension is required.\n");
}

define('BASE_URL', rtrim($argv[1] ?? 'http://localhost/equipment-tracker', '/'));

// ── Console colours ───────────────────────────────────────────────────────────
$colour = DIRECTORY_SEPARATOR === '/' || getenv('WT_SESSION') || getenv('TERM');
define('C_PASS',  $colour ? "\033[32m" : '');
define('C_FAIL',  $colour ? "\033[31m" : '');
define('C_SKIP',  $colour ? "\033[33m" : '');
define('C_HEAD',  $colour ? "\033[36;1m" : '');
define('C_BOLD',  $colour ? "\033[1m" : '');
define('C_RESET', $colour ? "\033[0m" : '');

// ── State ─────────────────────────────────────────────────────────────────────
$passed       = 0;
$failed       = 0;
$failures     = [];
$admin_token  = '';
$staff_token  = '';
$created_ids  = ['equipment' => null, 'site' => null, 'checkout' => null];

// ── HTTP helper ───────────────────────────────────────────────────────────────
function http(string $method, string $path, array $body = [], string $token = ''): array
{
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer {$token}";
    }

    $ch = curl_init(BASE_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($body) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['status' => 0, 'body' => [], 'error' => $err];
    }
    return ['status' => $status, 'body' => json_decode($raw, true) ?? []];
}

// ── Assertion helpers ─────────────────────────────────────────────────────────
function section(string $title): void
{
    echo "\n" . C_HEAD . "── {$title} " . str_repeat('─', max(2, 52 - strlen($title))) . C_RESET . "\n";
}

function ok(string $label): void
{
    global $passed;
    $passed++;
    echo C_PASS . "  ✓ " . C_RESET . $label . "\n";
}

function fail(string $label, string $detail = ''): void
{
    global $failed, $failures;
    $failed++;
    $msg = $label . ($detail ? " ({$detail})" : '');
    $failures[] = $msg;
    echo C_FAIL . "  ✗ " . C_RESET . $label . ($detail ? C_FAIL . "  ← {$detail}" . C_RESET : '') . "\n";
}

function skip(string $label): void
{
    echo C_SKIP . "  ~ " . C_RESET . "{$label} (skipped)\n";
}

function expect_status(string $label, int $want, array $res): bool
{
    $got = $res['status'];
    if ($got === 0) {
        fail($label, 'cURL error — is the server running at ' . BASE_URL . '?');
        return false;
    }
    if ($got === $want) {
        ok($label);
        return true;
    }
    $detail = "expected {$want}, got {$got}";
    if (!empty($res['body']['error'])) {
        $detail .= " — \"{$res['body']['error']}\"";
    }
    fail($label, $detail);
    return false;
}

function expect_key(string $label, string $key, array $res): mixed
{
    if (array_key_exists($key, $res['body'])) {
        ok($label);
        return $res['body'][$key];
    }
    fail($label, "key '{$key}' missing from response");
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────

echo "\n" . C_BOLD . "Equipment Tracker — API Test Suite" . C_RESET . "\n";
echo "Target : " . C_HEAD . BASE_URL . C_RESET . "\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";

// ── 1. Auth ───────────────────────────────────────────────────────────────────
section('Auth');

$res = http('POST', '/api/login', ['username' => 'admin', 'password' => 'admin123']);
if (expect_status('POST /api/login  admin valid creds → 200', 200, $res)) {
    $admin_token = $res['body']['token'] ?? '';
    $admin_token ? ok("Admin bearer token received") : fail("Admin bearer token received", "token missing");
    if (!empty($res['body']['user']['role']) && $res['body']['user']['role'] === 'admin') {
        ok("Login response carries role=admin");
    } else {
        fail("Login response carries role=admin");
    }
}

$res = http('POST', '/api/login', ['username' => 'staff01', 'password' => 'staff123']);
if (expect_status('POST /api/login  staff valid creds → 200', 200, $res)) {
    $staff_token = $res['body']['token'] ?? '';
    $staff_token ? ok("Staff bearer token received") : fail("Staff bearer token received", "token missing");
}

$res = http('POST', '/api/login', ['username' => 'admin', 'password' => 'wrong']);
expect_status('POST /api/login  bad password → 401', 401, $res);

$res = http('POST', '/api/login', ['username' => 'nobody', 'password' => 'x']);
expect_status('POST /api/login  unknown user → 401', 401, $res);

$res = http('GET', '/api/equipment');
expect_status('GET  /api/equipment  no token → 401', 401, $res);

$res = http('GET', '/api/equipment', [], 'not-a-real-token');
expect_status('GET  /api/equipment  bad token → 401', 401, $res);

// ── 2. Equipment — admin CRUD ─────────────────────────────────────────────────
section('Equipment  (admin — full CRUD)');

$res = http('GET', '/api/equipment', [], $admin_token);
if (expect_status('GET /api/equipment → 200', 200, $res)) {
    is_array($res['body']) ? ok("Response is a JSON array") : fail("Response is a JSON array");
}

$serial = 'TEST-' . strtoupper(uniqid());
$res = http('POST', '/api/equipment', [
    'name'          => 'Test Unit Alpha',
    'make'          => 'TestMake',
    'model'         => 'TM-001',
    'serial_number' => $serial,
    'status'        => 'available',
], $admin_token);
if (expect_status('POST /api/equipment → 201', 201, $res)) {
    $created_ids['equipment'] = (int) ($res['body']['id'] ?? 0);
    $created_ids['equipment']
        ? ok("  New equipment id={$created_ids['equipment']}")
        : fail("  New equipment id returned");
}

if ($eid = $created_ids['equipment']) {
    $res = http('GET', "/api/equipment?id={$eid}", [], $admin_token);
    expect_status("GET /api/equipment?id={$eid} → 200", 200, $res);

    $res = http('PUT', "/api/equipment?id={$eid}", ['status' => 'maintenance'], $admin_token);
    expect_status("PUT /api/equipment?id={$eid} (status change) → 200", 200, $res);

    $res = http('GET', "/api/equipment?id={$eid}", [], $admin_token);
    if ($res['body']['status'] ?? '' === 'maintenance') {
        ok("  Status persisted as 'maintenance'");
    } else {
        fail("  Status persisted as 'maintenance'");
    }

    // Restore so checkout test can use it
    http('PUT', "/api/equipment?id={$eid}", ['status' => 'available'], $admin_token);
}

$res = http('GET', '/api/equipment?status=available', [], $admin_token);
expect_status('GET /api/equipment?status=available → 200', 200, $res);

$res = http('GET', '/api/equipment?id=999999', [], $admin_token);
expect_status('GET /api/equipment?id=999999 → 404', 404, $res);

$res = http('POST', '/api/equipment', ['name' => 'missing fields'], $admin_token);
expect_status('POST /api/equipment  missing fields → 422', 422, $res);

// ── 3. Sites — admin only ─────────────────────────────────────────────────────
section('Sites  (admin — full CRUD)');

$res = http('GET', '/api/sites', [], $admin_token);
if (expect_status('GET /api/sites → 200', 200, $res)) {
    is_array($res['body']) ? ok("Response is a JSON array") : fail("Response is a JSON array");
}

$res = http('POST', '/api/sites', [
    'name'      => 'Test Site Zulu',
    'address'   => '1 Test St, Test City',
    'latitude'  => 7.0707,
    'longitude' => 125.6087,
], $admin_token);
if (expect_status('POST /api/sites → 201', 201, $res)) {
    $created_ids['site'] = (int) ($res['body']['id'] ?? 0);
    $created_ids['site']
        ? ok("  New site id={$created_ids['site']}")
        : fail("  New site id returned");
}

if ($sid = $created_ids['site']) {
    $res = http('GET', "/api/sites?id={$sid}", [], $admin_token);
    expect_status("GET /api/sites?id={$sid} → 200", 200, $res);

    $res = http('PUT', "/api/sites?id={$sid}", ['name' => 'Test Site Zulu (updated)'], $admin_token);
    expect_status("PUT /api/sites?id={$sid} → 200", 200, $res);

    $res = http('DELETE', "/api/sites?id={$sid}", [], $admin_token);
    expect_status("DELETE /api/sites?id={$sid} → 200", 200, $res);
    $created_ids['site'] = null;
}

$res = http('POST', '/api/sites', ['name' => 'Bad', 'latitude' => 999, 'longitude' => 999, 'address' => 'x'], $admin_token);
expect_status('POST /api/sites  invalid lat/lng → 400', 400, $res);

// ── 4. Checkout ───────────────────────────────────────────────────────────────
section('Checkout');

$res = http('GET', '/api/checkout', [], $admin_token);
if (expect_status('GET /api/checkout (active) → 200', 200, $res)) {
    is_array($res['body']) ? ok("Response is a JSON array") : fail("Response is a JSON array");
}

$avail_res  = http('GET', '/api/equipment?status=available', [], $admin_token);
$avail_equip = $avail_res['body'][0] ?? null;

if (!$avail_equip) {
    skip('Checkout POST/PUT tests (no available equipment in DB)');
} else {
    $eid = $avail_equip['id'];

    $res = http('POST', '/api/checkout', [
        'equipment_id'  => $eid,
        'employee_name' => 'Test Employee',
        'employee_id'   => 'EMP-TEST-001',
        'notes'         => 'Automated test checkout',
    ], $admin_token);
    if (expect_status('POST /api/checkout → 201', 201, $res)) {
        $created_ids['checkout'] = (int) ($res['body']['id'] ?? 0);
        $created_ids['checkout']
            ? ok("  Checkout id={$created_ids['checkout']}")
            : fail("  Checkout id returned");
    }

    // Equipment should now be checked_out
    $eq = http('GET', "/api/equipment?id={$eid}", [], $admin_token);
    ($eq['body']['status'] ?? '') === 'checked_out'
        ? ok("  Equipment status flipped to 'checked_out'")
        : fail("  Equipment status flipped to 'checked_out'");

    // Double checkout should fail
    $res = http('POST', '/api/checkout', [
        'equipment_id'  => $eid,
        'employee_name' => 'Someone Else',
        'employee_id'   => 'EMP-TEST-002',
    ], $admin_token);
    expect_status('POST /api/checkout  already checked out → 409', 409, $res);

    if ($cid = $created_ids['checkout']) {
        $res = http('GET', "/api/checkout?id={$cid}", [], $admin_token);
        expect_status("GET /api/checkout?id={$cid} → 200", 200, $res);

        $res = http('PUT', "/api/checkout?id={$cid}", ['notes' => 'Test return'], $admin_token);
        expect_status("PUT /api/checkout?id={$cid} (return key) → 200", 200, $res);

        // Equipment should be available again
        $eq = http('GET', "/api/equipment?id={$eid}", [], $admin_token);
        ($eq['body']['status'] ?? '') === 'available'
            ? ok("  Equipment status restored to 'available'")
            : fail("  Equipment status restored to 'available'");

        // Double return should fail
        $res = http('PUT', "/api/checkout?id={$cid}", [], $admin_token);
        expect_status("PUT /api/checkout?id={$cid} (double return) → 409", 409, $res);

        $created_ids['checkout'] = null;
    }
}

// ── 5. Audit Log ─────────────────────────────────────────────────────────────
section('Audit Log');

$res = http('GET', '/api/audit', [], $admin_token);
if (expect_status('GET /api/audit → 200', 200, $res)) {
    isset($res['body']['data']) && is_array($res['body']['data'])
        ? ok("Response has 'data' array")
        : fail("Response has 'data' array");
    isset($res['body']['total'])
        ? ok("Response has 'total' count")
        : fail("Response has 'total' count");
}

$res = http('GET', '/api/audit?limit=5', [], $admin_token);
if (expect_status('GET /api/audit?limit=5 → 200', 200, $res)) {
    $count = count($res['body']['data'] ?? []);
    $count <= 5 ? ok("limit=5 respected (got {$count})") : fail("limit=5 respected", "got {$count}");
}

$res = http('GET', '/api/audit?action=checkout', [], $admin_token);
expect_status('GET /api/audit?action=checkout → 200', 200, $res);

$res = http('GET', '/api/audit?action=invalidaction', [], $admin_token);
expect_status('GET /api/audit?action=invalid → 400', 400, $res);

// ── 6. Map ────────────────────────────────────────────────────────────────────
section('Map');

$res = http('GET', '/api/map', [], $admin_token);
if (expect_status('GET /api/map → 200', 200, $res)) {
    isset($res['body']['pins'])    ? ok("Response has 'pins' key")    : fail("Response has 'pins' key");
    isset($res['body']['legend'])  ? ok("Response has 'legend' key")  : fail("Response has 'legend' key");
}

$res = http('GET', '/api/map?format=json', [], $admin_token);
if (expect_status('GET /api/map?format=json → 200', 200, $res)) {
    isset($res['body']['pins']) ? ok("JSON-only format returns pins") : fail("JSON-only format returns pins");
    $no_url = !isset($res['body']['static_map_url']);
    $no_url ? ok("JSON-only format omits static_map_url") : fail("JSON-only format omits static_map_url");
}

// ── 7. Role Enforcement ───────────────────────────────────────────────────────
section('Role Enforcement  (staff token)');

$res = http('GET', '/api/equipment', [], $staff_token);
expect_status('GET  /api/equipment   staff → 200 allowed', 200, $res);

$res = http('POST', '/api/equipment', [
    'name' => 'x', 'make' => 'x', 'model' => 'x', 'serial_number' => 'RBAC-TEST',
], $staff_token);
expect_status('POST /api/equipment   staff → 403 blocked', 403, $res);

$res = http('GET', '/api/sites', [], $staff_token);
expect_status('GET  /api/sites       staff → 403 blocked', 403, $res);

$res = http('POST', '/api/sites', [
    'name' => 'x', 'address' => 'x', 'latitude' => 0, 'longitude' => 0,
], $staff_token);
expect_status('POST /api/sites       staff → 403 blocked', 403, $res);

$res = http('GET', '/api/checkout', [], $staff_token);
expect_status('GET  /api/checkout    staff → 200 allowed', 200, $res);

$res = http('POST', '/api/checkout', [
    'equipment_id' => 1, 'employee_name' => 'x', 'employee_id' => 'x',
], $staff_token);
expect_status('POST /api/checkout    staff → 403 blocked', 403, $res);

$res = http('GET', '/api/audit', [], $staff_token);
expect_status('GET  /api/audit       staff → 200 allowed', 200, $res);

$res = http('GET', '/api/map', [], $staff_token);
expect_status('GET  /api/map         staff → 200 allowed', 200, $res);

// ── 8. Edge Cases ─────────────────────────────────────────────────────────────
section('Edge Cases');

$res = http('GET', '/api/nonexistent', [], $admin_token);
expect_status('GET /api/nonexistent → 404', 404, $res);

$res = http('DELETE', '/api/equipment', [], $admin_token);
expect_status('DELETE /api/equipment (no id) → 400', 400, $res);

$res = http('DELETE', '/api/equipment?id=999999', [], $admin_token);
expect_status('DELETE /api/equipment?id=999999 → 404', 404, $res);

// ── 9. Cleanup — delete the test equipment record ─────────────────────────────
if ($eid = $created_ids['equipment']) {
    $res = http('DELETE', "/api/equipment?id={$eid}", [], $admin_token);
    section('Cleanup');
    expect_status("DELETE test equipment id={$eid}", 200, $res);
}

// ── 10. Logout ────────────────────────────────────────────────────────────────
section('Logout');

$res = http('POST', '/api/logout', [], $admin_token);
expect_status('POST /api/logout (admin) → 200', 200, $res);

$res = http('GET', '/api/equipment', [], $admin_token);
expect_status('GET  /api/equipment after logout → 401', 401, $res);

$res = http('POST', '/api/logout', [], $staff_token);
expect_status('POST /api/logout (staff) → 200', 200, $res);

// ── Summary ───────────────────────────────────────────────────────────────────
$total = $passed + $failed;
echo "\n" . str_repeat('─', 54) . "\n";
$all_pass = $failed === 0;
echo C_BOLD . "  Result : ";
echo ($all_pass ? C_PASS : C_FAIL);
echo "{$passed}/{$total} passed";
if ($failed) echo "  ({$failed} failed)";
echo C_RESET . "\n";

if ($failures) {
    echo C_FAIL . "\n  Failed:\n" . C_RESET;
    foreach ($failures as $f) {
        echo "    • {$f}\n";
    }
}

echo "\n";
exit($failed > 0 ? 1 : 0);
