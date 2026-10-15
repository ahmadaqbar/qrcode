/* Denah meja: status mengikuti transaksi sebenarnya (api/pos-data.php -> table_overview). */
(function () {
  'use strict';
  var body = document.body;
  if (body.getAttribute('data-page') !== 'floor') return;
  var BASE = body.getAttribute('data-base') || '', CSRF = body.getAttribute('data-csrf');
  function $(s) { return document.querySelector(s); }
  function el(t, c, x) { var n = document.createElement(t); if (c) n.className = c; if (x !== undefined && x !== null) n.textContent = x; return n; }
  function rupiah(n) { return 'Rp' + String(Math.round(n || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
  var labels = {}, tables = [], panel, current = null;
  var ORDER_ST = { DRAFT: 'Ditahan', NEW: 'QR baru', PROCESSING: 'Berjalan', COMPLETED: 'Lunas' };

  function mins(since) { if (!since) return ''; var m = Math.floor((Date.now() - new Date(since.replace(' ', 'T')).getTime()) / 60000); return m >= 0 ? m + ' mnt' : ''; }
  function render() {
    var g = $('#floor-grid'); g.textContent = '';
    var cnt = {};
    tables.forEach(function (t) {
      cnt[t.status] = (cnt[t.status] || 0) + 1;
      var b = el('button', 'table-card tstat-' + t.status); b.type = 'button';
      b.appendChild(el('span', 'table-no', t.table_number));
      b.appendChild(el('span', 'small fw-semibold', labels[t.status]));
      if (t.orders.length) {
        b.appendChild(el('span', 'small', t.orders.map(function (o) { return o.number; }).join(', ')));
        if (t.total) b.appendChild(el('span', 'small fw-bold', rupiah(t.total)));
        b.appendChild(el('span', 'small text-muted', mins(t.since)));
      }
      b.addEventListener('click', function () { open(t); });
      g.appendChild(b);
    });
    var st = $('#floor-stats'); st.textContent = '';
    Object.keys(labels).forEach(function (k) {
      var c = el('div', 'col-6 col-md'); var d = el('div', 'tstat tstat-' + k + ' w-100 d-flex justify-content-between');
      d.appendChild(el('span', '', labels[k])); d.appendChild(el('strong', '', String(cnt[k] || 0))); c.appendChild(d); st.appendChild(c);
    });
    if (current) { var t = tables.find(function (x) { return x.id === current; }); if (t && panel && $('#tablePanel').classList.contains('show')) fill(t); }
  }
  function fill(t) {
    current = t.id;
    $('#tp-title').textContent = 'Meja ' + t.table_number + ' · ' + labels[t.status];
    var b = $('#tp-body'); b.textContent = '';
    if (!t.orders.length) b.appendChild(el('p', 'text-muted', 'Meja kosong.'));
    t.orders.forEach(function (o) {
      var a = el('a', 'list-group-item list-group-item-action d-flex justify-content-between align-items-center'); a.href = 'pos.php?order=' + o.id;
      var l = el('span'); l.appendChild(el('div', 'fw-semibold', o.number + (o.source === 'QR' ? ' · QR' : ''))); l.appendChild(el('div', 'small text-muted', ORDER_ST[o.status] + ' · ' + o.payment_status));
      a.appendChild(l); a.appendChild(el('span', 'fw-bold', rupiah(o.total)));
      var w = el('div', 'list-group mb-2'); w.appendChild(a); b.appendChild(w);
    });
    var active = t.orders.some(function (o) { return o.status !== 'COMPLETED'; });
    var bt = el('div', 'd-grid gap-2 mt-3');
    var n = el('a', 'btn btn-dark btn-lg', active ? 'Tambah pesanan ke meja ini' : 'Pesanan baru'); n.href = 'pos.php?table=' + t.id; bt.appendChild(n);
    if (t.status === 'PAID') {
      var r = el('button', 'btn btn-success btn-lg', 'Tandai meja tersedia'); r.type = 'button';
      r.addEventListener('click', function () {
        r.disabled = true;
        fetch(BASE + 'api/pos-action.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
          body: JSON.stringify({ action: 'release_table', table_id: t.id }) }).then(function (x) { return x.json(); })
          .then(function (j) { if (!j.ok) alert(j.error); load(); panel.hide(); });
      });
      bt.appendChild(r);
    }
    b.appendChild(bt);
  }
  function open(t) { panel = panel || new bootstrap.Offcanvas($('#tablePanel')); fill(t); panel.show(); }
  function load() {
    return fetch(BASE + 'api/pos-data.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { if (r.status === 401) location.href = 'login.php'; return r.json(); })
      .then(function (j) {
        if (!j.ok) return;
        labels = j.table_labels; tables = j.tables;
        var lg = $('#floor-legend'); lg.textContent = 'Status mengikuti transaksi; diperbarui otomatis.';
        render();
      });
  }
  function loop() { load().then(function () { setTimeout(loop, 8000); }, function () { setTimeout(loop, 8000); }); }
  loop();
})();
