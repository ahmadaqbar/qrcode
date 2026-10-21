# Tema "African Ethnic / Batik Tribal"

Tema ini hanya mengubah tampilan (CSS, aset statis, dan markup/class). Logika bisnis, skema database,
URL/route, nama field form, polling 4 detik, notifikasi/suara, validasi server, rate limit, dan upload tidak berubah.

## Berkas

| Berkas | Fungsi |
| --- | --- |
| `assets/css/theme-african.css` | Seluruh tema (token warna, komponen, halaman customer, admin, print). Dimuat setelah `style.css` oleh `includes/header.php`. |
| `assets/fonts/` | Font lokal (woff2): Poppins 400/600/800 dan Alfa Slab One (lisensi SIL OFL disertakan). |
| `assets/images/theme/*.svg` | Pola tribal, pemisah zig-zag/ornamen, bingkai foto, tekstur kertas, lencana. Total ± 34 KB. |
| `tools/gen-theme-patterns.py` | Generator SVG di atas (hanya alat dev; tidak dipakai saat runtime). |
| `includes/functions.php` | Helper: `price_short_html()`, `price_note_text()`, `theme_logo()`. |

Total aset tema ± 120 KB (font 53 KB, SVG 34 KB, CSS 33 KB). Tidak ada CDN, tidak ada build step.

## Palet (token CSS di `:root`)

`--ink #14110F` · `--navy #0F1B2D` · `--orange #E8861B` / `--orange-d #D9741A` · `--mustard #F2B33D` ·
`--teal #1E8A8F` · `--blue #1F4E8C` · `--crimson #B5242F` · `--paper #F6EFE2` · `--bone #FBF6EC`.

Font: judul bergaya slab-serif **Alfa Slab One** (judul seksi, nama brand, nomor pesanan), teks **Poppins**
(judul kategori menu, daftar menu, form). Ganti lewat `--font-head` / `--font-body`.

## Yang perlu Anda sediakan sendiri (opsional)

* **Logo SCENIC / restoran**: taruh di `assets/images/theme/logo.svg` (atau `.png` / `.webp`). Otomatis tampil di header customer dan halaman login.
* **Latar kustom**: `assets/images/theme/bg-scenic.webp` (disarankan ≤ 150 KB) — jika ada, dipakai sebagai latar halaman customer.
* **Foto menu asli**: unggah lewat admin (Products). Bingkai etnik dipasang otomatis oleh CSS.

## Pengaturan

* Catatan harga ("*Harga belum termasuk pajak & service"): tabel `settings`, key `price_note`. Nilai kosong menyembunyikan catatan.
  Contoh: `INSERT INTO settings (setting_key, setting_value) VALUES ('price_note','*Harga sudah termasuk pajak') ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);`
* Harga singkat ("17,5 K") hanya di daftar menu/top seller; keranjang, konfirmasi, dan sukses memakai Rupiah penuh.

## Regenerasi pola

```
python3 tools/gen-theme-patterns.py
```

## Aksesibilitas & performa

Kontras teks mengikuti WCAG AA, target sentuh ≥ 44 px, `prefers-reduced-motion` menonaktifkan animasi,
konten `.reveal` hanya disembunyikan jika JavaScript aktif (kelas `html.js`).

## Rollback

Tema berada di branch `feature/african-ethnic-theme` (satu commit per fase). Untuk membatalkan:

* Belum di-merge: cukup jangan merge / `git checkout main`.
* Sudah di-merge: `git revert -m 1 <merge-commit>` atau revert tiap commit fase (A–E).
* Cepat tanpa git: hapus baris `theme-african.css` di `includes/header.php`; tampilan kembali ke Bootstrap + `style.css`
  (kelas tambahan di markup tidak berbahaya).
