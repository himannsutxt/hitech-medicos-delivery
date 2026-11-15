<?php
declare(strict_types=1);
use HMD\{Auth, Ui};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::logout();
}
Ui::redirect('?r=login');
