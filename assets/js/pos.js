/* POS Kasir. Semua keputusan harga/izin divalidasi ulang di server (api/pos-*.php). */
(function () {
  'use strict';
  var body = document.body;
  if (body.getAttribute('data-page') !== 'pos') return;

  var BASE = body.getAttribute('data-base') || '';
  var CSRF = body.getAttribute('data-csrf') || '';

  /* ---------- util ---------- */
  function $(s, r) { return (r || document).querySelector(s); }
  function $all(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined && text !== null) n.textContent = text; return n; }
  function icon(name) { var i = el('i', 'bi ' + name); i.setAttribute('aria-hidden', 'true'); return i; }
  function rupiah(n) { n = Math.round(n || 0); return (n < 0 ? '-' : '') + 'Rp' + String(Math.abs(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
  function tok() { var a = new Uint8Array(16); crypto.getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  function api(path, data) {
    var opt = { credentials: 'same-origin', cache: 'no-store', headers: { 'X-CSRF-Token': CSRF } };
    if (data !== undefined) { opt.method = 'POST'; opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(data); }
    return fetch(BASE + 'api/' + path, opt).then(function (r) {
      if (r.status === 401) { location.href = 'login.php'; throw new Error('Sesi berakhir'); }
      return r.json().then(function (j) { j._status = r.status; return j; }, function () { return { ok: false, error: 'Respons server tidak valid', _status: r.status }; });
    });
  }
  function toast(msg, type) {
    var t = el('div', 'toast align-items-center border-0 text-bg-' + (type || 'dark'));
    t.setAttribute('role', 'status');
    var w = el('div', 'd-flex'); w.appendChild(el('div', 'toast-body', msg));
    var x = el('button', 'btn-close btn-close-white me-2 m-auto'); x.type = 'button'; x.setAttribute('data-bs-dismiss', 'toast'); x.setAttribute('aria-label', 'Tutup');
    w.appendChild(x); t.appendChild(w); $('#toasts').appendChild(t);
    var inst = new bootstrap.Toast(t, { delay: type === 'danger' ? 6000 : 2500 });
    t.addEventListener('hidden.bs.toast', function () { t.remove(); }); inst.show();
  }

  /* ---------- data & state ---------- */
  var D = { categories: [], products: [], tables: [], rates: {}, permissions: [], methods: [], shift: null, deliveryFee: 0 };
  var P = {};          // produk per id
  var cat = 'all', q = '';
  var S;
  var busy = false;
  var lineKey = 0;
  function can(p) { return D.permissions.indexOf(p) !== -1; }
  function blank() {
    return { order: null, type: 'DINE_IN', table_id: null, table_label: '', customer: '', note: '', lines: [],
             discount: { type: 'NONE', value: 0 }, other_fee: 0, other_fee_label: '', delivery_fee: 0, delivery: null,
             token: tok(), dirty: false };
  }
  S = blank();

  function editable() { return !S.order || S.order.editable; }
  function hasSent() { return S.lines.some(function (l) { return l.id && l.kitchen_status !== 'PENDING'; }); }
  function hasPending() { return S.lines.some(function (l) { return !l.id || l.kitchen_status === 'PENDING'; }); }
  function itemCount() { return S.lines.reduce(function (s, l) { return s + l.qty; }, 0); }

  /* ---------- perhitungan (sama dengan includes/orders.php compute_totals) ---------- */
  function rates() {
    if (S.order && S.order.order_type === S.type) return { service_rate: S.order.service_rate, tax_rate: S.order.tax_rate, tax_include_service: S.order.tax_include_service };
    return D.rates[S.type] || { service_rate: 0, tax_rate: 0, tax_include_service: 1 };
  }
  function lineDisc(price, qty, t, v) {
    var g = price * qty;
    if (t === 'PERCENT') return Math.min(g, Math.round(g * Math.min(100, v) / 100));
    if (t === 'AMOUNT') return Math.min(g, v);
    return 0;
  }
  function totals() {
    var sub = 0;
    S.lines.forEach(function (l) { sub += Math.max(0, l.price * l.qty - lineDisc(l.price, l.qty, l.discount_type, l.discount_value)); });
    var d = S.discount.type === 'PERCENT' ? Math.min(sub, Math.round(sub * Math.min(100, S.discount.value) / 100))
          : (S.discount.type === 'AMOUNT' ? Math.min(sub, S.discount.value) : 0);
    var base = sub - d, r = rates();
    var svc = Math.round(base * r.service_rate / 100);
    var tax = Math.round((base + (r.tax_include_service ? svc : 0)) * r.tax_rate / 100);
    var del = S.type === 'DELIVERY' ? S.delivery_fee : 0;
    return { subtotal: sub, discount: d, service: svc, tax: tax, delivery: del, other: S.other_fee, total: base + svc + tax + del + S.other_fee, r: r };
  }

  /* ---------- katalog ---------- */
  function renderCats() {
    var box = $('#pos-cats'); box.textContent = '';
    var mk = function (id, name, ic) {
      var b = el('button', 'btn btn-sm ' + (String(cat) === String(id) ? 'btn-dark' : 'btn-outline-dark'));
      b.type = 'button'; b.appendChild(icon(ic)); b.appendChild(document.createTextNode(' ' + name));
      b.setAttribute('aria-pressed', String(cat) === String(id) ? 'true' : 'false');
      b.addEventListener('click', function () { cat = id; renderCats(); renderGrid(); });
      box.appendChild(b);
    };
    mk('all', 'Semua', 'bi-grid-fill');
    D.categories.forEach(function (c) { mk(c.id, c.name, c.icon); });
  }
  function renderGrid() {
    var g = $('#pos-grid'); g.textContent = '';
    var qq = q.trim().toLowerCase(), n = 0;
    D.products.forEach(function (p) {
      if (cat !== 'all' && p.category_id !== cat) return;
      if (qq && p.name.toLowerCase().indexOf(qq) === -1 && (p.sku || '').toLowerCase().indexOf(qq) === -1) return;
      n++;
      var b = el('button', 'pos-prod' + (p.available ? '' : ' is-off')); b.type = 'button';
      b.setAttribute('data-id', p.id);
      var img = el('img'); img.src = BASE + 'assets/images/' + p.image; img.alt = ''; img.loading = 'lazy'; b.appendChild(img);
      var info = el('span', 'pos-prod-info');
      info.appendChild(el('span', 'pos-prod-name', p.name));
      var meta = el('span', 'pos-prod-meta');
      meta.appendChild(el('span', 'fw-bold', rupiah(p.price)));
      if (p.sku) meta.appendChild(el('span', 'text-muted', p.sku));
      info.appendChild(meta); b.appendChild(info);
      if (!p.available) { b.appendChild(el('span', 'badge text-bg-secondary pos-off', 'Unavailable')); b.setAttribute('aria-disabled', 'true'); }
      var inCart = S.lines.reduce(function (s, l) { return s + (l.product_id === p.id ? l.qty : 0); }, 0);
      if (inCart) b.appendChild(el('span', 'badge rounded-pill text-bg-danger pos-incart', String(inCart)));
      b.addEventListener('click', function () { addProduct(p); });
      g.appendChild(b);
    });
    $('#pos-empty').classList.toggle('d-none', n > 0);
  }

  /* ---------- pesanan aktif ---------- */
  function addProduct(p) {
    if (!p.available) { toast(p.name + ' sedang tidak tersedia.', 'secondary'); return; }
    if (!editable()) { toast('Pesanan ini sudah dibayar/ditutup. Buat transaksi baru.', 'warning'); return; }
    var l = S.lines.find(function (x) { return x.product_id === p.id && (!x.id || x.kitchen_status === 'PENDING') && !x.note && x.discount_type === 'NONE'; });
    if (l) { if (l.qty < 99) l.qty++; }
    else S.lines.push({ key: ++lineKey, id: null, product_id: p.id, name: p.name, price: p.price, qty: 1, note: '', discount_type: 'NONE', discount_value: 0, kitchen_status: 'PENDING', orig_qty: 0 });
    S.dirty = true;
    render();
    var fab = $('#pos-fab'); fab.classList.remove('pulse'); void fab.offsetWidth; fab.classList.add('pulse');
  }
  function changeQty(l, d) {
    var nq = l.qty + d;
    if (nq > 99) return;
    if (nq <= 0) {
      if (l.id && l.kitchen_status !== 'PENDING') { l.qty = 0; }
      else S.lines.splice(S.lines.indexOf(l), 1);
    } else l.qty = nq;
    S.dirty = true; render();
  }

  function badge(text, cls) { return el('span', 'badge ' + cls + ' me-1', text); }
  function renderOrder() {
    var o = S.order;
    $('#pos-order-title').textContent = o ? o.order_number + (o.source === 'QR' ? ' · QR' : '') : 'Transaksi Baru';
    var bd = $('#pos-order-badges'); bd.textContent = '';
    if (o) {
      bd.appendChild(badge(o.status_label, { DRAFT: 'text-bg-secondary', NEW: 'text-bg-danger', PROCESSING: 'text-bg-warning', COMPLETED: 'text-bg-success', CANCELLED: 'text-bg-dark' }[o.status]));
      bd.appendChild(badge(o.payment_label, o.payment_status === 'PAID' ? 'text-bg-success' : (o.payment_status === 'UNPAID' ? 'text-bg-light border text-dark' : 'text-bg-info')));
    }
    if (S.dirty) bd.appendChild(badge('Belum disimpan', 'text-bg-warning'));

    $all('input[name=otype]').forEach(function (r) { r.checked = r.value === S.type; r.disabled = !editable() || (o && o.status === 'PROCESSING' && r.value !== S.type); });
    $('#btn-table').classList.toggle('d-none', S.type !== 'DINE_IN');
    $('#table-label').textContent = S.table_id ? 'Meja ' + S.table_label : 'Pilih meja';
    $('#btn-delivery').classList.toggle('d-none', S.type !== 'DELIVERY');
    $('#delivery-label').textContent = S.delivery ? S.delivery.customer_name + ' · ' + S.delivery.phone : 'Isi data pengiriman (wajib)';
    var cust = $('#pos-customer'); if (document.activeElement !== cust) cust.value = S.customer; cust.disabled = !editable();
    $('#order-note-label').textContent = S.note ? 'Catatan: ' + S.note : 'Catatan pesanan';

    var box = $('#pos-lines'); box.textContent = '';
    if (!S.lines.length) {
      var em = el('div', 'text-center text-muted py-4 small'); em.appendChild(icon('bi-basket fs-3 d-block mb-1')); em.appendChild(document.createTextNode('Belum ada item. Pilih produk di kiri.'));
      box.appendChild(em);
    }
    S.lines.forEach(function (l) {
      var sent = l.id && l.kitchen_status !== 'PENDING';
      var row = el('div', 'pos-line' + (l.qty === 0 ? ' is-void' : ''));
      var top = el('div', 'd-flex justify-content-between gap-2');
      var nm = el('div', 'min-w-0'); nm.appendChild(el('div', 'fw-semibold text-truncate', l.name));
      var sub = el('div', 'small text-muted'); sub.textContent = rupiah(l.price) + (sent ? ' · ' : ' · ') ;
      sub.appendChild(badge(l.id ? l.kitchen_label || '' : 'Baru', sent ? 'text-bg-light border text-dark' : 'text-bg-warning'));
      nm.appendChild(sub);
      if (l.note) nm.appendChild(el('div', 'small fst-italic text-break', '“' + l.note + '”'));
      var dsc = lineDisc(l.price, l.qty, l.discount_type, l.discount_value);
      if (dsc) nm.appendChild(el('div', 'small text-success', 'Diskon ' + (l.discount_type === 'PERCENT' ? l.discount_value + '%' : rupiah(l.discount_value)) + ' (−' + rupiah(dsc) + ')'));
      top.appendChild(nm);
      top.appendChild(el('div', 'fw-semibold text-nowrap', rupiah(l.price * l.qty - dsc)));
      row.appendChild(top);
      var bot = el('div', 'd-flex justify-content-between align-items-center mt-1');
      var qc = el('div', 'qty-control');
      var m = el('button', 'btn btn-outline-dark btn-qty btn-qty-sm', '−'); m.type = 'button'; m.setAttribute('aria-label', 'Kurangi ' + l.name);
      var pl = el('button', 'btn btn-dark btn-qty btn-qty-sm', '+'); pl.type = 'button'; pl.setAttribute('aria-label', 'Tambah ' + l.name);
      m.disabled = pl.disabled = !editable() || l.qty === 0;
      m.addEventListener('click', function () { changeQty(l, -1); });
      pl.addEventListener('click', function () {
        if (sent) { var p = P[l.product_id]; if (p) addProduct(p); return; }   // tambahan untuk item terkirim = tiket baru
        changeQty(l, 1);
      });
      qc.appendChild(m); qc.appendChild(el('span', 'qty-num', String(l.qty))); qc.appendChild(pl);
      bot.appendChild(qc);
      var tools = el('div', 'd-flex gap-1');
      if (editable() && !sent) {
        var ed = el('button', 'btn btn-sm btn-light border'); ed.type = 'button'; ed.appendChild(icon('bi-pencil')); ed.setAttribute('aria-label', 'Catatan / diskon ' + l.name);
        ed.addEventListener('click', function () { editLine(l); }); tools.appendChild(ed);
      }
      if (editable() && l.qty > 0) {
        var rm = el('button', 'btn btn-sm btn-light border text-danger'); rm.type = 'button'; rm.appendChild(icon('bi-trash')); rm.setAttribute('aria-label', 'Hapus ' + l.name);
        rm.addEventListener('click', function () { changeQty(l, -l.qty); }); tools.appendChild(rm);
      }
      bot.appendChild(tools);
      row.appendChild(bot);
      box.appendChild(row);
    });
    S.lines = S.lines.filter(function (l) { return l.qty > 0 || l.id; });

    var t = totals();
    $('#s-subtotal').textContent = rupiah(t.subtotal);
    var show = function (id, v) { $('#r-' + id).classList.toggle('d-none', !v); };
    show('discount', t.discount); $('#s-discount').textContent = '−' + rupiah(t.discount);
    show('service', t.service); $('#s-srate').textContent = '(' + t.r.service_rate + '%)'; $('#s-service').textContent = rupiah(t.service);
    show('tax', t.tax); $('#s-trate').textContent = '(' + t.r.tax_rate + '%)'; $('#s-tax').textContent = rupiah(t.tax);
    show('delivery', t.delivery); $('#s-delivery').textContent = rupiah(t.delivery);
    show('other', t.other); $('#s-other-label').textContent = S.other_fee_label || 'Biaya lain'; $('#s-other').textContent = rupiah(t.other);
    $('#s-total').textContent = rupiah(t.total);
    show('paid', o && o.paid_amount); if (o) $('#s-paid').textContent = rupiah(o.paid_amount);
    $('#btn-discount').classList.toggle('d-none', !can('pos.discount') || !editable());
    $('#btn-fee').classList.toggle('d-none', !editable());
    $('#btn-order-note').disabled = !editable();

    // tombol aksi
    var isNewOrDraft = !o || o.status === 'DRAFT';
    $('#btn-hold').innerHTML = '';
    $('#btn-hold').appendChild(icon(isNewOrDraft ? 'bi-pause-circle' : 'bi-floppy'));
    $('#btn-hold').appendChild(document.createTextNode(isNewOrDraft ? ' Hold' : ' Simpan'));
    $('#btn-hold').disabled = busy || !editable() || !S.lines.length || (!isNewOrDraft && !S.dirty);
    $('#send-label').textContent = o && o.status === 'NEW' ? 'Terima' : 'Ke Dapur';
    $('#btn-send').disabled = busy || !editable() || !S.lines.length || (!hasPending() && !S.dirty && o && o.status === 'PROCESSING');
    $('#btn-pay').disabled = busy || !S.lines.length || !can('payment.take') || (o && ['COMPLETED', 'CANCELLED'].indexOf(o.status) !== -1);
    $('#btn-pay').classList.toggle('d-none', !can('payment.take'));
    $all('#more-menu [data-act]').forEach(function (b) {
      var a = b.getAttribute('data-act');
      var ok = ACTIONS[a] && ACTIONS[a].visible();
      b.closest('li').classList.toggle('d-none', !ok);
    });

    $('#fab-count').textContent = itemCount();
    $('#fab-total').textContent = rupiah(t.total);
    renderGrid();
  }
  function render() { renderOrder(); }

  /* ---------- simpan ---------- */
  function payload(action, extra) {
    var p = {
      order_id: S.order ? S.order.id : null, version: S.order ? S.order.version : 0, token: S.token, action: action,
      order_type: S.type, table_id: S.type === 'DINE_IN' ? S.table_id : null, customer_name: S.customer, note: S.note,
      discount: S.discount, other_fee: S.other_fee, other_fee_label: S.other_fee_label, delivery_fee: S.delivery_fee,
      delivery: S.type === 'DELIVERY' ? S.delivery : null,
      lines: S.lines.map(function (l) { return { id: l.id, product_id: l.product_id, qty: l.qty, note: l.note, discount_type: l.discount_type, discount_value: l.discount_value }; })
    };
    if (extra) Object.keys(extra).forEach(function (k) { p[k] = extra[k]; });
    return p;
  }
  function validateLocal() {
    if (!S.lines.length) { toast('Tambahkan minimal satu menu.', 'warning'); return false; }
    if (S.type === 'DINE_IN' && !S.table_id) { toast('Pilih meja untuk pesanan dine-in.', 'warning'); openTables(); return false; }
    if (S.type === 'DELIVERY' && !S.delivery) { toast('Isi data pengiriman terlebih dahulu.', 'warning'); editDelivery(); return false; }
    return true;
  }
  function save(action, extra) {
    if (busy || !validateLocal()) return Promise.resolve(null);
    busy = true; render();
    return api('pos-save.php', payload(action, extra)).then(function (j) {
      busy = false;
      if (j.ok) {
        applyOrder(j.order);
        toast(action === 'hold' ? 'Transaksi ditahan: ' + j.order.order_number : (action === 'send' ? 'Terkirim ke dapur: ' + j.order.order_number : 'Tersimpan: ' + j.order.order_number), 'success');
        refreshQueue();
        return j.order;
      }
      render();
      if (j.code === 'TABLE_BUSY') { tableBusy(j.orders, action); return null; }
      if (j.code === 'VOID_REASON') {
        askText('Alasan pembatalan item', 'Contoh: salah input, pelanggan batal', function (reason) { save(action, Object.assign({}, extra || {}, { void_reason: reason })); });
        return null;
      }
      if (j.code === 'NEED_AUTH') {
        askAuth(j.error, function (auth) { save(action, Object.assign({}, extra || {}, { auth: auth })); });
        return null;
      }
      if (j.code === 'CONFLICT') {
        toast(j.error, 'danger');
        if (S.order) loadOrder(S.order.id, true);
        return null;
      }
      toast(j.error || 'Gagal menyimpan.', 'danger');
      return null;
    }).catch(function (e) { busy = false; render(); toast('Koneksi bermasalah: ' + e.message, 'danger'); return null; });
  }
  function applyOrder(o) {
    var keepToken = S.token;
    S = blank();
    S.token = keepToken;
    S.order = o; S.type = o.order_type; S.table_id = o.table_id; S.table_label = o.table_number || '';
    S.customer = o.customer_name || ''; S.note = o.note || '';
    S.discount = { type: o.discount_type, value: o.discount_value };
    S.other_fee = o.other_fee; S.other_fee_label = o.other_fee_label || ''; S.delivery_fee = o.delivery_fee;
    S.delivery = o.delivery ? { customer_name: o.delivery.customer_name, phone: o.delivery.phone, address: o.delivery.address,
      landmark: o.delivery.landmark || '', delivery_note: o.delivery.delivery_note || '', source: o.delivery.source } : null;
    o.items.forEach(function (i) {
      if (i.kitchen_status === 'CANCELLED') return;
      S.lines.push({ key: ++lineKey, id: i.id, product_id: i.product_id, name: i.name, price: i.price, qty: i.qty, note: i.note,
        discount_type: i.discount_type, discount_value: i.discount_value, kitchen_status: i.kitchen_status, kitchen_label: i.kitchen_label, orig_qty: i.qty });
    });
    S.dirty = false;
    history.replaceState(null, '', 'pos.php?order=' + o.id);
    render();
  }
  function confirmDiscard() { return !S.dirty || confirm('Perubahan pada transaksi ini belum disimpan. Buang perubahan?'); }
  function loadOrder(id, force) {
    if (!force && !confirmDiscard()) return Promise.resolve();
    return api('pos-orders.php?id=' + id).then(function (j) {
      if (!j.ok) { toast(j.error, 'danger'); return; }
      S.dirty = false; applyOrder(j.order);
      var qp = bootstrap.Offcanvas.getInstance($('#queuePanel')); if (qp) qp.hide();
    });
  }
  function newOrder() {
    if (!confirmDiscard()) return;
    S = blank(); history.replaceState(null, '', 'pos.php'); render();
  }

  /* ---------- dialog umum ---------- */
  var fm, fmSubmit = null, fmHiding = false;
  $('#formModal').addEventListener('hide.bs.modal', function () { fmHiding = true; });
  $('#formModal').addEventListener('hidden.bs.modal', function () { fmHiding = false; });
  function dialog(title, buildBody, buttons, onSubmit) {
    // Modal masih beranimasi menutup: Bootstrap mengabaikan show() -> tunggu sampai benar-benar tertutup
    if (fmHiding) {
      $('#formModal').addEventListener('hidden.bs.modal', function once() { $('#formModal').removeEventListener('hidden.bs.modal', once); dialog(title, buildBody, buttons, onSubmit); });
      return;
    }
    fm = fm || new bootstrap.Modal($('#formModal'));
    $('#fm-title').textContent = title;
    var b = $('#fm-body'); b.textContent = ''; buildBody(b);
    var f = $('#fm-foot'); f.textContent = '';
    buttons.forEach(function (bt) {
      var x = el('button', 'btn ' + (bt.cls || 'btn-outline-secondary'), bt.text); x.type = bt.submit ? 'submit' : 'button';
      if (bt.dismiss) x.setAttribute('data-bs-dismiss', 'modal');
      if (bt.click) x.addEventListener('click', function () { fm.hide(); bt.click(); });
      f.appendChild(x);
    });
    fmSubmit = onSubmit || null;
    fm.show();
    setTimeout(function () { var i = $('#fm-body input, #fm-body textarea, #fm-body select'); if (i) i.focus(); }, 350);
  }
  $('#fm-form').addEventListener('submit', function (e) {
    e.preventDefault();
    if (fmSubmit && fmSubmit() !== false) fm.hide();
  });
  function field(parent, label, input) {
    var w = el('div', 'mb-3'); var lb = el('label', 'form-label small fw-semibold', label);
    input.id = input.id || 'f' + Math.random().toString(36).slice(2); lb.htmlFor = input.id;
    w.appendChild(lb); w.appendChild(input); parent.appendChild(w); return input;
  }
  function inp(type, value, attrs) {
    var i = el(type === 'textarea' ? 'textarea' : 'input', 'form-control'); if (type !== 'textarea') i.type = type;
    i.value = value === undefined || value === null ? '' : value;
    Object.keys(attrs || {}).forEach(function (k) { i.setAttribute(k, attrs[k]); }); return i;
  }
  function discountFields(parent, t, v) {
    var row = el('div', 'input-group');
    var sel = el('select', 'form-select'); sel.style.maxWidth = '140px'; sel.setAttribute('aria-label', 'Jenis diskon');
    [['NONE', 'Tanpa'], ['PERCENT', '%'], ['AMOUNT', 'Rp']].forEach(function (o) { var op = el('option', '', o[1]); op.value = o[0]; sel.appendChild(op); });
    sel.value = t; var val = inp('number', v || '', { min: 0, step: 1, 'aria-label': 'Nilai diskon' });
    row.appendChild(sel); row.appendChild(val);
    var w = el('div', 'mb-3'); w.appendChild(el('div', 'form-label small fw-semibold', 'Diskon')); w.appendChild(row); parent.appendChild(w);
    return { sel: sel, val: val };
  }
  function readDiscount(f) {
    var t = f.sel.value, v = parseInt(f.val.value, 10) || 0;
    if (t === 'NONE') v = 0;
    if (v < 0 || (t === 'PERCENT' && v > 100)) { toast('Nilai diskon tidak valid.', 'warning'); return null; }
    return { type: v ? t : 'NONE', value: v };
  }
  function askText(title, placeholder, cb) {
    var i;
    dialog(title, function (b) { i = field(b, title, inp('text', '', { maxlength: 255, placeholder: placeholder, required: 'required' })); },
      [{ text: 'Batal', dismiss: true }, { text: 'Lanjut', cls: 'btn-dark', submit: true }],
      function () { var v = i.value.trim(); if (!v) { i.classList.add('is-invalid'); return false; } cb(v); });
  }
  function editLine(l) {
    var note, d;
    dialog(l.name, function (b) {
      note = field(b, 'Catatan item', inp('text', l.note, { maxlength: 255, placeholder: 'Contoh: tidak pedas, tanpa bawang' }));
      if (can('pos.discount')) d = discountFields(b, l.discount_type, l.discount_value);
    }, [{ text: 'Batal', dismiss: true }, { text: 'Simpan', cls: 'btn-dark', submit: true }], function () {
      if (d) { var x = readDiscount(d); if (!x) return false; l.discount_type = x.type; l.discount_value = x.value; }
      l.note = note.value.trim(); S.dirty = true; render();
    });
  }
  $('#btn-discount').addEventListener('click', function () {
    var d;
    dialog('Diskon transaksi', function (b) { d = discountFields(b, S.discount.type, S.discount.value); b.appendChild(el('div', 'small text-muted', 'Diskon dihitung dari subtotal sebelum service & pajak. Tercatat di audit log.')); },
      [{ text: 'Batal', dismiss: true }, { text: 'Terapkan', cls: 'btn-dark', submit: true }],
      function () { var x = readDiscount(d); if (!x) return false; S.discount = x; S.dirty = true; render(); });
  });
  $('#btn-fee').addEventListener('click', function () {
    var lb, am, df;
    dialog('Biaya tambahan', function (b) {
      lb = field(b, 'Keterangan', inp('text', S.other_fee_label, { maxlength: 50, placeholder: 'Contoh: biaya kemasan' }));
      am = field(b, 'Nominal (Rp)', inp('number', S.other_fee || '', { min: 0, step: 500 }));
      if (S.type === 'DELIVERY') df = field(b, 'Ongkos kirim (Rp)', inp('number', S.delivery_fee, { min: 0, step: 500 }));
    }, [{ text: 'Batal', dismiss: true }, { text: 'Simpan', cls: 'btn-dark', submit: true }], function () {
      var a = parseInt(am.value, 10) || 0; if (a < 0) return false;
      S.other_fee = a; S.other_fee_label = lb.value.trim();
      if (df) S.delivery_fee = Math.max(0, parseInt(df.value, 10) || 0);
      S.dirty = true; render();
    });
  });
  $('#btn-order-note').addEventListener('click', function () {
    var n;
    dialog('Catatan pesanan', function (b) { n = field(b, 'Catatan untuk dapur / pelayan', inp('textarea', S.note, { maxlength: 500, rows: 3 })); },
      [{ text: 'Batal', dismiss: true }, { text: 'Simpan', cls: 'btn-dark', submit: true }], function () { S.note = n.value.trim(); S.dirty = true; render(); });
  });
  function editDelivery() {
    var f = {}, d = S.delivery || { customer_name: S.customer, phone: '', address: '', landmark: '', delivery_note: '', source: 'Telepon' };
    dialog('Data pengiriman', function (b) {
      b.appendChild(el('p', 'small text-muted', 'Hanya data yang diperlukan untuk mengantar pesanan.'));
      f.n = field(b, 'Nama pelanggan *', inp('text', d.customer_name, { maxlength: 100, required: 'required', autocomplete: 'off' }));
      f.p = field(b, 'Nomor telepon *', inp('tel', d.phone, { maxlength: 20, required: 'required', pattern: '\\+?[0-9][0-9 ()-]{5,19}', autocomplete: 'off' }));
      f.a = field(b, 'Alamat pengiriman *', inp('textarea', d.address, { maxlength: 255, rows: 2, required: 'required' }));
      f.l = field(b, 'Patokan lokasi', inp('text', d.landmark, { maxlength: 150 }));
      f.dn = field(b, 'Catatan pengiriman', inp('text', d.delivery_note, { maxlength: 255 }));
      var s = el('select', 'form-select'); ['Telepon', 'WhatsApp', 'Website', 'Walk-in', 'Lainnya'].forEach(function (x) { var o = el('option', '', x); o.value = x; s.appendChild(o); });
      s.value = d.source || 'Telepon'; f.s = field(b, 'Sumber pesanan', s);
      f.fee = field(b, 'Ongkos kirim (Rp)', inp('number', S.delivery ? S.delivery_fee : D.deliveryFee, { min: 0, step: 500 }));
    }, [{ text: 'Batal', dismiss: true }, { text: 'Simpan', cls: 'btn-dark', submit: true }], function () {
      var bad = false;
      [f.n, f.p, f.a].forEach(function (i) { var okv = i.value.trim() && (i !== f.p || /^\+?[0-9][0-9 ()-]{5,19}$/.test(i.value.trim())); i.classList.toggle('is-invalid', !okv); if (!okv) bad = true; });
      if (bad) return false;
      S.delivery = { customer_name: f.n.value.trim(), phone: f.p.value.trim(), address: f.a.value.trim(), landmark: f.l.value.trim(), delivery_note: f.dn.value.trim(), source: f.s.value };
      S.delivery_fee = Math.max(0, parseInt(f.fee.value, 10) || 0);
      if (!S.customer) S.customer = S.delivery.customer_name;
      S.dirty = true; render();
    });
  }
  $('#btn-delivery').addEventListener('click', editDelivery);

  /* ---------- tipe pesanan & meja ---------- */
  $all('input[name=otype]').forEach(function (r) {
    r.addEventListener('change', function () {
      S.type = r.value; S.dirty = true;
      if (S.type === 'DELIVERY' && !S.delivery) { S.delivery_fee = D.deliveryFee; editDelivery(); }
      render();
    });
  });
  $('#pos-customer').addEventListener('input', function (e) { S.customer = e.target.value; if (!S.dirty) { S.dirty = true; renderOrder(); } });

  var tm;
  function refreshData() {
    return api('pos-data.php').then(function (j) {
      if (!j.ok) { toast(j.error, 'danger'); return; }
      D.categories = j.categories; D.products = j.products; D.tables = j.tables; D.rates = j.rates; D.permissions = j.permissions;
      D.methods = j.payment_methods; D.shift = j.shift; D.deliveryFee = j.delivery_fee_default; D.tableLabels = j.table_labels;
      P = {}; D.products.forEach(function (p) { P[p.id] = p; });
    });
  }
  function openTables(onPick) {
    tm = tm || new bootstrap.Modal($('#tableModal'));
    refreshData().then(function () {
      var g = $('#table-grid'); g.textContent = '';
      var lg = $('#table-legend'); lg.textContent = '';
      Object.keys(D.tableLabels).forEach(function (k) { lg.appendChild(el('span', 'tstat tstat-' + k, D.tableLabels[k])); });
      D.tables.forEach(function (t) {
        var b = el('button', 'table-card tstat-' + t.status); b.type = 'button';
        b.appendChild(el('span', 'table-no', t.table_number));
        b.appendChild(el('span', 'small', D.tableLabels[t.status]));
        if (t.orders.length) b.appendChild(el('span', 'small fw-semibold', t.orders.map(function (o) { return o.number; }).join(', ')));
        if (S.table_id === t.id) b.classList.add('is-current');
        b.addEventListener('click', function () { tm.hide(); (onPick || pickTable)(t); });
        g.appendChild(b);
      });
      tm.show();
    });
  }
  function pickTable(t) {
    var active = t.orders.filter(function (o) { return ['DRAFT', 'NEW', 'PROCESSING'].indexOf(o.status) !== -1 && (!S.order || o.id !== S.order.id); });
    var setIt = function () { S.table_id = t.id; S.table_label = t.table_number; S.separate = false; S.dirty = true; render(); };
    if (!active.length || (S.order && S.order.status !== 'DRAFT')) {
      if (S.order && S.order.status !== 'DRAFT' && S.order.table_id !== t.id) { toast('Gunakan "Pindah meja" untuk pesanan yang sudah berjalan.', 'warning'); return; }
      setIt(); return;
    }
    tableBusy(active.map(function (o) { return { id: o.id, order_number: o.number, status: o.status, total: o.total }; }), null, t);
  }
  /** Meja sudah ada pesanan aktif: buka pesanan itu (item baru ikut ditambahkan) atau buat transaksi terpisah. */
  function tableBusy(orders, retryAction, t) {
    var pendingLines = S.lines.filter(function (l) { return !l.id; });
    var buttons = orders.map(function (o) {
      return { text: (pendingLines.length ? 'Tambahkan ke ' : 'Buka ') + o.order_number, cls: 'btn-dark', click: function () {
        S.dirty = false;
        loadOrder(o.id, true).then(function () {
          if (!S.order || !S.order.editable) { if (pendingLines.length) toast('Pesanan itu tidak dapat diubah; item tidak ditambahkan.', 'warning'); return; }
          pendingLines.forEach(function (l) { l.key = ++lineKey; S.lines.push(l); });
          if (pendingLines.length) { S.dirty = true; render(); toast('Item ditambahkan ke ' + S.order.order_number + '. Simpan / kirim ke dapur.', 'info'); }
        });
      } };
    });
    buttons.push({ text: 'Transaksi terpisah', cls: 'btn-outline-dark', click: function () {
      if (t) { S.table_id = t.id; S.table_label = t.table_number; }
      S.separate = true; S.dirty = true; render();
      if (retryAction) save(retryAction, { separate: true });
    } });
    buttons.unshift({ text: 'Batal', dismiss: true });
    dialog('Meja sudah memiliki pesanan', function (b) {
      b.appendChild(el('p', '', 'Meja ini sudah memiliki pesanan aktif. Hindari tagihan ganda: buka pesanan yang ada, atau sengaja buat transaksi terpisah.'));
      var ul = el('ul', 'list-group');
      orders.forEach(function (o) { var li = el('li', 'list-group-item d-flex justify-content-between'); li.appendChild(el('span', 'fw-semibold', o.order_number)); li.appendChild(el('span', '', rupiah(o.total))); ul.appendChild(li); });
      b.appendChild(ul);
    }, buttons);
  }
  $('#btn-table').addEventListener('click', function () { openTables(); });

  /* ---------- tombol utama ---------- */
  $('#btn-new').addEventListener('click', newOrder);
  $('#btn-hold').addEventListener('click', function () { save(!S.order || S.order.status === 'DRAFT' ? 'hold' : 'save', S.separate ? { separate: true } : null); });
  $('#btn-send').addEventListener('click', function () { save('send', S.separate ? { separate: true } : null); });
  $('#btn-pay').addEventListener('click', function () {
    if (typeof window.POS_PAY === 'function') window.POS_PAY();
    else toast('Modul pembayaran belum tersedia.', 'secondary');
  });

  /* Aksi menu "lainnya". Phase berikutnya menambah entri lewat window.POS_ACTIONS. */
  var ACTIONS = {
    cancel: {
      visible: function () { return !!S.order && ['DRAFT', 'NEW', 'PROCESSING'].indexOf(S.order.status) !== -1 && S.order.payment_status === 'UNPAID'; },
      run: function () {
        var o = S.order, sent = hasSent();
        var go = function (reason) {
          act({ action: 'cancel', order_id: o.id, version: o.version, reason: reason || '' }).then(function (j) {
            if (!j) return;
            toast('Transaksi ' + o.order_number + ' dibatalkan.', 'success'); S.dirty = false; S = blank(); history.replaceState(null, '', 'pos.php'); render(); refreshQueue();
          });
        };
        if (sent) askText('Alasan pembatalan ' + o.order_number, 'Wajib diisi (tercatat di audit log)', go);
        else if (confirm('Batalkan transaksi ' + o.order_number + '?')) go('');
      }
    }
  };
  /** Panggil pos-action; otomatis meminta otorisasi supervisor bila server membalas NEED_AUTH. */
  function act(data) {
    return api('pos-action.php', data).then(function (j) {
      if (j.ok) return j;
      if (j.code === 'NEED_AUTH') {
        return new Promise(function (res) { askAuth(j.error, function (auth) { res(act(Object.assign({}, data, { auth: auth }))); }); });
      }
      toast(j.error || 'Aksi gagal.', 'danger');
      if (j.code === 'CONFLICT' && S.order) loadOrder(S.order.id, true);
      return null;
    });
  }
  function askAuth(msg, cb) {
    var u, pw;
    dialog('Otorisasi Supervisor', function (b) {
      b.appendChild(el('div', 'alert alert-warning small py-2', msg));
      b.appendChild(el('p', 'small text-muted', 'Minta Manager memasukkan username & password. Tercatat di audit log.'));
      u = field(b, 'Username supervisor', inp('text', '', { autocomplete: 'off', required: 'required' }));
      pw = field(b, 'Password', inp('password', '', { autocomplete: 'new-password', required: 'required' }));
    }, [{ text: 'Batal', dismiss: true }, { text: 'Otorisasi', cls: 'btn-dark', submit: true }], function () {
      if (!u.value || !pw.value) return false;
      var a = { username: u.value.trim(), password: pw.value }; pw.value = '';
      setTimeout(function () { cb(a); }, 50);
    });
  }
  function openReceipt(type, extra) {
    if (!S.order) return;
    window.open('receipt.php?id=' + S.order.id + '&type=' + type + (extra || '') + '&autoprint=1', 'receipt', 'width=420,height=700');
  }
  ACTIONS['print-bill'] = {
    visible: function () { return !!S.order && ['NEW', 'PROCESSING'].indexOf(S.order.status) !== -1 && !S.dirty; },
    run: function () { act({ action: 'print_bill', order_id: S.order.id }).then(function (j) { if (j) { applyOrder(j.order); openReceipt('bill'); } }); }
  };
  ACTIONS['kitchen-ticket'] = {
    visible: function () { return !!S.order && hasSent(); },
    run: function () { openReceipt('kitchen'); }
  };
  ACTIONS['move'] = {
    visible: function () { return !!S.order && S.order.order_type === 'DINE_IN' && ['DRAFT', 'NEW', 'PROCESSING'].indexOf(S.order.status) !== -1 && !S.dirty; },
    run: function () {
      openTables(function (t) {
        if (t.id === S.order.table_id) return;
        act({ action: 'move', order_id: S.order.id, version: S.order.version, table_id: t.id }).then(function (j) {
          if (j) { applyOrder(j.order); toast('Dipindah ke Meja ' + t.table_number, 'success'); }
        });
      });
    }
  };
  ACTIONS['merge'] = {
    visible: function () { return !!S.order && S.order.order_type !== 'DELIVERY' && S.order.payment_status === 'UNPAID' && ['DRAFT', 'NEW', 'PROCESSING'].indexOf(S.order.status) !== -1 && !S.dirty; },
    run: function () {
      var cands = queue.filter(function (o) { return o.id !== S.order.id && o.order_type !== 'DELIVERY' && o.payment_status === 'UNPAID'; });
      if (!cands.length) { toast('Tidak ada tagihan lain yang dapat digabung.', 'secondary'); return; }
      dialog('Gabung ' + S.order.order_number + ' ke…', function (b) {
        b.appendChild(el('p', 'small text-muted', 'Semua item tagihan ini dipindah ke tagihan tujuan. Tagihan ini lalu ditutup (tercatat "digabung").'));
        var lg = el('div', 'list-group');
        cands.forEach(function (o) {
          var x = el('button', 'list-group-item list-group-item-action d-flex justify-content-between'); x.type = 'button';
          x.appendChild(el('span', '', o.number + ' · ' + o.place_label)); x.appendChild(el('span', 'fw-semibold', rupiah(o.total)));
          x.addEventListener('click', function () {
            fm.hide();
            act({ action: 'merge', order_id: S.order.id, target_order_id: o.id }).then(function (j) {
              if (j) { applyOrder(j.order); toast('Tagihan digabung ke ' + j.order.order_number, 'success'); refreshQueue(); }
            });
          });
          lg.appendChild(x);
        });
        b.appendChild(lg);
      }, [{ text: 'Batal', dismiss: true }]);
    }
  };
  ACTIONS['split'] = {
    visible: function () { return !!S.order && S.order.order_type !== 'DELIVERY' && S.order.payment_status === 'UNPAID' && ['DRAFT', 'NEW', 'PROCESSING'].indexOf(S.order.status) !== -1 && !S.dirty && itemCount() > 1; },
    run: function () {
      var inputs = [];
      dialog('Pisah tagihan ' + S.order.order_number, function (b) {
        b.appendChild(el('p', 'small text-muted', 'Pilih jumlah item yang dipindah ke tagihan baru (mis. per pelanggan).'));
        S.lines.forEach(function (l) {
          if (!l.id || !l.qty) return;
          var r = el('div', 'd-flex justify-content-between align-items-center mb-2 gap-2');
          r.appendChild(el('span', 'min-w-0 text-truncate', l.name + ' (' + l.qty + ')'));
          var i = inp('number', 0, { min: 0, max: l.qty, step: 1, 'aria-label': 'Jumlah ' + l.name }); i.style.maxWidth = '90px';
          r.appendChild(i); b.appendChild(r); inputs.push({ id: l.id, el: i, max: l.qty });
        });
      }, [{ text: 'Batal', dismiss: true }, { text: 'Pisahkan', cls: 'btn-dark', submit: true }], function () {
        var items = inputs.map(function (x) { return { item_id: x.id, qty: Math.min(x.max, Math.max(0, parseInt(x.el.value, 10) || 0)) }; }).filter(function (x) { return x.qty > 0; });
        if (!items.length) { toast('Pilih minimal satu item.', 'warning'); return false; }
        act({ action: 'split', order_id: S.order.id, version: S.order.version, items: items }).then(function (j) {
          if (j) { applyOrder(j.order); toast('Tagihan baru: ' + j.new_order.order_number + ' (' + rupiah(j.new_order.total) + ')', 'success'); refreshQueue(); }
        });
      });
    }
  };
  ACTIONS['receipt'] = {
    visible: function () { return !!S.order && S.order.payments && S.order.payments.length > 0; },
    run: function () { openReceipt('customer'); }
  };
  ACTIONS['refund'] = {
    visible: function () { return !!S.order && ['PAID', 'PARTIAL'].indexOf(S.order.payment_status) !== -1; },
    run: function () {
      var o = S.order;
      askText('Alasan refund ' + o.order_number + ' (' + rupiah(o.paid_amount) + ')', 'Wajib diisi, tercatat di audit log', function (reason) {
        act({ action: 'refund', order_id: o.id, reason: reason }).then(function (j) {
          if (j) { applyOrder(j.order); toast('Refund ' + rupiah(j.refunded) + ' tercatat.', 'success'); }
        });
      });
    }
  };

  /* ---------- Pembayaran ---------- */
  function payDialog(o) {
    if (!D.shift) {
      dialog('Shift belum dibuka', function (b) { b.appendChild(el('p', '', 'Buka shift kasir (isi modal awal) sebelum menerima pembayaran.')); },
        [{ text: 'Tutup', dismiss: true }, { text: 'Buka Shift', cls: 'btn-dark', click: function () { location.href = 'shift.php?return=' + encodeURIComponent('pos.php?order=' + o.id); } }]);
      return;
    }
    var idem = tok(), method = D.methods[0], amt, ten, ref, chg, refWrap, tenWrap;
    var remaining = o.total - o.paid_amount;
    dialog('Pembayaran ' + o.order_number, function (b) {
      var sum = el('div', 'pay-sum');
      sum.appendChild(el('div', 'small text-muted', 'Sisa tagihan'));
      sum.appendChild(el('div', 'pay-total', rupiah(remaining)));
      if (o.paid_amount) sum.appendChild(el('div', 'small text-success', 'Sudah dibayar ' + rupiah(o.paid_amount) + ' dari ' + rupiah(o.total)));
      b.appendChild(sum);
      var mg = el('div', 'pay-methods'); mg.setAttribute('role', 'radiogroup'); mg.setAttribute('aria-label', 'Metode pembayaran');
      D.methods.forEach(function (m, i) {
        var x = el('button', 'btn ' + (i === 0 ? 'btn-dark' : 'btn-outline-dark'), m.name); x.type = 'button'; x.setAttribute('data-code', m.code);
        x.addEventListener('click', function () { method = m; $all('.pay-methods .btn').forEach(function (y) { y.className = 'btn ' + (y === x ? 'btn-dark' : 'btn-outline-dark'); }); sync(); });
        mg.appendChild(x);
      });
      b.appendChild(mg);
      amt = field(b, 'Nominal dibayar (Rp)', inp('number', remaining, { min: 1, max: remaining, step: 1, required: 'required', inputmode: 'numeric' }));
      tenWrap = el('div');
      ten = field(tenWrap, 'Uang diterima (Rp)', inp('number', '', { min: 0, step: 1, inputmode: 'numeric', placeholder: 'Kosongkan = uang pas' }));
      var quick = el('div', 'd-flex flex-wrap gap-1 mb-2');
      var up = function (n) { return Math.ceil(remaining / n) * n; };
      [['Uang pas', remaining], [rupiah(up(10000)), up(10000)], [rupiah(up(50000)), up(50000)], [rupiah(up(100000)), up(100000)]].forEach(function (qv, i, arr) {
        if (i > 0 && qv[1] === arr[i - 1][1]) return;
        var x = el('button', 'btn btn-sm btn-outline-secondary', qv[0]); x.type = 'button';
        x.addEventListener('click', function () { ten.value = qv[1]; sync(); }); quick.appendChild(x);
      });
      tenWrap.appendChild(quick);
      chg = el('div', 'pay-change'); tenWrap.appendChild(chg);
      b.appendChild(tenWrap);
      refWrap = el('div');
      ref = field(refWrap, 'No. referensi / approval', inp('text', '', { maxlength: 100, autocomplete: 'off', placeholder: 'Bukan nomor kartu' }));
      refWrap.appendChild(el('div', 'small text-muted mb-2', 'Dicatat manual — sistem belum terhubung ke payment gateway. Jangan simpan nomor kartu.'));
      b.appendChild(refWrap);
      amt.addEventListener('input', sync); ten.addEventListener('input', sync);
      sync();
    }, [{ text: 'Batal', dismiss: true }, { text: 'Simpan Pembayaran', cls: 'btn-accent', submit: true }], function () {
      var a = parseInt(amt.value, 10) || 0, t = parseInt(ten.value, 10) || 0;
      if (a < 1 || a > remaining) { amt.classList.add('is-invalid'); return false; }
      if (method.type === 'CASH' && t && t < a) { ten.classList.add('is-invalid'); return false; }
      if (method.type !== 'CASH' && Number(method.requires_reference) && !ref.value.trim()) { ref.classList.add('is-invalid'); return false; }
      var btn = $('#fm-foot .btn-accent'); if (btn) btn.disabled = true;
      api('pos-pay.php', { order_id: o.id, method: method.code, amount: a, tendered: method.type === 'CASH' ? (t || a) : a, reference: ref.value.trim(), idem_key: idem }).then(function (j) {
        if (!j.ok) {
          if (j.code === 'NO_SHIFT') { D.shift = null; }
          if (btn) btn.disabled = false;
          toast(j.error, 'danger'); if (j.order) applyOrder(j.order); return;
        }
        applyOrder(j.order); refreshQueue();
        if (j.paid_full) paidDialog(j.order, j.change);
        else { toast('Pembayaran sebagian tercatat. Sisa ' + rupiah(j.order.remaining), 'info'); payDialog(j.order); }
      }).catch(function () { toast('Koneksi bermasalah. Coba simpan lagi — pembayaran tidak akan tercatat ganda.', 'danger'); if (btn) btn.disabled = false; });
      return false;   // dialog ditutup setelah respons (pembayaran berikutnya membuka dialog baru)
    });
    function sync() {
      var cash = method.type === 'CASH';
      tenWrap.classList.toggle('d-none', !cash);
      refWrap.classList.toggle('d-none', cash);
      ref.required = !cash && Number(method.requires_reference) === 1;
      var a = parseInt(amt.value, 10) || 0, t = parseInt(ten.value, 10) || a;
      chg.textContent = t >= a ? 'Kembalian: ' + rupiah(t - a) : 'Uang kurang ' + rupiah(a - t);
      chg.classList.toggle('text-danger', t < a);
    }
  }
  function paidDialog(o, change) {
    dialog('Pembayaran Lunas', function (b) {
      var w = el('div', 'text-center py-2');
      w.appendChild(icon('bi-check-circle-fill text-success display-4'));
      w.appendChild(el('div', 'fs-5 fw-bold mt-2', o.order_number + ' lunas'));
      w.appendChild(el('div', 'pay-change-big', 'Kembalian ' + rupiah(change)));
      b.appendChild(w);
    }, [{ text: 'Cetak Struk', cls: 'btn-outline-dark', click: function () { openReceipt('customer'); } },
        { text: 'Transaksi Baru', cls: 'btn-dark', click: function () { window.POS.newOrder(); } }]);
  }
  window.POS_PAY = function () {
    var go = function (o) { if (o) payDialog(o); };
    if (!S.order || S.dirty) save('send', S.separate ? { separate: true } : null).then(go);
    else go(S.order);
  };
  window.POS_ACTIONS = ACTIONS;
  $all('#more-menu [data-act]').forEach(function (b) {
    b.addEventListener('click', function () { var a = ACTIONS[b.getAttribute('data-act')]; if (a) a.run(); });
  });

  /* ---------- antrean (polling) ---------- */
  var qTab = 'NEW', lastMax = null, queue = [];
  function renderQueue() {
    var counts = { NEW: 0, DRAFT: 0, PROCESSING: 0 };
    queue.forEach(function (o) { counts[o.status]++; });
    Object.keys(counts).forEach(function (k) { $('#qc-' + k).textContent = counts[k]; });
    var b = $('#queue-badge'); b.textContent = counts.NEW; b.classList.toggle('d-none', !counts.NEW);
    var list = $('#queue-list'); list.textContent = '';
    var items = queue.filter(function (o) { return o.status === qTab; });
    if (!items.length) list.appendChild(el('div', 'text-muted small text-center py-4', 'Tidak ada pesanan.'));
    items.forEach(function (o) {
      var a = el('button', 'list-group-item list-group-item-action'); a.type = 'button';
      var t = el('div', 'd-flex justify-content-between'); t.appendChild(el('span', 'fw-semibold', o.number + (o.source === 'QR' ? ' · QR' : ''))); t.appendChild(el('span', 'small text-muted', o.time));
      var m = el('div', 'd-flex justify-content-between small');
      m.appendChild(el('span', '', o.place_label + ' · ' + o.qty + ' item' + (o.customer_name ? ' · ' + o.customer_name : '')));
      m.appendChild(el('span', 'fw-semibold', rupiah(o.remaining)));
      a.appendChild(t); a.appendChild(m);
      if (o.payment_status !== 'UNPAID') a.appendChild(el('span', 'badge text-bg-info', o.payment_label));
      a.addEventListener('click', function () { loadOrder(o.id); });
      list.appendChild(a);
    });
  }
  function refreshQueue() {
    return api('pos-orders.php').then(function (j) {
      if (!j.ok) return;
      queue = j.orders;
      if (lastMax !== null) {
        var fresh = queue.filter(function (o) { return o.id > lastMax && o.status === 'NEW' && o.source === 'QR'; });
        fresh.forEach(function (o) { toast('Pesanan QR baru: ' + o.number + ' (' + o.place_label + ')', 'danger'); });
      }
      lastMax = j.max_id;
      renderQueue();
    }).catch(function () {});
  }
  $all('#queue-tabs [data-q]').forEach(function (b) {
    b.addEventListener('click', function () {
      qTab = b.getAttribute('data-q');
      $all('#queue-tabs .nav-link').forEach(function (x) { x.classList.toggle('active', x === b); });
      renderQueue();
    });
  });
  function poll() { refreshQueue().then(function () { setTimeout(poll, 5000); }); }

  /* ---------- pencarian ---------- */
  $('#pos-search').addEventListener('input', function (e) { q = e.target.value; renderGrid(); });
  $('#pos-search').addEventListener('keydown', function (e) {
    if (e.key !== 'Enter') return;
    var first = $('#pos-grid .pos-prod:not(.is-off)'); if (first) { e.preventDefault(); first.click(); e.target.select(); }
  });

  window.addEventListener('beforeunload', function (e) { if (S.dirty) { e.preventDefault(); e.returnValue = ''; } });

  /* ---------- API untuk modul lain (pembayaran, meja, dsb.) ---------- */
  window.POS = { S: function () { return S; }, D: D, api: api, toast: toast, render: render, applyOrder: applyOrder, save: save,
                 loadOrder: loadOrder, newOrder: function () { S.dirty = false; S = blank(); history.replaceState(null, '', 'pos.php'); render(); },
                 dialog: dialog, field: field, inp: inp, el: el, icon: icon, rupiah: rupiah, can: can, openTables: openTables,
                 askText: askText, refreshQueue: refreshQueue, refreshData: refreshData, totals: totals, tok: tok };

  // Layar menengah: sidebar otomatis ringkas agar area produk cukup lebar (tidak disimpan ke preferensi)
  if (window.innerWidth >= 992 && window.innerWidth < 1280) document.documentElement.classList.add('sb-collapsed');

  refreshData().then(function () {
    renderCats(); render(); poll();
    var oid = parseInt(body.getAttribute('data-open-order'), 10);
    var tid = parseInt(body.getAttribute('data-open-table'), 10);
    if (oid) loadOrder(oid, true);
    else if (tid) { var t = D.tables.find(function (x) { return x.id === tid; }); if (t) pickTable(t); }
    $('#pos-search').focus();
  });
})();
