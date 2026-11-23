"""Draws the pages of a document as pictures (D-103), the way eOffice does.

Reads a PDF or a picture from STDIN. The first argument is its type
("application/pdf", "image/jpeg", "image/png" or "image/webp"). Writes one
JSON line per page to STDOUT:
    {"page": 1, "width": ..., "height": ..., "jpeg": "<base64>"}
then a final line: {"done": true, "page_count": N}

Pages are drawn by PDFium (pypdfium2, the engine inside Chrome) and saved as
JPEG by Pillow. The pictures carry nothing of the file but its look: no
scripts, no links, no hidden layers. Deliberately simple: no files, no
network, no database; the document exists only in this process's memory.
Encryption and storage stay in the application.
"""

import base64
import io
import json
import sys

import pypdfium2 as pdfium
from PIL import Image

PAGE_DPI = 110
MAX_PAGES = 200
MAX_SIDE = 2000
QUALITY = 80


def emit(obj: dict) -> None:
    sys.stdout.write(json.dumps(obj) + "\n")
    sys.stdout.flush()


def jpeg(image: Image.Image) -> tuple:
    image = image.convert("RGB")
    image.thumbnail((MAX_SIDE, MAX_SIDE * 2))
    buf = io.BytesIO()
    image.save(buf, "JPEG", quality=QUALITY, optimize=True)
    return image.width, image.height, buf.getvalue()


def main() -> int:
    kind = sys.argv[1] if len(sys.argv) > 1 else "application/pdf"
    data = sys.stdin.buffer.read()
    count = 0
    if kind.startswith("image/"):
        Image.MAX_IMAGE_PIXELS = 40_000_000
        with Image.open(io.BytesIO(data)) as picture:
            width, height, body = jpeg(picture)
        emit({"page": 1, "width": width, "height": height, "jpeg": base64.b64encode(body).decode("ascii")})
        count = 1
    else:
        pdf = pdfium.PdfDocument(data)
        try:
            for index in range(min(len(pdf), MAX_PAGES)):
                page = pdf[index]
                bitmap = page.render(scale=PAGE_DPI / 72, may_draw_forms=True, fill_color=(255, 255, 255, 255))
                width, height, body = jpeg(bitmap.to_pil())
                emit({"page": index + 1, "width": width, "height": height, "jpeg": base64.b64encode(body).decode("ascii")})
                page.close()
                count += 1
        finally:
            pdf.close()
    emit({"done": True, "page_count": count})
    return 0


if __name__ == "__main__":
    sys.exit(main())
