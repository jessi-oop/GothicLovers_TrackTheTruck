<?php
/**
 * GET    /api/equipment          — list all (optional ?status=, ?site_id=)
 * GET    /api/equipment?id=N     — single record
 * POST   /api/equipment          — create   [admin]
 * PUT    /api/equipment?id=N     — update   [admin]
 * DELETE /api/equipment?id=N     — delete   [admin]
 */

require_once __DIR__ . '/../src/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;
$db     = get_db();

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if ($id) {
        $stmt = $db->prepare(
            'SELECT e.*, s.name AS site_name
             FROM equipment e
             LEFT JOIN sites s ON s.id = e.site_id
             WHERE e.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) error_response('Equipment not found.', 404);
        json_response($row);
    }

    $where  = [];
    $params = [];

    if (!empty($_GET['status'])) {
        $where[]  = 'e.status = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['site_id'])) {
        $where[]  = 'e.site_id = ?';
        $params[] = (int) $_GET['site_id'];
    }

    $sql  = 'SELECT e.*, s.name AS site_name
             FROM equipment e
             LEFT JOIN sites s ON s.id = e.site_id';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY e.name';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    json_response($stmt->fetchAll());
}

// ── POST — create ─────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = get_json_body();
    require_fields($body, ['name', 'make', 'model', 'serial_number']);

    $valid_statuses = ['available', 'checked_out', 'maintenance', 'decommissioned'];
    $status = $body['status'] ?? 'available';
    if (!in_array($status, $valid_statuses, true)) {
        error_response('Invalid status value.');
    }

    $stmt = $db->prepare(
        'INSERT INTO equipment (name, make, model, serial_number, status, site_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        trim($body['name']),
        trim($body['make']),
        trim($body['model']),
        trim($body['serial_number']),
        $status,
        isset($body['site_id']) ? (int) $body['site_id'] : null,
    ]);

    $new_id = (int) $db->lastInsertId();
    $actor  = $GLOBALS['current_user'] ?? [];

    $db->prepare(
        'INSERT INTO audit_log (equipment_id, employee_id, employee_name, action, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $new_id,
        $actor['username']  ?? 'SYSTEM',
        $actor['full_name'] ?? 'System',
        'created',
        "Equipment record created: {$body['name']} ({$body['serial_number']})",
        client_ip(),
    ]);

    json_response(['id' => $new_id, 'message' => 'Equipment created.'], 201);
}

// ── PUT — update ──────────────────────────────────────────────────────────────
if ($method === 'PUT') {
    if (!$id) error_response('id is required.');
    $body = get_json_body();

    $fields  = [];
    $params  = [];
    $allowed = ['name', 'make', 'model', 'serial_number', 'status', 'site_id'];

    foreach ($allowed as $col) {
        if (array_key_exists($col, $body)) {
            $fields[]  = "{$col} = ?";
            $params[]  = $col === 'site_id'
                ? ($body[$col] === null ? null : (int) $body[$col])
                : $body[$col];
        }
    }

    if (!$fields) error_response('No updatable fields provided.');

    $params[] = $id;
    $db->prepare('UPDATE equipment SET ' . implode(', ', $fields) . ' WHERE id = ?')
       ->execute($params);

    $actor   = $GLOBALS['current_user'] ?? [];
    $changed = implode(', ', array_keys(array_intersect_key($body, array_flip($allowed))));
    $db->prepare(
        'INSERT INTO audit_log (equipment_id, employee_id, employee_name, action, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $id,
        $actor['username']  ?? 'SYSTEM',
        $actor['full_name'] ?? 'System',
        'updated',
        "Fields updated: {$changed}",
        client_ip(),
    ]);

    json_response(['message' => 'Equipment updated.']);
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!$id) error_response('id is required.');

    $stmt = $db->prepare('SELECT name, serial_number FROM equipment WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) error_response('Equipment not found.', 404);

    $actor = $GLOBALS['current_user'] ?? [];
    $db->prepare(
        'INSERT INTO audit_log (equipment_id, employee_id, employee_name, action, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([
        $id,
        $actor['username']  ?? 'SYSTEM',
        $actor['full_name'] ?? 'System',
        'deleted',
        "Deleted: {$row['name']} ({$row['serial_number']})",
        client_ip(),
    ]);

    $db->prepare('DELETE FROM equipment WHERE id = ?')->execute([$id]);
    json_response(['message' => 'Equipment deleted.']);
}

method_not_allowed(['GET', 'POST', 'PUT', 'DELETE']);
