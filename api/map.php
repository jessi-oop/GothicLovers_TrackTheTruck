<?php
/**
 * /api/map.php
 *
 * GET /api/map.php
 *   Returns:
 *     - pins[]          — all equipment with coordinates (from their assigned site)
 *     - static_map_url  — Google Static Maps URL with colored markers
 *     - map_image_url   — same URL (alias, ready to use as <img src="">)
 *
 *   Pin color by equipment status:
 *     available    → green
 *     checked_out  → red
 *     maintenance  → yellow
 *     decommissioned → gray
 *
 * GET /api/map.php?site_id=N   — limit pins to one site
 * GET /api/map.php?format=json — pins JSON only (no static map URL)
 */

require_once __DIR__ . '/../src/db.php';


if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    method_not_allowed(['GET']);
}

$db = get_db();

$where  = ['e.site_id IS NOT NULL'];  // only equipment placed on a site
$params = [];

if (!empty($_GET['site_id'])) {
    $where[]  = 'e.site_id = ?';
    $params[] = (int) $_GET['site_id'];
}

$sql = 'SELECT e.id AS equipment_id,
               e.name, e.make, e.model, e.serial_number, e.status,
               s.id AS site_id, s.name AS site_name,
               s.latitude, s.longitude, s.address
        FROM equipment e
        JOIN sites s ON s.id = e.site_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY s.name, e.name';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ── Active checkout rider — who has it right now ──────────────────────────────
if ($rows) {
    $equip_ids = implode(',', array_column($rows, 'equipment_id'));
    $active = $db->query(
        "SELECT equipment_id, employee_name, employee_id, checked_out_at, expected_return_at
         FROM key_checkouts
         WHERE is_returned = 0 AND equipment_id IN ({$equip_ids})"
    )->fetchAll(PDO::FETCH_UNIQUE);

    foreach ($rows as &$row) {
        $row['active_checkout'] = $active[$row['equipment_id']] ?? null;
    }
    unset($row);
}

if (($_GET['format'] ?? '') === 'json') {
    json_response(['pins' => $rows]);
}

// ── Build Google Static Maps URL ──────────────────────────────────────────────
$color_map = [
    'available'      => 'green',
    'checked_out'    => 'red',
    'maintenance'    => 'yellow',
    'decommissioned' => 'gray',
];

// Group markers by color+location to minimize URL length
$markers = [];
foreach ($rows as $r) {
    $color   = $color_map[$r['status']] ?? 'blue';
    $label   = strtoupper(substr($r['name'], 0, 1));
    $lat     = $r['latitude'];
    $lng     = $r['longitude'];
    $markers[] = "color:{$color}|label:{$label}|{$lat},{$lng}";
}

$base = 'https://maps.googleapis.com/maps/api/staticmap';
$args = [
    'size'    => MAP_WIDTH . 'x' . MAP_HEIGHT,
    'zoom'    => MAP_ZOOM,
    'maptype' => 'roadmap',
    'key'     => GOOGLE_MAPS_KEY,
];

$query = http_build_query($args);
foreach ($markers as $m) {
    $query .= '&markers=' . urlencode($m);
}

$static_map_url = $base . '?' . $query;

json_response([
    'pins'           => $rows,
    'static_map_url' => $static_map_url,
    'map_image_url'  => $static_map_url,   // alias for convenience
    'legend'         => [
        'green'  => 'available',
        'red'    => 'checked_out',
        'yellow' => 'maintenance',
        'gray'   => 'decommissioned',
    ],
]);
