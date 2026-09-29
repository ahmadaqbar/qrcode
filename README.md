# Restaurant QR Ordering (PHP 7.4 + MySQL)

Customer scan QR meja → pilih menu → keranjang → catatan → konfirmasi → pesanan berhasil.
Staff login → dashboard (polling 4 detik, popup + notifikasi browser + suara) → terima → selesai.

## Setup
1. `mysql -u root -p < database/schema.sql` (membuat DB `restaurant_ordering` + data contoh).
   Upgrade dari versi lama: `mysql restaurant_ordering < database/migrations/002_security_and_admin.sql`
2. Kredensial DB via env (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`) atau buat `config/local.php`:
   ```php
   <?php return ['user' => 'app', 'pass' => 'rahasia'];
   ```
3. Jalankan di Apache/Nginx+PHP-FPM, atau `php -S 0.0.0.0:8080` untuk development.
4. Staff: `/admin/login.php` — user `staff` / `staff123` (**ganti di produksi**:
   `UPDATE users SET password = '<hasil password_hash()>' WHERE username='staff'`).
5. QR meja: `/admin/qr.php` (pastikan alamat server bisa diakses dari HP customer).

Nama restoran: env `APP_NAME`. Foto menu: taruh di `assets/images/` lalu isi kolom `products.image`.
Notifikasi suara: klik **AKTIFKAN** sekali saat membuka dashboard (aturan autoplay Chrome).

## Fitur admin (tahap 2)
- `/admin/products.php` kelola menu + upload foto (JPG/PNG/WebP, maks 2MB); menu yang pernah dipesan tidak dihapus, hanya ditandai Habis
- `/admin/categories.php`, `/admin/tables.php` kelola kategori & meja; `/admin/account.php` ganti password
- Keamanan: login dikunci 10 menit setelah 5 gagal (per IP), maks 5 order/menit/IP, maks 15 order NEW menumpuk per meja
- Customer melihat status pesanan berubah live di halaman sukses; ringkasan hari ini di `/admin/orders.php`
- Upload memakai `assets/images/menu/.htaccess` (Apache). Di Nginx, blokir eksekusi PHP di folder itu.
