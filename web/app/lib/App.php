<?php
declare(strict_types=1);
namespace HMD;

final class App
{
    public const VERSION = '1.1.1';
    public const NAME = 'Hitech Medicos Delivery';
    private static ?array $config = null;

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function config(): array
    {
        if (self::$config === null) {
            $f = self::root() . '/app/config.php';
            if (!is_file($f)) {
                throw new \RuntimeException('not_installed');
            }
            self::$config = require $f;
        }
        return self::$config;
    }

    public static function setConfig(array $c): void
    {
        self::$config = $c;
    }

    public static function installed(): bool
    {
        return is_file(self::root() . '/app/config.php');
    }

    public static function storage(string $sub = ''): string
    {
        $base = self::config()['storage_path'] ?? (self::root() . '/storage');
        return rtrim($base, '/') . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
    }

    public static function log(string $msg, array $ctx = []): void
    {
        $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $msg . ($ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES) : '') . "\n";
        try {
            @file_put_contents(self::storage('logs/error.log'), $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            error_log($line);
        }
    }

    public static function installErrorHandlers(bool $json = false): void
    {
        set_exception_handler(static function (\Throwable $e) use ($json): void {
            self::log('Uncaught ' . get_class($e) . ': ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            if (!headers_sent()) {
                http_response_code(500);
            }
            if ($json) {
                if (!headers_sent()) {
                    header('Content-Type: application/json');
                }
                echo json_encode(['ok' => false, 'error' => 'server_error', 'message' => 'Something went wrong. Please try again.', 'message_hi' => 'कुछ गलत हो गया। कृपया फिर से कोशिश करें।', 'retry' => true]);
            } else {
                echo '<div style="font-family:sans-serif;padding:40px;color:#c2304a">Something went wrong. The error has been logged. <a href="?r=dashboard">Back to dashboard</a></div>';
            }
        });
        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false; // silenced with @
            }
            self::log("PHP error $no: $str", ['file' => $file, 'line' => $line]);
            return true;
        });
    }
}
