-- POS Phase 4 — Antrean integrasi Accurate.
-- BACKUP DULU:  mysqldump --single-transaction restaurant_ordering > backup_sebelum_008.sql
-- Jalankan SEKALI: mysql restaurant_ordering < database/migrations/008_accurate_integration.sql
--
-- Transaksi POS TIDAK pernah diubah oleh modul ini. Kegagalan integrasi hanya mengubah baris antrean.

CREATE TABLE IF NOT EXISTS integration_queue (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  doc_type ENUM('SALE','REFUND') NOT NULL,                 -- jenis dokumen tujuan (dipetakan oleh bridge)
  idempotency_key VARCHAR(64) NOT NULL UNIQUE,             -- referensi unik & konsisten, mis. POS-ORD-000123-SALE
  status ENUM('PENDING','PROCESSING','SUCCESS','FAILED','RETRY_SCHEDULED') NOT NULL DEFAULT 'PENDING',
  mode VARCHAR(20) NULL,                                    -- simulation / bridge (mode saat diproses)
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  needs_verification TINYINT(1) NOT NULL DEFAULT 0,         -- hasil percobaan sebelumnya tidak pasti (timeout) -> cek dulu sebelum kirim
  queued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_attempt_at DATETIME NULL,
  next_attempt_at DATETIME NULL,
  locked_until DATETIME NULL,
  external_ref VARCHAR(100) NULL,                           -- nomor/ID dokumen di Accurate (bila tersedia)
  response_message VARCHAR(1000) NULL,
  succeeded_at DATETIME NULL,
  payload_hash CHAR(64) NULL,
  KEY idx_iq_status (status, next_attempt_at),
  KEY idx_iq_order (order_id),
  KEY idx_iq_queued (queued_at),
  CONSTRAINT fk_iq_order FOREIGN KEY (order_id) REFERENCES orders (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS integration_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  queue_id INT UNSIGNED NOT NULL,
  attempt SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  action VARCHAR(20) NOT NULL,                              -- VALIDATE / VERIFY / SEND / RETRY / REQUEUE
  result VARCHAR(20) NOT NULL,
  mode VARCHAR(20) NULL,
  request_summary TEXT NULL,                                -- ringkasan payload, TANPA kredensial
  response TEXT NULL,
  duration_ms INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_il_queue (queue_id),
  CONSTRAINT fk_il_queue FOREIGN KEY (queue_id) REFERENCES integration_queue (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Pemetaan kode Accurate (akun kas/bank, pelanggan, gudang, cabang, barang jasa).
-- Nilai SENGAJA KOSONG: wajib diisi dari data yang disetujui pengelola Accurate. Transaksi tidak dikirim bila mapping belum lengkap.
CREATE TABLE IF NOT EXISTS accurate_account_mapping (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  map_key VARCHAR(60) NOT NULL UNIQUE,
  map_value VARCHAR(100) NOT NULL DEFAULT '',
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Dokumen tiruan untuk MODE SIMULASI saja (bukan Accurate). Dipakai menguji idempotensi & retry.
CREATE TABLE IF NOT EXISTS integration_sim_documents (
  idempotency_key VARCHAR(64) NOT NULL PRIMARY KEY,
  reference VARCHAR(40) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('accurate_mode', 'simulation'),          -- off | simulation | bridge
  ('accurate_send_on', 'paid'),             -- paid (masuk antrean saat lunas) | manual
  ('accurate_sim_behavior', 'ok');          -- ok | fail | reject | timeout_after_commit (khusus uji simulasi)
