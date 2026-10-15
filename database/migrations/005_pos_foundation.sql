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
