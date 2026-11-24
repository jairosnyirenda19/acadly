"""Acadly's document engine (D-096): one JSON instruction in on stdin, one A4 PDF out on stdout.

The same design as eOffice's: PHP gathers the records into an instruction and
this worker only draws. Every document shares one look: a rule in the school's
main colour, a letterhead (logo, name, title, number, status, time), ruled
sections, Page X of Y and the reference on every page, and a check code with
its QR so anyone can confirm the document is genuine.

Instruction:
    brand: {name, address, phone, main, logo (base64 PNG or JPEG, or null)}
    title, reference, status ('Original' | 'Copy' | 'Cancelled'), generated
    sections: [ {kind: 'facts' | 'table' | 'tiles' | 'text' | 'heading' | 'signatures', ...} ]
    check: {code, url} or null
    footer: the line on every page
"""

from __future__ import annotations

import base64
import io
import json
import sys
from pathlib import Path

from reportlab.graphics import renderPDF
from reportlab.graphics.barcode.qr import QrCodeWidget
from reportlab.graphics.shapes import Drawing
from reportlab.lib import colors
from reportlab.lib.enums import TA_RIGHT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import mm
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas as pdfcanvas
from reportlab.platypus import Flowable, Image, KeepTogether, Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle
from xml.sax.saxutils import escape

FONTS = Path(__file__).parent / 'fonts'
pdfmetrics.registerFont(TTFont('Display', str(FONTS / 'ArchivoNarrow-ExtraBold.ttf')))
pdfmetrics.registerFont(TTFont('Body', str(FONTS / 'AlbertSans-Regular.ttf')))
pdfmetrics.registerFont(TTFont('Body-Semi', str(FONTS / 'AlbertSans-SemiBold.ttf')))
pdfmetrics.registerFont(TTFont('Body-Bold', str(FONTS / 'AlbertSans-Bold.ttf')))
pdfmetrics.registerFont(TTFont('Mono', str(FONTS / 'DejaVuSansMono.ttf')))
pdfmetrics.registerFont(TTFont('Mono-Bold', str(FONTS / 'DejaVuSansMono-Bold.ttf')))

INK = colors.HexColor('#0C2340')
MUTED = colors.HexColor('#4B5D75')
RULE = colors.HexColor('#C7D5E3')
PAPER = colors.HexColor('#F1F4F6')
RED = colors.HexColor('#D12B1F')
TEAL = colors.HexColor('#0A7775')
MARIGOLD = colors.HexColor('#FFB703')
WHITE = colors.white

PAGE_W, PAGE_H = A4
MARGIN = 18 * mm
WIDTH = PAGE_W - 2 * MARGIN


def style(name: str, font: str = 'Body', size: float = 9.5, color=INK, leading: float | None = None, align: int = 0, space: float = 0) -> ParagraphStyle:
    return ParagraphStyle(name, fontName=font, fontSize=size, leading=leading or size * 1.32, textColor=color, alignment=align, spaceAfter=space)


S = {
    'body': style('body'),
    'muted': style('muted', size=8.5, color=MUTED),
    'small': style('small', size=7.5, color=MUTED),
    'value': style('value', 'Body-Semi', 9.5),
    'cell': style('cell', size=9),
    'cellRight': style('cellRight', 'Mono', 8.6, align=TA_RIGHT),
    'cellMono': style('cellMono', 'Mono', 8.4),
    'head': style('head', 'Body-Bold', 7, color=INK, leading=9),
    'headRight': style('headRight', 'Body-Bold', 7, color=INK, leading=9, align=TA_RIGHT),
    'tileLabel': style('tileLabel', 'Body-Bold', 6.8, color=MUTED, leading=9),
    'tileLabelInk': style('tileLabelInk', 'Body-Bold', 6.8, color=colors.HexColor('#A9BBD1'), leading=9),
    'tileNote': style('tileNote', size=7.6, color=MUTED),
    'tileNoteInk': style('tileNoteInk', size=7.6, color=colors.HexColor('#C9D6E4')),
}


def p(text: str, st: str = 'body') -> Paragraph:
    return Paragraph(escape(str(text)).replace('\n', '<br/>'), S[st])


def caps(text: str) -> str:
    return ' '.join(escape(str(text)).upper())  # letter-spaced small capitals


def section_heading(text: str) -> list:
    t = Table([[Paragraph(f'<font name="Body-Bold" size="7">{escape(str(text).upper())}</font>', style('h', 'Body-Bold', 7, leading=9))]], colWidths=[WIDTH])
    t.setStyle(TableStyle([('LINEBELOW', (0, 0), (-1, -1), 0.8, INK), ('LEFTPADDING', (0, 0), (-1, -1), 0), ('BOTTOMPADDING', (0, 0), (-1, -1), 3), ('TOPPADDING', (0, 0), (-1, -1), 0)]))
    return [Spacer(1, 12), t, Spacer(1, 5)]


def facts(items: list, columns: int = 1) -> Table:
    """Label and value pairs in a ruled box; two columns when asked."""
    rows = []
    pairs = [(p(i['label'], 'muted'), p(i['value'], 'value')) for i in items]
    if columns == 2:
        half = (len(pairs) + 1) // 2
        for a, b in zip(pairs[:half], pairs[half:] + [(p('', 'muted'), p('', 'value'))] * (2 * half - len(pairs))):
            rows.append([a[0], a[1], b[0], b[1]])
        widths = [WIDTH * 0.15, WIDTH * 0.35, WIDTH * 0.15, WIDTH * 0.35]
    else:
        rows = [[a, b] for a, b in pairs]
        widths = [WIDTH * 0.24, WIDTH * 0.76]
    t = Table(rows, colWidths=widths)
    t.setStyle(TableStyle([
        ('BOX', (0, 0), (-1, -1), 0.8, INK),
        ('LINEBELOW', (0, 0), (-1, -2), 0.4, RULE),
        ('VALIGN', (0, 0), (-1, -1), 'TOP'),
        ('TOPPADDING', (0, 0), (-1, -1), 4), ('BOTTOMPADDING', (0, 0), (-1, -1), 4),
        ('LEFTPADDING', (0, 0), (-1, -1), 7), ('RIGHTPADDING', (0, 0), (-1, -1), 7),
    ] + ([('LINEBEFORE', (2, 0), (2, -1), 0.8, INK)] if columns == 2 else [])))
    return t


def table(sec: dict) -> Table:
    """A boxed, lightly ruled ledger. Its head repeats on every page; amounts are right-aligned figures."""
    cols = sec['columns']
    align = sec.get('align', ['left'] * len(cols))
    weights = sec.get('weights', [1] * len(cols))
    total = sum(weights)
    widths = [WIDTH * w / total for w in weights]
    head = [Paragraph(escape(str(c).upper()), S['headRight' if a == 'right' else 'head']) for c, a in zip(cols, align)]
    body = []
    for row in sec['rows']:
        cells = []
        for value, a in zip(row, align):
            if a == 'right':
                cells.append(Paragraph(escape(str(value)), S['cellRight']))
            elif a == 'mono':
                cells.append(Paragraph(escape(str(value)), S['cellMono']))
            else:
                cells.append(p(value, 'cell'))
        body.append(cells)
    t = Table([head] + body, colWidths=widths, repeatRows=1)
    rules = [
        ('BOX', (0, 0), (-1, -1), 0.8, INK),
        ('LINEBELOW', (0, 0), (-1, 0), 0.8, INK),
        ('LINEBELOW', (0, 1), (-1, -2), 0.3, RULE),
        ('VALIGN', (0, 0), (-1, -1), 'TOP'),
        ('TOPPADDING', (0, 0), (-1, -1), 4), ('BOTTOMPADDING', (0, 0), (-1, -1), 4),
        ('LEFTPADDING', (0, 0), (-1, -1), 6), ('RIGHTPADDING', (0, 0), (-1, -1), 6),
    ]
    for i in range(2, len(body) + 1, 2):
        rules.append(('BACKGROUND', (0, i), (-1, i), PAPER))
    if sec.get('margin'):
        rules.append(('LINEAFTER', (0, 1), (0, -1), 1.1, RED))  # the register's red margin line
    if sec.get('total'):
        rules += [('LINEABOVE', (0, -1), (-1, -1), 0.8, INK), ('FONTNAME', (0, -1), (-1, -1), 'Body-Bold')]
    t.setStyle(TableStyle(rules))
    return t


def tiles(items: list, main) -> Table:
    """The key figures in a band; the first stands on the main colour."""
    cells = []
    for i, it in enumerate(items):
        ink = i == 0
        value = Paragraph(f'<font name="Display" size="21">{escape(str(it["value"]))}</font>', style(f'v{i}', 'Display', 21, color=WHITE if ink else INK, leading=22))
        cells.append([Paragraph(escape(str(it['label']).upper()), S['tileLabelInk' if ink else 'tileLabel']), value, Paragraph(escape(str(it.get('note', ''))), S['tileNoteInk' if ink else 'tileNote'])])
    widths = [WIDTH / len(items)] * len(items)
    inner = [[Table([[c[0]], [c[1]], [c[2]]], colWidths=[w - 4]) for c, w in zip(cells, widths)]]
    for row in inner:
        for t in row:
            t.setStyle(TableStyle([('LEFTPADDING', (0, 0), (-1, -1), 0), ('RIGHTPADDING', (0, 0), (-1, -1), 0), ('TOPPADDING', (0, 0), (-1, -1), 1), ('BOTTOMPADDING', (0, 0), (-1, -1), 1)]))
    t = Table(inner, colWidths=widths)
    t.setStyle(TableStyle([
        ('BOX', (0, 0), (-1, -1), 0.8, INK),
        ('LINEBEFORE', (1, 0), (-1, -1), 0.8, INK),
        ('BACKGROUND', (0, 0), (0, 0), main),
        ('VALIGN', (0, 0), (-1, -1), 'TOP'),
        ('TOPPADDING', (0, 0), (-1, -1), 7), ('BOTTOMPADDING', (0, 0), (-1, -1), 8),
        ('LEFTPADDING', (0, 0), (-1, -1), 9), ('RIGHTPADDING', (0, 0), (-1, -1), 5),
    ]))
    return t


class Qr(Flowable):
    def __init__(self, value: str, size: float):
        super().__init__()
        self.value, self.size = value, size

    def wrap(self, *args):
        return self.size, self.size

    def draw(self):
        widget = QrCodeWidget(self.value, barLevel='M')
        x0, y0, x1, y1 = widget.getBounds()
        d = Drawing(self.size, self.size, transform=[self.size / (x1 - x0), 0, 0, self.size / (y1 - y0), 0, 0])
        widget.barFillColor = INK
        d.add(widget)
        renderPDF.draw(d, self.canv, 0, 0)


def signatures(sec: dict, check: dict | None) -> Table:
    """Signature lines, the stamp's place, and the check code with its QR."""
    sign = Table([[Spacer(1, 26)], [p(sec.get('signed_by', ''), 'small')]], colWidths=[WIDTH * 0.3])
    sign.setStyle(TableStyle([('LINEBELOW', (0, 0), (-1, 0), 0.6, INK), ('LEFTPADDING', (0, 0), (-1, -1), 0)]))
    stamp = Table([[p('School stamp', 'small')]], colWidths=[WIDTH * 0.26], rowHeights=[52])
    stamp.setStyle(TableStyle([('BOX', (0, 0), (-1, -1), 0.6, colors.HexColor('#8496AC')), ('ALIGN', (0, 0), (-1, -1), 'CENTER'), ('VALIGN', (0, 0), (-1, -1), 'MIDDLE')]))
    cells = [sign, stamp]
    if check:
        qr = Table([[Qr(check['url'] + '?code=' + check['code'], 70)],
                    [Paragraph(f'<font name="Mono-Bold" size="9">{escape(check["code"])}</font>', style('code', 'Mono-Bold', 9, align=1))],
                    [Paragraph(escape(f'Check at {check["url"]}'), style('cu', size=6.8, color=MUTED, align=1))]], colWidths=[WIDTH * 0.3])
        qr.setStyle(TableStyle([('ALIGN', (0, 0), (-1, -1), 'CENTER'), ('LEFTPADDING', (0, 0), (-1, -1), 0), ('RIGHTPADDING', (0, 0), (-1, -1), 0)]))
        cells.append(qr)
    t = Table([cells], colWidths=[WIDTH * 0.34, WIDTH * 0.32, WIDTH * 0.34][: len(cells)])
    t.setStyle(TableStyle([('VALIGN', (0, 0), (-1, -1), 'BOTTOM'), ('LEFTPADDING', (0, 0), (-1, -1), 0)]))
    return t


def letterhead(doc: dict, main) -> Table:
    brand = doc['brand']
    logo = None
    if brand.get('logo'):
        try:
            data = io.BytesIO(base64.b64decode(brand['logo']))
            reader = ImageReader(data)
            w, h = reader.getSize()
            scale = min(34 / w, 34 / h)
            data.seek(0)
            logo = Image(data, width=w * scale, height=h * scale)
        except Exception:  # a logo that cannot be read is left out, never a failed document
            logo = None
    name = Paragraph(f'<font name="Display" size="15">{escape(brand["name"].upper())}</font><br/><font name="Body" size="7.5" color="#4B5D75">{escape(brand.get("address") or "")}</font>', style('n', 'Display', 15, leading=16))
    left = Table([[logo, name]] if logo else [[name]], colWidths=[40, WIDTH * 0.42 - 40] if logo else [WIDTH * 0.42])
    left.setStyle(TableStyle([('VALIGN', (0, 0), (-1, -1), 'MIDDLE'), ('LEFTPADDING', (0, 0), (-1, -1), 0)]))
    title = Paragraph(f'<font name="Display" size="22">{escape(doc["title"].upper())}</font>', style('t', 'Display', 22, align=1, leading=23))
    status = doc.get('status', 'Original')
    tone = '#D12B1F' if status == 'Cancelled' else '#0C2340'
    right = Paragraph(
        f'<font name="Mono-Bold" size="10.5">{escape(doc["reference"])}</font><br/>'
        f'<font name="Body-Bold" size="7" color="{tone}">{escape(status.upper())}</font><br/>'
        f'<font name="Body" size="7" color="#4B5D75">Generated {escape(doc.get("generated", ""))}</font>',
        style('r', size=8, leading=12, align=TA_RIGHT))
    t = Table([[left, title, right]], colWidths=[WIDTH * 0.42, WIDTH * 0.22, WIDTH * 0.36])
    t.setStyle(TableStyle([('VALIGN', (0, 0), (-1, -1), 'MIDDLE'), ('LINEBELOW', (0, 0), (-1, 0), 1.4, INK), ('LEFTPADDING', (0, 0), (-1, -1), 0), ('RIGHTPADDING', (0, 0), (-1, -1), 0), ('BOTTOMPADDING', (0, 0), (-1, -1), 9)]))
    return t


class Numbered(pdfcanvas.Canvas):
    """Draws the rule, the footer and Page X of Y once the page count is known."""

    def __init__(self, *args, doc: dict, main, **kwargs):
        super().__init__(*args, **kwargs)
        self._pages = []
        self._instr, self._main = doc, main

    def showPage(self):
        self._pages.append(dict(self.__dict__))
        self._startPage()

    def save(self):
        total = len(self._pages)
        for state in self._pages:
            self.__dict__.update(state)
            self._decorate(total)
            super().showPage()
        super().save()

    def _decorate(self, total: int):
        self.setFillColor(self._main)
        self.rect(0, PAGE_H - 4, PAGE_W, 4, stroke=0, fill=1)
        self.setStrokeColor(RULE)
        self.setLineWidth(0.4)
        self.line(MARGIN, 14 * mm, PAGE_W - MARGIN, 14 * mm)
        self.setFont('Body', 7)
        self.setFillColor(MUTED)
        self.drawString(MARGIN, 10 * mm, self._instr.get('footer', ''))
        self.drawRightString(PAGE_W - MARGIN, 10 * mm, f'Page {self._pageNumber} of {total}')
        if self._instr.get('status') == 'Cancelled':
            self.saveState()
            self.setFillColor(RED, alpha=0.12)
            self.setFont('Display', 96)
            self.translate(PAGE_W / 2, PAGE_H / 2)
            self.rotate(32)
            self.drawCentredString(0, -30, 'CANCELLED')
            self.restoreState()


def build(doc: dict) -> bytes:
    main = colors.HexColor(doc['brand'].get('main') or '#0C2340')
    story = [letterhead(doc, main)]
    for sec in doc.get('sections', []):
        kind = sec.get('kind')
        if sec.get('heading'):
            story += section_heading(sec['heading'])
        if kind == 'facts':
            story.append(facts(sec['items'], sec.get('columns', 1)))
        elif kind == 'table':
            story.append(table(sec))
        elif kind == 'tiles':
            story += [Spacer(1, 12), tiles(sec['items'], main)]
        elif kind == 'text':
            story += [Spacer(1, 10), p(sec['text'], sec.get('style', 'muted'))]
        elif kind == 'signatures':
            story += [Spacer(1, 26), KeepTogether([signatures(sec, doc.get('check'))])]
    out = io.BytesIO()
    pdf = SimpleDocTemplate(out, pagesize=A4, leftMargin=MARGIN, rightMargin=MARGIN, topMargin=14 * mm, bottomMargin=20 * mm,
                            title=f'{doc["title"]} {doc["reference"]}', author=doc['brand']['name'], creator='Acadly IQ-X')
    pdf.build(story, canvasmaker=lambda *a, **k: Numbered(*a, doc=doc, main=main, **k))
    return out.getvalue()


def build_many(docs: list) -> bytes:
    """Several documents, each drawn on its own pages, joined into one PDF (a class's report cards)."""
    import pypdfium2 as pdfium

    joined = pdfium.PdfDocument.new()
    for d in docs:
        joined.import_pages(pdfium.PdfDocument(build(d)))
    out = io.BytesIO()
    joined.save(out)
    return out.getvalue()


if __name__ == '__main__':
    instruction = json.loads(sys.stdin.buffer.read().decode('utf-8'))
    if 'documents' in instruction:
        sys.stdout.buffer.write(build_many(instruction['documents']))
    else:
        sys.stdout.buffer.write(build(instruction))
