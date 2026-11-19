<?php

declare(strict_types=1);

// Load .env
$envFile = dirname(__DIR__) . '/.env';

if (is_file($envFile)) {
    $lines = file(
        $envFile,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    if ($lines === false) {
        throw new RuntimeException('Gagal membaca file .env');
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if (
            $line === '' ||
            str_starts_with($line, '#') ||
            !str_contains($line, '=')
        ) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if (
            preg_match('/^[A-Z][A-Z0-9_]*$/', $key) &&
            getenv($key) === false
        ) {
            putenv($key . '=' . $value);
        }
    }
}

date_default_timezone_set('Asia/Makassar');

function envRequired(string $key): string
{
    $value = getenv($key);

    if ($value === false || trim($value) === '') {
        throw new RuntimeException(
            "Environment variable {$key} belum diatur."
        );
    }

    return $value;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . envRequired('DB_HOST')
        . ';port=' . (getenv('DB_PORT') ?: '3306')
        . ';dbname=' . envRequired('DB_NAME')
        . ';charset=utf8mb4';

    $pdo = new PDO(
        $dsn,
        envRequired('DB_USER'),
        getenv('DB_PASS') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );

    return $pdo;
}

function telegramApi(string $method, array $params): array
{
    $url = 'https://api.telegram.org/bot'
        . envRequired('TELEGRAM_BOT_TOKEN')
        . '/' . $method;

    $ch = curl_init($url);

    if ($ch === false) {
        throw new RuntimeException('cURL tidak tersedia');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(
            $params,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        ),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (
        $response === false ||
        $status < 200 ||
        $status >= 300
    ) {
        throw new RuntimeException(
            'Telegram API gagal. HTTP ' . $status . ' ' . $error
        );
    }

    $decoded = json_decode(
        $response,
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    if (!($decoded['ok'] ?? false)) {
        throw new RuntimeException('Telegram API menolak permintaan.');
    }

    return $decoded;
}

function sendMessage(
    int|string $chatId,
    string $text,
    ?array $keyboard = null
): void {
    $params = [
        'chat_id' => $chatId,
        'text' => $text
    ];

    if ($keyboard !== null) {
        $params['reply_markup'] = [
            'inline_keyboard' => $keyboard
        ];
    }

    telegramApi('sendMessage', $params);
}

function answerCallback(string $id, string $text): void
{
    telegramApi('answerCallbackQuery', [
        'callback_query_id' => $id,
        'text' => $text,
        'show_alert' => false
    ]);
}

// ========================================
// TOMBOL BATALKAN
// ========================================

function cancelKeyboard(): array
{
    return [
        [
            [
                'text' => '❌ Batalkan',
                'callback_data' => 'flow:cancel'
            ]
        ]
    ];
}

// ========================================
// TOMBOL HADIR
// ========================================

function presentKeyboard(): array
{
    return [
        [
            [
                'text' => '✅ Hadir',
                'callback_data' => 'att:present'
            ]
        ],
        ...cancelKeyboard()
    ];
}

// ========================================
// PILIH WILAYAH DINAMIS
// ========================================

/**
 * Tentukan WOK akun SF.
 * Prioritas: penempatan verified yang berlaku pada hari absensi;
 * fallback: kandidat tugas DJP terbaru (belum diverifikasi).
 * Tidak pernah membuka semua wilayah jika data penempatan kosong.
 */
function sfAreaContext(PDO $pdo, int $botSfId, ?string $date = null): array
{
    $date = $date ?? attendanceDate();
    $official = mappedOfficialSf($pdo, $botSfId);
    if (!$official) {
        return ['ok' => false, 'reason' => 'Kode SF resmi belum terhubung atau tidak valid.'];
    }
    $code = strtoupper(trim((string)$official['kode_sf']));

    $stmt = $pdo->prepare("\n        SELECT cluster, wok, effective_date\n        FROM sf_work_assignments\n        WHERE UPPER(TRIM(kode_sf)) COLLATE utf8mb4_unicode_ci = ?\n          AND verification_status = 'verified'\n          AND effective_date <= ?\n          AND (end_date IS NULL OR end_date >= ?)\n        ORDER BY effective_date DESC, id DESC\n        LIMIT 1\n    ");
    $stmt->execute([$code, $date, $date]);
    $record = $stmt->fetch();
    if ($record) {
        $wok = strtoupper(trim((string)($record['wok'] ?? '')));
        if ($wok === '' || $wok === '-') {
            return ['ok' => false, 'reason' => 'Penempatan terverifikasi belum memiliki WOK. Hubungi admin.'];
        }
        return [
            'ok' => true,
            'kode_sf' => $code,
            'cluster' => $record['cluster'],
            'wok' => $wok,
            'source' => 'VERIFIED'
        ];
    }

    // Saat belum ada verifikasi, ambil WOK dari tugas DJP TERBARU.
    // Semua tugas pada tanggal terbaru ditinjau untuk mencegah konflik WOK.
    $stmt = $pdo->prepare("\n        SELECT DISTINCT UPPER(TRIM(COALESCE(wok, ''))) AS wok,\n                        UPPER(TRIM(COALESCE(cluster, ''))) AS cluster\n        FROM sf_djp_assignments\n        WHERE UPPER(TRIM(kode_sf)) COLLATE utf8mb4_unicode_ci = ?\n          AND assignment_date = (\n              SELECT MAX(assignment_date)\n              FROM sf_djp_assignments\n              WHERE UPPER(TRIM(kode_sf)) COLLATE utf8mb4_unicode_ci = ?\n                AND assignment_date <= ?\n          )\n    ");
    $stmt->execute([$code, $code, $date]);
    $records = $stmt->fetchAll();
    if (!$records) {
        return ['ok' => false, 'reason' => 'Belum ada tugas DJP/penempatan untuk SF ini. Hubungi admin.'];
    }
    $woks = [];
    foreach ($records as $row) {
        $value = trim($row['wok'] ?? '');
        if ($value !== '' && $value !== '-') $woks[$value] = true;
    }
    if (count($woks) !== 1 || count($records) > 1) {
        return ['ok' => false, 'reason' => 'WOK DJP terakhir kosong atau tidak konsisten. Perlu verifikasi admin.'];
    }
    return [
        'ok' => true,
        'kode_sf' => $code,
        'cluster' => $records[0]['cluster'],
        'wok' => array_key_first($woks),
        'source' => 'DJP_SEMENTARA'
    ];
}

/** Daftar area yang memang dipetakan ke WOK dan aktif. */
function sfAllowedAreas(PDO $pdo, int $botSfId, ?string $date = null): array
{
    $context = sfAreaContext($pdo, $botSfId, $date);
    if (!$context['ok']) {
        return ['context' => $context, 'areas' => []];
    }
    $stmt = $pdo->prepare("\n        SELECT a.id, a.area_name\n        FROM sf_wok_area_map map\n        JOIN work_areas a ON a.id = map.area_id\n        WHERE UPPER(TRIM(map.wok)) COLLATE utf8mb4_unicode_ci = ?\n          AND map.active = 1 AND a.active = 1\n        ORDER BY a.area_name\n    ");
    $stmt->execute([$context['wok']]);
    $areas = $stmt->fetchAll();
    if (!$areas) {
        $context = ['ok' => false, 'reason' => 'WOK ' . $context['wok'] . ' belum memiliki area yang diizinkan. Hubungi admin.'];
    }
    return ['context' => $context, 'areas' => $areas];
}

/** Tombol wilayah hanya untuk akun SF bersangkutan. */
function areaKeyboard(PDO $pdo, int $botSfId): array
{
    $access = sfAllowedAreas($pdo, $botSfId);
    $keyboard = [];
    $row = [];
    foreach ($access['areas'] as $area) {
        $row[] = [
            'text' => '📍 ' . $area['area_name'],
            'callback_data' => 'att:area:' . $area['id']
        ];
        if (count($row) === 2) {
            $keyboard[] = $row;
            $row = [];
        }
    }
    if ($row) $keyboard[] = $row;
    return [...$keyboard, ...cancelKeyboard()];
}

/** Cek area terhadap WOK saat callback dan saat simpan. */
function sfAllowedArea(PDO $pdo, int $botSfId, int $areaId, ?string $date = null): ?array
{
    $access = sfAllowedAreas($pdo, $botSfId, $date);
    foreach ($access['areas'] as $area) {
        if ((int)$area['id'] === $areaId) return $area;
    }
    return null;
}

// ========================================
// PILIH AGENCY DINAMIS
// ========================================

function agencyKeyboard(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, agency_name
        FROM agencies
        WHERE active = 1
        ORDER BY agency_name ASC
    ");

    $keyboard = [];

    foreach ($stmt->fetchAll() as $agency) {
        $keyboard[] = [
            [
                'text' => $agency['agency_name'],
                'callback_data' => 'reg:agency:' . $agency['id']
            ]
        ];
    }

    return [...$keyboard, ...cancelKeyboard()];
}

// ========================================
// REQUEST KONTAK TELEGRAM
// ========================================

function sendContactPrompt(int $chatId): void
{
    telegramApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => "4/4 Silakan bagikan nomor telepon Anda.\n\n"
            . "Tekan Batalkan jika tidak ingin melanjutkan.",
        'reply_markup' => [
            'keyboard' => [
                [
                    [
                        'text' => '📱 Bagikan Kontak Saya',
                        'request_contact' => true
                    ]
                ],
                [
                    [
                        'text' => '❌ Batalkan'
                    ]
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ]
    ]);
}

function removeReplyKeyboard(int $chatId, string $text): void
{
    telegramApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'reply_markup' => [
            'remove_keyboard' => true
        ]
    ]);
}

// ========================================
// DATA SALES FORCE
// ========================================

function registeredSf(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT id, sf_code, full_name,
               active, registration_status
        FROM sales_force
        WHERE telegram_user_id = ?
        LIMIT 1
    ");

    $stmt->execute([$userId]);

    return $stmt->fetch() ?: null;
}

// ========================================
// IDENTITAS SF RESMI TELKOMSEL
// ========================================

function findOfficialSf(PDO $pdo, string $code): ?array
{
    $code = strtoupper(trim($code));
    if (!preg_match('/^[A-Z0-9.\-]{3,80}$/', $code)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT kode_sf, nama_sf, branch, company_name, cluster
        FROM sf_master_telkomsel
        WHERE UPPER(TRIM(kode_sf)) COLLATE utf8mb4_unicode_ci = ?
          AND UPPER(TRIM(branch)) = 'MAKASSAR'
          AND UPPER(company_name) LIKE '%KINARYA%'
        LIMIT 1
    ");
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

function mappedOfficialSf(PDO $pdo, int $botSfId): ?array
{
    $stmt = $pdo->prepare("
        SELECT t.kode_sf, t.nama_sf, t.cluster
        FROM sf_code_mapping m
        JOIN sf_master_telkomsel t
          ON UPPER(TRIM(t.kode_sf)) COLLATE utf8mb4_unicode_ci =
             UPPER(TRIM(m.telkomsel_sf_code)) COLLATE utf8mb4_unicode_ci
        WHERE m.bot_sf_id = ?
          AND UPPER(TRIM(t.branch)) = 'MAKASSAR'
          AND UPPER(t.company_name) LIKE '%KINARYA%'
        LIMIT 1
    ");
    $stmt->execute([$botSfId]);
    return $stmt->fetch() ?: null;
}

function officialCodeAlreadyMapped(PDO $pdo, string $code, ?int $exceptBotSfId = null): bool
{
    $stmt = $pdo->prepare("
        SELECT 1 FROM sf_code_mapping
        WHERE UPPER(TRIM(telkomsel_sf_code)) COLLATE utf8mb4_unicode_ci = ?
          AND (? IS NULL OR bot_sf_id <> ?)
        LIMIT 1
    ");
    $stmt->execute([strtoupper(trim($code)), $exceptBotSfId, $exceptBotSfId]);
    return (bool)$stmt->fetchColumn();
}

// ========================================
// SESSION REGISTRASI DAN ABSENSI
// ========================================

function getSession(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT step, data_json, updated_at
        FROM bot_sessions
        WHERE telegram_user_id = ?
        LIMIT 1
    ");

    $stmt->execute([$userId]);
    $record = $stmt->fetch();

    if (!$record) {
        return null;
    }

    // Kedaluwarsa setelah 24 jam
    if (strtotime($record['updated_at']) < time() - 86400) {
        clearSession($pdo, $userId);
        return null;
    }

    return [
        'step' => $record['step'],
        'data' => json_decode($record['data_json'], true) ?: []
    ];
}

function saveSession(
    PDO $pdo,
    int $userId,
    string $step,
    array $data = []
): void {
    $stmt = $pdo->prepare("
        INSERT INTO bot_sessions
            (telegram_user_id, step, data_json)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            step = VALUES(step),
            data_json = VALUES(data_json),
            updated_at = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        $userId,
        $step,
        json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
        )
    ]);
}

function clearSession(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare("
        DELETE FROM bot_sessions
        WHERE telegram_user_id = ?
    ");

    $stmt->execute([$userId]);
}


function normalizeSfTime(string $input): ?string
{
    $input = trim($input);

    if (!preg_match(
        '/^([01]?\d|2[0-3]):([0-5]\d)$/',
        $input,
        $matches
    )) {
        return null;
    }

    return sprintf(
        '%02d:%02d',
        (int)$matches[1],
        (int)$matches[2]
    );
}


function attendanceDate(): string
{
    return (new DateTimeImmutable(
        'now',
        new DateTimeZone('Asia/Makassar')
    ))->format('Y-m-d');
}

// ========================================
// VERIFIKASI ADMIN
// ========================================

function isAdmin(int $userId): bool
{
    $ids = array_filter(
        array_map(
            'trim',
            explode(',', getenv('ADMIN_TELEGRAM_IDS') ?: '')
        )
    );

    return in_array((string)$userId, $ids, true);
}
