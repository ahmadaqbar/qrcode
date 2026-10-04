/* Restaurant QR Ordering - satu file JS, diinisialisasi per halaman lewat <body data-page="..."> */
(function () {
  'use strict';

  var body = document.body;
  var PAGE = body.getAttribute('data-page');
  var BASE = body.getAttribute('data-base') || '';
  var TABLE = body.getAttribute('data-table') || '';

  /* ---------- util ---------- */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function rupiah(n) { return 'Rp' + String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text; // textContent => aman dari XSS
    return n;
  }
  function randomToken() {
    var a = new Uint8Array(16);
    (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  function lsGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
  function lsSet(k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* abaikan */ } }
  function ssGet(k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } }
  function ssSet(k, v) { try { window.sessionStorage.setItem(k, v); } catch (e) { /* abaikan */ } }
  function ssDel(k) { try { window.sessionStorage.removeItem(k); } catch (e) { /* abaikan */ } }
  var FLYER_KEY = 'new_menu_popup_seen';
  function lsDel(k) { try { window.localStorage.removeItem(k); } catch (e) { /* abaikan */ } }

  /* ---------- Cart (customer, disimpan di localStorage per meja) ---------- */
  var Cart = {
    key: function () { return 'cart_' + TABLE; },
    data: null,
    load: function () {
      if (this.data) return this.data;
      var d = null;
      try { d = JSON.parse(lsGet(this.key())); } catch (e) { d = null; }
      if (!d || typeof d !== 'object' || typeof d.items !== 'object' || d.items === null) d = { items: {}, note: '', name: '', token: null };
      this.data = d;
      return d;
    },
    save: function () { lsSet(this.key(), JSON.stringify(this.data)); },
    qty: function (id) { var i = this.load().items[id]; return i ? i.qty : 0; },
    set: function (id, name, price, qty) {
      var d = this.load();
      qty = Math.max(0, Math.min(99, qty));
      if (qty === 0) delete d.items[id]; else d.items[id] = { id: id, name: name, price: price, qty: qty };
      d.token = null; // isi pesanan berubah => order baru
      this.save();
    },
    list: function () {
      var d = this.load();
      return Object.keys(d.items).map(function (k) { return d.items[k]; });
    },
    count: function () { return this.list().reduce(function (s, i) { return s + i.qty; }, 0); },
    total: function () { return this.list().reduce(function (s, i) { return s + i.qty * i.price; }, 0); },
    clear: function () { this.data = null; lsDel(this.key()); ssDel(FLYER_KEY); }
  };

  /* ---------- Halaman: menu ---------- */
  function initMenu() {
    var bar = $('#cart-bar');
    var PD = null; // detail produk (diisi di bawah)
    bar.addEventListener('click', function (ev) { if (Cart.count() === 0) ev.preventDefault(); });
    function refresh() {
      $all('.menu-card').forEach(function (card) {
        var box = $('[data-qty-control]', card);
        if (!box) return;
        var id = card.getAttribute('data-id');
        var q = Cart.qty(id);
        box.textContent = '';
        if (q === 0) {
          var add = el('button', 'btn btn-dark btn-qty', '+');
          add.type = 'button'; add.setAttribute('aria-label', 'Tambah');
          add.addEventListener('click', function () { change(card, 1); });
          box.appendChild(add);
        } else {
          var minus = el('button', 'btn btn-outline-dark btn-qty', '−');
          minus.type = 'button'; minus.setAttribute('aria-label', 'Kurangi');
          minus.addEventListener('click', function () { change(card, -1); });
          var plus = el('button', 'btn btn-dark btn-qty', '+');
          plus.type = 'button'; plus.setAttribute('aria-label', 'Tambah');
          plus.addEventListener('click', function () { change(card, 1); });
          box.appendChild(minus); box.appendChild(el('span', 'qty-num', String(q))); box.appendChild(plus);
        }
      });
      var c = Cart.count();
      bar.classList.toggle('is-empty', c === 0);
      bar.setAttribute('aria-disabled', c === 0 ? 'true' : 'false');
      $('#cart-label').textContent = c === 0 ? 'Pesanan kosong' : 'Lihat Pesanan';
      $('#cart-count').textContent = c;
      $('#cart-total').textContent = c === 0 ? '' : rupiah(Cart.total());
      if (PD) PD.update();
    }
    function change(card, delta) {
      var id = card.getAttribute('data-id');
      Cart.set(id, card.getAttribute('data-name'), parseInt(card.getAttribute('data-price'), 10), Cart.qty(id) + delta);
      refresh();
    }
    $all('.cat-tabs button').forEach(function (b) {
      b.addEventListener('click', function () {
        var cat = b.getAttribute('data-cat');
        $all('.cat-tabs button').forEach(function (x) {
          var on = x === b;
          x.classList.toggle('btn-dark', on); x.classList.toggle('btn-outline-dark', !on);
          x.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        $all('.menu-item').forEach(function (m) {
          m.classList.toggle('d-none', cat !== 'all' && m.getAttribute('data-cat') !== cat);
        });
        var menu = $('#menu');
        if (menu && menu.scrollIntoView) menu.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
    PD = initProductDetail(function () { refresh(); });
    refresh();
    initFlyer();
    initOpenProduct(PD);
  }

  /* Daftar id produk sesuai konteks klik: top seller / menu yang sedang tampil / tunggal (promo, flyer). */
  function contextList(el, id) {
    var top = el.closest('#top-seller');
    if (top) return $all('.top-card', top).map(function (c) { return parseInt(c.getAttribute('data-id'), 10); });
    if (el.closest('#menu')) {
      return $all('.menu-item').filter(function (m) { return !m.classList.contains('d-none'); })
        .map(function (m) { return parseInt($('.menu-card', m).getAttribute('data-id'), 10); });
    }
    return [id];
  }

  /* Klik gambar/nama produk, banner promo, atau flyer -> detail produk. */
  function initOpenProduct(pd) {
    if (!pd) return;
    function go(el) {
      var id = parseInt(el.getAttribute('data-open'), 10);
      if (!id) return;
      var fm = el.closest('#flyer-modal');
      if (fm) { // dua modal tidak boleh bertumpuk: tutup flyer dulu
        fm.addEventListener('hidden.bs.modal', function once() { fm.removeEventListener('hidden.bs.modal', once); pd.open(id, [id]); });
        window.bootstrap.Modal.getInstance(fm).hide();
        return;
      }
      pd.open(id, contextList(el, id));
    }
    document.addEventListener('click', function (ev) {
      var t = ev.target.closest ? ev.target.closest('.open-product') : null;
      if (t && t.getAttribute('data-open')) go(t);
      else if (t && t.classList.contains('flyer-img')) { var m = $('#flyer-modal'); var id = m && m.getAttribute('data-product'); if (id && id !== '0') { t.setAttribute('data-open', id); go(t); } }
    });
    document.addEventListener('keydown', function (ev) {
      if ((ev.key === 'Enter' || ev.key === ' ') && ev.target.classList && ev.target.classList.contains('open-product')) { ev.preventDefault(); ev.target.click(); }
    });
  }

  /* Modal detail produk. Data dari <script id="products-data"> (tanpa request tambahan -> navigasi instan). */
  function initProductDetail(onChange) {
    var dataEl = $('#products-data'), modalEl = $('#product-modal');
    if (!dataEl || !modalEl || !window.bootstrap) return null;
    var map = {};
    try { JSON.parse(dataEl.textContent).forEach(function (p) { map[p.id] = p; }); } catch (e) { return null; }
    var modal = new window.bootstrap.Modal(modalEl);
    var st = { list: [], i: 0 }, flashTimer = null;
    var add = $('#pd-add'), minus = $('#pd-minus'), prev = $('#pd-prev'), next = $('#pd-next');

    function cur() { return map[st.list[st.i]]; }
    function foot() {
      var p = cur(); if (!p) return;
      var q = Cart.qty(String(p.id));
      add.disabled = !p.orderable || q >= 99;
      add.textContent = p.orderable ? '+ Tambah Pesanan' : 'Tidak tersedia';
      $('#pd-note').classList.toggle('d-none', p.orderable);
      minus.classList.toggle('d-none', q === 0);
      $('#pd-inorder').textContent = q > 0 ? 'Di pesanan: ' + q + ' \u00B7 Total pesanan ' + rupiah(Cart.total()) : '';
    }
    function render() {
      var p = cur(); if (!p) return;
      var img = $('#pd-img'); img.src = BASE + 'assets/images/' + p.image; img.alt = p.name;
      $('#pd-name').textContent = p.name;
      $('#pd-price').textContent = p.price_text;
      var d = $('#pd-desc'); d.textContent = p.desc; d.classList.toggle('d-none', !p.desc);
      var multi = st.list.length > 1;
      prev.classList.toggle('d-none', !multi); next.classList.toggle('d-none', !multi);
      prev.disabled = st.i === 0; next.disabled = st.i === st.list.length - 1;
      $('#pd-count').textContent = multi ? (st.i + 1) + ' / ' + st.list.length : '';
      $('#pd-count').classList.toggle('d-none', !multi);
      foot();
    }
    function go(delta) {
      var n = st.i + delta;
      if (n < 0 || n >= st.list.length) return;
      st.i = n; render();
    }
    prev.addEventListener('click', function () { go(-1); });
    next.addEventListener('click', function () { go(1); });
    add.addEventListener('click', function () {
      var p = cur(); if (!p || !p.orderable) return;
      Cart.set(String(p.id), p.name, p.price, Cart.qty(String(p.id)) + 1);
      onChange(); // refresh() -> update() -> foot()
      add.textContent = '\u2713 Ditambahkan';
      clearTimeout(flashTimer); flashTimer = setTimeout(foot, 900);
    });
    minus.addEventListener('click', function () {
      var p = cur(); if (!p) return;
      Cart.set(String(p.id), p.name, p.price, Cart.qty(String(p.id)) - 1);
      onChange();
    });
    // swipe kiri/kanan pada gambar & tombol panah keyboard
    var x0 = null, media = $('#pd-media');
    media.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    media.addEventListener('touchend', function (e) {
      if (x0 === null) return;
      var dx = e.changedTouches[0].clientX - x0; x0 = null;
      if (Math.abs(dx) > 50) go(dx < 0 ? 1 : -1);
    }, { passive: true });
    modalEl.addEventListener('keydown', function (e) { if (e.key === 'ArrowLeft') go(-1); else if (e.key === 'ArrowRight') go(1); });

    return {
      open: function (id, ids) {
        if (!map[id]) return;
        var list = (ids || []).filter(function (x) { return map[x]; });
        if (list.indexOf(id) === -1) list = [id];
        st.list = list; st.i = list.indexOf(id);
        render(); modal.show();
      },
      update: function () { if (modalEl.classList.contains('show')) foot(); }
    };
  }

  /* Popup flyer: sekali per sesi tab (sessionStorage). Flyer baru (id berbeda) tampil lagi. */
  function initFlyer() {
    var fm = $('#flyer-modal');
    if (!fm || !window.bootstrap) return;
    var id = fm.getAttribute('data-flyer-id');
    if (ssGet(FLYER_KEY) === id) return;
    ssSet(FLYER_KEY, id); // tandai saat tampil, jadi refresh tidak mengulang
    new window.bootstrap.Modal(fm).show(); // klik area luar = tutup (backdrop default)
  }

  /* ---------- Halaman: cart (+ catatan) ---------- */
  function initCart() {
    var step = 1;
    var list = $('#cart-list');
    var footer = $('#cart-footer');
    var next = $('#btn-next');

    function render() {
      var items = Cart.list();
      list.textContent = '';
      $('#cart-empty').classList.toggle('d-none', items.length > 0);
      footer.classList.toggle('d-none', items.length === 0);
      items.forEach(function (it) {
        var row = el('div', 'list-group-item');
        var top = el('div', 'd-flex justify-content-between');
        top.appendChild(el('div', 'fw-semibold', it.name));
        var del = el('button', 'btn btn-sm btn-link text-danger p-0', 'Hapus');
        del.type = 'button';
        del.addEventListener('click', function () { Cart.set(it.id, it.name, it.price, 0); render(); });
        top.appendChild(del);
        row.appendChild(top);
        var bottom = el('div', 'd-flex justify-content-between align-items-center mt-2');
        bottom.appendChild(el('div', 'text-muted small', it.qty + ' x ' + rupiah(it.price) + ' = ' + rupiah(it.qty * it.price)));
        var ctl = el('div', 'qty-control');
        var m = el('button', 'btn btn-outline-dark btn-qty', '−'); m.type = 'button';
        m.addEventListener('click', function () { Cart.set(it.id, it.name, it.price, it.qty - 1); render(); });
        var p = el('button', 'btn btn-dark btn-qty', '+'); p.type = 'button';
        p.addEventListener('click', function () { Cart.set(it.id, it.name, it.price, it.qty + 1); render(); });
        ctl.appendChild(m); ctl.appendChild(el('span', 'qty-num', String(it.qty))); ctl.appendChild(p);
        bottom.appendChild(ctl);
        row.appendChild(bottom);
        list.appendChild(row);
      });
      $('#cart-total').textContent = rupiah(Cart.total());
    }

    function goStep(n) {
      step = n;
      $('#step-cart').classList.toggle('d-none', n !== 1);
      $('#step-note').classList.toggle('d-none', n !== 2);
      $('#step-title').textContent = n === 1 ? 'Daftar Pesanan' : 'Catatan Pesanan';
    }

    var d = Cart.load();
    $('#note').value = d.note || '';
    $('#cust-name').value = d.name || '';

    next.addEventListener('click', function () {
      if (Cart.count() === 0) return;
      if (step === 1) { goStep(2); window.scrollTo(0, 0); return; }
      var dd = Cart.load();
      dd.note = $('#note').value.trim();
      dd.name = $('#cust-name').value.trim();
      Cart.save();
      window.location.href = 'checkout.php?table=' + encodeURIComponent(TABLE);
    });
    $('#back-link').addEventListener('click', function (ev) {
      if (step === 2) { ev.preventDefault(); goStep(1); }
    });
    render();
  }

  /* ---------- Halaman: checkout ---------- */
  function initCheckout() {
    var d = Cart.load();
    var items = Cart.list();
    var btn = $('#btn-order');
    var err = $('#c-error');

    if (items.length === 0) {
      btn.disabled = true;
      err.textContent = 'Pesanan masih kosong. Silakan pilih menu terlebih dahulu.';
      err.classList.remove('d-none');
      return;
    }
    var box = $('#c-items');
    items.forEach(function (it) {
      var r = el('div', 'd-flex justify-content-between');
      r.appendChild(el('span', '', it.name + ' x' + it.qty));
      r.appendChild(el('span', 'text-muted', rupiah(it.qty * it.price)));
      box.appendChild(r);
    });
    $('#c-total').textContent = rupiah(Cart.total());
    if (d.note) { $('#c-note').textContent = d.note; $('#row-note').classList.remove('d-none'); }
    if (d.name) { $('#c-name').textContent = d.name; $('#row-name').classList.remove('d-none'); }

    var busy = false;
    btn.addEventListener('click', function () {
      if (busy) return; // cegah double click
      busy = true; btn.disabled = true; btn.textContent = 'Memproses...';
      err.classList.add('d-none');
      // token dipertahankan bila request diulang -> server tidak membuat order ganda
      if (!d.token) { d.token = randomToken(); Cart.save(); }
      var payload = {
        table: TABLE, token: d.token, note: d.note || '', name: d.name || '',
        items: items.map(function (i) { return { id: parseInt(i.id, 10), qty: i.qty }; })
      };
      fetch(BASE + 'api/create-order.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload)
      }).then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
        .then(function (res) {
          if (res.body && res.body.ok) {
            Cart.clear();
            window.location.replace('success.php?t=' + encodeURIComponent(res.body.token));
          } else {
            throw new Error((res.body && res.body.error) || 'Pesanan gagal.');
          }
        })
        .catch(function (e) {
          err.textContent = e && e.message && e.message !== 'Failed to fetch' ? e.message : 'Koneksi bermasalah. Silakan coba lagi.';
          err.classList.remove('d-none');
          busy = false; btn.disabled = false; btn.textContent = 'PESAN SEKARANG';
        });
    });
  }

  /* ---------- Admin: update status ---------- */
  function updateStatus(orderId, status) {
    var body = new URLSearchParams();
    body.set('order_id', orderId);
    body.set('status', status);
    return fetch(BASE + 'api/update-order.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': document.body.getAttribute('data-csrf') || '' },
      body: body
    }).then(function (r) { return r.json(); });
  }

  /* ---------- Halaman admin: order-detail ---------- */
  function initOrderDetail() {
    var box = $('#actions');
    if (!box) return;
    var err = $('#action-error');
    $all('[data-set-status]', box).forEach(function (b) {
      b.addEventListener('click', function () {
        var c = b.getAttribute('data-confirm');
        if (c && !window.confirm(c)) return;
        $all('button', box).forEach(function (x) { x.disabled = true; });
        updateStatus(box.getAttribute('data-order-id'), b.getAttribute('data-set-status')).then(function (j) {
          if (j.ok) { window.location.reload(); return; }
          err.textContent = j.error || 'Gagal.'; err.classList.remove('d-none');
          $all('button', box).forEach(function (x) { x.disabled = false; });
        }).catch(function () {
          err.textContent = 'Koneksi bermasalah.'; err.classList.remove('d-none');
          $all('button', box).forEach(function (x) { x.disabled = false; });
        });
      });
    });
  }

  /* ---------- Halaman: success (status pesanan live) ---------- */
  function initSuccess() {
    var token = body.getAttribute('data-token');
    var msgs = { NEW: 'Mohon menunggu.', PROCESSING: 'Pesanan Anda sedang disiapkan.', COMPLETED: 'Pesanan selesai. Selamat menikmati!', CANCELLED: 'Pesanan dibatalkan. Silakan hubungi pelayan.' };
    var last = body.getAttribute('data-status');
    function tick() {
      if (last === 'COMPLETED' || last === 'CANCELLED') return;
      fetch(BASE + 'api/order-status.php?t=' + encodeURIComponent(token), { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j.ok) {
            last = j.status;
            $('#order-status').textContent = j.status_label;
            $('#wait-msg').textContent = msgs[j.status] || '';
          }
        })
        .catch(function () { /* coba lagi di putaran berikut */ })
        .then(function () { setTimeout(tick, 5000); });
    }
    setTimeout(tick, 5000);
  }

  /* ---------- Halaman admin: QR ---------- */
  function initQr() {
    $all('.qr-holder').forEach(function (h) {
      var qr = window.qrcode(0, 'M');
      qr.addData(h.getAttribute('data-url'));
      qr.make();
      var n = qr.getModuleCount(), cell = 8, pad = 4 * cell, size = n * cell + pad * 2;
      var cv = document.createElement('canvas');
      cv.width = size; cv.height = size + 56;
      var ctx = cv.getContext('2d');
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cv.width, cv.height);
      ctx.fillStyle = '#000';
      for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) {
        if (qr.isDark(r, c)) ctx.fillRect(pad + c * cell, pad + r * cell, cell, cell);
      }
      ctx.font = 'bold 32px sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText('Meja ' + h.getAttribute('data-table'), size / 2, size + 22);
      h.appendChild(cv);
      $('[data-download]', h.parentNode).addEventListener('click', function () {
        var a = document.createElement('a');
        a.href = cv.toDataURL('image/png');
        a.download = 'qr-meja-' + h.getAttribute('data-table') + '.png';
        document.body.appendChild(a); a.click(); a.remove();
      });
    });
  }

  /* ---------- Halaman admin: dashboard (polling + notifikasi + suara) ---------- */
  function initDashboard() {
    var POLL_MS = 4000;
    var lastMaxId = null;       // null = poll pertama (jangan bunyikan untuk order lama)
    var audio = new Audio(BASE + 'assets/sounds/new-order.mp3');
    audio.preload = 'auto';
    var audioReady = false;     // true setelah ada interaksi user
    var banner = $('#notif-banner');
    var stateBadge = $('#notif-state');
    var testBtn = $('#btn-test-sound');

    function updateNotifUi() {
      var perm = ('Notification' in window) ? Notification.permission : 'unsupported';
      var text = 'Notifikasi: ' + (audioReady ? 'suara aktif' : 'suara mati') +
        (perm === 'granted' ? ', browser aktif' : perm === 'denied' ? ', browser diblokir' : '');
      stateBadge.textContent = text;
      stateBadge.className = 'badge ' + (audioReady ? 'text-bg-success' : 'text-bg-secondary');
      banner.classList.toggle('d-none', audioReady);
      testBtn.classList.toggle('d-none', !audioReady);
    }

    // Beep cadangan (Web Audio) bila file mp3 gagal diputar
    function beep() {
      try {
        var Ctx = window.AudioContext || window.webkitAudioContext;
        var ctx = new Ctx();
        [880, 1175].forEach(function (f, i) {
          var o = ctx.createOscillator(), g = ctx.createGain();
          o.frequency.value = f; o.connect(g); g.connect(ctx.destination);
          g.gain.setValueAtTime(0.3, ctx.currentTime + i * 0.25);
          g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + i * 0.25 + 0.22);
          o.start(ctx.currentTime + i * 0.25); o.stop(ctx.currentTime + i * 0.25 + 0.25);
        });
      } catch (e) { /* tidak ada audio */ }
    }
    function playSound() {
      try {
        audio.currentTime = 0;
        var p = audio.play();
        if (p && p.catch) p.catch(function () { beep(); });
      } catch (e) { beep(); }
    }

    // Aktivasi HARUS dari aksi user (aturan autoplay Chrome)
    function enable() {
      audio.muted = true;
      var p = audio.play();
      var done = function () { audio.pause(); audio.currentTime = 0; audio.muted = false; audioReady = true; lsSet('notif_enabled', '1'); updateNotifUi(); };
      if (p && p.then) p.then(done).catch(function () { audio.muted = false; audioReady = true; updateNotifUi(); });
      else done();
      if ('Notification' in window && Notification.permission === 'default') {
        try { Notification.requestPermission().then(updateNotifUi); } catch (e) { Notification.requestPermission(updateNotifUi); }
      }
    }
    $('#btn-enable-notif').addEventListener('click', enable);
    testBtn.addEventListener('click', playSound);
    // Klik/tap apa pun di halaman juga membuka izin audio (mis. setelah refresh)
    if (lsGet('notif_enabled') === '1') {
      var once = function () { document.removeEventListener('click', once); document.removeEventListener('touchend', once); enable(); };
      document.addEventListener('click', once);
      document.addEventListener('touchend', once);
    }
    updateNotifUi();

    function toast(o) {
      var t = el('div', 'toast align-items-center text-bg-danger border-0');
      t.setAttribute('role', 'alert');
      var wrap = el('div', 'd-flex');
      var b = el('div', 'toast-body');
      b.appendChild(el('div', 'fw-bold', '🔔 PESANAN BARU'));
      b.appendChild(el('div', '', 'Meja ' + o.table_number));
      b.appendChild(el('div', '', o.order_number));
      b.appendChild(el('div', '', o.item_count + ' Item'));
      var x = el('button', 'btn-close btn-close-white me-2 m-auto'); x.type = 'button';
      x.setAttribute('data-bs-dismiss', 'toast');
      wrap.appendChild(b); wrap.appendChild(x); t.appendChild(wrap);
      $('#toasts').appendChild(t);
      var inst = new window.bootstrap.Toast(t, { delay: 15000 });
      t.addEventListener('hidden.bs.toast', function () { t.remove(); });
      inst.show();
    }
    function browserNotify(o) {
      if (!('Notification' in window) || Notification.permission !== 'granted') return;
      try {
        new Notification('🔔 PESANAN BARU', {
          body: 'Meja ' + o.table_number + '\n' + o.order_number + '\n' + o.item_count + ' Item',
          tag: o.order_number
        });
      } catch (e) { /* beberapa browser mobile butuh service worker */ }
    }
    function notifyNew(newOrders) {
      newOrders.forEach(function (o) { toast(o); browserNotify(o); });
      if (audioReady) playSound();
    }

    function card(o) {
      var col = el('div', 'col-12 col-md-6 col-xl-4');
      var c = el('div', 'card order-card h-100 ' + (o.status === 'NEW' ? 'border-danger' : 'border-warning'));
      var b = el('div', 'card-body d-flex flex-column');
      var head = el('div', 'd-flex justify-content-between align-items-start mb-2');
      var l = el('div');
      l.appendChild(el('div', 'fw-bold fs-5', o.order_number));
      l.appendChild(el('div', 'text-muted', 'Meja ' + o.table_number));
      head.appendChild(l);
      head.appendChild(el('span', 'badge text-bg-light border', o.time));
      b.appendChild(head);
      o.items.forEach(function (i) { b.appendChild(el('div', '', i.name + ' x' + i.quantity)); });
      if (o.customer_name) b.appendChild(el('div', 'small text-muted mt-2', 'Nama: ' + o.customer_name));
      if (o.note) {
        var n = el('div', 'note-box mt-2');
        n.appendChild(el('div', 'small fw-semibold', 'Catatan:'));
        n.appendChild(el('div', 'text-break', o.note));
        b.appendChild(n);
      }
      var tot = el('div', 'mt-3 mb-3');
      tot.appendChild(el('div', 'small text-muted', 'Total:'));
      tot.appendChild(el('div', 'fw-bold fs-5', o.total_text));
      b.appendChild(tot);
      var acts = el('div', 'mt-auto d-grid gap-2');
      if (o.status === 'NEW') {
        acts.appendChild(actionBtn('btn btn-success btn-lg', 'TERIMA PESANAN', o.id, 'PROCESSING'));
        acts.appendChild(actionBtn('btn btn-outline-danger btn-sm', 'Batalkan', o.id, 'CANCELLED', 'Batalkan pesanan ' + o.order_number + '?'));
      } else {
        acts.appendChild(actionBtn('btn btn-primary btn-lg', 'SELESAIKAN PESANAN', o.id, 'COMPLETED'));
      }
      b.appendChild(acts);
      var link = el('a', 'small mt-2 text-center', 'Lihat detail');
      link.href = 'order-detail.php?id=' + o.id;
      b.appendChild(link);
      c.appendChild(b); col.appendChild(c);
      return col;
    }
    function actionBtn(cls, text, id, status, confirmMsg) {
      var b = el('button', cls, text); b.type = 'button';
      b.addEventListener('click', function () {
        if (confirmMsg && !window.confirm(confirmMsg)) return;
        $all('.order-card button').forEach(function (x) { x.disabled = true; });
        updateStatus(id, status).then(function (j) {
          if (!j.ok) window.alert(j.error || 'Gagal memperbarui status.');
          poll(true);
        }).catch(function () { window.alert('Koneksi bermasalah.'); poll(true); });
      });
      return b;
    }

    function render(orders) {
      var news = orders.filter(function (o) { return o.status === 'NEW'; });
      var proc = orders.filter(function (o) { return o.status === 'PROCESSING'; });
      [[news, '#list-new', '#empty-new'], [proc, '#list-proc', '#empty-proc']].forEach(function (s) {
        var box = $(s[1]); box.textContent = '';
        s[0].forEach(function (o) { box.appendChild(card(o)); });
        $(s[2]).classList.toggle('d-none', s[0].length > 0);
      });
    }

    var timer = null;
    function poll(now) {
      if (timer) { clearTimeout(timer); timer = null; }
      fetch(BASE + 'api/get-orders.php?status=active', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) {
          if (r.status === 401) { window.location.href = 'login.php'; throw new Error('auth'); }
          return r.json();
        })
        .then(function (j) {
          if (!j.ok) throw new Error(j.error);
          $('#conn-state').textContent = '';
          if (lastMaxId !== null) {
            var fresh = j.orders.filter(function (o) { return o.id > lastMaxId && o.status === 'NEW'; });
            if (fresh.length) notifyNew(fresh.reverse());
          }
          lastMaxId = j.max_id;
          render(j.orders);
        })
        .catch(function (e) { if (e.message !== 'auth') $('#conn-state').textContent = 'Koneksi terputus, mencoba lagi...'; })
        .then(function () { timer = setTimeout(poll, POLL_MS); });
    }
    poll();
  }

  /* ---------- Admin: sidebar collapse (desktop) + penutupan offcanvas (mobile) ---------- */
  function initSidebar() {
    var tgl = $('#sidebar-toggle');
    if (tgl) {
      var root = document.documentElement;
      tgl.setAttribute('aria-expanded', root.classList.contains('sb-collapsed') ? 'false' : 'true');
      tgl.addEventListener('click', function () {
        var c = root.classList.toggle('sb-collapsed');
        lsSet('admin_sidebar_collapsed', c ? '1' : '0');
        tgl.setAttribute('aria-expanded', c ? 'false' : 'true');
      });
    }
    var oc = $('#adminNav');
    if (oc && window.bootstrap) {
      $all('.side-link', oc).forEach(function (a) {
        a.addEventListener('click', function () { var i = window.bootstrap.Offcanvas.getInstance(oc); if (i) i.hide(); });
      });
    }
  }

  /* ---------- Admin: preview produk pada form promo/flyer ---------- */
  function initBannerAdmin() {
    var sel = $('#product_id'), box = $('#product-preview');
    if (!sel || !box) return;
    function upd() {
      var o = sel.options[sel.selectedIndex];
      if (!o || !o.value) { box.classList.add('d-none'); return; }
      $('#pp-img').src = o.getAttribute('data-img');
      $('#pp-name').textContent = o.getAttribute('data-name');
      $('#pp-price').textContent = o.getAttribute('data-price');
      var ok = o.getAttribute('data-avail') === '1', b = $('#pp-status');
      b.textContent = ok ? 'Tersedia' : 'Habis';
      b.className = 'badge text-bg-' + (ok ? 'success' : 'secondary');
      box.classList.remove('d-none');
    }
    sel.addEventListener('change', upd); upd();
  }

  var inits = { 'banner-admin': initBannerAdmin, menu: initMenu, cart: initCart, checkout: initCheckout, success: initSuccess, 'order-detail': initOrderDetail, qr: initQr, dashboard: initDashboard };
  initSidebar();
  if (inits[PAGE]) inits[PAGE]();
})();
