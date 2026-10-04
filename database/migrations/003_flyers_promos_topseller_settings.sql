-- Tahap 3: flyer popup, promo banner, top seller, settings (Base URL QR).
-- Aman untuk data existing: hanya menambah kolom/tabel. Jalankan SEKALI:
--   mysql restaurant_ordering < database/migrations/003_flyers_promos_topseller_settings.sql

-- Top Seller (Option A: kolom pada products, relasi 1:1 sehingga paling sederhana)
ALTER TABLE products
  ADD COLUMN is_top_seller TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN top_seller_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD KEY idx_products_top (is_top_seller, top_seller_order);

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
  image VARCHAR(255) NOT NULL,               -- relatif terhadap assets/, mis. uploads/flyers/xxx.jpg
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  start_date DATE NULL,
  end_date DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_flyers_active (is_active, start_date, end_date)
) ENGINE=InnoDB;

-- Banner promo (slideshow)
CREATE TABLE IF NOT EXISTS promos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(100) NOT NULL,
  image VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  start_date DATE NULL,
  end_date DATE NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_promos_active (is_active, start_date, end_date, sort_order)
) ENGINE=InnoDB;
