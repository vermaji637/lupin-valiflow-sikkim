from docx import Document
from docx.shared import Pt
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from doc_parser import (
    PARA_BLANK_RE,
    PARA_COLON_BLANK_RE,
    dedupe_colon_label,
    count_underlined_tab_blanks,
    iter_unique_textboxes,
    _para_text_from_element,
    looks_like_sl_no_value,
    is_sl_no_column_header,
)
import regex as re
import os
import shutil
import zipfile
from copy import deepcopy

# Namespace for inline/anchored drawing elements inside runs.
DRAWING_NS = "http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"

# Standard annex footer line appended to every generated report.
FOOTER_ANNEX_TEXT = "ANNEX_xxx_xxx_XXXXX | (1.0) | SOP_XXXX_XXX_00000 | CLASS I"


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


def _set_cell_text(cell, value: str) -> None:
    """
    Write value into a table cell without destroying logo/image runs.
    Preserves the first text run's font so filled cells match the original DOCX look.
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
        if font_size is None and font_name is None and font_bold is None:
            for run in para.runs:
                if (run.text or "").strip() or run.bold is not None or run.font.size is not None:
                    font_bold = run.bold
                    font_italic = run.italic
                    font_size = run.font.size
                    font_name = run.font.name
                    break

    if is_header:
        if not font_name:
            font_name = "Arial"
        if font_size is None or int(getattr(font_size, "pt", 0) or 0) < 12:
            font_size = Pt(12)

    if not text_paras:
        text_paras = [cell.add_paragraph()]

    for para in text_paras:
        _clear_text_runs(para)

    target_para = text_paras[0]
    # Keep existing paragraph alignment/indent from the template — do not force left/reset.
    if value:
        new_run = target_para.add_run(value)
        try:
            if font_bold is not None:
                new_run.bold = font_bold
            if font_italic is not None:
                new_run.italic = font_italic
            if font_size is not None:
                new_run.font.size = font_size
            if font_name:
                new_run.font.name = font_name
        except Exception:
            pass

    for para in text_paras[1:]:
        _clear_text_runs(para)


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


# Toggle Yes/No checkbox symbols on inline paragraph radio lines.
def _fill_paragraph_yes_no(para, choice: str) -> None:
    """Mark Yes or No on inline paragraph radio lines like 'Training imparted: Yes □ No □'."""
    choice = _normalize_text_value(choice).strip().lower()
    if choice not in ("yes", "no"):
        return
    full_text = para.text
    if choice == "yes":
        new_text = re.sub(
            r"(Yes)\s*[\u25a1\u2610☐□]",
            r"\1 \u2611",
            full_text,
            count=1,
            flags=re.IGNORECASE,
        )
        new_text = re.sub(
            r"(No)\s*[\u25a1\u2610☐□\u2611☑]",
            r"\1 \u25a1",
            new_text,
            count=1,
            flags=re.IGNORECASE,
        )
    else:
        new_text = re.sub(
            r"(Yes)\s*[\u25a1\u2610☐□\u2611☑]",
            r"\1 \u25a1",
            full_text,
            count=1,
            flags=re.IGNORECASE,
        )
        new_text = re.sub(
            r"(No)\s*[\u25a1\u2610☐□]",
            r"\1 \u2611",
            new_text,
            count=1,
            flags=re.IGNORECASE,
        )
    _clear_text_runs(para)
    para.add_run(new_text)


# Mark the first text run in a checklist paragraph as checked without duplicating label text.
def _fill_paragraph_checkbox(para) -> None:
    """Mark checklist paragraph checked via symbol run — do not duplicate label text."""
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

    full = (para.text or "").strip()
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
    for run in para.runs:
        if _run_has_image(run):
            continue
        if PARA_BLANK_RE.search(run.text):
            run.text = PARA_BLANK_RE.sub(value, run.text, count=1)
            _fix_overhanging_list_indent(para)
            return

    full_text = para.text
    if PARA_BLANK_RE.search(full_text):
        new_text = PARA_BLANK_RE.sub(value, full_text, count=1)
        if para.runs:
            _clear_text_runs(para)
            para.add_run(new_text)
        _fix_overhanging_list_indent(para)
        return

    if PARA_COLON_BLANK_RE.search(full_text.strip()):
        if _append_colon_blank_value(para, value):
            return
        new_text = re.sub(r":\s*$", f": {value}", full_text.strip())
        _clear_text_runs(para)
        para.add_run(new_text)
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
    # A list-of-attachment data row can have only the title filled.
    # "Dust Extractor CFM Measurement" and "Area Recovery Test report"
    # are those rows, not section banners.
    if len(seen) >= 3 and len(texts) < len(seen):
        return False
    unique = {_normalize_header_compare(t) for t in texts}
    if len(unique) != 1:
        return False
    text = texts[0]
    if len(text) > 220:
        return False
    lower = text.lower()
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
    if row_index == 0:
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
    # Locked cells keep the original DOCX text — never overwrite pre-filled content.
    if locked and normalized_type != "table_sl_no":
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
        if location.startswith("header_"):
            cell = _resolve_header_cell(doc, location)
            if cell is not None:
                if _normalize_header_compare(cell.text) == _normalize_header_compare(value):
                    return
                _set_cell_text(cell, value)

        elif location.startswith("table") and "_col_" in location:
            t = int(parts[1])
            r = int(parts[3])
            c = int(parts[5])
            # Never push user/data values into section banners or repeated header rows.
            if _is_structural_table_row(doc, t, r):
                return
            row = doc.tables[t].rows[r]
            if c >= len(row.cells):
                return
            cell = row.cells[c]
            # Same text already present — keep original formatting/layout.
            if _normalize_header_compare(cell.text) == _normalize_header_compare(value):
                return
            if str(value).lower() in ("yes", "no"):
                _fill_yes_no_cell(cell, value)
            elif len(row.cells) == 1:
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
            p = int(parts[1])
            para = _body_paragraph(doc, p)
            if para is not None:
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
    return False


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


def _unbold_filled_body_lines(doc) -> None:
    """
    Dotted remark lines and the blank lines under SUMMARY & CONCLUSION
    carry bold in the template. Typed text must stay regular weight.
    The label before the colon stays bold.
    """
    body = doc.element.body
    for child in list(body):
        if child.tag != qn("w:p"):
            continue
        if _split_bold_label(child):
            continue
        text = _paragraph_plain_text(child)
        if _looks_like_kept_heading(text):
            continue
        _strip_paragraph_bold(child)


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


def fill_docx(
    template_path: str,
    field_values: dict,
    output_path: str,
    attachment_links: dict | None = None,
    equipment_id: str | None = None,
    table_ops: list | None = None,
) -> tuple[str, dict[str, str]]:
    """
    Fill template DOCX with user values.
    Body fields are written first; header XML is restored from the template (logos),
    then header text fields are applied so fills are not overwritten.
    attachment_links maps field location → download URL for clickable attachment cells.

    Returns (output_path, repaired_values) where repaired_values maps location →
    restored template cell text for fields that had column-header echo corruption.
    """
    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    shutil.copy2(template_path, output_path)
    doc = Document(output_path)

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

    if table_ops:
        from neq_docx_save import _apply_table_ops
        _apply_table_ops(doc, table_ops)

    add_footer_to_doc(doc)
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
    from neq_docx_save import _keep_filled_text_inside_cells
    _keep_filled_text_inside_cells(doc, unify_font=False, break_long_tokens=False)
    _drop_floating_tables_below_following_text(doc)
    _fill_blank_sl_no_cells(doc)
    _keep_approval_names_inside_cells(doc)
    _apply_heading_font_from_paragraph_mark(doc)
    _unbold_filled_body_lines(doc)
    _inject_page_number_fields(doc)
    _normalize_header_table_fonts(doc, font_name="Arial", font_size_pt=12.0)
    # Font unify runs after the footer is written and can leave footer distance at 0.
    _pin_annex_footer_inside_page(doc)
    doc.save(output_path)

    return output_path, repaired_values


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
                for row in table.rows:
                    for cell in row.cells:
                        for para in cell.paragraphs:
                            if any(_run_has_image(r) for r in para.runs):
                                continue
                            for run in para.runs:
                                if (run.text or "").strip():
                                    run.font.name = font_name
                                    run.font.size = Pt(font_size_pt)


def _keep_approval_names_inside_cells(doc) -> None:
    """
    Approval name and signature paragraphs use a negative indent, so the
    e-sign name starts left of the cell and runs through the right border.
    Keep that text inside the cell, left aligned, so Word wraps it there.
    """
    name_headers = ("name & designation", "name and designation", "name/designation")
    sign_headers = ("signature & date", "signature and date", "sign and date", "sign/date")

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
        value_cols = {
            index
            for index, label in enumerate(header)
            if label in name_headers or label in sign_headers
        }
        if not value_cols:
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
                if this_col not in value_cols:
                    continue
                if not (cell.text or "").strip():
                    continue
                for para in cell.paragraphs:
                    _clamp_paragraph_inside_cell(para)
                if label_cell is not None:
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


def add_footer_to_doc(doc) -> None:
    """Write only the annex reference line in the document footer (no toolbar note)."""
    for section in doc.sections:
        footer = section.footer
        footer.is_linked_to_previous = False
        while footer.paragraphs:
            element = footer.paragraphs[0]._element
            element.getparent().remove(element)
        para = footer.add_paragraph()
        para.alignment = WD_ALIGN_PARAGRAPH.CENTER
        _tighten_footer_paragraph(para)
        run_annex = para.add_run(FOOTER_ANNEX_TEXT)
        run_annex.font.size = Pt(9)
    _pin_annex_footer_inside_page(doc)


def _tighten_footer_paragraph(para) -> None:
    """No extra space, so the annex line cannot drop below the page edge."""
    fmt = para.paragraph_format
    fmt.space_before = Pt(0)
    fmt.space_after = Pt(0)
    fmt.line_spacing = 1.0


def _pin_annex_footer_inside_page(doc) -> None:
    """
    A footer distance of 0 draws the annex line on the page edge.
    Word then shows it in the gap under the sheet, outside the footer.
    """
    for section in doc.sections:
        footer = section.footer
        if footer is None:
            continue
        annex_paras = [p for p in footer.paragraphs if "ANNEX" in (p.text or "")]
        if not annex_paras:
            continue
        for para in annex_paras:
            _tighten_footer_paragraph(para)
        # Half an inch from the edge keeps one line inside the bottom margin.
        if section.footer_distance is None or int(section.footer_distance) < int(Pt(28)):
            section.footer_distance = Pt(36)
        if section.bottom_margin is None or int(section.bottom_margin) < int(Pt(64)):
            section.bottom_margin = Pt(72)


# Copy header*.xml parts from the template zip back into the filled output zip.
def _restore_header_parts(template_path: str, output_path: str) -> None:
    with zipfile.ZipFile(template_path, "r") as tpl_zip:
        template_parts = {
            name: tpl_zip.read(name)
            for name in tpl_zip.namelist()
            if name.startswith("word/header") and name.endswith(".xml")
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
