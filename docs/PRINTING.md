# Cetak Struk, Tagihan, Tiket Dapur & Delivery

Halaman: `admin/receipt.php?id=<order>&type=customer|bill|kitchen|delivery`
(dibuka dari POS: *Cetak struk*, *Cetak tagihan*, *Cetak tiket dapur*; dari Delivery: *Struk*).

## Cara kerja
- Dicetak lewat **dialog print browser** (`window.print()`), bukan perintah langsung ke printer.
- Lebar kertas **58 mm atau 80 mm** diatur di **Settings → Lebar kertas struk** (CSS `@page size`).
- Tiket dapur dipisah per area produksi (Dapur/Bar) dan per kiriman (Tambahan #2, dst.), tanpa harga.
- Tagihan (*bill*) diberi tanda "BELUM LUNAS — bukan bukti pembayaran".

## Kebutuhan & batasan (penting)
- Printer thermal harus terpasang sebagai printer di sistem operasi **dengan driver** yang mendukung ukuran kertas
  58/80 mm (mis. driver dari vendor printer). Atur ukuran kertas & margin 0 di preferensi driver.
- Browser selalu menampilkan dialog print. Untuk cetak tanpa dialog di komputer kasir khusus, Chrome bisa dijalankan
  dengan `--kiosk-printing` (mencetak ke printer default) — konfigurasi ini di sisi perangkat, bukan aplikasi.
- Perintah ESC/POS (potong kertas otomatis, buka laci kas, logo bitmap) **tidak** dikirim oleh browser. Pemotongan
  kertas bergantung pada pengaturan driver. Membuka laci kas otomatis membutuhkan driver yang mendukung "open drawer
  after print" atau aplikasi print-bridge lokal (belum termasuk).
- Tablet/HP: cetak bergantung pada dukungan printer Bluetooth/jaringan di OS (mis. Android print service vendor).
- Uji cetak di printer yang sebenarnya sebelum operasional: font monospace bisa berbeda per perangkat.
