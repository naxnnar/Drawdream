"""สร้าง favicon สี่เหลี่ยมจัตุรัสจาก img/โลโก้เว็บ.png — ครอปให้โลโก้ใหญ่ชัด ไม่บีบสัดส่วน."""
from pathlib import Path

try:
    from PIL import Image
except ImportError:
    raise SystemExit("ติดตั้ง Pillow ก่อน: py -m pip install pillow")

root = Path(__file__).resolve().parent.parent
src = root / "img" / "โลโก้เว็บ.png"
if not src.is_file():
    raise SystemExit(f"ไม่พบไฟล์: {src}")

img = Image.open(src).convert("RGBA")
bbox = img.getbbox()
if bbox:
    img = img.crop(bbox)
w, h = img.size
print(f"Source (trimmed): {w}x{h}")

# ครอปเป็นสี่เหลี่ยมจัตุรัสกลางภาพ แล้วขยายเต็ม canvas
side = min(w, h)
left = (w - side) // 2
top = (h - side) // 2
square = img.crop((left, top, left + side, top + side))

FILL = 0.96  # โลโก้เกือบเต็มกรอบ favicon

for size in (32, 48, 64, 128, 192):
    canvas = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    inner = int(round(size * FILL))
    resized = square.resize((inner, inner), Image.Resampling.LANCZOS)
    offset = (size - inner) // 2
    canvas.paste(resized, (offset, offset), resized)
    out = root / "img" / f"favicon-{size}x{size}.png"
    canvas.save(out, optimize=True)
    print(f"Wrote {out}")

print("Done.")
