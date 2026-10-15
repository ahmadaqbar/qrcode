<?php
require_once __DIR__ . '/../includes/auth.php';
require_permission('pos.use');

$page_title = 'POS Kasir';
$base = '../';
$admin = true;
$page = 'pos';
$body_attrs = ['csrf' => csrf_token(), 'open-order' => isset($_GET['order']) ? (int) $_GET['order'] : 0,
               'open-table' => isset($_GET['table']) ? (int) $_GET['table'] : 0];
$extra_scripts = ['assets/js/pos.js'];
include __DIR__ . '/../includes/header.php';
?>
<div class="pos" id="pos">
  <!-- ===== Area utama: pencarian, kategori, produk ===== -->
  <section class="pos-main">
    <div class="pos-topbar">
      <div class="input-group pos-search">
        <span class="input-group-text bg-white"><i class="bi bi-search" aria-hidden="true"></i></span>
        <input type="search" class="form-control" id="pos-search" placeholder="Cari nama / kode produk" autocomplete="off" aria-label="Cari produk">
      </div>
      <button class="btn btn-outline-dark position-relative" id="btn-queue" type="button" data-bs-toggle="offcanvas" data-bs-target="#queuePanel">
        <i class="bi bi-inboxes" aria-hidden="true"></i> <span class="d-none d-sm-inline">Antrean</span>
        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger d-none" id="queue-badge">0</span>
      </button>
    </div>
    <div class="pos-cats" id="pos-cats" role="toolbar" aria-label="Kategori"></div>
    <div class="pos-grid" id="pos-grid" aria-live="polite"></div>
    <div class="text-center text-muted py-5 d-none" id="pos-empty">Produk tidak ditemukan.</div>
  </section>

  <!-- ===== Panel kanan: pesanan aktif ===== -->
  <aside class="pos-panel offcanvas-lg offcanvas-bottom" tabindex="-1" id="posPanel" aria-labelledby="pos-order-title">
    <div class="pos-panel-head">
      <div class="min-w-0">
        <div class="fw-bold text-truncate" id="pos-order-title">Transaksi Baru</div>
        <div class="small" id="pos-order-badges"></div>
      </div>
      <div class="d-flex gap-1">
        <button class="btn btn-sm btn-outline-secondary" type="button" id="btn-new" title="Transaksi baru"><i class="bi bi-plus-lg"></i> Baru</button>
        <button type="button" class="btn-close d-lg-none ms-1" data-bs-dismiss="offcanvas" data-bs-target="#posPanel" aria-label="Tutup"></button>
      </div>
    </div>

    <div class="pos-type btn-group w-100" role="group" aria-label="Tipe pesanan">
      <input type="radio" class="btn-check" name="otype" id="ot-DINE_IN" value="DINE_IN" checked>
      <label class="btn btn-outline-dark btn-sm" for="ot-DINE_IN"><i class="bi bi-shop"></i> Dine-in</label>
      <input type="radio" class="btn-check" name="otype" id="ot-TAKEAWAY" value="TAKEAWAY">
      <label class="btn btn-outline-dark btn-sm" for="ot-TAKEAWAY"><i class="bi bi-bag"></i> Takeaway</label>
      <input type="radio" class="btn-check" name="otype" id="ot-DELIVERY" value="DELIVERY">
      <label class="btn btn-outline-dark btn-sm" for="ot-DELIVERY"><i class="bi bi-bicycle"></i> Delivery</label>
    </div>

    <div class="pos-meta">
      <button class="btn btn-sm btn-light border w-100 text-start" type="button" id="btn-table"><i class="bi bi-grid-1x2"></i> <span id="table-label">Pilih meja</span></button>
      <button class="btn btn-sm btn-light border w-100 text-start d-none" type="button" id="btn-delivery"><i class="bi bi-geo-alt"></i> <span id="delivery-label">Isi data pengiriman</span></button>
      <input class="form-control form-control-sm" id="pos-customer" maxlength="100" placeholder="Nama pelanggan (opsional)" aria-label="Nama pelanggan">
    </div>

    <div class="pos-lines" id="pos-lines"></div>

    <div class="pos-summary">
      <button class="btn btn-link btn-sm p-0 mb-1" type="button" id="btn-order-note"><i class="bi bi-chat-left-text"></i> <span id="order-note-label">Catatan pesanan</span></button>
      <div class="sum-row"><span>Subtotal</span><span id="s-subtotal">Rp0</span></div>
      <div class="sum-row d-none" id="r-discount"><span>Diskon</span><span id="s-discount"></span></div>
      <div class="sum-row d-none" id="r-service"><span>Service <span id="s-srate"></span></span><span id="s-service"></span></div>
      <div class="sum-row d-none" id="r-tax"><span>Pajak <span id="s-trate"></span></span><span id="s-tax"></span></div>
      <div class="sum-row d-none" id="r-delivery"><span>Ongkos kirim</span><span id="s-delivery"></span></div>
      <div class="sum-row d-none" id="r-other"><span id="s-other-label">Biaya lain</span><span id="s-other"></span></div>
      <div class="sum-row sum-total"><span>Total</span><span id="s-total">Rp0</span></div>
      <div class="sum-row small text-success d-none" id="r-paid"><span>Sudah dibayar</span><span id="s-paid"></span></div>
      <div class="d-flex gap-2 flex-wrap small mt-1">
        <button class="btn btn-link btn-sm p-0" type="button" id="btn-discount"><i class="bi bi-percent"></i> Diskon</button>
        <button class="btn btn-link btn-sm p-0" type="button" id="btn-fee"><i class="bi bi-plus-circle"></i> Biaya tambahan</button>
      </div>
    </div>

    <div class="pos-actions">
      <div class="dropdown">
        <button class="btn btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Aksi lain"><i class="bi bi-three-dots"></i></button>
        <ul class="dropdown-menu" id="more-menu">
          <li><button class="dropdown-item" type="button" data-act="print-bill"><i class="bi bi-printer"></i> Cetak tagihan</button></li>
          <li><button class="dropdown-item" type="button" data-act="move"><i class="bi bi-arrow-left-right"></i> Pindah meja</button></li>
          <li><button class="dropdown-item" type="button" data-act="merge"><i class="bi bi-union"></i> Gabung tagihan</button></li>
          <li><button class="dropdown-item" type="button" data-act="split"><i class="bi bi-scissors"></i> Pisah tagihan</button></li>
          <li><button class="dropdown-item" type="button" data-act="kitchen-ticket"><i class="bi bi-receipt-cutoff"></i> Cetak tiket dapur</button></li>
          <li><hr class="dropdown-divider"></li>
          <li><button class="dropdown-item text-danger" type="button" data-act="cancel"><i class="bi bi-x-octagon"></i> Batalkan transaksi</button></li>
        </ul>
      </div>
      <button class="btn btn-outline-dark" type="button" id="btn-hold"><i class="bi bi-pause-circle"></i> Hold</button>
      <button class="btn btn-dark" type="button" id="btn-send"><i class="bi bi-send"></i> <span id="send-label">Ke Dapur</span></button>
      <button class="btn btn-accent" type="button" id="btn-pay"><i class="bi bi-cash-coin"></i> Bayar</button>
    </div>
  </aside>
</div>

<!-- Tombol buka panel pesanan (tablet/HP) -->
<button class="pos-fab d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#posPanel" id="pos-fab">
  <span><i class="bi bi-receipt"></i> Pesanan <span class="badge text-bg-light text-dark" id="fab-count">0</span></span>
  <span class="fw-bold" id="fab-total">Rp0</span>
</button>

<!-- Antrean: QR baru / ditahan / aktif -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="queuePanel" aria-labelledby="queue-title">
  <div class="offcanvas-header">
    <h2 class="offcanvas-title h5" id="queue-title">Antrean Pesanan</h2>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Tutup"></button>
  </div>
  <div class="offcanvas-body pt-0">
    <ul class="nav nav-pills nav-fill small mb-2" id="queue-tabs">
      <li class="nav-item"><button class="nav-link active" data-q="NEW">QR Baru <span class="badge text-bg-danger" id="qc-NEW">0</span></button></li>
      <li class="nav-item"><button class="nav-link" data-q="DRAFT">Ditahan <span class="badge text-bg-secondary" id="qc-DRAFT">0</span></button></li>
      <li class="nav-item"><button class="nav-link" data-q="PROCESSING">Aktif <span class="badge text-bg-secondary" id="qc-PROCESSING">0</span></button></li>
    </ul>
    <div class="list-group" id="queue-list"></div>
  </div>
</div>

<!-- Pilih meja -->
<div class="modal fade" id="tableModal" tabindex="-1" aria-labelledby="tm-title" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
    <div class="modal-content">
      <div class="modal-header"><h2 class="modal-title h5" id="tm-title">Pilih Meja</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
      <div class="modal-body"><div class="table-legend small mb-2" id="table-legend"></div><div class="table-grid" id="table-grid"></div></div>
    </div>
  </div>
</div>

<!-- Dialog umum (catatan item, diskon, delivery, konfirmasi) -->
<div class="modal fade" id="formModal" tabindex="-1" aria-labelledby="fm-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
    <form class="modal-content" id="fm-form" novalidate>
      <div class="modal-header"><h2 class="modal-title h5" id="fm-title"></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
      <div class="modal-body" id="fm-body"></div>
      <div class="modal-footer" id="fm-foot"></div>
    </form>
  </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" id="toasts"></div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
