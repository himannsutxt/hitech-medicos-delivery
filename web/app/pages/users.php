<?php
declare(strict_types=1);
use HMD\{Db, Fmt, Perm, Ui, Auth, Audit, Install, Validate};

$me = Auth::user();
$e = [Ui::class, 'e'];
$tab = ($_GET['tab'] ?? '') === 'roles' ? 'roles' : 'users';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'user_save') {
        $id = (int)($_POST['user_id'] ?? 0);
        $row = ['name' => Validate::text($_POST['name'] ?? '', 100), 'email' => strtolower(trim((string)($_POST['email'] ?? ''))), 'mobile' => Validate::mobile($_POST['mobile'] ?? ''),
            'role_id' => (int)($_POST['role_id'] ?? 0), 'status' => !empty($_POST['status']) ? 1 : 0];
        $pw = (string)($_POST['password'] ?? '');
        $err = [];
        if ($row['name'] === '' || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $err[] = 'Name and a valid email are required.';
        }
        if (!Db::val('SELECT 1 FROM hm_role WHERE role_id=?', [$row['role_id']])) {
            $err[] = 'Choose a role.';
        }
        if (Db::val('SELECT 1 FROM hm_user WHERE email=? AND user_id<>?', [$row['email'], $id])) {
            $err[] = 'Another user has this email.';
        }
        if ((!$id || $pw !== '') && ($p = Install::passwordProblem($pw))) {
            $err[] = $p;
        }
        if ($id === (int)$me['user_id'] && ($row['status'] === 0 || $row['role_id'] !== (int)$me['role_id'])) {
            $err[] = 'You cannot disable yourself or change your own role.';
        }
        if ($err) {
            Ui::flash('bad', implode(' ', $err));
            Ui::redirect('?r=users&edit=' . $id);
        }
        if ($pw !== '') {
            $row['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
        }
        if ($id) {
            Db::update('hm_user', $row, 'user_id=? AND deleted_at IS NULL', [$id]);
            unset($row['password_hash']);
            Audit::log('user.update', 'user', $id, null, $row + ['password_changed' => $pw !== '']);
        } else {
            $id = Db::insert('hm_user', $row + ['created_at' => Fmt::nowUtc()]);
            unset($row['password_hash']);
            Audit::log('user.create', 'user', $id, null, $row);
        }
        Ui::flash('ok', 'User saved.');
    } elseif ($act === 'role_save') {
        $rid = (int)($_POST['role_id'] ?? 0);
        $name = Validate::text($_POST['name'] ?? '', 64);
        $perms = array_values(array_intersect(array_keys(Perm::KEYS), (array)($_POST['perms'] ?? [])));
        $role = $rid ? Db::one('SELECT * FROM hm_role WHERE role_id=?', [$rid]) : null;
        if ($role && $role['name'] === 'Super Admin') {
            Ui::flash('bad', 'The Super Admin role always has every permission.');
        } elseif ($name === '' || Db::val('SELECT 1 FROM hm_role WHERE name=? AND role_id<>?', [$name, $rid])) {
            Ui::flash('bad', 'Give the role a unique name.');
        } else {
            if ($role) {
                Db::update('hm_role', ['name' => $name, 'permissions' => json_encode($perms)], 'role_id=?', [$rid]);
                Audit::log('role.update', 'role', $rid, json_decode($role['permissions'], true), $perms);
            } else {
                $rid = Db::insert('hm_role', ['name' => $name, 'permissions' => json_encode($perms), 'created_at' => Fmt::nowUtc()]);
                Audit::log('role.create', 'role', $rid, null, $perms);
            }
            Ui::flash('ok', 'Role saved.');
        }
        Ui::redirect('?r=users&tab=roles&role=' . $rid);
    }
    Ui::redirect('?r=users');
}
$roles = Db::all('SELECT * FROM hm_role ORDER BY role_id');
$roleMap = array_column($roles, 'name', 'role_id');
$nav = '<div class="eva-tabs"><a class="' . ($tab === 'users' ? 'active' : '') . '" href="?r=users">Users</a><a class="' . ($tab === 'roles' ? 'active' : '') . '" href="?r=users&tab=roles">Roles & permissions</a></div>';
if ($tab === 'users') {
    $users = Db::all('SELECT u.*, r.name role FROM hm_user u JOIN hm_role r ON r.role_id=u.role_id WHERE u.deleted_at IS NULL ORDER BY u.name');
    $tr = '';
    foreach ($users as $x) {
        $tr .= '<tr><td data-label="Name"><b>' . $e($x['name']) . '</b></td><td data-label="Email">' . $e($x['email']) . '</td><td data-label="Role">' . $e($x['role']) . '</td><td data-label="Status">' . ((int)$x['status'] === 1 ? '<span class="eva-pill ok">Active</span>' : '<span class="eva-pill muted">Disabled</span>') . ($x['locked_until'] && strtotime($x['locked_until'] . ' UTC') > time() ? ' <span class="eva-pill bad">Locked</span>' : '') . '</td><td data-label="Last login">' . Fmt::dt($x['last_login_at']) . '</td><td><a class="eva-btn sm" href="?r=users&edit=' . (int)$x['user_id'] . '"><i class="fa-solid fa-pen"></i> Edit</a></td></tr>';
    }
    $edit = isset($_GET['edit']) ? (Db::one('SELECT * FROM hm_user WHERE user_id=? AND deleted_at IS NULL', [(int)$_GET['edit']]) ?? ['user_id' => 0, 'name' => '', 'email' => '', 'mobile' => '', 'role_id' => 0, 'status' => 1]) : null;
    $form = $edit !== null ? '<form method="post" class="eva-card">' . Ui::csrfField() . '<input type="hidden" name="act" value="user_save"><input type="hidden" name="user_id" value="' . (int)$edit['user_id'] . '"><div class="eva-h"><h4><i class="fa-solid fa-user-pen"></i> ' . ($edit['user_id'] ? 'Edit user' : 'Add user') . '</h4></div>'
        . '<div class="eva-field"><label class="req">Name</label><div><input type="text" name="name" value="' . $e($edit['name']) . '" required></div></div>'
        . '<div class="eva-field"><label class="req">Email</label><div><input type="email" name="email" value="' . $e($edit['email']) . '" required><div class="help">Used to log in.</div></div></div>'
        . '<div class="eva-field"><label>Mobile</label><div><input type="text" name="mobile" value="' . $e($edit['mobile']) . '"></div></div>'
        . '<div class="eva-field"><label class="req">Role</label><div><select name="role_id">' . Ui::options($roleMap, (string)$edit['role_id'], 'Choose…') . '</select></div></div>'
        . '<div class="eva-field"><label class="' . ($edit['user_id'] ? '' : 'req') . '">Password</label><div><input type="password" name="password" autocomplete="new-password"><div class="help">' . ($edit['user_id'] ? 'Leave empty to keep the current password. ' : '') . 'At least 8 characters with letters and numbers.</div></div></div>'
        . '<div class="eva-field"><label>Active</label><div><label><input type="checkbox" name="status" value="1"' . ((int)$edit['status'] === 1 ? ' checked' : '') . '> Can log in</label></div></div>'
        . '<div style="margin-top:12px"><button class="eva-btn primary"><i class="fa-solid fa-check"></i> Save user</button> <a class="eva-btn" href="?r=users">Cancel</a></div></form>' : '';
    $body = $form . '<div class="eva-card">' . $nav . '<div class="eva-table-wrap"><table class="eva-table stack"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead><tbody>' . $tr . '</tbody></table></div></div>';
    Ui::page('Users', 'users', $body, 'Office staff who use this website. Riders are managed under Riders.', '<a class="eva-btn primary" href="?r=users&edit=0"><i class="fa-solid fa-user-plus"></i> Add user</a>');
} else {
    $sel = isset($_GET['role']) ? (Db::one('SELECT * FROM hm_role WHERE role_id=?', [(int)$_GET['role']]) ?? null) : ($roles[1] ?? $roles[0]);
    if (isset($_GET['new'])) {
        $sel = ['role_id' => 0, 'name' => '', 'permissions' => '[]'];
    }
    $list = '';
    foreach ($roles as $r) {
        $list .= '<a class="eva-btn sm' . ((int)$r['role_id'] === (int)($sel['role_id'] ?? -1) ? ' primary' : '') . '" href="?r=users&tab=roles&role=' . (int)$r['role_id'] . '">' . $e($r['name']) . '</a> ';
    }
    $have = json_decode($sel['permissions'] ?? '[]', true) ?: [];
    $isSuper = ($sel['name'] ?? '') === 'Super Admin';
    $checks = '';
    foreach (Perm::KEYS as $k => $l) {
        $checks .= '<label style="display:block;padding:5px 0"><input type="checkbox" name="perms[]" value="' . $k . '"' . (($isSuper || in_array($k, $have, true)) ? ' checked' : '') . ($isSuper ? ' disabled' : '') . '> ' . $e($l) . ' <span class="muted small">(' . $k . ')</span></label>';
    }
    $body = '<div class="eva-card">' . $nav . '<div style="margin-bottom:12px">' . $list . '<a class="eva-btn sm" href="?r=users&tab=roles&new=1"><i class="fa-solid fa-plus"></i> New role</a></div>'
        . '<form method="post">' . Ui::csrfField() . '<input type="hidden" name="act" value="role_save"><input type="hidden" name="role_id" value="' . (int)($sel['role_id'] ?? 0) . '"><div class="eva-field"><label class="req">Role name</label><div><input type="text" name="name" value="' . $e($sel['name'] ?? '') . '"' . ($isSuper ? ' readonly' : '') . '></div></div>'
        . '<div class="eva-field"><label>Permissions</label><div>' . $checks . ($isSuper ? '<div class="help">Super Admin always has everything.</div>' : '') . '</div></div>'
        . ($isSuper ? '' : '<button class="eva-btn primary" style="margin-top:12px"><i class="fa-solid fa-check"></i> Save role</button>') . '</form></div>';
    Ui::page('Roles & permissions', 'users', $body, 'Choose exactly which pages and actions each role can use.');
}
