<?php
/**
 * ไฟล์: includes/google_auth.php
 * เข้าสู่ระบบ / สมัครสมาชิกด้วยบัญชี Google (OAuth 2.0 Authorization Code + PKCE + state)
 *
 * ขั้นตอน: auth/google_login.php → หน้า Google → auth/google_callback.php
 *   - พบบัญชีที่เชื่อม Google ไว้แล้ว / อีเมลตรงกับบัญชีเดิม → เข้าสู่ระบบ (ถ้าอนุมัติแล้ว)
 *   - ยังไม่มีบัญชี → ไปหน้าสมัครสมาชิก กรอกข้อมูลเพิ่ม (แผนก ตำแหน่ง เบอร์โทร) แล้วรอ admin อนุมัติ
 * ตั้งค่าที่ ตั้งค่าระบบ: google_enabled, google_client_id, google_client_secret, google_allowed_domain
 */

/** เปิดใช้และตั้งค่าครบหรือไม่ */
function google_enabled(): bool
{
    return setting('google_enabled', '0') === '1'
        && trim((string)setting('google_client_id')) !== ''
        && trim((string)setting('google_client_secret')) !== '';
}

/** Redirect URI ที่ต้องลงทะเบียนใน Google Cloud Console */
function google_redirect_uri(): string
{
    return site_base_url() . '/auth/google_callback.php';
}

/** โดเมนอีเมลที่อนุญาต (ว่าง = ทุกโดเมน) */
function google_allowed_domain(): string
{
    return strtolower(trim((string)setting('google_allowed_domain'), " @\t"));
}

/**
 * URL ของ Google — โหมดพัฒนา (APP_DEBUG) ใช้เซิร์ฟเวอร์จำลองได้ผ่าน setting google_mock_base เพื่อทดสอบอัตโนมัติ
 */
function google_endpoint(string $name): string
{
    $mock = APP_DEBUG ? rtrim((string)setting('google_mock_base'), '/') : '';
    if ($mock !== '') {
        return $mock . '/' . $name;
    }
    $map = [
        'auth'     => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token'    => 'https://oauth2.googleapis.com/token',
        'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo',
    ];
    return $map[$name];
}

/** base64url (ไม่มี =) */
function google_b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/**
 * สร้าง URL ไปหน้าเลือกบัญชี Google และเก็บ state/PKCE ไว้ใน session
 * @param string $mode login (เข้าสู่ระบบ/สมัคร) | link (เชื่อมบัญชีจากหน้าโปรไฟล์)
 */
function google_auth_url(string $mode, string $next = ''): string
{
    $state = bin2hex(random_bytes(16));
    $verifier = google_b64url(random_bytes(32));
    $_SESSION['google_oauth'] = [
        'state'    => $state,
        'verifier' => $verifier,
        'mode'     => $mode,
        'next'     => $next,
        'at'       => time(),
    ];
    $params = [
        'client_id'             => trim((string)setting('google_client_id')),
        'redirect_uri'          => google_redirect_uri(),
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'state'                 => $state,
        'code_challenge'        => google_b64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt'                => 'select_account',
    ];
    if (google_allowed_domain() !== '') {
        $params['hd'] = google_allowed_domain();
    }
    return google_endpoint('auth') . '?' . http_build_query($params);
}

/** เรียก HTTP (POST form หรือ GET + Bearer) แล้วคืน JSON เป็น array */
function google_http(string $url, ?array $post = null, string $bearer = ''): ?array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($bearer !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) {
        error_log('Google OAuth HTTP ' . $code . ' ' . $err . ' ' . substr((string)$body, 0, 300));
        return null;
    }
    $json = json_decode((string)$body, true);
    return is_array($json) ? $json : null;
}

/**
 * ตรวจ state แล้วแลก code เป็นข้อมูลผู้ใช้ Google
 * @return array ['ok'=>bool, 'error'=>string, 'mode'=>..., 'next'=>..., 'profile'=>[sub,email,given_name,family_name,picture]]
 */
function google_handle_callback(array $query): array
{
    $saved = $_SESSION['google_oauth'] ?? null;
    unset($_SESSION['google_oauth']); // ใช้ได้ครั้งเดียว
    $fail = fn(string $msg) => ['ok' => false, 'error' => $msg, 'mode' => $saved['mode'] ?? 'login', 'next' => $saved['next'] ?? ''];

    if (!$saved || !is_string($query['state'] ?? null) || !hash_equals($saved['state'], $query['state'])) {
        return $fail('คำขอเข้าสู่ระบบด้วย Google ไม่ถูกต้องหรือหมดอายุ กรุณาลองใหม่');
    }
    if (time() - (int)$saved['at'] > 600) {
        return $fail('หมดเวลาการเข้าสู่ระบบด้วย Google กรุณาลองใหม่');
    }
    if (!empty($query['error'])) {
        return $fail('ยกเลิกการเข้าสู่ระบบด้วย Google');
    }
    $code = is_string($query['code'] ?? null) ? $query['code'] : '';
    if ($code === '') {
        return $fail('ไม่ได้รับรหัสยืนยันจาก Google');
    }

    $token = google_http(google_endpoint('token'), [
        'code'          => $code,
        'client_id'     => trim((string)setting('google_client_id')),
        'client_secret' => trim((string)setting('google_client_secret')),
        'redirect_uri'  => google_redirect_uri(),
        'grant_type'    => 'authorization_code',
        'code_verifier' => $saved['verifier'],
    ]);
    if (!$token || empty($token['access_token'])) {
        return $fail('เชื่อมต่อ Google ไม่สำเร็จ (ตรวจ Client ID/Secret และ Redirect URI ในตั้งค่าระบบ)');
    }
    $info = google_http(google_endpoint('userinfo'), null, (string)$token['access_token']);
    if (!$info || empty($info['sub']) || empty($info['email'])) {
        return $fail('อ่านข้อมูลบัญชี Google ไม่สำเร็จ');
    }
    $verified = $info['email_verified'] ?? false;
    if (!($verified === true || $verified === 'true')) {
        return $fail('อีเมลของบัญชี Google นี้ยังไม่ได้ยืนยัน');
    }
    $email = mb_strtolower((string)$info['email']);
    $domain = google_allowed_domain();
    if ($domain !== '' && substr($email, -strlen('@' . $domain)) !== '@' . $domain) {
        return $fail('อนุญาตเฉพาะบัญชี Google ของโดเมน @' . $domain);
    }
    return [
        'ok' => true, 'error' => '', 'mode' => $saved['mode'], 'next' => (string)$saved['next'],
        'profile' => [
            'sub'         => substr((string)$info['sub'], 0, 64),
            'email'       => $email,
            'given_name'  => mb_substr((string)($info['given_name'] ?? ''), 0, 100),
            'family_name' => mb_substr((string)($info['family_name'] ?? ''), 0, 100),
        ],
    ];
}

/** ข้อมูล Google ที่รอสมัครสมาชิก (อายุ 30 นาที) */
function google_pending_signup(): ?array
{
    $g = $_SESSION['google_signup'] ?? null;
    if (!is_array($g) || time() - (int)($g['at'] ?? 0) > 1800) {
        unset($_SESSION['google_signup']);
        return null;
    }
    return $g;
}

/** ชื่อผู้ใช้แนะนำจากอีเมล (ไม่ซ้ำ) */
function google_suggest_username(string $email): string
{
    $base = strtolower(preg_replace('/[^a-z0-9_.-]/i', '', strstr($email, '@', true) ?: 'user'));
    $base = substr($base !== '' ? $base : 'user', 0, 24);
    if (strlen($base) < 4) {
        $base = str_pad($base, 4, '0');
    }
    $name = $base;
    for ($i = 2; db_value('SELECT id FROM users WHERE username = ?', 's', [$name]); $i++) {
        $name = $base . $i;
    }
    return $name;
}

/** ปุ่ม "เข้าสู่ระบบด้วย Google" */
function google_button_html(string $label, string $href): string
{
    return '<a href="' . e($href) . '" class="btn btn-outline-secondary w-100 py-2 d-flex align-items-center justify-content-center gap-2 bg-white text-dark">'
        . '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>'
        . '<span>' . e($label) . '</span></a>';
}
