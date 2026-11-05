"""Generate /assets/img/guilloche.svg - a banknote-style guilloche rosette.

Each band is one wavy ring, r(t) = R + A*sin(n*t), defined once and repeated
with <use> at evenly spaced rotations inside a single lobe period. The
interleaved copies produce the woven moire of engraved security printing while
keeping the file a few kilobytes.

Usage: python -I build_guilloche.py <output svg>
"""
import math
import sys

out = sys.argv[1]
C = 500.0  # centre of a 1000 x 1000 viewBox


def ring(R, A, n, samples_per_lobe=10, phase=0.0, k=1.0):
    pts = []
    total = n * samples_per_lobe
    for i in range(total + 1):
        t = 2 * math.pi * i / total
        r = R + A * math.sin(n * t + phase) * (1 if k == 1.0 else (0.6 + 0.4 * math.cos(k * t)))
        pts.append((C + r * math.cos(t), C + r * math.sin(t)))
    d = "M%.1f %.1f" % pts[0]
    px, py = pts[0]
    rel = []
    for x, y in pts[1:]:
        rel.append("%.1f %.1f" % (x - px, y - py))
        px, py = x, y
    d += "l" + " ".join(rel)
    return d.replace(" -", "-").replace(".0 ", " ").replace(".0-", "-") + "z"


bands = [
    # id, R, A, lobes, copies, stroke width
    ("a", 468, 16, 40, 10, 0.7),
    ("b", 392, 30, 28, 12, 0.7),
    ("c", 300, 42, 20, 12, 0.75),
    ("d", 196, 38, 14, 10, 0.8),
    ("e", 104, 46, 9, 9, 0.85),
]

defs = []
uses = []
for bid, R, A, n, copies, sw in bands:
    defs.append('<path id="%s" d="%s" stroke-width="%s"/>' % (bid, ring(R, A, n), sw))
    step = 360.0 / n / copies
    for i in range(copies):
        uses.append('<use href="#%s" transform="rotate(%.3f 500 500)"/>' % (bid, i * step))

# Fine concentric hairlines framing the bands.
circles = "".join('<circle cx="500" cy="500" r="%d" stroke-width="0.6"/>' % r for r in (492, 432, 352, 250, 150, 52))

svg = (
    '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 1000 1000">'
    '<g fill="none" stroke="#d9b25c" stroke-linejoin="round">'
    "<defs>%s</defs>%s%s</g></svg>\n" % ("".join(defs), "".join(uses), circles)
)
# Safari < 15 and some image decoders only resolve xlink:href inside <use>.
svg = svg.replace('<use href="#', '<use xlink:href="#')
with open(out, "w", encoding="utf-8", newline="\n") as f:
    f.write(svg)
print(out, len(svg), "bytes")
