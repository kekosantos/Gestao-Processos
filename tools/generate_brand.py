#!/usr/bin/env python3
"""Generate the LexCloud flat geometric mark in raster and vector formats."""
from pathlib import Path
from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parents[1]
ASSETS = ROOT / "public" / "assets"
ASSETS.mkdir(parents=True, exist_ok=True)
BG = (11, 91, 85, 255)
FG = (245, 247, 245, 255)

# Full-bleed opaque square app icon; the three shapes suggest a clear legal portal.
image = Image.new("RGBA", (512, 512), BG)
draw = ImageDraw.Draw(image)
draw.rounded_rectangle((118, 112, 394, 184), radius=10, fill=FG)
draw.rounded_rectangle((148, 184, 218, 398), radius=8, fill=FG)
draw.rounded_rectangle((294, 184, 364, 398), radius=8, fill=FG)
image.convert("RGB").save(ASSETS / "lexcloud-icon-512.png", optimize=True)
image.resize((192, 192), Image.Resampling.LANCZOS).convert("RGB").save(ASSETS / "lexcloud-icon-192.png", optimize=True)
image.resize((180, 180), Image.Resampling.LANCZOS).convert("RGB").save(ASSETS / "apple-touch-icon.png", optimize=True)

mark = '''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" role="img" aria-label="LexCloud"><rect width="512" height="512" fill="#0b5b55"/><path fill="#f5f7f5" d="M118 112h276v72H118zM148 184h70v214h-70zM294 184h70v214h-70z"/></svg>'''
(ASSETS / "lexcloud-mark.svg").write_text(mark + "\n", encoding="utf-8")
(ASSETS / "favicon.svg").write_text(mark + "\n", encoding="utf-8")
print("Generated 512px, 192px, Apple-touch PNG icons and SVG mark/favicon.")
