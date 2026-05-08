<?php
/**
 * /api/sites.php
 *
 * GET    /api/sites.php          — list all sites (with equipment count)
 * GET    /api/sites.php?id=N     — single site + its equipment list
 * POST   /api/sites.php          — create site
 * PUT    /api/sites.php?id=N     — update site
 * DELETE /api/sites.php?id=N     — delete site (only if no equipment assigned)
 */

require_once __DIR__ . '/../src/db.php';


$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;
$db     = get_db();

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if ($id) {
        $stmt = $db->prepare('SELECT * FROM sites WHERE id = ?');
        $stmt->execute([$id]);
        $site = $stmt->fetch();
        if (!$site) error_response('Site not found.', 404);

        $stmt = $db->prepare(
            'SELECT id, name, make, model, serial_number, status
             FROM equipment WHERE site_id = ? ORDER BY name'
        );
        $stmt->execute([$id]);
        $site['equipment'] = $stmt->fetchAll();
        json_response($site);
    }

    $stmt = $db->query(
        'SELECT s.*, COUNT(e.id) AS equipment_count
         FROM sites s
         LEFT JOIN equipment e ON e.site_id = s.id
         GROUP BY s.id
         ORDER BY s.name'
    );
    json_response($stmt->fetchAll());
}

// ── POST — create ─────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = get_json_body();
    require_fields($body, ['name', 'address', 'latitude', 'longitude']);

    $lat = (float) $body['latitude'];
    $lng = (float) $body['longitude'];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        error_response('Invalid latitude/longitude values.');
    }

    $stmt = $db->prepare(
        'INSERT INTO sites (name, address, latitude, longitude) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([trim($body['name']), trim($body['address']), $lat, $lng]);

    json_response(['id' => (int) $db->lastInsertId(), 'message' => 'Site created.'], 201);
}

// ── PUT — update ──────────────────────────────────────────────────────────────
if ($method === 'PUT') {
    if (!$id) error_response('id is required.');
    $body = get_json_body();

    $fields  = [];
    $params  = [];
    $allowed = ['name', 'address', 'latitude', 'longitude'];

    foreach ($allowed as $col) {
        if (array_key_exists($col, $body)) {
            $fields[]  = "{$col} = ?";
            $params[]  = $body[$col];
        }
    }

    if (!$fields) error_response('No updatable fields provided.');
    $params[] = $id;
    $db->prepare('UPDATE sites SET ' . implode(', ', $fields) . ' WHERE id = ?')
       ->execute($params);

    json_response(['message' => 'Site updated.']);
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!$id) error_response('id is required.');

    $stmt = $db->prepare('SELECT COUNT(*) FROM equipment WHERE site_id = ?');
    $stmt->execute([$id]);
    if ((int) $stmt->fetchColumn() > 0) {
        error_response('Cannot delete site with assigned equipment. Reassign equipment first.', 409);
    }

    $db->prepare('DELETE FROM sites WHERE id = ?')->execute([$id]);
    json_response(['message' => 'Site deleted.']);
}

method_not_allowed(['GET', 'POST', 'PUT', 'DELETE']);
