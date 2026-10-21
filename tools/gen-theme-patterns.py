#!/usr/bin/env python3
"""
Generator pola tema "African Ethnic / Batik Tribal" (DEV ONLY — hasilnya sudah di-commit di assets/images/theme/).
Jalankan hanya bila ingin mengubah pola/palet:  python3 tools/gen-theme-patterns.py
Semua SVG seamless (elemen di tepi digandakan di sisi seberang) dan tanpa referensi eksternal.
"""
import math, os, sys

OUT = os.path.join(os.path.dirname(__file__), '..', 'assets', 'images', 'theme')
INK, NAVY = '#14110F', '#0F1B2D'
ORANGE, MUSTARD, TEAL, BLUE, CRIMSON, CREAM = '#E8861B', '#F2B33D', '#1E8A8F', '#1F4E8C', '#B5242F', '#F6EFE2'


_defs = []


def svg(w, h, body, extra=''):
    d = '<defs>%s</defs>' % ''.join(_defs) if _defs else ''
    return ('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="%d" height="%d" viewBox="0 0 %d %d"%s>%s%s</svg>\n'
            % (w, h, w, h, extra, d, body))


def wrap(fn, w, h, *a):
    """Motif ditulis SEKALI di <defs>, lalu dipakai di 9 posisi geser (-w,0,+w)x(-h,0,+h) agar tile seamless."""
    mid = 'm%d' % len(_defs)
    _defs.append('<g id="%s">%s</g>' % (mid, fn(*a)))
    return ''.join('<use xlink:href="#%s" x="%d" y="%d"/>' % (mid, dx, dy) for dx in (-w, 0, w) for dy in (-h, 0, h))


def concentric(cx, cy, colors, r0, step):
    s = ''
    for i, c in enumerate(colors):
        r = r0 - i * step
        if i % 2 == 0:
            s += '<circle cx="%g" cy="%g" r="%g" fill="%s"/>' % (cx, cy, r, c)
        else:   # cincin bertitik
            n = max(8, int(2 * math.pi * r / 6))
            s += '<circle cx="%g" cy="%g" r="%g" fill="%s"/>' % (cx, cy, r, c)
            for k in range(n):
                a = 2 * math.pi * k / n
                s += '<circle cx="%.1f" cy="%.1f" r="1.3" fill="%s"/>' % (cx + (r - step / 2) * math.cos(a), cy + (r - step / 2) * math.sin(a), CREAM)
    return s


def spiral(cx, cy, turns, r_max, color, sw=2.2):
    pts = []
    n = 90
    for i in range(n + 1):
        t = i / n
        a = t * turns * 2 * math.pi
        r = t * r_max
        pts.append('%.1f,%.1f' % (cx + r * math.cos(a), cy + r * math.sin(a)))
    return '<polyline points="%s" fill="none" stroke="%s" stroke-width="%g" stroke-linecap="round"/>' % (' '.join(pts), color, sw)


def chevrons(x, y, w, rows, color, sw=3):
    s = ''
    for i in range(rows):
        yy = y + i * 9
        s += '<polyline points="%g,%g %g,%g %g,%g" fill="none" stroke="%s" stroke-width="%g" stroke-linejoin="miter"/>' % (x, yy + 8, x + w / 2, yy, x + w, yy + 8, color, sw)
    return s


def diamond_x(cx, cy, r, c1, c2):
    return ('<polygon points="%g,%g %g,%g %g,%g %g,%g" fill="%s"/>' % (cx, cy - r, cx + r, cy, cx, cy + r, cx - r, cy, c1) +
            '<path d="M%g %gL%g %gM%g %gL%g %g" stroke="%s" stroke-width="2"/>' % (cx - r / 2, cy - r / 2, cx + r / 2, cy + r / 2, cx + r / 2, cy - r / 2, cx - r / 2, cy + r / 2, c2))


def dot_grid(x, y, cols, rows, gap, color, r=1.6):
    return ''.join('<circle cx="%g" cy="%g" r="%g" fill="%s"/>' % (x + i * gap, y + j * gap, r, color) for i in range(cols) for j in range(rows))


def triangles(x, y, n, size, color):
    return ''.join('<polygon points="%g,%g %g,%g %g,%g" fill="%s"/>' % (x + i * size, y + size, x + i * size + size / 2, y, x + i * size + size, y + size, color) for i in range(n))


# ---------- pattern-tribal.svg : tile 240x240 (latar gelap) ----------
def tile_dark():
    b = '<rect width="240" height="240" fill="%s"/>' % NAVY
    # garis vertikal tipis
    b += ''.join('<rect x="%d" y="0" width="2" height="240" fill="#16263d"/>' % x for x in range(20, 240, 40))
    b += wrap(lambda: concentric(60, 60, [ORANGE, NAVY, MUSTARD, INK, TEAL, CREAM, CRIMSON], 44, 6), 240, 240)
    b += wrap(lambda: concentric(180, 180, [BLUE, CREAM, ORANGE, INK, MUSTARD, NAVY, TEAL], 44, 6), 240, 240)
    b += wrap(lambda: spiral(180, 60, 3.2, 30, MUSTARD) + spiral(180, 60, 3.2, 20, ORANGE, 1.6), 240, 240)
    b += wrap(lambda: spiral(60, 180, 3.2, 30, TEAL), 240, 240)
    b += wrap(lambda: dot_grid(112, 60, 4, 4, 6, CREAM) + dot_grid(8, 176, 5, 3, 6, MUSTARD), 240, 240)
    return svg(240, 240, b)


# ---------- pattern-tribal-soft.svg : versi pudar utk panel krem ----------
def tile_soft():
    b = wrap(lambda: concentric(40, 40, [ORANGE, CREAM, MUSTARD, CREAM, TEAL], 26, 5) + concentric(120, 120, [BLUE, CREAM, CRIMSON, CREAM, ORANGE], 26, 5)
             + dot_grid(66, 20, 3, 3, 6, MUSTARD), 160, 160)
    return svg(160, 160, b)


# Gaya pemisah (argumen CLI): calm = garis emas + titik (default), line = garis polos, none = tanpa ornamen
STYLE = sys.argv[1] if len(sys.argv) > 1 else 'calm'


def _hline(w, h, y, dots):
    if STYLE == 'none':
        return svg(w, h, '')
    b = '<rect x="0" y="%s" width="%d" height="2" fill="%s"/>' % (y, w, MUSTARD)
    if STYLE == 'calm' and dots:
        b += ''.join('<circle cx="%d" cy="%s" r="1.6" fill="%s"/>' % (x, y + 7, ORANGE) for x in range(6, w, 12))
    return svg(w, h, b)


# ---------- divider-zigzag.svg (nama lama dipertahankan): pita 24x14 repeat-x ----------
def divider():
    # pita penutup di tepi header/sidebar/footer: dasar navy solid supaya pola latar tidak tembus
    inner = _hline(24, 14, 3, True)
    return inner.replace('>', '><rect width="24" height="14" fill="%s"/>' % NAVY, 1) if STYLE != 'none' else inner


# ---------- divider-zigzag-v.svg : pita vertikal 14x24 (repeat-y) utk tepi kiri panel ----------
def divider_v():
    if STYLE == 'none':
        return svg(14, 24, '')
    b = '<rect width="14" height="24" fill="%s"/>' % NAVY
    b += '<rect x="3" y="0" width="2" height="24" fill="%s"/>' % MUSTARD
    if STYLE == 'calm':
        b += ''.join('<circle cx="10" cy="%d" r="1.6" fill="%s"/>' % (y, ORANGE) for y in range(6, 24, 12))
    return svg(14, 24, b)


# ---------- divider-ornament.svg : garis tipis untuk judul section ----------
def divider_thin():
    return _hline(24, 14, 6, False)


# ---------- frame-border.svg : 72x72, slice 24 -> bingkai foto tenang (pita krem polos, garis merah bertitik di sisi foto) ----------
def frame():
    SAND = '#EBD9B4'
    b = '<rect width="72" height="72" fill="%s"/>' % SAND
    b += '<rect x="9" y="9" width="54" height="54" fill="none" stroke="%s" stroke-width="1.5"/>' % NAVY
    # garis merah dengan titik krem tepat di sisi foto (area 20..24 dan 48..52)
    b += '<rect x="22" y="22" width="28" height="28" fill="none" stroke="%s" stroke-width="4"/>' % CRIMSON
    for i in range(23, 50, 5):
        for (x, y) in ((i, 22), (i, 50), (22, i), (50, i)):
            b += '<circle cx="%s" cy="%s" r="1" fill="%s"/>' % (x, y, CREAM)
    b += '<rect x="0.75" y="0.75" width="70.5" height="70.5" fill="none" stroke="%s" stroke-width="1.5"/>' % INK
    return svg(72, 72, b)


# ---------- paper.svg : noise kertas halus 160x160 ----------
def paper():
    return ('<svg xmlns="http://www.w3.org/2000/svg" width="160" height="160"><filter id="n" x="0" y="0" width="100%%" height="100%%">'
            '<feTurbulence type="fractalNoise" baseFrequency="0.9" numOctaves="2" stitchTiles="stitch" result="t"/>'
            '<feColorMatrix type="matrix" values="0 0 0 0 0.35  0 0 0 0 0.25  0 0 0 0 0.12  0 0 0 0.09 0"/></filter>'
            '<rect width="160" height="160" filter="url(#n)"/></svg>\n')


# ---------- stamp-ring.svg : lingkaran dasar lencana "stempel" ----------
def stamp():
    b = '<circle cx="50" cy="50" r="47" fill="none" stroke="%s" stroke-width="3" stroke-dasharray="2 4" stroke-linecap="round"/>' % MUSTARD
    b += '<circle cx="50" cy="50" r="40" fill="none" stroke="%s" stroke-width="2"/>' % MUSTARD
    return svg(100, 100, b)


# ---------- success-ornament.svg : ornamen untuk halaman sukses ----------
def success_orn():
    b = concentric(60, 60, [ORANGE, INK, MUSTARD, INK, TEAL], 56, 7)
    b += '<circle cx="60" cy="60" r="27" fill="%s"/>' % CREAM
    b += '<path d="M44 61 L55 72 L77 47" fill="none" stroke="%s" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/>' % INK
    return svg(120, 120, b)


builders = {'pattern-tribal.svg': tile_dark, 'pattern-tribal-soft.svg': tile_soft, 'divider-zigzag.svg': divider, 'divider-zigzag-v.svg': divider_v, 'divider-ornament.svg': divider_thin,
            'frame-border.svg': frame, 'paper.svg': paper, 'stamp-ring.svg': stamp, 'ornament-success.svg': success_orn}
files = {}
for name, fn in builders.items():
    del _defs[:]
    files[name] = fn()
os.makedirs(OUT, exist_ok=True)
for name, content in files.items():
    with open(os.path.join(OUT, name), 'w') as f:
        f.write(content)
    print(name, len(content.encode()), 'bytes')
