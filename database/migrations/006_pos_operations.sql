-- POS Phase 2 — Payment & Shift.
-- BACKUP DULU:  mysqldump --single-transaction restaurant_ordering > backup_sebelum_006.sql
-- Jalankan SEKALI: mysql restaurant_ordering < database/migrations/006_pos_operations.sql

-- Metode pembayaran (dapat dikonfigurasi di Settings). TIDAK terhubung payment gateway:
-- QRIS / kartu / transfer / e-wallet dicatat manual oleh kasir.
CREATE TABLE IF NOT EXISTS payment_methods (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(50) NOT NULL,
  type ENUM('CASH','NONCASH') NOT NULL,
  requires_reference TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT IGNORE INTO payment_methods (code, name, type, requires_reference, sort_order) VALUES
  ('CASH', 'Tunai', 'CASH', 0, 1),
  ('CARD', 'Debit / Kartu Kredit', 'NONCASH', 0, 2),
  ('QRIS', 'QRIS', 'NONCASH', 0, 3),
  ('TRANSFER', 'Transfer Bank', 'NONCASH', 1, 4),
  ('EWALLET', 'E-Wallet', 'NONCASH', 0, 5),
  ('OTHER', 'Lainnya', 'NONCASH', 1, 6);

-- Shift kasir. Kolom ringkasan diisi saat tutup shift (angka final, tidak berubah walau data lain diubah).
CREATE TABLE IF NOT EXISTS cash_shifts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  status ENUM('OPEN','CLOSED') NOT NULL DEFAULT 'OPEN',
  opened_at DATETIME NOT NULL,
  opening_cash INT UNSIGNED NOT NULL DEFAULT 0,
  closed_at DATETIME NULL,
  closed_by INT UNSIGNED NULL,
  cash_sales INT NULL,
  noncash_sales INT NULL,
  refunds_cash INT NULL,
  refunds_noncash INT NULL,
  cash_in INT NULL,
  cash_out INT NULL,
  expected_cash INT NULL,
  counted_cash INT NULL,
  difference INT NULL,                       -- counted - expected (negatif = kurang)
  note VARCHAR(500) NULL,
  override_by INT UNSIGNED NULL,             -- manager yang mengotorisasi tutup shift dengan kondisi belum beres
  override_reason VARCHAR(255) NULL,
  open_marker TINYINT AS (IF(status = 'OPEN', 1, NULL)) STORED,
  UNIQUE KEY uq_one_open_shift (user_id, open_marker),   -- satu shift terbuka per kasir (aman dari klik ganda)
  KEY idx_shift_opened (opened_at),
  CONSTRAINT fk_shift_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cash_movements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  shift_id INT UNSIGNED NOT NULL,
  type ENUM('IN','OUT') NOT NULL,
  amount INT UNSIGNED NOT NULL,
  note VARCHAR(255) NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cm_shift (shift_id),
  CONSTRAINT fk_cm_shift FOREIGN KEY (shift_id) REFERENCES cash_shifts (id)
) ENGINE=InnoDB;

-- Pembayaran. Refund = baris baru bernilai negatif (pembayaran asli tidak pernah diubah).
CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  shift_id INT UNSIGNED NULL,
  method_code VARCHAR(20) NOT NULL,
  kind ENUM('PAYMENT','REFUND') NOT NULL DEFAULT 'PAYMENT',
  amount INT NOT NULL,                       -- nominal yang dibebankan ke tagihan (refund: negatif)
  tendered INT NOT NULL DEFAULT 0,           -- uang diterima (tunai)
  change_amount INT NOT NULL DEFAULT 0,
  reference VARCHAR(100) NULL,               -- no. referensi non-tunai (BUKAN data kartu)
  idem_key CHAR(32) NOT NULL UNIQUE,         -- cegah pembayaran ganda (klik ganda / retry)
  refund_of INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pay_order (order_id),
  KEY idx_pay_shift (shift_id),
  KEY idx_pay_created (created_at),
  CONSTRAINT fk_pay_order FOREIGN KEY (order_id) REFERENCES orders (id),
  CONSTRAINT fk_pay_shift FOREIGN KEY (shift_id) REFERENCES cash_shifts (id),
  CONSTRAINT fk_pay_method FOREIGN KEY (method_code) REFERENCES payment_methods (code)
) ENGINE=InnoDB;
