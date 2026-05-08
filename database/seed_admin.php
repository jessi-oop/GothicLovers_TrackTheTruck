<?php
/**
 * Seeds the default admin and staff accounts.
 * Run once from CLI: php database/seed_admin.php
 *
 * IMPORTANT: Change default passwords immediately after seeding.
 */

require_once __DIR__ . '/../src/db.php';

$users = [
    [
        'username'  => 'admin',
        'password'  => 'admin123',
        'full_name' => 'System Administrator',
        'role'      => 'admin',
    ],
    [
        'username'  => 'staff01',
        'password'  => 'staff123',
        'full_name' => 'Staff Member One',
        'role'      => 'staff',
    ],
];

$db   = get_db();
$stmt = $db->prepare(
    'INSERT IGNORE INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)'
);

foreach ($users as $u) {
    $hash = password_hash($u['password'], PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt->execute([$u['username'], $hash, $u['full_name'], $u['role']]);
    echo "Created: {$u['username']}  role={$u['role']}  password={$u['password']}\n";
}

echo "\nDone. Change default passwords before deploying.\n";
