# Prediksi SF 

PHP 8.1+ + MySQL 8 / MariaDB. Tanpa framework; perlu ekstensi `pdo_mysql` dan `curl`. Timezone absensi: Asia/Makassar (WITA). **Satu check-in per SF per hari.**

## 1. Database

Import `sql/schema.sql` melalui phpMyAdmin atau:

```bash
mysql -u root -p < sql/schema.sql
```

Tambahkan kode SF **hanya melalui admin** (jangan izinkan user membuat kode SF sendiri):

```sql
INSERT INTO sales_force (sf_code, full_name) VALUES ('SF001', 'SF Contoh');
```

**Keamanan:** Pengaitan akun dengan `/daftar SF001` menggunakan kode SF sebagai bukti kepemilikan. Bila kode SF mudah ditebak atau tersebar, ini **tidak cukup aman untuk produksi**. Untuk deployment nyata, gunakan OTP sekali pakai yang diberikan admin setelah verifikasi identitas SF, atau pengaitan Telegram user ID langsung oleh admin. Kode SF bukan kredensial kuat.

## 2. Konfigurasi lingkungan

Set environment variables dari `.env.example` melalui konfigurasi hosting/web server **dan** shell untuk script CLI. File `.env.example` hanya contoh, **tidak dibaca otomatis** oleh aplikasi. Misalnya (Linux shell):

```bash
export DB_HOST='127.0.0.1'
export DB_PORT='3306'
export DB_NAME='sf_attendance'
export DB_USER='sf_bot_user'
export DB_PASS='PASSWORD_DB'
export TELEGRAM_BOT_TOKEN='TOKEN_DARI_BOTFATHER'
export TELEGRAM_WEBHOOK_SECRET='RANDOM_ALPHANUMERIC_32_CHARS'
export TELEGRAM_WEBHOOK_URL='https://domain-anda.id/sf-bot/webhook.php'
```

Saat menggunakan Apache, Anda dapat menggunakan `SetEnv` di **konfigurasi VirtualHost** (bukan file yang bisa diunduh publik), atau PHP-FPM `env[]` dan `clear_env` yang sesuai. Pada cPanel gunakan pengaturan environment hosting yang tersedia. Pastikan environment tersedia pada proses PHP yang menangani webhook.

Untuk MySQL produksi gunakan user database khusus dengan hak akses minimum (`SELECT`, `INSERT`, `UPDATE` pada tabel yang diperlukan); jangan pakai root.

## 3. Buat bot dan set webhook

1. Buka `@BotFather` di Telegram dan buat bot (`/newbot`).
2. Simpan token sebagai `TELEGRAM_BOT_TOKEN`.
3. Hosting file `public/webhook.php` pada **HTTPS publik**. Sebaiknya document root mengarah ke folder `public/` agar folder `src`, `sql`, `scripts`, dan file konfigurasi tidak terbuka dari web.
4. Buat secret acak alfanumerik 32+ karakter (misalnya `openssl rand -hex 24`). Pasang sebagai `TELEGRAM_WEBHOOK_SECRET`.
5. Jalankan dari server dengan environment sudah diset:

```bash
php scripts/setup_webhook.php
```

Jika menggunakan XAMPP localhost, Telegram **tidak bisa mengakses `http://localhost`**. Gunakan domain HTTPS publik atau tunnel HTTPS development seperti ngrok, dan set URL webhook sesuai alamat tersebut. `scripts/setup_webhook.php` hanya boleh dijalankan lewat CLI.

## 4. Alur bot

1. Admin memasukkan SF: `SF001`.
2. SF chat bot secara private: `/start`.
3. SF kirim `/daftar SF001` (hanya contoh demonstrasi; lihat peringatan keamanan di atas).
4. SF kirim `/absen`.
5. SF pilih **✅ Hadir**.
6. SF pilih **📍 Biringkanaya**.
7. Bot mencatat check-in di `sf_attendance`.
8. SF kirim `/status` untuk melihat status hari ini.

Jika tombol lama diklik ulang, unique key `(sf_id, attendance_date)` mencegah duplikasi. Tanggal dan waktu dihitung server Asia/Makassar, bukan waktu dari pesan Telegram.

## 5. Query laporan harian

```sql
SELECT a.attendance_date, s.sf_code, s.full_name,
       w.area_name, a.checkin_at, a.status
FROM sf_attendance a
JOIN sales_force s ON s.id = a.sf_id
JOIN work_areas w ON w.id = a.area_id
WHERE a.attendance_date = CURRENT_DATE()
ORDER BY a.checkin_at DESC;
```

**Catatan:** `CURRENT_DATE()` memakai timezone sesi MySQL, yang mungkin tidak sama dengan WITA. Untuk laporan akurat tanpa konfigurasi timezone MySQL, kirim tanggal WITA dari PHP sebagai parameter (`WHERE attendance_date = ?`).

## 6. Batasan versi awal

- Belum tersedia fitur Tidak Hadir, izin, dan koreksi absensi oleh admin.
- Lokasi adalah pilihan wilayah, **bukan verifikasi GPS**. Bot tidak membuktikan SF secara fisik berada di Biringkanaya.
- Tidak ada dashboard admin dan integrasi historical sales / prediksi AI (akan menjadi modul lanjutan).
- Konfigurasi demo `/daftar KODE` harus diperkuat sebelum produksi (OTP/admin bind).
- Callback lama untuk pilihan area tetap dapat digunakan tetapi penyimpanan selalu menggunakan **tanggal server saat tombol ditekan**.
- Jika volume sangat tinggi, tambahkan queue dan tabel pemrosesan `update_id` untuk pengiriman Telegram dengan jaminan retry yang lebih terkontrol. Uniqueness attendance sudah mencegah check-in ganda.

## 7. Struktur

```
sf-telegram-attendance/
├── public/webhook.php
├── src/bootstrap.php
├── scripts/setup_webhook.php
├── sql/schema.sql
├── .env.example
└── README.md
```
