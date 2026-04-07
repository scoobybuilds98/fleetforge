<?php
declare(strict_types=1);
@ini_set('session.save_path', '/var/folders/x6/w_1x53t5577d0hfvz_lgj73m0000gn/T');
@ini_set('session.use_cookies', '0');
@ini_set('session.name', 'ff_session');
@ini_set('session.use_strict_mode', '0');

require __DIR__ . '/../../config/app.php';

$user = db_row('SELECT u.*, r.slug AS role_slug FROM users u JOIN user_roles r ON r.id = u.role_id WHERE u.id = 1');
$perms = require FF_ROOT . '/config/permissions.php';

session_start();
$_SESSION['ff_user'] = [
    'id'          => (int) $user['id'],
    'name'        => $user['name'],
    'email'       => $user['email'],
    'role_id'     => (int) $user['role_id'],
    'role_slug'   => 'super_admin',
    'permissions' => $perms['super_admin'] ?? [],
    'theme'       => 'dark',
];
$_SESSION['ff_last_activity'] = time();
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$sid = session_id();
session_write_close();

echo $sid . PHP_EOL;
