<?php
function get_bearer_token(): ?string {
    // Apache may strip the Authorization header; fall back to apache_request_headers()
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');
    return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : null;
}

function resolve_user(): ?array {
    $token = get_bearer_token();
    if (!$token) return null;

    $stmt = get_db()->prepare(
        'SELECT u.id, u.username, u.full_name, u.role
         FROM user_sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.token = ? AND s.expires_at > NOW() AND u.is_active = 1'
    );
    $stmt->execute([$token]);
    return $stmt->fetch() ?: null;
}

function require_auth(): array {
    $user = resolve_user();
    if (!$user) error_response('Unauthorized. Please log in.', 401);
    return $user;
}

function require_role(array $user, array $roles): void {
    if (!in_array($user['role'], $roles, true)) {
        error_response('Forbidden. Insufficient permissions.', 403);
    }
}
