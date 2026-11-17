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

function areaKeyboard(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT id, area_name
        FROM work_areas
        WHERE active = 1
        ORDER BY area_name ASC
    ");

    $keyboard = [];
    $row = [];

    foreach ($stmt->fetchAll() as $area) {
        $row[] = [
            'text' => '📍 ' . $area['area_name'],
            'callback_data' => 'att:area:' . $area['id']
        ];

        if (count($row) === 2) {
            $keyboard[] = $row;
            $row = [];
        }
    }

    if ($row) {
        $keyboard[] = $row;
    }

    return [...$keyboard, ...cancelKeyboard()];
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
        'text' => "3/3 Silakan bagikan nomor telepon Anda.\n\n"
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
