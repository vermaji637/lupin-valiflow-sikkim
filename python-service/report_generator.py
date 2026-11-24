from docx import Document
from docx.shared import Pt
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement, parse_xml
from docx.oxml.ns import qn, nsdecls
from doc_parser import (
    PARA_BLANK_RE,
    PARA_COLON_BLANK_RE,
    dedupe_colon_label,
    count_underlined_tab_blanks,
    iter_unique_textboxes,
    _para_text_from_element,
    looks_like_sl_no_value,
    is_sl_no_column_header,
    is_two_col_grid_table,
    is_two_col_header_row,
    is_toc_list_table,
)
import regex as re
import os
import shutil
import zipfile
from copy import deepcopy

# Namespace for inline/anchored drawing elements inside runs.
DRAWING_NS = "http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"

# Legacy placeholder only — never invent this on fill. Original HVAC / requal
# / revalidation templates have no annex footer line.
FOOTER_ANNEX_TEXT = "ANNEX_xxx_xxx_XXXXX | (1.0) | SOP_XXXX_XXX_00000 | CLASS I"
_PLACEHOLDER_ANNEX_RE = re.compile(
    r"ANNEX_xxx[_\w]*\s*\|\s*\([^)]*\)\s*\|\s*SOP_XXXX[_\w]*\s*\|\s*CLASS\s*I",
    re.IGNORECASE,
)


# Normalize user-submitted text: strip nulls, fix mojibake, coerce to UTF-8 string.
def _normalize_text_value(value) -> str:
    if value is None:
        return ""
    if isinstance(value, bytes):
        value = value.decode("utf-8", errors="replace")
    else:
        value = str(value)
    value = value.replace("\x00", "")
    value = value.encode("utf-8", errors="replace").decode("utf-8")
    if re.search(r"[\u00c3\u00c2\u2018\u2019\u201c\u201d]", value):
        try:
            recovered = value.encode("latin-1").decode("utf-8")
            if recovered:
                value = recovered
        except (UnicodeEncodeError, UnicodeDecodeError):
            pass
    return value


def _run_has_image(run) -> bool:
    """True when a run contains an inline/anchored image — must not be deleted."""
    el = run._element
    if el.findall(f".//{{{DRAWING_NS}}}inline"):
        return True
    if el.findall(f".//{{{DRAWING_NS}}}anchor"):
        return True
    if el.findall(f".//{{{W_NS}}}drawing"):
        return True
    if el.findall(f".//{{{W_NS}}}pict"):
        return True
    return False


# Remove text runs from a paragraph but keep runs that contain logos or images.
def _clear_text_runs(para) -> None:
    """Remove only text runs from a paragraph; preserve image/drawing runs."""
    for run in list(para.runs):
        if not _run_has_image(run):
            run._element.getparent().remove(run._element)


# Strip indent/tabs/justification that can clip the first character after fill.
def _reset_paragraph_formatting(para) -> None:
    """Remove left indent / tabs that can clip the first character in Word and HTML preview."""
    from docx.oxml.ns import qn

    p_pr = para._element.get_or_add_pPr()
    for tag_name in ("w:ind", "w:tabs", "w:mirrorIndents"):
        tag = qn(tag_name)
        child = p_pr.find(tag)
        if child is not None:
            p_pr.remove(child)
    jc = p_pr.find(qn("w:jc"))
    if jc is not None:
        p_pr.remove(jc)


# Force left alignment on a paragraph via both python-docx API and raw OOXML.
def _set_paragraph_left_align(para) -> None:
    from docx.oxml.ns import qn

    para.alignment = WD_ALIGN_PARAGRAPH.LEFT
    p_pr = para._element.get_or_add_pPr()
    jc = p_pr.find(qn("w:jc"))
    if jc is None:
        jc = OxmlElement("w:jc")
        p_pr.append(jc)
    jc.set(qn("w:val"), "left")


# Insert a Word field code (PAGE, NUMPAGES, etc.) as begin/instr/separate/end runs.
def _insert_word_field(paragraph, field_code: str) -> None:
    """Insert a Word field (PAGE, NUMPAGES, etc.) into a paragraph."""
    run_begin = paragraph.add_run()
    fld_begin = OxmlElement("w:fldChar")
    fld_begin.set(qn("w:fldCharType"), "begin")
    run_begin._r.append(fld_begin)

    run_instr = paragraph.add_run()
    instr = OxmlElement("w:instrText")
    instr.set(qn("xml:space"), "preserve")
    instr.text = field_code
    run_instr._r.append(instr)

    run_sep = paragraph.add_run()
    fld_sep = OxmlElement("w:fldChar")
    fld_sep.set(qn("w:fldCharType"), "separate")
    run_sep._r.append(fld_sep)

    paragraph.add_run("1")

    run_end = paragraph.add_run()
    fld_end = OxmlElement("w:fldChar")
    fld_end.set(qn("w:fldCharType"), "end")
    run_end._r.append(fld_end)


# Tell Word to refresh PAGE/NUMPAGES fields when the document is opened.
def _enable_update_fields_on_open(doc) -> None:
    settings = doc.settings.element
    update = settings.find(qn("w:updateFields"))
    if update is None:
        update = OxmlElement("w:updateFields")
        settings.append(update)
    update.set(qn("w:val"), "true")


# Replace static "16 of 24" header text with live PAGE and NUMPAGES field codes.
def _inject_page_number_fields(doc) -> None:
    """Replace static '16 of 24' header text with PAGE / NUMPAGES field codes."""
    page_pattern = re.compile(r"^\s*\d+\s+of\s+\d+\s*$", re.I)
    for section in doc.sections:
        for table in section.header.tables:
            for row in table.rows:
                for cell in row.cells:
                    cell_text = cell.text.strip()
                    if not page_pattern.match(cell_text):
                        continue
                    para = cell.paragraphs[0]
                    _clear_text_runs(para)
                    _reset_paragraph_formatting(para)
                    para.alignment = WD_ALIGN_PARAGRAPH.CENTER
                    _insert_word_field(para, "PAGE")
                    para.add_run(" of ")
                    _insert_word_field(para, "NUMPAGES")
    _enable_update_fields_on_open(doc)


# Locate the inner <w:p> element inside a text box by txbx index and paragraph index.
def _resolve_textbox_paragraph_element(doc, txbx_index: int, inner_index: int):
    for idx, _, txbx in iter_unique_textboxes(doc):
        if idx != txbx_index:
            continue
        inner_paras = txbx.findall(f"{{{W_NS}}}p")
        if inner_index < len(inner_paras):
            return inner_paras[inner_index]
    return None


# Remove leftover underscore/dot placeholder runs after a tab blank is filled.
def _clear_underscore_placeholder_runs(p_element) -> None:
    """Remove trailing underscore/dot placeholder runs after a tab blank is filled."""
    for run in list(p_element.findall(f".//{{{W_NS}}}r")):
        t_el = run.find(f"{{{W_NS}}}t")
        if t_el is None or not t_el.text:
            continue
        if re.match(r"^[_\.\s]+$", t_el.text):
            parent = run.getparent()
            if parent is not None:
                parent.remove(run)


# Replace the Nth underlined-tab blank in a text-box paragraph with the user value.
def _fill_underlined_tab_blank(p_element, blank_index: int, value: str) -> bool:
    """Replace the Nth underlined tab blank with a text value."""
    value = _normalize_text_value(value)
    seen = 0
    for run in p_element.findall(f".//{{{W_NS}}}r"):
        if run.find(f"{{{W_NS}}}tab") is None:
            continue
        r_pr = run.find(f"{{{W_NS}}}rPr")
        if r_pr is None or r_pr.find(f"{{{W_NS}}}u") is None:
            continue
        if seen == blank_index:
            for child in list(run):
                run.remove(child)
            t_el = OxmlElement("w:t")
            t_el.text = value
            run.append(t_el)
            if r_pr.find(f"{{{W_NS}}}u") is not None:
                r_pr.remove(r_pr.find(f"{{{W_NS}}}u"))
            return True
        seen += 1
    return False


# Write user text into a bordered blank recommendation line inside a text box.
def _fill_textbox_line(p_element, value: str) -> None:
    """Write user text into a bordered blank recommendation line."""
    value = _normalize_text_value(value)
    for child in list(p_element):
        if child.tag.endswith("}r"):
            p_element.remove(child)
    p_pr = p_element.find(f"{{{W_NS}}}pPr")
    if p_pr is not None:
        jc = p_pr.find(f"{{{W_NS}}}jc")
        if jc is None:
            jc = OxmlElement("w:jc")
            p_pr.append(jc)
        jc.set(qn("w:val"), "left")
    if value:
        run = OxmlElement("w:r")
        text_el = OxmlElement("w:t")
        text_el.text = value
        run.append(text_el)
        p_element.append(run)


# Insert a clickable external hyperlink run at the end of a paragraph.
def _add_hyperlink_to_paragraph(
    paragraph,
    text: str,
    url: str,
    font_name=None,
    font_size=None,
    bold=None,
    italic=None,
) -> None:
    """Insert a clickable external hyperlink run in a paragraph."""
    part = paragraph.part
    r_id = part.relate_to(url, "http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink", is_external=True)

    hyperlink = OxmlElement("w:hyperlink")
    hyperlink.set(qn("r:id"), r_id)

    run = OxmlElement("w:r")
    r_pr = OxmlElement("w:rPr")
    if font_name:
        r_fonts = OxmlElement("w:rFonts")
        r_fonts.set(qn("w:ascii"), font_name)
        r_fonts.set(qn("w:hAnsi"), font_name)
        r_pr.append(r_fonts)
    if font_size is not None:
        try:
            half_points = str(int(round(font_size.pt * 2)))
        except (AttributeError, TypeError, ValueError):
            half_points = ""
        if half_points:
            for tag in ("w:sz", "w:szCs"):
                node = OxmlElement(tag)
                node.set(qn("w:val"), half_points)
                r_pr.append(node)
    if bold:
        r_pr.append(OxmlElement("w:b"))
    if italic:
        r_pr.append(OxmlElement("w:i"))
    color = OxmlElement("w:color")
    color.set(qn("w:val"), "0563C1")
    r_pr.append(color)
    underline = OxmlElement("w:u")
    underline.set(qn("w:val"), "single")
    r_pr.append(underline)
    run.append(r_pr)

    text_el = OxmlElement("w:t")
    text_el.text = text
    run.append(text_el)
    hyperlink.append(run)
    paragraph._p.append(hyperlink)


def _cell_text_font(cell):
    """Font of the first text run, so a filled value matches that cell."""
    font_bold = None
    font_italic = None
    font_size = None
    font_name = None
    for para in cell.paragraphs:
        if any(_run_has_image(r) for r in para.runs):
            continue
        for run in para.runs:
            if (run.text or "").strip() or run.bold is not None or run.font.size is not None or run.font.name:
                font_bold = run.bold
                font_italic = run.italic
                font_size = run.font.size
                font_name = run.font.name
                return font_name, font_size, font_bold, font_italic
    return font_name, font_size, font_bold, font_italic


# Clear text paragraphs in a cell and write a blue underlined hyperlink (or plain text).
def _set_cell_hyperlink(cell, text: str, url: str) -> None:
    """Write a blue underlined hyperlink into a table cell, keeping that cell's font."""
    text = _normalize_text_value(text).strip()
    url = (url or "").strip()
    if not text:
        return

    font_name, font_size, font_bold, font_italic = _cell_text_font(cell)

    for para in cell.paragraphs:
        if any(_run_has_image(r) for r in para.runs):
            continue
        _clear_text_runs(para)

    para = cell.paragraphs[0]
    if url:
        _add_hyperlink_to_paragraph(
            para,
            text,
            url,
            font_name=font_name,
            font_size=font_size,
            bold=font_bold,
            italic=font_italic,
        )
    else:
        _set_cell_text(cell, text)


# Write approver name in italic Segoe Script for signature-table Name columns.
def _set_cell_signature_text(cell, value: str) -> None:
    """Write approver name into Signature column using italic script-style formatting."""
    value = _normalize_text_value(value).strip()
    if not value:
        return

    for para in cell.paragraphs:
        if any(_run_has_image(r) for r in para.runs):
            continue
        _clear_text_runs(para)

    target_para = cell.paragraphs[0]
    _reset_paragraph_formatting(target_para)
    _set_paragraph_left_align(target_para)
    run = target_para.add_run(value)
    run.italic = True
    run.font.name = "Segoe Script"


# Remove Word list numbering so auto-list serials do not duplicate user Sl. No. values.
def _remove_paragraph_numbering(para) -> None:
    """Remove Word auto-list numbering so literal serial text is not duplicated."""
    p_pr = para._element.get_or_add_pPr()
    num_pr = p_pr.find(qn("w:numPr"))
    if num_pr is not None:
        p_pr.remove(num_pr)


# Extract leading integer from a serial-number string like "1." or "2)".
def _sl_no_numeric_part(value: str) -> int | None:
    match = re.match(r"^(\d+)", (value or "").strip())
    return int(match.group(1)) if match else None


def _set_sl_no_cell_text(cell, value: str) -> None:
    """
    Write a serial number into col 0 without duplicating template list numbers.
    Skips when the cell already shows the same serial (1., 2., …).
    """
    value = _normalize_text_value(value).strip()
    if not value:
        return

    existing = ""
    for para in cell.paragraphs:
        text = para.text.strip()
        if text:
            existing = text
            break

    # Skip write when template already has the same serial number.
    if existing and looks_like_sl_no_value(existing) and looks_like_sl_no_value(value):
        if _sl_no_numeric_part(existing) == _sl_no_numeric_part(value):
            return

    for para in cell.paragraphs:
        if any(_run_has_image(r) for r in para.runs):
            continue
        _remove_paragraph_numbering(para)

    _set_cell_text(cell, value)


def _is_header_cell(cell) -> bool:
    """True when cell is located inside a header table."""
    try:
        for ancestor in cell._tc.iterancestors():
            if ancestor.tag.endswith("hdr"):
                return True
    except Exception:
        pass
    return False


def _para_has_numbering(para) -> bool:
    p_pr = para._element.find(qn("w:pPr"))
    return p_pr is not None and p_pr.find(qn("w:numPr")) is not None


def _delete_paragraph_element(para) -> None:
    element = para._element
    parent = element.getparent()
    if parent is not None:
        parent.remove(element)


def _extract_para_run_font(para):
    """Return (bold, italic, size, name) from the first styled run or paragraph mark."""
    font_bold = font_italic = font_size = font_name = None
    for run in para.runs:
        if not (
            (run.text or "").strip()
            or run.bold is not None
            or run.font.size is not None
            or run.font.name
        ):
            continue
        font_bold = run.bold
        font_italic = run.italic
        font_size = run.font.size
        font_name = run.font.name
        if font_size is not None or font_name:
            return font_bold, font_italic, font_size, font_name
    p_pr = para._element.find(qn("w:pPr"))
    if p_pr is not None:
        r_pr = p_pr.find(qn("w:rPr"))
        if r_pr is not None:
            sz = r_pr.find(qn("w:sz"))
            if sz is not None:
                try:
                    font_size = Pt(int(sz.get(qn("w:val")) or 0) / 2.0)
                except (TypeError, ValueError):
                    pass
            fonts = r_pr.find(qn("w:rFonts"))
            if fonts is not None:
                font_name = fonts.get(qn("w:ascii")) or fonts.get(qn("w:hAnsi")) or font_name
    return font_bold, font_italic, font_size, font_name


def _default_fill_font_size():
    """
    Body fill default when the template run has no explicit size.
    Requal/reval AHU originals are Arial 11 (sz=22). Never default to 12pt —
    that inflated the final PDF from ~25 pages to ~41.
    NEQ keeps 12 only when the active import source is NEQ.
    """
    try:
        if _active_is_neq():
            return Pt(12)
    except Exception:
        pass
    return Pt(11)


def _write_run_with_font(para, value: str, font_bold, font_italic, font_size, font_name) -> None:
    """Write text with explicit rPr so list bullets keep the same size as the label."""
    new_run = para.add_run(value)
    r_pr = new_run._element.get_or_add_rPr()
    try:
        if font_bold is not None:
            new_run.bold = font_bold
        if font_italic is not None:
            new_run.italic = font_italic
        if font_name:
            new_run.font.name = font_name
            fonts = r_pr.find(qn("w:rFonts"))
            if fonts is None:
                fonts = OxmlElement("w:rFonts")
                r_pr.insert(0, fonts)
            fonts.set(qn("w:ascii"), font_name)
            fonts.set(qn("w:hAnsi"), font_name)
            fonts.set(qn("w:cs"), font_name)
        # Prefer template size; fall back to Arial 11 for requal/reval (not 12).
        size = font_size if font_size is not None else _default_fill_font_size()
        new_run.font.size = size
        try:
            half = str(int(round(float(size.pt) * 2)))
        except (AttributeError, TypeError, ValueError):
            half = "22"
        for tag in ("w:sz", "w:szCs"):
            node = r_pr.find(qn(tag))
            if node is None:
                node = OxmlElement(tag)
                r_pr.append(node)
            node.set(qn("w:val"), half)
        # Keep paragraph mark size in sync so Word/LO don't fall back smaller.
        p_pr = para._element.get_or_add_pPr()
        p_r_pr = p_pr.find(qn("w:rPr"))
        if p_r_pr is None:
            p_r_pr = OxmlElement("w:rPr")
            p_pr.append(p_r_pr)
        for tag in ("w:sz", "w:szCs"):
            node = p_r_pr.find(qn(tag))
            if node is None:
                node = OxmlElement(tag)
                p_r_pr.append(node)
            node.set(qn("w:val"), half)
        if font_name:
            fonts = p_r_pr.find(qn("w:rFonts"))
            if fonts is None:
                fonts = OxmlElement("w:rFonts")
                p_r_pr.insert(0, fonts)
            fonts.set(qn("w:ascii"), font_name)
            fonts.set(qn("w:hAnsi"), font_name)
    except Exception:
        pass


def _sync_list_cell_fonts_to_label(cell) -> bool:
    """
    Make Word-list bullet lines the same font size as the lead-in label
    (e.g. 'Airflow should be:' vs 'From Positive pressure…').
    Also drop accidental duplicate bullet paragraphs from earlier repairs.
    """
    paras = [
        p for p in cell.paragraphs if not any(_run_has_image(r) for r in p.runs)
    ]
    if len(paras) < 2:
        return False
    changed = False
    # Remove consecutive duplicate listed paragraphs (same visible text).
    prev_txt = None
    for para in list(paras):
        if not _para_has_numbering(para):
            prev_txt = None
            continue
        txt = re.sub(r"\s+", " ", (para.text or "").strip()).lower()
        if txt and prev_txt == txt:
            _delete_paragraph_element(para)
            changed = True
            continue
        prev_txt = txt or prev_txt

    paras = [
        p for p in cell.paragraphs if not any(_run_has_image(r) for r in p.runs)
    ]
    listed = [p for p in paras if _para_has_numbering(p) and (p.text or "").strip()]
    if not listed:
        return changed
    label = next((p for p in paras if not _para_has_numbering(p) and (p.text or "").strip()), paras[0])
    font_bold, font_italic, font_size, font_name = _extract_para_run_font(label)
    if font_size is None:
        font_size = _default_fill_font_size()
    if not font_name:
        font_name = "Arial"

    def _run_needs_size(para) -> bool:
        for run in para.runs:
            if not (run.text or "").strip():
                continue
            r_pr = run._element.find(qn("w:rPr"))
            if r_pr is None or r_pr.find(qn("w:sz")) is None:
                return True
            try:
                cur = int(r_pr.find(qn("w:sz")).get(qn("w:val")) or 0)
            except (TypeError, ValueError):
                return True
            if cur < int(round(float(font_size.pt) * 2)):
                return True
        return False

    # Keep the label itself at the same explicit size.
    if (label.text or "").strip() and _run_needs_size(label):
        text = (label.text or "").strip()
        _clear_text_runs(label)
        _write_run_with_font(label, text, font_bold, font_italic, font_size, font_name)
        changed = True

    for para in listed:
        text = (para.text or "").strip()
        if not text or not _run_needs_size(para):
            continue
        _clear_text_runs(para)
        _write_run_with_font(para, text, font_bold, font_italic, font_size, font_name)
        changed = True
    return changed


def _split_value_for_list_paras(value: str, para_count: int) -> list[str]:
    """
    Map a flat/newline cell value onto existing paragraphs so Word list bullets
    (Acceptance Criteria row 13) stay on their original numbered paragraphs.
    """
    if para_count <= 1:
        return [value]
    raw_lines = [ln.strip() for ln in re.split(r"\r?\n+", value) if ln.strip()]
    if len(raw_lines) == para_count:
        return raw_lines
    if len(raw_lines) > para_count:
        head = raw_lines[: para_count - 1]
        tail = " ".join(raw_lines[para_count - 1 :])
        return head + [tail]
    # Single mashed line: "Label: item1. item2." (space after "." may be missing).
    sentence_split = re.compile(r"(?<=\.)\s*(?=[A-Z])")
    if len(raw_lines) <= 1 and para_count >= 2:
        text = raw_lines[0] if raw_lines else value
        if ":" in text:
            idx = text.find(":")
            first = text[: idx + 1].strip()
            rest = text[idx + 1 :].strip()
            segs = [s.strip() for s in sentence_split.split(rest) if s.strip()]
            if len(segs) == para_count - 1:
                return [first] + segs
            if len(segs) > para_count - 1:
                return [first] + segs[: para_count - 2] + [
                    " ".join(segs[para_count - 2 :])
                ]
            if segs:
                parts = [first] + segs
                while len(parts) < para_count:
                    parts.append("")
                return parts[:para_count]
        segs = [s.strip() for s in sentence_split.split(text) if s.strip()]
        if len(segs) == para_count:
            return segs
    parts = raw_lines[:] if raw_lines else ([value] if value else [])
    while len(parts) < para_count:
        parts.append("")
    return parts[:para_count]


def _set_cell_text(cell, value: str) -> None:
    """
    Write value into a table cell without destroying logo/image runs.
    Preserves the first text run's font so filled cells match the original DOCX look.

    Multi-paragraph list cells (Word numPr bullets) keep their structure: lines are
    mapped onto existing paragraphs. Empty leftover list paragraphs are removed so
    orphan bullet dots do not appear in Observation / Result.
    """
    value = _normalize_text_value(value).strip()
    text_paras = []
    font_bold = None
    font_italic = None
    font_size = None
    font_name = None

    is_header = _is_header_cell(cell)

    for para in cell.paragraphs:
        if any(_run_has_image(r) for r in para.runs):
            continue
        text_paras.append(para)

    if text_paras:
        # Prefer the non-list label font ("Airflow should be:") for bullet lines.
        label = next(
            (p for p in text_paras if not _para_has_numbering(p) and (p.text or "").strip()),
            text_paras[0],
        )
        font_bold, font_italic, font_size, font_name = _extract_para_run_font(label)

    if is_header or any(_para_has_numbering(p) for p in text_paras):
        if not font_name:
            font_name = "Arial"
        # Fill missing size only — never bump Arial 11 up to 12.
        if font_size is None:
            font_size = _default_fill_font_size()
    elif font_size is None:
        # Body description cells often inherit 11pt from the style with no run sz.
        font_size = _default_fill_font_size()
        if not font_name:
            font_name = "Arial"

    if not text_paras:
        text_paras = [cell.add_paragraph()]

    has_list = any(_para_has_numbering(p) for p in text_paras)

    # Preserve template list layout: write one line per existing paragraph.
    if has_list and len(text_paras) > 1:
        lines = _split_value_for_list_paras(value, len(text_paras)) if value else [""] * len(text_paras)
        for para, line in zip(text_paras, lines):
            _clear_text_runs(para)
            if line:
                _write_run_with_font(
                    para, line, font_bold, font_italic, font_size, font_name
                )
        # Drop trailing empty list paragraphs that would render as lone bullets.
        for para in list(text_paras[1:]):
            if not (para.text or "").strip() and _para_has_numbering(para):
                _delete_paragraph_element(para)
        _sync_list_cell_fonts_to_label(cell)
        return

    for para in text_paras:
        _clear_text_runs(para)

    target_para = text_paras[0]
    # Keep existing paragraph alignment/indent from the template — do not force left/reset.
    if value:
        _write_run_with_font(
            target_para, value, font_bold, font_italic, font_size, font_name
        )

    # Never leave emptied list paragraphs behind (orphan • in Observation column).
    for para in list(text_paras[1:]):
        _clear_text_runs(para)
        if not (para.text or "").strip() and _para_has_numbering(para):
            _delete_paragraph_element(para)
        else:
            _remove_paragraph_numbering(para)


def _fill_colon_label_cell(cell, value: str) -> None:
    """
    Fill colon-label cells like 'EFFECTIVE DATE:' without duplicating the label.
    Only modifies the paragraph that contains the colon label; clears other text paragraphs.
    """
    value = _normalize_text_value(value)
    filled = False
    for para in cell.paragraphs:
        text = para.text.strip()
        if not text:
            continue
        text = dedupe_colon_label(text)
        if PARA_COLON_BLANK_RE.search(text) or re.search(r"EFFECTIVE\s+DATE\s*:", text, re.I):
            _clear_text_runs(para)
            label = re.sub(r":\s*$", "", text).strip()
            if re.match(re.escape(label) + r"\s*:\s*", value, re.I):
                para.add_run(value)
            else:
                para.add_run(f"{label}: {value}")
            filled = True
            break
    if filled:
        for para in cell.paragraphs:
            if not para.text.strip() and not any(_run_has_image(r) for r in para.runs):
                _clear_text_runs(para)
        return
    _set_cell_text(cell, value)


# Render Yes/No choice as checked/unchecked box symbols in a table cell.
def _fill_yes_no_cell(cell, choice: str) -> None:
    """Write Yes/No selection as 'Yes ☑  No ☐' style marks in the cell."""
    choice = _normalize_text_value(choice).strip().lower()
    if choice in ("yes", "1", "true", "on"):
        text = "Yes ☑   No ☐"
    elif choice in ("no", "0", "false", "off"):
        text = "Yes ☐   No ☑"
    else:
        text = "Yes ☐   No ☐"
    _set_cell_text(cell, text)


def _is_fixed_header_label(text: str) -> bool:
    """True for letterhead labels that must stay, such as Equipment ID. No."""
    label = re.sub(r"\s+", " ", (text or "").strip().lower())
    label = label.rstrip(":").strip()
    if not label:
        return False
    if label in {
        "title",
        "equipment name",
        "equipment id. no",
        "equipment id no",
        "document number",
        "document no",
        "reference protocol no",
    }:
        return True
    return label.startswith("equipment id") or label.startswith("reference protocol")


def _next_distinct_header_cell(cells, start_index):
    """The next cell that is not the same merged cell."""
    if start_index >= len(cells):
        return None
    current_id = id(cells[start_index]._tc)
    for nxt in cells[start_index + 1 :]:
        if id(nxt._tc) != current_id:
            return nxt
    return None


# Map location string header_0_row_2_col_2 to the matching header table cell.
def _resolve_header_cell(doc, location: str):
    """
    Resolve header_0_row_2_col_2 to a header table cell.

    A merged label such as Equipment ID. No. occupies two grid columns.
    A value stored at the second of those columns must be written in the
    empty cell to the right, not over the label.
    """
    match = re.match(r"header_(\d+)_row_(\d+)_col_(\d+)", location)
    if not match:
        return None
    section_index = int(match.group(1))
    row_index = int(match.group(2))
    col_index = int(match.group(3))
    if section_index >= len(doc.sections):
        return None
    header = doc.sections[section_index].header
    if not header.tables:
        return None
    table = header.tables[0]
    try:
        cells = table.rows[row_index].cells
    except IndexError:
        return None
    if col_index >= len(cells):
        return None
    cell = cells[col_index]
    inside_merged_label = col_index > 0 and cell._tc is cells[col_index - 1]._tc
    if inside_merged_label or _is_fixed_header_label(cell.text):
        nxt = _next_distinct_header_cell(cells, col_index)
        if nxt is not None and not _is_fixed_header_label(nxt.text):
            return nxt
        if _is_fixed_header_label(cell.text):
            return None
    return cell


# Safe lookup of a body paragraph by enumerate(doc.paragraphs) index.
def _body_paragraph(doc, para_index: int):
    paragraphs = doc.paragraphs
    if para_index < 0 or para_index >= len(paragraphs):
        return None
    return paragraphs[para_index]


def _is_verification_pid_heading_text(text: str) -> bool:
    return bool(
        re.search(
            r"verification\s+with\s+the\s+current\s+p\s*&?\s*id",
            text or "",
            re.I,
        )
    )


def _write_arial_11_run(para, text: str, *, bold: bool) -> None:
    """Add an Arial 11 run with explicit bold/regular for LibreOffice PDF."""
    run = para.add_run(text)
    try:
        run.bold = bold
        run.font.name = "Arial"
        run.font.size = Pt(11)
        r_pr = run._element.get_or_add_rPr()
        for tag in ("w:b", "w:bCs"):
            for node in list(r_pr.findall(qn(tag))):
                r_pr.remove(node)
        if bold:
            r_pr.append(OxmlElement("w:b"))
            r_pr.append(OxmlElement("w:bCs"))
        for tag in ("w:sz", "w:szCs"):
            node = r_pr.find(qn(tag))
            if node is None:
                node = OxmlElement(tag)
                r_pr.append(node)
            node.set(qn("w:val"), "22")
        fonts = r_pr.find(qn("w:rFonts"))
        if fonts is None:
            fonts = OxmlElement("w:rFonts")
            r_pr.insert(0, fonts)
        fonts.set(qn("w:ascii"), "Arial")
        fonts.set(qn("w:hAnsi"), "Arial")
    except Exception:
        pass


def _fill_section_heading_body_notes(doc, heading_para_index: int, value: str) -> None:
    """
    Write para_N_body notes without wiping the section label.

    8.4 becomes: "Verification with the current P & ID: <notes>"
    (label bold, notes regular) — matches original key+value layout.
    """
    value = _normalize_text_value(value).strip()
    if not value:
        return
    # Notes field sometimes already includes the label — keep notes only once.
    value = re.sub(
        r"^(?:verification\s+with\s+the\s+current\s+p\s*&?\s*id\s*:?\s*)+",
        "",
        value,
        flags=re.I,
    ).strip()
    if not value:
        return
    heading = _body_paragraph(doc, heading_para_index)
    if heading is None:
        return

    # Always use the canonical label — templates can be polluted with a doubled
    # "Verification…: Verification…:" from an earlier append-colon bug.
    label = "Verification with the current P & ID:"

    _clear_text_runs(heading)
    _write_arial_11_run(heading, label + " ", bold=True)
    _write_arial_11_run(heading, value, bold=False)


_YES_NO_BOX_RE = re.compile(
    r"[\u2611\u2610\u2612☑✓☐☒□■\u25a1\uF0A7\uF0A8\uF0FE\uF0FD\uF070\uF0FC\uF06F\uF0A2]"
)


# Toggle Yes/No checkbox symbols on inline paragraph radio lines.
def _fill_paragraph_yes_no(para, choice: str) -> None:
    """Mark Yes or No on inline paragraph radio lines like 'Training imparted: Yes □ No □'."""
    choice = _normalize_text_value(choice).strip().lower()
    if choice not in ("yes", "no"):
        return
    yes_checked = choice == "yes"
    yes_mark = "\u2611" if yes_checked else "\u2610"
    no_mark = "\u2610" if yes_checked else "\u2611"

    # Prefer swapping the first two checkbox glyphs in-place (Word often splits Yes / □).
    seen = 0
    for run in para.runs:
        text = run.text or ""
        if not text or not _YES_NO_BOX_RE.search(text):
            continue
        chars: list[str] = []
        changed = False
        for char in text:
            if _YES_NO_BOX_RE.match(char) and seen < 2:
                chars.append(yes_mark if seen == 0 else no_mark)
                seen += 1
                changed = True
            else:
                chars.append(char)
        if changed:
            run.text = "".join(chars)
    if seen >= 2:
        return

    # Fallback: rebuild the whole line with explicit Yes/No marks.
    label = re.split(r"\s*:\s*", para.text or "", maxsplit=1)[0].strip() or "Training imparted"
    new_text = f"{label}: Yes {yes_mark}  No {no_mark}"
    _clear_text_runs(para)
    para.add_run(new_text)


def _remove_drawing_runs(para) -> None:
    """Remove empty Word drawing/pict checkbox images so only the checked glyph shows."""
    for run in list(para.runs):
        if _run_has_image(run):
            parent = run._element.getparent()
            if parent is not None:
                parent.remove(run._element)


# Mark the first text run in a checklist paragraph as checked without duplicating label text.
def _fill_paragraph_checkbox(para) -> None:
    """Mark checklist paragraph checked via symbol run — do not duplicate label text."""
    # Scope checklist lines use inline drawing boxes; leave them and the PDF looks unchecked.
    _remove_drawing_runs(para)
    for run in para.runs:
        if _run_has_image(run):
            continue
        text = run.text
        if not text.strip():
            continue
        stripped = re.sub(r"^[\u2611☑✓☐□\u25a1\u2610]\s*", "", text)
        run.text = f"☑ {stripped.lstrip()}"
        return
    para.add_run("☑ ")


# Replace the Nth underscore/dot blank in a paragraph with the user value.
def _fill_paragraph_blank_by_index(para, blank_index: int, value: str) -> None:
    """Replace the Nth blank placeholder in a paragraph with the user value."""
    value = _normalize_text_value(value)
    matches = list(PARA_BLANK_RE.finditer(para.text))
    if blank_index >= len(matches):
        return
    match = matches[blank_index]
    new_text = para.text[: match.start()] + value + para.text[match.end() :]
    _clear_text_runs(para)
    para.add_run(new_text)
    _fix_overhanging_list_indent(para)


def _fix_overhanging_list_indent(para) -> None:
    """
    List Paragraphs in some Lupin templates use hanging > left (first line starts
    left of the margin). That clips 'Preventive…' into 'tive…' and stacks glyphs
    beside section number 8.2. Expand left indent so hanging fits.
    """
    p_pr = para._element.pPr
    if p_pr is None:
        return
    ind = p_pr.find(qn("w:ind"))
    if ind is None:
        return
    try:
        left = int(ind.get(qn("w:left")) or ind.get(qn("w:start")) or 0)
        hanging = int(ind.get(qn("w:hanging")) or 0)
    except (TypeError, ValueError):
        return
    if hanging <= left:
        return
    # Keep hanging (room for auto number 8.2); push body text right so it stays on-page.
    ind.set(qn("w:left"), str(hanging))


def _append_colon_blank_value(para, value: str) -> bool:
    """
    Append ' {value}' after a trailing colon without wiping existing runs.
    Preserves bold / character spacing so List Paragraph labels stay intact.
    """
    value = _normalize_text_value(value).strip()
    if not value:
        return False

    full_para_text = (para.text or "").strip()
    norm_val = re.sub(r"[\s:]+", " ", value).strip().lower()
    norm_para = re.sub(r"[\s:]+", " ", full_para_text).strip().lower()

    # If the value equals the paragraph heading/label, no separate answer was typed.
    # Preserve the label as-is without appending or duplicating.
    if norm_val == norm_para:
        return True

    # If the typed value starts with the heading label (e.g. "Label: typed text"),
    # strip the duplicated prefix so it does not repeat.
    for prefix in (full_para_text, full_para_text.rstrip(":").strip()):
        if prefix and value.lower().startswith(prefix.lower()):
            val_after = value[len(prefix):].lstrip(" :")
            if not val_after:
                return True
            value = val_after
            break

    runs = list(para.runs)
    for run in reversed(runs):
        text = run.text or ""
        if not text.strip():
            continue
        if text.rstrip().endswith(":"):
            # Keep trailing spaces before colon intact; add value after colon.
            run.text = re.sub(r":\s*$", f": {value}", text)
            _fix_overhanging_list_indent(para)
            return True
        break

    full = full_para_text
    if not full.endswith(":"):
        return False

    # Colon may be its own run — append a new value run with prior formatting.
    style_run = runs[-1] if runs else None
    new_run = para.add_run(f" {value}")
    if style_run is not None:
        try:
            if style_run.bold is not None:
                new_run.bold = style_run.bold
            if style_run.italic is not None:
                new_run.italic = style_run.italic
            if style_run.font.size is not None:
                new_run.font.size = style_run.font.size
            if style_run.font.name:
                new_run.font.name = style_run.font.name
        except Exception:
            pass
    _fix_overhanging_list_indent(para)
    return True


# Replace the first blank placeholder or trailing colon blank in a paragraph.
def _fill_paragraph_blank(para, value: str) -> None:
    """Replace the first blank placeholder in a paragraph."""
    value = _normalize_text_value(value)
    # Att14 titles "(IOQ) REPORT FOR … MACHINE" / Identification are bold in Word —
    # keep that when rewriting runs after fill.
    was_bold = any(
        (r.bold is True) for r in para.runs if (r.text or "").strip() and not _run_has_image(r)
    )
    if not was_bold:
        # Whole-paragraph bold via style / rPr on first non-empty run.
        for r in para.runs:
            if (r.text or "").strip() and not _run_has_image(r):
                try:
                    rpr = r._element.find(qn("w:rPr"))
                    if rpr is not None and rpr.find(qn("w:b")) is not None:
                        was_bold = True
                except Exception:
                    pass
                break

    for run in para.runs:
        if _run_has_image(run):
            continue
        if PARA_BLANK_RE.search(run.text):
            run.text = PARA_BLANK_RE.sub(value, run.text, count=1)
            if was_bold:
                run.bold = True
            _fix_overhanging_list_indent(para)
            return

    full_text = para.text
    if PARA_BLANK_RE.search(full_text):
        new_text = PARA_BLANK_RE.sub(value, full_text, count=1)
        if para.runs:
            _clear_text_runs(para)
            new_run = para.add_run(new_text)
            if was_bold:
                new_run.bold = True
        _fix_overhanging_list_indent(para)
        return

    if PARA_COLON_BLANK_RE.search(full_text.strip()):
        norm_val = re.sub(r"[\s:]+", " ", value).strip().lower()
        norm_para = re.sub(r"[\s:]+", " ", full_text).strip().lower()
        if norm_val == norm_para:
            return
        if _append_colon_blank_value(para, value):
            return
        new_text = re.sub(r":\s*$", f": {value}", full_text.strip())
        _clear_text_runs(para)
        new_run = para.add_run(new_text)
        if was_bold:
            new_run.bold = True
        _fix_overhanging_list_indent(para)


def _resolve_body_table_cell(doc, location: str):
    """Return (table_index, row_index, col_index, cell) for table_T_row_R_col_C, else None."""
    if not location.startswith("table") or "_col_" not in location:
        return None
    parts = location.split("_")
    try:
        t = int(parts[1])
        r = int(parts[3])
        c = int(parts[5])
        cell = doc.tables[t].rows[r].cells[c]
        return t, r, c, cell
    except (IndexError, ValueError, KeyError):
        return None


def _normalize_header_compare(text: str) -> str:
    return re.sub(r"\s+", " ", (text or "").strip().lower())


def _row_unique_cell_texts(row) -> list[str]:
    texts: list[str] = []
    seen: set[int] = set()
    for cell in row.cells:
        tc_id = id(cell._tc)
        if tc_id in seen:
            continue
        seen.add(tc_id)
        text = (cell.text or "").strip()
        if text:
            texts.append(text)
    return texts


def _is_repeated_column_header_row(row, header_texts: list[str]) -> bool:
    """True when a body row repeats the table's column header labels (page-break / copy)."""
    if not header_texts or len(header_texts) < 3:
        return False
    matches = 0
    compared = 0
    seen: set[int] = set()
    for idx, cell in enumerate(row.cells):
        tc_id = id(cell._tc)
        if tc_id in seen:
            continue
        seen.add(tc_id)
        header = header_texts[idx] if idx < len(header_texts) else ""
        if not header:
            continue
        compared += 1
        if _normalize_header_compare(cell.text) == _normalize_header_compare(header):
            matches += 1
    return compared >= 3 and matches >= max(3, int(compared * 0.6))


def _is_equipment_section_banner_row(row) -> bool:
    """True for full-width banners like 'AHU: SK/ENG/AHU-005:' / 'Dust Extractor:…'."""
    texts = []
    seen = set()
    for cell in row.cells:
        tc_id = id(cell._tc)
        if tc_id in seen:
            continue
        seen.add(tc_id)
        text = (cell.text or "").strip()
        if text:
            texts.append(text)
    if not texts:
        return False
    # A list-of-attachment / 2-col label|value row can have only the left
    # label filled ("Equipment location", "Area Classification", …).
    # Those are fill targets, not AHU/Dust Extractor section banners.
    if len(seen) >= 2 and len(texts) < len(seen):
        return False
    unique = {_normalize_header_compare(t) for t in texts}
    if len(unique) != 1:
        return False
    text = texts[0]
    if len(text) > 220:
        return False
    lower = text.lower()
    # Signature / verification / approval / date cells are fillable fields, never section banners.
    if any(k in lower for k in ("verified", "sign and date", "signature", "signed", "date", "performed", "checked", "approved", "complied")):
        return False
    if re.match(
        r"^(ahu|dust\s*extractor|equipment|area|room|system)\b",
        lower,
    ):
        return True
    # Merged / single-label section title with a colon.
    return ":" in text and (len(seen) <= 2 or len(texts) == 1)


def _is_structural_table_row(doc, table_index: int, row_index: int) -> bool:
    """Section banners and repeated header rows must never receive fill values."""
    try:
        table = doc.tables[table_index]
        row = table.rows[row_index]
    except (IndexError, ValueError, KeyError):
        return False
    # Verification and signature rows must always receive fill values.
    row_text = " ".join(c.text for c in row.cells).lower()
    if any(k in row_text for k in ("verified", "sign and date", "signature", "signed by", "sign & date")):
        return False
    if row_index == 0:
        if len(row.cells) == 2 and not is_two_col_grid_table(table) and not is_two_col_header_row(row.cells, table.rows):
            return False
        return True
    header_texts = [c.text.strip() for c in table.rows[0].cells] if table.rows else []
    if _is_repeated_column_header_row(row, header_texts):
        return True
    if _is_equipment_section_banner_row(row):
        return True
    return False


def _value_echoes_column_header(doc, location: str, value: str) -> bool:
    """
    True when a data-row value is just that column's header text (or 'N. Header').
    Covers Sl. No. / List of Attachment corruption from TOC label→value upgrades.
    """
    resolved = _resolve_body_table_cell(doc, location)
    if resolved is None:
        return False
    _t, r, c, _cell = resolved
    if r < 1:
        return False
    val = (value or "").strip()
    if not val:
        return False
    try:
        header = doc.tables[_t].rows[0].cells[c].text.strip()
    except (IndexError, ValueError, KeyError):
        return False
    if not header:
        return False
    if _normalize_header_compare(val) == _normalize_header_compare(header):
        return True
    # e.g. "1. Sl. No." after serial was concatenated with the header label
    return re.fullmatch(
        rf"\d+\.?\s*{re.escape(header)}",
        val,
        flags=re.IGNORECASE,
    ) is not None


def _repair_header_echo_value(doc, location: str, value: str) -> str:
    """Replace header-echo corruption with the original template cell text when available."""
    if not _value_echoes_column_header(doc, location, value):
        return value
    resolved = _resolve_body_table_cell(doc, location)
    if resolved is None:
        return value
    _t, _r, _c, cell = resolved
    original = (cell.text or "").strip()
    # Template already has real content (e.g. serial "1") — restore it.
    if original and _normalize_header_compare(original) != _normalize_header_compare(value):
        return original
    # Template cell is empty or also the header — skip the write entirely.
    return ""


def _following_single_value_box(p_el):
    """The 1-cell table that sits under a New Equipment section heading, if any."""
    nxt = p_el.getnext()
    while nxt is not None and nxt.tag == qn("w:p") and not _para_plain_text(nxt).strip():
        nxt = nxt.getnext()
    if nxt is None or nxt.tag != qn("w:tbl"):
        return None
    rows = nxt.findall(qn("w:tr"))
    if len(rows) != 1:
        return None
    cells = rows[0].findall(qn("w:tc"))
    if len(cells) != 1:
        return None
    return cells[0]


def _is_neq_boxed_heading_text(text: str) -> bool:
    """'5.0 Review and Discussions:' / '6.0 Conclusion:' — the answer belongs in the box."""
    cleaned = re.sub(r"\s+", " ", text or "").strip()
    return bool(re.match(r"^\d+(?:\.\d+)+\s+\S.*:\s*$", cleaned))


def _is_neq_value_box_paragraph(p_el) -> bool:
    """Paragraph inside the single cell under a numbered colon heading."""
    tc = p_el.getparent()
    if tc is None or not str(tc.tag).endswith("}tc"):
        return False
    tr = tc.getparent()
    tbl = tr.getparent() if tr is not None else None
    if tbl is None or not str(tbl.tag).endswith("}tbl"):
        return False
    if len(tbl.findall(qn("w:tr"))) != 1 or len(tr.findall(qn("w:tc"))) != 1:
        return False
    prev = tbl.getprevious()
    while prev is not None and str(prev.tag).endswith("}p") and not _para_plain_text(prev).strip():
        prev = prev.getprevious()
    if prev is None or not str(prev.tag).endswith("}p"):
        return False
    return _is_neq_boxed_heading_text(_para_plain_text(prev))


def _write_neq_box_value(tc, value: str) -> None:
    """Put the answer in the box: Arial 11, regular — the heading above stays bold."""
    value = _normalize_text_value(value).strip()
    p_el = tc.find(qn("w:p"))
    if p_el is None:
        p_el = OxmlElement("w:p")
        tc.append(p_el)
    for child in list(p_el):
        if child.tag != qn("w:pPr"):
            p_el.remove(child)
    p_pr = p_el.find(qn("w:pPr"))
    if p_pr is None:
        p_pr = OxmlElement("w:pPr")
        p_el.insert(0, p_pr)
    mark = p_pr.find(qn("w:rPr"))
    if mark is None:
        mark = OxmlElement("w:rPr")
        p_pr.append(mark)
    _force_arial_11_on_rpr(mark, bold=False, force_size=True)
    if not value:
        return
    run = OxmlElement("w:r")
    r_pr = OxmlElement("w:rPr")
    _force_arial_11_on_rpr(r_pr, bold=False, force_size=True)
    run.append(r_pr)
    t_el = OxmlElement("w:t")
    if value[:1].isspace() or value[-1:].isspace():
        t_el.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
    t_el.text = value
    run.append(t_el)
    p_el.append(run)


def _fill_neq_heading_value_into_box(para, value: str) -> bool:
    """
    New Equipment only. '5.0 Review and Discussions:' and '6.0 Conclusion:'
    keep their heading text. The typed answer goes in the empty box under them.
    """
    if not _active_is_neq():
        return False
    heading = re.sub(r"\s+", " ", (para.text or "")).strip()
    if not _is_neq_boxed_heading_text(heading):
        return False
    tc = _following_single_value_box(para._element)
    if tc is None:
        return False
    answer = _normalize_text_value(value).strip()
    if answer.lower().startswith(heading.lower()):
        answer = answer[len(heading):].strip()
    _write_neq_box_value(tc, answer)
    return True


def _paragraph_style_id(p_el) -> str:
    p_pr = p_el.find(qn("w:pPr"))
    if p_pr is None:
        return ""
    ps = p_pr.find(qn("w:pStyle"))
    if ps is None:
        return ""
    return (ps.get(qn("w:val")) or "").strip()


def _get_sdt_toc_paragraphs(doc) -> list:
    """Return paragraphs inside a real TOC SDT (content control containing TOC lines)."""
    try:
        sdts = doc._element.xpath("//w:sdt")
    except Exception:
        return []
    for sdt in sdts:
        sdt_pr = sdt.find(qn("w:sdtPr"))
        is_toc = False
        if sdt_pr is not None:
            gallery = sdt_pr.find(f".//{qn('w:docPartGallery')}")
            if gallery is not None and "toc" in (gallery.get(qn("w:val")) or "").lower():
                is_toc = True
            tag = sdt_pr.find(qn("w:tag"))
            if tag is not None and "toc" in (tag.get(qn("w:val")) or "").lower():
                is_toc = True
        paras = sdt.findall(f".//{qn('w:p')}")
        if is_toc and paras:
            return paras
        # If no explicit tag/gallery, check if it contains multiple TOC paragraphs (>= 3)
        if len(paras) >= 3:
            for p in paras:
                p_pr = p.find(qn("w:pPr"))
                if p_pr is not None:
                    p_style = p_pr.find(qn("w:pStyle"))
                    if p_style is not None and "toc" in (p_style.get(qn("w:val")) or "").lower():
                        return paras
    return []


def _body_toc_paragraph_elements(doc) -> list:
    """
    Body TOC lines in fill-form order (toc_sdt_0, toc_sdt_1, ...).

    New Equipment IOQ protocols store the contents list as normal paragraphs
    (style TOC1), not inside a Word content control. The form still names
    those rows toc_sdt_N. Updating only w:sdt paragraphs left the typed
    page numbers in the database and the old digits in the document.
    """
    sdt_paras = _get_sdt_toc_paragraphs(doc)
    # If the document has content-control TOC entries (like Requalification),
    # body paragraphs are never TOC rows!
    if sdt_paras:
        return []

    found = []
    for child in list(doc.element.body):
        if child.tag != qn("w:p"):
            continue
        style = _paragraph_style_id(child).lower().replace(" ", "")
        # toc 3 is a body heading style on Att14, not a contents row.
        if style in {"toc3"}:
            continue
        title, page = _parse_toc_title_from_element(child)
        if not title or not page:
            continue
        # Page must be 1 to 4 clean digits (not trailing digits of doc codes like SK/IOQ/16-001)
        if not re.fullmatch(r"\d{1,4}", page.strip()):
            continue
        raw = ""
        for node in child.iter():
            if node.tag == qn("w:t") and node.text:
                raw += node.text
            elif node.tag == qn("w:tab"):
                raw += "\t"
        raw_upper = raw.upper()
        # Never match body reference lines
        if any(bad in raw_upper for bad in ("SK/", "SKM/", "QUALIFICATION DOCUMENT", "REQUALIFICATION DOCUMENT")):
            continue
        if style.startswith("toc"):
            found.append(child)
            continue
        # Must look like a real TOC entry (e.g. "1.0 OBJECTIVE ... 3" or numbered section)
        if _para_text_looks_like_toc_entry(raw) or re.match(r"^\d+(\.\d+)*\s+[A-Za-z]", title):
            if "\t" in raw or re.search(r"[\.\u2026]{3,}", raw):
                found.append(child)
    return found


def _toc_sdt_target_paragraph(doc, index: int):
    """Paragraph for toc_sdt_{index}: content-control row first, body TOC row otherwise."""
    sdt_paras = _get_sdt_toc_paragraphs(doc)
    if sdt_paras and 0 <= index < len(sdt_paras):
        return sdt_paras[index]
    body = _body_toc_paragraph_elements(doc)
    if 0 <= index < len(body):
        return body[index]
    return None


def _reapply_preserved_toc_pages(doc, field_values) -> int:
    """
    Write the TOC page digits from the fill form again after layout/realign.

    New Equipment preview and final download must show the numbers the user
    typed. An earlier fill only updated content-control paragraphs, so body
    TOC1 rows kept the template digits. Re-applying here is the last write.
    """
    if not field_values:
        return 0
    applied = 0
    for field in field_values:
        if not isinstance(field, dict):
            continue
        location = str(field.get("location") or "")
        value = str(field.get("value") or "").strip()
        if not value or not re.fullmatch(r"\d+", value):
            continue
        is_toc_page = (
            (location.startswith("toc_sdt_") and not location.endswith("_title"))
            or (
                location.startswith("table_")
                and "_toc_" in location
                and not location.endswith("_title")
            )
        )
        if not is_toc_page:
            continue
        before = None
        try:
            if location.startswith("toc_sdt_"):
                m = re.match(r"^toc_sdt_(\d+)$", location)
                if not m:
                    continue
                p_el = _toc_sdt_target_paragraph(doc, int(m.group(1)))
                if p_el is not None:
                    before = _parse_toc_title_from_element(p_el)[1]
            _apply_single_field(
                doc,
                location,
                value,
                False,
                None,
                field.get("type") or "toc_entry",
            )
            if location.startswith("toc_sdt_"):
                m = re.match(r"^toc_sdt_(\d+)$", location)
                p_el = _toc_sdt_target_paragraph(doc, int(m.group(1))) if m else None
                after = _parse_toc_title_from_element(p_el)[1] if p_el is not None else ""
                if after == value:
                    applied += 1
            else:
                applied += 1
        except Exception:
            continue
        _ = before
    return applied


def _clone_table_row_for_growth(table):
    """Clone the last data row of a table to add a new empty row matching style/widths."""
    if not table.rows:
        return None
    try:
        from neq_docx_save import _row_is_matrix_section_banner
    except Exception:
        _row_is_matrix_section_banner = lambda r: False

    template_tr = table.rows[-1]._tr
    for row in reversed(table.rows):
        if not _row_is_matrix_section_banner(row):
            template_tr = row._tr
            break
    new_tr = deepcopy(template_tr)
    for tc in new_tr.xpath(".//w:tc"):
        for p in tc.xpath(".//w:p"):
            for r in list(p.xpath(".//w:r")):
                p.remove(r)
    tr_pr = new_tr.find(qn("w:trPr"))
    if tr_pr is not None:
        tbl_header = tr_pr.find(qn("w:tblHeader"))
        if tbl_header is not None:
            tr_pr.remove(tbl_header)
    table._tbl.append(new_tr)
    return new_tr


def _ensure_table_rows_for_fields(doc, fields: list[dict]) -> int:
    """Ensure tables have enough rows to accommodate all table_t_row_r_col_c locations."""
    max_row_by_table: dict[int, int] = {}
    for f in (fields or []):
        loc = str((f or {}).get("location") or "")
        if loc.startswith("table_") and "_col_" in loc:
            parts = loc.split("_")
            try:
                t = int(parts[1])
                r = int(parts[3])
                max_row_by_table[t] = max(max_row_by_table.get(t, -1), r)
            except (IndexError, ValueError):
                continue

    added = 0
    for t_idx, max_r in max_row_by_table.items():
        if t_idx < len(doc.tables):
            table = doc.tables[t_idx]
            while len(table.rows) <= max_r:
                _clone_table_row_for_growth(table)
                added += 1
    return added


def _apply_single_field(
    doc,
    location: str,
    value: str,
    locked: bool,
    attachment_links: dict | None = None,
    field_type: str | None = None,
) -> None:
    """Apply one parsed field location to the open Document (body or header)."""
    normalized_type = str(field_type or "")
    is_toc_update = location.startswith("toc_sdt_") or (location.startswith("table_") and "_toc_" in location)
    # Locked cells keep the original DOCX text — never overwrite pre-filled content.
    # Exception: if a table cell in the document is currently empty (e.g. freshly cloned row), allow writing.
    if locked and normalized_type != "table_sl_no" and not is_toc_update:
        is_empty_table_cell = False
        if location.startswith("table_") and "_col_" in location:
            try:
                parts_check = location.split("_")
                t_c = int(parts_check[1])
                r_c = int(parts_check[3])
                c_c = int(parts_check[5])
                if t_c < len(doc.tables) and r_c < len(doc.tables[t_c].rows):
                    row_c = doc.tables[t_c].rows[r_c]
                    if c_c < len(row_c.cells) and not (row_c.cells[c_c].text or "").strip():
                        is_empty_table_cell = True
            except Exception:
                pass
        if not is_empty_table_cell:
            return
    if value is None or str(value).strip() == "":
        return

    value = _normalize_text_value(value)
    # Never overwrite real cell text with a column-header echo (Sl. No. / List of Attachment / …).
    if location.startswith("table") and "_col_" in location:
        value = _repair_header_echo_value(doc, location, value)
        if not str(value).strip():
            return

    parts = location.split("_")

    try:
        if location.startswith("toc_sdt_"):
            m_sdt_title = re.match(r"^toc_sdt_(\d+)_title$", location)
            m_sdt = re.match(r"^toc_sdt_(\d+)$", location)
            sdt_idx = None
            if m_sdt_title:
                sdt_idx = int(m_sdt_title.group(1))
            elif m_sdt:
                sdt_idx = int(m_sdt.group(1))
            p_el = _toc_sdt_target_paragraph(doc, sdt_idx) if sdt_idx is not None else None
            if p_el is not None and m_sdt_title:
                _title, page = _parse_toc_title_from_element(p_el)
                # Keep the page digit; only the section title changed.
                if page:
                    _rewrite_toc_paragraph_element(p_el, str(value).strip(), page)
            elif p_el is not None and m_sdt:
                title, _old = _parse_toc_title_from_element(p_el)
                page_digits = str(value).strip()
                if title and page_digits:
                    _rewrite_toc_paragraph_element(p_el, title, page_digits)
                else:
                    W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
                    for node in reversed(p_el.findall(f".//{{{W_NS}}}t")):
                        txt = (node.text or "").strip()
                        if re.search(r"\d+\s*$", txt):
                            node.text = re.sub(r"\d+\s*$", page_digits, node.text or "")
                            break
            return

        elif location.startswith("table") and "_toc_" in location:
            m_tbl_title = re.match(r"^table_(\d+)_row_(\d+)_toc_(\d+)_title$", location)
            m_tbl_toc = re.match(r"^table_(\d+)_row_(\d+)_toc_(\d+)$", location)
            if not m_tbl_title and not m_tbl_toc:
                return

            m = m_tbl_title or m_tbl_toc
            t_i = int(m.group(1))
            r_i = int(m.group(2))
            l_i = int(m.group(3))

            if t_i >= len(doc.tables):
                return
            tbl = doc.tables[t_i]
            if not tbl.rows:
                return

            # Guard: If table is NOT an Att14-style TOC table, do not treat as _toc_!
            if not _is_att14_style_toc_table(tbl) and not is_toc_list_table(tbl):
                return

            target_r = (r_i + l_i) if (r_i + l_i) < len(tbl.rows) else r_i
            if target_r >= len(tbl.rows):
                return
            row = tbl.rows[target_r]

            if len(row.cells) >= 2:
                # Converted 2-col TOC table:
                # cells[0] holds title + uniform dot leaders.
                # cells[1] holds the right-aligned page number.
                if m_tbl_title:
                    title_text = str(value).strip()
                    if title_text:
                        p_el = _build_att14_toc_title_para(title_text)
                        tc = row.cells[0]._tc
                        for p in tc.findall(qn("w:p")):
                            tc.remove(p)
                        tc.append(p_el)
                elif m_tbl_toc:
                    page_digits = str(value).strip()
                    if page_digits:
                        p_el = _build_att14_toc_page_para(page_digits)
                        tc = row.cells[1]._tc
                        for p in tc.findall(qn("w:p")):
                            tc.remove(p)
                        tc.append(p_el)
            else:
                # 1-col unconverted TOC table:
                cell = row.cells[0]
                paras = cell.paragraphs
                target_l = 0 if target_r != r_i else l_i
                if target_l < len(paras):
                    p_el = paras[target_l]._element
                    if m_tbl_title:
                        _title, page = _parse_toc_title_from_element(p_el)
                        if page:
                            _rewrite_toc_paragraph_element(p_el, str(value).strip(), page)
                    elif m_tbl_toc:
                        page_digits = str(value).strip()
                        W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
                        updated = False
                        for node in reversed(p_el.findall(f".//{{{W_NS}}}t")):
                            txt = (node.text or "").strip()
                            if re.search(r"\d+\s*$", txt):
                                node.text = re.sub(r"\d+\s*$", page_digits, node.text or "")
                                updated = True
                                break
                        if not updated:
                            title, _old = _parse_toc_title_from_element(p_el)
                            if title:
                                _rewrite_toc_paragraph_element(p_el, title, page_digits)
            return

        elif location.startswith("header_"):
            cell = _resolve_header_cell(doc, location)
            if cell is not None:
                if _normalize_header_compare(cell.text) == _normalize_header_compare(value):
                    return
                _set_cell_text(cell, value)

        elif location.startswith("table") and "_col_" in location:
            t = int(parts[1])
            r = int(parts[3])
            c = int(parts[5])
            if t >= len(doc.tables):
                return
            table = doc.tables[t]
            while r >= len(table.rows):
                _clone_table_row_for_growth(table)
            # Never push user/data values into section banners or repeated header rows.
            if _is_structural_table_row(doc, t, r):
                return
            row = table.rows[r]
            if c >= len(row.cells):
                return
            cell = row.cells[c]
            # Same text already present — keep original formatting/layout.
            if _normalize_header_compare(cell.text) == _normalize_header_compare(value):
                return
            if str(value).lower() in ("yes", "no"):
                _fill_yes_no_cell(cell, value)
            elif len(row.cells) == 1 or len(set(c._tc for c in row.cells)) == 1 or PARA_COLON_BLANK_RE.search((cell.text or "").strip()):
                _fill_colon_label_cell(cell, value)
            elif field_type == "table_sl_no":
                _set_sl_no_cell_text(cell, value)
            elif field_type == "attachment":
                url = ""
                if attachment_links:
                    url = str(attachment_links.get(location) or "")
                if url:
                    _set_cell_hyperlink(cell, value, url)
                else:
                    _set_cell_text(cell, value)
            else:
                _set_cell_text(cell, value)

        elif location.startswith("table") and "col" not in location:
            t = int(parts[1])
            r = int(parts[3])
            row = doc.tables[t].rows[r]
            if len(row.cells) >= 2:
                right_cell = row.cells[1]
                if row.cells[0]._tc != right_cell._tc:
                    _set_cell_text(right_cell, value)

        elif location.startswith("txbx_"):
            match = re.match(r"txbx_(\d+)_p_(\d+)(?:_blank_(\d+))?", location)
            if not match:
                return
            txbx_index = int(match.group(1))
            inner_index = int(match.group(2))
            blank_index = match.group(3)
            p_element = _resolve_textbox_paragraph_element(doc, txbx_index, inner_index)
            if p_element is None:
                return
            inner_text = _para_text_from_element(p_element).strip()
            is_empty_line = (
                not inner_text
                and not count_underlined_tab_blanks(p_element)
            )
            if field_type == "textbox_line" or (
                field_type in (None, "") and blank_index is None and is_empty_line
            ):
                _fill_textbox_line(p_element, value)
            elif blank_index is not None:
                blank_idx = int(blank_index)
                tab_count = count_underlined_tab_blanks(p_element)
                filled = False
                if blank_idx == 0 and tab_count:
                    filled = _fill_underlined_tab_blank(p_element, 0, value)
                elif blank_idx == 1 and tab_count:
                    filled = _fill_underlined_tab_blank(p_element, tab_count - 1, value)
                    if filled:
                        _clear_underscore_placeholder_runs(p_element)
                if not filled:
                    from docx.text.paragraph import Paragraph

                    anchor = doc.paragraphs[0]
                    para = Paragraph(p_element, anchor._parent)
                    _fill_paragraph_blank_by_index(para, blank_idx, value)
            else:
                from docx.text.paragraph import Paragraph

                anchor = doc.paragraphs[0]
                para = Paragraph(p_element, anchor._parent)
                _fill_paragraph_blank(para, value)

        elif "_blank_" in location:
            para_index = int(parts[1])
            blank_index = int(location.split("_blank_")[1])
            para = _body_paragraph(doc, para_index)
            if para is not None:
                _fill_paragraph_blank_by_index(para, blank_index, value)

        elif location.startswith("para"):
            # para_N_body = notes under a locked heading (8.4 P&ID) — never wipe the label.
            if re.match(r"^para_\d+_body$", location):
                _fill_section_heading_body_notes(doc, int(parts[1]), value)
                return
            p = int(parts[1])
            para = _body_paragraph(doc, p)
            if para is None:
                return

            para_text_raw = (para.text or "").strip()
            # NEVER overwrite a section heading paragraph (OBJECTIVE, SCOPE, RESPONSIBILITY, etc.)
            try:
                from doc_parser import is_section_heading_paragraph
                is_heading = is_section_heading_paragraph(para_text_raw)
            except Exception:
                is_heading = False
            if is_heading or _is_tight_gap_section_heading(para_text_raw):
                return

            if _fill_neq_heading_value_into_box(para, value):
                return

            val_lower = str(value).strip().lower()
            if field_type == "paragraph_radio" and val_lower in ("yes", "no"):
                _fill_paragraph_yes_no(para, value)
            elif field_type == "paragraph_checkbox" and val_lower in (
                "1",
                "yes",
                "true",
                "on",
            ):
                _fill_paragraph_checkbox(para)
            elif field_type == "paragraph_content":
                if locked:
                    return
                # If target paragraph already has this exact text, nothing to do
                if para_text_raw == str(value).strip():
                    return
                # Do not write if value is identical to some other paragraph in the doc
                # (which indicates a shifted index from an older/different template)
                if para_text_raw and any(p_other.text.strip() == str(value).strip() for p_other in doc.paragraphs):
                    return

                font_name = None
                font_size = None
                if para.runs:
                    font_name = para.runs[0].font.name
                    font_size = para.runs[0].font.size
                _clear_text_runs(para)
                new_run = para.add_run(str(value))
                if font_name:
                    new_run.font.name = font_name
                if font_size:
                    new_run.font.size = font_size
            else:
                _fill_paragraph_blank(para, value)

    except (IndexError, ValueError):
        return


def _blank_sort_key(field: dict) -> tuple:
    """Sort paragraph blank parts by para index, then descending blank index (fill right-to-left)."""
    location = field.get("location", "")
    para_part, _, blank_part = location.partition("_blank_")
    try:
        para_index = int(para_part.split("_")[1])
        blank_index = int(blank_part)
    except (IndexError, ValueError):
        return (0, 0)
    return (para_index, -blank_index)


def _cell_has_list_number(cell) -> bool:
    """True when Word already draws 1. 2. 3. from list numbering."""
    for para in cell.paragraphs:
        p_pr = para._element.find(qn("w:pPr"))
        if p_pr is not None and p_pr.find(qn("w:numPr")) is not None:
            return True
    return False


def _fill_blank_sl_no_cells(doc) -> int:
    """
    Write 1, 2, 3… into an Sl. No. column whose cells are blank.

    Cells that already contain 1.0 / 2.0, or a Word list number, are left as they are.
    """
    written = 0
    for table in doc.tables:
        if not table.rows:
            continue
        header_cells = []
        seen_header = set()
        for cell in table.rows[0].cells:
            if id(cell._tc) in seen_header:
                continue
            seen_header.add(id(cell._tc))
            header_cells.append(cell)
        if not header_cells:
            continue
        header_text = re.sub(r"\s+", " ", (header_cells[0].text or "")).strip()
        if not is_sl_no_column_header(header_text):
            continue
        header_texts = [
            re.sub(r"\s+", " ", (cell.text or "")).strip() for cell in header_cells
        ]
        serial = 0
        for row in table.rows[1:]:
            if _is_equipment_section_banner_row(row) or _is_repeated_column_header_row(row, header_texts):
                continue
            cells = []
            seen_row = set()
            for cell in row.cells:
                if id(cell._tc) in seen_row:
                    continue
                seen_row.add(id(cell._tc))
                cells.append(cell)
            if not cells or cells[0]._tc is header_cells[0]._tc:
                continue
            if (cells[0].text or "").strip() or _cell_has_list_number(cells[0]):
                continue
            serial += 1
            _set_cell_text(cells[0], str(serial))
            written += 1
    return written


def _apply_heading_font_from_paragraph_mark(doc) -> None:
    """
    Section headings such as 7.0 REPORT APPROVAL keep Arial bold on the
    paragraph mark, while the words themselves have an empty run.
    Word then draws those words in Times New Roman. Copy the paragraph
    mark font onto that run so the heading stays Arial 12 bold.
    """
    from copy import deepcopy

    for para in doc.paragraphs:
        p_pr = para._element.find(qn("w:pPr"))
        if p_pr is None:
            continue
        mark = p_pr.find(qn("w:rPr"))
        if mark is None:
            continue
        mark_fonts = mark.find(qn("w:rFonts"))
        mark_bold = mark.find(qn("w:b"))
        if mark_fonts is None and mark_bold is None:
            continue
        for run in para.runs:
            if not (run.text or "").strip():
                continue
            r_pr = run._element.find(qn("w:rPr"))
            if r_pr is None:
                r_pr = OxmlElement("w:rPr")
                run._element.insert(0, r_pr)
            if mark_fonts is not None and r_pr.find(qn("w:rFonts")) is None:
                r_pr.insert(0, deepcopy(mark_fonts))
            if mark_bold is not None and r_pr.find(qn("w:b")) is None:
                r_pr.append(deepcopy(mark_bold))
                mark_bold_cs = mark.find(qn("w:bCs"))
                if mark_bold_cs is not None and r_pr.find(qn("w:bCs")) is None:
                    r_pr.append(deepcopy(mark_bold_cs))
            mark_sz = mark.find(qn("w:sz"))
            if mark_sz is not None and r_pr.find(qn("w:sz")) is None:
                r_pr.append(deepcopy(mark_sz))
                mark_sz_cs = mark.find(qn("w:szCs"))
                if mark_sz_cs is not None and r_pr.find(qn("w:szCs")) is None:
                    r_pr.append(deepcopy(mark_sz_cs))
            elif r_pr.find(qn("w:sz")) is None and para.style is not None:
                style_rpr = para.style.element.find(qn("w:rPr"))
                style_sz = style_rpr.find(qn("w:sz")) if style_rpr is not None else None
                if style_sz is not None:
                    r_pr.append(deepcopy(style_sz))
                    style_sz_cs = style_rpr.find(qn("w:szCs"))
                    if style_sz_cs is not None and r_pr.find(qn("w:szCs")) is None:
                        r_pr.append(deepcopy(style_sz_cs))


def _paragraph_plain_text(p_element) -> str:
    return "".join(node.text or "" for node in p_element.iter(qn("w:t"))).strip()


def _is_numbered_section_title(text: str) -> bool:
    """Body lines such as '5.0 Review and Discussions:' — not TOC dot leaders."""
    cleaned = re.sub(r"\s+", " ", text or "").strip()
    if not cleaned or len(cleaned) > 120 or "…" in cleaned or "...." in cleaned:
        return False
    return bool(re.match(r"^\d+(?:\.\d+)+\s+\S", cleaned))


def _rpr_is_bold(r_pr) -> bool:
    if r_pr is None:
        return False
    bold = r_pr.find(qn("w:b"))
    if bold is None:
        return False
    return (bold.get(qn("w:val")) or "true").lower() not in {"0", "false", "off"}


def _paragraph_is_bold(p_el) -> bool:
    """True when the original paragraph mark or a text run is bold."""
    p_pr = p_el.find(qn("w:pPr"))
    if p_pr is not None and _rpr_is_bold(p_pr.find(qn("w:rPr"))):
        return True
    for run in p_el.findall(qn("w:r")):
        if not "".join(node.text or "" for node in run.findall(qn("w:t"))).strip():
            continue
        if _rpr_is_bold(run.find(qn("w:rPr"))):
            return True
    return False


def _keep_neq_bold_heading_font(p_el) -> None:
    """
    Heading that is already bold in the New Equipment template stays bold.
    Missing size becomes Arial 11. An existing size (11pt on Att14 headings) is kept.
    """
    def touch(r_pr) -> None:
        has_sz = r_pr.find(qn("w:sz")) is not None
        _force_arial_11_on_rpr(r_pr, bold=True, force_size=not has_sz)

    p_pr = p_el.find(qn("w:pPr"))
    if p_pr is not None:
        mark = p_pr.find(qn("w:rPr"))
        if mark is None:
            mark = OxmlElement("w:rPr")
            p_pr.append(mark)
        touch(mark)
    for run in p_el.findall(qn("w:r")):
        if not "".join(node.text or "" for node in run.findall(qn("w:t"))).strip():
            continue
        r_pr = run.find(qn("w:rPr"))
        if r_pr is None:
            r_pr = OxmlElement("w:rPr")
            run.insert(0, r_pr)
        touch(r_pr)


def _looks_like_kept_heading(text: str) -> bool:
    """Section titles stay bold. Filled remarks and summary lines do not."""
    cleaned = re.sub(r"\s+", " ", text or "").strip()
    if not cleaned:
        return True
    if len(cleaned) <= 90 and (cleaned.endswith(":") or re.match(r"^\d+(?:\.\d+)+\s", cleaned)):
        return True
    letters = [char for char in cleaned if char.isalpha()]
    if letters and len(cleaned) < 120:
        upper = sum(1 for char in letters if char.isupper()) / len(letters)
        if upper >= 0.75:
            return True
    # Numbered list titles often store only the label ("Calibration") while Word
    # shows "8.1 Calibration". Title-case short phrases stay bold like the template.
    if len(cleaned) <= 90 and not cleaned.endswith(".") and "\n" not in cleaned:
        words = cleaned.split()
        if 1 <= len(words) <= 10:
            small = {"and", "of", "the", "for", "to", "in", "on", "a", "an", "&", "/"}
            alpha_words = [w.strip("()[]") for w in words if any(c.isalpha() for c in w)]
            if alpha_words and all(
                (w[0].isupper() if w else False) or w.lower() in small
                for w in alpha_words
            ):
                return True
    return False


def _paragraph_has_numbering(p_element) -> bool:
    """True when Word auto-numbering supplies 8.1 / 7.2 style prefixes."""
    p_pr = p_element.find(qn("w:pPr"))
    return p_pr is not None and p_pr.find(qn("w:numPr")) is not None


def _strip_paragraph_bold(p_element) -> None:
    """Remove bold from a filled body line and from its paragraph mark."""
    targets = [p_element]
    p_pr = p_element.find(qn("w:pPr"))
    if p_pr is not None:
        mark = p_pr.find(qn("w:rPr"))
        if mark is not None:
            targets.append(mark)
    for run in p_element.findall(qn("w:r")):
        r_pr = run.find(qn("w:rPr"))
        if r_pr is not None:
            targets.append(r_pr)
    for node in targets:
        for tag in ("w:b", "w:bCs"):
            for bold in list(node.findall(qn(tag))):
                node.remove(bold)


_BOLD_LABELS = (
    "summary & conclusion",
    "summary and conclusion",
    "reviewed by",
    "verification with the current p & id",
)


def _run_font_source(p_element):
    for run in p_element.findall(qn("w:r")):
        r_pr = run.find(qn("w:rPr"))
        if r_pr is not None:
            return r_pr
    p_pr = p_element.find(qn("w:pPr"))
    if p_pr is not None:
        return p_pr.find(qn("w:rPr"))
    return None


def _make_text_run(text: str, font_source, bold: bool):
    run = OxmlElement("w:r")
    r_pr = OxmlElement("w:rPr")
    if font_source is not None:
        for tag in ("w:rFonts", "w:sz", "w:szCs", "w:spacing"):
            node = font_source.find(qn(tag))
            if node is not None:
                r_pr.append(deepcopy(node))
    if bold:
        r_pr.append(OxmlElement("w:b"))
        r_pr.append(OxmlElement("w:bCs"))
    run.append(r_pr)
    parts = text.split("\n")
    for index, part in enumerate(parts):
        if index:
            run.append(OxmlElement("w:br"))
        text_node = OxmlElement("w:t")
        if part[:1].isspace() or part[-1:].isspace():
            text_node.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
        text_node.text = part
        run.append(text_node)
    return run


def _split_bold_label(p_element) -> bool:
    """
    Keep "SUMMARY & CONCLUSION:" and "Reviewed By:" bold.
    The words typed after the colon stay regular weight.
    """
    text = _paragraph_plain_text(p_element)
    match = re.match(r"^(.{1,80}?:)\s*(.*)$", text, flags=re.S)
    if not match:
        return False
    label = re.sub(r"\s+", " ", match.group(1)).strip()
    value = match.group(2).strip()
    key = label.rstrip(":").strip().lower()
    if key not in _BOLD_LABELS or not value:
        return False
    font_source = _run_font_source(p_element)
    for run in list(p_element.findall(qn("w:r"))):
        p_element.remove(run)
    p_pr = p_element.find(qn("w:pPr"))
    if p_pr is not None:
        mark = p_pr.find(qn("w:rPr"))
        if mark is not None:
            for tag in ("w:b", "w:bCs"):
                for node in list(mark.findall(qn(tag))):
                    mark.remove(node)
    p_element.append(_make_text_run(label + " ", font_source, True))
    p_element.append(_make_text_run(value, font_source, False))
    return True


def _paragraph_numbering_ilvl(p_element) -> int | None:
    """Return Word list level (0 = 7.0 / OBJECTIVE, 1 = 7.1 subsection), if any."""
    p_pr = p_element.find(qn("w:pPr"))
    if p_pr is None:
        return None
    num = p_pr.find(qn("w:numPr"))
    if num is None:
        return None
    ilvl = num.find(qn("w:ilvl"))
    if ilvl is None:
        return 0
    try:
        return int(ilvl.get(qn("w:val")) or 0)
    except (TypeError, ValueError):
        return 0


def _should_keep_neq_heading_bold(text: str, ilvl: int | None) -> bool:
    """
    NEQ original: major titles (OBJECTIVE) and short subsection titles
    (Pre-Installation Checks:) stay bold; body lines under 7.1 / 14.0 do not.
    """
    cleaned = re.sub(r"\s+", " ", text or "").strip()
    if not cleaned:
        return False
    letters = [c for c in cleaned if c.isalpha()]
    if letters and len(cleaned) < 90:
        upper = sum(1 for c in letters if c.isupper()) / len(letters)
        # INSTALLATION CHECKS / REFERENCES / OBJECTIVE
        if upper >= 0.75:
            return True
    level = 0 if ilvl is None else ilvl
    if level >= 1:
        # Short subsection titles, often ending with ':'
        if cleaned.endswith(".") and len(cleaned) > 70:
            return False
        words = cleaned.split()
        if cleaned.endswith(":") or len(words) <= 10:
            return True
    return False


# Signature-table department labels + short check names: regular in original AHU DOCX.
_REQUAL_NEVER_BOLD_LABELS = {
    "engineering",
    "production",
    "validation",
    "quality assurance",
    "quality",
    "user department(engineering)",
    "user department(production)",
    "user department",
    "vfd",
    "vfd panel",
    "vfd / electrical panel",
}


def _should_keep_requal_heading_bold(text: str, ilvl: int | None, has_numbering: bool) -> bool:
    """
    Requal/reval original AHU: section titles stay bold; responsibility bullets,
    8.3 test list items, VFD check labels, and department names stay regular.
    """
    cleaned = re.sub(r"\s+", " ", text or "").strip()
    if not cleaned:
        return False
    key = cleaned.lower().rstrip(":")
    if key in _REQUAL_NEVER_BOLD_LABELS or key.startswith("user department"):
        return False
    # SUMMARY & CONCLUSION: / REFERENCES: / ABBREVIATIONS: / REVISION HISTORY:
    if cleaned.endswith(":") and len(cleaned) <= 80:
        return True
    # 1.0 OBJECTIVE / 3.0 RESPONSIBILITY / 8.1 Calibration
    if re.match(r"^\d+\.\d+\s+\S", cleaned) and len(cleaned) <= 100:
        return True
    # Long prose bullets (responsibility / scope) — never bold.
    if len(cleaned) > 70 or cleaned.count(" ") >= 10:
        return False
    # Numbered list body items ("Airflow volume measurement / ACPH") — regular.
    if has_numbering:
        return False
    # Abbreviation lines ("DQ - Design Qualification") — regular.
    if re.match(r"^[A-Za-z0-9]{1,12}\s*[-–—:]\s+\S", cleaned):
        return False
    # Short ALL-CAPS tokens (VFD) — regular. Multi-word banners
    # (PRE-APPROVAL SIGNATURE, CHECKED BY) stay bold like the template.
    letters = [c for c in cleaned if c.isalpha()]
    if letters:
        upper = sum(1 for c in letters if c.isupper()) / len(letters)
        if upper >= 0.75:
            if " " not in cleaned and len(cleaned) <= 16:
                return False
            if len(cleaned.split()) >= 2 and len(cleaned) <= 90:
                return True
    return False


def _unbold_filled_body_lines(doc) -> None:
    """
    Dotted remark lines and the blank lines under SUMMARY & CONCLUSION
    carry bold in the template. Typed text must stay regular weight.
    The label before the colon stays bold.

    Requal/reval: never force-bold every numbered paragraph — that made
    3.0 responsibility bullets and 8.3 test lists bold unlike the original.
    Walk body + table cells so abbreviation lines are corrected too.
    """
    is_neq = _active_is_neq()
    for child in _iter_all_paragraph_elements(doc):
        if _split_bold_label(child):
            continue
        text = _paragraph_plain_text(child)
        has_num = _paragraph_has_numbering(child)
        ilvl = _paragraph_numbering_ilvl(child) if has_num else None

        if is_neq:
            # Value typed for 5.0 / 6.0 belongs in the box, in normal type.
            if _is_neq_value_box_paragraph(child):
                _ensure_arial_11_regular_runs(child)
                continue
            # Att14 cover titles stay bold in the original Word template.
            if re.search(
                r"\(IOQ\)\s*REPORT\s+FOR|Identification\s*No\.?\s*:",
                text or "",
                re.I,
            ):
                _ensure_arial_11_on_heading_runs(child)
                continue
            # Numbered titles that are bold in the template stay bold (5.0, 6.0, 7.0).
            # Titles that are regular in the template (1.0 Objective, 3.2) stay regular.
            if _is_numbered_section_title(text) and _paragraph_is_bold(child):
                _keep_neq_bold_heading_font(child)
                continue
            if has_num:
                if _should_keep_neq_heading_bold(text, ilvl):
                    _ensure_arial_11_on_heading_runs(child)
                else:
                    _strip_paragraph_bold(child)
                    _ensure_arial_11_regular_runs(child)
                continue
            if _looks_like_kept_heading(text) and _should_keep_neq_heading_bold(text, 0):
                _ensure_arial_11_on_heading_runs(child)
                continue
            _strip_paragraph_bold(child)
            _ensure_arial_11_regular_runs(child)
            continue

        # Requal / reval / legacy
        if _should_keep_requal_heading_bold(text, ilvl, has_num):
            _ensure_arial_11_on_heading_runs(child)
            continue
        _strip_paragraph_bold(child)
        _ensure_arial_11_regular_runs(child)


def _force_arial_11_on_rpr(r_pr, *, bold: bool = False, force_size: bool = True) -> None:
    """
    Put Arial (+ optional bold) on a run/mark/numbering rPr.
    LibreOffice PDF drops inherited sizes, so sz=22 (11pt) is written explicitly
    when force_size is True or when the rPr has no size yet.
    """
    fonts = r_pr.find(qn("w:rFonts"))
    if fonts is None:
        fonts = OxmlElement("w:rFonts")
        r_pr.insert(0, fonts)
    fonts.set(qn("w:ascii"), "Arial")
    fonts.set(qn("w:hAnsi"), "Arial")
    fonts.set(qn("w:cs"), "Arial")
    has_sz = r_pr.find(qn("w:sz")) is not None
    if force_size or not has_sz:
        for tag in ("w:sz", "w:szCs"):
            for node in list(r_pr.findall(qn(tag))):
                r_pr.remove(node)
        sz = OxmlElement("w:sz")
        sz.set(qn("w:val"), "22")  # 11pt
        r_pr.append(sz)
        sz_cs = OxmlElement("w:szCs")
        sz_cs.set(qn("w:val"), "22")
        r_pr.append(sz_cs)
    for tag in ("w:b", "w:bCs"):
        for node in list(r_pr.findall(qn(tag))):
            r_pr.remove(node)
    if bold:
        r_pr.append(OxmlElement("w:b"))
        r_pr.append(OxmlElement("w:bCs"))


def _force_arial_11_bold_on_rpr(r_pr) -> None:
    """Force Arial 11 bold onto a run/paragraph-mark rPr (LibreOffice needs explicit sz)."""
    _force_arial_11_on_rpr(r_pr, bold=True, force_size=True)


def _ensure_arial_11_on_heading_runs(p_element) -> None:
    """Match original IOQ headings: Arial 11 bold (explicit size for LibreOffice PDF)."""
    p_pr = p_element.find(qn("w:pPr"))
    if p_pr is not None:
        mark = p_pr.find(qn("w:rPr"))
        if mark is None:
            mark = OxmlElement("w:rPr")
            p_pr.append(mark)
        _force_arial_11_bold_on_rpr(mark)
    for run in p_element.findall(qn("w:r")):
        text = "".join(node.text or "" for node in run.iter(qn("w:t"))).strip()
        if not text:
            continue
        r_pr = run.find(qn("w:rPr"))
        if r_pr is None:
            r_pr = OxmlElement("w:rPr")
            run.insert(0, r_pr)
        _force_arial_11_bold_on_rpr(r_pr)


def _ensure_arial_11_regular_runs(p_element) -> None:
    """
    Body lines: keep existing explicit sizes (attachment titles may be 14/18pt),
    but never leave font/size missing — default to Arial 11 like Normal on IOQ.
    """
    p_pr = p_element.find(qn("w:pPr"))
    if p_pr is not None:
        mark = p_pr.find(qn("w:rPr"))
        if mark is not None:
            _force_arial_11_on_rpr(mark, bold=False, force_size=False)
    for run in p_element.findall(qn("w:r")):
        text = "".join(node.text or "" for node in run.iter(qn("w:t"))).strip()
        if not text:
            continue
        r_pr = run.find(qn("w:rPr"))
        if r_pr is None:
            r_pr = OxmlElement("w:rPr")
            run.insert(0, r_pr)
        _force_arial_11_on_rpr(r_pr, bold=False, force_size=False)


def _iter_all_paragraph_elements(doc):
    """Yield every body paragraph element, including those inside tables."""
    body = doc.element.body
    for child in list(body):
        if child.tag == qn("w:p"):
            yield child
        elif child.tag == qn("w:tbl"):
            for p_el in child.iter(qn("w:p")):
                yield p_el


def _neq_heading_abstract_ids(doc) -> set[str]:
    """
    Abstract numbering ids used by major NEQ section titles (OBJECTIVE / 5.0 …).
    Their auto-number glyphs must be Arial 11 bold like the heading text.
    """
    heading_num_ids: set[str] = set()
    for p_el in _iter_all_paragraph_elements(doc):
        if not _paragraph_has_numbering(p_el):
            continue
        text = _paragraph_plain_text(p_el)
        ilvl = _paragraph_numbering_ilvl(p_el)
        if not _should_keep_neq_heading_bold(text, ilvl):
            continue
        p_pr = p_el.find(qn("w:pPr"))
        num_pr = p_pr.find(qn("w:numPr")) if p_pr is not None else None
        if num_pr is None:
            continue
        num_id_el = num_pr.find(qn("w:numId"))
        if num_id_el is None:
            continue
        num_id = (num_id_el.get(qn("w:val")) or "").strip()
        if num_id:
            heading_num_ids.add(num_id)
    abstract_ids: set[str] = set()
    try:
        numbering = doc.part.numbering_part._element
    except Exception:
        return abstract_ids
    for num in numbering.findall(qn("w:num")):
        num_id = (num.get(qn("w:numId")) or "").strip()
        if num_id not in heading_num_ids:
            continue
        abs_el = num.find(qn("w:abstractNumId"))
        if abs_el is not None and (abs_el.get(qn("w:val")) or "").strip():
            abstract_ids.add(abs_el.get(qn("w:val")))
    return abstract_ids


# Word stores list bullets as private-use glyphs that only render in the
# matching symbol font. Forcing Arial here turns arrows/dots into empty boxes.
_NEQ_BULLET_FONT_BY_GLYPH = {
    "\uf0b7": "Symbol",       # •
    "\uf0d8": "Wingdings",    # ➢
    "\uf0a7": "Wingdings",    # ▪
    "\uf0fc": "Wingdings",    # ✓
    "\uf0b2": "Wingdings",
    "o": "Courier New",
}


def _lvl_num_format(lvl) -> str:
    nf = lvl.find(qn("w:numFmt"))
    return ((nf.get(qn("w:val")) if nf is not None else "") or "").strip().lower()


def _restore_neq_bullet_level_font(lvl) -> None:
    """Keep Wingdings/Symbol/Courier bullet fonts; repair if Arial overwrote them."""
    lt = lvl.find(qn("w:lvlText"))
    glyph = (lt.get(qn("w:val")) if lt is not None else "") or ""
    r_pr = lvl.find(qn("w:rPr"))
    if r_pr is None:
        r_pr = OxmlElement("w:rPr")
        lvl.append(r_pr)
    fonts = r_pr.find(qn("w:rFonts"))
    ascii_font = (fonts.get(qn("w:ascii")) if fonts is not None else "") or ""
    symbol_fonts = {"Wingdings", "Wingdings 2", "Wingdings 3", "Symbol", "Courier New"}
    if ascii_font in symbol_fonts:
        return
    restore = _NEQ_BULLET_FONT_BY_GLYPH.get(glyph)
    if not restore:
        # Unknown bullet glyph — do not force Arial; leave template fonts alone.
        return
    if fonts is None:
        fonts = OxmlElement("w:rFonts")
        r_pr.insert(0, fonts)
    fonts.set(qn("w:ascii"), restore)
    fonts.set(qn("w:hAnsi"), restore)
    fonts.set(qn("w:hint"), "default")
    # cs Arial breaks some Symbol bullets in LibreOffice — drop it for bullets.
    if fonts.get(qn("w:cs")) is not None:
        del fonts.attrib[qn("w:cs")]
    # Size on symbol fonts is optional; strip forced 11pt if we added it earlier.
    for tag in ("w:sz", "w:szCs", "w:b", "w:bCs"):
        for node in list(r_pr.findall(qn(tag))):
            r_pr.remove(node)


def _normalize_neq_numbering_fonts(doc) -> None:
    """
    Heading auto-numbers (5.0 / 7.1): Arial 11 bold + space suffix.
    Bullet lists: preserve Wingdings/Symbol arrow/dot fonts — never Arial.
    """
    try:
        numbering = doc.part.numbering_part._element
    except Exception:
        return
    heading_abstracts = _neq_heading_abstract_ids(doc)
    for absn in numbering.findall(qn("w:abstractNum")):
        abs_id = (absn.get(qn("w:abstractNumId")) or "").strip()
        is_heading = abs_id in heading_abstracts
        for lvl in absn.findall(qn("w:lvl")):
            fmt = _lvl_num_format(lvl)
            if fmt == "bullet":
                _restore_neq_bullet_level_font(lvl)
                continue
            if not is_heading:
                # Non-heading decimal/letter lists: ensure Arial 11, not bold.
                r_pr = lvl.find(qn("w:rPr"))
                if r_pr is None:
                    r_pr = OxmlElement("w:rPr")
                    lvl.append(r_pr)
                _force_arial_11_on_rpr(r_pr, bold=False, force_size=True)
                continue
            r_pr = lvl.find(qn("w:rPr"))
            if r_pr is None:
                r_pr = OxmlElement("w:rPr")
                lvl.append(r_pr)
            _force_arial_11_on_rpr(r_pr, bold=True, force_size=True)
            # Original looks like \"5.0 EQUIPMENT\" (one space). Default
            # num-tab leaves a wide gap in LibreOffice PDF.
            suff = lvl.find(qn("w:suff"))
            if suff is None:
                suff = OxmlElement("w:suff")
                # Schema order: after lvlText / lvlJc, before pPr.
                lvl_jc = lvl.find(qn("w:lvlJc"))
                if lvl_jc is not None:
                    lvl_jc_index = list(lvl).index(lvl_jc)
                    lvl.insert(lvl_jc_index + 1, suff)
                else:
                    lvl.insert(0, suff)
            suff.set(qn("w:val"), "space")


def _normalize_requal_numbering_fonts(doc) -> None:
    """
    Requal/reval: keep section-number glyphs Arial 11 bold; strip bold from
    bullet / body list levels so responsibility and 8.3 items match original.
    """
    if _active_is_neq():
        return
    try:
        numbering = doc.part.numbering_part._element
    except Exception:
        return

    heading_num_ids: set[str] = set()
    for p_el in _iter_all_paragraph_elements(doc):
        if not _paragraph_has_numbering(p_el):
            continue
        text = _paragraph_plain_text(p_el)
        ilvl = _paragraph_numbering_ilvl(p_el)
        if not _should_keep_requal_heading_bold(text, ilvl, True):
            continue
        p_pr = p_el.find(qn("w:pPr"))
        num_pr = p_pr.find(qn("w:numPr")) if p_pr is not None else None
        if num_pr is None:
            continue
        num_id_el = num_pr.find(qn("w:numId"))
        if num_id_el is None:
            continue
        num_id = (num_id_el.get(qn("w:val")) or "").strip()
        if num_id:
            heading_num_ids.add(num_id)

    heading_abstracts: set[str] = set()
    for num in numbering.findall(qn("w:num")):
        num_id = (num.get(qn("w:numId")) or "").strip()
        if num_id not in heading_num_ids:
            continue
        abs_el = num.find(qn("w:abstractNumId"))
        if abs_el is not None and (abs_el.get(qn("w:val")) or "").strip():
            heading_abstracts.add(abs_el.get(qn("w:val")))

    for absn in numbering.findall(qn("w:abstractNum")):
        abs_id = (absn.get(qn("w:abstractNumId")) or "").strip()
        is_heading = abs_id in heading_abstracts
        for lvl in absn.findall(qn("w:lvl")):
            fmt = _lvl_num_format(lvl)
            if fmt == "bullet":
                _restore_neq_bullet_level_font(lvl)
                # Bullets must not render bold in LibreOffice PDF.
                r_pr = lvl.find(qn("w:rPr"))
                if r_pr is not None:
                    for tag in ("w:b", "w:bCs"):
                        for node in list(r_pr.findall(qn(tag))):
                            r_pr.remove(node)
                continue
            r_pr = lvl.find(qn("w:rPr"))
            if r_pr is None:
                r_pr = OxmlElement("w:rPr")
                lvl.append(r_pr)
            _force_arial_11_on_rpr(r_pr, bold=is_heading, force_size=True)


_AMPM_DATETIME_RE = re.compile(
    r"\b(\d{2}-\d{2}-\d{4})\s+(\d{1,2}):(\d{2})\s*(AM|PM)\b",
    re.IGNORECASE,
)
_EFFECTIVE_DATE_ONLY_RE = re.compile(
    r"^(\d{2}-\d{2}-\d{4})(?:\s+\d{1,2}:\d{2}(?:\s*(?:AM|PM))?)?$",
    re.IGNORECASE,
)


def _to_24h_datetime_stamp(match: re.Match) -> str:
    day = match.group(1)
    hour = int(match.group(2))
    minute = match.group(3)
    ampm = match.group(4).upper()
    if ampm == "PM" and hour < 12:
        hour += 12
    if ampm == "AM" and hour == 12:
        hour = 0
    return f"{day} {hour:02d}:{minute}"


def _replace_text_in_paragraph_element(p_el, new_text: str) -> bool:
    """Replace visible text of a paragraph while keeping the first run's rPr."""
    runs = p_el.findall(qn("w:r"))
    if not runs:
        return False
    first = True
    for run in runs:
        for t_el in run.findall(qn("w:t")):
            if first:
                t_el.text = new_text
                first = False
            else:
                t_el.text = ""
        # Drop leftover text in drawings/tabs-only runs.
    if first:
        return False
    return True


def _iter_doc_tables(doc):
    """Body tables plus every section header/footer table (cover Effective Date lives here)."""
    for table in doc.tables:
        yield table
    for section in doc.sections:
        try:
            for table in section.header.tables:
                yield table
        except Exception:
            pass
        try:
            for table in section.footer.tables:
                yield table
        except Exception:
            pass


def _strip_effective_date_time_in_tables(tables) -> int:
    """Force Effective Date value cells to d-m-Y only (drop time / AM/PM)."""
    fixed = 0
    for table in tables:
        for row in table.rows:
            cells = list(row.cells)
            for i, cell in enumerate(cells):
                label = re.sub(r"\s+", " ", (cell.text or "")).strip().lower()
                if "effective date" not in label:
                    continue
                candidates = [cell]
                if i + 1 < len(cells):
                    candidates.append(cells[i + 1])
                for target in candidates:
                    for para in target.paragraphs:
                        raw = (para.text or "").strip()
                        if not raw:
                            continue
                        m_only = _EFFECTIVE_DATE_ONLY_RE.match(raw)
                        if m_only and raw != m_only.group(1):
                            if _replace_text_in_paragraph_element(para._element, m_only.group(1)):
                                fixed += 1
                            continue
                        if _AMPM_DATETIME_RE.search(raw) or re.search(
                            r"\d{2}-\d{2}-\d{4}\s+\d{1,2}:\d{2}", raw
                        ):
                            # Keep label text if present; strip time from any stamp.
                            new_text = _AMPM_DATETIME_RE.sub(lambda m: m.group(1), raw)
                            new_text = re.sub(
                                r"(\d{2}-\d{2}-\d{4})\s+\d{1,2}:\d{2}(?:\s*(?:AM|PM))?",
                                r"\1",
                                new_text,
                                flags=re.IGNORECASE,
                            )
                            if new_text != raw and _replace_text_in_paragraph_element(
                                para._element, new_text
                            ):
                                fixed += 1
    return fixed


def _normalize_neq_date_time_display(doc) -> int:
    """
    Effective Date cells (body + header letterhead): date only (d-m-Y).
    Signature / other stamps: convert AM/PM to 24-hour (d-m-Y H:i).
    """
    if not _active_is_neq():
        return 0
    fixed = _strip_effective_date_time_in_tables(_iter_doc_tables(doc))

    # Signature stamps and any remaining AM/PM datetimes → 24-hour.
    # Do not touch Effective Date value cells again (already date-only).
    for p_el in _iter_all_paragraph_elements(doc):
        raw = _paragraph_plain_text(p_el)
        if not raw or not _AMPM_DATETIME_RE.search(raw):
            continue
        new_text = _AMPM_DATETIME_RE.sub(_to_24h_datetime_stamp, raw)
        if new_text != raw and _replace_text_in_paragraph_element(p_el, new_text):
            fixed += 1

    # Header/footer paragraphs outside body iterator.
    for section in doc.sections:
        for part in (section.header, section.footer):
            try:
                paras = part.paragraphs
            except Exception:
                continue
            for para in paras:
                raw = (para.text or "").strip()
                if not raw or not _AMPM_DATETIME_RE.search(raw):
                    continue
                new_text = _AMPM_DATETIME_RE.sub(_to_24h_datetime_stamp, raw)
                if new_text != raw and _replace_text_in_paragraph_element(para._element, new_text):
                    fixed += 1
    return fixed


def _normalize_neq_document_fonts(doc) -> None:
    """
    NEQ final view/download: headings Arial 11 bold; every other run keeps its
    template size when set, otherwise Arial 11 — no missing font size for LO PDF.
    Applies to all New-equipment templates (protocol + attachments).
    """
    if not _active_is_neq():
        return
    _normalize_neq_numbering_fonts(doc)
    _normalize_neq_date_time_display(doc)
    for p_el in _iter_all_paragraph_elements(doc):
        text = _paragraph_plain_text(p_el)
        if not text:
            continue
        # Skip TOC lines — handled by _rewrite_toc_paragraph_element.
        try:
            style_id = ""
            p_pr = p_el.find(qn("w:pPr"))
            if p_pr is not None:
                ps = p_pr.find(qn("w:pStyle"))
                if ps is not None:
                    style_id = (ps.get(qn("w:val")) or "").upper()
            if style_id.startswith("TOC"):
                continue
        except Exception:
            pass
        if _is_neq_value_box_paragraph(p_el):
            _ensure_arial_11_regular_runs(p_el)
            continue
        if re.search(
            r"\(IOQ\)\s*REPORT\s+FOR|Identification\s*No\.?\s*:",
            text or "",
            re.I,
        ):
            _ensure_arial_11_on_heading_runs(p_el)
            continue
        if _is_numbered_section_title(text) and _paragraph_is_bold(p_el):
            _keep_neq_bold_heading_font(p_el)
            continue
        ilvl = _paragraph_numbering_ilvl(p_el) if _paragraph_has_numbering(p_el) else None
        if _paragraph_has_numbering(p_el) and _should_keep_neq_heading_bold(text, ilvl):
            _ensure_arial_11_on_heading_runs(p_el)
            continue
        if _looks_like_kept_heading(text) and _should_keep_neq_heading_bold(text, 0):
            _ensure_arial_11_on_heading_runs(p_el)
            continue
        _ensure_arial_11_regular_runs(p_el)


def _floating_row_growth_twips(tr, grid_widths: list[int]) -> int:
    """How many twips a filled row grows past the template's fixed height."""
    tr_pr = tr.find(qn("w:trPr"))
    tr_h = tr_pr.find(qn("w:trHeight")) if tr_pr is not None else None
    fixed = int(tr_h.get(qn("w:val")) or 0) if tr_h is not None else 0
    col = 0
    max_lines = 1
    for tc in tr.findall(qn("w:tc")):
        tc_pr = tc.find(qn("w:tcPr"))
        span = 1
        width = grid_widths[col] if col < len(grid_widths) else 1600
        if tc_pr is not None:
            grid_span = tc_pr.find(qn("w:gridSpan"))
            if grid_span is not None:
                span = int(grid_span.get(qn("w:val")) or 1)
            tc_w = tc_pr.find(qn("w:tcW"))
            if tc_w is not None and (tc_w.get(qn("w:w")) or "").isdigit():
                width = int(tc_w.get(qn("w:w")))
            elif grid_widths:
                width = sum(grid_widths[col:col + span]) or width
        text = "".join(node.text or "" for node in tc.iter(qn("w:t"))).strip()
        if text:
            chars_per_line = max(8, width // 90)
            lines = 0
            for part in text.splitlines() or [""]:
                lines += max(1, (len(part) + chars_per_line - 1) // chars_per_line)
            max_lines = max(max_lines, lines)
        col += span
    content_height = max_lines * 260 + 60
    if fixed and content_height > fixed:
        return content_height - fixed
    return 0


def _blank_paragraph_like(source) -> object:
    """An empty paragraph with the same spacing as the template's spacer lines."""
    clone = deepcopy(source)
    for attr in list(clone.attrib):
        if attr.endswith("paraId") or attr.endswith("textId"):
            del clone.attrib[attr]
    for child in list(clone):
        if child.tag == qn("w:r"):
            clone.remove(child)
    return clone


def _drop_floating_tables_below_following_text(doc) -> None:
    """
    Filled rows grow, and a floating table then paints over the next heading.
    Add the same blank lines the template already uses, for the extra height only,
    so "8.2 Preventive Maintenance Details:" stays under the calibration table.
    """
    body = doc.element.body
    children = list(body)
    for index, child in enumerate(children):
        if child.tag != qn("w:tbl"):
            continue
        tbl_pr = child.find(qn("w:tblPr"))
        if tbl_pr is None or tbl_pr.find(qn("w:tblpPr")) is None:
            continue
        grid = child.find(qn("w:tblGrid"))
        widths = []
        if grid is not None:
            for col in grid.findall(qn("w:gridCol")):
                raw = col.get(qn("w:w")) or "0"
                widths.append(int(raw) if raw.isdigit() else 0)
        growth = sum(_floating_row_growth_twips(tr, widths) for tr in child.findall(qn("w:tr")))
        if growth < 200:
            continue
        spacer = None
        target = None
        for follower in children[index + 1:]:
            if follower.tag == qn("w:tbl"):
                target = follower
                break
            if follower.tag != qn("w:p"):
                continue
            if _paragraph_plain_text(follower):
                target = follower
                break
            spacer = follower
        if target is None:
            continue
        if spacer is None:
            spacer = OxmlElement("w:p")
        # One template spacer is about a short line. Add a little air under the border.
        extra = int((growth + 360) / 280) + 1
        extra = max(1, min(extra, 24))
        for _ in range(extra):
            target.addprevious(_blank_paragraph_like(spacer))


def _is_neq_import_source(import_source: str | None) -> bool:
    return (import_source or "").strip().lower() == "new_equipment_qualification"


def _ensure_neq_header_logo_title_gap(doc) -> int:
    """
    NEQ letterhead: floating Lupin logo + centered protocol title.
    Word respects wrapSquare distR; LibreOffice PDF often ignores it so
    "INSTALLATION AND OPERATIONAL QUALIFICATION PROTOCOL" sits flush on the
    logo. Bump wrap distance and left-indent the title past the logo + gap.
    """
    if not _active_is_neq():
        return 0
    wp_ns = DRAWING_NS
    a_ns = "http://schemas.openxmlformats.org/drawingml/2006/main"
    changed = 0
    # ~0.25" wrap gap (original is ~0.125"; LO needs more to show a clear gap).
    min_dist_emu = 228600
    for section in doc.sections:
        try:
            header = section.header
        except Exception:
            continue
        logo_right_emu = 0
        for para in header.paragraphs:
            for anchor in para._element.iter(f"{{{wp_ns}}}anchor"):
                for attr in ("distR", "distL"):
                    try:
                        cur = int(anchor.get(attr) or 0)
                    except (TypeError, ValueError):
                        cur = 0
                    if cur < min_dist_emu:
                        anchor.set(attr, str(min_dist_emu))
                        changed += 1
                extent = anchor.find(f"{{{wp_ns}}}extent")
                try:
                    cx = int(extent.get("cx") or 0) if extent is not None else 0
                except (TypeError, ValueError):
                    cx = 0
                pos_off = 0
                pos_h = anchor.find(f"{{{wp_ns}}}positionH")
                if pos_h is not None:
                    off_el = pos_h.find(f"{{{wp_ns}}}posOffset")
                    if off_el is not None and (off_el.text or "").strip():
                        try:
                            pos_off = int(off_el.text)
                        except (TypeError, ValueError):
                            pos_off = 0
                try:
                    dist_r = int(anchor.get("distR") or min_dist_emu)
                except (TypeError, ValueError):
                    dist_r = min_dist_emu
                # Content-left-relative right edge of logo + wrap gap.
                logo_right_emu = max(logo_right_emu, pos_off + cx + dist_r)
        if logo_right_emu <= 0:
            continue
        # EMU → twips (1440 twips/inch, 914400 EMU/inch).
        clear_twips = max(720, int(round(logo_right_emu * 1440 / 914400)))
        for para in header.paragraphs:
            text = re.sub(r"\s+", " ", (para.text or "")).strip().upper()
            if not text:
                continue
            if "PROTOCOL" not in text:
                continue
            if not (
                "INSTALLATION AND OPERATIONAL QUALIFICATION" in text
                or "PERIODIC REQUALIFICATION" in text
                or "QUALIFICATION PROTOCOL" in text
            ):
                continue
            p_el = para._element
            p_pr = p_el.find(qn("w:pPr"))
            if p_pr is None:
                p_pr = OxmlElement("w:pPr")
                p_el.insert(0, p_pr)
            ind = p_pr.find(qn("w:ind"))
            if ind is None:
                ind = OxmlElement("w:ind")
                p_pr.append(ind)
            try:
                cur_left = int(ind.get(qn("w:left")) or 0)
            except (TypeError, ValueError):
                cur_left = 0
            if cur_left < clear_twips:
                ind.set(qn("w:left"), str(clear_twips))
                changed += 1
            # Drop a large negative right indent that pulls title into the logo.
            try:
                cur_right = int(ind.get(qn("w:right")) or 0)
            except (TypeError, ValueError):
                cur_right = 0
            if cur_right < 0:
                ind.set(qn("w:right"), "0")
                changed += 1
    return changed


# Active during fill_docx / normalize_existing_filled_docx so TOC/bold helpers
# can apply NEQ-safe rules without threading import_source through every call.
_CURRENT_IMPORT_SOURCE: str | None = None


def _active_import_source() -> str | None:
    return _CURRENT_IMPORT_SOURCE


def _active_is_neq() -> bool:
    return _is_neq_import_source(_CURRENT_IMPORT_SOURCE)


def fill_docx(
    template_path: str,
    field_values: dict,
    output_path: str,
    attachment_links: dict | None = None,
    equipment_id: str | None = None,
    table_ops: list | None = None,
    import_source: str | None = None,
    preserve_toc_pages: bool = False,
) -> tuple[str, dict[str, str]]:
    """
    Fill template DOCX with user values.
    Body fields are written first; header XML is restored from the template (logos),
    then header text fields are applied so fills are not overwritten.
    attachment_links maps field location → download URL for clickable attachment cells.

    NEQ-only layout_v2 (defloat / header pct→dxa) is gated by import_source so
    requalification / revalidation keep their legacy filled layout.

    Returns (output_path, repaired_values) where repaired_values maps location →
    restored template cell text for fields that had column-header echo corruption.
    """
    global _CURRENT_IMPORT_SOURCE
    prev_source = _CURRENT_IMPORT_SOURCE
    _CURRENT_IMPORT_SOURCE = import_source
    try:
        return _fill_docx_inner(
            template_path,
            field_values,
            output_path,
            attachment_links=attachment_links,
            equipment_id=equipment_id,
            table_ops=table_ops,
            import_source=import_source,
            preserve_toc_pages=preserve_toc_pages,
        )
    finally:
        _CURRENT_IMPORT_SOURCE = prev_source


def _fill_docx_inner(
    template_path: str,
    field_values: dict,
    output_path: str,
    attachment_links: dict | None = None,
    equipment_id: str | None = None,
    table_ops: list | None = None,
    import_source: str | None = None,
    preserve_toc_pages: bool = False,
) -> tuple[str, dict[str, str]]:
    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    shutil.copy2(template_path, output_path)
    doc = Document(output_path)
    try:
        from doc_parser import normalize_attachment_list_paragraphs_to_table
        if normalize_attachment_list_paragraphs_to_table(doc):
            doc.save(output_path)
            doc = Document(output_path)
    except Exception:
        pass

    repaired_values: dict[str, str] = {}

    # Pre-repair header-echo corruption from template cells (List of Attachment only).
    for field in field_values:
        location = field.get("location", "")
        raw_value = field.get("value", "")
        if not location.startswith("table") or "_col_" not in location:
            continue
        if not _value_echoes_column_header(doc, location, str(raw_value or "")):
            continue
        fixed = _repair_header_echo_value(doc, location, str(raw_value or ""))
        if fixed and fixed.strip():
            field["value"] = fixed
            repaired_values[location] = fixed
        else:
            # Skip write — keep whatever is already in the template cell.
            field["value"] = ""
            resolved = _resolve_body_table_cell(doc, location)
            if resolved is not None:
                original = (resolved[3].text or "").strip()
                if original:
                    repaired_values[location] = original

    # Split fields so header fills run after header XML is restored from template.
    header_fields = []
    body_fields = []
    for field in field_values:
        location = field.get("location", "")
        if location.startswith("header_"):
            header_fields.append(field)
        else:
            body_fields.append(field)

    # Insert/delete rows BEFORE writing cells so mid-table Add Row lands at
    # after_row+1, then field locations (already shifted by the fill UI) win.
    if table_ops:
        from neq_docx_save import _apply_table_ops
        _apply_table_ops(doc, table_ops)

    _ensure_table_rows_for_fields(doc, body_fields)

    # Fill multi-blank paragraphs from last blank to first so match indices stay stable.
    body_fields.sort(key=lambda f: _blank_sort_key(f) if "_blank_" in f.get("location", "") else (10**9, 0))

    for field in body_fields:
        _apply_single_field(
            doc,
            field.get("location", ""),
            field.get("value", ""),
            field.get("locked", False),
            attachment_links,
            field.get("type"),
        )

    # Repeat the full leading header block (Att08 = 2 rows). Never mark data rows
    # as headers. For Att08-style multi-row headers, keep data rows intact
    # (cantSplit) so LibreOffice PDF does not orphan Sign/Date across pages.
    try:
        for tbl in doc.tables:
            rows = list(tbl.rows)
            if len(rows) <= 1:
                continue
            header_n = _leading_header_row_count(tbl)
            keep_row_intact = header_n >= 2
            for index, row in enumerate(rows):
                tr_pr = row._tr.get_or_add_trPr()
                headers = tr_pr.findall(qn("w:tblHeader"))
                if header_n > 0 and index < header_n:
                    if not headers:
                        tr_pr.append(OxmlElement("w:tblHeader"))
                    continue
                for node in headers:
                    tr_pr.remove(node)
                if keep_row_intact and tr_pr.find(qn("w:cantSplit")) is None:
                    tr_pr.append(OxmlElement("w:cantSplit"))
    except Exception:
        pass

    add_footer_to_doc(doc)
    _repair_mashed_bullet_cells(doc)
    doc.save(output_path)
    # Restore original header XML so logos and images are not lost after body fill.
    _restore_header_parts(template_path, output_path)

    doc = Document(output_path)
    if header_fields:
        for field in header_fields:
            _apply_single_field(
                doc,
                field.get("location", ""),
                field.get("value", ""),
                field.get("locked", False),
                attachment_links,
                field.get("type"),
            )
    # Requalification: Equipment ID. No. value comes from the NEQ record.
    _write_header_equipment_id(doc, equipment_id or "")
    # Keep the template font sizes. Do not restyle runs to Arial 11, and do not
    # insert hard breaks in the middle of words such as "Instrument" or "Calibration".
    from neq_docx_save import (
        _keep_filled_text_inside_cells,
        _clamp_inflated_letterhead_top_margin,
    )
    _keep_filled_text_inside_cells(doc, unify_font=False, break_long_tokens=False)
    _clamp_inflated_letterhead_top_margin(doc)
    # 14/11 baseline (requal/reval): defloat + clamp. NEQ adds header pct→dxa.
    _normalize_filled_docx_layout(
        doc,
        profile="neq" if _is_neq_import_source(import_source) else "legacy",
    )
    _drop_floating_tables_below_following_text(doc)
    _fill_blank_sl_no_cells(doc)
    _trim_empty_paras_before_attachment_heading(doc, keep=2)
    # NEQ: TOC sheet must stay free — 1.0 OBJECTIVE starts on the next page.
    _ensure_neq_body_starts_after_toc(doc)
    _repair_mashed_bullet_cells(doc)
    _repair_abbreviation_entries(doc)
    _keep_approval_names_inside_cells(doc)
    _apply_heading_font_from_paragraph_mark(doc)
    _unbold_filled_body_lines(doc)
    _normalize_neq_document_fonts(doc)
    _ensure_att14_cover_titles_bold(doc)
    _normalize_requal_body_fonts_to_arial_11(doc)
    _normalize_requal_numbering_fonts(doc)
    _ensure_neq_header_logo_title_gap(doc)
    _inject_page_number_fields(doc)
    # NEQ letterhead uses Arial 12. Requal/reval keep original header sizes
    # (often 10/11/14pt) — forcing 12pt changed density vs the Word original.
    if _is_neq_import_source(import_source):
        _normalize_header_table_fonts(doc, font_name="Arial", font_size_pt=12.0)
    # Font unify runs after the footer is written and can leave footer distance at 0.
    _pin_annex_footer_inside_page(doc)
    # Re-apply after approval clamp so every body cell keeps ~1-letter padding.
    _ensure_body_cell_margins(doc)
    # Att14: undo any prior bad rewrite of body "toc 3" headings before TOC realign.
    _restore_misstyled_att14_body_headings(doc)
    _ensure_att14_cover_titles_bold(doc)
    # [TOC] Save filled DOCX first. Final fill recomputes page numbers.
    # Word preview / NEQ final keep the digits typed on the form.
    doc.save(output_path)
    if not preserve_toc_pages:
        toc_updates = recompute_toc_page_numbers(doc, layout_docx_path=output_path)
        if toc_updates:
            repaired_values.update(toc_updates)
    # Guarantee every TOC page digit sits on the same right-tab column.
    # Table TOC (Att14) uses literal ellipsis leaders — tabs do not print in LO PDF.
    _realign_toc_page_columns(doc)
    if preserve_toc_pages:
        # Last write wins: put the form digits back after realign/layout.
        _reapply_preserved_toc_pages(doc, body_fields)
    doc.save(output_path)

    return output_path, repaired_values


def normalize_existing_filled_docx(docx_path: str, import_source: str | None = None) -> str:
    """
    Apply view/download layout fixes to an already-filled DOCX in place.

    Requal/reval use 14/11 layout_v1 profile. NEQ uses layout_v2 (header pct→dxa).
    """
    global _CURRENT_IMPORT_SOURCE
    if not docx_path or not os.path.isfile(docx_path):
        raise FileNotFoundError(f"Filled DOCX not found: {docx_path}")
    prev_source = _CURRENT_IMPORT_SOURCE
    _CURRENT_IMPORT_SOURCE = import_source
    try:
        profile = "neq" if _is_neq_import_source(import_source) else "legacy"
        doc = Document(docx_path)
        _normalize_filled_docx_layout(doc, profile=profile)
        _ensure_body_cell_margins(doc)
        _keep_approval_names_inside_cells(doc)
        _repair_mashed_bullet_cells(doc)
        _repair_abbreviation_entries(doc)
        _pin_annex_footer_inside_page(doc)
        from neq_docx_save import _clamp_inflated_letterhead_top_margin
        _clamp_inflated_letterhead_top_margin(doc)
        _trim_empty_paras_before_attachment_heading(doc, keep=2)
        _ensure_neq_body_starts_after_toc(doc)
        # Re-apply heading keep/Arial-11 after layout so 8.1 Calibration matches original.
        _unbold_filled_body_lines(doc)
        _normalize_neq_document_fonts(doc)
        _ensure_att14_cover_titles_bold(doc)
        _normalize_requal_body_fonts_to_arial_11(doc)
        _normalize_requal_numbering_fonts(doc)
        _ensure_neq_header_logo_title_gap(doc)
        _restore_misstyled_att14_body_headings(doc)
        _ensure_att14_cover_titles_bold(doc)
        _realign_toc_page_columns(doc)
        doc.save(docx_path)
        return docx_path
    finally:
        _CURRENT_IMPORT_SOURCE = prev_source


# ── TOC recompute after final fill (post-approval stamps included) ───────────
# Easy map:
#   1) [WORD COM]  Microsoft Word reads real page numbers (best match for users)
#   2) [LIBREOFFICE] Only if Word fails — converts DOCX→PDF, then we read pages
#   3) Soft estimate if both fail
# Trigger API: POST http://127.0.0.1:8001/fill

def _block_has_page_break(element) -> bool:
    try:
        xml_b = element.xml if hasattr(element, "xml") else None
        if xml_b is None:
            from lxml import etree
            xml_b = etree.tostring(element)
        if isinstance(xml_b, str):
            xml_b = xml_b.encode("utf-8", errors="ignore")
        return (
            b"lastRenderedPageBreak" in xml_b
            or b'type="page"' in xml_b
            or b"<w:pageBreakBefore" in xml_b
        )
    except Exception:
        return False


def _estimate_block_lines(text: str, is_table: bool = False, row_count: int = 0) -> float:
    """Rough A4 body consumption for soft page breaks after content grows."""
    if is_table:
        return max(1.5, float(row_count) * 1.15)
    flat = re.sub(r"\s+", " ", (text or "")).strip()
    if not flat:
        return 0.4
    return max(1.0, (len(flat) / 95.0) + flat.count("\n") * 0.6)


def _iter_body_blocks(doc):
    """Yield (kind, element, text, meta) for body paragraphs and tables in order."""
    from docx.table import Table
    from docx.text.paragraph import Paragraph

    body = doc.element.body
    tbl_i = 0
    for child in list(body.iterchildren()):
        if child.tag == qn("w:tbl"):
            table = Table(child, doc)
            text = "\n".join(
                re.sub(r"\s+", " ", (c.text or "")).strip()
                for row in table.rows
                for c in row.cells
            )
            yield ("table", child, text, {"rows": len(table.rows), "table": table, "index": tbl_i})
            tbl_i += 1
        elif child.tag == qn("w:p"):
            para = Paragraph(child, doc)
            text = re.sub(r"\s+", " ", (para.text or "")).strip()
            yield ("para", child, text, {"para": para})


def _section_heading_match(text: str):
    """Return (sec_num, title) for major headings like '3.0 RESPONSIBILITY'."""
    flat = re.sub(r"\s+", " ", (text or "")).strip()
    if not flat or len(flat) > 140:
        return None
    m = re.match(r"^(\d+\.0)\s+([A-Za-z].+)$", flat)
    if not m:
        return None
    title = re.sub(r"[\.\:\-_]+$", "", m.group(2)).strip()
    if len(title) < 3:
        return None
    return m.group(1), title


def _score_heading_candidate(sec_num: str, title: str, toc_title: str) -> int:
    score = 20
    if re.match(r"^\d+\.0$", sec_num):
        score += 30
    toc_words = [
        w for w in re.sub(r"[\.\:\-_]+", " ", (toc_title or "").upper()).split()
        if len(w) > 2 and w not in {"AND", "THE", "FOR", "ANY", "OF"}
    ]
    upper = (title or "").upper()
    hits = sum(1 for w in toc_words if w in upper)
    score += hits * 25
    if hits == 0 and toc_words:
        score -= 35
    if len(title) <= 60:
        score += 10
    return score


def _map_section_pages(doc) -> dict[str, dict]:
    """
    Map major section numbers (1.0, 2.0, …) to {page, title} in the filled DOCX.
    Uses hard page breaks when present, otherwise soft A4 estimate.
    """
    usable_lines = 46.0
    page = 1
    used = 0.0
    # sec -> list of (score, page, title)
    candidates: dict[str, list[tuple[int, int, str]]] = {}

    for kind, element, text, meta in _iter_body_blocks(doc):
        if _block_has_page_break(element) and (text or kind == "table"):
            if used > 2:
                page += 1
                used = 0.0

        matched = _section_heading_match(text) if kind == "para" else None
        if matched:
            sec, title = matched
            score = _score_heading_candidate(sec, title, title)
            candidates.setdefault(sec, []).append((score, page, title))

        lines = _estimate_block_lines(
            text,
            is_table=(kind == "table"),
            row_count=int(meta.get("rows") or 0),
        )
        used += lines
        while used >= usable_lines:
            page += 1
            used -= usable_lines

    best: dict[str, dict] = {}
    for sec, rows in candidates.items():
        rows.sort(key=lambda r: (r[0], r[1]), reverse=True)
        top_score = rows[0][0]
        tied = [r for r in rows if r[0] == top_score]
        tied.sort(key=lambda r: r[1])
        score, page_no, title = tied[-1]
        best[sec] = {"page": page_no, "title": title, "score": score}
    return best


def _toc_search_query(title: str) -> str:
    """Build a Word Find query from a TOC title (skip section number)."""
    flat = re.sub(r"\s+", " ", (title or "")).strip()
    flat = re.sub(r"^\d+(?:\.\d+)*\s*", "", flat).strip()
    flat = re.sub(r"[\.\:\-_]+$", "", flat).strip()
    if not flat:
        return ""
    # Prefer distinctive multi-word titles; strip noisy punctuation for Find.
    flat = flat.replace("/", " ").replace("’", "'")
    flat = re.sub(r"\s+", " ", flat).strip()
    # Very long SOP titles still match when shortened.
    if len(flat) > 48:
        parts = [w for w in flat.split() if len(w) > 2]
        flat = " ".join(parts[:5])
    return flat


def _is_toc_body_paragraph(para) -> bool:
    """True for Word TOC lines in body (style toc 1 / tab + page number)."""
    style = ""
    try:
        style = ((para.style.name if para.style else "") or "").lower()
    except Exception:
        style = ""
    raw = (para.text or "").strip()
    has_ellipsis = bool(re.search(r"[\.\u2026]{3,}", raw))
    has_tab = "\t" in raw
    # Att14 mis-styles body headings (1.0 Objective, 3.2 …) as "toc 3".
    # Those must never be rewritten as TOC — even after a bad prior fill left a tab+page.
    if "toc 3" in style or style.replace(" ", "") in ("toc3",):
        return has_ellipsis
    if style.startswith("toc"):
        return has_tab or has_ellipsis
    if not re.match(r"^\d+\.0\b", raw):
        return False
    return has_tab or has_ellipsis


def _ensure_att14_cover_titles_bold(doc) -> int:
    """Force bold on Att14 '(IOQ) REPORT FOR … MACHINE' / Identification lines."""
    fixed = 0
    for para in doc.paragraphs:
        text = para.text or ""
        if not re.search(
            r"\(IOQ\)\s*REPORT\s+FOR|Identification\s*No\.?\s*:",
            text,
            re.I,
        ):
            continue
        touched = False
        for run in para.runs:
            if not (run.text or "").strip():
                continue
            run.bold = True
            touched = True
        if touched:
            fixed += 1
    return fixed


def _restore_misstyled_att14_body_headings(doc) -> int:
    """
    Undo bad fills that turned Att14 body headings into \"1.0 Objective\\t2\"
    because Word marked them style toc 3.
    """
    fixed = 0
    for para in doc.paragraphs:
        style = ""
        try:
            style = ((para.style.name if para.style else "") or "").lower()
        except Exception:
            style = ""
        if "toc 3" not in style and style.replace(" ", "") not in ("toc3",):
            continue
        raw = (para.text or "").strip()
        if not raw or re.search(r"[\.\u2026]{3,}", raw):
            continue
        m = re.match(
            r"^(\d+(?:\.\d+)*\s+[A-Za-z][^\t]*?)(?:\t|\s+)(\d{1,3})\s*$",
            raw,
        )
        if not m:
            continue
        title = re.sub(r"\s+", " ", m.group(1)).strip()
        if not title:
            continue
        _clear_text_runs(para)
        run = para.add_run(title)
        run.bold = True
        try:
            para.style = "Normal"
        except Exception:
            pass
        fixed += 1
    return fixed


def _para_element_in_table(p_el) -> bool:
    """True when a paragraph OOXML element lives inside a w:tc (TOC table cells)."""
    parent = p_el.getparent() if p_el is not None else None
    while parent is not None:
        tag = parent.tag.split("}")[-1] if isinstance(parent.tag, str) else ""
        if tag == "tc":
            return True
        if tag in ("body", "hdr", "ftr", "document"):
            return False
        parent = parent.getparent()
    return False


def _collect_toc_entries_from_doc(doc) -> list[dict]:
    """Read current TOC SDT/table/body lines as {sec, title, query}."""
    entries: list[dict] = []
    seen = set()

    def push(title: str) -> None:
        title = re.sub(r"\s+", " ", (title or "")).strip()
        if not title:
            return
        m = re.match(r"^(\d+\.0)\b\s*(.*)$", title)
        if not m:
            return
        sec = m.group(1)
        if sec in seen:
            return
        rest = (m.group(2) or "").strip() or title
        query = _toc_search_query(title)
        if not query:
            return
        seen.add(sec)
        entries.append({"sec": sec, "title": rest[:90], "query": query})

    sdt_paras = _get_sdt_toc_paragraphs(doc)
    for p_el in sdt_paras:
        title, _old = _parse_toc_title_from_element(p_el)
        push(title)

    if entries:
        return entries

    # NEQ IOQ templates keep TOC as body paragraphs (style "toc 1"), not SDT/table.
    for para in doc.paragraphs:
        if not _is_toc_body_paragraph(para):
            continue
        title, _old = _parse_toc_title_from_element(para._element)
        push(title)
    if entries:
        return entries

    for table in doc.tables:
        if not table.rows:
            continue
        for row in table.rows[:30]:
            if not row.cells:
                continue
            for para in row.cells[0].paragraphs:
                title, _old = _parse_toc_title_from_element(para._element)
                push(title)
    return entries


_WORD_TOC_MAP_PS1 = r"""
param([string]$DocPath, [string]$QueriesPath)
$ErrorActionPreference = "Stop"
$word = $null
$doc = $null
try {
  $queries = Get-Content -Raw -Path $QueriesPath | ConvertFrom-Json
  $word = New-Object -ComObject Word.Application
  $word.Visible = $false
  $word.DisplayAlerts = 0
  # Open writable copy semantics so pagination matches what users see (not a stale read-only view).
  $doc = $word.Documents.Open($DocPath, $false, $false)
  try { $doc.Repaginate() } catch {}
  $total = [int]$doc.ComputeStatistics(2)
  $results = @()
  foreach ($q in $queries) {
    $sec = [string]$q.sec
    $text = [string]$q.query
    $title = [string]$q.title
    $bodyPage = 0
    $alt = New-Object System.Collections.Generic.List[string]
    if ($text) { $alt.Add($text) | Out-Null }
    # Fallbacks when exact title punctuation differs in body vs TOC.
    if ($title -match 'EQUIPMENT') {
      $alt.Add('EQUIPMENT / SYSTEM DETAILS') | Out-Null
      $alt.Add('EQUIPMENT /SYSTEM DETAILS') | Out-Null
      $alt.Add('SYSTEM DETAILS') | Out-Null
    }
    if ($title -match 'SUMMARY') {
      $alt.Add('SUMMARY & CONCLUSION') | Out-Null
      $alt.Add('SUMMARY AND CONCLUSION') | Out-Null
      $alt.Add('SUMMARY') | Out-Null
    }
    if ($title -match 'REFERENCES') { $alt.Add('REFERENCES') | Out-Null }
    if ($title -match 'REVISION') { $alt.Add('REVISION HISTORY') | Out-Null }
    if ($title -match 'POST') {
      $alt.Add('POST-APPROVAL') | Out-Null
      $alt.Add('POST APPROVAL') | Out-Null
      $alt.Add('POST-APPROVAL SIGNATURE') | Out-Null
    }
    if ($title -match 'attachment') {
      $alt.Add('LIST OF ATTACHMENT') | Out-Null
      $alt.Add('list of attachment') | Out-Null
    }
    $seenAlt = @{}
    foreach ($needle in $alt) {
      if (-not $needle -or $seenAlt.ContainsKey($needle)) { continue }
      $seenAlt[$needle] = $true
      $start = 0
      for ($n=0; $n -lt 12; $n++) {
        $rng = $doc.Range([Math]::Max($start,0), $doc.Content.End)
        $find = $rng.Find
        $null = $find.ClearFormatting()
        $find.Text = $needle
        $find.Forward = $true
        $find.Wrap = 0
        $find.MatchCase = $false
        if (-not $find.Execute()) { break }
        $page = [int]$rng.Information(3)
        # Skip TOC sheet (page 1-2). First later hit is the real section heading.
        if ($page -gt 2) {
          $bodyPage = $page
          break
        }
        $start = [int]$rng.End + 1
        if ($start -ge $doc.Content.End) { break }
      }
      if ($bodyPage -gt 0) { break }
    }
    $results += [pscustomobject]@{ sec=$sec; title=$title; page=$bodyPage; query=$text }
  }
  ([pscustomobject]@{ total=$total; sections=$results }) | ConvertTo-Json -Compress -Depth 5
} finally {
  if ($doc -ne $null) { $doc.Close($false) | Out-Null }
  if ($word -ne $null) { $word.Quit() | Out-Null }
  [GC]::Collect() | Out-Null
}
"""


def _map_section_pages_from_word(docx_path: str, toc_entries: list[dict] | None = None) -> dict[str, dict]:
    """
    [WORD COM] Open the filled DOCX in Microsoft Word (PowerShell COM) and read
    the real page of each TOC heading. This is NOT LibreOffice.
    Skips TOC-page hits (page 1–2) so body headings win.
    Used by: recompute_toc_page_numbers() during API POST /fill.
    """
    import json
    import subprocess
    import tempfile

    if os.name != "nt" or not docx_path or not os.path.isfile(docx_path):
        return {}

    entries = list(toc_entries or [])
    if not entries:
        # Minimal fallback queries for common requal TOC.
        entries = [
            {"sec": "15.0", "title": "REVISION HISTORY", "query": "REVISION HISTORY"},
        ]

    tmp_dir = tempfile.mkdtemp(prefix="valiflow_word_toc_")
    ps1_path = os.path.join(tmp_dir, "word_map.ps1")
    queries_path = os.path.join(tmp_dir, "queries.json")
    try:
        with open(ps1_path, "w", encoding="utf-8") as fh:
            fh.write(_WORD_TOC_MAP_PS1)
        with open(queries_path, "w", encoding="utf-8") as fh:
            json.dump(entries, fh, ensure_ascii=False)

        cmd = [
            "powershell",
            "-NoProfile",
            "-NonInteractive",
            "-File",
            ps1_path,
            "-DocPath",
            os.path.abspath(docx_path),
            "-QueriesPath",
            queries_path,
        ]
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=300)
        raw = (result.stdout or "").strip()
        if not raw:
            return {}
        # Last JSON object line (ignore Word COM noise).
        payload = None
        for line in reversed(raw.splitlines()):
            line = line.strip()
            if line.startswith("{") and "sections" in line:
                payload = json.loads(line)
                break
        if not isinstance(payload, dict):
            return {}

        found: dict[str, dict] = {}
        for row in payload.get("sections") or []:
            if not isinstance(row, dict):
                continue
            sec = str(row.get("sec") or "").strip()
            page = int(row.get("page") or 0)
            title = str(row.get("title") or "").strip()
            if not sec or page < 3:
                continue
            found[sec] = {"page": page, "title": title[:90], "score": 200}
        return found
    except Exception:
        return {}
    finally:
        shutil.rmtree(tmp_dir, ignore_errors=True)


def _find_libreoffice_bin() -> str | None:
    # [LIBREOFFICE] Find soffice.exe / libreoffice on this machine.
    # Env override: LIBREOFFICE_BIN
    env = (os.getenv("LIBREOFFICE_BIN") or "soffice").strip()
    if env and os.path.isfile(env):
        return env
    found = shutil.which(env) if env else None
    if found:
        return found
    for candidate in (
        r"C:\Program Files\LibreOffice\program\soffice.exe",
        r"C:\Program Files (x86)\LibreOffice\program\soffice.exe",
        "/usr/bin/soffice",
        "/usr/bin/libreoffice",
    ):
        if os.path.isfile(candidate):
            return candidate
    return None


def _docx_to_pdf_for_toc(docx_path: str) -> str | None:
    """
    [LIBREOFFICE] Convert filled DOCX → PDF with: soffice --headless --convert-to pdf
    Used ONLY when Microsoft Word is not available for TOC page mapping.
    Related API path: POST /fill → fill_docx() → recompute_toc_page_numbers()
    """
    import subprocess
    import tempfile

    lo = _find_libreoffice_bin()
    if not lo or not os.path.isfile(docx_path):
        return None
    out_dir = tempfile.mkdtemp(prefix="valiflow_toc_")
    try:
        # [LIBREOFFICE COMMAND] soffice --headless --convert-to pdf --outdir <tmp> <docx>
        cmd = [
            lo,
            "--headless",
            "--norestore",
            "--convert-to",
            "pdf",
            "--outdir",
            out_dir,
            os.path.abspath(docx_path),
        ]
        subprocess.run(cmd, capture_output=True, text=True, timeout=240)
        base = os.path.splitext(os.path.basename(docx_path))[0] + ".pdf"
        pdf_path = os.path.join(out_dir, base)
        if os.path.isfile(pdf_path) and os.path.getsize(pdf_path) > 0:
            return pdf_path
    except Exception:
        return None
    return None


def _map_section_pages_from_pdf(pdf_path: str) -> dict[str, dict]:
    """
    [LIBREOFFICE RESULT] Read section heading pages from the PDF LibreOffice made.
    Uses PyMuPDF (fitz). Backup only — can be ±1 page vs real Word.
    Handles split lines ('1.0' then 'OBJECTIVE:') common in protocol DOCX.
    """
    try:
        import fitz  # PyMuPDF
    except Exception:
        return {}

    try:
        pdf = fitz.open(pdf_path)
    except Exception:
        return {}

    found: dict[str, dict] = {}
    try:
        for i in range(pdf.page_count):
            page_no = i + 1
            lines = [
                re.sub(r"\s+", " ", ln).strip()
                for ln in pdf.load_page(i).get_text("text").splitlines()
            ]
            lines = [ln for ln in lines if ln]
            # Page 1 is always the TOC sheet — never treat its section numbers as body hits.
            # Page 2 may contain both residual TOC lines and body (1.0 OBJECTIVE / 2.0 SCOPE).
            if page_no > 1:
                for idx, ln in enumerate(lines):
                    if "TABLE OF CONTENTS" in ln.upper():
                        continue
                    # TOC leader lines (…… 13) — never treat as body headings.
                    if re.search(r"[\.\u2026]{3,}", ln):
                        continue

                    sec = None
                    title = None
                    m_num = re.match(r"^(\d+\.0)\s*$", ln)
                    if m_num and idx + 1 < len(lines):
                        nxt = lines[idx + 1]
                        if re.match(r"^[A-Za-z/(]", nxt) and not re.search(r"[\.\u2026]{3,}", nxt):
                            # Trailing page digit = still a TOC row on page 2.
                            if page_no == 2 and re.search(r"\b\d{1,2}\s*$", nxt):
                                continue
                            sec = m_num.group(1)
                            title = re.sub(r"[:\s]+$", "", nxt).strip()
                    else:
                        m = re.match(r"^(\d+\.0)\s+([A-Za-z/(].+)$", ln)
                        if not m:
                            continue
                        if re.search(r"[\.\u2026]{3,}", ln):
                            continue
                        cand_title = m.group(2).strip()
                        # Skip glued TOC leftovers like "POST APPROVAL 17".
                        if re.search(r"\d+\s*$", cand_title) and not re.search(
                            r"[A-Za-z:)\]]$", cand_title
                        ):
                            continue
                        if page_no == 2 and re.search(r"\b\d{1,2}\s*$", cand_title):
                            continue
                        sec = m.group(1)
                        title = re.sub(r"[:\s]+$", "", cand_title).strip()

                    if not sec or not title or len(title) < 3 or len(title) > 120:
                        continue
                    # First content hit wins (true section start).
                    if sec not in found:
                        found[sec] = {"page": page_no, "title": title[:90], "score": 100}

            # Capture APPROVAL PAGE (often unnumbered) so TOC can use the real last PDF page.
            if page_no > 2:
                for ln in lines:
                    if re.search(r"^APPROVAL\s+PAGE\b", ln, re.I):
                        found.setdefault(
                            "APPROVAL",
                            {"page": page_no, "title": "APPROVAL PAGE", "score": 100},
                        )
                        break
    finally:
        pdf.close()
    return found


def _parse_toc_title_from_element(p_el) -> tuple[str, str]:
    tokens = []
    for elem in p_el.iter():
        tag = elem.tag.split("}")[-1]
        if tag == "t" and elem.text:
            tokens.append(elem.text)
        elif tag == "tab":
            tokens.append("\t")
    raw = "".join(tokens).strip()
    if not raw or raw.lower() == "table of contents":
        return "", ""
    # Prefer split on tab (title | page).
    if "\t" in raw:
        left, right = raw.rsplit("\t", 1)
        if not re.search(r"[A-Za-z]", right):
            page = re.sub(r"\D", "", right)
            title = re.sub(r"[\.\u2026\u22ef\t_:]+$", "", left).strip()
            title = re.sub(r"^(\d+\.\d+)\s*([A-Za-z])", r"\1 \2", title)
            return re.sub(r"\s+", " ", title), page
        else:
            m = re.search(r"^(.*?)[ \t\.\u2026\u22ef]+(\d+)\s*$", right)
            if m:
                title = f"{left} {m.group(1)}"
                page = m.group(2)
                title = re.sub(r"[\.\u2026\u22ef\t_:]+$", "", title).strip()
                title = re.sub(r"^(\d+\.\d+)\s*([A-Za-z])", r"\1 \2", title)
                return re.sub(r"\s+", " ", title), page
    m = re.search(r"^(.*?)[ \t\.\u2026\u22ef]+(\d+)\s*$", raw)
    if not m:
        m = re.search(r"^(.*?)(\d+)\s*$", raw)
    if m:
        title = re.sub(r"[\.\u2026\u22ef\t_:]+$", "", m.group(1)).strip()
        title = re.sub(r"^(\d+\.\d+)\s*([A-Za-z])", r"\1 \2", title)
        return re.sub(r"\s+", " ", title), m.group(2).strip()
    return re.sub(r"\s+", " ", raw), ""


def _ppr_insert_tabs(p_pr, tabs_el) -> None:
    """Insert w:tabs in OOXML schema order (before spacing/ind/jc/rPr)."""
    old = p_pr.find(qn("w:tabs"))
    if old is not None:
        p_pr.remove(old)
    after_tags = {
        qn("w:pStyle"),
        qn("w:keepNext"),
        qn("w:keepLines"),
        qn("w:pageBreakBefore"),
        qn("w:framePr"),
        qn("w:widowControl"),
        qn("w:numPr"),
        qn("w:suppressLineNumbers"),
        qn("w:pBdr"),
        qn("w:shd"),
        qn("w:tabs"),
    }
    insert_at = 0
    for i, child in enumerate(list(p_pr)):
        if child.tag in after_tags:
            insert_at = i + 1
            continue
        break
    p_pr.insert(insert_at, tabs_el)


def _make_toc_tab_stops(*, for_neq: bool = False):
    """
    Match original requal TOC1 stops used by Word:
      left 1495  → section title column after the number
      right 9000 → page-number column (dot leaders)
    Extra style stops (left 0 / right 9656) make LibreOffice land the first
    content tab too early, so page digits no longer share one vertical line.

    NEQ original visual is \"1.0 OBJECTIVE\" (one space, not a left-tab column).
    Only a right stop at 9000 with dot leaders is needed for the page digit.
    """
    tabs = OxmlElement("w:tabs")
    if not for_neq:
        left = OxmlElement("w:tab")
        left.set(qn("w:val"), "left")
        left.set(qn("w:pos"), "1495")
        tabs.append(left)
    right = OxmlElement("w:tab")
    right.set(qn("w:val"), "right")
    right.set(qn("w:leader"), "dot")
    right.set(qn("w:pos"), "9000")
    tabs.append(right)
    return tabs


def _ensure_neq_toc1_indent(p_pr) -> None:
    """Original NEQ TOC1 uses w:ind w:left=\"-450\" so numbers hang slightly left."""
    if p_pr is None:
        return
    ind = p_pr.find(qn("w:ind"))
    if ind is None:
        ind = OxmlElement("w:ind")
        # Keep tabs/spacing order stable: insert indent after tabs when present.
        tabs = p_pr.find(qn("w:tabs"))
        if tabs is not None:
            tabs_index = list(p_pr).index(tabs)
            p_pr.insert(tabs_index + 1, ind)
        else:
            p_pr.append(ind)
    ind.set(qn("w:left"), "-450")
    if ind.get(qn("w:firstLine")) is not None:
        del ind.attrib[qn("w:firstLine")]
    if ind.get(qn("w:hanging")) is not None:
        del ind.attrib[qn("w:hanging")]


def _fix_toc1_style_tabs(doc) -> None:
    """Force TOC1/TOC2 style tab stops to the original requal / NEQ two-stop layout."""
    try:
        styles_el = doc.styles.element
    except Exception:
        return
    for_neq = _active_is_neq()
    for style in styles_el.findall(qn("w:style")):
        sid = (style.get(qn("w:styleId")) or "").upper()
        name_el = style.find(qn("w:name"))
        nm = (name_el.get(qn("w:val")) if name_el is not None else "") or ""
        if sid not in {"TOC1", "TOC2"} and not re.match(r"^toc\s*[12]$", nm, re.I):
            continue
        p_pr = style.find(qn("w:pPr"))
        if p_pr is None:
            p_pr = OxmlElement("w:pPr")
            style.insert(0, p_pr)
        _ppr_insert_tabs(p_pr, _make_toc_tab_stops(for_neq=for_neq))
        if for_neq:
            # Original TOC1: Arial + bold at style; content is \"1.0 TITLE\" + right tab.
            _ensure_neq_toc1_indent(p_pr)
            spacing = p_pr.find(qn("w:spacing"))
            if spacing is None:
                spacing = OxmlElement("w:spacing")
                p_pr.append(spacing)
            spacing.set(qn("w:before"), "120")
            spacing.set(qn("w:after"), "120")
            spacing.set(qn("w:line"), "240")
            spacing.set(qn("w:lineRule"), "auto")
            r_pr = style.find(qn("w:rPr"))
            if r_pr is None:
                r_pr = OxmlElement("w:rPr")
                style.append(r_pr)
            fonts = r_pr.find(qn("w:rFonts"))
            if fonts is None:
                fonts = OxmlElement("w:rFonts")
                r_pr.insert(0, fonts)
            fonts.set(qn("w:ascii"), "Arial")
            fonts.set(qn("w:hAnsi"), "Arial")
            fonts.set(qn("w:cs"), "Arial")
            if r_pr.find(qn("w:b")) is None:
                r_pr.append(OxmlElement("w:b"))
            if r_pr.find(qn("w:bCs")) is None:
                r_pr.append(OxmlElement("w:bCs"))


def _rewrite_toc_paragraph_element(p_el, title: str, page_value: str) -> bool:
    """
    Rebuild one TOC line like the original requal Word template:

      1.0  [tab→1495]  OBJECTIVE  [tab→9000 + dots]  3

    Two content tabs + the two style/paragraph stops keep every page digit on
    one right column in both Word and LibreOffice PDF (final view).

    NEQ matches original IOQ visual:
      \"1.0 OBJECTIVE\" [tab→9000 + dots] 2
    One space after the number (no left-tab column), Arial 11 bold, so long
    titles (10.0 / 13.0) stay on one line with the page digit.

    Att14 TOC lives in a 1-col table — LibreOffice ignores tab leaders inside
    table cells, so those lines use literal ellipsis leaders like the Word file.
    """
    title = re.sub(r"\s+", " ", (title or "")).strip()
    # Drop trailing leader junk if a prior bad fill left dots on the title.
    title = re.sub(r"[\.\u2026\u22ef\t_:]+$", "", title).strip()
    page_value = str(page_value or "").strip()
    if not title or not page_value:
        return False

    m_num = re.match(r"^(\d+(?:\.\d+)*)\s*(.*)$", title)
    if m_num:
        num_part = m_num.group(1)
        rest_part = (m_num.group(2) or "").strip()
    else:
        num_part = title
        rest_part = ""

    for_neq = _active_is_neq()
    in_table = _para_element_in_table(p_el)

    # Keep style id (TOC1 / Header) if present.
    style_id = "TOC1"
    old_pr = p_el.find(qn("w:pPr"))
    if old_pr is not None:
        ps = old_pr.find(qn("w:pStyle"))
        if ps is not None and (ps.get(qn("w:val")) or "").strip():
            style_id = ps.get(qn("w:val"))

    for child in list(p_el):
        p_el.remove(child)

    p_pr = OxmlElement("w:pPr")
    p_el.append(p_pr)
    ps = OxmlElement("w:pStyle")
    ps.set(qn("w:val"), style_id)
    p_pr.append(ps)
    # Match original TOC1 paragraph spacing (before/after 120, single line).
    if for_neq and not in_table:
        spacing_p = OxmlElement("w:spacing")
        spacing_p.set(qn("w:before"), "120")
        spacing_p.set(qn("w:after"), "120")
        spacing_p.set(qn("w:line"), "240")
        spacing_p.set(qn("w:lineRule"), "auto")
        p_pr.append(spacing_p)
    # Body TOC: tab leaders. Table TOC (Att14): literal dots (tabs fail in LO PDF).
    if not in_table:
        _ppr_insert_tabs(p_pr, _make_toc_tab_stops(for_neq=for_neq))
        if for_neq:
            _ensure_neq_toc1_indent(p_pr)

    def _append_text_run(text: str, *, bold: bool = True) -> None:
        if text is None:
            return
        run = OxmlElement("w:r")
        r_pr = OxmlElement("w:rPr")
        fonts = OxmlElement("w:rFonts")
        fonts.set(qn("w:ascii"), "Arial")
        fonts.set(qn("w:hAnsi"), "Arial")
        fonts.set(qn("w:cs"), "Arial")
        r_pr.append(fonts)
        if bold:
            r_pr.append(OxmlElement("w:b"))
            r_pr.append(OxmlElement("w:bCs"))
        sz = OxmlElement("w:sz")
        sz.set(qn("w:val"), "22")  # 11pt
        r_pr.append(sz)
        sz_cs = OxmlElement("w:szCs")
        sz_cs.set(qn("w:val"), "22")
        r_pr.append(sz_cs)
        # Slight negative spacing; NEQ bold + single-space layout needs a bit
        # more condensation so 10.0 / 13.0 stay on one line in LibreOffice PDF.
        spacing = OxmlElement("w:spacing")
        spacing.set(qn("w:val"), "-6" if for_neq else "-1")
        r_pr.append(spacing)
        run.append(r_pr)
        t_el = OxmlElement("w:t")
        if text.startswith(" ") or text.endswith(" "):
            t_el.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
        t_el.text = text
        run.append(t_el)
        p_el.append(run)

    def _append_tab() -> None:
        tab_run = OxmlElement("w:r")
        tab_run.append(OxmlElement("w:tab"))
        p_el.append(tab_run)

    label = f"{num_part} {rest_part}".strip() if rest_part else num_part
    if in_table:
        # Att14 TOC: title left + right-tab (dot leader) + page.
        # Page digits land on one fixed column (Word + LibreOffice). Literal
        # ellipsis padding alone cannot align proportional Arial widths.
        rest = re.sub(r"\s+", " ", (rest_part or "")).strip().upper()
        label = f"{num_part}   {rest}".rstrip() if rest else num_part
        page_s = str(page_value).strip()
        tabs = OxmlElement("w:tabs")
        right = OxmlElement("w:tab")
        right.set(qn("w:val"), "right")
        right.set(qn("w:leader"), "dot")
        # Att14 TOC cell is 9513 twips — sit the page column just inside the edge.
        right.set(qn("w:pos"), "9300")
        tabs.append(right)
        _ppr_insert_tabs(p_pr, tabs)
        _append_text_run(label, bold=True)
        _append_tab()
        _append_text_run(page_s, bold=True)
    elif for_neq:
        # Original look: \"1.0 OBJECTIVE\" then right-tab to page (one space only).
        _append_text_run(label, bold=True)
        _append_tab()
        _append_text_run(page_value, bold=True)
    else:
        _append_text_run(num_part, bold=True)
        _append_tab()
        if rest_part:
            _append_text_run(rest_part, bold=True)
        _append_tab()
        _append_text_run(page_value, bold=True)
    return True


def _iter_toc_paragraph_elements(doc):
    """Yield OOXML paragraph elements that belong to the document TOC."""
    sdt_paras = _get_sdt_toc_paragraphs(doc)
    for p_el in sdt_paras:
        yield p_el
    if not sdt_paras:
        for para in doc.paragraphs:
            if _is_toc_body_paragraph(para):
                yield para._element
    for table in doc.tables:
        if not table.rows:
            continue
        for row in table.rows[:40]:
            if not row.cells:
                continue
            for para in row.cells[0].paragraphs:
                raw = (para.text or "").strip()
                if not raw:
                    continue
                if not re.match(r"^\d+(?:\.\d+)*\s+\S", raw):
                    continue
                if "...." not in raw and "…" not in raw and not re.search(r"[\.\u2026]{3,}", raw):
                    if "\t" not in raw and not re.search(r"\d+\s*$", raw):
                        continue
                yield para._element


def _toc_run_arial_bold_11(text: str):
    """One Arial 11 bold run (Att14 TOC look)."""
    run = OxmlElement("w:r")
    r_pr = OxmlElement("w:rPr")
    fonts = OxmlElement("w:rFonts")
    fonts.set(qn("w:ascii"), "Arial")
    fonts.set(qn("w:hAnsi"), "Arial")
    fonts.set(qn("w:cs"), "Arial")
    r_pr.append(fonts)
    r_pr.append(OxmlElement("w:b"))
    r_pr.append(OxmlElement("w:bCs"))
    sz = OxmlElement("w:sz")
    sz.set(qn("w:val"), "22")
    r_pr.append(sz)
    sz_cs = OxmlElement("w:szCs")
    sz_cs.set(qn("w:val"), "22")
    r_pr.append(sz_cs)
    run.append(r_pr)
    t_el = OxmlElement("w:t")
    if text.startswith(" ") or text.endswith(" "):
        t_el.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
    t_el.text = text
    run.append(t_el)
    return run


def _make_toc_tc(width_dxa: str, paragraphs_xml: list) -> "OxmlElement":
    """Build a table cell with fixed dxa width and the given paragraph elements."""
    tc = OxmlElement("w:tc")
    tc_pr = OxmlElement("w:tcPr")
    tc_w = OxmlElement("w:tcW")
    tc_w.set(qn("w:w"), str(width_dxa))
    tc_w.set(qn("w:type"), "dxa")
    tc_pr.append(tc_w)
    # Keep Att14 TOC grid clean — no cell borders fighting the page column.
    tc_borders = OxmlElement("w:tcBorders")
    for edge in ("top", "left", "bottom", "right"):
        el = OxmlElement(f"w:{edge}")
        el.set(qn("w:val"), "nil")
        tc_borders.append(el)
    tc_pr.append(tc_borders)
    tc.append(tc_pr)
    if not paragraphs_xml:
        paragraphs_xml = [OxmlElement("w:p")]
    for p_el in paragraphs_xml:
        tc.append(p_el)
    return tc


# Arial Bold 11pt advance widths in twips (measured from arialbd.ttf).
# Every TOC leader dot uses this same period width so the glyph size matches
# on every New Equipment contents line.
_NEQ_TOC_ARIAL_BOLD_11_TWIPS = {
    "A": 158, "B": 158, "C": 158, "D": 158, "E": 146, "F": 134, "G": 172,
    "H": 158, "I": 62, "J": 122, "K": 158, "L": 134, "M": 184, "N": 158,
    "O": 172, "P": 146, "Q": 172, "R": 158, "S": 146, "T": 134, "U": 158,
    "V": 146, "W": 208, "X": 146, "Y": 146, "Z": 134,
    "0": 122, "1": 122, "2": 122, "3": 122, "4": 122,
    "5": 122, "6": 122, "7": 122, "8": 122, "9": 122,
    " ": 62, ".": 62, "/": 62, "&": 158, "-": 74,
}
_NEQ_TOC_TITLE_CELL_TWIPS = 9000
_NEQ_TOC_CELL_PAD_TWIPS = 108  # Word/LibreOffice default left and right cell pad
_NEQ_TOC_DOT_SAFETY_TWIPS = 200  # keep the last period inside the cell (no wrap)


def _neq_arial_bold_11_twips(text: str) -> int:
    total = 0
    for ch in text or "":
        total += _NEQ_TOC_ARIAL_BOLD_11_TWIPS.get(
            ch, _NEQ_TOC_ARIAL_BOLD_11_TWIPS.get(ch.upper(), 158)
        )
    return total


def _neq_toc_uniform_dot_count(label: str) -> int:
    """How many identical '.' glyphs fit between this title and the page column."""
    usable = _NEQ_TOC_TITLE_CELL_TWIPS - (2 * _NEQ_TOC_CELL_PAD_TWIPS) - _NEQ_TOC_DOT_SAFETY_TWIPS
    remain = usable - _neq_arial_bold_11_twips((label or "") + " ")
    dot_w = _NEQ_TOC_ARIAL_BOLD_11_TWIPS["."]
    return max(8, remain // dot_w)


def _build_neq_att14_uniform_dot_title_para(label: str) -> "OxmlElement":
    """
    New Equipment Att14 contents line.

    One run of Arial Bold 11pt periods — the same glyph, size, and spacing on
    every row. No tab leader and no condensed Courier dots (those two styles
    were printing at different sizes in the Word preview).
    """
    p_el = OxmlElement("w:p")
    p_pr = OxmlElement("w:pPr")
    ps = OxmlElement("w:pStyle")
    ps.set(qn("w:val"), "Header")
    p_pr.append(ps)
    # Empty tab list so the Header style's right tab is not inherited.
    _ppr_insert_tabs(p_pr, OxmlElement("w:tabs"))
    spacing = OxmlElement("w:spacing")
    spacing.set(qn("w:before"), "60")
    spacing.set(qn("w:after"), "60")
    spacing.set(qn("w:line"), "240")
    spacing.set(qn("w:lineRule"), "auto")
    p_pr.append(spacing)
    p_el.append(p_pr)
    p_el.append(_toc_run_arial_bold_11(label + " "))

    dots_run = OxmlElement("w:r")
    r_pr = OxmlElement("w:rPr")
    fonts = OxmlElement("w:rFonts")
    fonts.set(qn("w:ascii"), "Arial")
    fonts.set(qn("w:hAnsi"), "Arial")
    fonts.set(qn("w:cs"), "Arial")
    r_pr.append(fonts)
    r_pr.append(OxmlElement("w:b"))
    r_pr.append(OxmlElement("w:bCs"))
    sz = OxmlElement("w:sz")
    sz.set(qn("w:val"), "22")
    r_pr.append(sz)
    sz_cs = OxmlElement("w:szCs")
    sz_cs.set(qn("w:val"), "22")
    r_pr.append(sz_cs)
    kern = OxmlElement("w:kern")
    kern.set(qn("w:val"), "0")
    r_pr.append(kern)
    sp = OxmlElement("w:spacing")
    sp.set(qn("w:val"), "0")
    r_pr.append(sp)
    dots_run.append(r_pr)
    t_el = OxmlElement("w:t")
    t_el.text = "." * _neq_toc_uniform_dot_count(label)
    dots_run.append(t_el)
    p_el.append(dots_run)
    return p_el


def _build_att14_toc_title_para(title: str) -> "OxmlElement":
    """
    Title cell: '1.0   TITLE' + leaders that fill to the page column.

    New Equipment uses identical Arial periods (see
    _build_neq_att14_uniform_dot_title_para). Other documents keep the
    right-tab leader plus a Courier period run.
    """
    title = re.sub(r"\s+", " ", (title or "")).strip()
    m = re.match(r"^(\d+(?:\.\d+)*)\s*(.*)$", title)
    if m:
        num_part = m.group(1)
        rest = (m.group(2) or "").strip().upper()
        label = f"{num_part}   {rest}".rstrip() if rest else num_part
    else:
        label = title.upper()

    if _active_is_neq():
        return _build_neq_att14_uniform_dot_title_para(label)

    p_el = OxmlElement("w:p")
    p_pr = OxmlElement("w:pPr")
    ps = OxmlElement("w:pStyle")
    ps.set(qn("w:val"), "Header")
    p_pr.append(ps)
    tabs = OxmlElement("w:tabs")
    right = OxmlElement("w:tab")
    right.set(qn("w:val"), "right")
    right.set(qn("w:leader"), "dot")
    right.set(qn("w:pos"), "8900")
    tabs.append(right)
    _ppr_insert_tabs(p_pr, tabs)
    spacing = OxmlElement("w:spacing")
    spacing.set(qn("w:before"), "60")
    spacing.set(qn("w:after"), "60")
    spacing.set(qn("w:line"), "240")
    spacing.set(qn("w:lineRule"), "auto")
    p_pr.append(spacing)
    p_el.append(p_pr)

    # Title in Arial bold.
    p_el.append(_toc_run_arial_bold_11(label + " "))

    # Courier periods on the SAME line as the title, sized to reach the page
    # column. Too many wrap under the title and leave a white gap before 2/4/5.
    # Empirically ~70 Courier-10 dots = full title-cell width in LO PDF.
    full_cell_dots = 70
    label_frac = min(0.80, (len(label) * 1.15) / 56.0)
    n_dots = max(18, min(68, int(full_cell_dots * (1.0 - label_frac))))
    dots_run = OxmlElement("w:r")
    r_pr = OxmlElement("w:rPr")
    fonts = OxmlElement("w:rFonts")
    fonts.set(qn("w:ascii"), "Courier New")
    fonts.set(qn("w:hAnsi"), "Courier New")
    fonts.set(qn("w:cs"), "Courier New")
    r_pr.append(fonts)
    r_pr.append(OxmlElement("w:b"))
    sz = OxmlElement("w:sz")
    sz.set(qn("w:val"), "20")  # 10pt — slightly denser, more dots before wrap
    r_pr.append(sz)
    sz_cs = OxmlElement("w:szCs")
    sz_cs.set(qn("w:val"), "20")
    r_pr.append(sz_cs)
    sp = OxmlElement("w:spacing")
    sp.set(qn("w:val"), "-20")
    r_pr.append(sp)
    dots_run.append(r_pr)
    t_el = OxmlElement("w:t")
    t_el.text = "." * n_dots
    dots_run.append(t_el)
    p_el.append(dots_run)

    # Word-side tab leader to the cell edge (LO may ignore; Courier pads anyway).
    tab_run = OxmlElement("w:r")
    tab_run.append(OxmlElement("w:tab"))
    p_el.append(tab_run)
    return p_el


def _build_att14_toc_page_para(page: str) -> "OxmlElement":
    """Page cell: left-aligned digit at column start (dots meet the number)."""
    p_el = OxmlElement("w:p")
    p_pr = OxmlElement("w:pPr")
    ps = OxmlElement("w:pStyle")
    ps.set(qn("w:val"), "Header")
    p_pr.append(ps)
    # Left-align so the digit sits flush against the title/dots column —
    # right-align left a white gap before 2/4/5 (see user red-line markup).
    jc = OxmlElement("w:jc")
    jc.set(qn("w:val"), "left")
    p_pr.append(jc)
    spacing = OxmlElement("w:spacing")
    spacing.set(qn("w:before"), "60")
    spacing.set(qn("w:after"), "60")
    spacing.set(qn("w:line"), "240")
    spacing.set(qn("w:lineRule"), "auto")
    p_pr.append(spacing)
    p_el.append(p_pr)
    p_el.append(_toc_run_arial_bold_11(str(page).strip()))
    return p_el


def _is_att14_style_toc_table(table) -> bool:
    """True for Att14 TOC: original 1-col list, or converted title|page 2-col."""
    if is_toc_list_table(table):
        return True
    if not table.rows or len(table.columns) != 2:
        return False
    # If the header has 'description' or 'sl. no', this is a section table, NOT Att14 TOC!
    header_text = " ".join(c.text.strip().lower() for c in table.rows[0].cells)
    if "description" in header_text or "sl. no" in header_text or "sl no" in header_text:
        return False
    hits = 0
    for row in table.rows:
        if len(row.cells) < 2:
            return False
        t0 = re.sub(r"\s+", " ", (row.cells[0].text or "")).strip()
        t1 = re.sub(r"\s+", " ", (row.cells[1].text or "")).strip()
        if re.match(r"^\d+\.\d+", t0) and re.fullmatch(r"\d+", t1):
            hits += 1
    return hits >= 3


def _collect_att14_toc_entries(table) -> list[tuple[str, str]]:
    """Collect (title, page) rows from 1-col or converted 2-col Att14 TOC."""
    entries: list[tuple[str, str]] = []
    for row in table.rows:
        if not row.cells:
            continue
        title_cell = row.cells[0]
        page_cell = row.cells[1] if len(row.cells) > 1 else None
        title_paras = list(title_cell.paragraphs)
        page_paras = list(page_cell.paragraphs) if page_cell is not None else []

        for idx, para in enumerate(title_paras):
            title, page = _parse_toc_title_from_element(para._element)
            if title and page:
                entries.append((title, page))
                continue
            title_only = re.sub(r"\s+", " ", (para.text or "")).strip()
            title_only = re.sub(r"[\.\u2026\u22ef\t_:]+$", "", title_only).strip()
            if not title_only:
                continue
            page_only = ""
            if idx < len(page_paras):
                page_only = re.sub(r"\D", "", page_paras[idx].text or "")
            elif page_cell is not None and len(title_paras) == 1:
                page_only = re.sub(r"\D", "", page_cell.text or "")
            if title_only and page_only:
                entries.append((title_only, page_only))
    return entries


def _convert_att14_toc_list_table_to_page_column(table) -> int:
    """
    Att14 TOC → 2-col (title+dots | right-aligned page) so LibreOffice PDF
    keeps every page digit on one vertical column (Word-original look).
    """
    if not _is_att14_style_toc_table(table):
        return 0

    entries = _collect_att14_toc_entries(table)
    if len(entries) < 3:
        return 0

    # Wide title+dots column; narrow page column so digits sit where dots end.
    title_w = "9000"
    page_w = "513"
    tbl = table._tbl

    tbl_grid = tbl.find(qn("w:tblGrid"))
    if tbl_grid is None:
        tbl_grid = OxmlElement("w:tblGrid")
        tbl.insert(0, tbl_grid)
    for child in list(tbl_grid):
        tbl_grid.remove(child)
    for w in (title_w, page_w):
        gc = OxmlElement("w:gridCol")
        gc.set(qn("w:w"), w)
        tbl_grid.append(gc)

    for tr in list(tbl.findall(qn("w:tr"))):
        tbl.remove(tr)

    # Hide outer table borders so title|page reads as one TOC line.
    tbl_pr = tbl.find(qn("w:tblPr"))
    if tbl_pr is None:
        tbl_pr = OxmlElement("w:tblPr")
        tbl.insert(0, tbl_pr)
    old_borders = tbl_pr.find(qn("w:tblBorders"))
    if old_borders is not None:
        tbl_pr.remove(old_borders)
    borders = OxmlElement("w:tblBorders")
    for edge in ("top", "left", "bottom", "right", "insideH", "insideV"):
        el = OxmlElement(f"w:{edge}")
        el.set(qn("w:val"), "nil")
        borders.append(el)
    tbl_pr.append(borders)

    for title, page in entries:
        tr = OxmlElement("w:tr")
        tr.append(_make_toc_tc(title_w, [_build_att14_toc_title_para(title)]))
        tr.append(_make_toc_tc(page_w, [_build_att14_toc_page_para(page)]))
        tbl.append(tr)

    return len(entries)


def _realign_toc_page_columns(doc) -> int:
    """Re-apply TOC layout so page digits share one column (Word + LO PDF)."""
    _fix_toc1_style_tabs(doc)
    fixed = 0
    # Att14 list TOC: structural title|page columns (tabs fail inside LO table cells).
    for table in doc.tables:
        try:
            n = _convert_att14_toc_list_table_to_page_column(table)
        except Exception:
            n = 0
        fixed += n
    seen = set()
    for p_el in _iter_toc_paragraph_elements(doc):
        el_id = id(p_el)
        if el_id in seen:
            continue
        seen.add(el_id)
        # Skip paragraphs already living in a converted Att14 2-col TOC page cell.
        if _para_element_in_table(p_el):
            # Page-only cells are short digits — leave them.
            title, page = _parse_toc_title_from_element(p_el)
            raw = ""
            try:
                raw = "".join(
                    (t.text or "")
                    for t in p_el.iter()
                    if isinstance(t.tag, str) and t.tag.endswith("}t")
                )
            except Exception:
                raw = ""
            if re.fullmatch(r"\d+", (raw or "").strip()):
                continue
            # Title cells in converted TOC already include leaders — don't glue page back on.
            if title and not page and "…" in (raw or ""):
                continue
            # NEQ Att14 leaders are plain periods (same size on every row).
            if _active_is_neq() and title and not page and re.search(r"\.{6,}", raw or ""):
                continue
        title, page = _parse_toc_title_from_element(p_el)
        if not title or not page:
            continue
        if _rewrite_toc_paragraph_element(p_el, title, page):
            fixed += 1
    return fixed


def recompute_toc_page_numbers(doc, layout_docx_path: str | None = None) -> dict[str, str]:
    """
    Rewrite TOC page numbers after final fill / approval stamps.

    Where this runs:
      API: POST /fill  →  fill_docx()  →  this function

    Page source order (easy to recognize):
      [WORD COM]     try Microsoft Word first (best)
      [LIBREOFFICE]  if Word fails, soffice DOCX→PDF then read pages
      [ESTIMATE]     last fallback if both tools fail
    """
    section_pages: dict[str, dict] = {}
    pdf_dir = None
    # Lock TOC1/TOC2 tab stops before rewriting lines (LibreOffice merges style tabs).
    _fix_toc1_style_tabs(doc)
    toc_entries = _collect_toc_entries_from_doc(doc)
    used_word = False
    need = max(1, len(toc_entries))

    word_pages: dict[str, dict] = {}
    lo_pages: dict[str, dict] = {}
    pdf_page_count = 0
    word_page_count = 0

    # --- [WORD COM] Step 1: real pages from Microsoft Word ---
    if layout_docx_path and os.path.isfile(layout_docx_path):
        word_pages = _map_section_pages_from_word(layout_docx_path, toc_entries) or {}
        if word_pages:
            try:
                word_page_count = max(int(info.get("page") or 0) for info in word_pages.values())
            except Exception:
                word_page_count = 0

    # --- [LIBREOFFICE] Step 2: always read PDF pages (preview is PDF) ---
    # Tool: soffice --headless --convert-to pdf  (see _docx_to_pdf_for_toc)
    if layout_docx_path and os.path.isfile(layout_docx_path):
        pdf_path = _docx_to_pdf_for_toc(layout_docx_path)
        if pdf_path:
            pdf_dir = os.path.dirname(pdf_path)
            lo_pages = _map_section_pages_from_pdf(pdf_path) or {}
            try:
                import fitz  # PyMuPDF

                pdf_page_count = int(fitz.open(pdf_path).page_count)
            except Exception:
                try:
                    pdf_page_count = max(int(info.get("page") or 0) for info in lo_pages.values()) if lo_pages else 0
                except Exception:
                    pdf_page_count = 0

    # Prefer PDF map whenever it covers enough TOC rows — preview/PDF is what users see.
    # Word pagination often differs (e.g. Word 16 pages vs LibreOffice PDF 14).
    if lo_pages and len(lo_pages) >= max(3, int(need * 0.5)):
        section_pages = dict(lo_pages)
        used_word = False
        # Fill any missing early sections (1.0/2.0) from Word if PDF missed them.
        for key, info in word_pages.items():
            if key not in section_pages:
                section_pages[key] = info
    elif word_pages:
        section_pages = dict(word_pages)
        used_word = True
        for key, info in lo_pages.items():
            if key not in section_pages:
                section_pages[key] = info
    elif lo_pages:
        section_pages = dict(lo_pages)
        used_word = False

    # --- [ESTIMATE] Step 3: soft A4 guess if both tools failed ---
    if not section_pages:
        section_pages = _map_section_pages(doc)
    if not section_pages:
        if pdf_dir and os.path.isdir(pdf_dir):
            shutil.rmtree(pdf_dir, ignore_errors=True)
        # Still realign existing TOC tabs so page digits share one column.
        _realign_toc_page_columns(doc)
        return {}

    updates: dict[str, str] = {}
    last_page = 0

    def resolve_page(title: str) -> int | None:
        nonlocal last_page
        flat = (title or "").strip()
        sec = ""
        m = re.match(r"^(\d+\.0)\b", flat)
        if m:
            sec = m.group(1)
        else:
            m2 = re.match(r"^(\d+)\b", flat)
            if m2:
                sec = f"{m2.group(1)}.0"

        page = None
        if sec and sec in section_pages:
            page = int(section_pages[sec]["page"])
        elif re.search(r"\bAPPROVAL\b", flat, re.I) and "APPROVAL" in section_pages:
            page = int(section_pages["APPROVAL"]["page"])
        else:
            toc_words = [
                w for w in re.sub(r"[\.\:\-_]+", " ", flat.upper()).split()
                if len(w) > 2 and w not in {"AND", "THE", "FOR", "ANY", "OF"}
                and not re.match(r"^\d", w)
            ]
            best_score = -1
            for cand_sec, info in section_pages.items():
                score = _score_heading_candidate(cand_sec, info.get("title") or "", flat)
                if toc_words:
                    upper = (info.get("title") or "").upper()
                    hits = sum(1 for w in toc_words if w in upper)
                    if hits <= 0:
                        continue
                    score += hits * 10
                if score > best_score:
                    best_score = score
                    page = int(info["page"])
            if best_score < 40:
                page = None

        if not page:
            return None
        # Word pages are authoritative — do not inflate REFERENCES 30 → 31 because a
        # previous LO/estimate row was too high. Only keep TOC monotonic for soft estimates.
        if (not used_word) and last_page and page < last_page:
            page = last_page
        elif used_word and last_page and page < last_page:
            # Tiny tolerance only (same-page sections OK; never jump forward to fix).
            page = page
        last_page = max(last_page, page)
        return page

    seen = set()
    for idx, p_el in enumerate(_iter_toc_paragraph_elements(doc)):
        el_id = id(p_el)
        if el_id in seen:
            continue
        seen.add(el_id)
        title, old_page = _parse_toc_title_from_element(p_el)
        if not title:
            continue
        page = resolve_page(title)
        if not page and old_page:
            try:
                page = int(old_page)
            except (TypeError, ValueError):
                page = None
        if not page:
            continue
        if _rewrite_toc_paragraph_element(p_el, title, str(page)):
            updates[f"toc_body_{idx}"] = str(page)

    if pdf_dir and os.path.isdir(pdf_dir):
        shutil.rmtree(pdf_dir, ignore_errors=True)
    return updates


def _normalize_header_table_fonts(doc, font_name: str = "Arial", font_size_pt: float = 12.0) -> None:
    """
    Ensure all text runs in header tables (letterhead) are uniformly styled
    with Arial 12pt (matching SOP template standards for Document Number,
    Equipment Name, Equipment ID, Title, etc.) while preserving bold styling and images.
    """
    for section in doc.sections:
        headers = [section.header]
        if hasattr(section, "first_page_header") and section.first_page_header:
            headers.append(section.first_page_header)
        if hasattr(section, "even_page_header") and section.even_page_header:
            headers.append(section.even_page_header)
        for hdr in headers:
            for table in hdr.tables:
                for r_idx, row in enumerate(table.rows):
                    # Multi-row letterheads: top rows (Annex, Company, Title) are 11-12pt.
                    # Lower grid rows (Facility, Equipment Name, Ref Doc, Version, etc.)
                    # have narrow cells designed for 10pt. 12pt causes awkward wrapping
                    # ("PRODUCTIO\nN") and inflates header height across all pages.
                    effective_pt = 10.0 if (len(table.rows) >= 4 and r_idx >= 3) else font_size_pt
                    for cell in row.cells:
                        for para in cell.paragraphs:
                            if any(_run_has_image(r) for r in para.runs):
                                continue
                            for run in para.runs:
                                if (run.text or "").strip():
                                    run.font.name = font_name
                                    run.font.size = Pt(effective_pt)


def _fix_broken_hanging_indents(doc) -> int:
    """
    Template list paragraphs use hanging > left (e.g. left=720, hanging=810).

    That puts the first line (and first word like "Any") at a negative indent, so
    LibreOffice/Word draw "Any" above the line and "other specific tests" below —
    and text can paint over the previous floating table. Clamp hanging <= left.
    Also drop negative tab stops that amplify the glitch.
    """
    changed = 0
    for para_el in doc.element.body.iter(qn("w:p")):
        p_pr = para_el.find(qn("w:pPr"))
        if p_pr is None:
            continue
        ind = p_pr.find(qn("w:ind"))
        if ind is not None:
            try:
                left = int(ind.get(qn("w:left")) or 0)
            except (TypeError, ValueError):
                left = 0
            try:
                hanging = int(ind.get(qn("w:hanging")) or 0)
            except (TypeError, ValueError):
                hanging = 0
            if hanging > left and hanging > 0:
                # Keep number gutter inside the left indent (first-line start >= 0).
                if left > 0:
                    ind.set(qn("w:hanging"), str(left))
                else:
                    ind.set(qn("w:left"), str(hanging))
                changed += 1
        tabs = p_pr.find(qn("w:tabs"))
        if tabs is None:
            continue
        removed_tab = False
        for tab in list(tabs.findall(qn("w:tab"))):
            try:
                pos = int(tab.get(qn("w:pos")) or 0)
            except (TypeError, ValueError):
                continue
            if pos < 0:
                tabs.remove(tab)
                removed_tab = True
                changed += 1
        if removed_tab and len(tabs) == 0:
            p_pr.remove(tabs)
    return changed


def _defloat_body_tables(doc) -> int:
    """
    Floating body tables (tblpPr) grow when filled and paint over the next
    heading/rows in LibreOffice PDF (overlap at page bottoms, 8.x cut-off).
    Keep them in normal document flow instead.

    Preserve the horizontal offset as tblInd. tblpX with horzAnchor=page is
    page-absolute (e.g. Calibrations tblpX=2167); tblInd is relative to the
    left margin — copying 2167 unchanged pushed the table past the right border.
    """
    changed = 0
    left_margin = 1350
    try:
        from neq_docx_save import _section_metric

        left_margin = int(_section_metric(doc, "w:pgMar", "w:left", 1350))
    except Exception:
        for sect in doc.element.body.iter(qn("w:sectPr")):
            mar = sect.find(qn("w:pgMar"))
            if mar is not None and mar.get(qn("w:left")):
                try:
                    left_margin = int(mar.get(qn("w:left")))
                except (TypeError, ValueError):
                    pass
                break

    for tbl in doc.element.body.findall(qn("w:tbl")):
        tbl_pr = tbl.find(qn("w:tblPr"))
        if tbl_pr is None:
            continue
        anchor = tbl_pr.find(qn("w:tblpPr"))
        if anchor is not None:
            existing_ind = tbl_pr.find(qn("w:tblInd"))
            x_spec = (anchor.get(qn("w:tblpXSpec")) or "").lower()
            raw_x = anchor.get(qn("w:tblpX"))
            if existing_ind is None and raw_x is not None and x_spec not in ("center", "right", "inside", "outside"):
                try:
                    x_dxa = int(raw_x)
                except (TypeError, ValueError):
                    x_dxa = None
                if x_dxa is not None:
                    # Only explicit page anchors are page-absolute. Missing/text/margin
                    # tblpX is already margin-relative (e.g. Comments x=-565). Treating
                    # missing as "page" previously did x-left_margin → -2005 and shoved
                    # the table left with a large empty right margin in LO PDF.
                    horz_raw = (anchor.get(qn("w:horzAnchor")) or "").strip().lower()
                    vert_raw = (anchor.get(qn("w:vertAnchor")) or "").strip().lower()
                    if horz_raw == "page":
                        ind_dxa = x_dxa - left_margin
                    else:
                        ind_dxa = x_dxa
                    # Text-anchored floats with a large positive x still need margin math.
                    if horz_raw == "" and vert_raw == "page" and x_dxa >= left_margin:
                        ind_dxa = x_dxa - left_margin
                    ind = OxmlElement("w:tblInd")
                    ind.set(qn("w:w"), str(ind_dxa))
                    ind.set(qn("w:type"), "dxa")
                    jc = tbl_pr.find(qn("w:jc"))
                    if jc is not None:
                        jc.addprevious(ind)
                    else:
                        tbl_pr.append(ind)
                    changed += 1
            tbl_pr.remove(anchor)
            changed += 1
        overlap = tbl_pr.find(qn("w:tblOverlap"))
        if overlap is not None:
            tbl_pr.remove(overlap)
            changed += 1
    return changed


def _normalize_header_footer_table_widths(doc) -> int:
    """
    Letterhead tables often use tblW type=pct. LibreOffice PDF then draws them
    narrower than Word and parks them on the left. Convert to dxa from grid
    (same as body tables after fill) so every viewer matches the original DOCX.
    """
    changed = 0
    parts = []
    for sect in doc.sections:
        for hdr in (
            getattr(sect, "header", None),
            getattr(sect, "first_page_header", None),
            getattr(sect, "even_page_header", None),
        ):
            if hdr is not None:
                parts.append(hdr)
        for ftr in (
            getattr(sect, "footer", None),
            getattr(sect, "first_page_footer", None),
            getattr(sect, "even_page_footer", None),
        ):
            if ftr is not None:
                parts.append(ftr)

    for part in parts:
        try:
            tables = part.tables
        except Exception:
            continue
        for table in tables:
            tbl = table._tbl
            tbl_pr = tbl.find(qn("w:tblPr"))
            if tbl_pr is None:
                continue
            tbl_w = tbl_pr.find(qn("w:tblW"))
            grid = tbl.find(qn("w:tblGrid"))
            grid_sum = 0
            if grid is not None:
                for col in grid.findall(qn("w:gridCol")):
                    try:
                        grid_sum += int(col.get(qn("w:w")) or 0)
                    except (TypeError, ValueError):
                        continue
            if grid_sum <= 0:
                continue
            if tbl_w is None:
                tbl_w = OxmlElement("w:tblW")
                tbl_pr.append(tbl_w)
            kind = (tbl_w.get(qn("w:type")) or "").lower()
            try:
                cur = int(tbl_w.get(qn("w:w")) or 0)
            except (TypeError, ValueError):
                cur = 0
            if kind == "pct" or cur <= 0 or (kind == "dxa" and abs(cur - grid_sum) > 80 and cur < grid_sum):
                tbl_w.set(qn("w:w"), str(grid_sum))
                tbl_w.set(qn("w:type"), "dxa")
                changed += 1
            # Keep original hanging indent when present; default slightly negative
            # like Lupin letterheads so the block spans to the page border.
            ind = tbl_pr.find(qn("w:tblInd"))
            if ind is None:
                ind = OxmlElement("w:tblInd")
                ind.set(qn("w:w"), "-635")
                ind.set(qn("w:type"), "dxa")
                jc = tbl_pr.find(qn("w:jc"))
                if jc is not None:
                    jc.addprevious(ind)
                else:
                    tbl_pr.append(ind)
                changed += 1
    return changed


def _tc_width_dxa(tc) -> int:
    tc_pr = tc.find(qn("w:tcPr"))
    if tc_pr is None:
        return 0
    tc_w = tc_pr.find(qn("w:tcW"))
    if tc_w is None or (tc_w.get(qn("w:type")) or "") != "dxa":
        return 0
    try:
        return int(tc_w.get(qn("w:w")) or 0)
    except (TypeError, ValueError):
        return 0


def _ensure_body_cell_margins(
    doc,
    *,
    left_twips: int = 120,
    right_twips: int = 120,
    top_twips: int = 40,
    bottom_twips: int = 40,
) -> int:
    """
    Add ~1-letter left/right cell padding when the template has no tcMar.
    Stops Super Admin / signature text from sticking to the cell border.
    120 twips ≈ one character at ~11pt.

    Narrow columns (Calibrations "Sr. No." ≈ 636 twips) must use smaller
    pads — 120+120 ate the cell and LO stacked letters / shoved the table out.
    """
    changed = 0
    for table in doc.tables:
        for row in table.rows:
            seen = set()
            for cell in row.cells:
                tc = cell._tc
                if id(tc) in seen:
                    continue
                seen.add(id(tc))
                width = _tc_width_dxa(tc)
                pad_l, pad_r = left_twips, right_twips
                if 0 < width < 900:
                    pad_l = pad_r = min(36, max(18, width // 16))
                elif 0 < width < 1500:
                    pad_l = pad_r = min(72, left_twips)
                sides = (
                    ("left", pad_l),
                    ("right", pad_r),
                    ("top", top_twips),
                    ("bottom", bottom_twips),
                )
                tc_pr = tc.get_or_add_tcPr()
                tc_mar = tc_pr.find(qn("w:tcMar"))
                if tc_mar is None:
                    tc_mar = OxmlElement("w:tcMar")
                    tc_pr.append(tc_mar)
                for side, want in sides:
                    node = tc_mar.find(qn(f"w:{side}"))
                    if node is None:
                        node = OxmlElement(f"w:{side}")
                        tc_mar.append(node)
                        node.set(qn("w:w"), str(want))
                        node.set(qn("w:type"), "dxa")
                        changed += 1
                        continue
                    try:
                        cur = int(node.get(qn("w:w")) or 0)
                    except (TypeError, ValueError):
                        cur = 0
                    # Raise floor for wide cells; shrink oversized pads on narrow ones.
                    if cur < want or (width and width < 1500 and cur > want):
                        node.set(qn("w:w"), str(want))
                        node.set(qn("w:type"), "dxa")
                        changed += 1
    return changed


def _is_comments_or_signature_table(table) -> bool:
    rows = list(table.rows)
    if not rows:
        return False
    txt = " ".join((c.text or "").strip().lower() for c in rows[0].cells)
    if any(k in txt for k in ("comment", "remark", "note")):
        return True
    if any(k in txt for k in ("complied by", "checked by", "approved by", "prepared by", "reviewed by")):
        return True
    return False


def _leading_header_row_count(table) -> int:
    """
    How many leading rows Word/LibreOffice should repeat across pages.

    Att08 Instrument Calibration uses a 2-row header:
      Instrument Description | ID | Calibration details (colspan) | Sign
      (rowspan)              |    | Done On | Due On              |
    Repeating only row 0 leaves an empty gray band / missing Done On–Due On
    on page 2+. Detect that pattern (and vertical merges) and return 2+.
    """
    rows = list(table.rows)
    if not rows:
        return 0
    if len(rows) == 1:
        return 1

    if _is_comments_or_signature_table(table):
        return 0

    max_span = 1
    row0 = rows[0]
    for col_idx, cell in enumerate(row0.cells):
        tc = cell._tc
        span = 1
        for nxt in range(1, len(rows)):
            nxt_cells = rows[nxt].cells
            if col_idx < len(nxt_cells) and nxt_cells[col_idx]._tc is tc:
                span += 1
            else:
                break
        if span > max_span:
            max_span = span

    r0 = " ".join((c.text or "").strip().lower() for c in row0.cells)
    r1 = " ".join((c.text or "").strip().lower() for c in rows[1].cells)
    if (
        ("done on" in r1 or "due on" in r1)
        and (
            "calibration" in r0
            or "instrument description" in r0
            or "instrument" in r0
            or "id code" in r0
        )
    ):
        max_span = max(max_span, 2)

    # Cap: keep a few header rows only; never mark the whole table as header.
    return max(1, min(max_span, 3, len(rows) - 1))


def _normalize_table_page_breaks(doc) -> int:
    """
    Mark the leading header row block with tblHeader (Att08 may need 2 rows).

    Always KEEP / ADD cantSplit on every data row. LibreOffice mid-row splits
    leave an empty Details/Description band at the top of the next page in the
    final post-approval PDF (requal AHU tables). Header rows may still split.
    """
    changed = 0
    for table in doc.tables:
        rows = list(table.rows)
        if not rows:
            continue
        header_n = _leading_header_row_count(table)
        for index, row in enumerate(rows):
            tr_pr = row._tr.get_or_add_trPr()
            headers = tr_pr.findall(qn("w:tblHeader"))
            if header_n > 0 and index < header_n:
                if not headers:
                    tr_pr.append(OxmlElement("w:tblHeader"))
                    changed += 1
                elif len(headers) > 1:
                    for extra in headers[1:]:
                        tr_pr.remove(extra)
                        changed += 1
                # Header rows themselves may split; data rows below must not.
                cant = tr_pr.find(qn("w:cantSplit"))
                if cant is not None:
                    tr_pr.remove(cant)
                    changed += 1
                continue
            for node in headers:
                tr_pr.remove(node)
                changed += 1
            cant = tr_pr.find(qn("w:cantSplit"))
            if cant is None:
                tr_pr.append(OxmlElement("w:cantSplit"))
                changed += 1
    return changed


def _strip_stale_soft_page_breaks(doc) -> int:
    """
    Remove Word lastRenderedPageBreak markers left from the template layout.
    After fill, content height changes; stale soft breaks make LibreOffice paint
    blank table bands at the top of continuation pages.
    """
    removed = 0
    soft_tag = qn("w:lastRenderedPageBreak")
    for parent in list(doc.element.body.iter()):
        for child in list(parent):
            tag = child.tag if isinstance(child.tag, str) else ""
            if tag == soft_tag or tag.endswith("lastRenderedPageBreak"):
                parent.remove(child)
                removed += 1
    return removed


def _normalize_requal_body_fonts_to_arial_11(doc) -> int:
    """
    Requal/reval body was often rewritten at 12pt (sz=24). Original AHU protocol
    body is Arial 11 (sz=22). Cap inflated body runs back to 11 without touching
    larger title/header sizes (14/18pt).
    """
    if _active_is_neq():
        return 0
    fixed = 0
    for run in doc.element.body.iter(qn("w:r")):
        text = "".join(node.text or "" for node in run.iter(qn("w:t"))).strip()
        if not text:
            continue
        r_pr = run.find(qn("w:rPr"))
        if r_pr is None:
            continue
        sz = r_pr.find(qn("w:sz"))
        if sz is None:
            # Missing size → explicit 11 so LibreOffice PDF matches Word.
            _force_arial_11_on_rpr(r_pr, bold=False, force_size=True)
            fixed += 1
            continue
        try:
            half = int(sz.get(qn("w:val")) or 0)
        except (TypeError, ValueError):
            continue
        # Only undo the fill default bump 11→12 (22→24). Leave 10/14/18 alone.
        if half == 24:
            for tag in ("w:sz", "w:szCs"):
                for node in list(r_pr.findall(qn(tag))):
                    r_pr.remove(node)
            sz_n = OxmlElement("w:sz")
            sz_n.set(qn("w:val"), "22")
            r_pr.append(sz_n)
            sz_cs = OxmlElement("w:szCs")
            sz_cs.set(qn("w:val"), "22")
            r_pr.append(sz_cs)
            fonts = r_pr.find(qn("w:rFonts"))
            if fonts is None:
                fonts = OxmlElement("w:rFonts")
                r_pr.insert(0, fonts)
            if not (fonts.get(qn("w:ascii")) or "").strip():
                fonts.set(qn("w:ascii"), "Arial")
                fonts.set(qn("w:hAnsi"), "Arial")
            fixed += 1
    return fixed


def _expand_clipping_textboxes(doc) -> int:
    """
    History / recommendation content lives in floating Word textboxes with
    wps:noAutofit and a fixed cy. Filled lines then clip at the bottom border
    (8.5 History box). Enable spAutoFit and grow cy so every paragraph shows.
    """
    from docx.oxml import parse_xml

    wps_ns = "http://schemas.microsoft.com/office/word/2010/wordprocessingShape"
    a_ns = "http://schemas.openxmlformats.org/drawingml/2006/main"
    wp_ns = "http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
    changed = 0

    for txbx in doc.element.body.findall(
        ".//{http://schemas.openxmlformats.org/wordprocessingml/2006/main}txbxContent"
    ):
        paras = txbx.findall(qn("w:p"))
        if not paras:
            continue
        full = "".join(t.text or "" for t in txbx.findall(".//" + qn("w:t")))
        if len(full.strip()) < 40:
            continue

        # Estimate wrapped lines inside ~6.4" wide box (underlines need extra air).
        lines = 0
        for para in paras:
            text = "".join(t.text or "" for t in para.findall(".//" + qn("w:t"))).strip()
            if not text:
                lines += 1
                continue
            lines += max(1, (len(text) // 55) + 1)

        node = txbx.getparent()
        wsp = None
        anchor = None
        while node is not None:
            tag = str(node.tag)
            if tag.endswith("}wsp"):
                wsp = node
            if tag.endswith("}anchor") or tag.endswith("}inline"):
                anchor = node
                break
            node = node.getparent()
        if wsp is None:
            continue

        body_pr = wsp.find(f"{{{wps_ns}}}bodyPr")
        if body_pr is not None:
            for child in list(body_pr):
                ctag = str(child.tag)
                if ctag.endswith("}noAutofit") or ctag.endswith("}normAutofit"):
                    body_pr.remove(child)
                    changed += 1
            if body_pr.find(f"{{{wps_ns}}}spAutoFit") is None:
                body_pr.append(
                    parse_xml(
                        f'<wps:spAutoFit xmlns:wps="{wps_ns}"/>'
                    )
                )
                changed += 1
            # Small inner padding so text is not flush to the box border.
            for attr, emu in (("lIns", "72000"), ("rIns", "72000"), ("tIns", "36000"), ("bIns", "90000")):
                try:
                    cur = int(body_pr.get(attr) or 0)
                except (TypeError, ValueError):
                    cur = 0
                if cur < int(emu):
                    body_pr.set(attr, emu)
                    changed += 1

        # Grow fixed height for LibreOffice (often ignores spAutoFit).
        # Cap so the floating 8.5 History box cannot paint through the ANNEX footer
        # (user: even if original clips, final must stay above the footer).
        emu_in = 914400
        need_cy = max(lines * 280000 + 360000, int(2.8 * emu_in))
        # Original template box is ~3.17"; never grow past ~3.4" on-page.
        max_cy = int(3.4 * emu_in)
        need_cy = min(need_cy, max_cy)

        # If this history/recommendation box is mid-page, start it on a fresh page
        # so the capped height still clears the footer band.
        host_para = txbx.getparent()
        while host_para is not None and not str(host_para.tag).endswith("}p"):
            host_para = host_para.getparent()
        if host_para is not None and (
            "history card" in full.lower()
            or "engineering shall review" in full.lower()
            or "recommendation" in full.lower()
        ):
            _set_para_page_break_before(host_para, enabled=True)

        extents = []
        if anchor is not None:
            extents.extend(anchor.findall(f"{{{wp_ns}}}extent"))
        extents.extend(wsp.findall(f".//{{{a_ns}}}ext"))
        for ext in extents:
            if ext.get("cy") is None:
                continue
            try:
                cur = int(ext.get("cy") or 0)
            except (TypeError, ValueError):
                continue
            # Grow up to cap; also shrink previously over-expanded boxes.
            if cur <= 0:
                continue
            if need_cy != cur and (need_cy > cur or cur > max_cy):
                ext.set("cy", str(min(need_cy, max_cy)))
                changed += 1

    return changed


def _dedupe_all_colon_paragraphs(doc) -> None:
    """
    Collapse duplicated colon labels in document paragraphs, e.g.
    'Verification with the current P & ID: Verification with the current P & ID:'
    → 'Verification with the current P & ID:'
    """
    try:
        from app import dedupe_colon_label
    except Exception:
        return
    for para in doc.paragraphs:
        txt = (para.text or "").strip()
        if ":" in txt:
            cleaned = dedupe_colon_label(txt)
            if cleaned != txt:
                runs = list(para.runs)
                if runs:
                    runs[0].text = cleaned
                    for r in runs[1:]:
                        r.text = ""
                else:
                    para.text = cleaned


def _trim_trailing_empty_paras_in_table_cells(doc) -> int:
    """
    Remove empty trailing paragraphs inside table cells.
    Template authors often type Enter repeatedly to make empty cells taller.
    When cells are filled, those trailing empty paragraphs blow up the row
    height and force page splits.
    """
    changed = 0
    for tbl in doc.tables:
        for row in tbl.rows:
            for cell in row.cells:
                tc = cell._tc
                paras = tc.findall(qn("w:p"))
                while len(paras) > 1:
                    last_p = paras[-1]
                    txt = "".join(last_p.itertext()).strip()
                    if not txt and not list(last_p.iter(qn("w:drawing"))) and not list(last_p.iter(qn("w:pict"))):
                        tc.remove(last_p)
                        paras = tc.findall(qn("w:p"))
                        changed += 1
                    else:
                        break
    return changed


def _separate_adjacent_tables(doc) -> int:
    """
    Ensure every adjacent pair of body tables has an intervening paragraph.
    Without an intervening <w:p>, Word and LibreOffice merge consecutive tables
    into one contiguous table, repeating table headers across all following tables.
    """
    body = doc.element.body
    changed = 0
    tables = list(body.findall(qn("w:tbl")))
    for tbl in tables:
        parent = tbl.getparent()
        if parent is None:
            continue
        try:
            idx = parent.index(tbl)
        except ValueError:
            continue
        if idx + 1 < len(parent):
            nxt = parent[idx + 1]
            if nxt.tag == qn("w:tbl"):
                sep_p = parse_xml(
                    f'<w:p {nsdecls("w")}><w:pPr><w:spacing w:before="60" w:after="60"/>'
                    f'<w:rPr><w:sz w:val="4"/><w:szCs w:val="4"/></w:rPr></w:pPr></w:p>'
                )
                parent.insert(idx + 1, sep_p)
                changed += 1
    return changed


def _normalize_filled_docx_layout(doc, profile: str = "legacy") -> None:
    """Post-fill layout pass so Word/LibreOffice PDF match readable formatting.

    profile='legacy' = 14/11 baseline (defloat + page-border clamp).
    profile='neq' keeps the same baseline; both convert header/footer pct→dxa
    so requal/reval letterheads stay full-width in LibreOffice PDF (same as NEQ).
    """
    _dedupe_all_colon_paragraphs(doc)
    _fix_broken_hanging_indents(doc)
    _defloat_body_tables(doc)
    # Letterhead tblW type=pct is drawn narrow by LibreOffice; always use grid dxa.
    _normalize_header_footer_table_widths(doc)
    try:
        from neq_docx_save import _clamp_body_tables_inside_page_border

        _clamp_body_tables_inside_page_border(doc)
    except Exception:
        pass
    _trim_trailing_empty_paras_in_table_cells(doc)
    _separate_adjacent_tables(doc)
    _strip_stale_soft_page_breaks(doc)
    _normalize_table_page_breaks(doc)
    _ensure_body_cell_margins(doc)
    _expand_clipping_textboxes(doc)


def _keep_approval_names_inside_cells(doc) -> None:
    """
    Prepared/Checked/Approved By tables often carry negative paragraph indents
    from the Word template (e.g. w:ind w:end="-567" on "Head - Quality Assurance").
    That pulls department labels into the Name column and lets Name/Signature
    spill across borders. Clamp EVERY data cell so text stays in its own column.
    """
    name_headers = ("name & designation", "name and designation", "name/designation")
    sign_headers = ("signature & date", "signature and date", "sign and date", "sign/date")
    title_headers = ("prepared by", "checked by", "approved by")

    for table in doc.tables:
        if not table.rows:
            continue
        header = []
        seen = set()
        for cell in table.rows[0].cells:
            if id(cell._tc) in seen:
                continue
            seen.add(id(cell._tc))
            header.append(re.sub(r"\s+", " ", (cell.text or "")).strip().lower())
        if not header:
            continue
        is_dept_sig = any(h in title_headers for h in header) and (
            any(h in name_headers for h in header) or any(h in sign_headers for h in header)
        )
        value_cols = {
            index
            for index, label in enumerate(header)
            if label in name_headers or label in sign_headers
        }
        if not is_dept_sig and not value_cols:
            continue
        for row in table.rows[1:]:
            seen_row = set()
            unique_cells = []
            for cell in row.cells:
                if id(cell._tc) in seen_row:
                    continue
                seen_row.add(id(cell._tc))
                unique_cells.append(cell)
            label_cell = unique_cells[0] if unique_cells else None
            for this_col, cell in enumerate(unique_cells):
                # Always clamp department/role labels (col 0) on these tables —
                # negative end-indent is what overlays "Head - Quality Assurance"
                # onto Name & Designation in the PDF preview.
                if not is_dept_sig and this_col not in value_cols:
                    continue
                for para in cell.paragraphs:
                    _clamp_paragraph_inside_cell(para)
                if (
                    label_cell is not None
                    and this_col in value_cols
                    and (cell.text or "").strip()
                ):
                    _copy_approval_font_from_label(label_cell, cell)


def _copy_approval_font_from_label(label_cell, value_cell) -> None:
    """
    Approval names were written with no font, so Word showed Times New Roman.
    Use the same font as the department label in that row (Arial in these tables).
    """
    font_name = None
    font_size = None
    for para in label_cell.paragraphs:
        for run_el in para._element.findall(qn("w:r")):
            r_pr = run_el.find(qn("w:rPr"))
            if r_pr is None:
                continue
            fonts = r_pr.find(qn("w:rFonts"))
            if fonts is not None and not font_name:
                font_name = fonts.get(qn("w:ascii")) or fonts.get(qn("w:hAnsi"))
            sz = r_pr.find(qn("w:sz"))
            if sz is not None and not font_size:
                font_size = sz.get(qn("w:val"))
            if font_name and font_size:
                break
    if not font_name:
        font_name = "Arial"
    for para in value_cell.paragraphs:
        for run_el in para._element.iter(qn("w:r")):
            if run_el.find(qn("w:t")) is None:
                continue
            r_pr = run_el.find(qn("w:rPr"))
            if r_pr is None:
                r_pr = OxmlElement("w:rPr")
                run_el.insert(0, r_pr)
            if r_pr.find(qn("w:rFonts")) is None:
                r_fonts = OxmlElement("w:rFonts")
                r_fonts.set(qn("w:ascii"), font_name)
                r_fonts.set(qn("w:hAnsi"), font_name)
                r_pr.insert(0, r_fonts)
            if font_size and r_pr.find(qn("w:sz")) is None:
                node = OxmlElement("w:sz")
                node.set(qn("w:val"), font_size)
                r_pr.append(node)
                node_cs = OxmlElement("w:szCs")
                node_cs.set(qn("w:val"), font_size)
                r_pr.append(node_cs)


def _is_equipment_id_header_label(text: str) -> bool:
    """Header label for the equipment ID value that must come from the NEQ record."""
    label = re.sub(r"\s+", " ", (text or "").strip().lower()).rstrip(":").strip()
    if not label or "name" in label:
        return False
    return bool(re.search(r"equipment(\s*/\s*system)?\s+id\b", label))


def _write_header_equipment_id(doc, equipment_id: str) -> None:
    """
    Put the NEQ equipment ID in the cell beside Equipment ID. No.
    Leave the label itself in place.
    """
    equipment_id = (equipment_id or "").strip()
    if not equipment_id:
        return
    for section in doc.sections:
        for table in section.header.tables:
            for row in table.rows:
                seen = set()
                cells = []
                for cell in row.cells:
                    if id(cell._tc) in seen:
                        continue
                    seen.add(id(cell._tc))
                    cells.append(cell)
                for index, cell in enumerate(cells):
                    if not _is_equipment_id_header_label(cell.text):
                        continue
                    if index + 1 >= len(cells):
                        continue
                    value_cell = cells[index + 1]
                    if _is_equipment_id_header_label(value_cell.text):
                        continue
                    if _is_fixed_header_label(value_cell.text):
                        continue
                    if (value_cell.text or "").strip() == equipment_id:
                        continue
                    _set_cell_text(value_cell, equipment_id)


def _clamp_paragraph_inside_cell(para) -> None:
    """Drop a negative indent and justified stretch so the line stays in the cell."""
    p_pr = para._element.get_or_add_pPr()
    ind = p_pr.find(qn("w:ind"))
    if ind is not None:
        for attr in ("w:start", "w:left", "w:end", "w:right", "w:hanging", "w:firstLine"):
            raw = ind.get(qn(attr))
            if raw is None:
                continue
            try:
                if int(raw) < 0:
                    ind.set(qn(attr), "0")
            except (TypeError, ValueError):
                continue
    jc = p_pr.find(qn("w:jc"))
    if jc is not None and (jc.get(qn("w:val")) or "") == "both":
        jc.set(qn("w:val"), "left")
    tc = para._element.getparent()
    if tc is None or not str(tc.tag).endswith("}tc"):
        return
    tc_pr = tc.find(qn("w:tcPr"))
    if tc_pr is None:
        return
    no_wrap = tc_pr.find(qn("w:noWrap"))
    if no_wrap is not None:
        tc_pr.remove(no_wrap)


def _is_placeholder_annex_footer_text(text: str | None) -> bool:
    t = (text or "").strip()
    if not t:
        return False
    if t == FOOTER_ANNEX_TEXT:
        return True
    return bool(_PLACEHOLDER_ANNEX_RE.fullmatch(t))


def _strip_placeholder_annex_footer(doc) -> int:
    """
    Remove invented ANNEX_xxx | SOP_XXXX | CLASS I lines that were never in the
    original template (e.g. PERIODIC HVAC VALIDATION REPORT).
    Keep real template footers such as ANNEX_SKM_QA_….
    """
    removed = 0
    for section in doc.sections:
        footer = section.footer
        if footer is None:
            continue
        for para in list(footer.paragraphs):
            if not _is_placeholder_annex_footer_text(para.text):
                continue
            element = para._element
            parent = element.getparent()
            if parent is not None:
                parent.remove(element)
                removed += 1
    return removed


def _unique_table_row_cells(row):
    seen = set()
    cells = []
    for cell in row.cells:
        tc_id = id(cell._tc)
        if tc_id in seen:
            continue
        seen.add(tc_id)
        cells.append(cell)
    return cells


def _para_plain_text(p_element) -> str:
    return "".join(node.text or "" for node in p_element.iter(qn("w:t"))).strip()


def _is_list_of_attachment_heading(text: str) -> bool:
    t = re.sub(r"\s+", " ", (text or "").strip()).upper()
    if not t:
        return False
    if "LIST OF ATTACHMENT" in t:
        return True
    return bool(re.match(r"^\d+(\.\d+)*\.?\s+LIST OF ATTACH", t))


def _is_tight_gap_section_heading(text: str) -> bool:
    """
    Major HVAC / revalidation body headings that often sit under the letterhead
    after a stack of empty paragraphs (OBJECTIVE after TOC, RESPONSIBILITY after
    the SCOPE table, LIST OF ATTACHMENT, …).
    """
    t = re.sub(r"\s+", " ", (text or "").strip()).upper().rstrip(":")
    if not t:
        return False
    if _is_list_of_attachment_heading(text):
        return True
    # Template often stores bare titles; Word list numbering adds "1.0" / "3.0".
    bare = {
        "OBJECTIVE",
        "SCOPE",
        "RESPONSIBILITY",
        "TYPE OF QUALIFICATION",
        "EQUIPMENT /SYSTEM DETAILS",
        "EQUIPMENT / SYSTEM DETAILS",
        "EQUIPMENT/SYSTEM DETAILS",
        "EQUIPMENT/ SYSTEM IDENTIFICATION",
        "EQUIPMENT/SYSTEM IDENTIFICATION",
        "EQUIPMENT/SYSTEM DESCRIPTION",
        "EQUIPMENT / SYSTEM DESCRIPTION",
        "EQUIPMENT/ SYSTEM DESCRIPTION",
        "PRE-REQUISITES",
        "PRE-REQUISITE",
        "APPLICABLE STANDARD OPERATING PROCEDURE (SOP'S)",
        "APPLICABLE STANDARD OPERATING PROCEDURE (SOP’S)",
        "PRE-QUALIFICATION CHECKS",
        "REQUALIFICATION CHECKS",
        "DEVIATIONS (IF ANY)",
        "DEVIATIONS",
        "METHODOLOGY",
        "OBSERVATION / RESULTS AND EVALUATION",
        "OBSERVATION/RESULTS AND EVALUATION",
        "SUMMARY AND CONCLUSION",
        "SUMMARY & CONCLUSION",
        "LIST OF ATTACHMENT",
        "LIST OF ATTACHMENTS",
        "REFERENCES",
        "ABBREVIATIONS",
        "REVISION HISTORY",
        "POST APPROVAL",
        "POST-APPROVAL",
        "POST-APPROVAL SIGNATURE",
        "PREVENTIVE MAINTENANCE DETAILS",
        "CALIBRATIONS OF INSTRUMENTS",
        "CALIBRATION OF INSTRUMENTS",
        "VERIFICATION WITH THE CURRENT P & ID",
        "VERIFICATION WITH CURRENT P & ID",
        "ANY OTHER SPECIFIC TESTS",
        "ANY OTHER SPECIFIC TEST",
        "HISTORY OF EQUIPMENT / SYSTEM",
        "HISTORY OF EQUIPMENT /SYSTEM",
        "REVIEW OF CHANGE CONTROLS",
        "REVIEW OF CHANGE CONTROLS RELATED TO THE EQUIPMENT / SYSTEM",
    }
    if t in bare:
        return True
    if re.match(
        r"^\d+(\.\d+)*\.?\s+(OBJECTIVE|SCOPE|RESPONSIBILITY|TYPE OF QUALIFICATION|"
        r"EQUIPMENT|PRE-REQUISITE|APPLICABLE STANDARD|PRE-QUALIFICATION|"
        r"REQUALIFICATION|DEVIATION|METHODOLOGY|OBSERVATION|SUMMARY|"
        r"LIST OF ATTACH|REFERENCE|ABBREVIATION|REVISION HISTORY|POST APPROVAL|"
        r"INSTALLATION CHECKS|OPERATIONAL CHECKS|TRAINING|ATTACHMENTS|"
        r"PREVENTIVE MAINTENANCE|CALIBRATION|VERIFICATION|ANY OTHER SPECIFIC|"
        r"HISTORY OF EQUIPMENT|REVIEW OF CHANGE CONTROLS)",
        t,
    ):
        return True
    if re.match(r"^\d+(\.\d+)+\.?\s+[A-Za-z]", t):
        return True
    return False


def _normalize_section_spacer_para(p_el) -> None:
    """Turn an empty ListParagraph spacer into a plain ~one-line blank."""
    p_pr = p_el.find(qn("w:pPr"))
    if p_pr is None:
        p_pr = OxmlElement("w:pPr")
        p_el.insert(0, p_pr)
    for tag in ("w:pStyle", "w:numPr", "w:ind"):
        node = p_pr.find(qn(tag))
        if node is not None:
            p_pr.remove(node)
    spacing = p_pr.find(qn("w:spacing"))
    if spacing is None:
        spacing = OxmlElement("w:spacing")
        p_pr.append(spacing)
    spacing.set(qn("w:before"), "0")
    spacing.set(qn("w:after"), "0")
    spacing.set(qn("w:line"), "240")
    spacing.set(qn("w:lineRule"), "auto")
    # Drop any leftover runs so the spacer stays truly empty.
    for child in list(p_el):
        if child.tag != qn("w:pPr"):
            p_el.remove(child)


def _set_para_page_break_before(p_el, enabled: bool = True) -> None:
    p_pr = p_el.find(qn("w:pPr"))
    if p_pr is None:
        p_pr = OxmlElement("w:pPr")
        p_el.insert(0, p_pr)
    existing = p_pr.find(qn("w:pageBreakBefore"))
    if enabled:
        if existing is None:
            p_pr.append(OxmlElement("w:pageBreakBefore"))
    elif existing is not None:
        p_pr.remove(existing)


def _collect_empty_idxs_before(children, idx: int) -> tuple[list[int], int]:
    """Return (empty_para_indexes nearest-first, index of first non-empty sibling)."""
    empty_idxs: list[int] = []
    j = idx - 1
    while j >= 0:
        prev = children[j]
        if prev.tag != qn("w:p"):
            break
        if _para_plain_text(prev):
            break
        empty_idxs.append(j)
        j -= 1
    return empty_idxs, j


def _ensure_para_keep_next(p_el) -> None:
    """Keep a section heading with the following body text (avoids orphan at page bottom)."""
    p_pr = p_el.find(qn("w:pPr"))
    if p_pr is None:
        p_pr = OxmlElement("w:pPr")
        p_el.insert(0, p_pr)
    if p_pr.find(qn("w:keepNext")) is None:
        # After pStyle/numPr when present.
        kn = OxmlElement("w:keepNext")
        insert_at = 0
        for i, child in enumerate(list(p_pr)):
            if child.tag in {qn("w:pStyle"), qn("w:keepNext"), qn("w:keepLines")}:
                insert_at = i + 1
        p_pr.insert(insert_at, kn)


def _para_text_is_toc_title(text: str) -> bool:
    t = re.sub(r"\s+", " ", (text or "").strip()).upper().rstrip(":")
    return t in {"TABLE OF CONTENTS", "TABLE OF CONTENT", "CONTENTS"}


def _para_text_looks_like_toc_entry(text: str) -> bool:
    """True for TOC leader lines such as '1.0 OBJECTIVE …… 3' (not body headings)."""
    raw = (text or "").strip()
    if not raw:
        return False
    if _para_text_is_toc_title(raw):
        return True
    if re.search(r"[\.\u2026\u22ef]{3,}", raw):
        return bool(re.match(r"^\d+(\.\d+)*\b", raw))
    # Tab + trailing page digit (Word TOC1).
    if "\t" in raw and re.search(r"\t\s*\d{1,3}\s*$", raw):
        return bool(re.match(r"^\d+(\.\d+)*\b", raw))
    return False


def _sdt_element_looks_like_toc(sdt_el) -> bool:
    texts = [_para_plain_text(p) for p in sdt_el.findall(".//" + qn("w:p"))]
    texts = [t for t in texts if t]
    if not texts:
        return False
    if any(_para_text_is_toc_title(t) for t in texts):
        return True
    hits = sum(1 for t in texts if _para_text_looks_like_toc_entry(t))
    return hits >= 3


def _tbl_element_looks_like_toc(tbl_el, doc) -> bool:
    try:
        from docx.table import Table

        table = Table(tbl_el, doc)
    except Exception:
        return False
    if _is_att14_style_toc_table(table) or is_toc_list_table(table):
        return True
    return False


def _body_element_is_toc_region(el, doc) -> bool:
    """True when a body child is the TOC SDT, TOC table, TOC title, or TOC line."""
    if el.tag == qn("w:sdt"):
        return _sdt_element_looks_like_toc(el)
    if el.tag == qn("w:tbl"):
        return _tbl_element_looks_like_toc(el, doc)
    if el.tag != qn("w:p"):
        return False
    text = _para_plain_text(el)
    if _para_text_is_toc_title(text) or _para_text_looks_like_toc_entry(text):
        return True
    # Style-based body TOC (toc 1) without leaders still in runs.
    try:
        for para in doc.paragraphs:
            if para._element is el:
                return _is_toc_body_paragraph(para)
    except Exception:
        pass
    return False


def _is_first_body_section_after_toc(text: str) -> bool:
    """
    First protocol body heading after TOC — usually 1.0 OBJECTIVE.
    Must not match TOC leader lines.
    """
    raw = (text or "").strip()
    if not raw or _para_text_looks_like_toc_entry(raw):
        return False
    t = re.sub(r"\s+", " ", raw).upper().rstrip(":")
    if t in {"OBJECTIVE", "1.0 OBJECTIVE", "1 OBJECTIVE"}:
        return True
    if re.match(r"^1\.0\s+OBJECTIVE\b", t):
        return True
    # Some templates put SCOPE first after TOC; still start body on a new page.
    if t in {"SCOPE", "1.0 SCOPE", "2.0 SCOPE"} or re.match(
        r"^[12]\.0\s+SCOPE\b", t
    ):
        return True
    return False


def _ensure_neq_body_starts_after_toc(doc) -> bool:
    """
    NEQ only: keep the TOC sheet free of body text.

    Word/LibreOffice were flowing 1.0 OBJECTIVE onto the TOC page after fill
    trimmed pageBreakBefore. Force a hard page break on the first body section
    heading after the TOC block so OBJECTIVE starts on the next page.
    """
    if not _active_is_neq():
        return False

    body = doc.element.body
    children = list(body)
    last_toc_i = -1
    for i, el in enumerate(children):
        if _body_element_is_toc_region(el, doc):
            last_toc_i = i
    if last_toc_i < 0:
        return False

    for j in range(last_toc_i + 1, len(children)):
        el = children[j]
        if el.tag != qn("w:p"):
            continue
        text = _para_plain_text(el)
        if not text:
            continue
        if _body_element_is_toc_region(el, doc):
            continue
        if not _is_first_body_section_after_toc(text):
            # Skip stray cover/label lines; keep scanning for OBJECTIVE.
            if _is_tight_gap_section_heading(text):
                # Another major heading before OBJECTIVE — still start body here.
                _set_para_page_break_before(el, enabled=True)
                _ensure_para_keep_next(el)
                return True
            continue
        _set_para_page_break_before(el, enabled=True)
        _ensure_para_keep_next(el)
        return True
    return False


def _trim_empty_paras_before_attachment_heading(doc, *, keep: int = 2) -> int:
    """
    Keep at most ~2 blank lines above major section headings.

    Matches the original requal Word template: short spacers only, no forced
    pageBreakBefore. Forcing breaks after tables left mostly-empty pages
    (Qualification History) and large gaps above PRE-REQUISITES / 8.0 / 5.0.
    """
    keep = max(0, int(keep))
    body = doc.element.body
    removed = 0

    # Pass 1: drop surplus empty paragraphs above each target heading.
    changed = True
    while changed:
        changed = False
        children = list(body)
        for idx, el in enumerate(children):
            if el.tag != qn("w:p"):
                continue
            is_heading = _is_tight_gap_section_heading(_para_plain_text(el))
            empty_idxs, prev_j = _collect_empty_idxs_before(children, idx)
            precedes_table = prev_j >= 0 and children[prev_j].tag == qn("w:tbl")
            local_keep = 1 if precedes_table else keep
            # Trim if target heading OR if any element is preceded by an excessive stack (> 2) of empty lines
            if not is_heading and len(empty_idxs) <= 2:
                continue
            if len(empty_idxs) <= local_keep:
                continue
            for drop_i in sorted(empty_idxs[local_keep:]):
                node = children[drop_i]
                parent = node.getparent()
                if parent is not None:
                    parent.remove(node)
                    removed += 1
                    changed = True
            break

    # Pass 2: normalize spacers; clear forced page breaks from older fills.
    # Original has no pageBreakBefore on PRE-REQUISITES / EQUIPMENT / 8.0 —
    # Word flows naturally; keepNext avoids orphan headings at page bottoms.
    # NEQ: OBJECTIVE after TOC is re-applied by _ensure_neq_body_starts_after_toc.
    children = list(body)
    for el in list(children):
        if el.tag != qn("w:p"):
            continue
        is_heading = _is_tight_gap_section_heading(_para_plain_text(el))
        children = list(body)
        try:
            idx = children.index(el)
        except ValueError:
            continue
        empty_idxs, prev_j = _collect_empty_idxs_before(children, idx)
        precedes_table = prev_j >= 0 and children[prev_j].tag == qn("w:tbl")
        local_keep = 1 if precedes_table else keep
        if not is_heading and len(empty_idxs) <= 2:
            continue
        if len(empty_idxs) > local_keep:
            for drop_i in sorted(empty_idxs[local_keep:]):
                node = children[drop_i]
                parent = node.getparent()
                if parent is not None:
                    parent.remove(node)
                    removed += 1
            children = list(body)
            try:
                idx = children.index(el)
            except ValueError:
                continue
            empty_idxs, prev_j = _collect_empty_idxs_before(children, idx)

        for i in empty_idxs:
            _normalize_section_spacer_para(children[i])
            _set_para_page_break_before(children[i], enabled=False)
        _set_para_page_break_before(el, enabled=False)
        _ensure_para_keep_next(el)

    removed += _tighten_spacing_after_section_headings(doc)
    return removed


def _clamp_para_spacing_twips(p_el, *, before_max: int | None = None, after_max: int | None = None) -> bool:
    """Reduce paragraph before/after spacing so LO PDF matches Word's tighter look."""
    p_pr = p_el.find(qn("w:pPr"))
    if p_pr is None:
        return False
    spacing = p_pr.find(qn("w:spacing"))
    if spacing is None:
        return False
    changed = False
    if before_max is not None:
        raw = spacing.get(qn("w:before"))
        try:
            before = int(raw) if raw is not None else None
        except (TypeError, ValueError):
            before = None
        if before is not None and before > before_max:
            spacing.set(qn("w:before"), str(before_max))
            changed = True
        if spacing.get(qn("w:beforeAutospacing")) is not None:
            del spacing.attrib[qn("w:beforeAutospacing")]
            changed = True
    if after_max is not None:
        raw = spacing.get(qn("w:after"))
        try:
            after = int(raw) if raw is not None else None
        except (TypeError, ValueError):
            after = None
        if after is not None and after > after_max:
            spacing.set(qn("w:after"), str(after_max))
            changed = True
        if spacing.get(qn("w:afterAutospacing")) is not None:
            del spacing.attrib[qn("w:afterAutospacing")]
            changed = True
    return changed


def _tighten_spacing_after_section_headings(doc) -> int:
    """
    Word collapses heading afterAutospacing + next before; LibreOffice stacks both
    and leaves a large white gap (e.g. after EQUIPMENT/SYSTEM DESCRIPTION).
    Clamp heading after + following body before to ~one tight line.
    """
    fixed = 0
    body = doc.element.body
    children = list(body)
    for idx, el in enumerate(children):
        if el.tag != qn("w:p"):
            continue
        if not _is_tight_gap_section_heading(_para_plain_text(el)):
            continue
        if _clamp_para_spacing_twips(el, after_max=60):
            fixed += 1
        for j in range(idx + 1, min(idx + 5, len(children))):
            nxt = children[j]
            if nxt.tag != qn("w:p"):
                break
            text = _para_plain_text(nxt)
            if not text:
                _normalize_section_spacer_para(nxt)
                continue
            if _clamp_para_spacing_twips(nxt, before_max=60):
                fixed += 1
            break
    return fixed


def _repair_mashed_bullet_cells(doc) -> int:
    """
    Fix Acceptance Criteria cells flattened by an older _set_cell_text:
    all text in para 0, empty Word-list paras below → orphan bullet dots.
    Restore lines onto the numbered paragraphs (row 13 airflow pattern).
    """
    repaired = 0
    sentence_split = re.compile(r"(?<=\.)\s*(?=[A-Z])")
    for table in doc.tables:
        for row in table.rows:
            for cell in _unique_table_row_cells(row):
                paras = [
                    p
                    for p in cell.paragraphs
                    if not any(_run_has_image(r) for r in p.runs)
                ]
                if len(paras) < 2:
                    continue

                # Half-fixed: "Label:" + one listed para holding two mashed sentences.
                if (
                    len(paras) == 2
                    and (paras[0].text or "").strip().endswith(":")
                    and _para_has_numbering(paras[1])
                ):
                    segs = [
                        s.strip()
                        for s in sentence_split.split((paras[1].text or "").strip())
                        if s.strip()
                    ]
                    if len(segs) >= 2:
                        font_bold, font_italic, font_size, font_name = _extract_para_run_font(
                            paras[0]
                        )
                        if font_size is None:
                            font_size = _default_fill_font_size()
                        if not font_name:
                            font_name = "Arial"
                        _clear_text_runs(paras[1])
                        _write_run_with_font(
                            paras[1], segs[0], font_bold, font_italic, font_size, font_name
                        )
                        new_el = deepcopy(paras[1]._element)
                        for t_el in new_el.findall(f".//{{{W_NS}}}t"):
                            t_el.text = ""
                        paras[1]._element.addnext(new_el)
                        new_para = cell.paragraphs[-1]
                        _clear_text_runs(new_para)
                        _write_run_with_font(
                            new_para,
                            " ".join(segs[1:]),
                            font_bold,
                            font_italic,
                            font_size,
                            font_name,
                        )
                        repaired += 1
                        continue

                empty_listed = [
                    p
                    for p in paras[1:]
                    if not (p.text or "").strip() and _para_has_numbering(p)
                ]
                if not empty_listed:
                    continue
                # Only the mash pattern: trailing paras empty+listed, no other content paras.
                mid_ok = all(
                    (not (p.text or "").strip()) or _para_has_numbering(p)
                    for p in paras[1:]
                )
                if not mid_ok:
                    continue
                head = (paras[0].text or "").strip()
                if not head:
                    for p in empty_listed:
                        _delete_paragraph_element(p)
                    repaired += 1
                    continue
                lines = _split_value_for_list_paras(head, len(paras))
                if len([ln for ln in lines if ln]) < 2:
                    for p in empty_listed:
                        _delete_paragraph_element(p)
                    repaired += 1
                    continue
                font_bold, font_italic, font_size, font_name = _extract_para_run_font(paras[0])
                if font_size is None:
                    font_size = _default_fill_font_size()
                if not font_name:
                    font_name = "Arial"
                for para, line in zip(paras, lines):
                    _clear_text_runs(para)
                    if line:
                        _write_run_with_font(
                            para, line, font_bold, font_italic, font_size, font_name
                        )
                for para in list(paras[1:]):
                    if not (para.text or "").strip() and _para_has_numbering(para):
                        _delete_paragraph_element(para)
                _sync_list_cell_fonts_to_label(cell)
                repaired += 1
        # Always equalize bullet font to the label size (row 13 Acceptance Criteria).
        for row in table.rows:
            for cell in _unique_table_row_cells(row):
                if _sync_list_cell_fonts_to_label(cell):
                    repaired += 1
    return repaired


def add_footer_to_doc(doc) -> None:
    """
    Do not invent an annex footer. Original revalidation / HVAC templates have
    none; only strip legacy placeholder lines and pin real annex text if present.
    """
    _strip_placeholder_annex_footer(doc)
    _pin_annex_footer_inside_page(doc)


def _tighten_footer_paragraph(para) -> None:
    """No extra space, so the annex line cannot drop below the page edge."""
    fmt = para.paragraph_format
    fmt.space_before = Pt(0)
    fmt.space_after = Pt(0)
    fmt.line_spacing = 1.0


def _parse_abbreviation_line(text: str) -> tuple[str, str] | None:
    """Split 'AHU-Air Handling Unit' / 'MOC- Material…' into (abbr, definition)."""
    raw = re.sub(r"\s+", " ", (text or "").strip())
    if not raw or raw.upper().rstrip(":").endswith("ABBREVIATIONS"):
        return None
    # Stop at next major section heading.
    if re.match(
        r"^\d+(\.\d+)*\.?\s*(REVISION HISTORY|POST APPROVAL|REFERENCES)\b",
        raw,
        re.I,
    ) or raw.upper().rstrip(":") in {
        "REVISION HISTORY",
        "POST APPROVAL",
        "REFERENCES",
        "ABBREVIATIONS",
    }:
        return None
    m = re.match(r"^(.+?)\s*-\s*(.+)$", raw)
    if not m:
        return None
    abbr = m.group(1).strip()
    definition = m.group(2).strip()
    if not abbr or not definition:
        return None
    # Avoid rewriting ordinary sentences that happen to contain a hyphen.
    if len(abbr) > 40 or " " in abbr and len(abbr) > 24:
        return None
    return abbr, definition


def _rewrite_abbreviation_paragraph(p_el, abbr: str, definition: str) -> None:
    """
    Restore original requal layout:  AHU  [tab]  -  [tab]  Air Handling Unit
    so the hyphen column aligns and reads as 'AHU - definition'.
    """
    # Keep style / indent from the template line.
    old_pr = p_el.find(qn("w:pPr"))
    style_id = None
    ind_attrib = None
    if old_pr is not None:
        ps = old_pr.find(qn("w:pStyle"))
        if ps is not None:
            style_id = ps.get(qn("w:val"))
        ind = old_pr.find(qn("w:ind"))
        if ind is not None:
            ind_attrib = dict(ind.attrib)

    for child in list(p_el):
        p_el.remove(child)

    p_pr = OxmlElement("w:pPr")
    p_el.append(p_pr)
    if style_id:
        ps = OxmlElement("w:pStyle")
        ps.set(qn("w:val"), style_id)
        p_pr.append(ps)
    else:
        ps = OxmlElement("w:pStyle")
        ps.set(qn("w:val"), "ListParagraph")
        p_pr.append(ps)
    if ind_attrib:
        ind = OxmlElement("w:ind")
        for k, v in ind_attrib.items():
            ind.set(k, v)
        p_pr.append(ind)
    else:
        ind = OxmlElement("w:ind")
        ind.set(qn("w:left"), "1170")
        p_pr.append(ind)
    # One shared tab stop so IQ / GI / °C align with AHU / PIBCV (default tabs
    # left short abbreviations with a broken gap before the hyphen).
    tabs = OxmlElement("w:tabs")
    tab_def = OxmlElement("w:tab")
    tab_def.set(qn("w:val"), "left")
    tab_def.set(qn("w:pos"), "2700")
    tabs.append(tab_def)
    _ppr_insert_tabs(p_pr, tabs)

    spacing = OxmlElement("w:spacing")
    spacing.set(qn("w:before"), "0")
    spacing.set(qn("w:after"), "0")
    spacing.set(qn("w:line"), "240")
    spacing.set(qn("w:lineRule"), "auto")
    p_pr.append(spacing)

    def _run(text: str = None, *, tab: bool = False) -> None:
        run = OxmlElement("w:r")
        r_pr = OxmlElement("w:rPr")
        fonts = OxmlElement("w:rFonts")
        fonts.set(qn("w:ascii"), "Arial")
        fonts.set(qn("w:hAnsi"), "Arial")
        r_pr.append(fonts)
        sz = OxmlElement("w:sz")
        sz.set(qn("w:val"), "22")
        r_pr.append(sz)
        run.append(r_pr)
        if tab:
            run.append(OxmlElement("w:tab"))
        else:
            t_el = OxmlElement("w:t")
            if text and (text.startswith(" ") or text.endswith(" ")):
                t_el.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
            t_el.text = text or ""
            run.append(t_el)
        p_el.append(run)

    # AHU[tab] - Air Handling Unit  → hyphen column lines up for every row.
    _run(abbr)
    _run(tab=True)
    _run(f" - {definition}")


def _repair_abbreviation_entries(doc) -> int:
    """
    Requalification 14.0 ABBREVIATIONS lines lose their tab separators after fill
    ('AHU-Air Handling Unit'). Restore abbr + tab + hyphen + tab + definition
    like the original Word template so hyphens align with spaces around them.
    """
    body = list(doc.element.body)
    start = None
    for i, el in enumerate(body):
        if el.tag != qn("w:p"):
            continue
        plain = _para_plain_text(el).upper().rstrip(":")
        if plain == "ABBREVIATIONS" or re.match(r"^\d+(\.\d+)*\.?\s*ABBREVIATIONS$", plain):
            start = i
            break
    if start is None:
        return 0

    # Requal only: start the list on a new page so LibreOffice does not clip it
    # under the annex footer. NEQ's original protocol has no page break here —
    # ABBREVIATIONS follows REFERENCES after one blank line. A forced break
    # left the rest of that page empty (the large gap after 15.0 REFERENCES).
    if _active_is_neq():
        _set_para_page_break_before(body[start], enabled=False)
    else:
        _set_para_page_break_before(body[start], enabled=True)
    _ensure_para_keep_next(body[start])

    fixed = 0
    for el in body[start + 1 :]:
        if el.tag == qn("w:tbl"):
            break
        if el.tag != qn("w:p"):
            continue
        # Build plain text including tabs so we can detect a healthy layout.
        tokens = []
        for node in el.iter():
            if node.tag == qn("w:t") and node.text:
                tokens.append(node.text)
            elif node.tag == qn("w:tab"):
                tokens.append("\t")
        joined = "".join(tokens)
        plain = re.sub(r"\s+", " ", joined.replace("\t", " ")).strip()
        upper = plain.upper().rstrip(":")
        if upper in {"REVISION HISTORY", "POST APPROVAL", "REFERENCES"} or re.match(
            r"^\d+(\.\d+)*\.?\s*(REVISION HISTORY|POST APPROVAL)\b",
            plain,
            re.I,
        ):
            break
        # Already repaired: AHU \t - definition (shared tab stop).
        if joined.count("\t") == 1 and re.search(r"\t - ", joined):
            continue
        # Legacy original: AHU \t - \t definition
        if joined.count("\t") >= 2 and re.search(r"\t\s*-\s*\t", joined):
            # Still rewrite so IQ/GI/°C get the shared tab stop.
            pass
        parsed = _parse_abbreviation_line(plain)
        if not parsed:
            continue
        abbr, definition = parsed
        _rewrite_abbreviation_paragraph(el, abbr, definition)
        fixed += 1
    return fixed


def _pin_annex_footer_inside_page(doc) -> None:
    """
    Keep a real annex footer readable: not glued to the page edge, and not
    glued to the last table row. Skip when the template has no annex line.
    """
    _strip_placeholder_annex_footer(doc)
    for section in doc.sections:
        footer = section.footer
        if footer is None:
            continue
        annex_paras = [
            p
            for p in footer.paragraphs
            if "ANNEX" in (p.text or "") and not _is_placeholder_annex_footer_text(p.text)
        ]
        if not annex_paras:
            continue
        for para in annex_paras:
            _tighten_footer_paragraph(para)
        # Distance from page bottom edge → footer line (avoid edge-stuck look).
        if section.footer_distance is None or int(section.footer_distance) < int(Pt(40)):
            section.footer_distance = Pt(44)
        # Body must clear the annex line (LO otherwise paints IQ under ANNEX).
        footer_dxa = int(section.footer_distance)
        min_bottom = footer_dxa + int(Pt(56))
        if section.bottom_margin is None or int(section.bottom_margin) < min_bottom:
            section.bottom_margin = min_bottom


# Copy header*.xml and footer*.xml parts from the template zip back into the filled output zip.
def _restore_header_parts(template_path: str, output_path: str) -> None:
    with zipfile.ZipFile(template_path, "r") as tpl_zip:
        template_parts = {
            name: tpl_zip.read(name)
            for name in tpl_zip.namelist()
            if (name.startswith("word/header") or name.startswith("word/footer")) and name.endswith(".xml")
        }
    if not template_parts:
        return
    temp_path = output_path + ".hf.tmp"
    updated_names = set()
    with zipfile.ZipFile(output_path, "r") as out_zip:
        with zipfile.ZipFile(temp_path, "w", compression=zipfile.ZIP_DEFLATED) as tmp_zip:
            for item in out_zip.infolist():
                if item.filename in template_parts:
                    tmp_zip.writestr(item, template_parts[item.filename])
                    updated_names.add(item.filename)
                else:
                    tmp_zip.writestr(item, out_zip.read(item.filename))
            for name, data in template_parts.items():
                if name not in updated_names:
                    tmp_zip.writestr(name, data)
    os.replace(temp_path, output_path)
