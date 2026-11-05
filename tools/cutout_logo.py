"""Cut the Mudra Archive logo coin out of its black background.

The coin is close to, but not exactly, a circle (it is a few pixels taller than
it is wide), so a plain circular mask would either clip the rim or leave a
black sliver. Instead the real edge is traced along 2880 rays from the centre,
smoothed, and used as the boundary of an anti-aliased alpha mask. Edge pixels
are recoloured from just inside the rim so no dark halo survives on light
backgrounds.

Usage: python -I tools/cutout_logo.py <black-background logo.png> <out dir>

The source is the original artwork with the black background, kept in git
history: git show 302752b:assets/logo.png > original-logo.png
"""
import math
import sys

import numpy as np
from PIL import Image

src, out_dir = sys.argv[1], sys.argv[2]

rgb = np.asarray(Image.open(src).convert("RGB")).astype(np.float64)
H, W, _ = rgb.shape
lum = rgb.max(axis=2)


def bilinear(img, x, y):
    x = np.clip(x, 0, W - 1.001)
    y = np.clip(y, 0, H - 1.001)
    x0 = np.floor(x).astype(int)
    y0 = np.floor(y).astype(int)
    fx = x - x0
    fy = y - y0
    if img.ndim == 2:
        a = img[y0, x0]; b = img[y0, x0 + 1]; c = img[y0 + 1, x0]; d = img[y0 + 1, x0 + 1]
        return (a * (1 - fx) + b * fx) * (1 - fy) + (c * (1 - fx) + d * fx) * fy
    fx = fx[..., None]; fy = fy[..., None]
    a = img[y0, x0]; b = img[y0, x0 + 1]; c = img[y0 + 1, x0]; d = img[y0 + 1, x0 + 1]
    return (a * (1 - fx) + b * fx) * (1 - fy) + (c * (1 - fx) + d * fx) * fy


# 1. Rough centre from the bounding box of everything brighter than the background.
ys, xs = np.where(lum > 40)
cx, cy = (xs.min() + xs.max()) / 2.0, (ys.min() + ys.max()) / 2.0

# 2. Trace the edge: for each ray, the outermost radius where luminance crosses
#    the threshold, interpolated to sub-pixel precision.
N = 2880
thr = 48.0
radii = np.arange(205.0, 265.0, 0.05)
angles = np.arange(N) * (2 * math.pi / N)
edge = np.zeros(N)
for i, t in enumerate(angles):
    px = cx + radii * math.cos(t)
    py = cy + radii * math.sin(t)
    prof = bilinear(lum, px, py)
    above = np.where(prof >= thr)[0]
    j = above.max()
    if j + 1 < len(radii):
        v0, v1 = prof[j], prof[j + 1]
        f = (v0 - thr) / (v0 - v1) if v0 != v1 else 0.0
        edge[i] = radii[j] + f * (radii[j + 1] - radii[j])
    else:
        edge[i] = radii[j]

# Recentre on the traced edge and repeat once, so the polar sampling is not skewed.
ex = cx + edge * np.cos(angles)
ey = cy + edge * np.sin(angles)
A = np.c_[2 * ex, 2 * ey, np.ones(N)]
sol, *_ = np.linalg.lstsq(A, ex ** 2 + ey ** 2, rcond=None)
cx, cy = sol[0], sol[1]
for i, t in enumerate(angles):
    px = cx + radii * math.cos(t)
    py = cy + radii * math.sin(t)
    prof = bilinear(lum, px, py)
    above = np.where(prof >= thr)[0]
    j = above.max()
    v0, v1 = prof[j], prof[min(j + 1, len(radii) - 1)]
    f = (v0 - thr) / (v0 - v1) if v0 != v1 else 0.0
    edge[i] = radii[j] + f * 0.05

# 3. Smooth the boundary (circular moving average over ~1.5 degrees) to remove the
#    stair-stepping left by the palette quantisation of the original PNG.
k = 13
pad = np.r_[edge[-k:], edge, edge[:k]]
kernel = np.ones(2 * k + 1) / (2 * k + 1)
smooth = np.convolve(pad, kernel, mode="same")[k:-k]
# Pull the boundary in by a quarter pixel so no background-blended pixel is kept opaque.
smooth -= 0.25
print("centre %.2f,%.2f  radius min %.2f max %.2f" % (cx, cy, smooth.min(), smooth.max()))

# 4. Alpha from signed distance to the boundary, with a 1 px anti-aliased ramp.
yy, xx = np.mgrid[0:H, 0:W].astype(np.float64)
dx = xx + 0.5 - cx - 0.5
dy = yy + 0.5 - cy - 0.5
dist = np.hypot(dx, dy)
theta = np.mod(np.arctan2(dy, dx), 2 * math.pi)
pos = theta / (2 * math.pi) * N
i0 = np.floor(pos).astype(int) % N
i1 = (i0 + 1) % N
fr = pos - np.floor(pos)
rb = smooth[i0] * (1 - fr) + smooth[i1] * fr
alpha = np.clip(rb - dist + 0.5, 0.0, 1.0)

# 5. Decontaminate: anything within 2 px of the edge takes the colour found 2.5 px
#    inside the rim along the same ray, so the anti-aliased fringe is coin-coloured
#    rather than a blend with black. Fully transparent pixels get the same colour
#    (colour bleed), so any later resampling cannot pull black in at the edge.
band = dist > (rb - 2.0)
ux = dx / np.maximum(dist, 1e-6)
uy = dy / np.maximum(dist, 1e-6)
sx = cx + 0.5 + ux * (rb - 2.5) - 0.5
sy = cy + 0.5 + uy * (rb - 2.5) - 0.5
inner = bilinear(rgb, sx, sy)
out_rgb = np.where(band[..., None], inner, rgb)

rgba = np.dstack([out_rgb, alpha * 255.0])
rgba = np.clip(np.round(rgba), 0, 255).astype(np.uint8)
logo = Image.fromarray(rgba, "RGBA")


def save_png(img, path):
    img.save(path, optimize=True)
    print(path, img.size)


def save_png8(img, path):
    """256-colour palette PNG with alpha: the silver artwork survives the palette
    (the original was already palettised), and the file is about a fifth of the
    size of a full RGBA PNG."""
    q = img.quantize(colors=256, method=Image.Quantize.FASTOCTREE, dither=Image.Dither.FLOYDSTEINBERG)
    q.save(path, optimize=True)
    print(path, img.size, "palette")


save_png8(logo, out_dir + "/logo.png")

# Header-sized copy: the header shows the logo at 42 CSS px, so 128 px covers
# 3x displays without shipping the 512 px original on every page view.
save_png8(logo.resize((128, 128), Image.LANCZOS), out_dir + "/logo-128.png")

# Favicons keep the transparency, so the tab shows a round coin, not a black square.
fav32 = logo.resize((32, 32), Image.LANCZOS)
save_png(fav32, out_dir + "/favicon-32x32.png")
logo.save(out_dir + "/favicon.ico", format="ICO", sizes=[(16, 16), (32, 32), (48, 48)])
print(out_dir + "/favicon.ico", "16/32/48")

# iOS fills transparent touch icons with black, so the touch icon gets the
# site's ink background with a soft gold spotlight instead.
S = 180
yy2, xx2 = np.mgrid[0:S, 0:S].astype(np.float64)
r = np.hypot(xx2 - S / 2, yy2 - S * 0.46) / (S * 0.72)
base = np.array([13, 16, 23], dtype=np.float64)        # --ink-950
glow = np.array([58, 46, 24], dtype=np.float64)        # warm gold haze
mix = np.clip(1 - r, 0, 1) ** 1.6
bg = base[None, None, :] * (1 - mix[..., None]) + glow[None, None, :] * mix[..., None]
touch = Image.fromarray(np.clip(np.round(bg), 0, 255).astype(np.uint8), "RGB").convert("RGBA")
coin = logo.resize((146, 146), Image.LANCZOS)
# Soft contact shadow under the coin.
shadow = Image.new("RGBA", (S, S), (0, 0, 0, 0))
sh = np.zeros((S, S, 4), dtype=np.float64)
d2 = np.hypot(xx2 - S / 2, (yy2 - S / 2 - 6) * 1.0) / 76.0
sh[..., 3] = np.clip(1.15 - d2, 0, 1) ** 2 * 150
shadow = Image.fromarray(sh.astype(np.uint8), "RGBA")
touch.alpha_composite(shadow)
touch.alpha_composite(coin, ((S - 146) // 2, (S - 146) // 2))
save_png(touch.convert("RGB"), out_dir + "/apple-touch-icon.png")
