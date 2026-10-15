# Restaurant QR Ordering (PHP 7.4 + MySQL)

Customer scan QR meja → pilih menu → keranjang → catatan → konfirmasi → pesanan berhasil.
Staff login → dashboard (polling 4 detik, popup + notifikasi browser + suara) → terima → selesai.

## Setup
1. `mysql -u root -p < database/schema.sql` (membuat DB `restaurant_ordering` + data contoh).
   Upgrade dari versi lama (berurutan, masing-masing sekali): `database/migrations/002_security_and_admin.sql`, lalu `003_flyers_promos_topseller_settings.sql`, lalu `004_product_links_category_icons.sql`, `005_pos_foundation.sql`, `006_pos_operations.sql`, `007_delivery_inventory.sql`, `008_accurate_integration.sql` (backup dulu — lihat docs/BACKUP.md)
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

## POS restoran (tahap 5)
Satu sumber data: pesanan QR dan POS memakai tabel `orders`/`order_items` yang sama (pesanan QR dibayar di POS memakai record yang sama).

| Modul | Halaman | Catatan |
|---|---|---|
| POS Kasir | `/admin/pos.php` | Produk + kategori ber-icon, cari nama/SKU, dine-in/takeaway/delivery, catatan & diskon item/transaksi, hold/resume, antrean pesanan QR, cegah tagihan ganda per meja, optimistic locking antar perangkat |
| Meja | `/admin/floor.php` | Status Available / Occupied / Ordering / Waiting Payment / Paid diturunkan dari transaksi; pindah, gabung, pisah tagihan |
| Pembayaran | dialog di POS | Tunai (kembalian), Debit/Kartu, QRIS, Transfer, E-wallet, Lainnya — **dicatat manual, tanpa payment gateway**; bayar sebagian; anti bayar ganda (row lock + idempotency key); refund tercatat sebagai baris negatif |
| Kitchen Display | `/admin/kitchen.php` | Tiket per pesanan × area (Kitchen/Bar) × kiriman; Baru → Disiapkan → Siap → Selesai; durasi; suara & kedip untuk tiket baru |
| Shift kasir | `/admin/shift.php` | Modal awal, kas masuk/keluar, tutup shift (selisih kas), blokir bila ada hold/bayar sebagian kecuali override manager |
| Delivery | `/admin/delivery.php` | Data antar seperlunya, tugaskan kurir, status New → … → Delivered + riwayat, struk antar; kurir hanya melihat tugasnya |
| Struk | `/admin/receipt.php` | Struk pelanggan, tagihan, tiket dapur, delivery — 58/80 mm (docs/PRINTING.md) |
| Inventory | `/admin/inventory.php` | Stok produk jadi (bukan bahan baku/resep) |
| Laporan | `/admin/reports.php` | Penjualan sah, per metode/kategori/tipe, terlaris, void, refund, shift, transaksi belum selesai |
| Settings | `/admin/settings.php` | Pajak, service, ongkir, struk, metode pembayaran (tarif disimpan per transaksi) |
| Users & Roles | `/admin/users.php`, `/admin/roles.php` | Administrator, Manager, Kasir, Waiter, Kitchen, Delivery Staff; izin diperiksa di server; otorisasi supervisor untuk void/refund/pindah meja |
| Integrasi Accurate | `/admin/accurate.php` | Antrean + simulasi + HTTP bridge; **belum terverifikasi dengan Accurate sungguhan** — lihat docs/ACCURATE_INTEGRATION.md |
| Audit Log | `/admin/audit.php` | Void, refund, diskon, shift, pengaturan, user, integrasi |

Cron yang disarankan: `* * * * * php cron/integration-worker.php` dan backup harian `tools/backup.sh` (docs/BACKUP.md).
Folder `cron/` dan `tools/` tidak boleh diakses dari web (Apache: `.htaccess` sudah ada; Nginx: tambahkan `deny`).
