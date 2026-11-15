<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'HMD\\', 4) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
