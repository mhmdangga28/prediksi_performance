CREATE DATABASE IF NOT EXISTS sf_attendance CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sf_attendance;

CREATE TABLE IF NOT EXISTS sales_force (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sf_code VARCHAR(40) NOT NULL,
  full_name VARCHAR(120) NOT NULL,
  telegram_user_id BIGINT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_sf_code (sf_code),
  UNIQUE KEY uk_telegram_user_id (telegram_user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS work_areas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  area_name VARCHAR(100) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uk_area_name (area_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sf_attendance (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sf_id BIGINT UNSIGNED NOT NULL,
  area_id INT UNSIGNED NOT NULL,
  attendance_date DATE NOT NULL,
  status ENUM('hadir') NOT NULL DEFAULT 'hadir',
  checkin_at DATETIME NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'telegram',
  telegram_update_id BIGINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_sf_attendance_day (sf_id, attendance_date),
  KEY idx_attendance_date_area (attendance_date, area_id),
  CONSTRAINT fk_attendance_sf FOREIGN KEY (sf_id) REFERENCES sales_force(id),
  CONSTRAINT fk_attendance_area FOREIGN KEY (area_id) REFERENCES work_areas(id)
) ENGINE=InnoDB;

INSERT INTO work_areas (area_name, active)
VALUES ('Biringkanaya', 1)
ON DUPLICATE KEY UPDATE active = VALUES(active);

-- Tambahkan SF yang sah sebelum proses /daftar.
-- Contoh: INSERT INTO sales_force (sf_code, full_name) VALUES ('SF001', 'Nama SF');
