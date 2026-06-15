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
)
import regex as re
import os
import shutil
import zipfile

DRAWING_NS = "http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"

FOOTER_ANNEX_TEXT = "ANNEX_xxx_xxx_XXXXX | (1.0) | SOP_XXXX_XXX_00000 | CLASS I"


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


def _clear_text_runs(para) -> None:
    """Remove only text runs from a paragraph; preserve image/drawing runs."""
    for run in list(para.runs):
        if not _run_has_image(run):
            run._element.getparent().remove(run._element)


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


def _set_paragraph_left_align(para) -> None:
    from docx.oxml.ns import qn

    para.alignment = WD_ALIGN_PARAGRAPH.LEFT
    p_pr = para._element.get_or_add_pPr()
    jc = p_pr.find(qn("w:jc"))
    if jc is None:
        jc = OxmlElement("w:jc")
        p_pr.append(jc)
    jc.set(qn("w:val"), "left")


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


def _enable_update_fields_on_open(doc) -> None:
    settings = doc.settings.element
    update = settings.find(qn("w:updateFields"))
    if update is None:
        update = OxmlElement("w:updateFields")
        settings.append(update)
    update.set(qn("w:val"), "true")


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


def _resolve_textbox_paragraph_element(doc, txbx_index: int, inner_index: int):
    for idx, _, txbx in iter_unique_textboxes(doc):
        if idx != txbx_index:
            continue
        inner_paras = txbx.findall(f"{{{W_NS}}}p")
        if inner_index < len(inner_paras):
            return inner_paras[inner_index]
    return None


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


def _add_hyperlink_to_paragraph(paragraph, text: str, url: str) -> None:
    """Insert a clickable external hyperlink run in a paragraph."""
    part = paragraph.part
    r_id = part.relate_to(url, "http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink", is_external=True)

    hyperlink = OxmlElement("w:hyperlink")
    hyperlink.set(qn("r:id"), r_id)

    run = OxmlElement("w:r")
    r_pr = OxmlElement("w:rPr")
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


def _set_cell_hyperlink(cell, text: str, url: str) -> None:
    """Write a blue underlined hyperlink into a table cell."""
    text = _normalize_text_value(text).strip()
    url = (url or "").strip()
    if not text:
        return

    for para in cell.paragraphs:
        if any(_run_has_image(r) for r in para.runs):
            continue
        _clear_text_runs(para)

    para = cell.paragraphs[0]
    _reset_paragraph_formatting(para)
    if url:
        _add_hyperlink_to_paragraph(para, text, url)
    else:
        para.add_run(text)


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


def _remove_paragraph_numbering(para) -> None:
    """Remove Word auto-list numbering so literal serial text is not duplicated."""
    p_pr = para._element.get_or_add_pPr()
    num_pr = p_pr.find(qn("w:numPr"))
    if num_pr is not None:
        p_pr.remove(num_pr)


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

    if existing and looks_like_sl_no_value(existing) and looks_like_sl_no_value(value):
        if _sl_no_numeric_part(existing) == _sl_no_numeric_part(value):
            return

    for para in cell.paragraphs:
        if any(_run_has_image(r) for r in para.runs):
            continue
        _remove_paragraph_numbering(para)

    _set_cell_text(cell, value)


def _set_cell_text(cell, value: str) -> None:
    """
    Write value into a table cell without destroying logo/image runs.
    Clears all text paragraphs first so no placeholder characters remain beside new text.
    """
    value = _normalize_text_value(value).strip()
    text_paras = []

    for para in cell.paragraphs:
        if any(_run_has_image(r) for r in para.runs):
            continue
        text_paras.append(para)

    if not text_paras:
        text_paras = [cell.add_paragraph()]

    for para in text_paras:
        _clear_text_runs(para)

    target_para = text_paras[0]
    _reset_paragraph_formatting(target_para)
    _set_paragraph_left_align(target_para)
    if value:
        target_para.add_run(value)

    for para in text_paras[1:]:
        _clear_text_runs(para)
        _reset_paragraph_formatting(para)
        _set_paragraph_left_align(para)


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


def _resolve_header_cell(doc, location: str):
    """Resolve header_0_row_2_col_2 to a header table cell."""
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
    return table.rows[row_index].cells[col_index]


def _body_paragraph(doc, para_index: int):
    paragraphs = doc.paragraphs
    if para_index < 0 or para_index >= len(paragraphs):
        return None
    return paragraphs[para_index]


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


def _fill_paragraph_blank(para, value: str) -> None:
    """Replace the first blank placeholder in a paragraph."""
    value = _normalize_text_value(value)
    for run in para.runs:
        if _run_has_image(run):
            continue
        if PARA_BLANK_RE.search(run.text):
            run.text = PARA_BLANK_RE.sub(value, run.text, count=1)
            return

    full_text = para.text
    if PARA_BLANK_RE.search(full_text):
        new_text = PARA_BLANK_RE.sub(value, full_text, count=1)
        if para.runs:
            _clear_text_runs(para)
            para.add_run(new_text)
        return

    if PARA_COLON_BLANK_RE.search(full_text.strip()):
        new_text = re.sub(r":\s*$", f": {value}", full_text.strip())
        _clear_text_runs(para)
        para.add_run(new_text)


def _apply_single_field(
    doc,
    location: str,
    value: str,
    locked: bool,
    attachment_links: dict | None = None,
    field_type: str | None = None,
) -> None:
    """Apply one parsed field location to the open Document (body or header)."""
    if locked and field_type != "table_sl_no" and not str(field_type or "").startswith(
        "approval_stamp"
    ):
        return
    if value is None or str(value).strip() == "":
        return

    value = _normalize_text_value(value)
    parts = location.split("_")

    try:
        if location.startswith("header_"):
            cell = _resolve_header_cell(doc, location)
            if cell is not None:
                _set_cell_text(cell, value)

        elif location.startswith("table") and "_col_" in location:
            t = int(parts[1])
            r = int(parts[3])
            c = int(parts[5])
            row = doc.tables[t].rows[r]
            cell = row.cells[c]
            if str(value).lower() in ("yes", "no"):
                _fill_yes_no_cell(cell, value)
            elif len(row.cells) == 1:
                _fill_colon_label_cell(cell, value)
            elif field_type == "table_sl_no":
                _set_sl_no_cell_text(cell, value)
            else:
                # Plain filename text — hyperlinks break in PDF/LibreOffice exports.
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


def fill_docx(
    template_path: str,
    field_values: dict,
    output_path: str,
    attachment_links: dict | None = None,
) -> str:
    """
    Fill template DOCX with user values.
    Body fields are written first; header XML is restored from the template (logos),
    then header text fields are applied so fills are not overwritten.
    attachment_links maps field location → download URL for clickable attachment cells.
    """
    shutil.copy2(template_path, output_path)
    doc = Document(output_path)

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

    add_footer_to_doc(doc)
    doc.save(output_path)
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
    _inject_page_number_fields(doc)
    doc.save(output_path)

    return output_path


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
        run_annex = para.add_run(FOOTER_ANNEX_TEXT)
        run_annex.font.size = Pt(7)


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
