#!/usr/bin/env python3
"""
Generator pola tema "African Ethnic / Batik Tribal" (DEV ONLY — hasilnya sudah di-commit di assets/images/theme/).
Jalankan hanya bila ingin mengubah pola/palet:  python3 tools/gen-theme-patterns.py
Semua SVG seamless (elemen di tepi digandakan di sisi seberang) dan tanpa referensi eksternal.
"""
import math, os

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
    b += wrap(lambda: chevrons(112, 8, 16, 4, CRIMSON) + chevrons(112, 196, 16, 4, MUSTARD), 240, 240)
    b += wrap(lambda: diamond_x(120, 120, 16, BLUE, CREAM) + diamond_x(0, 120, 12, CRIMSON, MUSTARD) + diamond_x(120, 0, 12, TEAL, CREAM), 240, 240)
    b += wrap(lambda: dot_grid(112, 60, 4, 4, 6, CREAM) + dot_grid(8, 176, 5, 3, 6, MUSTARD), 240, 240)
    b += wrap(lambda: triangles(100, 222, 4, 10, ORANGE) + triangles(140, 100, 3, 10, TEAL), 240, 240)
    return svg(240, 240, b)


# ---------- pattern-tribal-soft.svg : versi pudar utk panel krem ----------
def tile_soft():
    b = wrap(lambda: concentric(40, 40, [ORANGE, CREAM, MUSTARD, CREAM, TEAL], 26, 5) + concentric(120, 120, [BLUE, CREAM, CRIMSON, CREAM, ORANGE], 26, 5)
             + diamond_x(120, 40, 10, TEAL, CREAM) + diamond_x(40, 120, 10, CRIMSON, MUSTARD) + dot_grid(66, 20, 3, 3, 6, MUSTARD), 160, 160)
    return svg(160, 160, b)


# ---------- divider-zigzag.svg : pita 48x14 (repeat-x) ----------
def divider():
    b = '<rect width="48" height="14" fill="%s"/>' % INK
    b += ''.join('<polygon points="%d,13 %d,2 %d,13" fill="%s"/>' % (x, x + 6, x + 12, c) for x, c in ((0, ORANGE), (12, MUSTARD), (24, TEAL), (36, CRIMSON)))
    b += '<rect y="0" width="48" height="1.5" fill="%s"/><rect y="12.5" width="48" height="1.5" fill="%s"/>' % (CREAM, CREAM)
    return svg(48, 14, b)


# ---------- divider-zigzag-v.svg : pita vertikal 14x48 (repeat-y) utk tepi kiri panel ----------
def divider_v():
    b = '<rect width="14" height="48" fill="%s"/>' % INK
    b += ''.join('<polygon points="1,%d 12,%d 1,%d" fill="%s"/>' % (y, y + 6, y + 12, c) for y, c in ((0, ORANGE), (12, MUSTARD), (24, TEAL), (36, CRIMSON)))
    b += '<rect x="0" width="1.5" height="48" fill="%s"/><rect x="12.5" width="1.5" height="48" fill="%s"/>' % (CREAM, CREAM)
    return svg(14, 48, b)


# ---------- divider-ornament.svg : garis tipis zigzag berwarna (utk judul section) ----------
def divider_thin():
    b = '<polyline points="0,8 6,2 12,8 18,2 24,8" fill="none" stroke="%s" stroke-width="2"/>' % ORANGE
    b += ''.join('<circle cx="%d" cy="12" r="1.3" fill="%s"/>' % (x, MUSTARD) for x in (6, 18))
    return svg(24, 14, b)


# ---------- frame-border.svg : 72x72, slice 24 -> bingkai foto ----------
def frame():
    W = 72
    b = '<rect width="72" height="72" fill="%s"/>' % CREAM
    # cincin luar merah bertitik (4px)
    b += '<rect x="0" y="0" width="72" height="72" fill="%s"/>' % CRIMSON
    b += '<rect x="4" y="4" width="64" height="64" fill="%s"/>' % CREAM
    for i in range(0, 72, 6):
        for (x, y) in ((i + 3, 2), (i + 3, 70), (2, i + 3), (70, i + 3)):
            b += '<circle cx="%d" cy="%d" r="1.2" fill="%s"/>' % (x, y, CREAM)
    # pita dalam 24px: segitiga oranye/biru bergantian + belah ketupat di sudut
    b += '<rect x="4" y="4" width="64" height="20" fill="%s"/><rect x="4" y="48" width="64" height="20" fill="%s"/>' % (INK, INK)
    b += '<rect x="4" y="24" width="20" height="24" fill="%s"/><rect x="48" y="24" width="20" height="24" fill="%s"/>' % (INK, INK)
    for x in range(0, 72, 12):   # atas & bawah (periode 12 membagi 24)
        c1, c2 = (ORANGE, BLUE) if (x // 12) % 2 == 0 else (BLUE, ORANGE)
        b += '<polygon points="%d,22 %d,6 %d,22" fill="%s"/>' % (x + 2, x + 8, x + 14, c1)
        b += '<polygon points="%d,50 %d,66 %d,50" fill="%s"/>' % (x + 2, x + 8, x + 14, c2)
    for y in range(24, 48, 12):  # kiri & kanan
        b += '<polygon points="22,%d 6,%d 22,%d" fill="%s"/><polygon points="50,%d 66,%d 50,%d" fill="%s"/>' % (y + 2, y + 8, y + 14, ORANGE, y + 2, y + 8, y + 14, BLUE)
    for (cx, cy) in ((14, 14), (58, 14), (14, 58), (58, 58)):
        b += '<polygon points="%d,%d %d,%d %d,%d %d,%d" fill="%s" stroke="%s" stroke-width="1.5"/>' % (cx, cy - 9, cx + 9, cy, cx, cy + 9, cx - 9, cy, MUSTARD, INK)
    b += '<rect x="24" y="24" width="24" height="24" fill="%s"/>' % CREAM
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
