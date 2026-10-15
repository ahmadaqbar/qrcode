# Integrasi Accurate — Status, Desain, dan Langkah Go-Live

> **Status saat ini: BELUM TERVERIFIKASI dengan Accurate sungguhan.**
> Yang sudah berjalan dan teruji: antrean integrasi, validasi & mapping, idempotensi, retry dengan
> verifikasi, mode **simulasi**, dan adapter **HTTP bridge** yang diuji terhadap *mock* (bukan Accurate).
> Tidak ada dokumen yang pernah dikirim ke Accurate. Jangan anggap integrasi selesai sebelum Tahap H di bawah lulus.

## Tahap A — Hasil validasi & daftar yang harus dikonfirmasi

### Yang bisa diverifikasi dari sumber publik (Oktober 2026)
- **Accurate Online** memiliki REST API resmi: header `X-Session-ID` dari `/api/open-db.do`, endpoint
  `{host}/accurate/api/<modul>/save.do`, parameter dikirim sebagai HTTP POST.
  Sumber: <https://accurate.id/api-integration/api-example/>
- Accurate Online mendukung **impor faktur penjualan dari Excel** dan impor penerimaan penjualan.
  Sumber: <https://accurate.id/release-notes-aol/1009305/>, <https://accurate.id/release-notes-aol/1014611/>
- **Accurate 5 / Deluxe (desktop):** tidak ditemukan dokumentasi publik resmi mengenai API, SDK, atau format impor
  transaksi yang dapat diverifikasi. Mekanismenya **harus dikonfirmasi** ke vendor/pengelola Accurate perusahaan.

### Pertanyaan yang wajib dijawab pengelola Accurate perusahaan
| # | Pertanyaan | Jawaban |
|---|---|---|
| 1 | Produk & edisi tepatnya (Accurate 5 Deluxe / Enterprise / Accurate Online / lainnya) dan nomor versi/build | |
| 2 | Arsitektur instalasi: server database di mana (PC kantor, server lokal, cloud), OS, akses jaringan dari server POS | |
| 3 | Mekanisme integrasi resmi yang tersedia & berlisensi: API/SDK, impor file (format apa, menu apa), atau konektor pihak ketiga | |
| 4 | Jenis dokumen yang disetujui akuntan: Faktur Penjualan + Penerimaan, atau Faktur tunai, atau jurnal harian, per transaksi atau batch per shift/hari | |
| 5 | Kode **pelanggan umum**, **cabang**, **gudang** | |
| 6 | Kode **barang** untuk setiap produk menu (atau barang jasa) | |
| 7 | Kode **pajak** (PB1/PPN) & apakah harga termasuk pajak | |
| 8 | Perlakuan **service charge**, **ongkos kirim**, **biaya lain**: barang jasa atau akun pendapatan lain | |
| 9 | **Akun kas/bank** per metode pembayaran (Tunai, Debit/Kartu, QRIS, Transfer, E-wallet) | |
| 10 | Cara Accurate mencegah duplikasi: apakah nomor referensi/“No. Faktur” bisa diisi dari POS dan dicari kembali | |
| 11 | Perlakuan **refund/retur** | |
| 12 | Lingkungan uji (database Accurate percobaan) yang boleh dipakai sebelum live | |

## Arsitektur yang dibangun

```
Kasir bayar (POS) ──► orders/payments (sumber kebenaran, tidak pernah diubah oleh integrasi)
        │  dalam transaksi DB yang sama
        ▼
integration_queue  (key unik: POS-ORD-000123-SALE / -REFUND)
        │  cron: php cron/integration-worker.php   (atau tombol "Proses antrean sekarang")
        ▼
Worker: validasi mapping & aritmetika ─► [verifikasi bila percobaan ulang] ─► Adapter
        ├─ SimulationAdapter  (mode simulasi; dokumen tiruan SIM-xxxx)
        └─ BridgeAdapter      (HTTP JSON ke layanan bridge di sisi server Accurate)
                                     │
                                     ▼  (dibangun setelah Tahap A — memakai mekanisme RESMI yang terverifikasi)
                               Accurate
```

Kenapa **bridge**: aplikasi web ini tidak boleh menulis ke database internal Accurate, dan mekanisme Accurate desktop
biasanya hanya tersedia di jaringan/komputer tempat Accurate terpasang. Bridge adalah layanan kecil terpisah di sisi
tersebut yang memakai mekanisme resmi (API/SDK/impor) yang disetujui.

## Kontrak HTTP bridge (yang diharapkan BridgeAdapter)

Semua request membawa `Authorization: Bearer <token>`. Token & URL dibaca dari **konfigurasi server**
(`ACCURATE_BRIDGE_URL`, `ACCURATE_BRIDGE_TOKEN`, `ACCURATE_BRIDGE_TIMEOUT` sebagai env, atau `config/local.php`
kunci `accurate_bridge_url`, `accurate_bridge_token`, `accurate_bridge_timeout`). Tidak pernah ke browser/log.

| Endpoint | Perilaku wajib |
|---|---|
| `GET /health` | 200 bila bridge & koneksi ke Accurate sehat. |
| `POST /documents` | Header `Idempotency-Key`. Body = payload di bawah. **Harus idempoten**: key yang sama tidak boleh membuat dokumen kedua. 201/200 `{ "reference": "<no/ID dokumen Accurate>" }` bila dibuat; 409 `{ "reference": ... }` bila sudah ada; 4xx `{ "error": ... }` bila ditolak (tidak di-retry otomatis); 5xx/timeout = hasil tidak pasti. |
| `GET /documents/{key}` | 200 `{ "reference": ... }` bila dokumen dengan key itu sudah ada di Accurate; 404 bila belum. Dipakai worker **sebelum setiap percobaan ulang**. |

Status **SUCCESS** hanya disimpan bila bridge mengembalikan `reference` (bukti dokumen tercatat).

Contoh payload `SALE` (format internal; bridge memetakannya ke dokumen Accurate yang disetujui):
```json
{
  "idempotency_key": "POS-ORD-000123-SALE", "document_type": "SALE", "source": "QR-POS",
  "source_reference": "ORD-000123", "transaction_date": "2026-10-15 19:30:00", "order_type": "DINE_IN",
  "branch_code": "…", "warehouse_code": "…", "customer_code": "…",
  "lines": [{ "item_code": "…", "name": "Nasi Goreng", "quantity": 2, "unit_price": 25000, "discount": 0, "amount": 50000 }],
  "subtotal": 50000, "discount": { "amount": 5000, "type": "PERCENT", "value": 10 },
  "tax": { "code": "…", "rate": 10, "amount": 4725, "includes_service": true },
  "charges": [{ "item_code": "…", "name": "Service charge", "amount": 2250 }],
  "total": 51975,
  "payments": [{ "method": "QRIS", "account_code": "…", "amount": 51975, "reference": "…", "paid_at": "…" }]
}
```
Payload `REFUND` memuat `original_key`, `refund_total`, dan daftar refund per metode + akun.

## Validasi sebelum kirim (worker)
- Semua mapping wajib terisi (cabang, gudang, pelanggan, akun per metode bayar yang dipakai, barang jasa untuk
  service/ongkir/biaya lain bila ada, kode pajak bila ada pajak) dan setiap produk punya **kode barang Accurate**.
- Aritmetika: `Σ baris − diskon + service + pajak + ongkir + biaya lain = total` dan `Σ pembayaran = total`.
- Gagal validasi → `FAILED` dengan pesan jelas, **tidak dikirim**. Perbaiki mapping lalu klik **Retry**.

## Status antrean & aturan retry
| Status | Arti |
|---|---|
| PENDING | Menunggu diproses (refund juga menunggu sampai penjualan aslinya SUCCESS). |
| PROCESSING | Sedang diklaim worker (lock 5 menit; bila worker mati, otomatis jadi RETRY + wajib verifikasi). |
| SUCCESS | Target mengembalikan referensi dokumen. Mode simulasi diberi label **SIMULASI**. |
| RETRY_SCHEDULED | Gagal sementara / hasil tidak pasti. Backoff 1, 2, 4 … maks 60 menit; maks 8 percobaan. |
| FAILED | Ditolak target, validasi gagal, atau batas percobaan tercapai. Butuh tindakan manusia. |

**Pencegahan duplikasi:** key konsisten per transaksi; setiap percobaan ulang selalu `lookup(key)` dulu. Bila verifikasi
juga tidak pasti, item **tidak** dikirim ulang (tetap dijadwalkan). Teruji: timeout setelah dokumen terbuat →
retry menemukan dokumen → SUCCESS tanpa kiriman kedua; 3 worker paralel → tiap transaksi dikirim tepat sekali.

## Kapan transaksi masuk antrean
- **Saat lunas (default, direkomendasikan MVP).** Kasir tidak pernah menunggu Accurate.
- **Manual per rentang tanggal** (Integrasi Accurate → *Antrekan rentang*) bila akuntan meminta batch per shift/hari.
  Penggabungan beberapa transaksi menjadi satu dokumen **belum** diimplementasikan — hanya setelah disetujui akuntan.

## Tahap H — Checklist verifikasi go-live (wajib sebelum mode bridge dipakai produksi)
1. Tahap A terjawab & disetujui tertulis oleh pengelola Accurate/akuntan.
2. Bridge dibangun dengan mekanisme resmi; kontrak di atas (khususnya idempotensi & lookup) diuji di database Accurate **percobaan**.
3. Isi mapping & kode barang dari data yang disetujui. Pastikan "Mapping wajib belum lengkap: 0" dan "Produk tanpa kode: 0".
4. Set `config/local.php` (URL+token), pilih mode **Bridge**, klik **Tes koneksi bridge**.
5. Buat 1 transaksi uji (dengan diskon, pajak, service, 2 metode bayar) → proses → buka dokumennya di Accurate dan cocokkan:
   total, item & qty, diskon/pajak, akun kas/bank per metode, nomor referensi POS. Catat nomor dokumen Accurate.
6. Uji retry: matikan jaringan bridge saat kirim → nyalakan → retry → pastikan **tidak ada dokumen ganda** di Accurate.
7. Uji refund sesuai prosedur akuntansi yang disetujui.
8. Bila sebelumnya memakai simulasi: klik **Antre ulang hasil simulasi** (hasil simulasi belum pernah masuk Accurate).
9. Pasang cron worker (lihat `cron/integration-worker.php`) dan pantau halaman Integrasi Accurate.

## Yang sengaja TIDAK dilakukan
- Tidak menulis/membaca database internal Accurate secara langsung.
- Tidak mengarang kode akun/barang; semua mapping kosong sampai diisi dari data resmi.
- Tidak menganggap file CSV/Excel otomatis menghasilkan transaksi lengkap tanpa validasi format resmi.
- Tidak mengklaim berhasil tanpa referensi dokumen dari target.

## Menguji sendiri tanpa Accurate
```bash
# Mock bridge (BUKAN Accurate) — hanya untuk menguji kontrak HTTP:
MOCK_TOKEN=uji MOCK_STORE=/tmp/mock.json php -S 127.0.0.1:8099 tools/mock-accurate-bridge/index.php
# config/local.php: 'accurate_bridge_url' => 'http://127.0.0.1:8099', 'accurate_bridge_token' => 'uji'
# Simulasi gangguan: echo timeout > /tmp/mock.json.fault   (timeout | error500 | reject | down | none)
```
Folder `tools/` dan `cron/` tidak boleh dapat diakses dari web (sudah ada `.htaccess`; pada Nginx tambahkan `deny`).
