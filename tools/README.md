# Asset build scripts

Local-only scripts that generate the site's static artwork. Their output is
committed, so the site never runs them and they are **not deployed** (the
folder is also denied over HTTP). Run them only to change the artwork.

All three need Python 3 with Pillow and NumPy (`cutout_logo.py`) or nothing
beyond the standard library (`build_atlas.py`, `build_guilloche.py`). Each is
deterministic: re-running it on the same input reproduces the committed files
byte for byte.

| Script | Writes | What it does |
|---|---|---|
| `cutout_logo.py` | `assets/logo.png`, `assets/logo-128.png`, `assets/favicon.ico`, `assets/favicon-32x32.png`, `assets/apple-touch-icon.png` | Cuts the emblem out of its black background along its traced edge (the coin is a few pixels off round), recolours the anti-aliased rim so no dark halo remains, and writes palette PNGs with transparency. The touch icon gets the ink background, because iOS fills transparency with black. |
| `build_atlas.py` | `assets/img/atlas.svg` | Projects Natural Earth country outlines into Equal Earth and writes one `<path>` per country, tagged with its ISO code, label point and bounding box. `assets/js/atlas.js` loads it on the homepage. |
| `build_guilloche.py` | `assets/img/guilloche.svg` | Draws the banknote-style rosette used behind the hero, footer and coin viewer. |

## Usage

```bash
# Emblem and icons, from the original black-background artwork in git history
git show 302752b:assets/logo.png > original-logo.png
python -I tools/cutout_logo.py original-logo.png assets

# World map
python -I tools/build_atlas.py path/to/geo-data assets/img/atlas.svg

# Rosette
python -I tools/build_guilloche.py assets/img/guilloche.svg
```

`build_atlas.py` expects three files in its data directory:

| File | Source |
|---|---|
| `countries-110m.json` | `https://cdn.jsdelivr.net/npm/world-atlas@2.0.2/countries-110m.json` |
| `countries-50m.json` | `https://cdn.jsdelivr.net/npm/world-atlas@2.0.2/countries-50m.json` |
| `codes.json` | `https://cdn.jsdelivr.net/npm/i18n-iso-countries@7.11.3/codes.json` |

The outlines are [Natural Earth](https://www.naturalearthdata.com/) admin-0
countries, which are in the public domain. The 110m set draws the map; the
50m set adds the small states 110m leaves out (Singapore, Malta, Bahrain and
others) so a coin from one of them still has somewhere to go.

After changing `atlas.svg`, keep `aspect-ratio` on `.atlas-map` in
`assets/css/style.css` equal to the SVG's `viewBox` width / height.
