# Restaurant QR Ordering (PHP 7.4 + MySQL)

Customer scan QR meja → pilih menu → keranjang → catatan → konfirmasi → pesanan berhasil.
Staff login → dashboard (polling 4 detik, popup + notifikasi browser + suara) → terima → selesai.

## Setup
1. `mysql -u root -p < database/schema.sql` (membuat DB `restaurant_ordering` + data contoh).
   Upgrade dari versi lama (berurutan, masing-masing sekali): `database/migrations/002_security_and_admin.sql`, lalu `003_flyers_promos_topseller_settings.sql`, lalu `004_product_links_category_icons.sql`
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

## Fitur tahap 3
- **Popup flyer** (`/admin/flyers.php`): tampil sekali per sesi tab (`sessionStorage` key `new_menu_popup_seen`, berisi id flyer); muncul lagi di sesi baru, setelah order selesai, atau bila flyer baru. Hanya flyer `is_active=1` dan dalam periode (tanggal kosong = tanpa batas); bila ada beberapa, yang terbaru dipakai.
- **Top Seller** (`/admin/top-sellers.php`): kolom `products.is_top_seller` + `top_seller_order`; customer melihat maks 8 menu tersedia, urut `top_seller_order`, geser horizontal.
- **Promo banner** (`/admin/promos.php`): carousel otomatis 4,5 detik, bisa di-swipe, indikator bila >1 banner; hanya yang aktif & dalam periode.
- **Tombol Lihat Keranjang**: tombol bulat warna aksi di bawah layar, terpisah dari footer; ada state kosong.
- **QR Code Management** (`/admin/qr.php`): Base URL disimpan di tabel `settings` (`site_url`); QR dibuat ulang otomatis. Validasi: hanya http/https, tanpa HTML/spasi/kutip/query/fragment, trailing slash dibuang.
- Upload gambar (menu, flyer, promo): cek ekstensi + MIME + ukuran 2MB, nama acak; flyer/promo di `assets/uploads/`.
- Navigasi admin berupa sidebar (offcanvas di HP).

## Fitur tahap 4
- **Sidebar admin collapsible**: tombol ☰ (desktop) mengecilkan sidebar jadi icon-only (animasi 0,3s, konten melebar); state di `localStorage` key `admin_sidebar_collapsed`. Di HP berupa off-canvas dengan overlay. URL menu tidak berubah.
- **Promo & flyer terhubung produk** (`promos.product_id`, `flyers.product_id`, FK ke `products`): produk wajib dipilih (divalidasi server, preview nama/harga/status). Produk yang dipakai promo/flyer tidak bisa dihapus (ditandai Habis) sehingga relasi tidak rusak. Promo/flyer lama tanpa produk tetap tampil tapi tidak bisa diklik sampai diedit.
- **Detail produk** (modal, bottom-sheet di HP): klik gambar/nama di menu & Top Seller, atau gambar promo/flyer. Prev/Next mengikuti daftar yang sedang tampil (menu terfilter / top seller), bisa di-swipe. Tombol **+ Tambah Pesanan** menambah qty (tanpa duplikat); produk Habis dinonaktifkan. Server tetap memvalidasi produk & harga saat order.
- **Istilah "Keranjang" -> "Pesanan"** di seluruh tampilan customer (nama internal `cart` tidak diubah).
- **Kategori ber-icon** (Bootstrap Icons lokal, whitelist; dipilih admin di Categories) dan dapat digeser horizontal.
