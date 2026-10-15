-- Restaurant QR Ordering - schema + data contoh
-- Import: mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS restaurant_ordering
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE restaurant_ordering;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,            -- hash password_hash()
  name VARCHAR(100) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tables (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  table_number VARCHAR(10) NOT NULL UNIQUE,  -- contoh: 01, 02
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  icon VARCHAR(40) NULL                      -- class Bootstrap Icons, mis. bi-egg-fried
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  price INT UNSIGNED NOT NULL,               -- Rupiah, tanpa desimal
  image VARCHAR(255) NULL,                   -- nama file di assets/images/
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_top_seller TINYINT(1) NOT NULL DEFAULT 0,
  top_seller_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_products_top (is_top_seller, top_seller_order),
  KEY idx_products_category (category_id),
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_number VARCHAR(20) NOT NULL UNIQUE,  -- ORD-000001
  order_token CHAR(32) NOT NULL UNIQUE,      -- kunci idempotensi + akses halaman sukses
  client_ip VARCHAR(45) NULL,                -- untuk pembatasan jumlah order
  table_id INT UNSIGNED NOT NULL,
  customer_name VARCHAR(100) NULL,
  note VARCHAR(500) NULL,
  total INT UNSIGNED NOT NULL,
  status ENUM('NEW','PROCESSING','COMPLETED','CANCELLED') NOT NULL DEFAULT 'NEW',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_orders_status_created (status, created_at),
  KEY idx_orders_table (table_id),
  KEY idx_orders_ip_created (client_ip, created_at),
  CONSTRAINT fk_orders_table FOREIGN KEY (table_id) REFERENCES tables (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  product_name VARCHAR(100) NOT NULL,        -- snapshot nama saat order
  price INT UNSIGNED NOT NULL,               -- snapshot harga saat order
  quantity SMALLINT UNSIGNED NOT NULL,
  subtotal INT UNSIGNED NOT NULL,
  note VARCHAR(255) NULL,
  KEY idx_items_order (order_id),
  KEY idx_items_product (product_id),
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_ip_created (ip, created_at)
) ENGINE=InnoDB;

-- Konfigurasi key/value (mis. site_url untuk QR). Bila site_url belum ada, aplikasi
-- memakai alamat yang terdeteksi otomatis dari request.
CREATE TABLE IF NOT EXISTS settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(50) NOT NULL UNIQUE,
  setting_value VARCHAR(500) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

-- Flyer popup "Menu Baru". start_date/end_date NULL = tanpa batas.
CREATE TABLE IF NOT EXISTS flyers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(100) NOT NULL,
  product_id INT UNSIGNED NULL,              -- produk tujuan saat gambar diklik
  image VARCHAR(255) NOT NULL,               -- relatif terhadap assets/, mis. uploads/flyers/xxx.jpg
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  start_date DATE NULL,
  end_date DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_flyers_active (is_active, start_date, end_date),
  KEY idx_flyers_product (product_id),
  CONSTRAINT fk_flyers_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Banner promo (slideshow)
CREATE TABLE IF NOT EXISTS promos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(100) NOT NULL,
  product_id INT UNSIGNED NULL,              -- produk tujuan saat gambar diklik
  image VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  start_date DATE NULL,
  end_date DATE NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_promos_active (is_active, start_date, end_date, sort_order),
  KEY idx_promos_product (product_id),
  CONSTRAINT fk_promos_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ===== Data contoh =====
-- Login staff: staff / staff123  (GANTI password ini di produksi!)
INSERT INTO users (username, password, name) VALUES
  ('staff', '$2y$12$kL8AwwX7hdwKAVzf9dlKJeWDpyOYmHANepngPH9v1fdw8Bx6O.EsG', 'Staff Restaurant');

INSERT INTO tables (table_number) VALUES ('01'),('02'),('03'),('04'),('05');

INSERT INTO categories (id, name, icon) VALUES (1,'Makanan','bi-egg-fried'),(2,'Minuman','bi-cup-straw'),(3,'Dessert','bi-cake2');

INSERT INTO products (category_id, name, description, price) VALUES
  (1,'Nasi Goreng','Nasi goreng spesial dengan telur dan ayam',25000),
  (1,'Mie Goreng','Mie goreng dengan sayuran dan telur',23000),
  (1,'Ayam Bakar','Ayam bakar bumbu kecap dengan lalapan',30000),
  (1,'Sate Ayam','10 tusuk sate ayam bumbu kacang',28000),
  (2,'Es Teh','Teh manis dingin',8000),
  (2,'Jus Jeruk','Jeruk peras segar',12000),
  (2,'Kopi Susu','Kopi susu gula aren',15000),
  (3,'Pisang Goreng','Pisang goreng crispy dengan topping cokelat',15000),
  (3,'Es Krim Vanilla','Dua scoop es krim vanilla',14000);

-- Contoh Top Seller (urutan tampil 1..3)
UPDATE products SET is_top_seller = 1, top_seller_order = 1 WHERE name = 'Nasi Goreng';
UPDATE products SET is_top_seller = 1, top_seller_order = 2 WHERE name = 'Ayam Bakar';
UPDATE products SET is_top_seller = 1, top_seller_order = 3 WHERE name = 'Kopi Susu';

-- ===================== POS (migrasi 005-008, digabung untuk instalasi baru) =====================

-- >>> 005_pos_foundation.sql
-- Tahap 5 / POS Phase 1 — Foundation
-- BACKUP DULU sebelum menjalankan (lihat docs/BACKUP.md):
--   mysqldump --single-transaction --routines restaurant_ordering > backup_sebelum_005.sql
-- Jalankan SEKALI:  mysql restaurant_ordering < database/migrations/005_pos_foundation.sql
-- Tidak ada data yang dihapus. Tabel orders/order_items DIPERLUAS (satu sumber data untuk QR & POS).

-- ---------- Role & permission ----------
CREATE TABLE IF NOT EXISTS roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(50) NOT NULL
) ENGINE=InnoDB;

-- Administrator selalu memiliki SEMUA izin (diatur di kode), tidak perlu baris di sini.
CREATE TABLE IF NOT EXISTS role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission VARCHAR(50) NOT NULL,
  PRIMARY KEY (role_id, permission),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO roles (code, name) VALUES
  ('ADMIN', 'Administrator'),  ('MANAGER', 'Manager'),  ('CASHIER', 'Kasir'),  ('WAITER', 'Waiter'),  ('KITCHEN', 'Kitchen'),  ('DELIVERY', 'Delivery Staff');

INSERT INTO role_permissions (role_id, permission) VALUES
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'dashboard.view'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'orders.view'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'orders.manage'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'pos.use'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'pos.discount'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'order.void'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'table.move'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'payment.take'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'payment.refund'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'shift.manage'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'shift.override'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'kitchen.view'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'kitchen.update'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'delivery.view'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'delivery.manage'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'reports.view'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'catalog.manage'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'inventory.manage'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'tables.manage'),
  ((SELECT id FROM roles WHERE code = 'MANAGER'), 'audit.view'),
  ((SELECT id FROM roles WHERE code = 'CASHIER'), 'dashboard.view'),
  ((SELECT id FROM roles WHERE code = 'CASHIER'), 'orders.view'),
  ((SELECT id FROM roles WHERE code = 'CASHIER'), 'orders.manage'),
  ((SELECT id FROM roles WHERE code = 'CASHIER'), 'pos.use'),
  ((SELECT id FROM roles WHERE code = 'CASHIER'), 'payment.take'),
  ((SELECT id FROM roles WHERE code = 'CASHIER'), 'shift.manage'),
  ((SELECT id FROM roles WHERE code = 'CASHIER'), 'kitchen.view'),
  ((SELECT id FROM roles WHERE code = 'CASHIER'), 'delivery.view'),
  ((SELECT id FROM roles WHERE code = 'CASHIER'), 'delivery.manage'),
  ((SELECT id FROM roles WHERE code = 'WAITER'), 'dashboard.view'),
  ((SELECT id FROM roles WHERE code = 'WAITER'), 'orders.view'),
  ((SELECT id FROM roles WHERE code = 'WAITER'), 'orders.manage'),
  ((SELECT id FROM roles WHERE code = 'WAITER'), 'pos.use'),
  ((SELECT id FROM roles WHERE code = 'KITCHEN'), 'kitchen.view'),
  ((SELECT id FROM roles WHERE code = 'KITCHEN'), 'kitchen.update'),
  ((SELECT id FROM roles WHERE code = 'DELIVERY'), 'delivery.courier');

ALTER TABLE users
  ADD COLUMN role_id INT UNSIGNED NULL AFTER name,
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role_id;
-- User lama (staff) sebelumnya punya akses penuh -> Administrator, agar tidak kehilangan akses.
UPDATE users SET role_id = (SELECT id FROM roles WHERE code = 'ADMIN') WHERE role_id IS NULL;
ALTER TABLE users
  MODIFY role_id INT UNSIGNED NOT NULL,
  ADD CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id);

-- ---------- Katalog ----------
ALTER TABLE categories ADD COLUMN station ENUM('KITCHEN','BAR') NOT NULL DEFAULT 'KITCHEN';   -- area produksi
UPDATE categories SET station = 'BAR' WHERE name = 'Minuman';

ALTER TABLE products
  ADD COLUMN sku VARCHAR(30) NULL AFTER category_id,
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_available,     -- 0 = disembunyikan total
  ADD COLUMN accurate_item_code VARCHAR(50) NULL,                            -- kode barang di Accurate (diisi pengelola Accurate)
  ADD UNIQUE KEY uq_products_sku (sku);

-- ---------- Pesanan (satu sumber data QR + POS) ----------
ALTER TABLE orders
  MODIFY table_id INT UNSIGNED NULL,
  MODIFY status ENUM('DRAFT','NEW','PROCESSING','COMPLETED','CANCELLED') NOT NULL DEFAULT 'NEW',
  ADD COLUMN order_type ENUM('DINE_IN','TAKEAWAY','DELIVERY') NOT NULL DEFAULT 'DINE_IN' AFTER table_id,
  ADD COLUMN source ENUM('QR','POS') NOT NULL DEFAULT 'QR' AFTER order_type,
  ADD COLUMN subtotal INT UNSIGNED NOT NULL DEFAULT 0 AFTER note,
  ADD COLUMN discount_type ENUM('NONE','PERCENT','AMOUNT') NOT NULL DEFAULT 'NONE' AFTER subtotal,
  ADD COLUMN discount_value INT UNSIGNED NOT NULL DEFAULT 0 AFTER discount_type,
  ADD COLUMN discount_amount INT UNSIGNED NOT NULL DEFAULT 0 AFTER discount_value,
  ADD COLUMN service_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER discount_amount,     -- tarif yang BENAR-BENAR dipakai
  ADD COLUMN service_amount INT UNSIGNED NOT NULL DEFAULT 0 AFTER service_rate,
  ADD COLUMN tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER service_amount,
  ADD COLUMN tax_include_service TINYINT(1) NOT NULL DEFAULT 1 AFTER tax_rate,
  ADD COLUMN tax_amount INT UNSIGNED NOT NULL DEFAULT 0 AFTER tax_include_service,
  ADD COLUMN delivery_fee INT UNSIGNED NOT NULL DEFAULT 0 AFTER tax_amount,
  ADD COLUMN other_fee INT UNSIGNED NOT NULL DEFAULT 0 AFTER delivery_fee,
  ADD COLUMN other_fee_label VARCHAR(50) NULL AFTER other_fee,
  ADD COLUMN payment_status ENUM('UNPAID','PARTIAL','PAID','REFUNDED') NOT NULL DEFAULT 'UNPAID' AFTER status,
  ADD COLUMN paid_amount INT UNSIGNED NOT NULL DEFAULT 0 AFTER payment_status,
  ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 0,                 -- optimistic locking antar perangkat
  ADD COLUMN created_by INT UNSIGNED NULL,
  ADD COLUMN closed_by INT UNSIGNED NULL,
  ADD COLUMN closed_at DATETIME NULL,
  ADD COLUMN cancel_reason VARCHAR(255) NULL,
  ADD COLUMN cancelled_by INT UNSIGNED NULL,
  ADD COLUMN cancelled_at DATETIME NULL,
  ADD COLUMN merged_into_id INT UNSIGNED NULL,
  ADD COLUMN bill_printed_at DATETIME NULL,
  ADD COLUMN table_released_at DATETIME NULL,
  ADD KEY idx_orders_type_status (order_type, status),
  ADD KEY idx_orders_payment (payment_status),
  ADD KEY idx_orders_closed (closed_at);

-- Data lama: total = subtotal (belum ada pajak/service).
UPDATE orders SET subtotal = total;
-- Pesanan lama yang sudah COMPLETED dianggap lunas (dibayar di luar sistem sebelum ada modul pembayaran).
-- Tidak ada baris payments untuk data ini; laporan menampilkannya sebagai "data lama / tanpa metode".
UPDATE orders SET payment_status = 'PAID', paid_amount = total, closed_at = updated_at WHERE status = 'COMPLETED';
UPDATE orders SET table_released_at = updated_at WHERE status IN ('COMPLETED', 'CANCELLED');

ALTER TABLE order_items
  ADD COLUMN discount_type ENUM('NONE','PERCENT','AMOUNT') NOT NULL DEFAULT 'NONE' AFTER subtotal,
  ADD COLUMN discount_value INT UNSIGNED NOT NULL DEFAULT 0 AFTER discount_type,
  ADD COLUMN discount_amount INT UNSIGNED NOT NULL DEFAULT 0 AFTER discount_value,   -- subtotal = price*qty - discount_amount
  ADD COLUMN station ENUM('KITCHEN','BAR') NOT NULL DEFAULT 'KITCHEN',
  ADD COLUMN kitchen_status ENUM('PENDING','NEW','PREPARING','READY','SERVED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  ADD COLUMN kitchen_batch SMALLINT UNSIGNED NOT NULL DEFAULT 0,      -- tiket ke-n yang dikirim ke dapur
  ADD COLUMN sent_at DATETIME NULL,
  ADD COLUMN started_at DATETIME NULL,
  ADD COLUMN ready_at DATETIME NULL,
  ADD COLUMN served_at DATETIME NULL,
  ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD KEY idx_items_kitchen (kitchen_status, station);

UPDATE order_items oi JOIN orders o ON o.id = oi.order_id JOIN products p ON p.id = oi.product_id JOIN categories c ON c.id = p.category_id
SET oi.station = c.station,
    oi.kitchen_status = CASE o.status WHEN 'NEW' THEN 'PENDING' WHEN 'PROCESSING' THEN 'NEW' WHEN 'COMPLETED' THEN 'SERVED' ELSE 'CANCELLED' END,
    oi.kitchen_batch = IF(o.status IN ('PROCESSING', 'COMPLETED'), 1, 0),
    oi.sent_at = IF(o.status IN ('PROCESSING', 'COMPLETED'), o.created_at, NULL);

-- ---------- Delivery ----------
CREATE TABLE IF NOT EXISTS delivery_details (
  order_id INT UNSIGNED NOT NULL PRIMARY KEY,
  customer_name VARCHAR(100) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  address VARCHAR(255) NOT NULL,
  landmark VARCHAR(150) NULL,
  delivery_note VARCHAR(255) NULL,
  source VARCHAR(30) NOT NULL DEFAULT 'Telepon',
  courier_id INT UNSIGNED NULL,
  delivery_status ENUM('NEW','CONFIRMED','PREPARING','READY_FOR_PICKUP','OUT_FOR_DELIVERY','DELIVERED','CANCELLED') NOT NULL DEFAULT 'NEW',
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_delivery_status (delivery_status),
  KEY idx_delivery_courier (courier_id),
  CONSTRAINT fk_delivery_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE,
  CONSTRAINT fk_delivery_courier FOREIGN KEY (courier_id) REFERENCES users (id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS delivery_status_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  from_status VARCHAR(20) NULL,
  to_status VARCHAR(20) NOT NULL,
  user_id INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dsh_order (order_id),
  CONSTRAINT fk_dsh_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Audit log ----------
CREATE TABLE IF NOT EXISTS audit_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  action VARCHAR(50) NOT NULL,
  entity VARCHAR(30) NOT NULL,
  entity_id INT UNSIGNED NULL,
  detail TEXT NULL,                         -- JSON, tanpa password/secret
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_entity (entity, entity_id),
  KEY idx_audit_created (created_at),
  KEY idx_audit_action (action)
) ENGINE=InnoDB;

-- ---------- Pengaturan default (tarif TIDAK di-hardcode di kode; ubah di Settings) ----------
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('tax_rate', '0'), ('service_rate', '0'), ('tax_include_service', '1'), ('service_dine_in_only', '1'),
  ('delivery_fee_default', '0'), ('table_auto_release', '0'),
  ('outlet_name', ''), ('outlet_address', ''), ('outlet_phone', ''), ('receipt_footer', 'Terima kasih atas kunjungan Anda'),
  ('receipt_width', '80');

-- >>> 006_pos_operations.sql
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

-- >>> 007_delivery_inventory.sql
-- POS Phase 3 — Inventory sederhana (stok PRODUK MENU, bukan bahan baku).
-- BACKUP DULU:  mysqldump --single-transaction restaurant_ordering > backup_sebelum_007.sql
-- Jalankan SEKALI: mysql restaurant_ordering < database/migrations/007_delivery_inventory.sql
--
-- Catatan: ini BUKAN food-cost / resep (BOM). Bahan baku & resep perlu tabel terpisah
-- (mis. raw_materials, recipes) di tahap berikutnya; tabel ini hanya untuk produk jadi
-- (minuman kemasan, kue, dsb.) yang stoknya dihitung per unit jual.
ALTER TABLE products
  ADD COLUMN track_stock TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN stock_min INT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS inventory_movements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  type ENUM('OPENING','IN','OUT','ADJUSTMENT','SALE') NOT NULL,
  qty INT NOT NULL,                          -- bertanda: masuk +, keluar -
  note VARCHAR(255) NULL,
  order_id INT UNSIGNED NULL,                -- SALE: pesanan sumber
  user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_inv_product (product_id, created_at),
  KEY idx_inv_order (order_id),
  CONSTRAINT fk_inv_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB;

-- >>> 008_accurate_integration.sql
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
