"""Build /assets/img/atlas.svg: an Equal Earth world map for the Mudra Archive.

Source data: Natural Earth admin-0 countries (public domain), as packaged by
the world-atlas npm module (110m for the base map, plus the 50m outlines of
small states that 110m omits - Singapore, Malta, Bahrain and so on), with ISO
3166-1 numeric -> alpha-2 codes from i18n-iso-countries.

The projection is computed here, once, so the browser receives plain SVG paths:
no mapping library, no tile server, no third-party requests.

Usage: python -I build_atlas.py <data dir> <output svg>
"""
import json
import math
import sys

data_dir, out_path = sys.argv[1], sys.argv[2]

codes = {num: a2 for a2, a3, num, _ in json.load(open(data_dir + "/codes.json", encoding="utf-8"))}

# --- Equal Earth projection (Šavrič, Patterson & Jenny, 2018) -----------------
A1, A2, A3, A4 = 1.340264, -0.081106, 0.000893, 0.003796
M = math.sqrt(3) / 2


def equal_earth(lon, lat):
    lam = math.radians(lon)
    phi = math.radians(lat)
    theta = math.asin(M * math.sin(phi))
    t2 = theta * theta
    t6 = t2 * t2 * t2
    x = lam * math.cos(theta) / (M * (A1 + 3 * A2 * t2 + t6 * (7 * A3 + 9 * A4 * t2)))
    y = theta * (A1 + A2 * t2 + t6 * (A3 + A4 * t2))
    return x, y


LAT_TOP, LAT_BOTTOM = 84.0, -57.0
WIDTH = 1000.0
X_HALF = equal_earth(180, 0)[0]
SCALE = WIDTH / (2 * X_HALF)
Y_TOP = equal_earth(0, LAT_TOP)[1]
Y_BOTTOM = equal_earth(0, LAT_BOTTOM)[1]
HEIGHT = (Y_TOP - Y_BOTTOM) * SCALE
TX = WIDTH / 2
TY = Y_TOP * SCALE


def project(lon, lat):
    lat = max(min(lat, 89.9), -89.9)
    x, y = equal_earth(lon, lat)
    return (TX + x * SCALE, TY - y * SCALE)


# --- TopoJSON decoding ---------------------------------------------------------
def decode(topo):
    sx, sy = topo["transform"]["scale"]
    tx, ty = topo["transform"]["translate"]
    arcs = []
    for arc in topo["arcs"]:
        x = y = 0
        pts = []
        for dx, dy in arc:
            x += dx
            y += dy
            pts.append((x * sx + tx, y * sy + ty))
        arcs.append(pts)
    return arcs


def ring_coords(arcs, idx_list):
    out = []
    for idx in idx_list:
        pts = arcs[idx] if idx >= 0 else list(reversed(arcs[~idx]))
        if out:
            pts = pts[1:]
        out.extend(pts)
    # Natural Earth closes rings that touch the date line with points at exactly
    # -180 or +180, and a ring in the eastern hemisphere sometimes carries a -180
    # (Russia, Fiji). Projected naively that draws a stray line across the whole
    # map, so edge points are snapped to the side the rest of the ring is on.
    if any(abs(b[0] - a[0]) > 180 for a, b in zip(out, out[1:])):
        east = sum(1 for p in out if p[0] > 0) >= len(out) / 2
        edge = 180.0 if east else -180.0
        out = [((edge if abs(x) >= 179.9999 else x), y) for x, y in out]
    return out


def polygons(arcs, geom):
    if geom["type"] == "Polygon":
        return [[ring_coords(arcs, r) for r in geom["arcs"]]]
    if geom["type"] == "MultiPolygon":
        return [[ring_coords(arcs, r) for r in poly] for poly in geom["arcs"]]
    return []


# --- Geometry helpers in projected space ----------------------------------------
def area_centroid(ring):
    a = cx = cy = 0.0
    n = len(ring)
    for i in range(n):
        x0, y0 = ring[i]
        x1, y1 = ring[(i + 1) % n]
        cross = x0 * y1 - x1 * y0
        a += cross
        cx += (x0 + x1) * cross
        cy += (y0 + y1) * cross
    a *= 0.5
    if abs(a) < 1e-9:
        xs = [p[0] for p in ring]
        ys = [p[1] for p in ring]
        return 0.0, (sum(xs) / n, sum(ys) / n)
    return abs(a), (cx / (6 * a), cy / (6 * a))


def simplify(points, tol):
    """Douglas-Peucker, iterative. Keeps first and last point."""
    if len(points) < 4 or tol <= 0:
        return points
    keep = [False] * len(points)
    keep[0] = keep[-1] = True
    stack = [(0, len(points) - 1)]
    while stack:
        s, e = stack.pop()
        x0, y0 = points[s]
        x1, y1 = points[e]
        dx, dy = x1 - x0, y1 - y0
        norm = math.hypot(dx, dy)
        best, idx = -1.0, -1
        for i in range(s + 1, e):
            px, py = points[i]
            if norm < 1e-9:
                # Closed ring: first and last points coincide, so measure the
                # distance from that point instead of from a zero-length chord.
                d = math.hypot(px - x0, py - y0)
            else:
                d = abs(dy * px - dx * py + x1 * y0 - y1 * x0) / norm
            if d > best:
                best, idx = d, i
        if best > tol and idx > 0:
            keep[idx] = True
            stack.append((s, idx))
            stack.append((idx, e))
    return [p for p, k in zip(points, keep) if k]


def fmt(v):
    s = "%.1f" % v
    if s.endswith(".0"):
        s = s[:-2]
    if s == "-0":
        s = "0"
    return s


def ring_path(pts):
    """Relative-coordinate path for one closed ring, at 0.1 unit precision."""
    q = [(round(x * 10), round(y * 10)) for x, y in pts]
    dedup = [q[0]]
    for p in q[1:]:
        if p != dedup[-1]:
            dedup.append(p)
    if len(dedup) > 1 and dedup[-1] == dedup[0]:
        dedup.pop()
    if len(dedup) < 3:
        return ""
    d = ["M%s %s" % (fmt(dedup[0][0] / 10), fmt(dedup[0][1] / 10))]
    px, py = dedup[0]
    rel = []
    for x, y in dedup[1:]:
        rel.append("%s %s" % (fmt((x - px) / 10), fmt((y - py) / 10)))
        px, py = x, y
    d.append("l" + " ".join(rel).replace(" -", "-"))
    d.append("z")
    return "".join(d)


NAME_FIX = {
    "Bosnia and Herz.": "Bosnia and Herzegovina", "Central African Rep.": "Central African Republic",
    "Dem. Rep. Congo": "DR Congo", "Dominican Rep.": "Dominican Republic", "Eq. Guinea": "Equatorial Guinea",
    "Falkland Is.": "Falkland Islands", "Fr. S. Antarctic Lands": "French Southern Territories",
    "Macedonia": "North Macedonia", "S. Sudan": "South Sudan", "Solomon Is.": "Solomon Islands",
    "W. Sahara": "Western Sahara", "eSwatini": "Eswatini", "United States of America": "United States",
    "Antigua and Barb.": "Antigua and Barbuda", "Cook Is.": "Cook Islands", "Faeroe Is.": "Faroe Islands",
    "S. Geo. and the Is.": "South Georgia", "Heard I. and McDonald Is.": "Heard and McDonald Islands",
    "Br. Indian Ocean Ter.": "British Indian Ocean Territory", "St. Kitts and Nevis": "Saint Kitts and Nevis",
    "Cayman Is.": "Cayman Islands", "Marshall Is.": "Marshall Islands", "N. Mariana Is.": "Northern Mariana Islands",
    "Fr. Polynesia": "French Polynesia", "St. Pierre and Miquelon": "Saint Pierre and Miquelon",
    "Pitcairn Is.": "Pitcairn Islands", "St. Vin. and Gren.": "Saint Vincent and the Grenadines",
    "Turks and Caicos Is.": "Turks and Caicos Islands", "British Virgin Is.": "British Virgin Islands",
    "U.S. Virgin Is.": "U.S. Virgin Islands", "Wallis and Futuna Is.": "Wallis and Futuna",
    "St-Barthélemy": "Saint Barthélemy", "St-Martin": "Saint Martin", "Vatican": "Vatican City",
    "São Tomé and Principe": "São Tomé and Príncipe", "Timor-Leste": "Timor-Leste",
}
# Features Natural Earth publishes without an ISO code, assigned to the state
# ISO 3166 recognises, so hovering them behaves like the rest of that country.
NO_ID = {"Kosovo": ("XK", "Kosovo"), "N. Cyprus": ("CY", None), "Somaliland": ("SO", None)}

countries = {}  # iso -> {"name": str, "polys": [...]}
order = []


def add_feature(iso, name, polys):
    if iso not in countries:
        countries[iso] = {"name": name, "polys": []}
        order.append(iso)
    elif name and not countries[iso]["name"]:
        countries[iso]["name"] = name
    countries[iso]["polys"].extend(polys)


base = json.load(open(data_dir + "/countries-110m.json", encoding="utf-8"))
base_arcs = decode(base)
for g in base["objects"]["countries"]["geometries"]:
    raw_name = g["properties"]["name"]
    iso = codes.get(g.get("id"))
    name = NAME_FIX.get(raw_name, raw_name)
    if iso is None:
        if raw_name not in NO_ID:
            continue
        iso, name = NO_ID[raw_name]
    if iso == "AQ":
        continue  # Antarctica: off the bottom of the map
    add_feature(iso, name, polygons(base_arcs, g))

fine = json.load(open(data_dir + "/countries-50m.json", encoding="utf-8"))
fine_arcs = decode(fine)
have = set(countries)
for g in fine["objects"]["countries"]["geometries"]:
    iso = codes.get(g.get("id"))
    if iso is None or iso in have or iso == "AQ":
        continue
    raw_name = g["properties"]["name"]
    add_feature(iso, NAME_FIX.get(raw_name, raw_name), polygons(fine_arcs, g))

# --- Project, simplify and serialise --------------------------------------------
wrap_warnings = 0
paths = []
for iso in sorted(order, key=lambda k: countries[k]["name"] or k):
    c = countries[iso]
    d_parts = []
    best_area, best_centroid, best_bbox = -1.0, None, None
    total_bbox = [1e9, 1e9, -1e9, -1e9]
    for poly in c["polys"]:
        for ri, ring in enumerate(poly):
            mirror = None
            if any(abs(b[0] - a[0]) > 180 for a, b in zip(ring, ring[1:])):
                # A ring that genuinely straddles the date line (Fiji, Chukotka):
                # unwrap it so it is continuous, and draw a copy shifted by 360
                # degrees so the overhang appears at the opposite edge. The
                # ocean clip-path trims both copies to the map outline.
                east = sum(1 for p in ring if p[0] > 0) >= len(ring) / 2
                if east:
                    ring = [(x + 360 if x < 0 else x, y) for x, y in ring]
                    mirror = [(x - 360, y) for x, y in ring]
                else:
                    ring = [(x - 360 if x > 0 else x, y) for x, y in ring]
                    mirror = [(x + 360, y) for x, y in ring]
                if any(abs(b[0] - a[0]) > 180 for a, b in zip(ring, ring[1:])):
                    wrap_warnings += 1
            proj = [project(lon, lat) for lon, lat in ring]
            proj = simplify(proj, 0.12)
            p = ring_path(proj)
            if p:
                d_parts.append(p)
            if mirror:
                mp = ring_path(simplify([project(lon, lat) for lon, lat in mirror], 0.12))
                if mp:
                    d_parts.append(mp)
            if ri == 0 and len(proj) >= 3:
                area, cen = area_centroid(proj)
                xs = [q[0] for q in proj]
                ys = [q[1] for q in proj]
                total_bbox = [min(total_bbox[0], min(xs)), min(total_bbox[1], min(ys)),
                              max(total_bbox[2], max(xs)), max(total_bbox[3], max(ys))]
                if area > best_area:
                    best_area, best_centroid = area, cen
                    best_bbox = (min(xs), min(ys), max(xs), max(ys))
    if not d_parts:
        continue
    name = c["name"] or iso
    bx = ",".join(fmt(v) for v in best_bbox)
    attrs = 'data-iso="%s" data-name="%s" data-c="%s,%s" data-b="%s"' % (
        iso, name.replace("&", "&amp;").replace('"', "&quot;"),
        fmt(best_centroid[0]), fmt(best_centroid[1]), bx)
    # Anything smaller than a few pixels at full-map scale is drawn, but also gets
    # a dot in the browser when it is part of the collection, so it can be found.
    if best_area < 14:
        attrs += ' data-tiny="1"'
    paths.append('<path %s d="%s"/>' % (attrs, "".join(d_parts)))

# --- Ocean outline and graticule --------------------------------------------------
def meridian(lon, lat0=LAT_BOTTOM, lat1=LAT_TOP, step=1.0):
    pts = []
    lat = lat0
    while lat <= lat1 + 1e-9:
        pts.append(project(lon, lat))
        lat += step
    return pts


def path_from(pts, close=False):
    d = "M" + " ".join("%s %s" % (fmt(x), fmt(y)) for x, y in pts[:1])
    d += "L" + " ".join("%s %s" % (fmt(x), fmt(y)) for x, y in pts[1:])
    return d + ("z" if close else "")


left = meridian(-180)
right = meridian(180)
ocean = path_from(left[::-1] + right, close=True)  # parallels are straight in Equal Earth

grat = []
for lon in range(-150, 181, 30):
    if lon in (-180, 180):
        continue
    grat.append(path_from(meridian(lon)))
for lat in (-30, 0, 30, 60):
    a = project(-180, lat)
    b = project(180, lat)
    grat.append("M%s %sH%s" % (fmt(a[0]), fmt(a[1]), fmt(b[0])))

svg = []
MARGIN = 14.0  # breathing room so the map outline never touches the frame
svg.append('<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s %s %s %s" '
           'data-scale="%.6f" data-tx="%.4f" data-ty="%.4f" class="atlas-svg" role="img" '
           'aria-label="World map of the countries represented in the collection">'
           % (fmt(-MARGIN), fmt(-MARGIN), fmt(WIDTH + 2 * MARGIN), fmt(HEIGHT + 2 * MARGIN), SCALE, TX, TY))
svg.append("<!-- Natural Earth admin-0 countries (public domain) via world-atlas; Equal Earth projection. -->")
svg.append('<defs><clipPath id="atlas-clip"><path d="%s"/></clipPath></defs>' % ocean)
svg.append('<path class="atlas-ocean" d="%s"/>' % ocean)
svg.append('<path class="atlas-graticule" d="%s"/>' % "".join(grat))
svg.append('<g class="atlas-countries" clip-path="url(#atlas-clip)">')
svg.extend(paths)
svg.append("</g>")
svg.append('<g class="atlas-markers"></g>')
svg.append("</svg>")

with open(out_path, "w", encoding="utf-8", newline="\n") as f:
    f.write("\n".join(svg) + "\n")

print("countries:", len(paths), "size:", sum(len(s) for s in svg), "bytes",
      "viewBox: %s x %s" % (fmt(WIDTH), fmt(HEIGHT)), "wrap warnings:", wrap_warnings)
print("scale %.6f tx %.4f ty %.4f" % (SCALE, TX, TY))
