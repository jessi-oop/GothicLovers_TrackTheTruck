<?php
/**
 * /api/audit.php
 *
 * GET /api/audit.php
 *   Optional filters:
 *     ?equipment_id=N   — logs for a specific machine
 *     ?employee_id=X    — logs for a specific employee
 *     ?action=checkout  — filter by action type
 *     ?date_from=Y-m-d  — start date (inclusive)
 *     ?date_to=Y-m-d    — end date (inclusive)
 *     ?limit=50         — max rows (default 100, max 500)
 *     ?offset=0         — pagination offset
 */

require_once __DIR__ . '/../src/db.php';


if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    method_not_allowed(['GET']);
}

$db     = get_db();
$where  = [];
$params = [];

if (!empty($_GET['equipment_id'])) {
    $where[]  = 'al.equipment_id = ?';
    $params[] = (int) $_GET['equipment_id'];
}
if (!empty($_GET['employee_id'])) {
    $where[]  = 'al.employee_id = ?';
    $params[] = $_GET['employee_id'];
}
if (!empty($_GET['action'])) {
    $valid = ['checkout','return','status_change','created','updated','deleted'];
    if (!in_array($_GET['action'], $valid, true)) {
        error_response('Invalid action filter.');
    }
    $where[]  = 'al.action = ?';
    $params[] = $_GET['action'];
}
if (!empty($_GET['date_from'])) {
    $where[]  = 'DATE(al.timestamp) >= ?';
    $params[] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $where[]  = 'DATE(al.timestamp) <= ?';
    $params[] = $_GET['date_to'];
}

$limit  = min(500, max(1, (int) ($_GET['limit']  ?? 100)));
$offset = max(0,           (int) ($_GET['offset'] ?? 0));

$sql = 'SELECT al.*, e.name AS equipment_name, e.serial_number
        FROM audit_log al
        JOIN equipment e ON e.id = al.equipment_id';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY al.timestamp DESC LIMIT ? OFFSET ?';

$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Total count (without limit) for pagination metadata
$count_sql = 'SELECT COUNT(*) FROM audit_log al JOIN equipment e ON e.id = al.equipment_id';
if ($where) {
    // Remove the last two params (limit/offset) before counting
    $count_params = array_slice($params, 0, -2);
    $count_sql .= ' WHERE ' . implode(' AND ', $where);
} else {
    $count_params = [];
}
$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($count_params);
$total = (int) $count_stmt->fetchColumn();

json_response([
    'total'  => $total,
    'limit'  => $limit,
    'offset' => $offset,
    'data'   => $rows,
]);
