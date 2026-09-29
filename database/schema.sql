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
  is_active TINYINT(1) NOT NULL DEFAULT 1
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

-- ===== Data contoh =====
-- Login staff: staff / staff123  (GANTI password ini di produksi!)
INSERT INTO users (username, password, name) VALUES
  ('staff', '$2y$12$kL8AwwX7hdwKAVzf9dlKJeWDpyOYmHANepngPH9v1fdw8Bx6O.EsG', 'Staff Restaurant');

INSERT INTO tables (table_number) VALUES ('01'),('02'),('03'),('04'),('05');

INSERT INTO categories (id, name) VALUES (1,'Makanan'),(2,'Minuman'),(3,'Dessert');

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
