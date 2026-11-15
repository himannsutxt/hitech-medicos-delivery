<?php
declare(strict_types=1);
use HMD\{Auth, Db, Ui, Validate};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Ui::redirect('?r=dashboard');
}
$u = Auth::user();
$pages = ['orders', 'riders', 'cod', 'reports', 'exceptions', 'audit'];
if ($_GET['r'] === 'preset_save') {
    $page = (string)($_POST['page'] ?? '');
    $name = Validate::text((string)($_POST['name'] ?? ''), 60);
    $query = (string)($_POST['query'] ?? '');
    parse_str($query, $qp);
    if (!in_array($page, $pages, true) || ($qp['r'] ?? '') !== $page || $name === '' || strlen($query) > 1000) {
        Ui::flash('bad', 'Give the view a name.');
        Ui::redirect('?r=' . (in_array($page, $pages, true) ? $page : 'dashboard'));
    }
    Db::insert('hm_filter_preset', ['user_id' => $u['user_id'], 'page' => $page, 'name' => $name, 'query' => http_build_query($qp)]);
    Ui::flash('ok', "Saved view \"$name\".");
    Ui::redirect('?' . http_build_query($qp));
}
$id = (int)($_POST['id'] ?? 0);
Db::run('DELETE FROM hm_filter_preset WHERE preset_id=? AND user_id=?', [$id, $u['user_id']]);
$back = in_array($_POST['back'] ?? '', $pages, true) ? $_POST['back'] : 'dashboard';
Ui::flash('ok', 'Saved view removed.');
Ui::redirect('?r=' . $back);
