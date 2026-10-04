-- Tahap 4: promo & flyer terhubung ke produk; icon kategori.
-- Aman untuk data existing (kolom NULL-able, tidak ada data yang dihapus). Jalankan SEKALI:
--   mysql restaurant_ordering < database/migrations/004_product_links_category_icons.sql
--
-- Promo/flyer lama mendapat product_id NULL: tetap tampil di customer (tidak bisa diklik)
-- sampai admin membuka Edit dan memilih produk (wajib saat simpan).
-- FK ON DELETE RESTRICT: produk yang dipakai promo/flyer tidak bisa dihapus (admin menandainya Habis),
-- sehingga relasi tidak pernah rusak (setara soft delete tanpa kolom tambahan).

ALTER TABLE promos
  ADD COLUMN product_id INT UNSIGNED NULL AFTER title,
  ADD KEY idx_promos_product (product_id),
  ADD CONSTRAINT fk_promos_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT;

ALTER TABLE flyers
  ADD COLUMN product_id INT UNSIGNED NULL AFTER title,
  ADD KEY idx_flyers_product (product_id),
  ADD CONSTRAINT fk_flyers_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT;

-- Icon kategori = nama class Bootstrap Icons (divalidasi whitelist di aplikasi). NULL -> bi-grid.
ALTER TABLE categories ADD COLUMN icon VARCHAR(40) NULL;
UPDATE categories SET icon = 'bi-egg-fried' WHERE icon IS NULL AND name = 'Makanan';
UPDATE categories SET icon = 'bi-cup-straw'  WHERE icon IS NULL AND name = 'Minuman';
UPDATE categories SET icon = 'bi-cake2'      WHERE icon IS NULL AND name = 'Dessert';
