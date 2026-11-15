<?php
declare(strict_types=1);
namespace HMD;

/**
 * Outbound SMS / WhatsApp through a URL template, e.g.
 * https://api.provider.in/send?user=X&key=Y&sender={sender}&to={mobile}&text={message}
 * and admin e-mail alerts. Failures are logged, never thrown.
 */
final class Notify
{
    /** @var callable|null test hook */
    public static $transport = null;

    public static function sms(string $mobile, string $message): bool
    {
        if (Settings::get('sms_enabled') !== '1' || Settings::get('sms_url_template') === '') {
            return false;
        }
        return self::send(Settings::get('sms_url_template'), $mobile, $message);
    }

    public static function whatsapp(string $mobile, string $message): bool
    {
        if (Settings::get('whatsapp_enabled') !== '1' || Settings::get('whatsapp_url_template') === '') {
            return false;
        }
        return self::send(Settings::get('whatsapp_url_template'), $mobile, $message);
    }

    /** Sends to customer on WhatsApp if enabled, else SMS. */
    public static function customer(string $mobile, string $message): bool
    {
        return self::whatsapp($mobile, $message) || self::sms($mobile, $message);
    }

    public static function buildUrl(string $tpl, string $mobile, string $message): string
    {
        return strtr($tpl, [
            '{mobile}' => rawurlencode('91' . $mobile), '{mobile10}' => rawurlencode($mobile),
            '{message}' => rawurlencode($message), '{sender}' => rawurlencode(Settings::get('sms_sender')),
        ]);
    }

    private static function send(string $tpl, string $mobile, string $message): bool
    {
        $url = self::buildUrl($tpl, $mobile, $message);
        if (!preg_match('#^https://#i', $url)) {
            App::log('Notify: only https URLs are allowed');
            return false;
        }
        try {
            if (self::$transport) {
                return (bool)(self::$transport)($url);
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $ok = $body !== false && $code >= 200 && $code < 300;
            if (!$ok) {
                App::log('Notify: send failed', ['code' => $code, 'mobile' => substr($mobile, 0, 2) . '******' . substr($mobile, -2)]);
            }
            Db::run('INSERT INTO hm_job_run (job, last_run_at, last_status, last_message, failures) VALUES ("notify",?,?,?,?) ON DUPLICATE KEY UPDATE last_run_at=VALUES(last_run_at), last_status=VALUES(last_status), last_message=VALUES(last_message), failures=failures+VALUES(failures)',
                [Fmt::nowUtc(), $ok ? 'ok' : 'fail', $ok ? 'Last message sent' : "HTTP $code", $ok ? 0 : 1]);
            return $ok;
        } catch (\Throwable $e) {
            App::log('Notify exception: ' . $e->getMessage());
            return false;
        }
    }

    public static function admin(string $subject, string $body): void
    {
        $to = Settings::get('alert_email');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            @mail($to, '[' . Settings::get('company_name') . ' Delivery] ' . $subject, $body, "Content-Type: text/plain; charset=UTF-8\r\n");
        } catch (\Throwable $e) {
            App::log('Mail failed: ' . $e->getMessage());
        }
    }
}
