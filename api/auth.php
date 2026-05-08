<?php
/**
 * POST /api/login   { username, password } → { token, expires_at, user }
 * POST /api/logout  Authorization: Bearer <token>
 */

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    method_not_allowed(['POST']);
}

$db = get_db();

// ── Login ─────────────────────────────────────────────────────────────────────
if ($_route_path === '/api/login') {
    $body = get_json_body();
    require_fields($body, ['username', 'password']);

    $stmt = $db->prepare('SELECT * FROM users WHERE username = ? AND is_active = 1');
    $stmt->execute([trim($body['username'])]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($body['password'], $user['password_hash'])) {
        error_response('Invalid credentials.', 401);
    }

    $token   = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+8 hours'));

    $db->prepare(
        'INSERT INTO user_sessions (user_id, token, expires_at) VALUES (?, ?, ?)'
    )->execute([$user['id'], $token, $expires]);

    json_response([
        'token'      => $token,
        'expires_at' => $expires,
        'user'       => [
            'id'        => $user['id'],
            'username'  => $user['username'],
            'full_name' => $user['full_name'],
            'role'      => $user['role'],
        ],
    ]);
}

// ── Logout ────────────────────────────────────────────────────────────────────
if ($_route_path === '/api/logout') {
    $token = get_bearer_token();
    if ($token) {
        $db->prepare('DELETE FROM user_sessions WHERE token = ?')->execute([$token]);
    }
    json_response(['message' => 'Logged out successfully.']);
}

error_response('Not found.', 404);
