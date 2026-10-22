-- Promo & flyer: tujuan klik bisa berupa PRODUK (product_id) ATAU LINK LANGSUNG (link_url).
-- Aman untuk data existing (kolom NULL-able). Jalankan SEKALI:
--   mysql restaurant_ordering < database/migrations/005_banner_direct_link.sql
-- Aturan di aplikasi: tepat satu tujuan per baris (produk wajib bila memilih "Produk"; URL http/https wajib bila memilih "Link langsung").

ALTER TABLE promos  ADD COLUMN link_url VARCHAR(500) NULL AFTER product_id;
ALTER TABLE flyers  ADD COLUMN link_url VARCHAR(500) NULL AFTER product_id;
