/* Kitchen Display: polling 4 detik, notifikasi visual + suara untuk tiket baru. */
(function () {
  'use strict';
  var body = document.body;
  if (body.getAttribute('data-page') !== 'kitchen') return;
  var BASE = body.getAttribute('data-base') || '', CSRF = body.getAttribute('data-csrf'), CAN = body.getAttribute('data-can-update') === '1';
  function $(s) { return document.querySelector(s); }
  function el(t, c, x) { var n = document.createElement(t); if (c) n.className = c; if (x !== undefined && x !== null) n.textContent = x; return n; }
  var station = 'ALL', seen = null, audioOk = false;
  try { station = localStorage.getItem('kds_station') || 'ALL'; } catch (e) {}
  var audio = new Audio(BASE + 'assets/sounds/new-order.mp3');
  var NEXT = { NEW: ['PREPARING', 'Mulai'], PREPARING: ['READY', 'Siap'], READY: ['SERVED', 'Disajikan'] };

  function stationButtons() {
    document.querySelectorAll('#kds-station [data-st]').forEach(function (b) {
      var on = b.getAttribute('data-st') === station;
      b.className = 'btn ' + (on ? 'btn-dark' : 'btn-outline-dark'); b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }
  document.querySelectorAll('#kds-station [data-st]').forEach(function (b) {
    b.addEventListener('click', function () { station = b.getAttribute('data-st'); try { localStorage.setItem('kds_station', station); } catch (e) {} stationButtons(); seen = null; load(); });
  });
  $('#kds-sound').addEventListener('click', function () {
    audio.muted = true;
    var p = audio.play();
    var done = function () { audio.pause(); audio.currentTime = 0; audio.muted = false; audioOk = true; $('#kds-sound').className = 'btn btn-outline-success'; $('#kds-sound').textContent = 'Suara aktif'; };
    if (p && p.then) p.then(done).catch(done); else done();
  });

  function update(t, to) {
    fetch(BASE + 'api/kitchen.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ order_id: t.order_id, station: t.station, batch: t.batch, to: to }) })
      .then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) alert(j.error); load(); });
  }
  function card(t, fresh) {
    var c = el('article', 'kds-card' + (fresh ? ' kds-fresh' : '') + (t.minutes >= 25 && t.status !== 'SERVED' ? ' kds-late' : (t.minutes >= 15 && t.status !== 'SERVED' ? ' kds-warn' : '')));
    var h = el('div', 'kds-card-head');
    var l = el('div'); l.appendChild(el('div', 'fw-bold fs-5', t.place)); l.appendChild(el('div', 'small', t.order_number + (t.batch > 1 ? ' · Tambahan #' + t.batch : '') + (t.source === 'QR' ? ' · QR' : '')));
    if (t.customer) l.appendChild(el('div', 'small', t.customer));
    h.appendChild(l);
    var r = el('div', 'text-end'); r.appendChild(el('span', 'badge ' + (t.station === 'BAR' ? 'text-bg-info' : 'text-bg-secondary'), t.station === 'BAR' ? 'BAR' : 'KITCHEN'));
    r.appendChild(el('div', 'small mt-1', 'Masuk ' + t.time)); r.appendChild(el('div', 'kds-timer', t.minutes + ' mnt'));
    h.appendChild(r); c.appendChild(h);
    var ul = el('ul', 'kds-items');
    t.items.forEach(function (i) {
      var li = el('li', i.status === 'READY' && t.status !== 'READY' ? 'is-ready' : '');
      li.appendChild(el('span', 'kds-qty', i.qty + '×')); li.appendChild(el('span', 'kds-name', i.name));
      if (i.note) li.appendChild(el('div', 'kds-note', i.note));
      ul.appendChild(li);
    });
    c.appendChild(ul);
    if (t.note) c.appendChild(el('div', 'kds-note mb-2', 'Catatan: ' + t.note));
    if (CAN && NEXT[t.status]) {
      var label = t.status === 'READY' && t.place === 'Delivery' ? 'Diambil kurir' : NEXT[t.status][1];
      var b = el('button', 'btn btn-lg w-100 ' + (t.status === 'NEW' ? 'btn-warning' : (t.status === 'PREPARING' ? 'btn-success' : 'btn-dark')), label);
      b.type = 'button'; b.addEventListener('click', function () { b.disabled = true; update(t, NEXT[t.status][0]); });
      c.appendChild(b);
    }
    return c;
  }
  function load() {
    return fetch(BASE + 'api/kitchen.php' + (station !== 'ALL' ? '?station=' + station : ''), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (r.status === 401) location.href = 'login.php'; return r.json(); })
      .then(function (j) {
        if (!j.ok) return;
        $('#kds-clock').textContent = 'Diperbarui ' + j.server_time;
        var keys = {}, fresh = [];
        j.tickets.forEach(function (t) { keys[t.key] = 1; if (seen && !seen[t.key] && t.status === 'NEW') fresh.push(t.key); });
        ['NEW', 'PREPARING', 'READY', 'SERVED'].forEach(function (s) {
          var box = $('#kl-' + s); box.textContent = '';
          var list = j.tickets.filter(function (t) { return t.status === s; });
          if (s === 'SERVED') list.reverse();
          list.forEach(function (t) { box.appendChild(card(t, fresh.indexOf(t.key) !== -1)); });
          if (!list.length) box.appendChild(el('div', 'text-muted small text-center py-3', '—'));
          $('#kn-' + s).textContent = list.length;
        });
        if (fresh.length && audioOk) { try { audio.currentTime = 0; audio.play(); } catch (e) {} }
        if (fresh.length) document.title = '(' + fresh.length + ') Tiket baru - Kitchen';
        seen = keys;
      }).catch(function () { $('#kds-clock').textContent = 'Koneksi terputus, mencoba lagi…'; });
  }
  function loop() { load().then(function () { setTimeout(loop, 4000); }, function () { setTimeout(loop, 4000); }); }
  document.addEventListener('click', function () { document.title = 'Kitchen Display'; });
  stationButtons(); loop();
})();
