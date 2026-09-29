-- Tahap 2: batas percobaan login & pembatasan order.
-- Jalankan pada DB yang sudah ada: mysql restaurant_ordering < database/migrations/002_security_and_admin.sql
ALTER TABLE orders ADD COLUMN client_ip VARCHAR(45) NULL AFTER order_token, ADD KEY idx_orders_ip_created (client_ip, created_at);

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_ip_created (ip, created_at)
) ENGINE=InnoDB;
