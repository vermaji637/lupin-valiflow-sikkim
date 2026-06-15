from docx import Document
import regex as re
import os

W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
DRAWING_NS = "http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"

# Matches fill-in-the-blank placeholders in body paragraphs (underscores and dot leaders).
PARA_BLANK_PATTERN = (
    r"(?:"
    r"_{3,}"
    r"|\.{3,}"
    r"|\u2026+"
    r"|\ufffd+"
    r"|(?:\s*\.\s*){3,}"
    r")"
)
PARA_BLANK_RE = re.compile(PARA_BLANK_PATTERN)

# Label ends with colon and only whitespace/tabs after — e.g. "Model No.:"
PARA_COLON_BLANK_RE = re.compile(r":\s*$")

# Repeated label in one cell/paragraph — e.g. "EFFECTIVE DATE:EFFECTIVE DATE:"
DUPLICATE_COLON_LABEL_RE = re.compile(r"^(.+?:)\s*\1\s*$", re.IGNORECASE)

REPORT_NUMBER_LABEL_RE = re.compile(r"report\s*(?:no\.?|number|#)", re.IGNORECASE)

# Yes/No patterns in table cells.
CHECKBOX_CELL_RE = re.compile(
    r"(?:"
    r"[\u25a1\u2610\u2611\u2713☐☑]\s*(?:Yes|No)"
    r"|(?:Yes|No)\s*[\u25a1\u2610\u2611\u2713☐☑]"
    r"|\bYes\b\s+\bNo\b"
    r"|\bNo\b\s+\bYes\b"
    r")",
    re.IGNORECASE,
)

# Scope checklist lines under "applicable for:" (Word drawing checkbox or plain text).
PARAGRAPH_CHECKLIST_RE = re.compile(
    r"^(?:New/\s*first\s+installation|Major\s+modification|Shifted\s+from|In\s+Use\s+equipment)",
    re.IGNORECASE,
)

# TOC table detection — skip only real table-of-contents tables.
SECTION_TOC_LINE_RE = re.compile(r"^\d+\.\d+[\t\s]+[A-Z0-9/].*\d+\s*$", re.IGNORECASE)

SIGNATURE_TABLE_KEYWORDS = (
    "prepared by",
    "checked by",
    "approved by",
    "name & designation",
    "signature & date",
    "sign/date",
    "signature and date",
)

# Column headers that indicate a file upload cell (not plain text).
ATTACHMENT_KEYWORDS = (
    "certificate",
    "proof",
    "evidence",
    "annexure",
    "annex",
    "upload",
)

# Header table label → value cell is typically one column to the right on the same row.
HEADER_FIELD_SPECS = (
    ("FACILITY/SECTION", 2, 2),
    ("EQUIPMENT/SYSTEM  NAME", 2, 4),
    ("EQUIPMENT/SYSTEM NAME", 2, 4),
    ("EQUIPMENT/SYSTEM I.D NO.", 3, 4),
    ("EQUIPMENT/SYSTEM I.D No.", 3, 4),
    ("EQUIPMENT/SYSETM  I.D No.", 3, 4),
    ("DOCUMENT No.", 4, 2),
    ("DOCUMENT NO.", 4, 2),
    ("VERSION", 4, 4),
    ("EFFECTIVE DATE", 3, 4),
    ("EFFECTIVE DATE", 4, 4),
    ("FACILITY / SECTION", 3, 2),
    ("EQUIPMENT/SYSTEM ID NO.", 2, 4),
    ("EQUIPMENT/SYSTEM ID NO.", 2, 6),
)


def _normalize_cover_label(text: str) -> str:
    return re.sub(r"\s+", " ", (text or "").strip().lower()).rstrip(":")


def _match_cover_label_cell(text: str) -> tuple[str, str] | None:
    """Return (cover_field key, display label) for protocol / report cover cells."""
    ll = _normalize_cover_label(text)
    if not ll:
        return None
    patterns = (
        (r"document\s*no\.?", "document_no", "Document No."),
        (r"document\s*title", "document_title", "Document Title"),
        (r"equipment\s*/\s*system\s*name", "equipment_name", "Equipment/System Name"),
        (r"equipment\s*/\s*system\s*id", "equipment_id", "Equipment/System ID No."),
        (r"facility\s*/\s*section", "facility_section", "Facility / Section"),
        (r"effective\s*date", "effective_date", "Effective Date"),
    )
    for pat, key, display in patterns:
        if re.search(pat, ll):
            return key, display
    return None


def is_protocol_cover_table(table) -> bool:
    """First-page style cover grid: Document Title, Equipment, Facility, Effective Date."""
    if not table.rows or len(table.rows) < 2:
        return False
    text = " ".join(
        cell.text.strip().lower()
        for row in table.rows[:8]
        for cell in row.cells
    )
    return "document title" in text and (
        "effective date" in text
        or ("equipment" in text and "system" in text)
    )


def _scan_cover_table_fields(
    table,
    location_prefix: str,
    fillable_fields: list,
    seen_locations: set,
) -> set:
    """Find label/value pairs on protocol cover grids; return locations to skip in generic parser."""
    skip: set = set()
    for row_index, row in enumerate(table.rows):
        cells = row.cells
        for col_index, cell in enumerate(cells):
            matched = _match_cover_label_cell(cell.text)
            if not matched:
                continue
            key, display = matched
            value_col = col_index + 1
            while value_col < len(cells):
                neighbor = cells[value_col].text.strip()
                if neighbor and _match_cover_label_cell(neighbor):
                    value_col += 1
                    continue
                break
            if value_col >= len(cells):
                continue
            location = f"{location_prefix}_row_{row_index}_col_{value_col}"
            if location in seen_locations:
                skip.add(location)
                continue
            seen_locations.add(location)
            skip.add(location)
            val_text = cells[value_col].text.strip()
            fillable_fields.append({
                "label": display,
                "value": val_text,
                "locked": len(val_text) > 1,
                "type": "protocol_cover",
                "location": location,
                "widget": "date" if key == "effective_date" else "text",
                "cover_field": key,
            })
    return skip


def _element_in_textbox(element) -> bool:
    parent = element
    while parent is not None:
        tag = parent.tag.split("}")[-1] if "}" in parent.tag else parent.tag
        if tag == "txbxContent":
            return True
        parent = parent.getparent()
    return False


def _para_text_from_element(p_element, exclude_textboxes: bool = False) -> str:
    """Plain text from a raw OOXML <w:p> element (handles split runs)."""
    if p_element is None:
        return ""
    texts = []
    for t in p_element.findall(f".//{{{W_NS}}}t"):
        if not t.text:
            continue
        if exclude_textboxes and _element_in_textbox(t):
            continue
        texts.append(t.text)
    return "".join(texts).strip()


def _para_index_for_body_element(p_element, paragraphs) -> int | None:
    """Map a body <p> element to doc.paragraphs index."""
    for index, para in enumerate(paragraphs):
        if para._element is p_element:
            return index
    return None


def collect_paragraph_texts(doc) -> dict[int, str]:
    """
    Best-effort paragraph text keyed by para index.

    Word sometimes stores label text only in body-walk OOXML while para.text is empty
    (e.g. EFFECTIVE DATE: duplicated across runs / text boxes).
    """
    paragraphs = doc.paragraphs
    para_text_by_index: dict[int, str] = {}

    for child in doc.element.body:
        tag = child.tag.split("}")[-1] if "}" in child.tag else child.tag
        if tag != "p":
            continue
        para_index = _para_index_for_body_element(child, paragraphs)
        if para_index is None:
            continue
        text = _para_text_from_element(child, exclude_textboxes=True)
        previous = para_text_by_index.get(para_index, "")
        if len(text) >= len(previous):
            para_text_by_index[para_index] = text

    return para_text_by_index


def iter_unique_textboxes(doc):
    """
    Yield (textbox_index, parent_para_index, txbxContent element) for each text box.
    Skips duplicate txbxContent copies inside mc:AlternateContent fallbacks.
    """
    txbx_index = 0
    for para_index, para in enumerate(doc.paragraphs):
        txbx_elements = para._element.findall(f".//{{{W_NS}}}txbxContent")
        if not txbx_elements:
            continue
        yield txbx_index, para_index, txbx_elements[0]
        txbx_index += 1


def count_underlined_tab_blanks(p_element) -> int:
    """Count underlined tab characters used as fill-in blanks in Word."""
    count = 0
    for run in p_element.findall(f".//{{{W_NS}}}r"):
        if run.find(f"{{{W_NS}}}tab") is None:
            continue
        r_pr = run.find(f"{{{W_NS}}}rPr")
        if r_pr is not None and r_pr.find(f"{{{W_NS}}}u") is not None:
            count += 1
    return count


def is_border_blank_paragraph(p_element) -> bool:
    """True for empty paragraphs with a bottom/top border used as a write-in line."""
    if _para_text_from_element(p_element).strip():
        return False
    p_pr = p_element.find(f"{{{W_NS}}}pPr")
    if p_pr is None:
        return False
    p_bdr = p_pr.find(f"{{{W_NS}}}pBdr")
    if p_bdr is None:
        return False
    return any(
        p_bdr.find(f"{{{W_NS}}}{edge}") is not None
        for edge in ("bottom", "top", "between")
    )


def _infer_textbox_blank_widget(label: str, inner_text: str) -> str:
    ll = (label or inner_text or "").lower()
    if "from date" in ll or (ll.startswith("to ") or ll == "to"):
        return "date"
    if is_date_field_label(label) or is_date_field_label(inner_text):
        return "date"
    return "text"


def _scan_textbox_fields(doc, fillable_fields: list) -> set[int]:
    """
    Parse fillable fields inside bordered text boxes (e.g. History of Equipment section).
    Returns parent paragraph indices that own a text box.
    """
    parent_paras: set[int] = set()
    recommendation_counters: dict[int, int] = {}

    for txbx_index, parent_para_index, txbx in iter_unique_textboxes(doc):
        parent_paras.add(parent_para_index)
        inner_paras = txbx.findall(f"{{{W_NS}}}p")
        after_recommendation = False

        for inner_index, p_element in enumerate(inner_paras):
            inner_text = _para_text_from_element(p_element).strip()
            location_base = f"txbx_{txbx_index}_p_{inner_index}"
            ll = inner_text.lower()

            if ll.startswith("recommendation"):
                after_recommendation = True
                recommendation_counters[txbx_index] = 0
                continue

            if after_recommendation and (
                is_border_blank_paragraph(p_element)
                or (
                    not inner_text
                    and any(
                        _para_text_from_element(inner_paras[j]).strip()
                        for j in range(inner_index + 1, len(inner_paras))
                    )
                )
            ):
                recommendation_counters[txbx_index] = (
                    recommendation_counters.get(txbx_index, 0) + 1
                )
                line_no = recommendation_counters[txbx_index]
                fillable_fields.append({
                    "label": f"Recommendation line {line_no}",
                    "value": "",
                    "locked": False,
                    "type": "textbox_line",
                    "location": location_base,
                    "widget": "text",
                    "parent_para": parent_para_index,
                })
                continue

            if after_recommendation and inner_text:
                after_recommendation = False

            if "from date" in ll and "to" in ll:
                fillable_fields.append({
                    "label": "History card from date",
                    "value": "",
                    "locked": False,
                    "type": "textbox_blank_part",
                    "location": f"{location_base}_blank_0",
                    "widget": "date",
                    "parent_para": parent_para_index,
                    "blank_index": 0,
                })
                fillable_fields.append({
                    "label": "History card to date",
                    "value": "",
                    "locked": False,
                    "type": "textbox_blank_part",
                    "location": f"{location_base}_blank_1",
                    "widget": "date",
                    "parent_para": parent_para_index,
                    "blank_index": 1,
                })
                continue

            if "reviewed by" in ll:
                fillable_fields.append({
                    "label": "Reviewed by",
                    "value": "",
                    "locked": False,
                    "type": "textbox_blank_part",
                    "location": f"{location_base}_blank_0",
                    "widget": "text",
                    "parent_para": parent_para_index,
                    "blank_index": 0,
                })
                continue

            underscore_blanks = len(find_paragraph_blanks(inner_text))
            tab_blanks = count_underlined_tab_blanks(p_element)
            blank_total = max(underscore_blanks, tab_blanks)
            if blank_total <= 1 and has_paragraph_blank(inner_text):
                widget = _infer_textbox_blank_widget(inner_text, inner_text)
                fillable_fields.append({
                    "label": inner_text[:60] or location_base,
                    "value": "",
                    "locked": False,
                    "type": "textbox_blank",
                    "location": location_base,
                    "widget": widget,
                    "parent_para": parent_para_index,
                })
            elif blank_total > 1:
                for blank_index in range(blank_total):
                    fillable_fields.append({
                        "label": f"{inner_text[:40]} (blank {blank_index + 1})",
                        "value": "",
                        "locked": False,
                        "type": "textbox_blank_part",
                        "location": f"{location_base}_blank_{blank_index}",
                        "widget": _infer_textbox_blank_widget(inner_text, inner_text),
                        "parent_para": parent_para_index,
                        "blank_index": blank_index,
                    })

    return parent_paras


def find_paragraph_blanks(text: str) -> list:
    """Return all blank placeholder runs in a paragraph string."""
    return PARA_BLANK_RE.findall(text)


def iter_paragraph_blank_matches(text: str):
    """Yield regex match objects for each blank placeholder in order."""
    return list(PARA_BLANK_RE.finditer(text))


def dedupe_colon_label(text: str) -> str:
    """
    Collapse duplicated colon labels such as 'EFFECTIVE DATE:EFFECTIVE DATE:' → 'EFFECTIVE DATE:'.
    """
    t = re.sub(r"\s+", " ", (text or "").strip())
    if not t:
        return t
    match = DUPLICATE_COLON_LABEL_RE.match(t)
    if match:
        return match.group(1).strip()
    parts = re.split(r"\s*:\s*", t, maxsplit=1)
    if len(parts) == 2:
        left, right = parts[0].strip(), parts[1].strip().rstrip(":")
        if left.lower() == right.lower():
            return f"{left}:"
    return t


def is_report_number_label(label: str) -> bool:
    """True for column/cell labels like 'Report No.' — must stay a text field."""
    return bool(REPORT_NUMBER_LABEL_RE.search((label or "").strip()))


def is_date_field_label(label: str) -> bool:
    """True when a label should use a date picker (excludes 'Report No.' headers)."""
    if is_report_number_label(label):
        return False
    ll = (label or "").lower()
    return "date" in ll


def has_paragraph_blank(text: str) -> bool:
    """True when a paragraph contains dot/underscore blanks or a trailing colon blank."""
    if not text or not text.strip():
        return False
    normalized = dedupe_colon_label(text)
    if find_paragraph_blanks(normalized):
        return True
    return bool(PARA_COLON_BLANK_RE.search(normalized.strip()))


def is_checkbox_cell(text: str) -> bool:
    """True when a table cell contains a Yes/No checkbox pattern."""
    if not text or not text.strip():
        return False
    return bool(CHECKBOX_CELL_RE.search(text))


def is_yes_no_radio_cell(text: str) -> bool:
    """True when cell text is a Yes/No choice pattern (not plain department name)."""
    if not text or not text.strip():
        return False
    return bool(CHECKBOX_CELL_RE.search(text))


def is_paragraph_yes_no_radio(text: str) -> bool:
    """Paragraph lines like 'Training imparted: Yes □ No □'."""
    if not text or not text.strip():
        return False
    return bool(CHECKBOX_CELL_RE.search(text))


def paragraph_yes_no_label(text: str) -> str:
    """Extract leading label before the Yes/No choice group."""
    parts = re.split(r"\s*:\s*", text, maxsplit=1)
    if parts and parts[0].strip():
        return parts[0].strip()
    return re.sub(r"\s*(?:Yes|No)\b.*", "", text, flags=re.IGNORECASE).strip() or text[:60]


def element_has_drawing_checkbox(element) -> bool:
    """True when OOXML element contains a w:drawing (image-based form checkbox)."""
    if element is None:
        return False
    return bool(element.findall(f".//{{{W_NS}}}drawing"))


def paragraph_has_drawing_checkbox(para) -> bool:
    """True when a body paragraph uses a Word drawing as its checkbox control."""
    return element_has_drawing_checkbox(para._element)


def paragraph_text_has_prechecked_symbol(text: str) -> bool:
    """True when paragraph label already contains a checked box symbol."""
    return bool(re.match(r"^[\u2611☑✓]", (text or "").strip()))


def is_paragraph_checklist_item(text: str, para) -> bool:
    """Detect scope checklist lines like 'New/ first installation'."""
    t = (text or "").strip()
    if not t:
        return False
    if paragraph_has_drawing_checkbox(para):
        return True
    return bool(PARAGRAPH_CHECKLIST_RE.match(t))


def is_comments_header_label(label: str) -> bool:
    """Header labels that should have a textarea in the row below."""
    ll = re.sub(r":\s*$", "", (label or "").strip())
    ll = re.sub(r"\s+", " ", ll).strip().lower()
    return ll in ("comments", "comment", "remarks", "observation", "description")


def is_comments_section_table(table) -> bool:
    """True for single-column tables whose first row is a Comments/Remarks header."""
    if not table.rows or len(table.rows) < 2:
        return False
    if len(table.rows[0].cells) != 1:
        return False
    header = table.rows[0].cells[0].text.strip()
    return is_comments_header_label(header)


def is_cell_bold(cell) -> bool:
    """True when a table cell's text is bold (header styling in Word)."""
    from docx.oxml.ns import qn

    for para in cell.paragraphs:
        for run in para.runs:
            if run.bold:
                return True
            r_pr = run._element.rPr
            if r_pr is None:
                continue
            bold = r_pr.find(qn("w:b"))
            if bold is not None:
                val = bold.get(qn("w:val"))
                if val is None or str(val).lower() not in ("0", "false"):
                    return True
    return False


def is_two_col_grid_table(table) -> bool:
    """
    Detect 2-column matrix tables: row 0 = column headers, rows below = data grid.

    Example: Deviation (if any) | Corrective action taken  (header row)
             empty             | empty                     (data rows)

    Distinct from label-value tables where every row has a label in column 0
    (e.g. Name of Equipment | Equipment1).
    """
    if not table.rows or len(table.rows) < 2:
        return False
    if len(table.rows[0].cells) != 2:
        return False

    header_cells = table.rows[0].cells
    header_texts = [c.text.strip() for c in header_cells]
    if not all(header_texts):
        return False

    # Label-value stacks always have a descriptor in column 0 on the next row.
    if len(table.rows) > 1 and table.rows[1].cells[0].text.strip():
        return False

    # Require at least one empty data row beneath the header.
    has_empty_data_row = False
    for row in table.rows[1:]:
        row_texts = [c.text.strip() for c in row.cells[:2]]
        if not any(row_texts):
            has_empty_data_row = True
            break
    if not has_empty_data_row:
        return False

    # Header row is usually bold in the DOCX template.
    if all(is_cell_bold(c) for c in header_cells):
        return True

    # Known deviation / corrective-action column header pair.
    left, right = (t.lower() for t in header_texts)
    if "deviation" in left and "corrective" in right:
        return True

    return False


def is_toc_entry_text(text: str) -> bool:
    """True for TOC lines such as '1.0 OBJECTIVE …… 3' (dot/ellipsis leaders + page)."""
    if not text or not text.strip():
        return False
    t = text.strip()
    if re.match(r"^table\s+of\s+contents$", t, re.IGNORECASE):
        return True
    if SECTION_TOC_LINE_RE.match(t):
        return True
    if not re.search(r"\d+\.\d+\s+", t):
        return False
    has_leaders = (
        "\u2026" in t
        or re.search(r"\.{4,}", t)
        or re.search(r"(?:\s*\.\s*){3,}", t)
    )
    has_trailing_page = bool(re.search(r"\d+\s*$", t))
    return bool(has_leaders and has_trailing_page)


def is_toc_list_table(table) -> bool:
    """True for single-column tables whose rows are TOC entries."""
    if not table.rows:
        return False
    if any(len(row.cells) != 1 for row in table.rows):
        return False
    toc_rows = 0
    content_rows = 0
    for row in table.rows:
        text = row.cells[0].text.strip() if row.cells else ""
        if not text:
            continue
        content_rows += 1
        if is_toc_entry_text(text):
            toc_rows += 1
    return content_rows >= 3 and toc_rows >= max(2, int(content_rows * 0.6))


def is_toc_table(table) -> bool:
    """True for table-of-contents tables (Sl.No + Description + section numbers)."""
    if is_toc_list_table(table):
        return True
    if not table.rows:
        return False
    texts = " ".join(
        cell.text.strip().lower()
        for row in table.rows[:4]
        for cell in row.cells
    )
    has_sl = any(x in texts for x in ("sl. no", "sl no", "s. no"))
    has_desc = "description" in texts
    has_section = any(x in texts for x in ("1.0", "objective", "2.0", "methodology"))
    return has_sl and has_desc and has_section


def is_signature_table(header_row: list) -> bool:
    """True for Prepared By / Checked By / Approved By signature tables."""
    header_text = " ".join(header_row).lower()
    return any(kw in header_text for kw in SIGNATURE_TABLE_KEYWORDS)


def is_certificate_number_column(header_label: str) -> bool:
    """True for typed certificate/document number columns (not file uploads)."""
    ll = (header_label or "").strip().lower()
    if not ll:
        return False
    if re.search(r"certificate\s*(?:no\.?|number|#)", ll):
        return True
    if re.search(r"\bcert\.?\s*no\.?", ll):
        return True
    return False


def is_attachment_column(header_label: str) -> bool:
    """
    True when a table column expects a supporting document upload (certificate/proof).
    """
    ll = (header_label or "").strip().lower()
    if not ll:
        return False
    if "attachment no" in ll or "attachment type" in ll:
        return False
    if is_certificate_number_column(header_label):
        return False
    return any(kw in ll for kw in ATTACHMENT_KEYWORDS)


def is_list_of_attachment_table(table) -> bool:
    """True for Sl.No + List of Attachment + Attachment No. tables."""
    if not table.rows:
        return False
    text = " ".join(
        cell.text.strip().lower()
        for row in table.rows[:4]
        for cell in row.cells
    )
    return "list of attachment" in text


def is_attachment_upload_column(table, header_label: str) -> bool:
    """
    True when this column should accept a file upload.
    Includes 'Attachment No.' column on List of Attachment tables.
    """
    ll = (header_label or "").strip().lower()
    if not ll:
        return False
    if is_list_of_attachment_table(table) and ll in ("attachment no.", "attachment no"):
        return True
    return is_attachment_column(header_label)


def is_approval_page_table(table) -> bool:
    """
    True for unified APPROVAL PAGE tables: Role | Name | Signature | Date
    with data rows Recorded/Complied / Checked/Reviewed / Approved By.
    """
    if not table.rows or len(table.rows) < 2:
        return False
    header = [c.text.strip().lower() for c in table.rows[0].cells]
    if len(header) < 4:
        return False
    if header[0] != "role":
        return False
    if "name" not in header[1]:
        return False
    if "signature" not in header[2]:
        return False
    if "date" not in header[3]:
        return False
    for row in table.rows[1:]:
        role = row.cells[0].text.strip().lower()
        if any(
            kw in role
            for kw in (
                "performed by",
                "recorded by",
                "complied by",
                "checked by",
                "reviewed by",
                "approved by",
            )
        ):
            return True
    return False


def resolve_approval_page_role(row_label: str) -> str | None:
    """Map APPROVAL PAGE role row labels to POST workflow data keys."""
    rl = (row_label or "").strip().lower()
    if "performed" in rl or "recorded" in rl or "complied" in rl:
        return "post_prepared_by_name"
    if "checked" in rl or "reviewed" in rl:
        return "post_checked_by_engineering_name"
    if "approved" in rl:
        return "post_approved_by_quality_name"
    return None


def is_approval_page_performer_row(row_label: str) -> bool:
    """True for Performed/Recorded/Complied rows on the Veeva approval-page table."""
    rl = (row_label or "").strip().lower()
    return any(kw in rl for kw in ("performed", "recorded", "complied"))


def is_report_approval_block_table(table) -> bool:
    """
    True for end-of-report signature blocks: row0 title, row1 Name/Signature/Date, row2+ data.
    Example: table 58 Prepared By | table 59 Checked By | table 60 Approved By.
    """
    if not table.rows or len(table.rows) < 2:
        return False
    r0 = " ".join(c.text.strip().lower() for c in table.rows[0].cells)
    r1 = " ".join(c.text.strip().lower() for c in table.rows[1].cells)
    if not any(x in r0 for x in ("prepared by", "checked by", "approved by")):
        return False
    return "name" in r1 and ("signature" in r1 or "date" in r1)


def is_department_signature_table(table, header_row: list) -> bool:
    """True for Prepared/Checked/Approved tables with a Department column (tables 1–3)."""
    if not is_signature_table(header_row):
        return False
    if is_report_approval_block_table(table):
        return False
    h = " ".join(header_row).lower()
    return "department" in h or "name & designation" in h


def is_qualification_subheader_row(row) -> bool:
    """True for Qualification History sub-header rows (Report No. | Approval Date)."""
    texts = [c.text.strip().lower() for c in row.cells]
    has_report_no = any(re.fullmatch(r"report\s*no\.?", t) for t in texts)
    has_approval_date = any(t == "approval date" for t in texts)
    return has_report_no and has_approval_date


def get_qualification_subheader_row_index(table) -> int | None:
    """Row index of Report No. / Approval Date sub-headers, if present."""
    for row_index, row in enumerate(table.rows[:4]):
        if is_qualification_subheader_row(row):
            return row_index
    return None


def get_table_column_headers(table, report_approval_block: bool) -> list:
    """Column labels — row 1 for report-approval blocks, row 0 otherwise."""
    if report_approval_block and len(table.rows) > 1:
        return [cell.text.strip() for cell in table.rows[1].cells]

    subheader_row = get_qualification_subheader_row_index(table)
    if subheader_row is not None and table.rows:
        base = [cell.text.strip() for cell in table.rows[0].cells]
        sub = [cell.text.strip() for cell in table.rows[subheader_row].cells]
        width = max(len(base), len(sub))
        headers = []
        for col_index in range(width):
            sub_label = sub[col_index] if col_index < len(sub) else ""
            if col_index > 0 and (
                is_report_number_label(sub_label) or is_date_field_label(sub_label)
            ):
                headers.append(sub_label)
            else:
                headers.append(base[col_index] if col_index < len(base) else sub_label)
        return headers

    if table.rows:
        return [cell.text.strip() for cell in table.rows[0].cells]
    return []


def resolve_column_widget_label(
    header_label: str,
    cell_text: str,
    row_label: str = "",
) -> str:
    """
    Pick the most specific label for widget inference.
    Prefers inline cell labels (Report No.) over broad merged headers.
    """
    for line in (cell_text or "").splitlines():
        line = line.strip()
        if not line:
            continue
        if is_report_number_label(line) or is_date_field_label(line):
            return line
    if is_report_number_label(header_label) or is_date_field_label(header_label):
        return header_label
    if header_label:
        return header_label
    return row_label


def is_name_designation_column(header_label: str) -> bool:
    """True for Name & Designation / Name/ Designation columns."""
    ll = (header_label or "").lower()
    return "name" in ll and ("designation" in ll or "design" in ll)


def is_signature_date_column(header_label: str) -> bool:
    """True for Signature & Date or Date columns in signature tables."""
    ll = (header_label or "").lower()
    return ll == "date" or "signature" in ll and "date" in ll


SL_NO_COLUMN_HEADERS = (
    "sl. no.",
    "sl no",
    "s.no",
    "s. no.",
    "s.no.",
    "sr. no.",
    "sr no",
    "sr. no",
    "serial no",
    "serial no.",
)


def is_sl_no_column_header(header_label: str) -> bool:
    """
    True when a column header is Sl. No. / Sr. No.
    Only used for column index 0 — avoids matching 'Serial No./Model No.' in other columns.
    """
    ll = re.sub(r"\s+", " ", (header_label or "").strip().lower())
    if not ll:
        return False
    if ll in SL_NO_COLUMN_HEADERS:
        return True
    normalized = ll.rstrip(".")
    return normalized in (
        "sl. no",
        "sl no",
        "s.no",
        "s. no",
        "sr. no",
        "sr no",
        "serial no",
    )


def resolve_sl_no_column_index(col_headers: list, header_row: list) -> int | None:
    """Return 0 when the table's first column is Sl. No.; otherwise None."""
    if col_headers and is_sl_no_column_header(col_headers[0]):
        return 0
    if header_row and is_sl_no_column_header(header_row[0]):
        return 0
    return None


_SL_NO_VALUE_RE = re.compile(r"^\d+[\.\)]?$|^\d+\.\d+$")


def looks_like_sl_no_value(value: str) -> bool:
    """True for 1, 1., 1.0, 2) — not arbitrary user text."""
    return bool(_SL_NO_VALUE_RE.match((value or "").strip()))


def is_verified_by_sign_row(row) -> bool:
    """Footer row: Verified by sign and date."""
    text = " ".join(c.text.strip().lower() for c in row.cells)
    return "verified by" in text


def is_component_section_header_row(row) -> bool:
    """
    Section title row inside composite Main Body / Components tables.
    Merged cells repeat the same label (e.g. Mechanical Components).
    """
    cells = [c.text.strip() for c in row.cells if c.text.strip()]
    if not cells:
        return False
    unique = {c.lower() for c in cells}
    if len(unique) != 1:
        return False
    label = next(iter(unique))
    return any(
        kw in label
        for kw in (
            "main body",
            "mechanical components",
            "electrical components",
            "pneumatic components",
        )
    )


def is_composite_component_table(table) -> bool:
    """Main Body / Mechanical / Electrical blocks with Verified-by footer rows."""
    if not table.rows:
        return False
    text = " ".join(
        cell.text.strip().lower()
        for row in table.rows
        for cell in row.cells
    )
    if "verified by" not in text:
        return False
    return any(
        kw in text
        for kw in (
            "main body",
            "mechanical components",
            "electrical components",
            "pneumatic components",
        )
    )


def resolve_component_section_sl_no_rows(table) -> dict[int, int]:
    """
    Map data-row indices → serial number within each section block.
    Col 0 holds 1., 2., 3. between section headers and Verified-by footers.
    """
    if not is_composite_component_table(table):
        return {}

    serial_rows: dict[int, int] = {}
    in_data_block = False
    section_serial = 0

    for row_index in range(1, len(table.rows)):
        row = table.rows[row_index]

        if is_verified_by_sign_row(row):
            in_data_block = False
            section_serial = 0
            continue

        if is_component_section_header_row(row):
            in_data_block = True
            section_serial = 0
            continue

        if (
            not in_data_block
            and row_index == 1
            and not is_verified_by_sign_row(row)
            and not is_component_section_header_row(row)
        ):
            in_data_block = True
            section_serial = 0

        if in_data_block:
            section_serial += 1
            serial_rows[row_index] = section_serial

    return serial_rows


def resolve_signature_date_col_index(col_headers: list, report_approval_block: bool) -> int:
    """
    Column index for the Signature & Date (or Date-only) stamp cell on a signature row.
    Report-approval mini-tables use col 2; department tables may use col 2 or 3.
    """
    if report_approval_block:
        return 2
    for idx, label in enumerate(col_headers):
        if is_signature_date_column(label):
            return idx
    for idx, label in enumerate(col_headers):
        if (label or "").strip().lower() == "date":
            return idx
    if len(col_headers) > 3:
        return 3
    return 2


def resolve_signature_section_title(header_row: list) -> str:
    """
    Resolve Prepared By / Checked By / Approved By from row-0 cells.
    Row 0 may be a single merged title or a full header row (Department | Name | …).
    """
    if not header_row:
        return ""
    joined = " ".join(h for h in header_row if h).lower()
    for title in ("prepared by", "checked by", "approved by"):
        if title in joined:
            return title
    return (header_row[0] or "").lower()


def resolve_signature_approver_role(table_header: str, row_label: str) -> str | None:
    """
    Map a department-style signature row to a POST approval form field name.
    Matches ReportController@buildPostApprovalSteps keys.
    """
    th = (table_header or "").lower()
    rl = (row_label or "").lower().strip()

    if "prepared by" in th:
        return "post_prepared_by_name"
    if "checked by" in th:
        if "engineering" in rl:
            return "post_checked_by_engineering_name"
        if "production" in rl:
            return "post_checked_by_production_name"
        if "validation" in rl or "user" in rl:
            return "post_checked_by_validation_name"
        # QA row sometimes lives in the Checked By table (not a separate Approved By block).
        if "quality" in rl or rl in ("qa", "q.a.", "q. a."):
            return "post_approved_by_quality_name"
        return None
    if "approved by" in th:
        if "validation" in rl or "user" in rl:
            return "post_checked_by_validation_name"
        if "quality" in rl or "assurance" in rl:
            return "post_approved_by_quality_name"
    if "quality" in rl and "assurance" in rl:
        return "post_approved_by_quality_name"
    return None


def resolve_report_approval_approver_role(table, row_index: int) -> str | None:
    """Map report-approval block data rows (row 2+) to POST approver keys by table title."""
    if not table.rows:
        return None
    title = table.rows[0].cells[0].text.strip().lower()
    if "prepared by" in title:
        return "post_prepared_by_name"
    if "approved by" in title:
        return "post_approved_by_quality_name"
    if "checked by" in title:
        checked_by_row = {
            2: "post_checked_by_engineering_name",
            3: "post_checked_by_production_name",
            4: "post_checked_by_validation_name",
            5: "post_approved_by_quality_name",
        }
        return checked_by_row.get(row_index)
    return None


def is_section_heading_paragraph(text: str) -> bool:
    """Bold/heading paragraphs stay locked; colon-blank labels are not headings."""
    t = text.strip()
    if not t:
        return True
    if is_toc_entry_text(t):
        return True
    if has_paragraph_blank(t):
        return False
    if SECTION_TOC_LINE_RE.match(t):
        return True
    if re.match(r"^\d+\.\d+\t", t):
        return True
    letters = [c for c in t if c.isalpha()]
    if letters:
        upper_ratio = sum(1 for c in letters if c.isupper()) / len(letters)
        if upper_ratio >= 0.85 and len(t) < 120:
            if not re.search(r":\s+\S", t):
                return True
    return False


def infer_field_widget(label: str, field_type: str) -> str:
    """Suggest HTML widget: date, radio, checkbox, textarea, approver, file, or text."""
    if field_type == "approver":
        return "approver"
    if field_type == "attachment":
        return "file"
    if field_type == "table_radio" or field_type == "paragraph_radio":
        return "radio"
    if field_type in ("table_checkbox", "paragraph_checkbox"):
        return "checkbox"
    if is_report_number_label(label):
        return "text"
    if is_date_field_label(label):
        return "date"
    ll = (label or "").lower()
    if any(k in ll for k in ("comment", "remarks", "observation", "description")):
        return "textarea"
    return "text"


def _normalize_header_label(text: str) -> str:
    return re.sub(r"\s+", " ", (text or "").strip().upper())


def _scan_header_tables(doc, fillable_fields: list) -> None:
    """
    Scan the first section header table for FACILITY/SECTION, EQUIPMENT NAME, etc.
    These live in section.header.tables — not in doc.tables body list.
    Only section 0 is scanned; other sections repeat the same header on each page.
    """
    if not doc.sections:
        return

    section = doc.sections[0]
    seen_locations: set = set()

    seen_locations: set = set()

    for table_index, table in enumerate(section.header.tables):
        for label_text, row_index, col_index in HEADER_FIELD_SPECS:
            if row_index >= len(table.rows):
                continue
            row = table.rows[row_index]
            if col_index >= len(row.cells):
                continue
            cell = row.cells[col_index]
            cell_text = cell.text.strip()
            if len(cell_text) > 1:
                continue
            label_cell = row.cells[col_index - 1] if col_index > 0 else cell
            if _normalize_header_label(label_cell.text) != _normalize_header_label(label_text):
                continue
            # Always use header_0_* — fill_docx writes section 0 and Word propagates to pages.
            location = f"header_0_row_{row_index}_col_{col_index}"
            if location in seen_locations:
                continue
            seen_locations.add(location)
            fillable_fields.append({
                "label": label_text.strip(),
                "value": "",
                "locked": False,
                "type": "header_cell",
                "location": location,
                "widget": infer_field_widget(label_text, "header_cell"),
            })

        _scan_cover_table_fields(
            table,
            f"header_{table_index}",
            fillable_fields,
            seen_locations,
        )


# ── parse_docx() ────────────────────────────────────
def parse_docx(file_path: str) -> dict:
    doc = Document(file_path)

    result = {
        "tables": [],
        "paragraphs": [],
        "fillable_fields": []
    }

    skip_toc_labels = ["1.0", "2.0", "3.0", "4.0", "5.0", "6.0", "7.0", "sl. no.",
                       "objective", "methodology", "observation / results and evaluation",
                       "list of attachment", "deviation and corrective action taken (if any)",
                       "summary and conclusion", "report approval"]

    for table_index, table in enumerate(doc.tables):
        table_data = {"table_index": table_index, "rows": []}

        if is_toc_table(table):
            result["tables"].append(table_data)
            continue

        header_row = [cell.text.strip() for cell in table.rows[0].cells] if table.rows else []
        approval_page = is_approval_page_table(table)
        report_approval_block = is_report_approval_block_table(table)
        dept_signature = is_department_signature_table(table, header_row)
        signature_table = dept_signature or report_approval_block
        col_headers = get_table_column_headers(table, report_approval_block)
        sl_no_col = resolve_sl_no_column_index(col_headers, header_row)
        component_serial_rows = (
            resolve_component_section_sl_no_rows(table)
            if not approval_page
            else {}
        )
        qualification_subheader_row = get_qualification_subheader_row_index(table)
        cover_skip_locations: set = set()
        if is_protocol_cover_table(table):
            cover_skip_locations = _scan_cover_table_fields(
                table,
                f"table_{table_index}",
                result["fillable_fields"],
                set(),
            )

        for row_index, row in enumerate(table.rows):
            cells = row.cells
            row_data = []

            for col_index, cell in enumerate(cells):
                cell_text = cell.text.strip()
                is_empty = len(cell_text) == 0
                is_checkbox = is_checkbox_cell(cell_text)

                row_data.append({
                    "col_index": col_index,
                    "value": cell_text,
                    "is_empty": is_empty,
                    "is_locked": not is_empty and not is_checkbox,
                })

            table_data["rows"].append({"row_index": row_index, "cells": row_data})

            if len(cells) == 2:
                if is_two_col_grid_table(table):
                    # Row 0 = column headers (locked). Rows below = per-cell data entry.
                    if row_index > 0:
                        for col_index in range(2):
                            cell_text = cells[col_index].text.strip()
                            header_label = (
                                table.rows[0].cells[col_index].text.strip()
                                if col_index < len(table.rows[0].cells)
                                else ""
                            )
                            result["fillable_fields"].append({
                                "label": header_label,
                                "value": cell_text,
                                "locked": len(cell_text) > 1,
                                "type": "table_2col_grid",
                                "location": (
                                    f"table_{table_index}_row_{row_index}_col_{col_index}"
                                ),
                                "widget": "textarea",
                            })
                else:
                    label = dedupe_colon_label(cells[0].text.strip())
                    value = cells[1].text.strip()
                    value_is_duplicate_label = (
                        value
                        and dedupe_colon_label(value).rstrip(":").lower()
                        == label.rstrip(":").lower()
                    )

                    if label:
                        locked = len(value) > 1 and not value_is_duplicate_label
                        if is_date_field_label(label) and (not value or value_is_duplicate_label):
                            locked = False
                        if label.lower() in skip_toc_labels:
                            locked = True
                        result["fillable_fields"].append({
                            "label": label,
                            "value": "" if value_is_duplicate_label else value,
                            "locked": locked,
                            "type": "table_2col",
                            "location": f"table_{table_index}_row_{row_index}",
                            "widget": infer_field_widget(label, "table_2col"),
                        })

            elif len(cells) == 1:
                cell_text = cells[0].text.strip()
                header_label = table.rows[0].cells[0].text.strip() if table.rows else ""
                comments_table = is_comments_section_table(table)

                if cell_text and is_toc_entry_text(cell_text):
                    continue

                if comments_table:
                    if row_index == 0:
                        continue
                    if not cell_text:
                        label = re.sub(r":\s*$", "", header_label).strip() or "Comments"
                        result["fillable_fields"].append({
                            "label": label,
                            "value": "",
                            "locked": False,
                            "type": "table_1col",
                            "location": f"table_{table_index}_row_{row_index}_col_0",
                            "widget": "textarea",
                        })
                    continue

                if cell_text and has_paragraph_blank(cell_text):
                    if is_comments_header_label(cell_text):
                        continue
                    normalized_cell = dedupe_colon_label(cell_text)
                    label = re.sub(r":\s*$", "", normalized_cell).strip() or normalized_cell
                    result["fillable_fields"].append({
                        "label": label,
                        "value": "",
                        "locked": False,
                        "type": "table_1col",
                        "location": f"table_{table_index}_row_{row_index}_col_0",
                        "widget": infer_field_widget(label, "table_1col"),
                    })
                elif row_index > 0 and not cell_text and is_comments_header_label(header_label):
                    result["fillable_fields"].append({
                        "label": re.sub(r":\s*$", "", header_label).strip() or header_label,
                        "value": "",
                        "locked": False,
                        "type": "table_1col",
                        "location": f"table_{table_index}_row_{row_index}_col_0",
                        "widget": "textarea",
                    })

            elif approval_page and row_index > 0:
                row_label = cells[0].text.strip() if cells else ""
                approver_role = resolve_approval_page_role(row_label)
                if approver_role:
                    if is_approval_page_performer_row(row_label):
                        if len(cells) > 1:
                            date_col = 3 if len(cells) > 3 else 2
                            result["fillable_fields"].append({
                                "label": "Name",
                                "value": "",
                                "locked": False,
                                "type": "approver",
                                "location": (
                                    f"table_{table_index}_row_{row_index}_col_1"
                                ),
                                "widget": "approver",
                                "approver_role": approver_role,
                                "signature_date_location": (
                                    f"table_{table_index}_row_{row_index}_col_{date_col}"
                                ),
                            })
                        performer_stamp_cols = (
                            (2, "approval_stamp_signature", "Signature"),
                            (3, "approval_stamp_date", "Date"),
                        )
                        for col_index, field_type, col_label in performer_stamp_cols:
                            if col_index >= len(cells):
                                continue
                            result["fillable_fields"].append({
                                "label": col_label,
                                "value": "",
                                "locked": True,
                                "type": field_type,
                                "location": (
                                    f"table_{table_index}_row_{row_index}_col_{col_index}"
                                ),
                                "widget": "date" if field_type == "approval_stamp_date" else "text",
                                "approver_role": approver_role,
                            })
                    else:
                        stamp_cols = (
                            (1, "approval_stamp_name", "Name"),
                            (2, "approval_stamp_signature", "Signature"),
                            (3, "approval_stamp_date", "Date"),
                        )
                        for col_index, field_type, col_label in stamp_cols:
                            if col_index >= len(cells):
                                continue
                            result["fillable_fields"].append({
                                "label": col_label,
                                "value": "",
                                "locked": True,
                                "type": field_type,
                                "location": (
                                    f"table_{table_index}_row_{row_index}_col_{col_index}"
                                ),
                                "widget": "date" if field_type == "approval_stamp_date" else "text",
                                "approver_role": approver_role,
                            })

            elif len(cells) > 2 and row_index > 0:
                # Report-approval block: row 0 = title, row 1 = Name/Signature/Date labels.
                if report_approval_block and row_index < 2:
                    continue

                if qualification_subheader_row is not None and row_index == qualification_subheader_row:
                    continue

                row_label = cells[0].text.strip() if cells else ""
                for col_index, cell in enumerate(cells):
                    cell_text = cell.text.strip()
                    if cell_text and re.fullmatch(
                        r"report\s*no\.?|approval\s*date",
                        cell_text.strip(),
                        re.IGNORECASE,
                    ):
                        continue
                    header_label = (
                        col_headers[col_index]
                        if col_index < len(col_headers)
                        else (header_row[col_index] if col_index < len(header_row) else "")
                    )
                    widget_label = resolve_column_widget_label(
                        header_label, cell_text, row_label
                    )
                    combined_label = widget_label or header_label or row_label

                    if not header_label and not report_approval_block:
                        continue

                    field_loc = f"table_{table_index}_row_{row_index}_col_{col_index}"
                    if field_loc in cover_skip_locations:
                        continue

                    # Department signature: col 0 is department name (locked, no field).
                    if dept_signature and col_index == 0:
                        continue

                    # Sl. No. column — always locked; auto-number rows 1, 2, 3… (TOC tables skipped above).
                    if sl_no_col is not None and col_index == sl_no_col:
                        if row_index == 0:
                            continue
                        display_val = cell_text if cell_text else str(row_index)
                        result["fillable_fields"].append({
                            "label": combined_label or "Sl. No.",
                            "value": display_val,
                            "locked": True,
                            "type": "table_sl_no",
                            "location": (
                                f"table_{table_index}_row_{row_index}_col_{col_index}"
                            ),
                            "widget": "text",
                            "auto_serial": row_index,
                        })
                        continue

                    # Main Body / Components tables: col 0 serial (1., 2., 3.) between section headers.
                    if col_index == 0 and row_index in component_serial_rows:
                        serial = component_serial_rows[row_index]
                        if looks_like_sl_no_value(cell_text):
                            display_val = cell_text
                        else:
                            display_val = f"{serial}."
                        result["fillable_fields"].append({
                            "label": combined_label or "Sl. No.",
                            "value": display_val,
                            "locked": True,
                            "type": "table_sl_no",
                            "location": (
                                f"table_{table_index}_row_{row_index}_col_0"
                            ),
                            "widget": "text",
                            "auto_serial": serial,
                        })
                        continue

                    if is_yes_no_radio_cell(cell_text):
                        result["fillable_fields"].append({
                            "label": combined_label,
                            "value": cell_text,
                            "locked": False,
                            "type": "table_radio",
                            "location": f"table_{table_index}_row_{row_index}_col_{col_index}",
                            "widget": "radio",
                        })
                    elif is_checkbox_cell(cell_text):
                        result["fillable_fields"].append({
                            "label": combined_label,
                            "value": cell_text,
                            "locked": False,
                            "type": "table_radio",
                            "location": f"table_{table_index}_row_{row_index}_col_{col_index}",
                            "widget": "radio",
                        })
                    else:
                        field_type = "table_multicol"
                        widget = infer_field_widget(widget_label, field_type)
                        approver_role = None
                        table_header = resolve_signature_section_title(header_row)
                        is_name_col = is_name_designation_column(header_label) or (
                            report_approval_block and col_index == 0
                        )
                        is_date_col = is_signature_date_column(header_label) or (
                            report_approval_block and col_index == 2
                        )

                        # Name & Designation → user dropdown (all signature rows).
                        if signature_table and is_name_col:
                            field_type = "approver"
                            widget = "approver"
                            if report_approval_block:
                                approver_role = resolve_report_approval_approver_role(
                                    table, row_index
                                )
                            else:
                                approver_role = resolve_signature_approver_role(
                                    table_header, row_label
                                )
                        elif signature_table and is_date_col:
                            widget = "date"
                        elif is_attachment_upload_column(table, header_label):
                            field_type = "attachment"
                            widget = "file"

                        locked = len(cell_text) > 1 and not signature_table
                        if signature_table and (is_name_col or is_date_col or col_index == 1):
                            locked = len(cell_text) > 1

                        field_entry = {
                            "label": combined_label,
                            "value": cell_text,
                            "locked": locked,
                            "type": field_type,
                            "location": f"table_{table_index}_row_{row_index}_col_{col_index}",
                            "widget": widget,
                        }
                        if field_type == "approver":
                            if approver_role:
                                field_entry["approver_role"] = approver_role
                            date_col = resolve_signature_date_col_index(
                                col_headers, report_approval_block
                            )
                            field_entry["signature_date_location"] = (
                                f"table_{table_index}_row_{row_index}_col_{date_col}"
                            )
                        result["fillable_fields"].append(field_entry)

        result["tables"].append(table_data)

    _scan_header_tables(doc, result["fillable_fields"])

    textbox_parent_paras = _scan_textbox_fields(doc, result["fillable_fields"])
    para_texts = collect_paragraph_texts(doc)

    for para_index, para in enumerate(doc.paragraphs):
        if para_index in textbox_parent_paras:
            outside_text = para.text.strip() or para_texts.get(para_index, "")
            if outside_text and not has_paragraph_blank(outside_text):
                result["paragraphs"].append({
                    "para_index": para_index,
                    "original_text": outside_text,
                    "template_text": outside_text,
                    "blank_count": 0,
                    "locked": True,
                })
            continue

        text = para_texts.get(para_index, para.text.strip())

        if not text:
            continue

        if is_section_heading_paragraph(text):
            result["paragraphs"].append({
                "para_index": para_index,
                "original_text": text,
                "template_text": text,
                "blank_count": 0,
                "locked": True,
            })
            continue

        normalized_text = dedupe_colon_label(text)
        date_colon_blank = has_paragraph_blank(normalized_text) and is_date_field_label(
            re.sub(r":\s*$", "", normalized_text).strip()
        )

        if is_paragraph_checklist_item(text, para) and not date_colon_blank:
            prechecked = paragraph_text_has_prechecked_symbol(text)
            result["paragraphs"].append({
                "para_index": para_index,
                "original_text": text,
                "template_text": text,
                "blank_count": 0,
                "locked": prechecked,
            })
            result["fillable_fields"].append({
                "label": re.sub(r"^[\u2611☑✓☐□\u25a1\u2610]\s*", "", text).strip(),
                "value": "1" if prechecked else "",
                "locked": prechecked,
                "type": "paragraph_checkbox",
                "location": f"para_{para_index}",
                "widget": "checkbox",
            })
            continue

        if is_paragraph_yes_no_radio(text):
            result["paragraphs"].append({
                "para_index": para_index,
                "original_text": text,
                "template_text": text,
                "blank_count": 0,
                "locked": False,
            })
            result["fillable_fields"].append({
                "label": paragraph_yes_no_label(text),
                "value": "",
                "locked": False,
                "type": "paragraph_radio",
                "location": f"para_{para_index}",
                "widget": "radio",
            })
            continue

        if has_paragraph_blank(text):
            text = normalized_text
            blank_matches = iter_paragraph_blank_matches(text)
            clean_text = PARA_BLANK_RE.sub("{{BLANK}}", text)
            if PARA_COLON_BLANK_RE.search(text):
                clean_text = re.sub(r":\s*$", ": {{BLANK}}", text)

            result["paragraphs"].append({
                "para_index": para_index,
                "original_text": text,
                "template_text": clean_text,
                "blank_count": len(blank_matches) or 1,
                "locked": False,
            })

            if len(blank_matches) > 1:
                for blank_index, _match in enumerate(blank_matches):
                    result["fillable_fields"].append({
                        "label": f"{clean_text[:40]} (blank {blank_index + 1})",
                        "value": "",
                        "locked": False,
                        "type": "paragraph_blank_part",
                        "location": f"para_{para_index}_blank_{blank_index}",
                        "widget": infer_field_widget(clean_text, "paragraph_blank"),
                        "parent_para": para_index,
                        "blank_index": blank_index,
                    })
            else:
                display_label = re.sub(r":\s*$", "", text).strip() or clean_text[:60]
                result["fillable_fields"].append({
                    "label": display_label[:60],
                    "value": "",
                    "locked": False,
                    "type": "paragraph_blank",
                    "location": f"para_{para_index}",
                    "widget": infer_field_widget(display_label, "paragraph_blank"),
                })

        else:
            result["paragraphs"].append({
                "para_index": para_index,
                "original_text": text,
                "template_text": text,
                "blank_count": 0,
                "locked": True,
            })

    return result


if __name__ == "__main__":
    test_file = r"D:\lupin\valiflow-main (3)\valiflow-main\PERIODIC HVAC VALIDATION REPORT .docx"
    if os.path.exists(test_file):
        result = parse_docx(test_file)
        print(f"Fillable fields: {len(result['fillable_fields'])}")
        for field in result["fillable_fields"]:
            if field["location"].startswith("header_") or "Prepared" in field.get("label", ""):
                print(field)
