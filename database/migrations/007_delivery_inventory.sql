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
