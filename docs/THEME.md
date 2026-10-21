# Tema "Quiet Ethnic"

Prinsip: **90% bersih dan lega, 10% etnik.** Etnik hadir lewat warna hangat, tipografi berkarakter, dan sedikit motif kecil
(lingkaran konsentris, titik-titik berderet) — bukan pola yang memenuhi layar. Tema hanya mengubah tampilan; logika PHP,
skema database, route, nama field form, polling 4 detik, notifikasi/suara, validasi server, rate limit, upload, nama internal
`cart`, dan key storage (`new_menu_popup_seen`, `admin_sidebar_collapsed`) tidak berubah.

## Berkas

| Berkas | Fungsi |
| --- | --- |
| `assets/css/quiet.css` | Seluruh tema (token warna, customer, admin ringan, login, QR, cetak). Dimuat di `includes/header.php` setelah Bootstrap dan `style.css`, dengan `?v=<filemtime>` (cache-busting). |
| `assets/css/style.css` | Hanya sisa aturan dasar/admin (sidebar, tabel promo, icon kategori, footer). |
| `assets/fonts/` | DM Serif Display 400 dan DM Sans 400/500/700 (woff2 lokal, SIL OFL disertakan), `font-display: swap`. |
| `assets/images/q/pattern-hero.svg` | Satu-satunya pola: motif lingkaran konsentris 2 KB, dipakai di hero header (opacity 8%) dan halaman login (9%). |
| `design-preview.html` | Preview statis Varian A dan B berdampingan (memakai CSS yang sama). Buka lewat `http://localhost/<folder>/design-preview.html`. |
| `assets/css/legacy/`, `assets/fonts/legacy/`, `assets/images/legacy/`, `tools/legacy/` | Tema "African Ethnic" lama. **Tidak dimuat**; disimpan untuk rollback. |

Total aset tema sisi customer ± 110 KB (CSS 39 KB, font 68 KB, SVG 2 KB); setelah gzip CSS ± 9 KB.

## Anggaran ornamen

* Pola/motif besar hanya di hero header, halaman login, dan ikon halaman kosong/error/sukses.
* Daftar menu & pesanan: tanpa pola di belakang teks, kartu, harga, atau tombol.
* Satu-satunya ornamen di daftar menu: garis titik-titik tipis di bawah judul kategori.
* Tidak ada zig-zag/segitiga berbaris dan tidak ada bingkai foto.

## Palet (variabel `--q-*` di `quiet.css`)

| Peran | Varian A "Warm Minimal" | Varian B "Espresso Night" |
| --- | --- | --- |
| Latar | `#FBF6EC` | `#1E1611` |
| Kartu | `#FFFFFF`, border `#EFE4D0` | `#2A1F18`, border `#3B2D23` |
| Teks / sekunder | `#2B1D14` / `#6B5A4A` | `#F6EBDC` / `#C2B09A` |
| Aksi utama, harga | terakota `#B95A14` | emas `#E3A62F` |
| Aksen (lencana Meja/Top Seller) | mustard `#E3A62F` | terakota `#D9762A` |
| Sukses/diterima | teal `#1E7C80` | `#4FB3B7` |
| Bahaya / Habis | `#B5242F` | `#F07A82` |
| Hero header | `#1E1611` + motif 8% | `#140D09` |

Catatan: terakota aksi memakai `#B95A14`, sedikit lebih gelap dari `#C8641B` agar teks putih di atasnya lolos WCAG AA (≥ 4,5:1).
`#C8641B` asli dipakai hanya untuk dekorasi (`--q-primary-bright`).

## Mengganti varian

Varian A adalah default. Untuk Varian B (Espresso Night):

* Cara cepat tanpa kode: tambahkan setting di database
  `INSERT INTO settings (setting_key, setting_value) VALUES ('theme_variant','night') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);`
  (hapus baris atau isi kosong untuk kembali ke A). Admin/login tidak terpengaruh.
* Atau pasang class `theme-night` pada `<body>` (lihat `theme_body_class()` di `includes/functions.php`).

Semua warna lewat variabel CSS, jadi menyesuaikan palet cukup mengubah blok `:root` / `.theme-night`.

## Tipografi

Judul (nama restoran, judul kategori, angka besar): **DM Serif Display**. Teks, nama menu, tombol: **DM Sans**.
Body 15 px, nama menu 16 px bold, harga 16 px bold terakota, deskripsi 13 px dibatasi 2 baris.

## Perilaku tampilan

* Header: hero ("Selamat datang di") hanya terlihat saat belum scroll; saat scroll mengecil jadi bar solid 56 px
  (CSS murni: `position: sticky` dengan `top` negatif, tanpa JS dan tanpa loncatan). Chip kategori menempel di bawahnya dan memudar di tepi.
* Daftar menu: kartu horizontal (foto 88 px, nama, deskripsi 2 baris, harga, tombol `+` yang berubah menjadi stepper `− qty +`);
  2 kolom di ≥ 768 px, lebar maksimum 960 px (≥ 1200 px: 1100 px).
* Bar "Lihat Pesanan (n) · Rp total": pill melayang; meluncur turun dan hilang saat kosong; angka berdenyut saat jumlah berubah.
* Harga di semua halaman memakai format `Rp 17.500` (PHP `rupiah()` dan JS `rupiah()`); catatan harga dari `settings.price_note`
  (kosong = disembunyikan).
* Halaman sukses: stepper status live Diterima → Diproses → Selesai (di-update oleh polling yang sudah ada).
* Skeleton pemuatan gambar, efek tekan tombol, transisi 150–350 ms; semuanya mati bila `prefers-reduced-motion`.

## Yang perlu Anda sediakan sendiri (opsional)

* **Logo**: `assets/images/theme/logo.svg` (atau `.png` / `.webp`), tampil di header customer dan login.
* **Foto menu** lewat admin Products (tanpa foto → placeholder netral hangat `assets/images/placeholder.svg`).
* **Font lain** (mis. font brand): letakkan woff2 di `assets/fonts/`, tambahkan `@font-face` dan ganti `--q-font-head` / `--q-font-body`.

## Rollback

Tema berada di branch `feature/quiet-ethnic-theme` (satu commit per tahap).

* Belum di-merge: tetap di branch lama.
* Sudah di-merge: `git revert -m 1 <merge-commit>` atau revert commit per tahap.
* Kembali ke tema lama saja: di `includes/header.php` ganti `assets/css/quiet.css` dengan `assets/css/legacy/theme-african.css`
  dan pulihkan `assets/css/legacy/style-customer-and-admin.css` menjadi `assets/css/style.css`
  (markup baru memakai kelas berbeda, jadi rollback penuh sebaiknya lewat git).
