<?php
/**
 * /api/checkout.php
 *
 * POST /api/checkout.php
 *      { equipment_id, employee_name, employee_id, expected_return_at?, notes? }
 *      — Check out a key. Sets equipment.status = 'checked_out'.
 *
 * PUT  /api/checkout.php?id=N
 *      { notes? }
 *      — Return a key. Sets equipment.status = 'available', logs return.
 *
 * GET  /api/checkout.php             — list active checkouts (is_returned = 0)
 * GET  /api/checkout.php?id=N        — single checkout record
 * GET  /api/checkout.php?equipment_id=N — history for one machine
 */

require_once __DIR__ . '/../src/db.php';


$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;
$db     = get_db();

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if ($id) {
        $stmt = $db->prepare(
            'SELECT kc.*, e.name AS equipment_name, e.serial_number
             FROM key_checkouts kc
             JOIN equipment e ON e.id = kc.equipment_id
             WHERE kc.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) error_response('Checkout record not found.', 404);
        json_response($row);
    }

    if (!empty($_GET['equipment_id'])) {
        $stmt = $db->prepare(
            'SELECT kc.*, e.name AS equipment_name, e.serial_number
             FROM key_checkouts kc
             JOIN equipment e ON e.id = kc.equipment_id
             WHERE kc.equipment_id = ?
             ORDER BY kc.checked_out_at DESC'
        );
        $stmt->execute([(int) $_GET['equipment_id']]);
        json_response($stmt->fetchAll());
    }

    // Active checkouts only
    $stmt = $db->query(
        'SELECT kc.*, e.name AS equipment_name, e.serial_number, s.name AS site_name
         FROM key_checkouts kc
         JOIN equipment e ON e.id = kc.equipment_id
         LEFT JOIN sites s ON s.id = e.site_id
         WHERE kc.is_returned = 0
         ORDER BY kc.checked_out_at DESC'
    );
    json_response($stmt->fetchAll());
}

// ── POST — check out ──────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = get_json_body();
    require_fields($body, ['equipment_id', 'employee_name', 'employee_id']);

    $equip_id = (int) $body['equipment_id'];

    // Verify equipment exists and is available
    $stmt = $db->prepare('SELECT id, name, status FROM equipment WHERE id = ? FOR UPDATE');
    $db->beginTransaction();
    try {
        $stmt->execute([$equip_id]);
        $equip = $stmt->fetch();
        if (!$equip) {
            $db->rollBack();
            error_response('Equipment not found.', 404);
        }
        if ($equip['status'] !== 'available') {
            $db->rollBack();
            error_response("Equipment is currently '{$equip['status']}' and cannot be checked out.", 409);
        }

        // Create checkout record
        $db->prepare(
            'INSERT INTO key_checkouts
             (equipment_id, employee_name, employee_id, expected_return_at, notes)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $equip_id,
            trim($body['employee_name']),
            trim($body['employee_id']),
            $body['expected_return_at'] ?? null,
            $body['notes'] ?? null,
        ]);
        $checkout_id = (int) $db->lastInsertId();

        // Update equipment status
        $db->prepare("UPDATE equipment SET status = 'checked_out' WHERE id = ?")
           ->execute([$equip_id]);

        // Audit
        $detail = "Checked out by {$body['employee_name']} ({$body['employee_id']}).";
        if (!empty($body['expected_return_at'])) {
            $detail .= " Expected return: {$body['expected_return_at']}.";
        }
        $db->prepare(
            'INSERT INTO audit_log (equipment_id, employee_id, employee_name, action, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $equip_id,
            trim($body['employee_id']),
            trim($body['employee_name']),
            'checkout',
            $detail,
            client_ip(),
        ]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        error_response('Checkout failed: ' . $e->getMessage(), 500);
    }

    json_response(['id' => $checkout_id, 'message' => 'Key checked out successfully.'], 201);
}

// ── PUT — return key ──────────────────────────────────────────────────────────
if ($method === 'PUT') {
    if (!$id) error_response('id (checkout record id) is required.');
    $body = get_json_body();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT kc.*, e.name AS equipment_name
             FROM key_checkouts kc
             JOIN equipment e ON e.id = kc.equipment_id
             WHERE kc.id = ? FOR UPDATE'
        );
        $stmt->execute([$id]);
        $checkout = $stmt->fetch();

        if (!$checkout) {
            $db->rollBack();
            error_response('Checkout record not found.', 404);
        }
        if ($checkout['is_returned']) {
            $db->rollBack();
            error_response('Key has already been returned.', 409);
        }

        $now = date('Y-m-d H:i:s');

        // Mark returned
        $db->prepare(
            'UPDATE key_checkouts
             SET is_returned = 1, returned_at = ?, notes = CONCAT(COALESCE(notes,""), ?)
             WHERE id = ?'
        )->execute([
            $now,
            !empty($body['notes']) ? "\nReturn note: " . $body['notes'] : '',
            $id,
        ]);

        // Reset equipment status
        $db->prepare("UPDATE equipment SET status = 'available' WHERE id = ?")
           ->execute([$checkout['equipment_id']]);

        // Audit
        $db->prepare(
            'INSERT INTO audit_log (equipment_id, employee_id, employee_name, action, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $checkout['equipment_id'],
            $checkout['employee_id'],
            $checkout['employee_name'],
            'return',
            "Key returned by {$checkout['employee_name']}. Returned at: {$now}.",
            client_ip(),
        ]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        error_response('Return failed: ' . $e->getMessage(), 500);
    }

    json_response(['message' => 'Key returned successfully.']);
}

method_not_allowed(['GET', 'POST', 'PUT']);
