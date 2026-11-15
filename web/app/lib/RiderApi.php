<?php
declare(strict_types=1);
namespace HMD;

/**
 * JSON API for the rider app. All writes accept an idempotency key (client_uuid)
 * so offline retries never create duplicates. Errors carry English + Hindi text.
 */
final class RiderApi
{
    private ?array $rider = null;
    private ?array $token = null;
    private bool $otpOk = false;

    public function __construct(private array $body, private array $files = []) {}

    public static function err(string $code, string $en, string $hi, int $http = 400, bool $retry = false): array
    {
        return ['_http' => $http, 'ok' => false, 'error' => $code, 'message' => $en, 'message_hi' => $hi, 'retry' => $retry];
    }

    public function handle(string $method, string $path, ?string $bearer): array
    {
        $path = trim($path, '/');
        if ($method === 'POST' && $path === 'auth/login') {
            return $this->login();
        }
        if ($method === 'POST' && $path === 'auth/refresh') {
            return $this->refresh();
        }
        if (!$this->authenticate($bearer)) {
            return self::err('unauthorized', 'Session expired. Please log in again.', 'सत्र समाप्त हो गया। कृपया फिर से लॉग इन करें।', 401);
        }
        $deviceId = (string)($this->body['device_id'] ?? '');
        return match (true) {
            $method === 'GET' && $path === 'me' => $this->me(),
            $method === 'GET' && $path === 'deliveries/today' => $this->today(),
            $method === 'POST' && $path === 'duty/start' => $this->dutyStart(),
            $method === 'POST' && $path === 'duty/end' => $this->dutyEnd(),
            $method === 'POST' && $path === 'route/start' => $this->routeStart(),
            $method === 'POST' && $path === 'scan' => $this->scan(),
            $method === 'POST' && $path === 'locations/batch' => $this->locations(),
            $method === 'POST' && $path === 'media' => $this->media(),
            $method === 'POST' && $path === 'heartbeat' => $this->heartbeat(),
            $method === 'POST' && $path === 'cod/deposit' => $this->codDeposit(),
            $method === 'POST' && $path === 'auth/logout' => $this->logout(),
            $method === 'POST' && (bool)preg_match('#^deliveries/(\d+)/event$#', $path, $m) => $this->deliveryEvent((int)$m[1]),
            $method === 'POST' && (bool)preg_match('#^deliveries/(\d+)/otp/resend$#', $path, $m) => $this->otpResend((int)$m[1]),
            $method === 'POST' && (bool)preg_match('#^deliveries/(\d+)/call$#', $path, $m) => $this->callLog((int)$m[1]),
            default => self::err('not_found', 'Unknown request.', 'अज्ञात अनुरोध।', 404),
        };
    }

    // ---------------- Auth ----------------

    private function login(): array
    {
        $mobile = Validate::mobile((string)($this->body['mobile'] ?? ''));
        $pin = (string)($this->body['pin'] ?? '');
        $device = Validate::text((string)($this->body['device_id'] ?? ''), 100);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'cli';
        if (!RateLimit::hit('rlogin:' . $ip, 30, 900)) {
            return self::err('rate_limited', 'Too many attempts. Wait 15 minutes.', 'बहुत अधिक प्रयास। 15 मिनट रुकें।', 429);
        }
        if (!$mobile || !preg_match('/^\d{4,6}$/', $pin) || $device === '') {
            return self::err('invalid', 'Enter your 10-digit mobile number and PIN.', 'अपना 10 अंकों का मोबाइल नंबर और पिन डालें।', 422);
        }
        $r = Db::one('SELECT * FROM hm_rider WHERE mobile=? AND deleted_at IS NULL', [$mobile]);
        $bad = self::err('bad_login', 'Mobile number or PIN is wrong.', 'मोबाइल नंबर या पिन गलत है।', 401);
        if (!$r) {
            return $bad;
        }
        if ((int)$r['status'] !== 1) {
            return self::err('disabled', 'Your account is disabled. Contact your manager.', 'आपका खाता बंद है। अपने मैनेजर से संपर्क करें।', 403);
        }
        if ($r['locked_until'] && strtotime($r['locked_until'] . ' UTC') > time()) {
            return self::err('locked', 'Too many wrong PINs. Try after 15 minutes or ask your manager to reset.', 'बहुत बार गलत पिन। 15 मिनट बाद कोशिश करें।', 423);
        }
        if (!password_verify($pin, $r['pin_hash'])) {
            $f = (int)$r['failed_logins'] + 1;
            Db::update('hm_rider', ['failed_logins' => $f, 'locked_until' => $f >= 5 ? gmdate('Y-m-d H:i:s', time() + 900) : null], 'rider_id=?', [$r['rider_id']]);
            return $bad;
        }
        if ($r['device_id'] !== null && $r['device_id'] !== '' && !hash_equals($r['device_id'], $device)) {
            Audit::log('rider.login_other_device', 'rider', $r['rider_id'], null, ['device' => $device], ['type' => 'rider', 'id' => $r['rider_id'], 'name' => $r['name']]);
            return self::err('device_mismatch', 'This phone is not registered for you. Ask your manager to reset your phone.', 'यह फ़ोन आपके लिए पंजीकृत नहीं है। मैनेजर से फ़ोन रीसेट करवाएं।', 403);
        }
        Db::update('hm_rider', ['failed_logins' => 0, 'locked_until' => null, 'device_id' => $device], 'rider_id=?', [$r['rider_id']]);
        $tok = $this->issueTokens((int)$r['rider_id'], $device);
        Audit::log('rider.login', 'rider', $r['rider_id'], null, null, ['type' => 'rider', 'id' => $r['rider_id'], 'name' => $r['name']]);
        return ['ok' => true] + $tok + ['rider' => $this->riderPublic($r)];
    }

    private function issueTokens(int $riderId, string $device): array
    {
        $access = bin2hex(random_bytes(32));
        $refresh = bin2hex(random_bytes(32));
        $aExp = time() + 900;
        $rExp = time() + 30 * 86400;
        Db::insert('hm_rider_token', [
            'rider_id' => $riderId, 'device_id' => $device,
            'access_hash' => hash('sha256', $access), 'access_expires' => gmdate('Y-m-d H:i:s', $aExp),
            'refresh_hash' => hash('sha256', $refresh), 'refresh_expires' => gmdate('Y-m-d H:i:s', $rExp),
            'created_at' => Fmt::nowUtc(),
        ]);
        return ['access_token' => $access, 'access_expires_in' => 900, 'refresh_token' => $refresh];
    }

    private function refresh(): array
    {
        $rt = (string)($this->body['refresh_token'] ?? '');
        $device = (string)($this->body['device_id'] ?? '');
        $t = Db::one('SELECT * FROM hm_rider_token WHERE refresh_hash=?', [hash('sha256', $rt)]);
        if (!$t || (int)$t['revoked'] === 1 || strtotime($t['refresh_expires'] . ' UTC') < time() || !hash_equals($t['device_id'], $device)) {
            if ($t) {
                // A reused or stolen refresh token: revoke the whole family for this rider+device.
                Db::run('UPDATE hm_rider_token SET revoked=1 WHERE rider_id=?', [$t['rider_id']]);
            }
            return self::err('unauthorized', 'Session expired. Please log in again.', 'सत्र समाप्त हो गया। कृपया फिर से लॉग इन करें।', 401);
        }
        $r = Db::one('SELECT * FROM hm_rider WHERE rider_id=? AND status=1 AND deleted_at IS NULL', [$t['rider_id']]);
        if (!$r || $r['device_id'] !== $device) {
            return self::err('unauthorized', 'Session expired. Please log in again.', 'सत्र समाप्त हो गया। कृपया फिर से लॉग इन करें।', 401);
        }
        Db::update('hm_rider_token', ['revoked' => 1], 'token_id=?', [$t['token_id']]);
        return ['ok' => true] + $this->issueTokens((int)$t['rider_id'], $device);
    }

    private function authenticate(?string $bearer): bool
    {
        if (!$bearer || !preg_match('/^[a-f0-9]{64}$/', $bearer)) {
            return false;
        }
        $t = Db::one('SELECT * FROM hm_rider_token WHERE access_hash=? AND revoked=0', [hash('sha256', $bearer)]);
        if (!$t || strtotime($t['access_expires'] . ' UTC') < time()) {
            return false;
        }
        $r = Db::one('SELECT * FROM hm_rider WHERE rider_id=? AND status=1 AND deleted_at IS NULL', [$t['rider_id']]);
        if (!$r || $r['device_id'] !== $t['device_id']) {
            return false;
        }
        $this->token = $t;
        $this->rider = $r;
        return true;
    }

    private function logout(): array
    {
        Db::update('hm_rider_token', ['revoked' => 1], 'token_id=?', [$this->token['token_id']]);
        return ['ok' => true];
    }

    private function actor(): array
    {
        return ['type' => 'rider', 'id' => (int)$this->rider['rider_id'], 'name' => $this->rider['name']];
    }

    private function riderPublic(array $r): array
    {
        return ['rider_id' => (int)$r['rider_id'], 'name' => $r['name'], 'mobile' => $r['mobile'], 'employee_code' => $r['employee_code'],
            'vehicle_number' => $r['vehicle_number'], 'company' => Settings::get('company_name')];
    }

    private function geo(): ?array
    {
        $lat = $this->body['lat'] ?? null;
        $lng = $this->body['lng'] ?? null;
        return Validate::latLng($lat, $lng) ? ['lat' => (float)$lat, 'lng' => (float)$lng] : null;
    }

    /** Returns a stored response if this client_uuid was already processed. */
    private function replay(string $type, ?int $deliveryId = null): ?array
    {
        $u = (string)($this->body['client_uuid'] ?? '');
        if ($u === '' || !preg_match('/^[0-9a-f\-]{36}$/i', $u)) {
            return null;
        }
        $e = Db::one('SELECT event_id FROM hm_event WHERE rider_id=? AND client_uuid=?', [$this->rider['rider_id'], strtolower($u)]);
        return $e ? ['ok' => true, 'duplicate' => true] : null;
    }

    private function mark(string $type, ?int $deliveryId, ?int $dutyId, array $meta = []): void
    {
        $u = strtolower((string)($this->body['client_uuid'] ?? ''));
        Db::insert('hm_event', [
            'client_uuid' => preg_match('/^[0-9a-f\-]{36}$/', $u) ? $u : null,
            'delivery_id' => $deliveryId, 'duty_id' => $dutyId, 'rider_id' => $this->rider['rider_id'], 'type' => $type,
            'actor_type' => 'rider', 'actor_id' => $this->rider['rider_id'],
            'lat' => $this->geo()['lat'] ?? null, 'lng' => $this->geo()['lng'] ?? null,
            'device_at' => Tracking::deviceTime($this->body['device_at'] ?? null), 'server_at' => Fmt::nowUtc(),
            'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    // ---------------- Reads ----------------

    private function me(): array
    {
        return ['ok' => true, 'rider' => $this->riderPublic($this->rider), 'duty' => Tracking::activeDuty((int)$this->rider['rider_id']),
            'settings' => [
                'gps_interval_moving' => Settings::int('gps_interval_moving'), 'gps_interval_slow' => Settings::int('gps_interval_slow'),
                'gps_interval_stationary' => Settings::int('gps_interval_stationary'), 'customer_geofence_m' => Settings::int('customer_geofence_m'),
                'photo_on_delivery' => Settings::get('photo_on_delivery') === '1', 'photo_on_failure' => Settings::get('photo_on_failure') === '1',
                'require_scan_before_route' => Settings::get('require_scan_before_route') === '1',
                'fail_reasons' => StateMachine::FAIL_REASONS, 'support_mobile' => Settings::get('support_mobile'),
            ]];
    }

    private function today(): array
    {
        $rid = (int)$this->rider['rider_id'];
        [$s, $e] = Fmt::istDayRangeUtc(Fmt::todayIst());
        $rows = Db::all("SELECT delivery_id, order_no, customer_name, customer_mobile, address, landmark, pincode, cust_lat, cust_lng, items_count,
                order_value, payment_type, cod_amount, priority, is_cold_chain, notes, status, sequence, attempt_count, sla_due_at
            FROM hm_delivery WHERE rider_id=? AND deleted_at IS NULL
              AND (status IN ('assigned','loaded','out','en_route','arrived','not_delivered','rescheduled','rto') OR (closed_at>=? AND closed_at<?) OR (status='delivered' AND delivered_at>=?))
            ORDER BY FIELD(priority,'urgent','priority','normal'), sequence, delivery_id", [$rid, $s, $e, $s]);
        foreach ($rows as &$r) {
            $r['otp_required'] = Delivery::otpRequired($r);
            $r['status_label'] = StateMachine::label($r['status']);
            foreach (['delivery_id', 'items_count', 'sequence', 'attempt_count', 'is_cold_chain'] as $k) {
                $r[$k] = (int)$r[$k];
            }
            foreach (['order_value', 'cod_amount'] as $k) {
                $r[$k] = (float)$r[$k];
            }
            $r['cust_lat'] = $r['cust_lat'] !== null ? (float)$r['cust_lat'] : null;
            $r['cust_lng'] = $r['cust_lng'] !== null ? (float)$r['cust_lng'] : null;
        }
        unset($r);
        $txns = Db::all('SELECT type, mode, amount FROM hm_cod_txn WHERE rider_id=?', [$rid]);
        $duty = Tracking::activeDuty($rid);
        $sum = Summary::riderDay($rid, Fmt::todayIst());
        return ['ok' => true, 'deliveries' => $rows, 'duty' => $duty, 'cash_in_hand' => Ledger::cashInHand($txns),
            'cod_limit' => (float)$this->rider['cod_limit'], 'summary' => $sum,
            'pending_settlement' => (bool)Db::val("SELECT 1 FROM hm_settlement WHERE rider_id=? AND status='requested'", [$rid])];
    }

    // ---------------- Duty ----------------

    private function dutyStart(): array
    {
        if ($r = $this->replay('duty_start')) {
            return $r + ['duty' => Tracking::activeDuty((int)$this->rider['rider_id'])];
        }
        $rid = (int)$this->rider['rider_id'];
        if ($d = Tracking::activeDuty($rid)) {
            return ['ok' => true, 'duty' => $d, 'already' => true];
        }
        $geo = $this->geo();
        if (!$geo) {
            return self::err('gps_required', 'Turn on GPS to start duty.', 'ड्यूटी शुरू करने के लिए GPS चालू करें।', 422);
        }
        if (Settings::get('block_duty_if_unsettled') === '1') {
            [$s] = Fmt::istDayRangeUtc(Fmt::todayIst());
            $old = Db::all('SELECT type, mode, amount FROM hm_cod_txn WHERE rider_id=? AND created_at<?', [$rid, $s]);
            if (Ledger::cashInHand($old) > 0.009) {
                return self::err('cod_unsettled', 'You have unsettled COD from an earlier day. Deposit it with accounts first.', 'पिछले दिन का COD जमा नहीं हुआ है। पहले अकाउंट्स में जमा करें।', 409);
            }
        }
        $in = Tracking::warehouseCheck($this->rider['warehouse_id'] !== null ? (int)$this->rider['warehouse_id'] : null, $geo['lat'], $geo['lng']);
        $selfie = isset($this->body['selfie_media_id']) ? (int)$this->body['selfie_media_id'] : null;
        if ($selfie && !Db::val('SELECT 1 FROM hm_media WHERE media_id=? AND rider_id=?', [$selfie, $rid])) {
            $selfie = null;
        }
        // Use the phone's time when the start was recorded offline (validated: not future, < 48 h old).
        $startAt = Tracking::deviceTime($this->body['device_at'] ?? null);
        $id = Db::insert('hm_duty', [
            'rider_id' => $rid, 'duty_date' => Fmt::todayIst(), 'start_at' => $startAt,
            'start_lat' => $geo['lat'], 'start_lng' => $geo['lng'],
            'start_accuracy' => is_numeric($this->body['accuracy'] ?? null) ? (float)$this->body['accuracy'] : null,
            'start_battery' => is_numeric($this->body['battery'] ?? null) ? (int)$this->body['battery'] : null,
            'start_in_geofence' => $in === null ? null : (int)$in, 'selfie_media_id' => $selfie, 'status' => 'active',
        ]);
        $this->mark('duty_start', null, $id, ['in_geofence' => $in]);
        Tracking::setLive($rid, 'at_warehouse');
        if ($in === false) {
            Exceptions::raise('outside_warehouse', 'medium', $rid, null, "{$this->rider['name']} started duty outside the warehouse geofence.");
        }
        Audit::log('duty.start', 'duty', $id, null, null, $this->actor());
        return ['ok' => true, 'duty' => Tracking::activeDuty($rid), 'in_geofence' => $in];
    }

    private function dutyEnd(): array
    {
        $rid = (int)$this->rider['rider_id'];
        $duty = Tracking::activeDuty($rid);
        if (!$duty) {
            return ['ok' => true, 'already' => true];
        }
        $held = Db::all('SELECT order_no, status FROM hm_delivery WHERE rider_id=? AND deleted_at IS NULL AND status IN (' . Db::in(StateMachine::WITH_RIDER) . ')', array_merge([$rid], StateMachine::WITH_RIDER));
        if ($held) {
            $list = implode(', ', array_map(static fn($h) => $h['order_no'], array_slice($held, 0, 10)));
            return self::err('parcels_pending', 'Hand over these parcels at the warehouse before ending duty: ' . $list, 'ड्यूटी खत्म करने से पहले ये पार्सल गोदाम में जमा करें: ' . $list, 409) + ['orders' => array_column($held, 'order_no')];
        }
        $cash = Ledger::cashInHand(Db::all('SELECT type, mode, amount FROM hm_cod_txn WHERE rider_id=?', [$rid]));
        $shortToday = (bool)Db::val("SELECT 1 FROM hm_settlement WHERE rider_id=? AND status='short' AND accepted_at>=?", [$rid, $duty['start_at']]);
        if ($cash > 0.009 && !$shortToday) {
            return self::err('cod_pending', 'Deposit your COD cash (' . Fmt::inr($cash) . ') with accounts before ending duty.', 'ड्यूटी खत्म करने से पहले COD कैश (' . Fmt::inr($cash) . ') जमा करें।', 409);
        }
        $geo = $this->geo();
        Db::update('hm_duty', ['end_at' => Tracking::deviceTime($this->body['device_at'] ?? null), 'end_lat' => $geo['lat'] ?? null, 'end_lng' => $geo['lng'] ?? null, 'status' => 'ended', 'route_end_at' => $duty['route_end_at'] ?? Fmt::nowUtc()], 'duty_id=?', [$duty['duty_id']]);
        $km = Tracking::recomputeKm((int)$duty['duty_id']);
        $this->mark('duty_end', null, (int)$duty['duty_id']);
        Tracking::setLive($rid, 'off_duty');
        $sum = Summary::riderDay($rid, Fmt::todayIst());
        Audit::log('duty.end', 'duty', $duty['duty_id'], null, $km, $this->actor());
        return ['ok' => true, 'km' => $km, 'summary' => $sum];
    }

    private function routeStart(): array
    {
        $rid = (int)$this->rider['rider_id'];
        $duty = Tracking::activeDuty($rid);
        if (!$duty) {
            return self::err('no_duty', 'Start duty first.', 'पहले ड्यूटी शुरू करें।', 409);
        }
        if (Settings::get('require_scan_before_route') === '1') {
            $unscanned = Db::all("SELECT order_no FROM hm_delivery WHERE rider_id=? AND status='assigned' AND deleted_at IS NULL", [$rid]);
            if ($unscanned) {
                return self::err('scan_pending', 'Scan these parcels first: ' . implode(', ', array_column($unscanned, 'order_no')), 'पहले ये पार्सल स्कैन करें: ' . implode(', ', array_column($unscanned, 'order_no')), 409);
            }
        }
        $loaded = Db::all("SELECT * FROM hm_delivery WHERE rider_id=? AND status='loaded' AND deleted_at IS NULL", [$rid]);
        if (!$loaded) {
            return self::err('nothing_loaded', 'No parcels are loaded. Scan your parcels first.', 'कोई पार्सल लोड नहीं है। पहले पार्सल स्कैन करें।', 409);
        }
        $sent = 0;
        foreach ($loaded as $d) {
            $d2 = Delivery::move($d, 'out', 'rider', $rid, [], $this->geo(), null, ['duty_id' => $duty['duty_id']]);
            if (Settings::get('notify_customer_out') === '1') {
                $otpText = '';
                if (Delivery::otpRequired($d2)) {
                    $otp = Delivery::issueOtp($d2);
                    $otpText = " Share OTP $otp with the delivery person only after you receive your order.";
                }
                $msg = Settings::get('company_name') . ": Your order {$d['order_no']} is out for delivery today with {$this->rider['name']}." . $otpText;
                if (Notify::customer($d['customer_mobile'], $msg)) {
                    $sent++;
                }
            }
        }
        if (!$duty['route_start_at']) {
            Db::update('hm_duty', ['route_start_at' => Fmt::nowUtc()], 'duty_id=?', [$duty['duty_id']]);
        }
        $this->mark('route_start', null, (int)$duty['duty_id'], ['orders' => count($loaded)]);
        Tracking::setLive($rid, 'travelling');
        return ['ok' => true, 'out' => count($loaded), 'notified' => $sent];
    }

    private function scan(): array
    {
        $code = Validate::text((string)($this->body['code'] ?? ''), 60);
        $rid = (int)$this->rider['rider_id'];
        if ($code === '') {
            return self::err('invalid', 'Nothing scanned.', 'कुछ स्कैन नहीं हुआ।', 422);
        }
        $d = Db::one('SELECT * FROM hm_delivery WHERE order_no=? AND deleted_at IS NULL', [$code]);
        if (!$d) {
            return ['ok' => true, 'result' => 'not_found', 'message' => "No order $code in the system. Keep it aside and tell the dispatcher.", 'message_hi' => "सिस्टम में $code ऑर्डर नहीं है। इसे अलग रखें।"];
        }
        if ((int)$d['rider_id'] !== $rid) {
            return ['ok' => true, 'result' => 'not_yours', 'message' => "Order $code is not assigned to you. Do not take it.", 'message_hi' => "ऑर्डर $code आपको असाइन नहीं है। इसे न लें।"];
        }
        if ($d['status'] === 'loaded' || $d['status'] === 'out') {
            return ['ok' => true, 'result' => 'already', 'message' => "Order $code is already scanned.", 'message_hi' => "ऑर्डर $code पहले से स्कैन है।"];
        }
        if ($d['status'] !== 'assigned') {
            return ['ok' => true, 'result' => 'wrong_status', 'message' => "Order $code is " . StateMachine::label($d['status']) . '.', 'message_hi' => "ऑर्डर $code की स्थिति: " . StateMachine::label($d['status'])];
        }
        Delivery::move($d, 'loaded', 'rider', $rid, [], $this->geo());
        $left = (int)Db::val("SELECT COUNT(*) FROM hm_delivery WHERE rider_id=? AND status='assigned' AND deleted_at IS NULL", [$rid]);
        return ['ok' => true, 'result' => 'loaded', 'message' => "Order $code loaded. $left left to scan.", 'message_hi' => "ऑर्डर $code लोड हुआ। $left बाकी हैं।", 'remaining' => $left];
    }

    // ---------------- Tracking ----------------

    private function locations(): array
    {
        $rid = (int)$this->rider['rider_id'];
        $duty = Tracking::activeDuty($rid);
        $pts = $this->body['points'] ?? [];
        if (!is_array($pts)) {
            return self::err('invalid', 'Bad location data.', 'गलत लोकेशन डेटा।', 422);
        }
        if (!$duty) {
            // Tracking only on duty: points sent off duty are discarded by design (privacy).
            return ['ok' => true, 'stored' => 0, 'discarded' => count($pts), 'reason' => 'off_duty'];
        }
        $res = Tracking::ingest($this->rider, $duty, $pts);
        return ['ok' => true] + $res;
    }

    private function heartbeat(): array
    {
        $flags = array_intersect_key($this->body, array_flip(['gps_on', 'permission', 'background', 'battery_saver', 'battery', 'app_version']));
        Db::update('hm_rider', ['last_seen_at' => Fmt::nowUtc(), 'last_battery' => is_numeric($flags['battery'] ?? null) ? (int)$flags['battery'] : $this->rider['last_battery']], 'rider_id=?', [$this->rider['rider_id']]);
        if (isset($flags['gps_on']) && !$flags['gps_on'] && Tracking::activeDuty((int)$this->rider['rider_id'])) {
            Exceptions::raise('gps_offline', 'medium', (int)$this->rider['rider_id'], null, "{$this->rider['name']} turned GPS off while on duty.");
        }
        return ['ok' => true, 'server_time' => gmdate('c')];
    }

    // ---------------- Media ----------------

    private function media(): array
    {
        $f = $this->files['file'] ?? null;
        $kind = (string)($this->body['kind'] ?? '');
        if (!in_array($kind, ['selfie', 'pod', 'failed', 'parcel'], true)) {
            return self::err('invalid', 'Unknown photo type.', 'अज्ञात फोटो प्रकार।', 422);
        }
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) && !defined('HMD_TESTING')) {
            return self::err('upload_failed', 'Photo upload failed. Try again.', 'फोटो अपलोड नहीं हुई। फिर कोशिश करें।', 422, true);
        }
        if ($f['size'] > 1_500_000) {
            return self::err('too_large', 'Photo is too large.', 'फोटो बहुत बड़ी है।', 413);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$ext || @getimagesize($f['tmp_name']) === false) {
            return self::err('bad_type', 'Only photos (JPG, PNG) are allowed.', 'केवल फोटो (JPG, PNG) की अनुमति है।', 415);
        }
        $dir = App::storage('uploads/' . gmdate('Y/m'));
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        $moved = defined('HMD_TESTING') ? copy($f['tmp_name'], $dest) : move_uploaded_file($f['tmp_name'], $dest);
        if (!$moved) {
            return self::err('upload_failed', 'Could not save the photo.', 'फोटो सेव नहीं हुई।', 500, true);
        }
        $geo = $this->geo();
        $id = Db::insert('hm_media', ['rider_id' => $this->rider['rider_id'], 'kind' => $kind, 'file_path' => gmdate('Y/m') . '/' . $name,
            'mime' => $mime, 'size_bytes' => (int)$f['size'], 'lat' => $geo['lat'] ?? null, 'lng' => $geo['lng'] ?? null, 'created_at' => Fmt::nowUtc()]);
        return ['ok' => true, 'media_id' => $id];
    }

    // ---------------- Delivery events ----------------

    private function callLog(int $id): array
    {
        $d = Delivery::get($id);
        if (!$d || (int)$d['rider_id'] !== (int)$this->rider['rider_id']) {
            return self::err('not_found', 'Order not found.', 'ऑर्डर नहीं मिला।', 404);
        }
        if ($r = $this->replay('call', $id)) {
            return $r;
        }
        $this->mark('call', $id, null, ['duration_s' => (int)($this->body['duration_s'] ?? 0)]);
        return ['ok' => true];
    }

    private function otpResend(int $id): array
    {
        $d = Delivery::get($id);
        if (!$d || (int)$d['rider_id'] !== (int)$this->rider['rider_id'] || !in_array($d['status'], ['out', 'en_route', 'arrived'], true)) {
            return self::err('not_found', 'Order not found.', 'ऑर्डर नहीं मिला।', 404);
        }
        if (!RateLimit::hit('otp_resend:' . $id, 3, 3600)) {
            return self::err('rate_limited', 'OTP already sent 3 times. Use photo proof and call the customer.', 'OTP 3 बार भेजा जा चुका है।', 429);
        }
        $otp = Delivery::issueOtp($d);
        $ok = Notify::customer($d['customer_mobile'], Settings::get('company_name') . ": OTP for order {$d['order_no']} is $otp. Share it only after you receive your order.");
        return ['ok' => $ok, 'message' => $ok ? 'OTP sent to customer.' : 'Could not send OTP. Use photo proof.'];
    }

    private function deliveryEvent(int $id): array
    {
        $rid = (int)$this->rider['rider_id'];
        $d = Delivery::get($id);
        if (!$d || (int)$d['rider_id'] !== $rid) {
            return self::err('not_found', 'Order not found or not assigned to you.', 'ऑर्डर नहीं मिला या आपको असाइन नहीं है।', 404);
        }
        if ($r = $this->replay('delivery', $id)) {
            return $r + ['delivery' => Delivery::get($id)];
        }
        $type = (string)($this->body['type'] ?? '');
        $duty = Tracking::activeDuty($rid);
        if (!$duty) {
            return self::err('no_duty', 'Start duty first.', 'पहले ड्यूटी शुरू करें।', 409);
        }
        $geo = $this->geo();
        $deviceAt = Tracking::deviceTime($this->body['device_at'] ?? null);
        $dist = ($geo && $d['cust_lat'] !== null) ? (int)round(Geo::distanceM($geo['lat'], $geo['lng'], (float)$d['cust_lat'], (float)$d['cust_lng'])) : null;
        $uuid = strtolower((string)($this->body['client_uuid'] ?? ''));
        $uuid = preg_match('/^[0-9a-f\-]{36}$/', $uuid) ? $uuid : null;

        // OTP is checked outside the transaction so the wrong-try counter survives a rejected request.
        $this->otpOk = false;
        if ($type === 'delivered' && Delivery::otpRequired($d) && (string)($this->body['otp'] ?? '') !== '') {
            if ((int)$d['otp_tries'] >= 5) {
                return self::err('otp_locked', 'Too many wrong OTPs. Use photo proof.', 'बहुत बार गलत OTP। फोटो प्रूफ लें।', 409);
            }
            if (!Delivery::checkOtp($d, (string)$this->body['otp'])) {
                Db::run('UPDATE hm_delivery SET otp_tries=otp_tries+1 WHERE delivery_id=?', [$d['delivery_id']]);
                return self::err('otp_wrong', 'OTP is wrong. Ask the customer to check the SMS.', 'OTP गलत है। ग्राहक से SMS दोबारा देखने को कहें।', 409);
            }
            $this->otpOk = true;
        }
        try {
            return Db::tx(function () use ($type, $d, $rid, $duty, $geo, $deviceAt, $dist, $uuid) {
                $meta = ['duty_id' => $duty['duty_id'], 'distance_m' => $dist];
                $attempt = Db::one('SELECT * FROM hm_attempt WHERE delivery_id=? AND closed_at IS NULL ORDER BY attempt_id DESC LIMIT 1', [$d['delivery_id']]);
                switch ($type) {
                    case 'start':
                        $d2 = Delivery::move($d, 'en_route', 'rider', $rid, ['attempt_count' => (int)$d['attempt_count'] + 1], $geo, $deviceAt, $meta);
                        Db::insert('hm_attempt', ['delivery_id' => $d['delivery_id'], 'rider_id' => $rid, 'duty_id' => $duty['duty_id'], 'attempt_no' => (int)$d['attempt_count'] + 1, 'started_at' => $deviceAt]);
                        Tracking::setLive($rid, 'travelling');
                        break;
                    case 'arrived':
                        $manual = !empty($this->body['manual']);
                        $d2 = Delivery::move($d, 'arrived', 'rider', $rid, [], $geo, $deviceAt, $meta + ['manual' => $manual]);
                        if ($attempt) {
                            Db::update('hm_attempt', ['arrived_at' => $deviceAt, 'arrived_manual' => $manual ? 1 : 0, 'distance_from_address_m' => $dist], 'attempt_id=?', [$attempt['attempt_id']]);
                        }
                        if ($manual && $dist !== null && $dist > Settings::int('manual_arrive_flag_m')) {
                            Exceptions::raise('manual_arrival_far', 'medium', $rid, (int)$d['delivery_id'], "{$this->rider['name']} marked arrival for {$d['order_no']} manually, {$dist} m from the address.");
                        }
                        Tracking::setLive($rid, 'at_customer');
                        break;
                    case 'delivered':
                        $d2 = $this->delivered($d, $attempt, $geo, $deviceAt, $dist, $meta);
                        break;
                    case 'failed':
                        $d2 = $this->failed($d, $attempt, $geo, $deviceAt, $dist, $meta);
                        break;
                    default:
                        throw new \InvalidArgumentException('Unknown action.');
                }
                if ($uuid) {
                    Db::run('UPDATE hm_event SET client_uuid=? WHERE event_id=(SELECT id FROM (SELECT MAX(event_id) id FROM hm_event WHERE delivery_id=?) t)', [$uuid, $d['delivery_id']]);
                }
                return ['ok' => true, 'delivery' => $d2];
            });
        } catch (\DomainException | \InvalidArgumentException $e) {
            return self::err('not_allowed', $e->getMessage(), 'यह कार्रवाई अभी संभव नहीं है। सूची रीफ़्रेश करें।', 409);
        }
    }

    private function delivered(array $d, ?array $attempt, ?array $geo, string $deviceAt, ?int $dist, array $meta): array
    {
        $rid = (int)$this->rider['rider_id'];
        if (!$geo) {
            throw new \InvalidArgumentException('GPS location is needed to mark delivered. Turn on GPS.');
        }
        // proof
        $otpOk = $this->otpOk;
        $photo = isset($this->body['photo_media_id']) ? (int)$this->body['photo_media_id'] : 0;
        if ($photo && !Db::val('SELECT 1 FROM hm_media WHERE media_id=? AND rider_id=?', [$photo, $rid])) {
            $photo = 0;
        }
        if (Delivery::otpRequired($d) && !$otpOk && !$photo) {
            throw new \InvalidArgumentException('Enter the customer OTP, or take a photo if the OTP did not arrive.');
        }
        if (Settings::get('photo_on_delivery') === '1' && !$photo && !$otpOk) {
            throw new \InvalidArgumentException('Take a photo of the parcel being handed over.');
        }
        // COD
        $mode = null;
        if ($d['payment_type'] === 'cod') {
            $mode = (string)($this->body['payment_mode'] ?? '');
            $amt = Validate::money($this->body['amount'] ?? null);
            if (!in_array($mode, ['cash', 'upi'], true)) {
                throw new \InvalidArgumentException('Select how the customer paid: Cash or UPI.');
            }
            if ($amt === null || abs($amt - (float)$d['cod_amount']) > 0.009) {
                throw new \InvalidArgumentException('Collect exactly ' . Fmt::inr($d['cod_amount']) . '.');
            }
            $ref = Validate::text((string)($this->body['upi_ref'] ?? ''), 100);
            if ($mode === 'upi' && !preg_match('/^[A-Za-z0-9]{6,30}$/', $ref)) {
                throw new \InvalidArgumentException('Enter the UPI transaction ID (UTR) shown on the customer\'s phone.');
            }
            Db::insert('hm_cod_txn', ['rider_id' => $rid, 'delivery_id' => $d['delivery_id'], 'type' => 'collect', 'mode' => $mode, 'amount' => $amt, 'reference' => $ref ?: null, 'created_at' => Fmt::nowUtc()]);
        }
        if ($d['status'] === 'en_route') {
            // Arrival not detected (e.g. app auto-arrive missed): record it now.
            $d = Delivery::move($d, 'arrived', 'rider', $rid, [], $geo, $deviceAt, $meta + ['implicit' => true]);
            if ($attempt) {
                Db::update('hm_attempt', ['arrived_at' => $deviceAt, 'distance_from_address_m' => $dist], 'attempt_id=?', [$attempt['attempt_id']]);
            }
        }
        $extra = [];
        if ($d['cust_lat'] === null) {
            $extra = ['cust_lat' => $geo['lat'], 'cust_lng' => $geo['lng']]; // learn the customer pin
        }
        $d2 = Delivery::move($d, 'delivered', 'rider', $rid, $extra, $geo, $deviceAt, $meta + ['otp' => $otpOk, 'photo' => (bool)$photo, 'payment_mode' => $mode]);
        if ($attempt) {
            Db::update('hm_attempt', ['closed_at' => $deviceAt, 'outcome' => 'delivered', 'received_by' => Validate::text((string)($this->body['received_by'] ?? ''), 100) ?: null], 'attempt_id=?', [$attempt['attempt_id']]);
            if ($photo) {
                Db::insert('hm_proof', ['attempt_id' => $attempt['attempt_id'], 'type' => 'photo', 'media_id' => $photo, 'created_at' => Fmt::nowUtc()]);
            }
            if ($otpOk) {
                Db::insert('hm_proof', ['attempt_id' => $attempt['attempt_id'], 'type' => 'otp', 'otp_verified' => 1, 'created_at' => Fmt::nowUtc()]);
            }
        }
        if ($dist !== null && $dist > Settings::int('away_flag_m')) {
            Exceptions::raise('delivered_away', 'high', $rid, (int)$d['delivery_id'], "{$this->rider['name']} marked {$d['order_no']} delivered {$dist} m from the customer address.");
        }
        $cash = Ledger::cashInHand(Db::all('SELECT type, mode, amount FROM hm_cod_txn WHERE rider_id=?', [$rid]));
        if ($cash > (float)$this->rider['cod_limit']) {
            Exceptions::raise('cod_limit', 'medium', $rid, null, "{$this->rider['name']} is carrying " . Fmt::inr($cash) . ' cash, above the limit of ' . Fmt::inr($this->rider['cod_limit']) . '.');
        }
        if (Settings::get('notify_customer_delivered') === '1') {
            Notify::customer($d['customer_mobile'], Settings::get('company_name') . ": Order {$d['order_no']} was delivered at " . Fmt::time(Fmt::nowUtc()) . '. Thank you!');
        }
        Tracking::setLive($rid, 'available');
        return $d2;
    }

    private function failed(array $d, ?array $attempt, ?array $geo, string $deviceAt, ?int $dist, array $meta): array
    {
        $rid = (int)$this->rider['rider_id'];
        $reason = (string)($this->body['reason'] ?? '');
        if (!isset(StateMachine::FAIL_REASONS[$reason])) {
            throw new \InvalidArgumentException('Choose a reason.');
        }
        $remark = Validate::text((string)($this->body['remark'] ?? ''), 500);
        if ($reason === 'other' && mb_strlen($remark) < 10) {
            throw new \InvalidArgumentException('Write what happened (at least 10 letters).');
        }
        if (!$geo) {
            throw new \InvalidArgumentException('GPS location is needed. Turn on GPS.');
        }
        $photo = isset($this->body['photo_media_id']) ? (int)$this->body['photo_media_id'] : 0;
        if ($photo && !Db::val('SELECT 1 FROM hm_media WHERE media_id=? AND rider_id=?', [$photo, $rid])) {
            $photo = 0;
        }
        if (Settings::get('photo_on_failure') === '1' && !$photo && $reason !== 'not_reachable') {
            throw new \InvalidArgumentException('Take a photo at the door as proof.');
        }
        $resDate = null;
        if ($reason === 'reschedule_request') {
            $resDate = (string)($this->body['reschedule_date'] ?? '');
            if (!Validate::date($resDate) || $resDate < Fmt::todayIst()) {
                throw new \InvalidArgumentException('Pick the date the customer wants.');
            }
        }
        $calls = (int)Db::val("SELECT COUNT(*) FROM hm_event WHERE delivery_id=? AND type='call' AND server_at>=?", [$d['delivery_id'], $attempt['started_at'] ?? $d['out_at'] ?? $d['assigned_at']]);
        $d = Delivery::move($d, 'not_delivered', 'rider', $rid, [], $geo, $deviceAt, $meta + ['reason' => $reason, 'remark' => $remark]);
        $next = StateMachine::afterFailure($reason, (int)$d['attempt_count'], max(1, Settings::int('max_attempts')));
        $d2 = Delivery::move($d, $next, 'system', null, $next === 'rescheduled' ? ['scheduled_for' => $resDate] : [], null, null, ['reason' => $reason]);
        $outcome = $reason === 'customer_refused' ? 'refused' : ($reason === 'reschedule_request' ? 'rescheduled' : 'failed');
        if ($attempt) {
            Db::update('hm_attempt', ['closed_at' => $deviceAt, 'outcome' => $outcome, 'reason_code' => $reason, 'remark' => $remark ?: null, 'reschedule_date' => $resDate,
                'distance_from_address_m' => $attempt['distance_from_address_m'] ?? $dist], 'attempt_id=?', [$attempt['attempt_id']]);
            if ($photo) {
                Db::insert('hm_proof', ['attempt_id' => $attempt['attempt_id'], 'type' => 'photo', 'media_id' => $photo, 'created_at' => Fmt::nowUtc()]);
            }
        }
        if ($dist !== null && $dist > Settings::int('away_flag_m')) {
            Exceptions::raise('failed_away', 'high', $rid, (int)$d['delivery_id'], "{$this->rider['name']} marked {$d['order_no']} as not delivered (" . StateMachine::FAIL_REASONS[$reason] . ") {$dist} m from the address.");
        }
        if ($calls === 0 && in_array($reason, ['customer_unavailable', 'not_reachable'], true)) {
            Exceptions::raise('no_call_before_fail', 'medium', $rid, (int)$d['delivery_id'], "{$this->rider['name']} marked {$d['order_no']} '" . StateMachine::FAIL_REASONS[$reason] . "' without calling from the app.");
        }
        Tracking::setLive($rid, 'available');
        return $d2;
    }

    private function codDeposit(): array
    {
        $rid = (int)$this->rider['rider_id'];
        $cash = Ledger::cashInHand(Db::all('SELECT type, mode, amount FROM hm_cod_txn WHERE rider_id=?', [$rid]));
        if ($cash <= 0.009) {
            return ['ok' => true, 'message' => 'No cash to deposit.', 'expected' => 0];
        }
        $open = Db::one("SELECT * FROM hm_settlement WHERE rider_id=? AND status='requested'", [$rid]);
        if ($open) {
            Db::update('hm_settlement', ['expected' => $cash], 'settlement_id=?', [$open['settlement_id']]);
            return ['ok' => true, 'settlement_id' => (int)$open['settlement_id'], 'expected' => $cash];
        }
        $id = Db::insert('hm_settlement', ['rider_id' => $rid, 'expected' => $cash, 'status' => 'requested', 'requested_at' => Fmt::nowUtc()]);
        Audit::log('cod.deposit_request', 'settlement', $id, null, ['expected' => $cash], $this->actor());
        return ['ok' => true, 'settlement_id' => $id, 'expected' => $cash, 'message' => 'Hand ' . Fmt::inr($cash) . ' to accounts. They will confirm.'];
    }
}
