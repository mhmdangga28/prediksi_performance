<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$secret = envRequired('TELEGRAM_WEBHOOK_SECRET');
$given = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';

if (!is_string($given) || !hash_equals($secret, $given)) {
    http_response_code(403);
    exit('Forbidden');
}

try {
    $raw = file_get_contents('php://input');

    if ($raw === false || strlen($raw) > 256000) {
        http_response_code(400);
        exit('Invalid payload');
    }

    $update = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $pdo = db();

    if (isset($update['message'])) {
        $message = $update['message'];

        if (($message['chat']['type'] ?? '') !== 'private') {
            exit('OK');
        }

        $chatId = (int)($message['chat']['id'] ?? 0);
        $userId = (int)($message['from']['id'] ?? 0);

        if ($chatId <= 0 || $chatId !== $userId) {
            exit('OK');
        }

        $input = trim((string)($message['text'] ?? ''));
        $session = getSession($pdo, $userId);
        $sf = registeredSf($pdo, $userId);

        // ====================================
        // BATALKAN PROSES
        // ====================================

        if (
            preg_match('~^/batal(?:@\w+)?$~i', $input) ||
            $input === '❌ Batalkan'
        ) {
            clearSession($pdo, $userId);

            removeReplyKeyboard(
                $chatId,
                "❌ Proses dibatalkan.\n\n"
                    . "Data sementara tidak disimpan.\n"
                    . "Ketik /start untuk membuka menu."
            );

            // ====================================
            // START DAN HELP
            // ====================================

        } elseif (preg_match('~^/(start|help)(?:@\w+)?$~i', $input)) {
            clearSession($pdo, $userId);

            removeReplyKeyboard(
                $chatId,
                "👋 Selamat datang di Bot Absensi SF!\n\n"
                    . "📋 MENU\n\n"
                    . "/daftar - Registrasi SF\n"
                    . "/absen - Rencana aktivitas harian\n"
                    . "/status - Status kehadiran\n"
                    . "/pulang - Catat waktu pulang aktual\n"
                    . "/batal - Batalkan proses\n"
                    . "/id - ID Telegram Anda\n\n"
                    . "Gunakan /daftar jika belum memiliki akun."
            );
        } elseif (preg_match('~^/id(?:@\w+)?$~i', $input)) {
            sendMessage($chatId, "Telegram User ID: {$userId}");

            // ====================================
            // MULAI REGISTRASI
            // ====================================

        } elseif (preg_match('~^/daftar(?:@\w+)?$~i', $input)) {
            if ($sf) {
                sendMessage(
                    $chatId,
                    "Anda sudah terdaftar.\n\n"
                        . "Kode: {$sf['sf_code']}\n"
                        . "Nama: {$sf['full_name']}\n"
                        . "Status: {$sf['registration_status']}"
                );
            } else {
                saveSession($pdo, $userId, 'reg_name');

                sendMessage(
                    $chatId,
                    "📝 REGISTRASI SALES FORCE\n\n"
                        . "Pertanyaan 1 dari 3\n\n"
                        . "Siapa nama lengkap Anda?",
                    cancelKeyboard()
                );
            }

            // ====================================
            // MULAI ABSENSI
            // ====================================

        } elseif (preg_match('~^/absen(?:@\w+)?$~i', $input)) {
            if (!$sf) {
                sendMessage(
                    $chatId,
                    "Anda belum terdaftar.\nKetik /daftar."
                );
            } elseif (
                $sf['registration_status'] !== 'approved' ||
                (int)$sf['active'] !== 1
            ) {
                sendMessage(
                    $chatId,
                    "Akun belum disetujui admin.\n"
                        . "Status: {$sf['registration_status']}"
                );
            } else {
                $stmt = $pdo->prepare("
                    SELECT id FROM sf_attendance
                    WHERE sf_id = ?
                      AND attendance_date = ?
                    LIMIT 1
                ");

                $stmt->execute([$sf['id'], attendanceDate()]);

                if ($stmt->fetch()) {
                    sendMessage(
                        $chatId,
                        'Anda sudah absen hari ini. Ketik /status.'
                    );
                } else {
                    saveSession($pdo, $userId, 'att_present');

                    sendMessage(
                        $chatId,
                        "Halo {$sf['full_name']}!\n\n"
                            . "Apakah Anda hadir untuk aktivitas penjualan?",
                        presentKeyboard()
                    );
                }
            }

            // ====================================
            // STATUS ABSENSI
            // ====================================

        } elseif (preg_match('~^/status(?:@\w+)?$~i', $input)) {
            if (!$sf) {
                sendMessage($chatId, 'Belum terdaftar. Ketik /daftar.');
            } elseif (
                $sf['registration_status'] !== 'approved' ||
                (int)$sf['active'] !== 1
            ) {
                sendMessage(
                    $chatId,
                    "Nama: {$sf['full_name']}\n"
                        . "Kode: {$sf['sf_code']}\n"
                        . "Registrasi: {$sf['registration_status']}"
                );
            } else {
                $stmt = $pdo->prepare("
                    SELECT a.checkin_at, a.planned_start_time, a.planned_end_time, a.actual_checkout_at, w.area_name
                    FROM sf_attendance a
                    JOIN work_areas w ON w.id = a.area_id
                    WHERE a.sf_id = ?
                      AND a.attendance_date = ?
                    LIMIT 1
                ");

                $stmt->execute([$sf['id'], attendanceDate()]);
                $record = $stmt->fetch();

                if ($record) {
                    sendMessage(
                        $chatId,
                        "✅ SUDAH HADIR\n\n"
                            . "Rencana lokasi: {$record['area_name']}\n"
                            . "Mulai (rencana): {$record['planned_start_time']} WITA\n"
                            . "Pulang (rencana): {$record['planned_end_time']} WITA\n"
                            . "Check-in tercatat: {$record['checkin_at']} WITA\n"
                            . "Pulang aktual: " . ($record['actual_checkout_at'] ?: 'Belum dilaporkan')
                    );
                } else {
                    sendMessage(
                        $chatId,
                        'Belum melakukan absensi hari ini. Ketik /absen.'
                    );
                }
            }

            // ====================================
            // PULANG AKTUAL (BERBEDA DARI RENCANA)
            // ====================================

        } elseif (preg_match('~^/pulang(?:@\w+)?$~i', $input)) {
            if (!$sf || $sf['registration_status'] !== 'approved' || (int)$sf['active'] !== 1) {
                sendMessage($chatId, 'Akun belum aktif.');
            } else {
                $stmt = $pdo->prepare("UPDATE sf_attendance SET actual_checkout_at = ? WHERE sf_id = ? AND attendance_date = ? AND actual_checkout_at IS NULL");
                $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar')))->format('Y-m-d H:i:s');
                $stmt->execute([$now, $sf['id'], attendanceDate()]);
                sendMessage($chatId, $stmt->rowCount() === 1
                    ? "✅ Jam pulang aktual dicatat: {$now} WITA"
                    : 'Tidak ada absensi hari ini yang belum mencatat pulang.');
            }

            // ====================================
            // APPROVAL ADMIN
            // ====================================

        } elseif (preg_match(
            '~^/(approve|reject)(?:@\w+)?\s+(SF\d+)$~i',
            $input,
            $matches
        )) {
            if (!isAdmin($userId)) {
                sendMessage($chatId, 'Perintah ini hanya untuk admin.');
            } else {
                $status = strtolower($matches[1]) === 'approve'
                    ? 'approved' : 'rejected';
                $code = strtoupper($matches[2]);

                $stmt = $pdo->prepare("
                    UPDATE sales_force
                    SET registration_status = ?, active = ?
                    WHERE sf_code = ?
                      AND registration_status = 'pending'
                ");

                $stmt->execute([
                    $status,
                    $status === 'approved' ? 1 : 0,
                    $code
                ]);

                if ($stmt->rowCount() === 1) {
                    sendMessage(
                        $chatId,
                        "Status {$code} menjadi {$status}."
                    );

                    $stmt = $pdo->prepare("
                        SELECT telegram_user_id
                        FROM sales_force WHERE sf_code = ?
                    ");
                    $stmt->execute([$code]);

                    $target = (int)$stmt->fetchColumn();

                    if ($target > 0) {
                        sendMessage(
                            $target,
                            $status === 'approved'
                                ? "✅ Registrasi {$code} disetujui. Ketik /absen."
                                : "❌ Registrasi {$code} ditolak. Hubungi admin."
                        );
                    }
                } else {
                    sendMessage(
                        $chatId,
                        'Kode tidak ditemukan atau sudah diproses.'
                    );
                }
            }

            // ====================================
            // INPUT JAM MULAI / PULANG RENCANA
            // ====================================

        } elseif ($session && $session['step'] === 'att_start') {
            $time = normalizeSfTime($input);
            if ($time === null) {
                sendMessage($chatId, 'Masukkan jam mulai dengan format HH:MM (contoh 08:00).', cancelKeyboard());
            } else {
                saveSession($pdo, $userId, 'att_end', ['planned_start_time' => $time]);
                sendMessage($chatId, "2/3 Pukul berapa Anda RENCANA pulang hari ini?\nContoh: 17:00 (WITA)", cancelKeyboard());
            }
        } elseif ($session && $session['step'] === 'att_end') {
            $time = normalizeSfTime($input);
            $start = $session['data']['planned_start_time'] ?? '';
            if ($time === null || $time <= $start) {
                sendMessage($chatId, 'Masukkan HH:MM. Jam pulang harus setelah jam mulai (di hari yang sama). Contoh 17:00.', cancelKeyboard());
            } else {
                $data = $session['data'];
                $data['planned_end_time'] = $time;
                saveSession($pdo, $userId, 'att_area', $data);
                sendMessage($chatId, "3/3 Di wilayah mana Anda BERENCANA berjualan hari ini?", areaKeyboard($pdo));
            }

            // ====================================
            // INPUT NAMA
            // ====================================

        } elseif ($session && $session['step'] === 'reg_name') {
            if (
                str_starts_with($input, '/') ||
                mb_strlen($input, 'UTF-8') < 3 ||
                mb_strlen($input, 'UTF-8') > 120 ||
                !preg_match('/^[\p{L}\p{M} .\'-]+$/u', $input)
            ) {
                sendMessage(
                    $chatId,
                    'Nama tidak valid. Masukkan nama lengkap 3–120 karakter.',
                    cancelKeyboard()
                );
            } else {
                $keyboard = agencyKeyboard($pdo);

                if (count($keyboard) <= 1) {
                    sendMessage(
                        $chatId,
                        'Belum ada Agency aktif. Hubungi admin.',
                        cancelKeyboard()
                    );
                } else {
                    saveSession(
                        $pdo,
                        $userId,
                        'reg_agency',
                        ['name' => $input]
                    );

                    sendMessage(
                        $chatId,
                        "Pertanyaan 2 dari 3\n\n"
                            . "Anda tergabung dalam Agency mana?",
                        $keyboard
                    );
                }
            }

            // ====================================
            // INPUT NOMOR TELEPON
            // ====================================

        } elseif ($session && $session['step'] === 'reg_phone') {
            $contact = $message['contact'] ?? null;

            if (
                !is_array($contact) ||
                (int)($contact['user_id'] ?? 0) !== $userId
            ) {
                sendContactPrompt($chatId);
            } else {
                $phone = trim(
                    (string)($contact['phone_number'] ?? '')
                );

                if (!preg_match('/^\+?[0-9]{8,16}$/', $phone)) {
                    sendMessage(
                        $chatId,
                        'Nomor telepon tidak valid.',
                        cancelKeyboard()
                    );
                } else {
                    $data = $session['data'];
                    $data['phone'] = $phone;

                    saveSession(
                        $pdo,
                        $userId,
                        'reg_confirm',
                        $data
                    );

                    removeReplyKeyboard(
                        $chatId,
                        '✅ Nomor telepon berhasil diterima.'
                    );

                    sendMessage(
                        $chatId,
                        "📝 KONFIRMASI PENDAFTARAN\n\n"
                            . "Nama: {$data['name']}\n"
                            . "Agency: {$data['agency_name']}\n"
                            . "Telepon: {$data['phone']}\n\n"
                            . "Kode SF akan dibuat otomatis.\n"
                            . "Apakah data sudah benar?",
                        [
                            [
                                [
                                    'text' => '✅ Kirim Pendaftaran',
                                    'callback_data' => 'reg:confirm'
                                ]
                            ],
                            ...cancelKeyboard()
                        ]
                    );
                }
            }
        } elseif ($session) {
            sendMessage(
                $chatId,
                'Silakan gunakan tombol yang tersedia atau pilih Batalkan.',
                cancelKeyboard()
            );
        } else {
            sendMessage(
                $chatId,
                'Perintah tidak dikenal. Ketik /start.'
            );
        }

        // ========================================
        // CALLBACK TOMBOL TELEGRAM
        // ========================================

    } elseif (isset($update['callback_query'])) {
        $callback = $update['callback_query'];

        $cbId = (string)($callback['id'] ?? '');
        $userId = (int)($callback['from']['id'] ?? 0);
        $chat = $callback['message']['chat'] ?? [];
        $chatId = (int)($chat['id'] ?? 0);

        if (
            $cbId === '' ||
            $userId <= 0 ||
            $chatId !== $userId ||
            ($chat['type'] ?? '') !== 'private'
        ) {
            exit('OK');
        }

        $action = (string)($callback['data'] ?? '');
        $session = getSession($pdo, $userId);
        $sf = registeredSf($pdo, $userId);

        // ====================================
        // TOMBOL BATALKAN
        // ====================================

        if ($action === 'flow:cancel' && $session) {
            clearSession($pdo, $userId);

            answerCallback($cbId, 'Proses dibatalkan.');

            removeReplyKeyboard(
                $chatId,
                "❌ Proses dibatalkan.\n"
                    . "Tidak ada data sementara yang disimpan."
            );
        } elseif (!$session) {
            answerCallback($cbId, 'Proses sudah tidak aktif.');

            // ====================================
            // KONFIRMASI HADIR
            // ====================================

        } elseif (
            $action === 'att:present' &&
            $session['step'] === 'att_present' &&
            $sf &&
            $sf['registration_status'] === 'approved' &&
            (int)$sf['active'] === 1
        ) {
            saveSession($pdo, $userId, 'att_start');
            answerCallback($cbId, 'Isi rencana kerja.');
            sendMessage($chatId, "1/3 Pukul berapa Anda RENCANA mulai berjualan hari ini?\nContoh: 08:00 (WITA)", cancelKeyboard());

            // ====================================
            // PILIH WILAYAH
            // ====================================

        } elseif (
            preg_match('/^att:area:(\d+)$/', $action, $m) &&
            $session['step'] === 'att_area' &&
            $sf &&
            $sf['registration_status'] === 'approved' &&
            (int)$sf['active'] === 1
        ) {
            $stmt = $pdo->prepare("
                SELECT id, area_name
                FROM work_areas
                WHERE id = ? AND active = 1
                LIMIT 1
            ");

            $stmt->execute([(int)$m[1]]);
            $area = $stmt->fetch();

            if (!$area) {
                answerCallback($cbId, 'Wilayah tidak tersedia.');
            } else {
                $data = $session['data'];
                $data['area_id'] = (int)$area['id'];
                $data['area_name'] = $area['area_name'];
                saveSession($pdo, $userId, 'att_confirm', $data);
                answerCallback($cbId, 'Periksa rencana aktivitas Anda.');
                sendMessage(
                    $chatId,
                    "📋 KONFIRMASI RENCANA JUALAN\n\n" .
                        "Nama: {$sf['full_name']}\n" .
                        "Mulai rencana: {$data['planned_start_time']} WITA\n" .
                        "Pulang rencana: {$data['planned_end_time']} WITA\n" .
                        "Rencana wilayah: {$data['area_name']}\n\n" .
                        "Tekan Simpan untuk mencatat absensi dan rencana hari ini.",
                    [
                        [['text' => '✅ Simpan Absensi', 'callback_data' => 'att:confirm']],
                        ...cancelKeyboard()
                    ]
                );
            }
        } elseif (
            $action === 'att:confirm' &&
            $session['step'] === 'att_confirm' &&
            $sf && $sf['registration_status'] === 'approved' && (int)$sf['active'] === 1
        ) {
            $data = $session['data'];
            $start = $data['planned_start_time'] ?? null;
            $end = $data['planned_end_time'] ?? null;
            $areaId = (int)($data['area_id'] ?? 0);

            if (
                !is_string($start) || normalizeSfTime($start) === null ||
                !is_string($end) || normalizeSfTime($end) === null ||
                $end <= $start || $areaId <= 0
            ) {
                clearSession($pdo, $userId);
                answerCallback($cbId, 'Data absensi tidak lengkap.');
                sendMessage($chatId, 'Data tidak valid. Silakan mulai ulang /absen.');
            } else {
                $stmt = $pdo->prepare('SELECT id, area_name FROM work_areas WHERE id = ? AND active = 1 LIMIT 1');
                $stmt->execute([$areaId]);
                $area = $stmt->fetch();

                if (!$area) {
                    answerCallback($cbId, 'Wilayah tidak aktif.');
                    sendMessage($chatId, 'Wilayah sudah tidak aktif. Gunakan /batal lalu ulangi /absen.', cancelKeyboard());
                } else {
                    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Makassar'));
                    // Unique (sf_id, attendance_date) harus ada untuk mencegah duplikasi.
                    $stmt = $pdo->prepare("INSERT IGNORE INTO sf_attendance
                        (sf_id, area_id, attendance_date, status, checkin_at,
                         source, telegram_update_id, planned_start_time, planned_end_time)
                        VALUES (?, ?, ?, 'hadir', ?, 'telegram', ?, ?, ?)");
                    $stmt->execute([
                        $sf['id'],
                        $areaId,
                        $now->format('Y-m-d'),
                        $now->format('Y-m-d H:i:s'),
                        $update['update_id'] ?? null,
                        $start,
                        $end
                    ]);
                    $inserted = $stmt->rowCount() === 1;
                    clearSession($pdo, $userId);
                    answerCallback($cbId, $inserted ? 'Absensi berhasil.' : 'Sudah absen.');
                    sendMessage($chatId, $inserted
                        ? "✅ ABSENSI TERSIMPAN\nNama: {$sf['full_name']}\nMulai rencana: {$start} WITA\nPulang rencana: {$end} WITA\nLokasi rencana: {$area['area_name']}\n\nSaat selesai bekerja, ketik /pulang untuk mencatat jam pulang AKTUAL."
                        : 'Absensi hari ini sudah tercatat. Gunakan /status.');
                }
            }

            // ====================================
            // PILIH AGENCY
            // ====================================

        } elseif (
            preg_match('/^reg:agency:(\d+)$/', $action, $m) &&
            $session['step'] === 'reg_agency' &&
            !$sf
        ) {
            $stmt = $pdo->prepare("
                SELECT id, agency_name
                FROM agencies
                WHERE id = ? AND active = 1
                LIMIT 1
            ");

            $stmt->execute([(int)$m[1]]);
            $agency = $stmt->fetch();

            if (!$agency) {
                answerCallback($cbId, 'Agency tidak aktif.');
            } else {
                $data = $session['data'];
                $data['agency_id'] = (int)$agency['id'];
                $data['agency_name'] = $agency['agency_name'];

                saveSession(
                    $pdo,
                    $userId,
                    'reg_phone',
                    $data
                );

                answerCallback($cbId, 'Agency dipilih.');

                sendContactPrompt($chatId);
            }

            // ====================================
            // KONFIRMASI PENDAFTARAN
            // ====================================

        } elseif (
            $action === 'reg:confirm' &&
            $session['step'] === 'reg_confirm' &&
            !$sf
        ) {
            $data = $session['data'];

            $pdo->beginTransaction();

            try {
                // Gunakan sequence agar kode tidak bentrok
                $pdo->exec(
                    'INSERT INTO sf_code_sequence () VALUES ()'
                );

                $seq = (int)$pdo->lastInsertId();

                $sfCode = 'SF' . str_pad(
                    (string)$seq,
                    3,
                    '0',
                    STR_PAD_LEFT
                );

                $stmt = $pdo->prepare("
                    INSERT INTO sales_force
                    (
                        sf_code, full_name, telegram_user_id,
                        active, agency_id, phone_number,
                        registration_status
                    )
                    VALUES (?, ?, ?, 0, ?, ?, 'pending')
                ");

                $stmt->execute([
                    $sfCode,
                    $data['name'],
                    $userId,
                    $data['agency_id'],
                    $data['phone']
                ]);

                clearSession($pdo, $userId);
                $pdo->commit();

                answerCallback($cbId, 'Pendaftaran diterima.');

                sendMessage(
                    $chatId,
                    "✅ REGISTRASI BERHASIL DIKIRIM\n\n"
                        . "Nama: {$data['name']}\n"
                        . "Kode SF: {$sfCode}\n"
                        . "Agency: {$data['agency_name']}\n"
                        . "Status: Menunggu Verifikasi Admin\n\n"
                        . "Anda dapat mengecek status melalui /status."
                );

                // Notifikasi kepada admin
                $adminIds = array_filter(
                    array_map(
                        'trim',
                        explode(',', getenv('ADMIN_TELEGRAM_IDS') ?: '')
                    )
                );

                foreach ($adminIds as $adminId) {
                    if (ctype_digit($adminId)) {
                        try {
                            sendMessage(
                                (int)$adminId,
                                "📝 REGISTRASI SF BARU\n\n"
                                    . "Kode: {$sfCode}\n"
                                    . "Nama: {$data['name']}\n"
                                    . "Agency: {$data['agency_name']}\n\n"
                                    . "/approve {$sfCode}\n"
                                    . "/reject {$sfCode}"
                            );
                        } catch (Throwable $ex) {
                            error_log('Gagal mengirim notifikasi admin.');
                        }
                    }
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $ex;
            }
        } else {
            answerCallback(
                $cbId,
                'Tombol tidak sesuai tahap atau sudah kedaluwarsa.'
            );

            sendMessage(
                $chatId,
                'Silakan lanjutkan proses aktif atau ketik /batal.',
                cancelKeyboard()
            );
        }
    }

    http_response_code(200);
    echo 'OK';
} catch (Throwable $e) {
    error_log('SF bot webhook error: ' . $e->getMessage());

    http_response_code(500);
    echo 'Internal Server Error';
}
