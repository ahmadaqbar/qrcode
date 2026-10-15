# Backup & Restore

## Yang harus di-backup
1. Database `restaurant_ordering` (pesanan, pembayaran, shift, stok, audit, antrean integrasi).
2. Folder upload: `assets/uploads/` dan `assets/images/menu/` (foto menu, promo, flyer).
3. `config/local.php` (kredensial DB & bridge) — simpan terpisah dan terenkripsi, jangan di repository.

## Backup harian (contoh)
```bash
tools/backup.sh /var/backups/qrpos      # menyimpan .sql.gz + arsip upload, menghapus yang > 30 hari
```
Contoh cron (setiap hari 02:00):
```
0 2 * * * DB_USER=backup DB_PASS=rahasia /path/ke/app/tools/backup.sh /var/backups/qrpos >> /var/log/qrpos-backup.log 2>&1
```
`mysqldump --single-transaction` membuat snapshot konsisten tanpa mengunci kasir (InnoDB).

## Sebelum migrasi / perubahan besar
Selalu backup dulu, lalu jalankan migrasi **berurutan, masing-masing sekali**:
```bash
mysqldump --single-transaction --routines restaurant_ordering > backup_sebelum_migrasi.sql
mysql restaurant_ordering < database/migrations/00X_nama.sql
```

## Restore
```bash
gunzip -c /var/backups/qrpos/db-YYYYmmdd-HHMM.sql.gz | mysql restaurant_ordering
tar -xzf /var/backups/qrpos/uploads-YYYYmmdd-HHMM.tar.gz -C /path/ke/app
```
Uji restore secara berkala ke database terpisah (mis. `restaurant_ordering_restore_test`) — backup yang belum pernah
diuji restore belum terbukti bisa dipakai. Simpan salinan di lokasi lain (off-site).
