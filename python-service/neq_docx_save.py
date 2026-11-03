"""Apply NEQ preview text edits back into the original DOCX (structure preserved)."""

from __future__ import annotations

import io
import re
from copy import deepcopy

from docx import Document
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.table import Table, _Cell
from docx.text.paragraph import Paragraph

from report_generator import (
    _run_has_image,
    _normalize_text_value,
    _remove_paragraph_numbering,
    _normalize_header_table_fonts,
)

EDITABLE_TAGS = {"td", "th", "p", "h1", "h2", "h3", "h4", "h5", "h6", "li"}


def _normalize_preview_text(text: str | None) -> str:
    if text is None:
        return ""
    text = str(text).replace("\u00a0", " ")
    text = re.sub(r"\s+", " ", text).strip()
    return text


def _paragraph_from_element(doc, element) -> Paragraph:
    return Paragraph(element, doc)


def _table_from_element(doc, element) -> Table:
    return Table(element, doc)


MC_FALLBACK_TAG = "{http://schemas.openxmlformats.org/markup-compatibility/2006}Fallback"


def _is_inside_mc_fallback(element) -> bool:
    """True when this XML sits in Word's old-format copy of a drawing (skip it)."""
    parent = element.getparent() if element is not None else None
    while parent is not None:
        if parent.tag == MC_FALLBACK_TAG:
            return True
        parent = parent.getparent()
    return False


def _para_is_image_only(para: Paragraph) -> bool:
    """True when a paragraph only holds a drawing/logo and no visible text."""
    runs = list(para.runs)
    if not any(_run_has_image(run) for run in runs):
        return False
    return _normalize_preview_text(para.text) == ""


def _is_section_spacer_para(element) -> bool:
    """Blank line between sections — not a fill-in field (not inside a table or textbox)."""
    parent = element.getparent() if element is not None else None
    while parent is not None:
        tag = parent.tag
        if tag in (qn("w:tbl"), qn("w:txbxContent"), qn("w:tc")):
            return False
        if tag in (qn("w:body"), qn("w:hdr"), qn("w:ftr")):
            return True
        parent = parent.getparent()
    return False


def _iter_cell_targets(cell) -> list[tuple[str, object]]:
    """Collect editable text targets inside one table cell, including nested tables."""
    targets: list[tuple[str, object]] = []
    seen_empty = False
    for para in cell.paragraphs:
        if _para_is_image_only(para):
            continue
        text = _normalize_preview_text(para.text)
        if text == "":
            if seen_empty:
                continue
            seen_empty = True
        targets.append(("para", para))
    for table in cell.tables:
        targets.extend(_iter_table_targets(table))
    if not targets:
        targets.append(("cell", cell))
    return targets


def _iter_table_targets(table: Table) -> list[tuple[str, object]]:
    """Walk unique cells only — python-docx repeats merged cells across the grid."""
    targets: list[tuple[str, object]] = []
    seen_tc_ids: set[int] = set()
    for row in table.rows:
        for cell in row.cells:
            tc_id = id(cell._tc)
            if tc_id in seen_tc_ids:
                continue
            seen_tc_ids.add(tc_id)
            targets.extend(_iter_cell_targets(cell))
    return targets


def _collect_from_container(element, doc, zone, targets, seen_para_ids, seen_tbl_ids):
    """
    Walk Word XML in document order and collect every writable text slot.

    How Save mapping works:
    - Each collected paragraph/cell gets an index (0, 1, 2, ...).
    - The preview stamps that same index on the matching HTML node.
    - Save sends {index, text} and we write the text into this slot.

    We must walk the same things the preview shows as text:
    paragraphs, table cells, content controls (w:sdt), and textboxes
    (w:txbxContent). Comments boxes and floating COMPLIANCE tables live
    in textboxes. If we skip them, the screen looks editable but Save
    writes a nearby body paragraph instead (text appears outside the table).

    Header/footer are collected once even though the preview repeats them
    on every page. The browser maps all page copies to these single indexes.
    """
    if element is None:
        return

    p_tag = qn("w:p")
    tbl_tag = qn("w:tbl")
    sdt_tag = qn("w:sdt")
    sdt_content_tag = qn("w:sdtContent")
    txbx_tag = qn("w:txbxContent")
    sect_pr_tag = qn("w:sectPr")
    drawing_tag = qn("w:drawing")
    pict_tag = qn("w:pict")

    for child in list(element):
        if child.tag == sect_pr_tag or child.tag == MC_FALLBACK_TAG:
            continue

        # Word content control wrapping a table or paragraph.
        if child.tag == sdt_tag:
            content = child.find(sdt_content_tag)
            _collect_from_container(content, doc, zone, targets, seen_para_ids, seen_tbl_ids)
            continue

        if child.tag == tbl_tag:
            tbl_id = id(child)
            if tbl_id in seen_tbl_ids:
                continue
            seen_tbl_ids.add(tbl_id)
            table = _table_from_element(doc, child)
            for kind, obj in _iter_table_targets(table):
                targets.append((kind, obj, zone))
            continue

        if child.tag == p_tag:
            # Textboxes hang off a drawing inside this paragraph. Walk them
            # first so Comments / floating tables get their own indexes.
            for txbx in child.findall(".//%s" % txbx_tag):
                if _is_inside_mc_fallback(txbx):
                    continue
                _collect_from_container(txbx, doc, zone, targets, seen_para_ids, seen_tbl_ids)

            para_id = id(child)
            if para_id in seen_para_ids:
                continue

            para = _paragraph_from_element(doc, child)
            text = _normalize_preview_text(para.text)
            has_txbx = child.find(".//%s" % txbx_tag) is not None
            has_drawing = (
                child.find(".//%s" % drawing_tag) is not None
                or child.find(".//%s" % pict_tag) is not None
            )

            # Empty host paragraph for a logo / textbox is not a fill-in field.
            # Giving it an index makes the next empty form cell steal the wrong slot.
            if _para_is_image_only(para):
                continue
            if text == "" and (has_txbx or has_drawing):
                continue
            # Empty lines between tables are not fields. If we index them, the next
            # empty form cell (Document Title value, Equipment ID, etc.) steals the
            # wrong slot and Save writes outside the table.
            if text == "" and _is_section_spacer_para(child):
                continue

            seen_para_ids.add(para_id)
            targets.append(("para", para, zone))
            continue

        # Nested wrappers (customXml, etc.). Do not walk into p/tbl again —
        # those were handled above.
        _collect_from_container(child, doc, zone, targets, seen_para_ids, seen_tbl_ids)


def collect_docx_text_targets(doc: Document) -> list[tuple[str, object, str]]:
    """Collect writable slots in preview order: header → body → footer."""
    targets: list[tuple[str, object, str]] = []

    if doc.sections:
        header = doc.sections[0].header
        if header is not None:
            _collect_from_container(header._element, doc, "header", targets, set(), set())

    _collect_from_container(doc.element.body, doc, "body", targets, set(), set())

    if doc.sections:
        footer = doc.sections[0].footer
        if footer is not None:
            _collect_from_container(footer._element, doc, "footer", targets, set(), set())

    return targets


def _collect_tables_from_container(element, doc, tables, seen_tbl_ids):
    """Find Word tables in the same tree order as the preview (including nested / textbox)."""
    if element is None:
        return

    p_tag = qn("w:p")
    tbl_tag = qn("w:tbl")
    sdt_tag = qn("w:sdt")
    sdt_content_tag = qn("w:sdtContent")
    txbx_tag = qn("w:txbxContent")
    sect_pr_tag = qn("w:sectPr")

    for child in list(element):
        if child.tag == sect_pr_tag or child.tag == MC_FALLBACK_TAG:
            continue
        if child.tag == sdt_tag:
            _collect_tables_from_container(child.find(sdt_content_tag), doc, tables, seen_tbl_ids)
            continue
        if child.tag == tbl_tag:
            tbl_id = id(child)
            if tbl_id not in seen_tbl_ids:
                seen_tbl_ids.add(tbl_id)
                tables.append(_table_from_element(doc, child))
            # Do not index nested w:tbl children. docx-preview often wraps cell HTML in
            # nested <table>s that are excluded on the client — matching top-level-only
            # keeps Annex-13 / comparison fills on the correct Word table.
            continue
        if child.tag == p_tag:
            for txbx in child.findall(".//%s" % txbx_tag):
                if _is_inside_mc_fallback(txbx):
                    continue
                _collect_tables_from_container(txbx, doc, tables, seen_tbl_ids)
            continue
        _collect_tables_from_container(child, doc, tables, seen_tbl_ids)


def collect_docx_tables(doc: Document) -> list[Table]:
    tables: list[Table] = []
    seen: set[int] = set()
    if doc.sections:
        header = doc.sections[0].header
        if header is not None:
            _collect_tables_from_container(header._element, doc, tables, seen)
    _collect_tables_from_container(doc.element.body, doc, tables, seen)
    if doc.sections:
        footer = doc.sections[0].footer
        if footer is not None:
            _collect_tables_from_container(footer._element, doc, tables, seen)
    return tables


def _collect_all_tables_recursive(element, doc, tables, seen_tbl_ids):
    """Like top-level collect, but also indexes nested w:tbl (for label lookup)."""
    if element is None:
        return

    p_tag = qn("w:p")
    tbl_tag = qn("w:tbl")
    sdt_tag = qn("w:sdt")
    sdt_content_tag = qn("w:sdtContent")
    txbx_tag = qn("w:txbxContent")
    sect_pr_tag = qn("w:sectPr")

    for child in list(element):
        if child.tag == sect_pr_tag or child.tag == MC_FALLBACK_TAG:
            continue
        if child.tag == sdt_tag:
            _collect_all_tables_recursive(child.find(sdt_content_tag), doc, tables, seen_tbl_ids)
            continue
        if child.tag == tbl_tag:
            tbl_id = id(child)
            if tbl_id not in seen_tbl_ids:
                seen_tbl_ids.add(tbl_id)
                tables.append(_table_from_element(doc, child))
            # Recurse into nested tables for label-based identity writes.
            _collect_all_tables_recursive(child, doc, tables, seen_tbl_ids)
            continue
        if child.tag == p_tag:
            for txbx in child.findall(".//%s" % txbx_tag):
                if _is_inside_mc_fallback(txbx):
                    continue
                _collect_all_tables_recursive(txbx, doc, tables, seen_tbl_ids)
            continue
        _collect_all_tables_recursive(child, doc, tables, seen_tbl_ids)


def collect_docx_tables_including_nested(doc: Document) -> list[Table]:
    tables: list[Table] = []
    seen: set[int] = set()
    if doc.sections:
        header = doc.sections[0].header
        if header is not None:
            _collect_all_tables_recursive(header._element, doc, tables, seen)
    _collect_all_tables_recursive(doc.element.body, doc, tables, seen)
    if doc.sections:
        footer = doc.sections[0].footer
        if footer is not None:
            _collect_all_tables_recursive(footer._element, doc, tables, seen)
    return tables


_NEQ_IDENTITY_CANON_LABELS = (
    "Equipment/ system name",
    "Equipment/ system ID No.",
    "Equipment Name",
    "Equipment ID",
    "Reference Document No. (Version No.)",
    "Facility / Section",
    "Document Title",
    "Document No.",
    "Effective Date",
    "VERSION",
)


def _canonicalize_identity_label(label: str) -> str:
    t = _normalize_preview_text(label)
    if not t:
        return ""
    lower = t.lower()
    if "equipment" in lower and "system name" in lower:
        return "Equipment/ system name"
    if "equipment" in lower and ("system id" in lower or "id no" in lower):
        return "Equipment/ system ID No."
    # Attachment-6 letterhead labels
    if re.match(r"^equipment\s*name\b", lower):
        return "Equipment Name"
    if re.match(r"^equipment\s*id\.?\b", lower):
        return "Equipment ID"
    if "reference document" in lower or re.match(r"^ref\.?\s*document", lower):
        return "Reference Document No. (Version No.)"
    if "facilit" in lower and "sect" in lower:
        return "Facility / Section"
    if re.match(r"^document\s*title\b", lower):
        return "Document Title"
    if re.match(r"^document\s*no\b", lower):
        return "Document No."
    if re.match(r"^effective\s*date\b", lower):
        return "Effective Date"
    if re.match(r"^version\b", lower):
        return "VERSION"
    return t


def _text_extends_past_identity_label(text: str, canon: str) -> bool:
    """True when the cell has the label words and then more typed words."""

    def flat(value: str) -> str:
        value = _normalize_preview_text(value).lower()
        value = re.sub(r"[().]", " ", value)
        value = re.sub(r"\s*/\s*", " ", value)
        value = re.sub(r"\bno\.?\b", " ", value)
        return re.sub(r"\s+", " ", value).strip()

    raw = flat(text)
    label = flat(canon)
    if not label or raw == label:
        return False
    if raw.startswith(label + " "):
        return True
    # "vijay verma equipment system id" mentions the label, it is not the label.
    return label in raw and not raw.startswith(label)


def _is_neq_identity_label_text(text: str | None) -> bool:
    """True when a cell is itself an Attachment-11 identity label (not a typed value)."""
    t = _normalize_preview_text(text)
    if not t or len(t) > 72:
        return False
    # Values like "Facility / Section vijay" are not pure labels.
    if re.search(r":\s*\S", t):
        return False
    canon = _canonicalize_identity_label(t)
    if canon in _NEQ_IDENTITY_CANON_LABELS and _labels_match_identity(t, canon):
        # "equipment system name test" contains the label and then the typed words.
        if _text_extends_past_identity_label(t, canon):
            return False
        # Reject long values that merely contain a label phrase.
        if len(t) > len(canon) + 8:
            return False
        return True
    if _is_identity_field_label(t):
        return True
    return bool(_KNOWN_SHORT_LABEL.match(t))


def _is_header_parenthetical_fragment(text: str | None) -> bool:
    """'(If any)' is part of the Deviation heading, not a typed deviation."""
    t = _normalize_preview_text(text).lower().rstrip(".:").strip()
    return t in {"(if any)", "if any"}


_COLUMN_TITLE_RE = re.compile(
    r"parts?\s*name|acceptance|actual\s*moc|certificate\s*no|sign\s*and\s*date|"
    r"deviation|corrective|acceptable|comments?|s\.?\s*no|sr\.?\s*no|"
    r"performed|verified|topic|annex|instrument|sop\b|signature|"
    r"revision|^\s*check\b|method|observation|"
    r"risk\s*anal|risk\s*eval|remarks?|\brpn\b|mitigation|"
    r"high\s*#|medium\s*/\s*low",
    re.I,
)


def _headers_look_like_filled_data(headers: list) -> bool:
    """A page-split continuation used to send the first data row as column titles.

    Those titles never match the Word table, so certificate and sign cells
    on later parts rows were skipped. Reject that fingerprint.
    """
    if _headers_are_component_subsystem_sentinel(headers):
        return False
    if not headers or len(headers) < 2:
        return False
    titles = 0
    for raw in headers:
        text = _normalize_preview_text(str(raw or ""))
        if text and len(text) <= 42 and _COLUMN_TITLE_RE.search(text):
            titles += 1
    return titles == 0


def _is_identity_placeholder_value(text: str | None, label: str | None = None) -> bool:
    """Empty or label-echo placeholders that are safe to overwrite."""
    t = _normalize_preview_text(text)
    if not t:
        return True
    # Continuation fragments from Word wrap.
    if re.match(
        r"^(?:no\.?\s*\(?\s*version|system\s+name|system\s+id|/?\s*system)\b",
        t,
        re.I,
    ):
        return True
    # Template often repeats the label text inside the value cell.
    if label and _labels_match_identity(t, label):
        return True
    return False


def _labels_match_identity(cell_text: str, label: str) -> bool:
    a = _canonicalize_identity_label(cell_text).lower()
    b = _canonicalize_identity_label(label).lower()
    if not a or not b:
        return False
    if a == b:
        return True
    # Allow prefix match only when both canonicalize to known identity labels.
    if a in {x.lower() for x in _NEQ_IDENTITY_CANON_LABELS} and b in {
        x.lower() for x in _NEQ_IDENTITY_CANON_LABELS
    }:
        return False
    # Allow partial match when preview wrapped/split the label (same field only).
    if a.startswith(b) or b.startswith(a):
        # Avoid "document no" matching unrelated long strings.
        if min(len(a), len(b)) < 8:
            return False
        return True
    # Equipment/system name variants
    if "equipment" in a and "equipment" in b and "system name" in a and "system name" in b:
        return True
    if "equipment" in a and "equipment" in b and ("system id" in a or "id no" in a) and (
        "system id" in b or "id no" in b
    ):
        return True
    if ("equipment name" in a or a == "equipment name") and (
        "equipment name" in b or b == "equipment name"
    ):
        return True
    if ("equipment id" in a or a.startswith("equipment id")) and (
        "equipment id" in b or b.startswith("equipment id")
    ):
        return True
    if "reference document" in a and "reference document" in b:
        return True
    if "facilit" in a and "facilit" in b and "sect" in a and "sect" in b:
        return True
    if a == "version" and b == "version":
        return True
    return False


def _table_text_blob(table: Table) -> str:
    parts: list[str] = []
    for row in table.rows:
        for cell in _unique_row_cells(row):
            t = _normalize_preview_text(cell.text)
            if t:
                parts.append(t)
    return " | ".join(parts).lower()


def _table_is_identity_form_table(table: Table) -> bool:
    """Attachment-11 / Attachment-6 style identity letterhead (+ optional SOP grid)."""
    blob = _table_text_blob(table)
    if not blob:
        return False
    has_ref = (
        "reference document" in blob
        or "ref. document" in blob
        or "ref document" in blob
        or ("document no" in blob and ("version" in blob or "reference" in blob or "ref." in blob))
    )
    has_equip = "equipment" in blob and (
        "system name" in blob
        or "system id" in blob
        or "equipment name" in blob
        or "equipment id" in blob
    )
    has_fac = "facilit" in blob and "sect" in blob
    has_doc = "document title" in blob or "document no" in blob or "effective date" in blob
    has_version = bool(re.search(r"\bversion\b", blob))
    has_sop = (
        "list of sop" in blob
        or "title of sop" in blob
        or "verification of draft sop" in blob
        or ("sop" in blob and "effective" in blob and "date" in blob)
    )
    identity_hits = sum([has_ref, has_equip, has_fac, has_doc])
    return (
        identity_hits >= 2
        or (identity_hits >= 1 and has_sop)
        or (has_version and (has_doc or has_ref or identity_hits >= 1))
        or (has_version and not has_sop and identity_hits == 0 and len(blob) < 80)
    )


def _strip_label_prefix_from_value(label: str, text: str) -> str:
    """Drop echoed label text when the user typed into a label+value combined cell."""
    label_norm = _canonicalize_identity_label(label)
    t = _normalize_preview_text(text)
    if not t or not label_norm:
        return text
    lower_label = label_norm.lower()
    lower_t = t.lower()
    if lower_t == lower_label:
        return ""
    if lower_t.startswith(lower_label):
        rest = t[len(label_norm) :].strip().lstrip("/:-–—").strip()
        if rest and not _labels_match_identity(rest, label_norm):
            return rest
    return t


def _table_zone(doc: Document, table: Table) -> str:
    el = table._tbl
    parent = el.getparent() if el is not None else None
    while parent is not None:
        tag = parent.tag
        if tag == qn("w:hdr"):
            return "header"
        if tag == qn("w:ftr"):
            return "footer"
        if tag == qn("w:body"):
            return "body"
        parent = parent.getparent()
    return "body"


def _resolve_value_cell_from_label_index(cells: list, idx: int, label_norm: str):
    """Given a matched label cell index, return the value cell (skip wrap fragments / next labels)."""
    if idx + 1 >= len(cells):
        return None
    nxt = cells[idx + 1]
    nxt_text = _normalize_preview_text(nxt.text)
    # Split label: "Equipment/" + "system name" in next cell → value is idx+2.
    if re.match(
        r"^(?:no\.?\s*\(?\s*version|system\s+name|system\s+id|/?\s*system)\b",
        nxt_text,
        re.I,
    ):
        if idx + 2 < len(cells):
            nxt = cells[idx + 2]
            nxt_text = _normalize_preview_text(nxt.text)
        else:
            return None
    # Next cell is a different identity label (e.g. Equipment value empty, Facility label adjacent).
    if _is_neq_identity_label_text(nxt_text) and not _labels_match_identity(nxt_text, label_norm):
        if idx + 2 < len(cells):
            nxt = cells[idx + 2]
            nxt_text = _normalize_preview_text(nxt.text)
            if _is_neq_identity_label_text(nxt_text) and not _labels_match_identity(
                nxt_text, label_norm
            ):
                return None
        else:
            return None
    return nxt


def _find_value_cell_after_label(
    doc: Document,
    label: str,
    *,
    preferred_zone: str | None = None,
    original_text: str | None = None,
):
    """Locate the value cell after an identity label; score candidates to avoid wrong fields."""
    if not label:
        return None
    label_norm = _canonicalize_identity_label(label)
    if not label_norm:
        return None
    original_norm = (
        _normalize_preview_text(original_text) if original_text is not None else None
    )
    preferred = (preferred_zone or "").strip().lower() or None

    candidates: list[tuple[int, object]] = []
    all_tables = collect_docx_tables_including_nested(doc)
    identity_tables = [t for t in all_tables if _table_is_identity_form_table(t)]
    label_l = label_norm.lower()
    label_hit_tables = [
        t
        for t in all_tables
        if label_l in _table_text_blob(t)
        or any(tok in _table_text_blob(t) for tok in label_l.split() if len(tok) > 3)
    ]
    # Prefer identity tables, but always include any table that mentions this label (VERSION, etc.).
    search_tables: list[Table] = []
    seen_tbl: set[int] = set()
    for t in identity_tables + label_hit_tables + (all_tables if not identity_tables else []):
        tid = id(t._tbl) if hasattr(t, "_tbl") else id(t)
        if tid in seen_tbl:
            continue
        seen_tbl.add(tid)
        search_tables.append(t)
    for table in search_tables:
        zone = _table_zone(doc, table)
        is_identity_form = _table_is_identity_form_table(table)
        rows = list(table.rows)
        for row_i, row in enumerate(rows):
            cells = _unique_row_cells(row)
            # Vertical stack: single-cell label row, value in next row.
            if len(cells) == 1 and row_i + 1 < len(rows):
                cell_text = _normalize_preview_text(cells[0].text)
                if _labels_match_identity(cell_text, label_norm):
                    next_cells = _unique_row_cells(rows[row_i + 1])
                    if len(next_cells) == 1:
                        target = next_cells[0]
                        old_norm = _normalize_preview_text(target.text)
                        if not (
                            _is_neq_identity_label_text(old_norm)
                            and not _labels_match_identity(old_norm, label_norm)
                        ):
                            score = 40
                            if is_identity_form:
                                score += 45
                            if preferred and zone == preferred:
                                score += 6
                            if zone == "body":
                                score += 4
                            if label_norm == "VERSION":
                                score += 15
                            if original_norm is not None:
                                if old_norm == original_norm:
                                    score += 50
                                elif _is_identity_placeholder_value(old_norm, label_norm):
                                    score += 25
                            elif _is_identity_placeholder_value(old_norm, label_norm):
                                score += 20
                            candidates.append((score, target))
            # Multi-column label row (Document No. | VERSION) with values on next row.
            # Do NOT pair Equipment/system name | Equipment/system ID No. onto the
            # Facility row — that wiped ID labels / wrote values into the wrong cells.
            if len(cells) >= 2 and row_i + 1 < len(rows):
                label_like = [
                    c
                    for c in cells
                    if _is_neq_identity_label_text(_normalize_preview_text(c.text))
                ]
                if len(label_like) == len(cells):
                    next_cells = _unique_row_cells(rows[row_i + 1])
                    if len(next_cells) == len(cells):
                        next_label_like = sum(
                            1
                            for c in next_cells
                            if _is_neq_identity_label_text(_normalize_preview_text(c.text))
                        )
                        if next_label_like == 0:
                            for idx, cell in enumerate(cells):
                                cell_text = _normalize_preview_text(cell.text)
                                if not _labels_match_identity(cell_text, label_norm):
                                    continue
                                target = next_cells[idx]
                                old_norm = _normalize_preview_text(target.text)
                                if _is_neq_identity_label_text(old_norm):
                                    continue
                                score = 55
                                if is_identity_form:
                                    score += 45
                                if preferred and zone == preferred:
                                    score += 6
                                if zone == "body":
                                    score += 4
                                if label_norm == "VERSION":
                                    score += 20
                                if original_norm is not None:
                                    if old_norm == original_norm:
                                        score += 50
                                    elif _is_identity_placeholder_value(old_norm, label_norm):
                                        score += 25
                                elif _is_identity_placeholder_value(old_norm, label_norm):
                                    score += 20
                                candidates.append((score, target))
            for idx, cell in enumerate(cells):
                if idx + 1 >= len(cells):
                    continue
                cell_text = _normalize_preview_text(cell.text)
                matched = _labels_match_identity(cell_text, label_norm)
                target = None
                # Split label across two cells.
                if not matched and idx + 2 < len(cells):
                    merged = _normalize_preview_text(cell_text + " " + cells[idx + 1].text)
                    if _labels_match_identity(merged, label_norm):
                        target = cells[idx + 2]
                        # If that lands on another field label, abort this hit.
                        tgt = _normalize_preview_text(target.text)
                        if _is_neq_identity_label_text(tgt) and not _labels_match_identity(
                            tgt, label_norm
                        ):
                            target = None
                if matched:
                    target = _resolve_value_cell_from_label_index(cells, idx, label_norm)
                if target is None:
                    continue

                old_norm = _normalize_preview_text(target.text)
                # Never treat another field's label cell as our value target.
                if _is_neq_identity_label_text(old_norm) and not _labels_match_identity(
                    old_norm, label_norm
                ):
                    continue

                score = 10
                if is_identity_form:
                    score += 45
                # Zone is only a soft hint — docx-preview often marks body annex
                # tables as "header", which previously steered writes into the wrong table.
                if preferred and zone == preferred:
                    score += 6
                if zone == "body":
                    score += 4
                # Value must sit to the right of the matched label in the same row.
                try:
                    val_idx = cells.index(target)
                except ValueError:
                    val_idx = -1
                if val_idx >= 0 and val_idx <= idx:
                    score -= 80
                elif val_idx == idx + 1:
                    score += 20
                elif val_idx == idx + 2:
                    score += 10
                if original_norm is not None:
                    if old_norm == original_norm:
                        score += 50
                    elif _is_identity_placeholder_value(old_norm, label_norm) and (
                        original_norm == ""
                        or _is_identity_placeholder_value(original_norm, label_norm)
                    ):
                        score += 25
                    elif original_norm and old_norm and old_norm != original_norm:
                        # Cell content disagrees with what the user edited — weak candidate.
                        score -= 30
                elif _is_identity_placeholder_value(old_norm, label_norm):
                    score += 20
                # Prefer targets that sit in a row that also contains this label text.
                row_blob = " | ".join(_normalize_preview_text(c.text) for c in cells).lower()
                if label_norm.lower() in row_blob or any(
                    tok in row_blob for tok in label_norm.lower().split()[:2] if len(tok) > 3
                ):
                    score += 12
                # VERSION often shares a row with Document No. — boost, don't penalize.
                if label_norm == "VERSION":
                    score += 18
                    if re.search(r"\bdocument\s*no\b", row_blob):
                        score += 10
                # Only penalize when the *target* is Document No/Title's own value cell
                # (not merely because Document No. appears earlier in the same row).
                if label_norm not in (
                    "Document No.",
                    "Document Title",
                    "Reference Document No. (Version No.)",
                    "VERSION",
                ):
                    if val_idx > 0:
                        for look in range(max(0, val_idx - 2), val_idx):
                            left = _normalize_preview_text(cells[look].text)
                            left_canon = _canonicalize_identity_label(left)
                            if left_canon in ("Document No.", "Document Title") and not _labels_match_identity(
                                left, label_norm
                            ):
                                # Immediate left label → this target is that field's value.
                                if look == val_idx - 1:
                                    score -= 60
                                elif look == val_idx - 2 and _is_neq_identity_label_text(
                                    _normalize_preview_text(cells[val_idx - 1].text)
                                ):
                                    score -= 40
                candidates.append((score, target))

    if not candidates:
        return None
    candidates.sort(key=lambda item: item[0], reverse=True)
    best_score, best = candidates[0]
    if best_score < 5:
        return None
    return best


def _unique_row_cells(row):
    try:
        cells = []
        seen: set[int] = set()
        for cell in row.cells:
            tc_id = id(cell._tc)
            if tc_id in seen:
                continue
            seen.add(tc_id)
            cells.append(cell)
        return cells
    except ValueError:
        # Vertically merged header rows can break python-docx's grid walk.
        cells = []
        seen_tc: set[int] = set()
        for tc in row._tr.findall(qn("w:tc")):
            tc_id = id(tc)
            if tc_id in seen_tc:
                continue
            seen_tc.add(tc_id)
            cells.append(_Cell(tc, row._parent))
        return cells


def _tc_grid_span(tc) -> int:
    tc_pr = tc.tcPr
    if tc_pr is None or tc_pr.gridSpan is None:
        return 1
    try:
        return max(1, int(tc_pr.gridSpan.val))
    except (TypeError, ValueError):
        return 1


def _clear_tc_element_text(tc) -> None:
    """Clear visible text inside a w:tc element (keep structure)."""
    p_tag = qn("w:p")
    for p in tc.findall(p_tag):
        for child in list(p):
            if child.tag == qn("w:pPr"):
                continue
            p.remove(child)


def _tbl_grid_widths(tbl_el) -> list[int]:
    grid = tbl_el.find(qn("w:tblGrid"))
    if grid is None:
        return []
    widths: list[int] = []
    for gc in grid.findall(qn("w:gridCol")):
        try:
            widths.append(int(gc.get(qn("w:w")) or 0))
        except (TypeError, ValueError):
            widths.append(0)
    return widths


def _set_tc_width_dxa(tc, width: int) -> None:
    if width <= 0:
        return
    tc_pr = tc.get_or_add_tcPr()
    tc_w = tc_pr.find(qn("w:tcW"))
    if tc_w is None:
        tc_w = OxmlElement("w:tcW")
        tc_pr.append(tc_w)
    tc_w.set(qn("w:w"), str(int(width)))
    tc_w.set(qn("w:type"), "dxa")


def _split_horizontal_merges_in_row(row) -> bool:
    """
    Split horizontally merged cells (w:gridSpan) into one tc per grid column.

    Attachment-4 empty data rows are often one merged tc spanning Checks…Sign.
    Preview shows 5 editable columns, but python-docx unique cells = 1, so cols 1–4
    were skipped on Save (only Checks persisted).

    The merged cell's width is usually 100% of the table. Copying that width onto
    every new cell makes Word draw each column as the full page and the grid collapses.
    Each new cell gets its own grid-column width instead.
    """
    tr = row._tr
    tbl = tr.getparent()
    while tbl is not None and not tbl.tag.endswith("}tbl"):
        tbl = tbl.getparent()
    grid_widths = _tbl_grid_widths(tbl) if tbl is not None else []
    did = False
    col_cursor = 0
    for tc in list(tr.tc_lst):
        span = _tc_grid_span(tc)
        if span <= 1:
            col_cursor += 1
            continue
        did = True
        tc_pr = tc.get_or_add_tcPr()
        if tc_pr.gridSpan is not None:
            tc_pr.remove(tc_pr.gridSpan)
        pieces = [tc]
        anchor = tc
        for _ in range(span - 1):
            clone = deepcopy(tc)
            _clear_tc_element_text(clone)
            anchor.addnext(clone)
            anchor = clone
            pieces.append(clone)
        for offset, piece in enumerate(pieces):
            idx = col_cursor + offset
            width = grid_widths[idx] if idx < len(grid_widths) else 0
            _set_tc_width_dxa(piece, width)
        col_cursor += span
    return did


def _repair_exploded_full_width_cells(doc: Document) -> int:
    """
    A split banner cell leaves several tc elements each marked 100% wide (w:tcW 5000 pct).
    Put those cells back on the table grid so the check columns match the original file.
    """
    repaired = 0
    for table in collect_docx_tables(doc):
        widths = _tbl_grid_widths(table._tbl)
        if len(widths) < 2:
            continue
        for row in table.rows:
            tcs = list(row._tr.tc_lst)
            if len(tcs) < 2:
                continue
            full_width = 0
            for tc in tcs:
                if _tc_grid_span(tc) > 1:
                    full_width = 0
                    break
                tc_pr = tc.find(qn("w:tcPr"))
                tc_w_el = tc_pr.find(qn("w:tcW")) if tc_pr is not None else None
                if tc_w_el is None or (tc_w_el.get(qn("w:type")) or "") != "pct":
                    full_width = 0
                    break
                try:
                    val = int(tc_w_el.get(qn("w:w")) or 0)
                except (TypeError, ValueError):
                    val = 0
                if val >= 4000:
                    full_width += 1
            if full_width < 2 or full_width != len(tcs):
                continue
            if len(tcs) > len(widths):
                continue
            for idx, tc in enumerate(tcs):
                _set_tc_width_dxa(tc, widths[idx])
            repaired += 1
    return repaired


def _cell_at_column(row, col_index: int):
    """Return the cell at a visual/grid column index (handles merges after split)."""
    if col_index < 0:
        return None
    cells = list(row.cells)
    if col_index >= len(cells):
        return None
    return cells[col_index]


def _set_tc_grid_span(tc, span: int) -> None:
    """Set or clear w:gridSpan on a table cell element."""
    tc_pr = tc.get_or_add_tcPr()
    if span <= 1:
        if tc_pr.gridSpan is not None:
            tc_pr.remove(tc_pr.gridSpan)
        return
    if tc_pr.gridSpan is None:
        el = OxmlElement("w:gridSpan")
        tc_pr.append(el)
    tc_pr.gridSpan.val = span


def _collapse_row_to_single_merged_cell(row):
    """
    Collapse a multi-cell row into one full-width merged cell.

    Attachment-3 "Verified by sign and date" / section banners are a single
    merged row in the original DOCX. Column writes via _prepare_row_for_column_writes
    used to split gridSpan and left vertical borders — restore the simple row.
    """
    tr = row._tr
    tcs = list(tr.tc_lst)
    if not tcs:
        return None
    if len(tcs) == 1:
        return row.cells[0]

    first_tc = tcs[0]
    span = len(tcs)
    _set_tc_grid_span(first_tc, span)
    for tc in tcs[1:]:
        tr.remove(tc)
    _widen_full_row_banner_cell(first_tc)
    return row.cells[0]


def _widen_full_row_banner_cell(tc) -> None:
    """A full-row banner uses the whole table, not the serial column's width.

    Collapsing a data row left tcW at 410 pct (one column) with gridSpan 5.
    Word then drew "Verified by sign and date" as a few characters per line.
    The original banner cell is 5000 pct, which is 100% of the table.
    """
    if _tc_grid_span(tc) <= 1:
        return
    tc_pr = tc.get_or_add_tcPr()
    tc_w = tc_pr.find(qn("w:tcW"))
    if tc_w is None:
        tc_w = OxmlElement("w:tcW")
        tc_pr.append(tc_w)
    kind = (tc_w.get(qn("w:type")) or "").lower()
    try:
        raw = int(tc_w.get(qn("w:w")) or 0)
    except (TypeError, ValueError):
        raw = 0
    if kind == "pct" and raw < 4000:
        tc_w.set(qn("w:type"), "pct")
        tc_w.set(qn("w:w"), "5000")
    for paragraph in tc.findall(qn("w:p")):
        p_pr = paragraph.find(qn("w:pPr"))
        if p_pr is None:
            continue
        jc = p_pr.find(qn("w:jc"))
        if jc is not None and (jc.get(qn("w:val")) or "") == "center":
            p_pr.remove(jc)


def _row_starts_with_verified(row) -> bool:
    cells = _unique_row_cells(row)
    if not cells:
        return False
    text = _normalize_preview_text(cells[0].text)
    return bool(re.match(r"^verified\s*by\b", text, re.I))


def _row_for_verified_stamp(table, row_index: int, new_text: str) -> int:
    """Keep a verified-by stamp on the verified-by row.

    Adding a component row shifts later indexes. The stored index then points
    at the empty line above "Verified by sign and date", and the name is
    written into that narrow serial cell.
    """
    if not re.match(r"^verified\s*by\b", _normalize_preview_text(new_text), re.I):
        return row_index
    rows = list(table.rows)
    if 0 <= row_index < len(rows) and _row_starts_with_verified(rows[row_index]):
        return row_index
    best = None
    best_dist = None
    for index, row in enumerate(rows):
        if not _row_starts_with_verified(row):
            continue
        dist = abs(index - row_index)
        if best is None or dist < best_dist:
            best = index
            best_dist = dist
    return row_index if best is None else best


def _repair_component_matrix_banner_merges(doc: Document) -> int:
    """Re-merge verified-by / section-banner rows that were split into columns."""
    repaired = 0
    for table in collect_docx_tables(doc):
        if not _table_is_component_subsystem_matrix(table):
            continue
        for row in table.rows:
            if not _row_is_matrix_section_banner(row):
                continue
            if len(list(row._tr.tc_lst)) <= 1:
                continue
            if _collapse_row_to_single_merged_cell(row) is not None:
                repaired += 1
    return repaired


def _is_safety_by_row_text(text: str | None) -> bool:
    t = _normalize_preview_text(text)
    if not t or len(t) > 180:
        return False
    return bool(
        re.match(r"^safety\s*(?:checks?\s*)?s?\s*by\b", t, re.I)
        and re.search(r"sign", t, re.I)
    )


def _row_is_safety_by_signoff(row) -> bool:
    cells = _unique_row_cells(row)
    if not cells:
        return False
    return _is_safety_by_row_text(cells[0].text)


def _repair_safety_by_row_merges(doc: Document) -> int:
    """
    Collapse Attachment-10 "Safety By (Sign./Date)" rows to one full-width cell.
    Also fold a stamp that landed in a later column back into one Verified-by-style line.
    """
    repaired = 0
    for table in collect_docx_tables(doc):
        for row in table.rows:
            cells = _unique_row_cells(row)
            if len(cells) < 2:
                continue
            first = _normalize_preview_text(cells[0].text)
            if not re.match(r"^safety\s*(?:checks?\s*)?s?\s*by\b", first, re.I):
                continue
            stamp_bits: list[str] = []
            for cell in cells[1:]:
                t = _normalize_preview_text(cell.text)
                if t and t not in {"—", "-"}:
                    stamp_bits.append(t)
            line = first
            if stamp_bits and not re.search(r"\bapproved\b|\brejected\b", first, re.I):
                joined = stamp_bits[0] if len(stamp_bits) == 1 else " / ".join(stamp_bits)
                base = re.sub(r"\s*:\s*$", "", first).strip()
                line = f"{base} : {joined}"
            elif not stamp_bits and len(list(row._tr.tc_lst)) <= 1:
                continue
            cell = _collapse_row_to_single_merged_cell(row)
            if cell is None:
                continue
            if _normalize_preview_text(cell.text) != _normalize_preview_text(line):
                _set_cell_text_preserve(cell, line)
            repaired += 1
    return repaired


def _row_is_spanned_section_title(row) -> bool:
    """Serial plus one merged title, e.g. '1.' | 'Checks for major components'.

    The blank row under it is where Test / Acceptance / Result are typed.
    Splitting this merge turned the title into three value cells.
    """
    cells = _unique_row_cells(row)
    if not cells:
        return False
    spans = [_tc_grid_span(cell._tc) for cell in cells]
    if len(cells) == 1 and spans[0] >= 3:
        return bool(_normalize_preview_text(cells[0].text))
    if len(cells) != 2 or spans[0] != 1 or spans[1] < 2:
        return False
    serial = _normalize_preview_text(cells[0].text)
    title = _normalize_preview_text(cells[1].text)
    if not title or len(title) > 180:
        return False
    if serial and not re.fullmatch(r"\d+(?:\.\d+)?\.?", serial):
        return False
    return True


def _prepare_row_for_column_writes(row, col_index: int):
    """Ensure row can accept a write at col_index; split horizontal merges when needed."""
    if col_index < 0:
        return None
    if _row_is_spanned_section_title(row):
        cells = _unique_row_cells(row)
        if len(cells) == 1 or col_index <= 0:
            return cells[0]
        return cells[-1]
    unique = _unique_row_cells(row)
    grid_len = len(list(row.cells))
    # One merged tc spanning the grid → preview has N columns but Word has 1 unique cell.
    # Never split verified-by / section-banner / Safety By rows — those stay one simple cell.
    if len(unique) < grid_len and not (
        _row_is_matrix_section_banner(row) or _row_is_safety_by_signoff(row)
    ):
        _split_horizontal_merges_in_row(row)
        unique = _unique_row_cells(row)
        grid_len = len(list(row.cells))
    if col_index < len(unique):
        return unique[col_index]
    if col_index < grid_len:
        return _cell_at_column(row, col_index)
    return None


def _clear_row_text(row) -> None:
    for cell in _unique_row_cells(row):
        for para in cell.paragraphs:
            if _para_is_image_only(para):
                continue
            _remove_paragraph_numbering(para)
            _set_paragraph_text(para, "")


def _pick_insert_anchor_tr(table: Table, after_index: int):
    """Pick a clone template row that is not a header/section banner (keeps merges intact)."""
    tr_list = list(table._tbl.tr_lst)
    if not tr_list:
        return None, -1
    if after_index < 0 or after_index >= len(tr_list):
        after_index = len(tr_list) - 1

    def row_ok(abs_i: int) -> bool:
        if abs_i < 0 or abs_i >= len(table.rows):
            return False
        row = table.rows[abs_i]
        if _row_is_matrix_section_banner(row):
            return False
        cells = _unique_row_cells(row)
        probe_parts = [_normalize_preview_text(c.text).lower() for c in cells]
        probe = " | ".join(p for p in probe_parts if p)
        nonempty = [p for p in probe_parts if p]
        # Column header row — bad clone template.
        if len(nonempty) >= 3 and re.search(
            r"\b(check|method|specification|observation|sign\s*and\s*date|deviation|"
            r"corrective|acceptable|revision\s*no|comments?|role|name|signature)\b",
            probe,
        ):
            headerish = sum(
                1
                for p in nonempty
                if re.fullmatch(r"revision(?:\s*no\.?)?", p, flags=re.I)
                or re.match(
                    r"^(?:check|method|specification|observation|sign|deviation|acceptable|"
                    r"corrective|change|date|role|name|signature|comments?)\b",
                    p,
                    re.I,
                )
            )
            if headerish >= 2:
                return False
        return True

    if row_ok(after_index):
        return tr_list[after_index], after_index
    for i in range(after_index, -1, -1):
        if row_ok(i):
            return tr_list[i], after_index
    for i in range(after_index + 1, len(table.rows)):
        if row_ok(i):
            return tr_list[i], after_index
    for i in range(len(table.rows)):
        if row_ok(i):
            return tr_list[i], after_index
    # No safe data row — still insert after the requested index using that row's structure.
    return tr_list[after_index], after_index


def _resolve_table_for_op(doc: Document, tables: list, op: dict) -> Table | None:
    """Prefer header fingerprint when table indexes disagree between preview and Word."""
    headers = op.get("headers") if isinstance(op.get("headers"), list) else None
    table_index = op.get("table")
    table: Table | None = None
    try:
        ti = int(table_index)
        if 0 <= ti < len(tables):
            table = tables[ti]
    except (TypeError, ValueError):
        table = None
    if _headers_are_component_subsystem_sentinel(headers):
        # Prefer the preview table index when that Word table is a component section
        # (Main Body / Mechanical / Electrical may be separate tables).
        if table is not None and _table_is_component_subsystem_matrix(table):
            return table
        found = _find_component_subsystem_table(doc)
        if found is not None:
            return found
        return table
    if headers:
        found, _hdr = _find_table_and_header_row(doc, headers)
        if found is not None:
            return found
    return table


def _apply_table_ops(doc: Document, table_ops: list[dict]) -> tuple[int, int]:
    """Insert/delete Word table rows after text edits so existing indexes stay valid."""
    if not table_ops:
        return 0, 0

    tables = collect_docx_tables(doc)
    inserted = 0
    deleted = 0

    deletes = [op for op in table_ops if (op or {}).get("op") == "delete"]
    deletes.sort(key=lambda op: (int(op.get("table", -1)), -int(op.get("row", -1))))
    for op in deletes:
        table = _resolve_table_for_op(doc, tables, op)
        if table is None:
            continue
        # Page heading is the letterhead only. Body rows must not be deleted from it.
        if _table_zone(doc, table) == "header" or _table_is_identity_form_table(table):
            continue
        try:
            row_index = int(op.get("row"))
        except (TypeError, ValueError):
            continue
        if row_index < 0 or row_index >= len(table.rows) or len(table.rows) <= 1:
            continue
        row = table.rows[row_index]
        if _row_is_matrix_section_banner(row):
            continue
        row._tr.getparent().remove(row._tr)
        deleted += 1

    if deleted:
        tables = collect_docx_tables(doc)

    inserts = [op for op in table_ops if (op or {}).get("op") == "insert"]
    # Highest after_row first so earlier indexes stay valid when client sent original positions.
    inserts.sort(
        key=lambda op: (
            int(op.get("table", -1)),
            -(int(op.get("after_row", -1)) if op.get("after_row") not in (None, "") else -1),
        )
    )
    for op in inserts:
        tables = collect_docx_tables(doc)
        table = _resolve_table_for_op(doc, tables, op)
        if table is None or not table.rows:
            continue
        # Never grow the repeating page heading. A data row stays in its body table.
        if _table_zone(doc, table) == "header":
            continue
        after_row = op.get("after_row")
        try:
            after_index = int(after_row) if after_row is not None and after_row != "" else -1
        except (TypeError, ValueError):
            after_index = -1
        tr_list = list(table._tbl.tr_lst)
        safety_at = None
        for i, row in enumerate(table.rows):
            if _row_is_safety_by_signoff(row):
                safety_at = i
                break
        if after_index < 0 or after_index >= len(tr_list):
            # A row added on the next preview page has no row above it in that
            # fragment. Keep it above Safety By, not after the whole table.
            after_index = (safety_at - 1) if safety_at else (len(tr_list) - 1)
        elif safety_at is not None and after_index >= safety_at:
            after_index = safety_at - 1

        template_tr, insert_after = _pick_insert_anchor_tr(table, after_index)
        if template_tr is None:
            continue
        # Never clone a section banner (Temperature Sensor / Pressure Gauge) — find another row.
        template_row_idx = None
        for i, tr in enumerate(tr_list):
            if tr is template_tr or id(tr) == id(template_tr):
                template_row_idx = i
                break
        if template_row_idx is not None and _row_is_matrix_section_banner(table.rows[template_row_idx]):
            alt = None
            for i in range(len(table.rows)):
                if not _row_is_matrix_section_banner(table.rows[i]):
                    cells = _unique_row_cells(table.rows[i])
                    if len(cells) >= 2:
                        alt = tr_list[i]
                        break
            if alt is not None:
                template_tr = alt
            else:
                continue

        anchor = tr_list[insert_after] if 0 <= insert_after < len(tr_list) else tr_list[-1]
        new_tr = deepcopy(template_tr)
        anchor.addnext(new_tr)
        tr_list = list(table._tbl.tr_lst)
        try:
            insert_at = tr_list.index(new_tr)
        except ValueError:
            insert_at = min(insert_after + 1, len(tr_list) - 1)
        new_row = table.rows[insert_at]
        _clear_row_text(new_row)
        # Empty instrument rows are often one merged cell — split so all values land.
        if len(list(new_row.cells)) > len(_unique_row_cells(new_row)):
            _split_horizontal_merges_in_row(new_row)
        values = op.get("values") if isinstance(op.get("values"), list) else []
        for i, text_raw in enumerate(values):
            cell = _prepare_row_for_column_writes(new_row, i)
            if cell is None:
                continue
            text = _normalize_text_value(text_raw if text_raw is not None else "")
            if text != "":
                _set_cell_text_preserve(cell, text)
        inserted += 1

    return inserted, deleted


def serialize_text_targets(docx_bytes: bytes) -> list[dict]:
    """List each Word slot as {index, text, zone} so the preview can stamp data-neq-index."""
    doc = Document(io.BytesIO(docx_bytes))
    targets = collect_docx_text_targets(doc)
    return [
        {
            "index": idx,
            "text": _get_target_text(kind, obj),
            "zone": zone,
            # Empty lines inside a table must not be reused as body paragraph slots.
            "in_table": bool(
                (kind == "cell")
                or (kind == "para" and _para_is_inside_table(obj))
            ),
        }
        for idx, (kind, obj, zone) in enumerate(targets)
    ]


def _get_target_text(kind: str, obj) -> str:
    if kind == "cell":
        return _normalize_preview_text(obj.text)
    return _normalize_preview_text(obj.text)


def _first_text_run_rpr(para: Paragraph):
    """Clone character formatting (font/size/bold/etc.) from the first non-image text run."""
    for run in para.runs:
        if _run_has_image(run):
            continue
        r_pr = run._element.find(qn("w:rPr"))
        if r_pr is not None:
            return deepcopy(r_pr)
        return None
    # Annexure IDs / links often live in w:hyperlink wrappers — para.runs misses them.
    for hyperlink in para._element.findall(qn("w:hyperlink")):
        for r_el in hyperlink.findall(qn("w:r")):
            r_pr = r_el.find(qn("w:rPr"))
            if r_pr is not None:
                return deepcopy(r_pr)
    return None


def _run_element_has_drawing(r_el) -> bool:
    if r_el is None:
        return False
    return (
        r_el.find(qn("w:drawing")) is not None
        or r_el.find(qn("w:pict")) is not None
        or r_el.find(qn("w:object")) is not None
    )


def _clear_paragraph_text_keep_images(para: Paragraph) -> None:
    """
    Remove all visible text from a paragraph, including runs inside w:hyperlink.

    python-docx ``para.runs`` only lists direct ``w:r`` children — Annexure No. values
    are often hyperlinks, so clearing only ``para.runs`` left the old ID in place
    (new rows without links saved fine; existing Annexure cells did not).
    """
    el = para._element
    for hyperlink in list(el.findall(qn("w:hyperlink"))):
        el.remove(hyperlink)
    for child in list(el):
        if child.tag != qn("w:r"):
            continue
        if _run_element_has_drawing(child):
            continue
        el.remove(child)


def _style_alignment(para: Paragraph) -> str:
    """Alignment stored on the paragraph style, such as Body Text 2 = both."""
    style = getattr(para, "style", None)
    element = getattr(style, "element", None) if style is not None else None
    if element is None:
        return ""
    p_pr = element.find(qn("w:pPr"))
    if p_pr is None:
        return ""
    jc = p_pr.find(qn("w:jc"))
    if jc is None:
        return ""
    return (jc.get(qn("w:val")) or "").strip()


def _stop_stretching_spaces(para: Paragraph) -> None:
    """Word 'both' alignment pads every space out to the right edge. Use left.

    Body Text 2 is justified on the style, with no w:jc on the paragraph.
    That still stretches one typed space between words across the cell.
    A direct left alignment overrides the style. Center and right stay as they are.
    """
    p_pr = para._element.get_or_add_pPr()
    jc = p_pr.find(qn("w:jc"))
    direct = (jc.get(qn("w:val")) or "").strip() if jc is not None else ""
    if direct and direct != "both":
        return
    if direct != "both" and _style_alignment(para) != "both":
        return
    if jc is None:
        jc = OxmlElement("w:jc")
        r_pr = p_pr.find(qn("w:rPr"))
        if r_pr is not None:
            r_pr.addprevious(jc)
        else:
            p_pr.append(jc)
    jc.set(qn("w:val"), "left")


def _set_paragraph_text(para: Paragraph, text: str) -> None:
    """
    Replace paragraph text while preserving existing character and paragraph formatting.

    Keeps the first text run's rPr (font, size, bold, color, etc.) and does not
    reset alignment/indent — unlike the report fill helpers that intentionally reset
    layout for template blank filling.

    Newlines in ``text`` become Word soft breaks (w:br) so download keeps next lines.
    Also clears ``w:hyperlink`` wrappers (Annexure No. cells) so edits replace the ID.
    """
    style_rpr = _first_text_run_rpr(para)
    _clear_paragraph_text_keep_images(para)

    if text is None:
        text = ""
    text = str(text).replace("\r\n", "\n").replace("\r", "\n")
    text = _keep_pasted_tick(text)
    if not text:
        return

    # Justified text stretches one typed space across the whole line.
    # Left alignment keeps each space the width the user typed.
    _stop_stretching_spaces(para)

    lines = text.split("\n")
    for i, line in enumerate(lines):
        new_run = para.add_run(line)
        if style_rpr is not None:
            existing = new_run._element.find(qn("w:rPr"))
            if existing is not None:
                new_run._element.remove(existing)
            new_run._element.insert(0, deepcopy(style_rpr))
        text_el = new_run._element.find(qn("w:t"))
        if text_el is not None and (
            "  " in line or line[:1] == " " or line[-1:] == " "
        ):
            text_el.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
        if i < len(lines) - 1:
            br = new_run._element.makeelement(qn("w:br"), {})
            new_run._element.append(br)


def _strip_matrix_header_glue(
    text: str, headers: list | None = None, col_index: int | None = None
) -> str:
    """Remove column-header phrases / trailing row stamps glued onto typed values."""
    value = _normalize_preview_text(text)
    if not value:
        return ""
    phrases: list[str] = []
    col_header = ""
    if headers and col_index is not None and 0 <= col_index < len(headers):
        col_header = _normalize_preview_text(str(headers[col_index] or ""))
    is_comments_col = bool(re.match(r"^comments?\b", col_header, re.I))
    if headers and not is_comments_col:
        for h in headers:
            t = _normalize_preview_text(str(h or ""))
            if t:
                phrases.append(t)
    phrases.extend(
        [
            "Deviation (If Any)",
            "Deviation (if any)",
            "Acceptable/Not Acceptable",
            "Corrective Actions",
        ]
    )
    for phrase in phrases:
        if not phrase or not re.search(r"\s", phrase):
            # One-word headers ("test", "result") are real typed values.
            continue
        esc = re.escape(phrase).replace(r"\ ", r"\s*")
        value = re.sub(esc, " ", value, flags=re.I)
    value = re.sub(r"\bdeviation\s*\([^)]*\)", " ", value, flags=re.I)
    value = re.sub(r"\bacceptable\s*/?\s*not\s*acceptable\b", " ", value, flags=re.I)
    value = re.sub(r"\bcorrective\s*actions?\b", " ", value, flags=re.I)
    value = re.sub(r"\s+", " ", value).strip()
    if is_comments_col:
        value = re.sub(r"^comments\s*:\s*", "", value, flags=re.I).strip()
    return value.strip()


def _enable_cell_text_wrap(cell) -> None:
    """
    Keep the original column width and borders.

    Long values (letters, numbers, spaces, or one unbroken string) must wrap
    onto the next line inside the same cell. Only the row height may grow.
    Do not change w:tblGrid or w:tcW.
    """
    tc = cell._tc
    tc_pr = tc.get_or_add_tcPr()
    # Word "do not wrap" draws the value past the right border.
    for tag in ("w:noWrap", "w:tcFitText"):
        node = tc_pr.find(qn(tag))
        if node is not None:
            tc_pr.remove(node)

    tr = tc.getparent()
    while tr is not None and not str(tr.tag).endswith("}tr"):
        tr = tr.getparent()
    if tr is None:
        return
    tr_pr = tr.find(qn("w:trPr"))
    if tr_pr is None:
        return
    tr_height = tr_pr.find(qn("w:trHeight"))
    if tr_height is None:
        return
    # Missing hRule is drawn as a fixed row in Word, so filled text paints
    # outside the border on download. atLeast keeps that height as a minimum
    # and lets extra wrapped lines grow the row only.
    rule = tr_height.get(qn("w:hRule")) or ""
    if rule != "atLeast":
        tr_height.set(qn("w:hRule"), "atLeast")


def _story_roots(doc: Document) -> list:
    """Body plus every header and footer part. Download must wrap all of them."""
    roots = [doc.element.body]
    for rel in doc.part.rels.values():
        reltype = str(getattr(rel, "reltype", "") or "")
        if not (reltype.endswith("/header") or reltype.endswith("/footer")):
            continue
        try:
            roots.append(rel.target_part.element)
        except (AttributeError, ValueError, KeyError):
            continue
    return roots


def _table_width_dxa(tbl) -> int:
    """Original table width in twips. Do not change the grid."""
    tbl_pr = tbl.find(qn("w:tblPr"))
    if tbl_pr is not None:
        tbl_w = tbl_pr.find(qn("w:tblW"))
        if tbl_w is not None and (tbl_w.get(qn("w:type")) or "") == "dxa":
            try:
                width = int(tbl_w.get(qn("w:w")) or 0)
            except (TypeError, ValueError):
                width = 0
            if width > 0:
                return width
    total = 0
    grid = tbl.find(qn("w:tblGrid"))
    if grid is not None:
        for col in grid.findall(qn("w:gridCol")):
            try:
                total += int(col.get(qn("w:w")) or 0)
            except (TypeError, ValueError):
                continue
    return total


def _grid_col_widths(tbl) -> list[int]:
    grid = tbl.find(qn("w:tblGrid"))
    widths: list[int] = []
    if grid is None:
        return widths
    for col in grid.findall(qn("w:gridCol")):
        try:
            widths.append(int(col.get(qn("w:w")) or 0))
        except (TypeError, ValueError):
            widths.append(0)
    return widths


def _tc_grid_origin(tc) -> int:
    """Grid column where this cell starts, counting each cell's gridSpan."""
    tr = tc.getparent()
    if tr is None:
        return 0
    origin = 0
    for sibling in tr.findall(qn("w:tc")):
        if sibling is tc:
            return origin
        tc_pr = sibling.find(qn("w:tcPr"))
        span = 1
        if tc_pr is not None:
            grid_span = tc_pr.find(qn("w:gridSpan"))
            if grid_span is not None:
                try:
                    span = max(1, int(grid_span.get(qn("w:val")) or 1))
                except (TypeError, ValueError):
                    span = 1
        origin += span
    return origin


def _tc_grid_span(tc) -> int:
    tc_pr = tc.find(qn("w:tcPr"))
    if tc_pr is None:
        return 1
    grid_span = tc_pr.find(qn("w:gridSpan"))
    if grid_span is None:
        return 1
    try:
        return max(1, int(grid_span.get(qn("w:val")) or 1))
    except (TypeError, ValueError):
        return 1


def _effective_cell_width_dxa(tc, tbl) -> int:
    """
    Column width the border already uses.
    pct cells (Deviation, Comments, letterhead) are a share of the table.
    dxa cells are already in twips. The value is not written back.

    A merged banner keeps the first column's tcW (the narrow serial column)
    plus gridSpan. Measuring only tcW made "Verified by sign and date" wrap
    every four characters. Sum the spanned grid columns instead.
    """
    span = _tc_grid_span(tc)
    if span > 1:
        cols = _grid_col_widths(tbl)
        origin = _tc_grid_origin(tc)
        if cols and origin < len(cols):
            return sum(cols[origin : origin + span])
    tc_pr = tc.find(qn("w:tcPr"))
    if tc_pr is None:
        return 0
    tc_w = tc_pr.find(qn("w:tcW"))
    if tc_w is None:
        return 0
    try:
        raw = int(tc_w.get(qn("w:w")) or 0)
    except (TypeError, ValueError):
        return 0
    if raw <= 0:
        return 0
    kind = (tc_w.get(qn("w:type")) or "dxa").lower()
    if kind == "dxa":
        return raw
    if kind == "pct":
        table_width = _table_width_dxa(tbl)
        if table_width <= 0:
            return 0
        # Word pct is fiftieths of a percent. 5000 = 100% of the table.
        return max(0, int(raw / 5000 * table_width))
    return 0


def _segments_for_cell(text: str, max_chars: int) -> list[str] | None:
    """
    Split only a token that is wider than the column.
    Spaces stay as the user typed them. Each piece is one Word line.
    """
    if not text or max_chars < 4:
        return None
    if not re.search(r"\S{" + str(max_chars + 1) + r",}", text):
        return None
    segments: list[str] = []
    buf: list[str] = []

    def flush() -> None:
        if buf:
            segments.append("".join(buf))
            buf.clear()

    for part in re.split(r"(\s+)", text):
        if part == "":
            continue
        if part.isspace() or len(part) <= max_chars:
            buf.append(part)
            continue
        # An e-sign date must stay whole. A narrow Sign and Date column
        # was breaking "21-10-2026" into "21-10-202" and "6".
        if re.fullmatch(r"\d{1,2}[-/]\d{1,2}[-/]\d{2,4}", part) or re.fullmatch(
            r"\d{1,2}:\d{2}(?:\s*[AP]M)?", part, flags=re.I
        ):
            buf.append(part)
            continue
        flush()
        for start in range(0, len(part), max_chars):
            segments.append(part[start : start + max_chars])
    flush()
    if len(segments) <= 1:
        return None
    return segments


def _rewrite_run_with_line_breaks(run_el, segments: list[str]) -> None:
    """Put a Word line break between pieces. Keep the run's font."""
    for child in list(run_el):
        if child.tag in (qn("w:t"), qn("w:br")):
            run_el.remove(child)
    for index, segment in enumerate(segments):
        if index:
            run_el.append(OxmlElement("w:br"))
        text_el = OxmlElement("w:t")
        if segment[:1].isspace() or segment[-1:].isspace():
            text_el.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
        text_el.text = segment
        run_el.append(text_el)


def _join_split_esign_dates(doc: Document) -> int:
    """Put a date back together when a narrow column split the year.

    "21-10-202" + line break + "6" becomes "21-10-2026".
    """
    changed = 0
    for run_el in doc.element.iter(qn("w:r")):
        guard = 0
        while guard < 12:
            guard += 1
            children = list(run_el)
            joined = False
            for index in range(len(children) - 2):
                left_el, break_el, right_el = children[index], children[index + 1], children[index + 2]
                if left_el.tag != qn("w:t") or break_el.tag != qn("w:br") or right_el.tag != qn("w:t"):
                    continue
                left = left_el.text or ""
                right = right_el.text or ""
                head = re.search(r"(\d{1,2}[-/]\d{1,2}[-/])(\d{1,3})$", left)
                tail = re.match(r"^(\d+)", right)
                if not head or not tail:
                    continue
                need = 4 - len(head.group(2))
                if need <= 0 or len(tail.group(1)) < need:
                    continue
                left_el.text = left + tail.group(1)[:need]
                rest = right[need:]
                run_el.remove(break_el)
                if rest:
                    right_el.text = rest
                else:
                    run_el.remove(right_el)
                changed += 1
                joined = True
                break
            if not joined:
                break
    return changed


def _align_cell_text_to_top(tc) -> bool:
    """
    Some cells are w:vAlign=center. When a neighbour wraps onto more lines the
    row grows, and Word drops the shorter cell about one line down. Top keeps
    the first line of every cell on the same line. Horizontal jc is unchanged.
    """
    tc_pr = tc.find(qn("w:tcPr"))
    if tc_pr is None:
        tc_pr = OxmlElement("w:tcPr")
        tc.insert(0, tc_pr)
    valign = tc_pr.find(qn("w:vAlign"))
    if valign is None:
        valign = OxmlElement("w:vAlign")
        tc_pr.append(valign)
    if (valign.get(qn("w:val")) or "") == "top":
        return False
    valign.set(qn("w:val"), "top")
    return True


def _lock_table_layout(tbl) -> bool:
    """Fixed layout keeps the original grid. Autofit was widening columns to fit text."""
    tbl_pr = tbl.find(qn("w:tblPr"))
    if tbl_pr is None:
        tbl_pr = OxmlElement("w:tblPr")
        tbl.insert(0, tbl_pr)
    layout = tbl_pr.find(qn("w:tblLayout"))
    if layout is None:
        layout = OxmlElement("w:tblLayout")
        tbl_pr.append(layout)
    if (layout.get(qn("w:type")) or "") == "fixed":
        return False
    layout.set(qn("w:type"), "fixed")
    return True


def _force_run_font(r_pr, font_name: str = "Arial") -> bool:
    """
    One face for the downloaded file.

    The template mixes three sources, so Word's font box changes by cell:
    Book Antiqua comes from the Body Text 2 style, Calibri (Body) is the
    theme font, and some runs say Calibri directly. An explicit Arial on the
    run replaces all three. Checkbox symbol fonts are left alone.
    """
    if r_pr is None:
        return False
    fonts = r_pr.find(qn("w:rFonts"))
    if fonts is not None:
        for attr in ("ascii", "hAnsi", "cs", "eastAsia"):
            if _is_symbol_checkbox_font(fonts.get(qn(f"w:{attr}")) or ""):
                return False
    if fonts is None:
        fonts = OxmlElement("w:rFonts")
        r_pr.insert(0, fonts)
    changed = False
    for attr in ("ascii", "hAnsi", "cs", "eastAsia"):
        key = qn(f"w:{attr}")
        if fonts.get(key) != font_name:
            fonts.set(key, font_name)
            changed = True
    # Theme names are what Word labels "Calibri (Body)". Drop them.
    for attr in ("asciiTheme", "hAnsiTheme", "cstheme", "eastAsiaTheme"):
        key = qn(f"w:{attr}")
        if key in fonts.attrib:
            del fonts.attrib[key]
            changed = True
    return changed


def _run_uses_symbol_font(r_pr) -> bool:
    if r_pr is None:
        return False
    fonts = r_pr.find(qn("w:rFonts"))
    if fonts is None:
        return False
    for attr in ("ascii", "hAnsi", "cs", "eastAsia"):
        if _is_symbol_checkbox_font(fonts.get(qn(f"w:{attr}")) or ""):
            return True
    return False


def _force_run_size(r_pr, half_points: str = "22") -> bool:
    """
    Filled text is Arial 11.

    Table Grid leaves a run with no size, and Word then shows Arial 10.
    12pt headings and 14pt titles already carry a larger size and stay.
    """
    if r_pr is None:
        return False
    changed = False
    for tag in ("w:sz", "w:szCs"):
        node = r_pr.find(qn(tag))
        if node is None:
            node = OxmlElement(tag)
            r_pr.append(node)
            node.set(qn("w:val"), half_points)
            changed = True
            continue
        try:
            current = int(node.get(qn("w:val")) or 0)
        except (TypeError, ValueError):
            current = 0
        if 0 < current <= 20:
            node.set(qn("w:val"), half_points)
            changed = True
    return changed


def _unify_download_font(root, font_name: str = "Arial") -> int:
    """Apply Arial 11 to every text run and paragraph mark in this story part."""
    changed = 0
    for run_el in root.iter(qn("w:r")):
        if _run_element_has_drawing(run_el) and run_el.find(qn("w:t")) is None:
            continue
        r_pr = run_el.find(qn("w:rPr"))
        if r_pr is None:
            r_pr = OxmlElement("w:rPr")
            run_el.insert(0, r_pr)
        if _run_uses_symbol_font(r_pr):
            continue
        if _force_run_font(r_pr, font_name):
            changed += 1
        if _force_run_size(r_pr):
            changed += 1
    for para_el in root.iter(qn("w:p")):
        p_pr = para_el.find(qn("w:pPr"))
        if p_pr is None:
            continue
        r_pr = p_pr.find(qn("w:rPr"))
        if r_pr is None:
            r_pr = OxmlElement("w:rPr")
            p_pr.append(r_pr)
        if _run_uses_symbol_font(r_pr):
            continue
        if _force_run_font(r_pr, font_name):
            changed += 1
        # Paragraph mark only. Do not pull a 12pt heading down to 11pt.
        runs = [
            el.find(qn("w:rPr"))
            for el in para_el.findall(qn("w:r"))
            if el.find(qn("w:t")) is not None and (el.find(qn("w:t")).text or "").strip()
        ]
        larger = False
        for run_pr in runs:
            if run_pr is None:
                continue
            sz = run_pr.find(qn("w:sz"))
            try:
                if sz is not None and int(sz.get(qn("w:val")) or 0) > 22:
                    larger = True
            except (TypeError, ValueError):
                continue
        if not larger and _force_run_size(r_pr):
            changed += 1
    return changed


def _keep_filled_text_inside_cells(
    doc: Document,
    *,
    unify_font: bool = True,
    break_long_tokens: bool = True,
) -> int:
    """
    Final-approval download opens in Word, which does not use the preview CSS.

    Do not change tblGrid or tcW, so the original borders stay.
    A long word or a string of digits is moved to the next line inside that cell.
    The row only grows taller.
    """
    changed = 0
    body = doc.element.body
    for root in _story_roots(doc):
        # Letterhead lives in the header. Leave its vertical centering alone.
        align_top = root is body
        for tbl in root.iter(qn("w:tbl")):
            if _lock_table_layout(tbl):
                changed += 1
            for tr in tbl.findall(qn("w:tr")):
                tr_pr = tr.find(qn("w:trPr"))
                tr_height = tr_pr.find(qn("w:trHeight")) if tr_pr is not None else None
                if tr_height is not None and (tr_height.get(qn("w:hRule")) or "") != "atLeast":
                    tr_height.set(qn("w:hRule"), "atLeast")
                    changed += 1
                for tc in tr.findall(qn("w:tc")):
                    # Download: same first-line start in every body cell (S, D, RPN, Remarks).
                    if align_top and _align_cell_text_to_top(tc):
                        changed += 1
                    tc_pr = tc.find(qn("w:tcPr"))
                    if tc_pr is not None:
                        for tag in ("w:noWrap", "w:tcFitText"):
                            node = tc_pr.find(qn(tag))
                            if node is not None:
                                tc_pr.remove(node)
                                changed += 1
                    # A verified-by / safety-by line is one sentence across the row.
                    # Do not insert line breaks into it.
                    banner_text = _normalize_preview_text("".join(tc.itertext()))
                    if re.match(
                        r"^(?:verified\s*by|safety\s*(?:checks?\s*)?s?\s*by)\b",
                        banner_text,
                        re.I,
                    ):
                        continue
                    # 11pt letters such as d/w/m are about 7pt wide. Break before they cross the border.
                    # This covers every attachment in the testin folder, including narrow Sr. No.
                    # columns and percentage-width cells (Deviation, Comments, SOP, Training).
                    # Report download turns this off so original words stay whole.
                    if break_long_tokens:
                        width = _effective_cell_width_dxa(tc, tbl)
                        if width > 280:
                            max_chars = max(3, int((width - 140) / 144))
                        else:
                            max_chars = 3
                        for paragraph in tc.findall(qn("w:p")):
                            for text_el in list(paragraph.iter(qn("w:t"))):
                                run_el = text_el.getparent()
                                if run_el is None or run_el.tag != qn("w:r"):
                                    continue
                                segments = _segments_for_cell(text_el.text or "", max_chars)
                                if not segments:
                                    continue
                                _rewrite_run_with_line_breaks(run_el, segments)
                                changed += 1
        # NEQ download uses one font. Report download keeps the template sizes.
        if unify_font:
            changed += _unify_download_font(root)
    # The letterhead is in the header. A page-pinned body table is one
    # object: when it no longer fits under the letterhead, Word moves the
    # whole table to the next page and leaves page 1 empty. Flow it instead,
    # and start the body under the letterhead, the same as the preview.
    changed += _flow_page_anchored_tables(doc)
    changed += _keep_body_below_letterhead(doc)
    changed += _repeat_letterhead_on_every_section(doc)
    changed += _unbold_filled_data_rows(doc)
    _normalize_header_table_fonts(doc, font_name="Arial", font_size_pt=12.0)
    return changed


def _section_metric(doc: Document, tag: str, attr: str, default: int) -> int:
    sect = doc.element.body.find(qn("w:sectPr"))
    if sect is None:
        return default
    node = sect.find(qn(tag))
    if node is None:
        return default
    try:
        value = int(node.get(qn(attr)) or 0)
    except (TypeError, ValueError):
        return default
    return value if value > 0 else default


def _content_width_dxa(doc: Document) -> int:
    page_w = _section_metric(doc, "w:pgSz", "w:w", 12240)
    left = _section_metric(doc, "w:pgMar", "w:left", 1440)
    right = _section_metric(doc, "w:pgMar", "w:right", 1440)
    return max(1440, page_w - left - right)


def _line_pitch_dxa(doc: Document) -> int:
    """Document grid line pitch. Wrapped header lines snap to this, not to 12pt."""
    return _section_metric(doc, "w:docGrid", "w:linePitch", 240)


def _story_table_width_dxa(tbl, content_width: int) -> int:
    direct = _table_width_dxa(tbl)
    if direct > 0:
        return direct
    tbl_pr = tbl.find(qn("w:tblPr"))
    if tbl_pr is None:
        return content_width
    tbl_w = tbl_pr.find(qn("w:tblW"))
    if tbl_w is None or (tbl_w.get(qn("w:type")) or "") != "pct":
        return content_width
    try:
        raw = int(tbl_w.get(qn("w:w")) or 0)
    except (TypeError, ValueError):
        return content_width
    if raw <= 0:
        return content_width
    return max(1, int(raw / 5000 * content_width))


def _story_cell_width_dxa(tc, table_width: int) -> int:
    tc_pr = tc.find(qn("w:tcPr"))
    if tc_pr is None:
        return table_width
    tc_w = tc_pr.find(qn("w:tcW"))
    if tc_w is None:
        return table_width
    try:
        raw = int(tc_w.get(qn("w:w")) or 0)
    except (TypeError, ValueError):
        return table_width
    if raw <= 0:
        return table_width
    kind = (tc_w.get(qn("w:type")) or "dxa").lower()
    if kind == "dxa":
        return raw
    if kind == "pct":
        return max(1, int(raw / 5000 * table_width))
    return table_width


def _paragraph_wrapped_lines(paragraph, max_chars: int) -> int:
    lines = 1
    column = 0
    for node in paragraph.iter():
        if node.tag == qn("w:br") or node.tag == qn("w:cr"):
            lines += 1
            column = 0
            continue
        if node.tag != qn("w:t") or not node.text:
            continue
        for char in node.text.replace("\r", ""):
            if char == "\n":
                lines += 1
                column = 0
                continue
            column += 1
            if max_chars > 0 and column >= max_chars and (char.isspace() or column > max_chars + 2):
                lines += 1
                column = 0
    return lines


def _estimated_row_height_dxa(tr, table_width: int, line_pitch: int) -> int:
    """
    The template row height already fits the printed labels.

    Only lines beyond that capacity make the row taller. An empty letterhead
    therefore keeps its original height and the body table stays where it was.
    """
    specified = 0
    tr_pr = tr.find(qn("w:trPr"))
    if tr_pr is not None:
        tr_height = tr_pr.find(qn("w:trHeight"))
        if tr_height is not None:
            try:
                specified = int(tr_height.get(qn("w:val")) or 0)
            except (TypeError, ValueError):
                specified = 0
    # Template rows are sized for about 12pt lines, not the document grid.
    line = 280
    capacity = max(1, int(round(specified / line))) if specified else 1
    most_lines = 1
    for tc in tr.findall(qn("w:tc")):
        tc_pr = tc.find(qn("w:tcPr"))
        if tc_pr is not None:
            merge = tc_pr.find(qn("w:vMerge"))
            if merge is not None and (merge.get(qn("w:val")) or "") != "restart":
                continue
        width = _story_cell_width_dxa(tc, table_width)
        max_chars = max(4, int((max(width, 200) - 180) / 140))
        lines = 0
        saw_text = False
        for paragraph in tc.findall(qn("w:p")):
            text = "".join(node.text or "" for node in paragraph.iter(qn("w:t")))
            if not text.strip():
                continue
            saw_text = True
            lines += _paragraph_wrapped_lines(paragraph, max_chars)
        if not saw_text:
            continue
        if lines > most_lines:
            most_lines = lines
    extra = max(0, most_lines - capacity)
    # Extra lines follow the document grid. 12pt is too short and the next
    # table, which is pinned on the page, climbs into the letterhead.
    return specified + extra * max(line, line_pitch)


def _estimated_header_bottom_dxa(doc: Document) -> int:
    """Page-Y where the letterhead ends, including the header's distance from the edge."""
    content_width = _content_width_dxa(doc)
    line_pitch = _line_pitch_dxa(doc)
    header_distance = _section_metric(doc, "w:pgMar", "w:header", 720)
    tallest = 0
    for rel in doc.part.rels.values():
        reltype = str(getattr(rel, "reltype", "") or "")
        if not reltype.endswith("/header"):
            continue
        try:
            root = rel.target_part.element
        except (AttributeError, ValueError, KeyError):
            continue
        for tbl in root.findall(qn("w:tbl")):
            table_width = _story_table_width_dxa(tbl, content_width)
            height = 0
            for tr in tbl.findall(qn("w:tr")):
                height += _estimated_row_height_dxa(tr, table_width, line_pitch)
            if height > tallest:
                tallest = height
    if tallest <= 0:
        return 0
    return header_distance + tallest


def _flow_page_anchored_tables(doc: Document) -> int:
    """
    Drop a page pin so the table stays in the story.

    Rows that still fit stay on this page. Only the overflow moves on.
    A text-anchored table (approval block) keeps its own offset.
    """
    changed = 0
    for tbl in doc.element.body.findall(qn("w:tbl")):
        tbl_pr = tbl.find(qn("w:tblPr"))
        if tbl_pr is None:
            continue
        anchor = tbl_pr.find(qn("w:tblpPr"))
        if anchor is None:
            continue
        if (anchor.get(qn("w:vertAnchor")) or "").lower() != "page":
            continue
        tbl_pr.remove(anchor)
        changed += 1
    return changed


def _header_story_text(doc: Document, rel_id: str | None) -> str:
    if not rel_id:
        return ""
    rel = doc.part.rels.get(rel_id)
    part = getattr(rel, "target_part", None) if rel is not None else None
    root = getattr(part, "element", None) if part is not None else None
    if root is None:
        return ""
    return _normalize_preview_text(" ".join(node.text or "" for node in root.iter(qn("w:t"))))


def _repeat_letterhead_on_every_section(doc: Document) -> int:
    """
    This template stores the letterhead on the first section only.

    Page 2 and the pages after it then open with no header. Point every
    section at that letterhead. An empty even or first header is the same
    letterhead, so those pages show it too.
    """
    sects = list(doc.element.body.iter(qn("w:sectPr")))
    best_id = ""
    best_len = 0
    for sect in sects:
        for ref in sect.findall(qn("w:headerReference")):
            if (ref.get(qn("w:type")) or "") != "default":
                continue
            rel_id = ref.get(qn("r:id")) or ""
            length = len(_header_story_text(doc, rel_id))
            if length > best_len:
                best_len = length
                best_id = rel_id
    if not best_id or best_len < 8:
        return 0
    changed = 0
    for sect in sects:
        refs = {
            (ref.get(qn("w:type")) or ""): ref
            for ref in sect.findall(qn("w:headerReference"))
        }
        default = refs.get("default")
        if default is None:
            node = OxmlElement("w:headerReference")
            node.set(qn("w:type"), "default")
            node.set(qn("r:id"), best_id)
            sect.insert(0, node)
            changed += 1
        elif len(_header_story_text(doc, default.get(qn("r:id")))) < 8:
            default.set(qn("r:id"), best_id)
            changed += 1
        for kind in ("even", "first"):
            ref = refs.get(kind)
            if ref is None:
                continue
            if len(_header_story_text(doc, ref.get(qn("r:id")))) < 8:
                ref.set(qn("r:id"), best_id)
                changed += 1
    return changed


def _keep_body_below_letterhead(doc: Document) -> int:
    """
    The body top margin starts under the letterhead.

    Without this, the first lines sit behind the header and the page
    looks blank even though the text is there.
    """
    bottom = _estimated_header_bottom_dxa(doc)
    if bottom <= 0:
        return 0
    gap = _line_pitch_dxa(doc)
    target = bottom + gap
    changed = 0
    for sect in doc.element.body.iter(qn("w:sectPr")):
        mar = sect.find(qn("w:pgMar"))
        if mar is None:
            continue
        try:
            current = int(mar.get(qn("w:top")) or 0)
        except (TypeError, ValueError):
            current = 0
        page_h = 16839
        sz = sect.find(qn("w:pgSz"))
        if sz is not None:
            try:
                page_h = int(sz.get(qn("w:h")) or page_h)
            except (TypeError, ValueError):
                page_h = 16839
        wanted = min(target, int(page_h * 0.45))
        if current >= wanted:
            continue
        mar.set(qn("w:top"), str(wanted))
        changed += 1
    return changed


def _drop_page_anchored_tables_below_letterhead(doc: Document) -> int:
    """
    Keep a page-anchored body table under the letterhead.

    Empty header rows leave a gap, so the original pin is kept.
    After fill the header is taller, and the pin is moved down with it.
    """
    bottom = _estimated_header_bottom_dxa(doc)
    if bottom <= 0:
        return 0
    gap = _line_pitch_dxa(doc)
    changed = 0
    for tbl in doc.element.body.findall(qn("w:tbl")):
        tbl_pr = tbl.find(qn("w:tblPr"))
        if tbl_pr is None:
            continue
        anchor = tbl_pr.find(qn("w:tblpPr"))
        if anchor is None:
            continue
        if (anchor.get(qn("w:vertAnchor")) or "").lower() != "page":
            continue
        try:
            current = int(anchor.get(qn("w:tblpY")) or 0)
        except (TypeError, ValueError):
            current = 0
        target = bottom + gap
        if current >= target:
            continue
        anchor.set(qn("w:tblpY"), str(target))
        changed += 1
    return changed


def _unwrap_glued_prefixes(text: str) -> str:
    """Earlier autosave snapshots were glued onto the final value with no gap.

    "nil kumar test" + "nil kumar test refrance docum1" must stay the final text.
    A normal value is left as typed.
    """
    best = _normalize_preview_text(text)
    if len(best) < 24:
        return text
    changed = True
    while changed:
        changed = False
        limit = len(best) // 2
        for cut in range(12, limit + 1):
            head = best[:cut]
            if " " not in head:
                continue
            tail = best[cut:]
            if tail.startswith(head) and len(tail) > len(head):
                best = tail
                changed = True
                break
    if best == _normalize_preview_text(text):
        return text
    return best


def _collapse_repeated_phrase(text: str) -> str:
    """One typed line was stored many times in the same cell. Keep it once."""
    flat = _normalize_preview_text(text)
    words = flat.split()
    if len(words) < 6:
        return text
    limit = len(words) // 3
    for size in range(1, limit + 1):
        if len(words) % size != 0:
            continue
        copies = len(words) // size
        if copies < 3:
            continue
        phrase = words[:size]
        if phrase * copies == words:
            return " ".join(phrase)
    return text


def _set_cell_text_preserve(cell, text: str) -> None:
    """Write cell text without wiping run styles, nested layout tables, or merges."""
    text = _unwrap_glued_prefixes(_normalize_text_value(text if text is not None else ""))
    text = _collapse_repeated_phrase(text)
    text = text.replace("\u00a0", " ").replace("\r\n", "\n").replace("\r", "\n")
    _enable_cell_text_wrap(cell)
    # NEVER delete nested tables here. Letterhead / Equipment ID / section banners
    # often use nested tbl or gridSpan — removing them wiped "Equipment/system ID No."
    # and broke Temperature Sensor / Pressure Gauge formatting after Save.
    text_paras = []

    for para in cell.paragraphs:
        if any(_run_has_image(r) for r in para.runs):
            continue
        text_paras.append(para)

    if not text_paras:
        text_paras = [cell.add_paragraph()]

    _set_paragraph_text(text_paras[0], text.strip("\n"))

    for para in text_paras[1:]:
        _set_paragraph_text(para, "")


def _cell_is_manual_serial(table: Table, row, cell, col_index: int) -> bool:
    """First serial column. Word auto-list must not supply this number."""
    if col_index != 0:
        return False
    if _row_is_matrix_section_banner(row) or _row_is_identity_letterhead(row):
        return False
    if _table_is_component_subsystem_matrix(table):
        return True
    for probe in table.rows[:8]:
        cells = _unique_row_cells(probe)
        if not cells:
            continue
        header = _normalize_preview_text(cells[0].text).lower()
        if re.match(r"^(?:sr|s|sl)\.?\s*no\b", header):
            return True
    return False


def _center_paragraph(para: Paragraph) -> bool:
    """Put the paragraph in the middle of the cell. Leave font size and bold alone."""
    p_pr = para._element.find(qn("w:pPr"))
    if p_pr is None:
        p_pr = OxmlElement("w:pPr")
        para._element.insert(0, p_pr)
    jc = p_pr.find(qn("w:jc"))
    if jc is None:
        jc = OxmlElement("w:jc")
        p_pr.append(jc)
    if (jc.get(qn("w:val")) or "") == "center":
        return False
    jc.set(qn("w:val"), "center")
    return True


def _align_serial_number_cells(doc: Document) -> int:
    """
    Serials such as 4. must sit in the center of the S. No. column, like 1. 2. 3.

    4. was justified, so it sat on the left. Font size and bold are not changed.
    """
    changed = 0
    serial_re = re.compile(r"^\d+(?:\.\d+)*\.?$")
    header_re = re.compile(r"^(?:sr|s|sl)\.?\s*no\b", re.I)
    for table in collect_docx_tables(doc):
        rows = list(table.rows)
        if not rows:
            continue
        has_header = False
        for probe in rows[:8]:
            cells = _unique_row_cells(probe)
            if cells and header_re.match(_normalize_preview_text(cells[0].text)):
                has_header = True
                break
        if not has_header:
            continue
        for row in rows:
            cells = _unique_row_cells(row)
            if not cells or not cells[0].paragraphs:
                continue
            text = _normalize_preview_text(cells[0].text)
            if not serial_re.match(text):
                continue
            for para in cells[0].paragraphs:
                if _center_paragraph(para):
                    changed += 1
            # A section title on this row, such as "Machine Performance Check.",
            # must use the same left edge as "Operational Checks."
            if len(cells) < 2 or not cells[1].paragraphs:
                continue
            title = _normalize_preview_text(cells[1].text)
            if not title or serial_re.match(title):
                continue
            for para in cells[1].paragraphs:
                p_pr = para._element.find(qn("w:pPr"))
                if p_pr is None:
                    continue
                jc = p_pr.find(qn("w:jc"))
                if jc is None or (jc.get(qn("w:val")) or "") != "both":
                    continue
                p_pr.remove(jc)
                changed += 1
    return changed


def _strip_manual_serial_autonumber(doc: Document) -> int:
    """Drop Word list numbering from serial cells so the typed number is the only one."""
    removed = 0
    for table in collect_docx_tables_including_nested(doc):
        for row in table.rows:
            cells = _unique_row_cells(row)
            if not cells or not _cell_is_manual_serial(table, row, cells[0], 0):
                continue
            for para in cells[0].paragraphs:
                if not _para_has_numbering(para):
                    continue
                # An unused template row only has a list number. Leave that number.
                # Stripping it turned the row into a blank line between 3 and 3.3.
                if _normalize_preview_text(para.text) == "":
                    continue
                _remove_paragraph_numbering(para)
                removed += 1
    return removed


def _drop_empty_component_rows_between_content(doc: Document) -> int:
    """Keep every component row.

    A blank slot between two filled rows used to be deleted. When a value had
    not been written yet, that delete removed the row the user had filled, so
    Main Body, Mechanical, and Electrical values were gone after refresh.
    """
    return 0


def _para_has_numbering(para: Paragraph) -> bool:
    p_pr = para._element.find(qn("w:pPr"))
    if p_pr is None:
        return False
    return p_pr.find(qn("w:numPr")) is not None


def _remove_ghost_numbered_paragraphs(doc: Document) -> int:
    """
    Drop body paragraphs that only consume a Word list number (ghost 7.0 lines),
    or whose text is only a bare section number like "7.0" with no title.
    These shift every following section heading by +1.
    """
    removed = 0
    body = doc.element.body
    p_tag = qn("w:p")
    for child in list(body):
        if child.tag != p_tag:
            continue
        if not _is_section_spacer_para(child):
            continue
        para = _paragraph_from_element(doc, child)
        text = _normalize_preview_text(para.text)
        is_empty_numbered = text == "" and _para_has_numbering(para)
        is_bare_section_number = bool(re.match(r"^\d+\.\d+\.?$", text))
        if not is_empty_numbered and not is_bare_section_number:
            continue
        parent = child.getparent()
        if parent is not None:
            parent.remove(child)
            removed += 1
    return removed


def _strip_preview_list_prefix(text: str, word_text: str) -> str:
    """
    Drop leading '7.0 ' style prefix from preview text when the Word paragraph
    uses auto-list numbering (number is not stored in para.text).
    """
    if not text:
        return text
    word_norm = _normalize_preview_text(word_text)
    if re.match(r"^\d+(?:\.\d+)+\.?\s", word_norm):
        return text
    normalized = str(text).replace("\r\n", "\n").replace("\r", "\n")
    lines = normalized.split("\n")
    if not lines:
        return text
    stripped_first = re.sub(r"^(\d+(?:\.\d+)+\.?)\s+", "", lines[0])
    if stripped_first == lines[0]:
        return text
    lines[0] = stripped_first
    return "\n".join(lines)


def _paragraph_has_image(para: Paragraph) -> bool:
    return any(_run_has_image(run) for run in para.runs)


def _clear_user_removed_text(kind: str, obj) -> None:
    """
    The user deleted this text in the editor.

    A body line is removed so the next open does not show it again.
    A table cell is emptied and kept, so the row and its borders stay.
    """
    if _is_instrument_column_header_text(getattr(obj, "text", "")):
        return
    if kind == "cell":
        _set_cell_text_preserve(obj, "")
        return
    if _paragraph_has_image(obj) or _para_is_inside_table(obj):
        _set_paragraph_text(obj, "")
        return
    element = obj._element
    parent = element.getparent() if element is not None else None
    if parent is not None:
        parent.remove(element)


def _find_paragraph_by_preview_text(doc: Document, preview_text: str) -> Paragraph | None:
    """Find the contents line the user typed under, so a new line stays there."""
    want = _normalize_preview_text(preview_text)
    if not want:
        return None
    prefix = re.match(r"^(\d+(?:\.\d+)+)\s+(\S+(?:\s+\S+){0,5})", want)
    for para in doc.paragraphs:
        have = _normalize_preview_text(para.text)
        if not have:
            continue
        if have == want or have.startswith(want[:48]) or want.startswith(have[:48]):
            return para
        if prefix and have.startswith(prefix.group(1)) and prefix.group(2).lower() in have.lower():
            return para
    return None


def _set_target_text(kind: str, obj, text: str) -> None:
    text = _normalize_text_value(text)
    if kind == "cell":
        _set_cell_text_preserve(obj, text)
        return
    if _para_has_numbering(obj):
        text = _strip_preview_list_prefix(text, obj.text or "")
    _set_paragraph_text(obj, text)


def _edit_new_text(edit: dict) -> str:
    raw = edit.get("text")
    if raw is None:
        raw = ""
    # Keep newlines; only normalize non-breaking spaces and encoding issues.
    text = _normalize_text_value(raw).replace("\u00a0", " ")
    return text.replace("\r\n", "\n").replace("\r", "\n")


# Short form-chrome only. Do NOT treat body sentences that end with ":"
# (e.g. "… under the following condition/s:") as labels — that blocked Save.
_KNOWN_SHORT_LABEL = re.compile(
    r"^(?:"
    r"annex[\s-]?\d*"
    r"|lup[i]?n\s+limited"
    r"|document\s*title\b.*|document\s*no\b.*|reference\s*document.*"
    r"|equipment(?:\s*/?\s*system)?(?:\s*(?:id|name|no\.?))?"
    r"|facilit(?:y)?(?:\s*/?\s*section)?"
    r"|(?:sr|s)\.?\s*no\.?"
    r"|serial\s*no\.?"
    r"|in-?house\s*equipment\s*no\.?"
    r"|name\s+of\s+the\s+supplier(?:/manufacturer)?"
    r"|purchase\s*order\s*no\.?"
    r"|requirements?(?:\s*\[.*)?"
    r"|dq|ioq|urs|rpn|remark.*|acceptable.*"
    r"|comments?\s*:?\s*|deviation.*|corrective\s*actions?.*"
    r"|role|name|signature|date|change|revision.*"
    r"|effective\s*date"
    r"|version"
    r"|topic"
    r"|applicability(?:\s+to\s+department)?"
    r"|as\s*per\s*document(?:\s*no\.?)?"
    r")\s*:?\s*$",
    re.I,
)


def _is_instrument_column_header_text(text: str | None) -> bool:
    """7.4 instruments grid titles. These must not be saved as fill-in text."""
    t = _normalize_preview_text(text)
    if not t or len(t) > 48:
        return False
    low = t.lower()
    if low in {"instrument", "function", "class"}:
        return True
    if re.match(r"^(?:s\.?\s*no\.?|sr\.?\s*no\.?)$", low):
        return True
    if re.match(r"location\s*in\s*the\s*system", low):
        return True
    if re.search(r"c/nc|other\s*type", low):
        return True
    return False


def _is_training_matrix_header_text(text: str | None) -> bool:
    """True for Attachment-12 column titles, including a short typed prefix.

    A filled value such as "vijay verma test topic 1" is not a title.
    """
    t = _normalize_preview_text(text)
    if not t or len(t) > 48:
        return False
    low = t.lower()
    if re.match(r"^(?:s\.?\s*no\.?|sr\.?\s*no\.?)$", low):
        return True
    if re.match(
        r"^(?:as\s*per\s*document(?:\s*no\.?)?|applicability(?:\s*to\s*department)?|topic)$",
        low,
    ):
        return True
    if re.search(r"\d", low) or len(low) > 40:
        return False
    if re.search(r"topic$", low) and len(low) <= 24:
        return True
    if re.search(r"applicability(?:\s*to\s*department)?$", low) and len(low.split()) <= 4:
        return True
    return False


def _toc_entry_title(text: str | None) -> str:
    t = _normalize_preview_text(text or "")
    t = re.sub(r"^\d+(?:\.\d+)+\s*", "", t)
    t = re.sub(r"\s+\d{1,3}$", "", t)
    return t.strip().lower()


def _same_toc_entry(old: str | None, new: str | None) -> bool:
    """Same contents line, including a changed page number."""
    if _is_toc_slot_text(old) and _is_toc_slot_text(new):
        return True
    old_title = _toc_entry_title(old)
    new_title = _toc_entry_title(new)
    return bool(old_title) and old_title == new_title and _is_toc_slot_text(old)


def _split_toc_visible(text: str | None):
    """Section, title, and page from a contents line. Leaders are ignored."""
    flat = _normalize_preview_text(text)
    flat = re.sub(r"[.\u2026·]{2,}", " ", flat)
    flat = re.sub(r"\s+", " ", flat).strip()
    match = re.match(r"^(\d+(?:\.\d+)+)\s+(.+?)\s+(\d{1,3})$", flat)
    if not match:
        return None
    return match.group(1), match.group(2).strip(" ."), match.group(3)


def _apply_toc_page_edit(para: Paragraph, new_text: str) -> bool:
    """Change only the page number. Leave the title, tabs, link, and font."""
    parts = _split_toc_visible(new_text)
    if not parts:
        return False
    _section, _title, page = parts
    if not re.fullmatch(r"\d{1,3}", page or ""):
        return False
    nodes = list(para._element.findall(".//" + qn("w:t")))
    page_node = None
    for node in nodes:
        if re.fullmatch(r"\d{1,3}", (node.text or "").strip()):
            page_node = node
    if page_node is None:
        return False
    if (page_node.text or "").strip() == page:
        return False
    page_node.text = page
    return True


def _is_toc_slot_text(text: str | None) -> bool:
    """True for Table of Contents title/entries — never overwrite these on Save."""
    raw = "" if text is None else str(text)
    t = _normalize_preview_text(raw)
    if not t:
        return False
    if re.search(r"table\s+of\s+contents", t, re.I):
        return True
    if not re.match(r"^\d+\.\d+", t):
        return False
    # Leaders / ellipsis / trailing page number typical of TOC lines.
    if re.search(r"\.{3,}|…|\u2026|·{3,}|\t", raw):
        return True
    if re.search(r"\s\d{1,3}$", t) and len(t) > 8:
        return True
    return False


_IDENTITY_FIELD_LABEL = re.compile(
    r"^(?:"
    r"model\s*no\.?"
    r"|serial\s*no\.?"
    r"|in-?house\s*equipment\s*no\.?"
    r"|name\s+of\s+the\s+supplier(?:/manufacturer)?"
    r"|purchase\s*order\s*no\.?"
    r")\s*:?\s*$",
    re.I,
)


def _is_identity_field_label(text: str | None) -> bool:
    t = _normalize_preview_text(text)
    if not t:
        return False
    if _IDENTITY_FIELD_LABEL.match(t):
        return True
    # "Model No.: ABC" still counts as identity-line contamination for table cells.
    return bool(re.match(
        r"^(?:model\s*no\.?|serial\s*no\.?|in-?house\s*equipment\s*no\.?"
        r"|name\s+of\s+the\s+supplier(?:/manufacturer)?|purchase\s*order\s*no\.?)\s*:",
        t,
        re.I,
    ))


def _para_is_inside_table(para: Paragraph) -> bool:
    el = para._element
    parent = el.getparent() if el is not None else None
    while parent is not None:
        if parent.tag == qn("w:tbl"):
            return True
        if parent.tag in (qn("w:body"), qn("w:hdr"), qn("w:ftr")):
            return False
        parent = parent.getparent()
    return False


def _looks_like_label_slot(text: str) -> bool:
    """True only for short static form labels — not body/prose fill-in lines."""
    t = _normalize_preview_text(text)
    if not t:
        return False
    if _is_toc_slot_text(text):
        return True
    # Real form labels are short ("Serial No.:", "Model No.:").
    if len(t) > 48:
        return False
    words = re.sub(r":+\s*$", "", t).strip().split()
    if len(words) > 5:
        return False
    if _KNOWN_SHORT_LABEL.match(t):
        return True
    if t.endswith(":"):
        return True
    if re.match(
        r"^(?:[\w./()-]+\s+){0,3}(?:no\.?|name|title|id|section)\s*:?$",
        t,
        re.I,
    ):
        return True
    return False


def _extract_typed_value_from_label(original: str, new_text: str) -> str | None:
    """If the user typed a short value into a label, return that value."""
    orig = _normalize_preview_text(original)
    live = _normalize_preview_text(new_text)
    if not orig or live == orig:
        return None
    if not _looks_like_label_slot(orig):
        return None
    at = live.find(orig)
    if at < 0:
        return None
    extra = _normalize_preview_text(live[:at] + " " + live[at + len(orig) :])
    if not extra or len(extra) > 80:
        return None
    # Reject typo/corruption fragments like "ed" from "edEffective Date", not short real IDs.
    if re.match(r"^ed$", extra, re.I) or re.match(r"^ed[a-z]", extra, re.I):
        return None
    return extra


def _apply_toc_page_by_title(doc: Document, edit: dict) -> bool:
    """Write a contents line by its section number, not by a shifted field index."""
    raw = str(edit.get("text") or "")
    new_parts = _split_toc_visible(raw)
    old_parts = _split_toc_visible(str(edit.get("original_text") or ""))
    if not new_parts:
        return False
    section = re.sub(r"\s+", "", new_parts[0]).lower()
    titles = []
    if old_parts and old_parts[1]:
        titles.append(re.sub(r"\s+", "", old_parts[1]).lower())
    if new_parts[1]:
        titles.append(re.sub(r"\s+", "", new_parts[1]).lower())
    sections = [section]
    if old_parts and old_parts[0]:
        old_section = re.sub(r"\s+", "", old_parts[0]).lower()
        if old_section and old_section not in sections:
            sections.append(old_section)
    if not section or not titles:
        return False
    for para in doc.paragraphs:
        visible = _normalize_preview_text(
            "".join(node.text or "" for node in para._element.findall(".//" + qn("w:t")))
        )
        if not _is_toc_slot_text(visible):
            continue
        blob = re.sub(r"\s+", "", visible).lower()
        if not any(re.match(re.escape(item) + r"(?!\d)", blob) for item in sections):
            continue
        if not any(title in blob for title in titles):
            continue
        if _apply_toc_page_edit(para, raw):
            return True
    return False


def _toc_entry_run_starts(targets: list[tuple[str, object, str]], start: int) -> bool:
    """True when three contents lines follow, even with no 'Table of Contents' heading."""
    hits = 0
    for i in range(start, len(targets)):
        if hits >= 3:
            return True
        text = _get_target_text(targets[i][0], targets[i][1])
        flat = _normalize_preview_text(text)
        if not flat:
            continue
        if re.search(r"table\s+of\s+contents", flat, re.I):
            return False
        if _is_toc_slot_text(text):
            hits += 1
            continue
        return False
    return hits >= 3


def _build_toc_protected_indexes(targets: list[tuple[str, object, str]]) -> set[int]:
    """Indexes spanning the TOC band (title + entries + blank spacers)."""
    protected: set[int] = set()
    in_toc = False
    for i, (kind, obj, _zone) in enumerate(targets):
        text = _get_target_text(kind, obj)
        if re.search(r"table\s+of\s+contents", text, re.I):
            in_toc = True
            protected.add(i)
            continue
        if not in_toc:
            if _toc_entry_run_starts(targets, i):
                in_toc = True
            else:
                continue
        if not text or _is_toc_slot_text(text):
            protected.add(i)
            continue
        if re.match(r"^\d+\.\d+\s+\S", text) and not _is_toc_slot_text(text):
            in_toc = False
            continue
        if text:
            in_toc = False
        else:
            protected.add(i)
    return protected


def _next_empty_index(
    targets: list[tuple[str, object, str]],
    start: int,
    zone: str | None,
    protected: set[int] | None = None,
) -> int | None:
    for i in range(start + 1, len(targets)):
        if protected and i in protected:
            continue
        kind, obj, slot_zone = targets[i]
        if zone and slot_zone != zone:
            continue
        if _get_target_text(kind, obj) == "":
            return i
    return None


def _sentence_tokens(text: str | None) -> list[str]:
    flat = re.sub(r"[.\u2026\u00b7]+", " ", _normalize_preview_text(text))
    flat = re.sub(r"[^\w\s/]+", " ", flat)
    return [token for token in flat.lower().split() if token]


def _same_continued_sentence(left: str | None, right: str | None) -> bool:
    """True when both strings are the same protocol sentence.

    A dotted blank is several ellipsis characters. The first autosave writes
    the words typed into one blank. The next autosave still sends the original
    dotted sentence as original_text, so the paragraph no longer matches it.
    The opening words stay the same, and that later value must still be written.
    """
    left_tokens = _sentence_tokens(left)
    right_tokens = _sentence_tokens(right)
    if len(left_tokens) < 6 or len(right_tokens) < 6:
        return False
    head = min(6, len(left_tokens), len(right_tokens))
    return left_tokens[:head] == right_tokens[:head]


def _retarget_shifted_paragraph(
    targets: list[tuple[str, object, str]],
    resolved: int,
    expected_norm: str,
    new_norm: str = "",
) -> int | None:
    """
    Write a body sentence only onto the paragraph that still has those words.

    A new table row inserts extra cell paragraphs and shifts every later index.
    The old index then points at a contact-surface cell, and the sentence from
    section 7.6 or 11.0 is written into that cell. When the slot no longer
    matches original_text, move the edit to the body paragraph that does.

    Keep typing in the same sentence. After the equipment name is saved, the
    machine I.D blank is a later edit of that paragraph and must not be dropped.
    """
    if resolved is None or not (0 <= resolved < len(targets)):
        return None
    kind, obj, zone = targets[resolved]
    old_norm = _get_target_text(kind, obj)
    if not expected_norm or old_norm == expected_norm:
        return resolved
    if (
        kind == "para"
        and zone == "body"
        and not _para_is_inside_table(obj)
        and (
            _same_continued_sentence(old_norm, expected_norm)
            or (new_norm and _same_continued_sentence(old_norm, new_norm))
        )
    ):
        return resolved
    matches = []
    for i, (k, o, z) in enumerate(targets):
        if k != "para" or z != "body":
            continue
        if _para_is_inside_table(o):
            continue
        if _get_target_text(k, o) == expected_norm:
            matches.append(i)
    if matches:
        return min(matches, key=lambda i: abs(i - resolved))
    soft = []
    for i, (k, o, z) in enumerate(targets):
        if k != "para" or z != "body" or _para_is_inside_table(o):
            continue
        current = _get_target_text(k, o)
        if _same_continued_sentence(current, expected_norm) or (
            new_norm and _same_continued_sentence(current, new_norm)
        ):
            soft.append(i)
    if len(soft) == 1:
        return soft[0]
    return None


def _resolve_target_index(
    targets: list[tuple[str, object, str]],
    edit: dict,
) -> int | None:
    """
    Prefer the frontend-mapped index when zone still matches.

    original_text is only used as a soft remap when the index no longer points
    at the expected zone (never drop a real user edit silently when index is valid).
    """
    idx = edit.get("index")
    try:
        index = int(idx)
    except (TypeError, ValueError):
        return None

    preferred_zone = edit.get("zone")
    expected = edit.get("original_text")
    expected_norm = (
        _normalize_preview_text(expected) if expected is not None else None
    )

    def text_at(i: int) -> str:
        kind, obj, _zone = targets[i]
        return _get_target_text(kind, obj)

    def zone_at(i: int) -> str:
        return targets[i][2]

    # Primary path: trust mapped index from the browser once zone is compatible.
    if 0 <= index < len(targets):
        if not preferred_zone or zone_at(index) == preferred_zone:
            return index

    # Soft remap when zone drifted: unique original_text in the preferred zone.
    if expected_norm and expected_norm != "":
        matches = [
            i for i in range(len(targets))
            if text_at(i) == expected_norm
            and (not preferred_zone or zone_at(i) == preferred_zone)
        ]
        if len(matches) == 1:
            return matches[0]
        if 0 <= index < len(targets) and index in matches:
            return index

    # Validation / QA stamp: find the label line even when index drifted.
    if edit.get("match_qa_line"):
        qa_matches = [
            i for i in range(len(targets))
            if re.search(
                r"validation\s*/?\s*quality\s*assurance",
                text_at(i),
                re.I,
            )
            and (not preferred_zone or zone_at(i) == preferred_zone)
        ]
        if len(qa_matches) == 1:
            return qa_matches[0]
        if 0 <= index < len(targets) and index in qa_matches:
            return index
        if qa_matches:
            return qa_matches[0]

    # Last resort: still honor a valid index so edited values are not discarded.
    if 0 <= index < len(targets):
        return index

    return None


def _header_row_score(have: list[str], want: list[str], want_is_sop_list: bool, want_is_sop_verify: bool) -> int:
    if not have:
        return 0

    def _tok(h: str) -> str:
        t = (h or "").strip().lower()
        # Accidental preview "ed" typing into headers (edCheck → check).
        return re.sub(r"^ed(?=[a-z])", "", t)

    have_n = [_tok(h) for h in have if _tok(h)]
    want_n = [_tok(w) for w in want if _tok(w)]
    score = 0
    for w in want_n:
        if w in have_n:
            score += 3
            continue
        for h in have_n:
            if w.startswith(h) or h.startswith(w) or w in h or h in w:
                score += 1
                break
    joined = " | ".join(have_n)
    if want_is_sop_list and (
        ("title" in joined and "sop" in joined)
        or ("sop" in joined and ("number" in joined or "no" in joined))
        or ("title" in joined and ("number" in joined or "effective" in joined))
    ):
        score += 6
    if want_is_sop_verify and ("performed" in joined and "verified" in joined):
        score += 6
    # Training Attachment-12: Topic / Applicability grids.
    if any("topic" in w for w in want_n) and any(
        "applicability" in w or "document" in w for w in want_n
    ):
        if "topic" in joined and ("applicability" in joined or "document" in joined):
            score += 6
    # IOQ / utilities / deviation grids
    if re.search(r"acceptance\s*criteria|observation|sign\s*and\s*date", joined) and re.search(
        r"\bcheck\b|\bmethod\b", joined
    ):
        score += 6
    if re.search(r"\bdeviation\b", joined) and re.search(r"corrective|acceptable", joined):
        score += 6
    if re.search(r"revision", joined) and re.search(r"\bchange\b", joined) and re.search(r"\bdate\b", joined):
        score += 6
    if re.search(
        r"title\s*of\s*sop|so[pg].*(?:no|number)|performed\s*by|verified\s*by|effective\s*date",
        joined,
    ):
        score += 2
    if want_is_sop_list and re.search(r"title|so[pg]|effective|number", joined):
        score += 2
    return score


def _table_is_component_subsystem_matrix(table: Table) -> bool:
    """Attachment 3 Major Component / Sub System — Main Body / Mechanical / Electrical banners."""
    if not table or not table.rows:
        return False
    blob_parts: list[str] = []
    for row in table.rows[:40]:
        for cell in _unique_row_cells(row):
            t = _normalize_preview_text(cell.text)
            if t:
                blob_parts.append(t.lower())
    blob = " | ".join(blob_parts)
    # Skip pure identity letterhead tables.
    if re.search(
        r"reference\s*document|document\s*title|equipment\s*/?\s*system|facilit\s*/?\s*sect",
        blob,
        re.I,
    ) and not re.search(
        r"mechanical\s*components?|electrical\s*components?|main\s*body",
        blob,
        re.I,
    ):
        return False
    # Pre-installation checks mention "electrical components" inside a
    # requirement. That grid has Check / Method / Specification headers
    # and is not the Major Component matrix.
    for row in table.rows[:8]:
        joined = " ".join(
            _normalize_preview_text(c.text).lower() for c in _unique_row_cells(row) if c.text
        )
        if (
            re.search(r"\bcheck\b", joined)
            and re.search(r"\bmethod\b", joined)
            and re.search(r"specification|observation|acceptance|checked\s*by", joined)
        ):
            return False
    for row in table.rows[:40]:
        cells = _unique_row_cells(row)
        if not cells:
            continue
        title = _normalize_preview_text(cells[0].text)
        if re.match(
            r"^(?:main\s*body|mechanical\s*components?|electrical\s*components?)",
            title,
            re.I,
        ):
            return True
    return False


def _headers_are_component_subsystem_sentinel(headers: list | None) -> bool:
    if not headers:
        return False
    first = _normalize_preview_text(str(headers[0] or "")).lower()
    return first == "__neq_component_matrix__" or first.startswith("__neq_cm_")


def _find_component_subsystem_table(doc: Document) -> Table | None:
    candidates = [
        t
        for t in collect_docx_tables_including_nested(doc)
        if _table_is_component_subsystem_matrix(t)
    ]
    if not candidates:
        return None
    if len(candidates) == 1:
        return candidates[0]

    def score(t: Table) -> int:
        blob = " ".join(
            _normalize_preview_text(c.text).lower()
            for row in t.rows[:40]
            for c in _unique_row_cells(row)
        )
        s = 0
        if re.search(r"main\s*body", blob):
            s += 3
        if re.search(r"mechanical\s*components?", blob):
            s += 3
        if re.search(r"electrical\s*components?", blob):
            s += 3
        s += min(len(t.rows), 30)
        return s

    candidates.sort(key=score, reverse=True)
    return candidates[0]


def _find_table_by_headers(doc: Document, headers: list) -> Table | None:
    """Find a Word table (incl. nested) whose header row matches the preview headers."""
    table, _ = _find_table_and_header_row(doc, headers)
    return table


def _find_table_and_header_row(
    doc: Document, headers: list, preferred_header_row: int | None = None
) -> tuple[Table | None, int | None]:
    """Find Word table + header row index (scan all rows — combined identity+SOP tables)."""
    if not headers:
        return None, None
    # Attachment 3: no real Check/Method header — locate by section banners.
    if _headers_are_component_subsystem_sentinel(headers):
        found = _find_component_subsystem_table(doc)
        if found is None:
            return None, None
        hdr = preferred_header_row if preferred_header_row is not None else -1
        return found, hdr
    want = [_normalize_preview_text(str(h or "")).lower() for h in headers if str(h or "").strip()]
    want = [h for h in want if h]
    if not want:
        return None, None
    want_joined = " | ".join(want)
    want_is_sop_verify = "performed by" in want_joined and "verified by" in want_joined
    # Verification grids also contain "Title of SOP" and "SOP No.". They are not
    # the 4-column list (Title / SOP Number / Effective Date). Matching both
    # wrote verification values into the list table, one row too high.
    want_is_sop_list = (not want_is_sop_verify) and (
        ("title of sop" in want_joined or ("title" in want_joined and "sop" in want_joined))
        and (
            "effective date" in want_joined or "sop number" in want_joined or "sop no" in want_joined
        )
    )
    want_is_comments = len(want) == 1 and "comment" in want[0]
    want_is_body_grid = bool(
        re.search(
            r"contact\s*surface|material\s*of\s*construction|ref\s*no\.?\s*of\s*certificate|"
            r"\binstrument\b|annexure\s*no|parts?\s*name|location\s*in\s*the\s*system",
            want_joined,
            re.I,
        )
    )
    need = 1 if want_is_comments else (2 if (want_is_sop_list or want_is_sop_verify) else max(2, min(len(want), 2)))

    best_table: Table | None = None
    best_header_row: int | None = None
    best_score = 0

    for table in collect_docx_tables_including_nested(doc):
        if not table.rows:
            continue
        # Contact-surface / instrument grids must not land in the page heading.
        if want_is_body_grid and _table_zone(doc, table) == "header":
            continue
        if want_is_body_grid and _table_is_identity_form_table(table) and not (
            want_is_sop_list or want_is_sop_verify
        ):
            continue
        # Prefer Attachment-11 style tables only when the grid really is an SOP.
        table_bonus = (
            8
            if _table_is_identity_form_table(table) and (want_is_sop_list or want_is_sop_verify)
            else 0
        )
        for header_row_idx in range(len(table.rows)):
            header_cells = _unique_row_cells(table.rows[header_row_idx])
            have = [_normalize_preview_text(c.text).lower() for c in header_cells]
            have = [h for h in have if h]
            score = _header_row_score(have, want, want_is_sop_list, want_is_sop_verify) + table_bonus
            if want_is_comments and any("comment" in h for h in have):
                score += 6
            # Prefer exact single-column Comments box over incidental "comment" columns.
            if want_is_comments and len(have) == 1 and "comment" in have[0]:
                score += 8
            if preferred_header_row is not None and header_row_idx == preferred_header_row:
                score += 12
            if score > best_score and score >= need:
                best_score = score
                best_table = table
                best_header_row = header_row_idx
    return best_table, best_header_row


def _find_sop_header_row_in_table(table: Table, headers: list | None = None) -> int | None:
    """Return the row index of the SOP column-header row inside a Word table."""
    if not table or not table.rows:
        return None
    want: list[str] = []
    want_is_sop_list = False
    want_is_sop_verify = False
    if headers:
        want = [_normalize_preview_text(str(h or "")).lower() for h in headers if str(h or "").strip()]
        want = [h for h in want if h]
        want_joined = " | ".join(want)
        want_is_sop_verify = "performed by" in want_joined and "verified by" in want_joined
        want_is_sop_list = (not want_is_sop_verify) and (
            ("title of sop" in want_joined or ("title" in want_joined and "sop" in want_joined))
            and (
                "effective date" in want_joined or "sop number" in want_joined or "sop no" in want_joined
            )
        )

    best_idx: int | None = None
    best_score = 0
    for header_row_idx in range(len(table.rows)):
        header_cells = _unique_row_cells(table.rows[header_row_idx])
        have = [_normalize_preview_text(c.text).lower() for c in header_cells]
        have = [h for h in have if h]
        joined = " | ".join(have)
        if want:
            score = _header_row_score(have, want, want_is_sop_list, want_is_sop_verify)
        else:
            score = 0
            if re.search(r"(sr\.?\s*no|s\.?\s*no)", joined) and re.search(
                r"title|sop|number|effective|date", joined
            ):
                score += 8
            if "performed" in joined and "verified" in joined:
                score += 8
        if score > best_score:
            best_score = score
            best_idx = header_row_idx
    if best_score >= 2:
        return best_idx
    return None


def _is_matrix_section_banner_text(text: str | None) -> bool:
    """True for category banner rows (Attachment 3/4/6 section titles)."""
    t = _normalize_preview_text(text)
    if not t or len(t) > 120:
        return False
    # Strip accidental "ed" glue so "Mechanical Components ed" still matches.
    t_clean = re.sub(r"\s+ed\b", "", t, flags=re.I).strip()
    t_clean = re.sub(r"^ed(?=[A-Za-z])", "", t_clean, flags=re.I).strip() or t
    # Verified-by footer rows under each component block.
    if re.match(r"^verified\s*by\b", t_clean, re.I):
        return True
    # Column-header chrome is not a section banner.
    if re.search(
        r"acceptance\s*criteria|observation\s*/?\s*result|\bmethod\b|\bspecification\b",
        t_clean,
        re.I,
    ) and not re.match(r"^verified\s*by\b", t_clean, re.I):
        if re.search(r"\bcheck\b|\bdeviation\b|\bsign\s*and\s*date\b", t_clean, re.I):
            return False
    if re.search(
        r"power\s*/?\s*electrical|electrical\s*connections|identification\s*of\s*utilities"
        r"|water\s*/?\s*steam|compressed\s*air|general\s+checks?",
        t_clean,
        re.I,
    ):
        return True
    # Prefix match (no trailing \b) so edited titles like "Main Bodydsadas" still
    # count as banners and stay out of data-row indexing.
    return bool(
        re.match(
            r"^(?:main\s*body|mechanical\s*components?|electrical\s*components?"
            r"|mechanical\s*adjustments?|machine\s*performance"
            r"|general\s*checks?|safety\s*(?:and\s*)?performance"
            r"|operational\s*/?\s*functional(?:\s*checks?)?"
            r"|pressure\s*gauge|temperature\s*sensor|humidity\s*sensor|flow\s*meter"
            r"|differential\s*pressure|vacuum\s*gauge|level\s*sensor|weighing\s*balance)",
            t_clean,
            re.I,
        )
    )


def _row_is_identity_letterhead(row) -> bool:
    """Reference / Document Title / Equipment / Facility rows are not comparison data rows."""
    cells = _unique_row_cells(row)
    if not cells:
        return False
    texts = [_normalize_preview_text(c.text).lower() for c in cells]
    blob = " | ".join(t for t in texts if t)
    if not blob or len(blob) > 280:
        return False
    if re.search(r"title\s*of\s*sop|requirements\s*defined|performed\s*by|acceptable\s*/\s*not", blob):
        return False
    return bool(
        re.search(
            r"reference\s*document|document\s*title|equipment\s*/?\s*system|facilit(?:y)?\s*/?\s*section",
            blob,
        )
    )


_COLUMN_HEADING_COMPACT = {
    "check",
    "acceptancecriteria",
    "observation/result",
    "observationresult",
    "signanddate",
    "instrumentidnumber",
    "calibrationdetails",
    "remark",
    "remarks",
    "deviation(ifany)",
    "acceptable/notacceptable",
    "acceptablenotacceptable",
    "correctiveactions",
    "correctiveaction",
    "comments",
    "comment",
}


def _row_is_column_heading(row) -> bool:
    """Check / Deviation / Instruments titles stay bold. Filled values do not."""
    cells = _unique_row_cells(row)
    texts = [_normalize_preview_text(c.text) for c in cells]
    nonempty = [t for t in texts if t]
    if not nonempty:
        return False
    compacted = [re.sub(r"\s+", "", t).lower() for t in nonempty]
    if len(compacted) == 1 and compacted[0] in {"comments", "comment"}:
        return True
    return all(
        item in _COLUMN_HEADING_COMPACT or item.startswith("instrumentsused")
        for item in compacted
    )


def _clear_bold_markup(r_pr) -> bool:
    if r_pr is None:
        return False
    changed = False
    for tag in ("w:b", "w:bCs"):
        node = r_pr.find(qn(tag))
        if node is None:
            continue
        val = (node.get(qn("w:val")) or "true").lower()
        if val in ("0", "false", "off"):
            continue
        r_pr.remove(node)
        changed = True
    return changed


def _unbold_filled_data_rows(doc: Document) -> int:
    """
    A value written onto a bold section heading kept that bold.

    Headings stay bold. Typed check rows such as mechanical 1 and
    machine check 5 / 6 are normal.
    """
    changed = 0
    for table in doc.tables:
        for row in table.rows:
            if _row_is_matrix_section_banner(row) or _row_is_safety_by_signoff(row):
                continue
            if _row_is_column_heading(row):
                continue
            for cell in _unique_row_cells(row):
                for paragraph in cell.paragraphs:
                    p_pr = paragraph._p.find(qn("w:pPr"))
                    if p_pr is not None and _clear_bold_markup(p_pr.find(qn("w:rPr"))):
                        changed += 1
                    for run in paragraph.runs:
                        if _clear_bold_markup(run._r.find(qn("w:rPr"))):
                            changed += 1
    return changed


def _row_is_matrix_section_banner(row) -> bool:
    cells = _unique_row_cells(row)
    if not cells:
        return False
    texts = [_normalize_preview_text(c.text) for c in cells]
    nonempty = [t for t in texts if t]
    if not nonempty:
        return False
    unique = list(dict.fromkeys(t.lower() for t in nonempty))
    # Verified-by rows often span one cell; section banners too.
    title = nonempty[0]
    if _is_matrix_section_banner_text(title):
        return True
    if len(unique) >= 2:
        return False
    return False


def _cell_is_short_column_title(text: str) -> bool:
    """True only when the whole cell is a column title.

    A filled value such as "mechanical components check1" or
    "electrical test deviation 1" contains title words, but it is not a header.
    Treating it as one skipped the row and the next save wrote onto the row above.
    """
    t = (text or "").strip()
    if not t or len(t) > 32:
        return False
    if re.fullmatch(r"[sdl]", t, flags=re.I):
        return True
    if re.search(r"\d", t) and not re.fullmatch(r"(?:s\.?\s*no\.?|sr\.?\s*no\.?)", t, flags=re.I):
        return False
    # "revision test" is a typed value. Only the column title is a header.
    if re.search(r"\brevision\b", t, flags=re.I) and not re.fullmatch(
        r"revision(?:\s*no\.?)?", t, flags=re.I
    ):
        return False
    return bool(_COLUMN_TITLE_RE.search(t)) and len(t.split()) <= 5


def _row_is_column_subheader(row) -> bool:
    """Second header line under a grouped title, e.g. S | D | L | RPN | High#/Medium/Low.

    It is not a data row. Counting it shifted Revised Risk Analysis values
    onto the wrong Word rows, and the last typed row was dropped.
    """
    cells = _unique_row_cells(row)
    texts = [_normalize_preview_text(c.text) for c in cells]
    texts = [t for t in texts if t]
    if len(texts) < 2:
        return False
    title_hits = 0
    for text in texts:
        if _cell_is_short_column_title(text):
            title_hits += 1
    return title_hits >= max(2, (len(texts) + 1) // 2)


def _headers_are_parts_grid(headers: list | None) -> bool:
    blob = " ".join(_normalize_preview_text(str(h or "")).lower() for h in (headers or []))
    return "parts name" in blob and "acceptance" in blob


def _row_matching_parts_label(table: Table, header_idx: int | None, label: str) -> int | None:
    """Find the parts row by the Parts Name cell, not by a shifted index."""
    want = _normalize_preview_text(label).lower()
    if not want:
        return None
    start = header_idx if isinstance(header_idx, int) and header_idx >= 0 else 0
    for index in range(start + 1, len(table.rows)):
        cells = _unique_row_cells(table.rows[index])
        if not cells:
            continue
        have = _normalize_preview_text(cells[0].text).lower()
        if have == want:
            return index
    return None


def _data_row_index_to_table_row(
    table: Table,
    data_row_index: int,
    *,
    header_row_index: int | None = None,
    headers: list | None = None,
) -> int | None:
    """Map a 0-based data-row index (header + section banners excluded) to Word row index."""
    if data_row_index < 0 or not table.rows:
        return None

    start: int | None = None
    if header_row_index is not None:
        try:
            start = int(header_row_index)
        except (TypeError, ValueError):
            start = None

    if start is None:
        start = _find_sop_header_row_in_table(table, headers)

    if start is None:
        # Legacy fallback — first rows only.
        if len(table.rows) > 1:
            first = " | ".join(
                _normalize_preview_text(c.text).lower() for c in _unique_row_cells(table.rows[0])
            )
            if re.search(
                r"sr\.?\s*no|s\.?\s*no|title|sop|performed|verified|effective|annex|instrument|requirement"
                r"|deviation|corrective|acceptable|comments?|check|method|observation",
                first,
            ):
                start = 0
            elif len(table.rows) > 2:
                second = " | ".join(
                    _normalize_preview_text(c.text).lower() for c in _unique_row_cells(table.rows[1])
                )
                if re.search(
                    r"sr\.?\s*no|s\.?\s*no|title|sop|performed|verified|effective|annex|instrument"
                    r"|deviation|corrective|comments?",
                    second,
                ):
                    start = 1
                else:
                    start = 0
            else:
                start = 0
        else:
            start = 0

    # Walk rows after the header, skipping section banners / letterhead
    # so a comparison grid is not written into Facility / Equipment cells.
    seen = 0
    for abs_i in range(start + 1, len(table.rows)):
        row = table.rows[abs_i]
        if (
            _row_is_matrix_section_banner(row)
            or _row_is_identity_letterhead(row)
            or _row_is_safety_by_signoff(row)
            or _row_is_column_subheader(row)
        ):
            continue
        if seen == data_row_index:
            return abs_i
        seen += 1
    return None


def _apply_cell_edits(doc: Document, cell_edits: list[dict]) -> int:
    """Write preview table cells by (table, row, col). Does not need paragraph indexes."""
    if not cell_edits:
        return 0

    tables = collect_docx_tables(doc)
    applied = 0
    for op in cell_edits:
        if not isinstance(op, dict):
            continue

        # Nested SOP / data grids — locate Word table by header fingerprint.
        if op.get("match_by_headers") and op.get("headers"):
            new_text = op.get("text")
            if new_text is None:
                new_text = ""
            new_text = _normalize_text_value(str(new_text)).replace("\u00a0", " ")
            # Scrub accidental "ed" glue from preview corruption (121ed, ed123ed).
            original = op.get("original_text")
            original_norm = (
                _normalize_preview_text(original) if original is not None else ""
            )
            new_low = new_text.lower()
            orig_low = original_norm.lower()
            if orig_low and new_low == (orig_low + "ed"):
                new_text = original_norm
            elif orig_low and new_low == ("ed" + orig_low):
                new_text = original_norm
            elif orig_low and new_low == ("ed" + orig_low + "ed"):
                new_text = original_norm
            else:
                scrubbed = re.sub(r"^ed(?=[A-Za-z0-9])", "", new_text, flags=re.I)
                if re.search(r"[0-9.]ed$", scrubbed, flags=re.I):
                    scrubbed = re.sub(r"ed$", "", scrubbed, flags=re.I)
                if scrubbed.strip():
                    new_text = scrubbed.strip()
            # Yes / No on a continued attachments page has no heading row, so the
            # header fingerprint misses the Word table. The annex number still
            # names the row. Write that cell directly.
            anchor = _annex_key(str(op.get("row_anchor") or ""))
            if anchor:
                annex_table, annex_index = _find_annex_anchor_row(doc, anchor)
                if annex_table is not None and annex_index is not None:
                    try:
                        annex_col = int(op.get("col"))
                    except (TypeError, ValueError):
                        annex_col = 2
                    annex_cell = _attachment_cell_for_column(annex_table.rows[annex_index], annex_col)
                    if annex_cell is not None:
                        annex_new = _normalize_preview_text(new_text)
                        annex_old = _normalize_preview_text(annex_cell.text)
                        if annex_new != annex_old and not _is_toc_slot_text(annex_new):
                            _set_cell_text_preserve(annex_cell, new_text)
                            applied += 1
                        continue
            # Never overwrite section banners / verified-by chrome (Attachment 3/4)
            # unless the client marked an intentional banner / verified-by edit.
            allow_banner = bool(op.get("allow_banner_edit") or op.get("match_abs_row"))
            probe_new = _normalize_preview_text(new_text)
            if not allow_banner:
                if _is_matrix_section_banner_text(probe_new) or _is_matrix_section_banner_text(
                    original_norm
                ):
                    continue
                if re.match(r"^verified\s*by\b", probe_new, re.I) or re.match(
                    r"^verified\s*by\b", original_norm, re.I
                ):
                    continue
                if re.search(
                    r"^(?:main\s*body|mechanical\s*components?|electrical\s*components?"
                    r"|pressure\s*gauge|temperature\s*sensor)",
                    probe_new,
                    flags=re.I,
                ):
                    continue
            headers_list = list(op.get("headers") or [])
            if _headers_look_like_filled_data(headers_list):
                continue
            preferred_hdr: int | None = None
            if op.get("header_row_index") is not None:
                try:
                    preferred_hdr = int(op.get("header_row_index"))
                except (TypeError, ValueError):
                    preferred_hdr = None
            table: Table | None = None
            found_hdr: int | None = None
            if _headers_are_component_subsystem_sentinel(headers_list):
                try:
                    ti = int(op.get("table"))
                except (TypeError, ValueError):
                    ti = -1
                if 0 <= ti < len(tables) and _table_is_component_subsystem_matrix(tables[ti]):
                    table = tables[ti]
                    found_hdr = preferred_hdr if preferred_hdr is not None else -1
                else:
                    table, found_hdr = _find_table_and_header_row(
                        doc, headers_list, preferred_header_row=preferred_hdr
                    )
            else:
                table, found_hdr = _find_table_and_header_row(
                    doc, headers_list, preferred_header_row=preferred_hdr
                )
            if table is None:
                continue
            # Grids such as Contact surface must not write into the page heading.
            if _table_zone(doc, table) == "header":
                continue
            try:
                data_row = int(op.get("row"))
                col_index = int(op.get("col"))
            except (TypeError, ValueError):
                continue

            # Absolute-row write for section banners / verified-by lines.
            # Keep one simple merged cell — never call _prepare_row_for_column_writes
            # (that splits gridSpan and creates column borders).
            if allow_banner:
                row_index = _row_for_verified_stamp(table, data_row, new_text)
                if row_index < 0 or row_index >= len(table.rows):
                    continue
                row = table.rows[row_index]
                cell = _collapse_row_to_single_merged_cell(row)
                if cell is None:
                    unique = _unique_row_cells(row)
                    cell = unique[0] if unique else None
                if cell is None:
                    continue
                old_norm = _normalize_preview_text(cell.text)
                new_norm = _normalize_preview_text(new_text)
                if old_norm == new_norm:
                    continue
                if _is_toc_slot_text(old_norm) or _is_toc_slot_text(new_text):
                    continue
                _widen_full_row_banner_cell(cell._tc)
                _set_cell_text_preserve(cell, new_text)
                applied += 1
                continue

            hdr_idx = preferred_hdr if preferred_hdr is not None else found_hdr
            if _headers_are_component_subsystem_sentinel(headers_list) and hdr_idx is None:
                hdr_idx = -1
            # Attachment rows are found by annex number, so the typed text
            # stays on that row even when the preview row index has shifted.
            anchor = _annex_key(str(op.get("row_anchor") or ""))
            anchored_index = None
            if anchor:
                for idx, candidate in enumerate(table.rows):
                    if any(_annex_key(cell.text) == anchor for cell in _unique_row_cells(candidate)):
                        anchored_index = idx
                        break
            if anchored_index is not None:
                row_index = anchored_index
            elif _headers_are_parts_grid(headers_list):
                # Stamped Word row wins. Matching only the Parts Name text
                # wrote every later column onto the first row that already
                # said "add1", and the row the user filled stayed blank.
                if op.get("abs_row"):
                    try:
                        row_index = int(data_row)
                    except (TypeError, ValueError):
                        row_index = None
                    if (
                        row_index is None
                        or row_index < 0
                        or row_index >= len(table.rows)
                        or _row_is_column_subheader(table.rows[row_index])
                    ):
                        continue
                else:
                    labeled = _row_matching_parts_label(
                        table,
                        preferred_hdr if preferred_hdr is not None else found_hdr,
                        str(op.get("row_label") or ""),
                    )
                    if labeled is not None:
                        row_index = labeled
                    else:
                        row_index = _data_row_index_to_table_row(
                            table,
                            data_row,
                            header_row_index=preferred_hdr if preferred_hdr is not None else found_hdr,
                            headers=headers_list,
                        )
            elif op.get("abs_row"):
                try:
                    row_index = int(data_row)
                except (TypeError, ValueError):
                    row_index = None
                if row_index is None or row_index < 0 or row_index >= len(table.rows):
                    continue
                # The column-title row is not a data row. A shifted index used to
                # write "1.1 / add1" onto it or onto the filled row above.
                if _row_is_column_subheader(table.rows[row_index]):
                    continue
            else:
                row_index = _data_row_index_to_table_row(
                    table,
                    data_row,
                    header_row_index=hdr_idx,
                    headers=headers_list,
                )
            # Do NOT auto-extend here. New rows are inserted once via table_ops.
            # Auto-extend previously duplicated every Add-row (1 Add → 2 identical rows)
            # and shifted content addresses for following cells.
            if row_index is None:
                continue
            # The Comments value belongs in the row under the word Comments.
            # A split page sometimes addresses the heading row itself.
            headers_blob = " ".join(str(h) for h in (headers_list or [])).lower()
            if (
                len(headers_list or []) == 1
                and "comment" in headers_blob
                and _row_is_column_heading(table.rows[row_index])
                and row_index + 1 < len(table.rows)
            ):
                row_index += 1
            # Never overwrite a header-looking or section-banner row via data-row mapping.
            if _row_is_matrix_section_banner(table.rows[row_index]):
                continue
            cell = _prepare_row_for_column_writes(table.rows[row_index], col_index)
            if cell is None:
                continue
            # Scrub glued header labels from typed values before write.
            new_text = _strip_matrix_header_glue(new_text, headers_list, col_index)
            old_norm = _normalize_preview_text(cell.text)
            new_norm = _normalize_preview_text(new_text)
            if old_norm == new_norm:
                continue
            original = op.get("original_text")
            original_norm = (
                _normalize_preview_text(original) if original is not None else ""
            )
            allow_clear = bool(op.get("allow_clear") or op.get("user_edited"))
            # Intentional clear: empty new text when original matches (or user marked clear).
            if new_norm == "" and old_norm != "":
                if allow_clear or original_norm == old_norm:
                    pass
                elif original_norm == "":
                    continue
                else:
                    # original doesn't match Word cell — wrong target, don't wipe.
                    continue
            if _is_toc_slot_text(old_norm) or _is_toc_slot_text(new_text):
                continue
            # Never overwrite training column titles via a mis-mapped data row.
            if _is_training_matrix_header_text(old_norm) or _is_training_matrix_header_text(
                original_norm
            ):
                continue
            if _is_instrument_column_header_text(old_norm) or _is_instrument_column_header_text(
                original_norm
            ):
                continue
            # Do not plant the Deviation heading's "(If any)" into an empty data cell.
            if _is_header_parenthetical_fragment(new_norm) and not old_norm:
                header_blob = " ".join(str(h) for h in (headers_list or [])).lower()
                if "deviation" in header_blob or "if any" in header_blob:
                    continue
            _set_cell_text_preserve(cell, new_text)
            applied += 1
            continue

        # Identity header fields from nested preview tables — find by label text.
        if op.get("match_by_label") and op.get("label"):
            new_text = op.get("text")
            if new_text is None:
                new_text = ""
            new_text = _normalize_text_value(str(new_text)).replace("\u00a0", " ")
            # A pasted body paragraph must stay in the body. A single-line header
            # value such as Reference Document No. can be longer than 160 characters.
            flat = _normalize_preview_text(new_text)
            if "\n" in new_text or len(flat) > 400:
                continue
            label = _canonicalize_identity_label(str(op.get("label") or "").strip())
            # Ignore wrap-fragment "labels" that are not real field names.
            if re.match(
                r"^(?:no\.?\s*\(?\s*version|system\s+name|system\s+id|/?\s*system)\b",
                _normalize_preview_text(label),
                re.I,
            ):
                continue
            if not label:
                continue
            new_text = _strip_label_prefix_from_value(label, new_text)
            original = op.get("original_text")
            original_norm_pre = (
                _normalize_preview_text(original) if original is not None else ""
            )
            # Scrub accidental "ed" glue on identity values (121ed, 0.2ed).
            new_low = new_text.lower()
            orig_low = original_norm_pre.lower()
            if orig_low and new_low == (orig_low + "ed"):
                new_text = original_norm_pre
            elif orig_low and new_low == ("ed" + orig_low):
                new_text = original_norm_pre
            elif orig_low and new_low == ("ed" + orig_low + "ed"):
                new_text = original_norm_pre
            else:
                scrubbed = re.sub(r"^ed(?=[A-Za-z0-9])", "", new_text, flags=re.I)
                if re.search(r"[0-9.]ed$", scrubbed, flags=re.I):
                    scrubbed = re.sub(r"ed$", "", scrubbed, flags=re.I)
                if scrubbed.strip():
                    new_text = scrubbed.strip()
            # Value cell still shows the field label (placeholder) — not a real prior value.
            if (
                original_norm_pre
                and _is_neq_identity_label_text(original_norm_pre)
                and _labels_match_identity(original_norm_pre, label)
            ):
                original = ""
            target = _find_value_cell_after_label(
                doc,
                label,
                preferred_zone=str(op.get("zone") or "").strip() or None,
                original_text=str(original) if original is not None else None,
            )
            if target is None:
                continue
            old_norm = _normalize_preview_text(target.text)
            new_norm = _normalize_preview_text(new_text)
            if old_norm == new_norm:
                continue
            if _is_toc_slot_text(old_norm) or _is_toc_slot_text(new_text):
                continue
            # Never overwrite training matrix headers (Topic / Applicability…).
            if _is_training_matrix_header_text(old_norm) or _is_training_matrix_header_text(
                original_norm_pre
            ):
                continue
            if _is_training_matrix_header_text(label):
                continue
            if _is_instrument_column_header_text(old_norm) or _is_instrument_column_header_text(
                original_norm_pre
            ):
                continue
            # Refuse to overwrite ANY identity label cell (Equipment/system ID No. etc.).
            if _is_neq_identity_label_text(old_norm):
                # Only allow when the cell is a same-field placeholder echo.
                if not (
                    _labels_match_identity(old_norm, label)
                    and not _labels_match_identity(new_norm, label)
                    and new_norm
                ):
                    continue
            # Reject cleaned label fragments ("No." from "Equipment/system ID No.").
            if re.match(r"^(?:no\.?|id\.?|system|equipment\/?)$", new_norm, re.I):
                continue
            allow_clear = bool(op.get("allow_clear") or op.get("user_edited"))
            original_norm = (
                _normalize_preview_text(original) if original is not None else ""
            )
            if new_norm == "" and old_norm != "":
                if allow_clear or original_norm == old_norm:
                    pass
                else:
                    continue
            # Don't write another label/placeholder into the value cell.
            if (
                (_is_identity_field_label(new_norm) or _is_neq_identity_label_text(new_norm))
                and not allow_clear
            ):
                continue
            # Never clear/replace Equipment ID / name labels via a mismatched op.
            if old_norm and re.search(
                r"equipment\s*/?\s*system\s*(name|id)|equipment\s*(name|id)",
                old_norm,
                re.I,
            ):
                if not _labels_match_identity(old_norm, label):
                    continue
                if _is_neq_identity_label_text(old_norm) and new_norm != old_norm:
                    # Writing a value INTO the label cell itself only when user typed
                    # over the label and label matches this field.
                    if not _labels_match_identity(old_norm, label):
                        continue
            # If original was provided and cell disagrees badly, skip (wrong target).
            if (
                original_norm
                and old_norm
                and original_norm != old_norm
                and not _is_identity_placeholder_value(old_norm, label)
                and not _is_identity_placeholder_value(original_norm, label)
                and not allow_clear
            ):
                continue
            _set_cell_text_preserve(target, new_text)
            applied += 1
            continue

        try:
            table_index = int(op.get("table"))
            row_index = int(op.get("row"))
            col_index = int(op.get("col"))
        except (TypeError, ValueError):
            continue
        if table_index < 0 or table_index >= len(tables):
            continue
        table = tables[table_index]
        if _table_zone(doc, table) == "header" or _table_is_identity_form_table(table):
            continue
        if row_index < 0 or row_index >= len(table.rows):
            continue
        allow_banner = bool(op.get("allow_banner_edit") or op.get("match_abs_row"))
        # Section verified-by / banner stamps stay one merged cell.
        if allow_banner:
            row_index = _row_for_verified_stamp(
                table, row_index, str(op.get("text") or "")
            )
            if row_index < 0 or row_index >= len(table.rows):
                continue
            cell = _collapse_row_to_single_merged_cell(table.rows[row_index])
            if cell is None:
                unique = _unique_row_cells(table.rows[row_index])
                cell = unique[0] if unique else None
            if cell is None:
                continue
            _widen_full_row_banner_cell(cell._tc)
            cells = [cell]
            col_index = 0
        else:
            cells = _unique_row_cells(table.rows[row_index])
            if col_index < 0 or col_index >= len(cells):
                continue
            cell = cells[col_index]
        new_text = op.get("text")
        if new_text is None:
            new_text = ""
        new_text = _normalize_text_value(str(new_text)).replace("\u00a0", " ")
        old_norm = _normalize_preview_text(cell.text)
        new_norm = _normalize_preview_text(new_text)
        if old_norm == new_norm:
            continue

        if _is_toc_slot_text(old_norm) or _is_toc_slot_text(new_text):
            continue

        # Never overwrite letterhead labels or instrument section banners via positional writes
        # unless this is an intentional banner / verified-by edit.
        if _is_neq_identity_label_text(old_norm) and new_norm != old_norm and not allow_banner:
            continue
        if _is_training_matrix_header_text(old_norm) and new_norm != old_norm:
            continue
        if _is_instrument_column_header_text(old_norm) and new_norm != old_norm:
            continue
        if not allow_banner:
            if _row_is_matrix_section_banner(table.rows[row_index]):
                continue
            if _is_matrix_section_banner_text(old_norm) and new_norm != old_norm:
                continue

        original = op.get("original_text")
        original_norm = (
            _normalize_preview_text(original) if original is not None else old_norm
        )
        if _is_training_matrix_header_text(original_norm) and new_norm != original_norm:
            continue
        if _is_instrument_column_header_text(original_norm) and new_norm != original_norm:
            continue
        allow_clear = bool(op.get("allow_clear") or op.get("user_edited"))
        if new_norm == "" and old_norm != "" and len(old_norm) > 1:
            if allow_clear or original_norm == old_norm:
                pass
            elif original_norm and original_norm == old_norm and len(original_norm) > 1:
                pass
            else:
                continue

        # Never treat Annexure / S.No / multi-column data cells as label→next redirects.
        # That was dumping "Model No.:" / "Serial No.:" into neighbouring table columns.
        # Annex-13 comparison / risk matrices are multi-column — always write in place.
        row_cell_count = len(cells)
        allow_label_redirect = row_cell_count == 2 and col_index == 0
        if allow_label_redirect and (
            _looks_like_label_slot(original_norm) or _looks_like_label_slot(old_norm)
        ):
            extra = _extract_typed_value_from_label(original_norm or old_norm, new_norm)
            if extra and col_index + 1 < len(cells):
                nxt = cells[col_index + 1]
                if _normalize_preview_text(nxt.text) == "":
                    _set_cell_text_preserve(nxt, extra)
                    applied += 1
                    continue
            if _looks_like_label_slot(new_norm) and new_norm != old_norm and not extra:
                continue
            _set_cell_text_preserve(cell, new_text)
            applied += 1
            continue

        # Block identity labels from overwriting unrelated table values (NA, annex IDs).
        # Do not block empty→filled Annex-13 / matrix cells.
        if (
            old_norm
            and _is_identity_field_label(new_norm)
            and not _is_identity_field_label(old_norm)
        ):
            continue

        _set_cell_text_preserve(cell, new_text)
        applied += 1
    return applied


W14_NS = "http://schemas.microsoft.com/office/word/2010/wordml"


CHECKBOX_GLYPH_RE = re.compile(
    r"[\u2611☑✓☐□\u25a1\u2610\uF0A7\uF0A8\uF0FE\uF0FD\uF070\uF0FC\uF06F\uF0A2]"
)

# Wingdings / Symbol fonts often encode an empty box as a normal letter (e.g. 'o', 'q').
_SYMBOL_CHECKBOX_FONTS = (
    "wingdings",
    "wingdings 2",
    "wingdings 3",
    "symbol",
    "segoe ui symbol",
    "mt extra",
    "zapfdingbats",
)


def _clear_checkbox_visuals(para: Paragraph) -> None:
    """Remove drawing / VML / object checkbox visuals so Unicode ☑/☐ is the only mark."""
    el = para._element
    for tag in (qn("w:drawing"), qn("w:pict"), qn("w:object")):
        for node in list(el.findall(f".//{tag}")):
            parent = node.getparent()
            if parent is not None:
                parent.remove(node)


def _set_w14_checkbox_state(para: Paragraph, checked: bool) -> int:
    """Toggle Word 2010+ content-control checkboxes (w14:checkbox) if present."""
    updated = 0
    checked_tag = f"{{{W14_NS}}}checked"
    checkbox_tag = f"{{{W14_NS}}}checkbox"
    val_attr = f"{{{W14_NS}}}val"
    for cb in para._element.findall(f".//{checkbox_tag}"):
        checked_el = cb.find(checked_tag)
        if checked_el is None:
            checked_el = cb.makeelement(checked_tag, {val_attr: "1" if checked else "0"})
            cb.insert(0, checked_el)
        else:
            checked_el.set(val_attr, "1" if checked else "0")
        updated += 1
    return updated


def _run_font_names(run) -> list[str]:
    r_pr = run._element.find(qn("w:rPr"))
    if r_pr is None:
        return []
    r_fonts = r_pr.find(qn("w:rFonts"))
    if r_fonts is None:
        return []
    names: list[str] = []
    for attr in ("ascii", "hAnsi", "cs", "eastAsia"):
        val = r_fonts.get(qn(f"w:{attr}"))
        if val:
            names.append(val)
    return names


def _is_symbol_checkbox_font(name: str) -> bool:
    n = (name or "").strip().lower()
    if not n:
        return False
    return any(n == f or n.startswith(f) for f in _SYMBOL_CHECKBOX_FONTS)


def _is_checkbox_symbol_run(run) -> bool:
    """True for short Wingdings/Symbol/PUA runs that render as an empty/checked box."""
    if _run_has_image(run):
        return False
    text = run.text or ""
    stripped = text.strip()
    if not stripped or len(stripped) > 4:
        return False
    if CHECKBOX_GLYPH_RE.search(stripped):
        return True
    fonts = _run_font_names(run)
    if any(_is_symbol_checkbox_font(f) for f in fonts):
        return True
    if len(stripped) == 1 and 0xF000 <= ord(stripped) <= 0xF0FF:
        return True
    return False


def _ensure_unicode_checkbox_font(run) -> None:
    """Force a font that can display ☑/☐ (Wingdings would hide the tick)."""
    r_pr = run._element.get_or_add_rPr()
    r_fonts = r_pr.find(qn("w:rFonts"))
    if r_fonts is None:
        r_fonts = OxmlElement("w:rFonts")
        r_pr.insert(0, r_fonts)
    for attr in ("ascii", "hAnsi", "cs"):
        r_fonts.set(qn(f"w:{attr}"), "Segoe UI Symbol")
    try:
        run.font.name = "Segoe UI Symbol"
    except Exception:
        pass


def _set_run_as_unicode_checkbox(run, mark_char: str) -> None:
    """Replace a symbol/glyph checkbox run with a visible Unicode tick/box."""
    raw = run.text or ""
    leading = raw[: len(raw) - len(raw.lstrip())] if raw else ""
    trailing = raw[len(raw.rstrip()) :] if raw else ""
    if not leading and not trailing:
        run.text = mark_char + " "
    else:
        run.text = f"{leading}{mark_char}{trailing or ' '}"
    _ensure_unicode_checkbox_font(run)


def _replace_glyphs_in_run_text(text: str, mark_char: str) -> tuple[str, bool]:
    """Replace existing checkbox glyphs in a run; return (new_text, changed)."""
    if not text or not CHECKBOX_GLYPH_RE.search(text):
        return text, False
    new_text = CHECKBOX_GLYPH_RE.sub(mark_char, text)
    return new_text, new_text != text


def _prepend_checkbox_mark(para: Paragraph, mark_char: str) -> None:
    """Insert a leading Unicode checkbox (SCOPE templates place the box first)."""
    for run in para.runs:
        if _run_has_image(run):
            continue
        text = run.text or ""
        stripped = text.lstrip()
        if CHECKBOX_GLYPH_RE.match(stripped[:1] or ""):
            run.text = CHECKBOX_GLYPH_RE.sub(mark_char, text, count=1)
            _ensure_unicode_checkbox_font(run)
            return
        if stripped:
            run.text = mark_char + " " + stripped
            _ensure_unicode_checkbox_font(run)
            return
    para.add_run(mark_char + " ")
    if para.runs:
        _ensure_unicode_checkbox_font(para.runs[-1])


def _set_paragraph_checkbox_state(para: Paragraph, checked: bool) -> None:
    """
    Same method as requalification Fill (`report_generator._fill_paragraph_checkbox`):
    write ☑/☐ onto the first non-image text run so Word always shows the tick.

    SCOPE templates often keep an empty drawing/Wingdings box in a separate run;
    we skip those, mark the label run, then drop empty drawing boxes so only the
    Unicode tick remains visible.
    """
    mark_char = "☑" if checked else "☐"
    _set_w14_checkbox_state(para, checked)

    # Strip existing leading/trailing checkbox glyphs from all text runs first
    # so we never leave a second box at the end of the line.
    glyph_only = re.compile(
        r"^[\s\u2611☑✓☐□\u25a1\u2610\uF0A7\uF0A8\uF0FE\uF0FD\uF070\uF0FC]+$"
    )
    leading_trailing = re.compile(
        r"^[\u2611☑✓☐□\u25a1\u2610\uF0A7\uF0A8\uF0FE\uF0FD\uF070\uF0FC]+\s*"
        r"|[\s\u2611☑✓☐□\u25a1\u2610\uF0A7\uF0A8\uF0FE\uF0FD\uF070\uF0FC]+$"
    )
    for run in para.runs:
        if _run_has_image(run):
            continue
        text = run.text or ""
        if not text:
            continue
        if _is_checkbox_symbol_run(run) or glyph_only.match(text.strip()):
            run.text = ""
            continue
        cleaned = leading_trailing.sub("", text)
        if cleaned != text:
            run.text = cleaned

    for run in para.runs:
        if _run_has_image(run):
            continue
        text = run.text or ""
        if not text.strip():
            continue
        run.text = f"{mark_char} {text.lstrip()}"
        _ensure_unicode_checkbox_font(run)
        break
    else:
        para.add_run(f"{mark_char} ")
        if para.runs:
            _ensure_unicode_checkbox_font(para.runs[-1])

    # Remove leftover empty drawing/pict boxes so the Unicode mark is the only box.
    _clear_checkbox_visuals(para)
    for run in list(para.runs):
        if _run_has_image(run) and not (run.text or "").strip():
            parent = run._element.getparent()
            if parent is not None:
                parent.remove(run._element)
        elif not (run.text or "").strip() and not _run_has_image(run):
            # Drop emptied glyph-only runs
            parent = run._element.getparent()
            if parent is not None and len(para.runs) > 1:
                parent.remove(run._element)


def _checkbox_label_norm(text: str | None) -> str:
    glyph_re = re.compile(
        r"[\u2611☑✓☐□\u25a1\u2610\uF0A7\uF0A8\uF0FE\uF0FD\uF070\uF0FC]"
    )
    return _normalize_preview_text(glyph_re.sub("", text or ""))


def _labels_compatible(expected_norm: str, candidate_norm: str) -> bool:
    """Exact / prefix label match only — never loose substring (avoids 'installation' collisions)."""
    if not expected_norm or not candidate_norm:
        return not expected_norm
    if expected_norm == candidate_norm:
        return True
    if candidate_norm.startswith(expected_norm) or expected_norm.startswith(candidate_norm):
        # Require meaningful overlap so short stubs cannot steal longer labels.
        return min(len(expected_norm), len(candidate_norm)) >= 12
    return False


def _para_from_cell_matching(cell, expected_norm: str):
    """Pick paragraph in cell whose text matches expected label; else None."""
    if not cell or not cell.paragraphs:
        return None
    if expected_norm:
        for candidate in cell.paragraphs:
            cand_norm = _checkbox_label_norm(candidate.text)
            if _labels_compatible(expected_norm, cand_norm):
                return candidate
        return None
    for candidate in cell.paragraphs:
        if any(_run_has_image(r) for r in candidate.runs) and _normalize_preview_text(candidate.text) == "":
            continue
        return candidate
    return cell.paragraphs[0]


def _resolve_checkbox_paragraph(doc: Document, targets: list, op: dict):
    """
    Resolve checkbox host.

    Prefer target index / original_text for body SCOPE lines.
    table/row/col is only reliable for single Yes/NA cells — never blindly take
    the first paragraph of a layout cell (that was writing ticks onto Document No.).
    """
    expected = _normalize_preview_text(op.get("original_text"))
    expected_norm = _checkbox_label_norm(expected)

    # 1) Prefer index (SCOPE body paragraphs).
    try:
        index = int(op.get("index"))
    except (TypeError, ValueError):
        index = -1
    if 0 <= index < len(targets):
        kind, obj, _zone = targets[index]
        if kind == "para":
            if _glyph_checkbox_target_ok(obj, expected_norm):
                return obj
        if kind == "cell":
            matched = _para_from_cell_matching(obj, expected_norm)
            if matched is not None and _glyph_checkbox_target_ok(matched, expected_norm):
                return matched

    # 2) Soft remap by unique original_text / stripped checklist label.
    if expected_norm:
        matches = []
        for i, (kind, obj, _zone) in enumerate(targets):
            text = _get_target_text(kind, obj)
            stripped = _checkbox_label_norm(text)
            if text == expected or stripped == expected_norm or _labels_compatible(expected_norm, stripped):
                matches.append(i)
        if len(matches) == 1:
            kind, obj, _zone = targets[matches[0]]
            if kind == "para":
                if _glyph_checkbox_target_ok(obj, expected_norm):
                    return obj
            if kind == "cell":
                matched = _para_from_cell_matching(obj, expected_norm)
                if matched is not None and _glyph_checkbox_target_ok(matched, expected_norm):
                    return matched

    # 3) Cell address last — and only if paragraph text matches label (or no label).
    table_i = op.get("table")
    row_i = op.get("row")
    col_i = op.get("col")
    if table_i is not None and row_i is not None and col_i is not None:
        try:
            ti, ri, ci = int(table_i), int(row_i), int(col_i)
        except (TypeError, ValueError):
            ti = ri = ci = -1
        tables = collect_docx_tables(doc)
        if 0 <= ti < len(tables) and 0 <= ri < len(tables[ti].rows):
            cells = _unique_row_cells(tables[ti].rows[ri])
            if 0 <= ci < len(cells):
                matched = _para_from_cell_matching(cells[ci], expected_norm)
                if matched is not None and _glyph_checkbox_target_ok(matched, expected_norm):
                    return matched
    return None


def _paragraph_table_header(para: Paragraph) -> str:
    """Column header of the cell that contains this paragraph, or ''."""
    el = para._element
    parent = el.getparent() if el is not None else None
    tc = None
    while parent is not None:
        if parent.tag == qn("w:tc"):
            tc = parent
            break
        parent = parent.getparent()
    if tc is None:
        return ""
    tr = tc.getparent()
    tbl = tr.getparent() if tr is not None else None
    if tbl is None or tr is None:
        return ""
    try:
        col = list(tr).index(tc)
    except ValueError:
        return ""
    rows = tbl.findall(qn("w:tr"))
    if not rows:
        return ""
    header_cells = rows[0].findall(qn("w:tc"))
    if col < 0 or col >= len(header_cells):
        return ""
    texts = header_cells[col].findall(".//" + qn("w:t"))
    return _normalize_preview_text("".join((t.text or "") for t in texts)).lower()


def _is_annex_or_attachment_name_header(header: str) -> bool:
    return bool(
        re.search(r"annex(?:ure)?\s*no", header or "", re.I)
        or re.search(r"attachment\s*(?:no\.?|type|number)", header or "", re.I)
    )


_CHECKBOX_ONLY_RE = re.compile(
    r"^[\s\u2610\u2611\u2612\u2713\u2714\u2705☑☐✓✔□■◻◼]*$"
)


def _strip_checkboxes_from_annex_name_cells(doc: Document) -> int:
    """Remove a tick that was saved under Annexure No. or Attachment Type."""
    removed = 0
    for table in collect_docx_tables(doc):
        if not table.rows:
            continue
        header_cells = _unique_row_cells(table.rows[0])
        drop_cols = []
        for idx, cell in enumerate(header_cells):
            if _is_annex_or_attachment_name_header(_normalize_preview_text(cell.text)):
                drop_cols.append(idx)
        if not drop_cols:
            continue
        for row in table.rows[1:]:
            cells = _unique_row_cells(row)
            for idx in drop_cols:
                if idx >= len(cells):
                    continue
                cell = cells[idx]
                for para in list(cell.paragraphs):
                    raw = para.text or ""
                    if not raw.strip():
                        continue
                    if _CHECKBOX_ONLY_RE.match(raw):
                        parent = para._element.getparent()
                        if parent is not None and len(cell.paragraphs) > 1:
                            parent.remove(para._element)
                            removed += 1
                        else:
                            for run in para.runs:
                                if run.text:
                                    run.text = ""
                            removed += 1
    return removed


def _strip_checkbox_glyphs(text: str) -> str:
    return re.sub(
        r"[\u2610\u2611\u2612\u2713\u2714\u25a1\u25a0☑☐✓✔□]",
        "",
        text or "",
    )


def _is_serial_number_header(header: str) -> bool:
    return bool(re.match(r"^(?:sr|s|sl)\.?\s*no\b", (header or "").strip(), re.I))


def _is_applicable_choice_header(header: str) -> bool:
    text = (header or "").strip().lower()
    if not text or re.search(r"annex|attachment\s*no", text):
        return False
    return bool(re.search(r"\bna\b|n/a|not\s*applicable|yes\s*if\s*applicable|\bapplicable\b", text))


def _paragraph_zone(para: Paragraph) -> str:
    node = para._element
    while node is not None:
        if node.tag == qn("w:hdr"):
            return "header"
        if node.tag == qn("w:ftr"):
            return "footer"
        node = node.getparent()
    return "body"


def _glyph_checkbox_target_ok(para: Paragraph, expected_norm: str) -> bool:
    """A tick with no label must not land on the protocol title or S. No."""
    if _paragraph_zone(para) in ("header", "footer"):
        return False
    text = para.text or ""
    low = _normalize_preview_text(text).lower()
    if "installation and operational qualification" in low:
        return False
    header = _paragraph_table_header(para)
    if _is_serial_number_header(header) or _is_annex_or_attachment_name_header(header):
        return False
    if expected_norm:
        return _labels_compatible(expected_norm, _checkbox_label_norm(text))
    if not _is_applicable_choice_header(header):
        return False
    return _checkbox_label_norm(text) == ""


def _annex_key(text: str) -> str:
    """The annex id only, so 097663 cannot match 09766 or 097664."""
    # Keep the full id. annex_mum_cqa_097656 must not collapse to annex_mum,
    # or every attachment row is saved as the same box.
    match = re.search(r"annex(?:[_-][a-z0-9]+)+", _normalize_preview_text(text).lower())
    return match.group(0) if match else ""


def _clear_cell_checkbox_glyphs(cell) -> None:
    """Leave no tick behind in a later paragraph of the same cell."""
    glyph_re = re.compile(r"[\u2610\u2611\u2612\u2713\u2714\u25a1\u25a0☑☐✓✔□]")
    for para in list(cell.paragraphs):
        for run in para.runs:
            if not run.text:
                continue
            cleaned = glyph_re.sub("", run.text)
            if cleaned != run.text:
                run.text = cleaned


def _find_annex_anchor_row(doc: Document, anchor: str):
    """Body row whose annex number is exactly this id."""
    want = _annex_key(anchor)
    if not want:
        return None, None
    for table in collect_docx_tables(doc):
        if _table_zone(doc, table) == "header":
            continue
        for index, row in enumerate(table.rows):
            if any(_annex_key(cell.text) == want for cell in _unique_row_cells(row)):
                return table, index
    return None, None


def _attachment_cell_for_column(row, col_index: int):
    """NA column when the typed value is Yes/No; otherwise the column that was edited."""
    cells = _unique_row_cells(row)
    annex_at = -1
    attachment_at = -1
    na_at = -1
    for index, cell in enumerate(cells):
        label = _normalize_preview_text(cell.text).lower()
        if annex_at < 0 and _annex_key(label):
            annex_at = index
        elif attachment_at < 0 and re.search(r"ioq\s*attachment\s*\d+", label):
            attachment_at = index
        elif na_at < 0:
            na_at = index
    if col_index <= 0 and annex_at >= 0:
        return cells[annex_at]
    if col_index == 1 and attachment_at >= 0:
        return cells[attachment_at]
    if na_at >= 0 and col_index >= 2:
        return cells[na_at]
    if 0 <= col_index < len(cells):
        return cells[col_index]
    return cells[na_at] if na_at >= 0 else None


def _apply_annex_anchor_checkbox(doc: Document, anchor: str, checked: bool) -> bool:
    """Tick or clear only the NA cell on the row with this exact annex number."""
    want = _annex_key(anchor)
    if not want:
        return False
    for table in collect_docx_tables(doc):
        for row in table.rows:
            cells = _unique_row_cells(row)
            annex_at = -1
            attachment_at = -1
            for index, cell in enumerate(cells):
                label = _normalize_preview_text(cell.text).lower()
                if annex_at < 0 and _annex_key(label) == want:
                    annex_at = index
                if attachment_at < 0 and re.search(r"ioq\s*attachment\s*\d+", label):
                    attachment_at = index
            if annex_at < 0 or attachment_at < 0 or annex_at == attachment_at:
                continue
            target = None
            for index, cell in enumerate(cells):
                if index == annex_at or index == attachment_at:
                    continue
                target = cell
                break
            if target is None or not target.paragraphs:
                return False
            _clear_cell_checkbox_glyphs(target)
            _set_paragraph_checkbox_state(target.paragraphs[0], checked)
            return True
    return False


def _strip_checkboxes_from_identity_labels(doc: Document) -> int:
    """Remove a tick glued onto Document No. / Title / Equipment / Facility labels."""
    label_re = re.compile(
        r"document\s*no|document\s*title|equipment\s*/?\s*system|"
        r"facilit(?:y)?\s*/\s*section|effective\s*date|reference\s*document",
        re.I,
    )
    removed = 0
    tables = []
    if doc.sections and doc.sections[0].header is not None:
        tables.extend(doc.sections[0].header.tables)
    tables.extend(collect_docx_tables(doc))
    seen = set()
    for table in tables:
        tid = id(table._tbl)
        if tid in seen:
            continue
        seen.add(tid)
        for row in table.rows:
            for cell in _unique_row_cells(row):
                raw = cell.text or ""
                if not label_re.search(raw):
                    continue
                if raw == _strip_checkbox_glyphs(raw):
                    continue
                for para in cell.paragraphs:
                    changed = False
                    for run in para.runs:
                        if not run.text:
                            continue
                        cleaned = _strip_checkbox_glyphs(run.text)
                        if cleaned != run.text:
                            run.text = cleaned
                            changed = True
                    if changed:
                        removed += 1
    return removed


def _strip_leading_tick(text: str) -> str:
    return re.sub(r"^[\s\u2610\u2611\u2612\u2713\u2714☑☐✓✔□]+", "", text or "")


def _strip_title_and_serial_checkboxes(doc: Document) -> int:
    """Take a tick off the protocol title and off S. No. cells."""
    removed = 0
    title_re = re.compile(r"installation and operational qualification", re.I)
    roots = []
    for section in doc.sections:
        if section.header is not None:
            roots.append(section.header)
        if section.footer is not None:
            roots.append(section.footer)
    for root in roots:
        for para in root.paragraphs:
            if not title_re.search(para.text or ""):
                continue
            for run in para.runs:
                if not run.text:
                    continue
                cleaned = _strip_leading_tick(run.text)
                if cleaned != run.text:
                    run.text = cleaned
                    removed += 1
        for table in root.tables:
            for row in table.rows:
                for cell in _unique_row_cells(row):
                    if not title_re.search(cell.text or ""):
                        continue
                    for para in cell.paragraphs:
                        for run in para.runs:
                            if not run.text:
                                continue
                            cleaned = _strip_leading_tick(run.text)
                            if cleaned != run.text:
                                run.text = cleaned
                                removed += 1
    for table in collect_docx_tables(doc):
        if not table.rows:
            continue
        header_cells = _unique_row_cells(table.rows[0])
        serial_cols = [
            idx for idx, cell in enumerate(header_cells)
            if _is_serial_number_header(_normalize_preview_text(cell.text))
        ]
        if not serial_cols:
            continue
        for row in table.rows[1:]:
            cells = _unique_row_cells(row)
            for idx in serial_cols:
                if idx >= len(cells):
                    continue
                cell = cells[idx]
                raw = cell.text or ""
                if raw == _strip_checkbox_glyphs(raw):
                    continue
                if _checkbox_label_norm(raw):
                    continue
                for para in cell.paragraphs:
                    for run in para.runs:
                        if not run.text:
                            continue
                        cleaned = _strip_checkbox_glyphs(run.text)
                        if cleaned != run.text:
                            run.text = cleaned
                            removed += 1
    return removed


_CHECKBOX_GLYPH_RE = re.compile(r"[\u2610\u2611\u2612\u25a1\u25a0]")


def _is_protected_approval_tail(text: str | None) -> bool:
    """Lines that close Identification of Personnel and Training.

    A date picker or a hidden Sign/Date line must not delete them.
    Every document that has this block keeps all three lines.
    """
    norm = _normalize_preview_text(text).lower()
    if not norm:
        return False
    if (
        re.search(r"training\s*imparted", norm)
        and re.search(r"\byes\b", norm)
        and re.search(r"\bno\b", norm)
    ):
        return True
    if re.search(r"validation\s*/?\s*quality\s*assurance", norm):
        return True
    return bool(re.fullmatch(r"\(?\s*sign\s*/?\s*date\s*\)?", norm))


def _is_training_imparted_choice(text: str | None) -> bool:
    """'Training imparted : Yes □  No □' — one box after Yes, one after No."""
    norm = _checkbox_label_norm(text).lower()
    return bool(
        re.search(r"training\s*imparted", norm)
        and re.search(r"\byes\b", norm)
        and re.search(r"\bno\b", norm)
    )


def _find_training_imparted_choice(doc: Document):
    for paragraph in doc.paragraphs:
        if _is_training_imparted_choice(paragraph.text):
            return paragraph
    for table in collect_docx_tables(doc):
        for row in table.rows:
            for cell in _unique_row_cells(row):
                for paragraph in cell.paragraphs:
                    if _is_training_imparted_choice(paragraph.text):
                        return paragraph
    return None


def _set_training_imparted_choice(para: Paragraph, yes_checked: bool) -> None:
    """
    Check marks the Yes box and clears No.
    Uncheck marks the No box and clears Yes.
    The words Training imparted / Yes / No stay where they are.
    """
    yes_mark = "\u2611" if yes_checked else "\u2610"
    no_mark = "\u2610" if yes_checked else "\u2611"
    seen = 0
    for run in para.runs:
        if _run_has_image(run) or not run.text:
            continue
        if not _CHECKBOX_GLYPH_RE.search(run.text):
            continue
        chars: list[str] = []
        changed = False
        for char in run.text:
            if _CHECKBOX_GLYPH_RE.match(char) and seen < 2:
                chars.append(yes_mark if seen == 0 else no_mark)
                seen += 1
                changed = True
            else:
                chars.append(char)
        if changed:
            run.text = "".join(chars)
            _ensure_unicode_checkbox_font(run)
    if seen >= 2:
        return
    # Boxes were removed by an earlier save. Put them back after Yes and No.
    replaced = False
    for run in para.runs:
        if _run_has_image(run) or not run.text:
            continue
        new = run.text
        if re.search(r"\bYes\b", new) and "\u2610" not in new and "\u2611" not in new:
            new = re.sub(r"(\bYes\b)", r"\1 " + yes_mark, new, count=1)
        if re.search(r"\bNo\b", new) and new.count("\u2610") + new.count("\u2611") < 2:
            new = re.sub(r"(\bNo\b)", r"\1 " + no_mark, new, count=1)
        if new != run.text:
            run.text = new
            _ensure_unicode_checkbox_font(run)
            replaced = True
    if not replaced and para.runs:
        tail = para.runs[-1]
        tail.text = (tail.text or "") + " " + yes_mark + " " + no_mark
        _ensure_unicode_checkbox_font(tail)


def _apply_checkbox_ops(doc: Document, targets: list, checkbox_ops: list[dict]) -> int:
    """Toggle checklist / Yes-NA cells to ☑ / ☐ (Unicode), including drawing/pict hosts."""
    if not checkbox_ops:
        return 0

    applied = 0
    for op in checkbox_ops:
        if not isinstance(op, dict):
            continue
        checked = bool(op.get("checked"))
        label = str(op.get("original_text") or "")
        if _is_training_imparted_choice(label):
            para = _find_training_imparted_choice(doc)
            if para is None:
                para = _resolve_checkbox_paragraph(doc, targets, op)
            if para is not None:
                _set_training_imparted_choice(para, checked)
                applied += 1
            continue
        anchor = str(op.get("row_anchor") or "").strip()
        if anchor:
            if _apply_annex_anchor_checkbox(doc, anchor, checked):
                applied += 1
            continue
        para = None
        if op.get("abs_row"):
            try:
                table_index = int(op.get("table"))
                row_index = int(op.get("row"))
                col_index = int(op.get("col"))
            except (TypeError, ValueError):
                table_index = row_index = col_index = -1
            tables = collect_docx_tables(doc)
            if 0 <= table_index < len(tables) and 0 <= row_index < len(tables[table_index].rows):
                cells = _unique_row_cells(tables[table_index].rows[row_index])
                if 0 <= col_index < len(cells) and cells[col_index].paragraphs:
                    expected_norm = _checkbox_label_norm(str(op.get("original_text") or ""))
                    candidate = cells[col_index].paragraphs[0]
                    if _glyph_checkbox_target_ok(candidate, expected_norm):
                        para = candidate
        if para is None:
            para = _resolve_checkbox_paragraph(doc, targets, op)
        if para is None:
            continue
        header = _paragraph_table_header(para)
        if _is_annex_or_attachment_name_header(header) or _is_serial_number_header(header):
            continue
        if _paragraph_zone(para) in ("header", "footer"):
            continue
        _set_paragraph_checkbox_state(para, checked)
        applied += 1

    return applied


def _bump_revision_token(value: str) -> str | None:
    """Increment a revision token like 00→01, 1→2, Rev.01→Rev.02.

    A blank Revision No. stays blank. Do not invent 01 on save.
    """
    raw = (value or "").strip()
    if not raw:
        return None
    m = re.search(r"(\d+)\s*$", raw)
    if not m:
        return None
    num = m.group(1)
    width = len(num)
    nxt = str(int(num) + 1).zfill(width)
    return raw[: m.start(1)] + nxt


def _increment_revision_number(doc: Document) -> bool:
    """
    Auto-increment Revision No. on save.
    Finds a label cell (Revision No / Rev. No) and bumps the adjacent value cell,
    or the first data cell under a Revision No. column in revision-history tables.
    """
    tables = collect_docx_tables(doc)
    label_re = re.compile(r"^revision\s*no\.?$|^rev\.?\s*no\.?$", re.I)

    for table in tables:
        for row in table.rows:
            cells = _unique_row_cells(row)
            for col, cell in enumerate(cells):
                text = _normalize_preview_text(cell.text)
                if not label_re.match(text):
                    continue
                # Value in next cell on same row
                if col + 1 < len(cells):
                    nxt = cells[col + 1]
                    bumped = _bump_revision_token(_normalize_preview_text(nxt.text))
                    if bumped is not None:
                        _set_cell_text_preserve(nxt, bumped)
                        return True
                # Or value below in next row, same column
                # (handled by column-header path below)

    # Revision History style: header row contains "Revision No."
    for table in tables:
        rows = list(table.rows)
        if not rows:
            continue
        header_cells = _unique_row_cells(rows[0])
        rev_col = -1
        for i, cell in enumerate(header_cells):
            t = _normalize_preview_text(cell.text)
            if label_re.search(t) or re.search(r"revision\s*no", t, re.I):
                rev_col = i
                break
        if rev_col < 0:
            continue
        # Only a number the user already typed is incremented.
        # An empty Revision No. cell must stay empty.
        last_filled = None
        for row in rows[1:]:
            cells = _unique_row_cells(row)
            if rev_col >= len(cells):
                continue
            val = _normalize_preview_text(cells[rev_col].text)
            if val:
                last_filled = cells[rev_col]
        target = last_filled
        if target is None:
            continue
        bumped = _bump_revision_token(_normalize_preview_text(target.text))
        if bumped is not None:
            _set_cell_text_preserve(target, bumped)
            return True

    return False


def _enclosing_table_element(element):
    """Nearest w:tbl around this paragraph, if the paragraph sits inside a cell."""
    node = element
    table_el = None
    while node is not None:
        if node.tag == qn("w:tbl"):
            table_el = node
        if node.tag in (qn("w:body"), qn("w:hdr"), qn("w:ftr")):
            break
        node = node.getparent()
    return table_el


def _keep_pasted_tick(text: str) -> str:
    """Symbol-font tick is stored as Ö / private-use F0D6. Write the real √."""
    if not text:
        return text
    return (
        text.replace("\uf0d6", "\u221a")
        .replace("(\u00d6)", "(\u221a)")
        .replace("(\u00f6)", "(\u221a)")
    )


def _match_paragraph_above(new_p, above_p) -> None:
    """A paragraph added under another one keeps that paragraph's indent."""
    if new_p is None or above_p is None or above_p.tag != qn("w:p"):
        return
    src_ppr = above_p.find(qn("w:pPr"))
    if src_ppr is None:
        return
    dest_ppr = new_p.find(qn("w:pPr"))
    if dest_ppr is None:
        dest_ppr = OxmlElement("w:pPr")
        new_p.insert(0, dest_ppr)

    src_ind = src_ppr.find(qn("w:ind"))
    if src_ind is not None:
        ind = deepcopy(src_ind)
        # A numbered heading hangs its number. The new line should start
        # where that heading's words start, not where the number sits.
        if src_ppr.find(qn("w:numPr")) is not None:
            for attr in ("w:hanging", "w:firstLine"):
                key = qn(attr)
                if key in ind.attrib:
                    del ind.attrib[key]
        existing = dest_ppr.find(qn("w:ind"))
        if existing is not None:
            dest_ppr.remove(existing)
        dest_ppr.append(ind)
    elif (src_ppr.find(qn("w:pStyle")) is not None
          and (src_ppr.find(qn("w:pStyle")).get(qn("w:val")) or "") == "ListParagraph"):
        ind = OxmlElement("w:ind")
        ind.set(qn("w:left"), "720")
        existing = dest_ppr.find(qn("w:ind"))
        if existing is not None:
            dest_ppr.remove(existing)
        dest_ppr.append(ind)

    src_jc = src_ppr.find(qn("w:jc"))
    if src_jc is not None:
        existing = dest_ppr.find(qn("w:jc"))
        if existing is not None:
            dest_ppr.remove(existing)
        dest_ppr.append(deepcopy(src_jc))


def _new_plain_body_paragraph(text: str):
    """
    A paragraph added on the page.

    Do not copy the title or a table cell: those are centered and have no gap,
    so paragraph 3 appeared above paragraph 1 and the lines sat on top of each other.
    Left aligned, with space before and after, and the user's line breaks kept.
    """
    paragraph = OxmlElement("w:p")
    p_pr = OxmlElement("w:pPr")
    spacing = OxmlElement("w:spacing")
    spacing.set(qn("w:before"), "160")
    spacing.set(qn("w:after"), "160")
    spacing.set(qn("w:line"), "276")
    spacing.set(qn("w:lineRule"), "auto")
    p_pr.append(spacing)
    jc = OxmlElement("w:jc")
    jc.set(qn("w:val"), "left")
    p_pr.append(jc)
    paragraph.append(p_pr)

    lines = _keep_pasted_tick(str(text)).replace("\r\n", "\n").replace("\r", "\n").split("\n")
    for index, line in enumerate(lines):
        run = OxmlElement("w:r")
        r_pr = OxmlElement("w:rPr")
        fonts = OxmlElement("w:rFonts")
        for attr in ("ascii", "hAnsi", "cs", "eastAsia"):
            fonts.set(qn(f"w:{attr}"), "Arial")
        r_pr.append(fonts)
        size = OxmlElement("w:sz")
        size.set(qn("w:val"), "22")
        r_pr.append(size)
        size_cs = OxmlElement("w:szCs")
        size_cs.set(qn("w:val"), "22")
        r_pr.append(size_cs)
        run.append(r_pr)
        text_el = OxmlElement("w:t")
        if line[:1].isspace() or line[-1:].isspace():
            text_el.set("{http://www.w3.org/XML/1998/namespace}space", "preserve")
        text_el.text = line
        run.append(text_el)
        if index < len(lines) - 1:
            run.append(OxmlElement("w:br"))
        paragraph.append(run)
    return paragraph


def _reuse_following_paragraph(anchor, text: str, previous_text: str, parent) -> bool:
    """A second autosave of the same added line updates it instead of inserting again."""
    prev = _normalize_preview_text(previous_text)
    if not prev or anchor is None:
        return False
    nxt = anchor.getnext()
    if nxt is None or not str(getattr(nxt, "tag", "")).endswith("}p"):
        return False
    para = Paragraph(nxt, parent)
    have = _normalize_preview_text(para.text)
    new_norm = _normalize_preview_text(text)
    if have == new_norm:
        return True
    if have == prev:
        _set_paragraph_text(para, text)
        return True
    return False


def _apply_structure_ops(doc: Document, targets: list, structure_ops: list[dict]) -> int:
    """Insert a new paragraph (optionally bulleted) after a mapped target paragraph."""
    if not structure_ops:
        return 0

    applied = 0
    ops = [op for op in structure_ops if isinstance(op, dict)]
    # Page paragraphs stay in the order the user typed (1st, then 2nd, then 3rd).
    # Inserting each one on the same line with addnext was reversing that order.
    outside_ops = [op for op in ops if op.get("outside_table") and not op.get("toc_insert")]
    # Contents lines keep the order they were added and stay under the line above them.
    toc_ops = [op for op in ops if op.get("toc_insert")]
    inside_ops = [op for op in ops if not op.get("outside_table") and not op.get("toc_insert")]
    chain_after: dict[int, object] = {}

    for op in outside_ops + toc_ops + inside_ops:
        try:
            after_index = int(op.get("after_index"))
        except (TypeError, ValueError):
            continue
        if after_index < 0 or after_index >= len(targets):
            continue

        kind, obj, _zone = targets[after_index]
        if kind == "para":
            base_para = obj
        elif kind == "cell":
            paras = [p for p in obj.paragraphs if not _para_is_image_only(p)]
            base_para = paras[-1] if paras else (obj.paragraphs[-1] if obj.paragraphs else None)
        else:
            continue
        if base_para is None:
            continue

        text = op.get("text")
        if text is None:
            text = ""
        text = _normalize_text_value(str(text)).replace("\u00a0", " ")
        text = text.replace("\r\n", "\n").replace("\r", "\n").strip("\n")
        if not text.strip():
            continue

        anchor = base_para._element
        if op.get("toc_insert"):
            # Keep a new contents line under the line the user typed it under.
            placed = _find_paragraph_by_preview_text(doc, str(op.get("after_text") or ""))
            if placed is not None:
                anchor = placed._element
            cursor = chain_after.get(id(anchor), anchor)
            source = placed._element if placed is not None else anchor
            new_el = deepcopy(source)
            new_para = Paragraph(new_el, base_para._parent)
            _clear_paragraph_text_keep_images(new_para)
            _remove_paragraph_numbering(new_para)
            _set_paragraph_text(new_para, text)
            cursor.addnext(new_el)
            chain_after[id(anchor)] = new_el
            applied += 1
            continue

        if op.get("outside_table"):
            # Keep the new line under the paragraph the user added it after.
            # Walking back to any earlier body line was parking a 7.7 paragraph
            # under the next heading (8.0 OPERATIONAL CHECKS).
            placed = _find_paragraph_by_preview_text(doc, str(op.get("after_text") or ""))
            if placed is not None and _enclosing_table_element(placed._element) is None and not _is_toc_slot_text(placed.text):
                anchor = placed._element
            elif (
                base_para is not None
                and _enclosing_table_element(base_para._element) is None
                and not _is_toc_slot_text(base_para.text)
            ):
                anchor = base_para._element
            else:
                body_para = None
                for idx in range(after_index, -1, -1):
                    kind_i, obj_i, zone_i = targets[idx]
                    if kind_i != "para" or zone_i == "header":
                        continue
                    if _enclosing_table_element(obj_i._element) is not None:
                        continue
                    if _is_toc_slot_text(obj_i.text):
                        continue
                    body_para = obj_i
                    break
                if body_para is not None:
                    anchor = body_para._element
                else:
                    table_el = _enclosing_table_element(anchor)
                    if table_el is not None:
                        anchor = table_el
            cursor = chain_after.get(id(anchor), anchor)
            if _reuse_following_paragraph(cursor, text, str(op.get("previous_text") or ""), base_para._parent):
                applied += 1
                continue
            new_el = _new_plain_body_paragraph(text)
            _match_paragraph_above(new_el, anchor if anchor.tag == qn("w:p") else None)
            cursor.addnext(new_el)
            chain_after[id(anchor)] = new_el
            applied += 1
            continue

        new_el = deepcopy(base_para._element)
        new_para = Paragraph(new_el, base_para._parent)
        _clear_paragraph_text_keep_images(new_para)
        # Never inherit Word section/list numbering — that creates ghost 7.0 lines
        # and shifts every following heading by +1.
        _remove_paragraph_numbering(new_para)

        is_bullet = (op.get("op") == "insert_bullet") or bool(op.get("list"))
        if is_bullet and text and not text.startswith(("•", "-", "☑", "☐")):
            if new_para._element.find(qn("w:numPr")) is None:
                text = "• " + text

        _set_paragraph_text(new_para, text)
        cursor = chain_after.get(id(anchor), anchor)
        if _reuse_following_paragraph(cursor, text, str(op.get("previous_text") or ""), base_para._parent):
            applied += 1
            continue
        cursor.addnext(new_el)
        chain_after[id(anchor)] = new_el
        applied += 1

    return applied


def _header_row_is_letterhead_label_row(row) -> bool:
    blob = " ".join(
        _normalize_preview_text(c.text).lower() for c in _unique_row_cells(row)
    )
    if not blob:
        return False
    return bool(
        re.search(
            r"document\s*(no|title|number)|reference\s*document|equipment\s*/?\s*system|"
            r"equipment\s*(name|id)|facilit(?:y)?\s*/?\s*section|effective\s*date|\bversion\b",
            blob,
        )
    )


def _row_xml_text(row) -> str:
    parts = []
    for node in row._tr.findall(".//" + qn("w:t")):
        if node.text:
            parts.append(node.text)
    return _normalize_preview_text(" ".join(parts))


def _strip_body_sentences_from_header(doc: Document) -> int:
    """A body sentence saved into the page header is repeated at the top of every page."""
    body_norms = set()
    for para in doc.paragraphs:
        norm = _normalize_preview_text(para.text).lower()
        if len(norm) >= 40:
            body_norms.add(norm)
    removed = 0
    for section in doc.sections:
        header = section.header
        if header is None:
            continue
        for para in list(header.paragraphs):
            norm = _normalize_preview_text(para.text).lower()
            if len(norm) < 40:
                continue
            if norm not in body_norms and "if the document is provided" not in norm:
                continue
            element = para._element
            parent = element.getparent()
            if parent is not None:
                parent.remove(element)
                removed += 1
    return removed


def _dedupe_consecutive_body_paragraphs(doc: Document) -> int:
    """Identical long paragraphs stacked by repeated saves collapse to the first copy."""
    removed = 0
    body = doc.element.body
    previous = None
    for child in list(body):
        if child.tag != qn("w:p"):
            previous = None
            continue
        text = _normalize_preview_text(
            "".join((node.text or "") for node in child.findall(".//" + qn("w:t")))
        ).lower()
        # Long repeats and short ones ("dgdfgfd2" stacked twice under a heading).
        # A numbered section title is kept even when the same number appears again.
        repeated = bool(text) and text == previous and (
            len(text) >= 40 or (len(text) >= 4 and not re.match(r"^\d+(?:\.\d+)+\s+\S", text))
        )
        if repeated:
            parent = child.getparent()
            if parent is not None:
                parent.remove(child)
                removed += 1
            continue
        previous = text or None
    return removed


def _strip_foreign_header_rows(doc: Document) -> int:
    """Drop body values that were saved into the repeating page heading."""
    removed = 0
    header = doc.sections[0].header if doc.sections else None
    if header is None:
        return 0
    for table in list(header.tables):
        drop = []
        for row in list(table.rows):
            try:
                blob = _row_xml_text(row).lower()
            except Exception:
                continue
            if not blob:
                continue
            if row._tr.find(".//{http://schemas.openxmlformats.org/drawingml/2006/main}blip") is not None:
                continue
            if row._tr.find(".//{http://schemas.openxmlformats.org/wordprocessingml/2006/main}drawing") is not None:
                continue
            if re.search(
                r"document\s*(no|title|number)|reference\s*document|equipment|"
                r"facilit(?:y)?|effective\s*date|\bversion\b|"
                r"\blupin\b|\bannex\b|attachment|\bioq\b|qualification",
                blob,
            ):
                continue
            drop.append(row._tr)
        for tr in drop:
            parent = tr.getparent()
            if parent is not None:
                parent.remove(tr)
                removed += 1
    return removed


def _toc_entry_key(text: str | None) -> str:
    """Section plus title, without dot leaders or the page number."""
    plain = _normalize_preview_text(text)
    plain = re.sub(r"[.\u2026·]{2,}", " ", plain)
    plain = re.sub(r"\s+", " ", plain).strip().lower()
    plain = re.sub(r"\s+\d{1,3}$", "", plain)
    return plain.strip()


def _toc_leader_parts(text: str) -> tuple[str, str, str]:
    """Split a contents line into the words, the dot leader, and the page number."""
    raw = text or ""
    match = re.search(r"([.\u2026·]{3,})\s*(\d{1,3})\s*$", raw)
    if not match:
        return raw.rstrip(), "", ""
    return raw[: match.start()].rstrip(), match.group(1), match.group(2)


def _toc_leader_width(leader: str) -> int:
    """One ellipsis is about as wide as three periods, which is how 7.0 and 8.0 line up."""
    return sum(3 if ch == "\u2026" else 1 for ch in leader)


def _toc_column_width(sample_text: str) -> int:
    """Width of a real contents line, so a new line's page number ends in the same place."""
    prefix, leader, _page = _toc_leader_parts(sample_text)
    if not leader:
        return 108
    return len(prefix) + 1 + _toc_leader_width(leader)


def _toc_bold_run_format(paragraphs) -> object | None:
    """The contents lines are bold. A regular run is the same point size but looks smaller."""
    for para in paragraphs or []:
        rpr = _first_text_run_rpr(para)
        if rpr is not None and rpr.find(qn("w:b")) is not None:
            return rpr
    return None


def _stamp_toc_run_format(para: Paragraph, sample_rpr) -> bool:
    """Put the 7.0 / 8.0 bold onto a line that was saved as regular text."""
    if sample_rpr is None or para is None:
        return False
    changed = False
    for run_el in para._element.findall(qn("w:r")):
        if run_el.find(qn("w:t")) is None:
            continue
        existing = run_el.find(qn("w:rPr"))
        if existing is not None and existing.find(qn("w:b")) is not None:
            continue
        if existing is not None:
            run_el.remove(existing)
        run_el.insert(0, deepcopy(sample_rpr))
        changed = True
    return changed


def _format_toc_line(section: str, title: str, page: str, sample_text: str = "") -> str:
    title = re.sub(r"[.\u2026·]{2,}", " ", _normalize_preview_text(title))
    title = re.sub(r"\s+\d{1,3}$", "", title).strip()
    section = _normalize_preview_text(section)
    page = _normalize_preview_text(page)
    prefix = f"{section}  {title}".rstrip()
    if not page:
        return prefix
    # Match the 7.0 / 8.0 leader. Short ASCII dots stop before their page number.
    need = _toc_column_width(sample_text) - len(prefix) - 1
    if need < 9:
        need = 9
    leader = ("\u2026" * (need // 3)) + ("." * (need % 3))
    return f"{prefix} {leader}{page}"


def _replace_trailing_page(para: Paragraph, page: str) -> bool:
    nodes = para._element.findall(".//" + qn("w:t"))
    if not nodes or not re.fullmatch(r"\d{1,3}", page or ""):
        return False
    last = nodes[-1]
    current = last.text or ""
    updated = re.sub(r"\d{1,3}\s*$", page, current)
    if updated == current:
        return False
    last.text = updated
    return True


def _find_toc_table(doc: Document):
    """The contents list is one table. Extra 7.1 lines above it are not part of it."""
    best = None
    best_hits = 0
    for table in doc.tables:
        hits = 0
        for row in table.rows:
            for cell in _unique_row_cells(row):
                if _is_toc_slot_text(cell.text) or re.match(
                    r"^\d+\.\d+", _normalize_preview_text(cell.text)
                ):
                    hits += 1
        if hits > best_hits:
            best = table
            best_hits = hits
    if best is None or best_hits < 4:
        return None
    return best


def _toc_table_paragraphs(table: Table) -> list[Paragraph]:
    found: list[Paragraph] = []
    seen: set[int] = set()
    for row in table.rows:
        for cell in _unique_row_cells(row):
            for para in cell.paragraphs:
                if id(para._element) in seen:
                    continue
                seen.add(id(para._element))
                if _normalize_preview_text(para.text):
                    found.append(para)
    return found


def _delete_stray_toc_paragraphs(doc: Document, toc_table: Table) -> int:
    """
    Drop contents lines that were saved above the contents table.

    Those are the deleted 'abc history' lines. The list the user sees
    is the table, so a line they removed must not stay above it.
    """
    body = doc.element.body
    table_el = toc_table._tbl
    heading_seen = False
    removed = 0
    for child in list(body):
        if child is table_el:
            break
        if child.tag != qn("w:p"):
            continue
        text = _normalize_preview_text(
            "".join(node.text or "" for node in child.findall(".//" + qn("w:t")))
        )
        if re.search(r"table\s+of\s+contents", text, re.I):
            heading_seen = True
            continue
        if not heading_seen:
            continue
        if _is_toc_slot_text(text) or re.match(r"^\d+\.\d+", text or ""):
            body.remove(child)
            removed += 1
    return removed


def _sync_toc_entries(doc: Document, lines: list | None) -> int:
    """
    Make the contents table match the lines on screen, in that order.

    A line the user deleted is removed. A line they added stays under
    the contents line above it, not at the top of the list.
    """
    if not isinstance(lines, list) or len(lines) < 5:
        return 0
    table = _find_toc_table(doc)
    if table is None:
        return 0
    changed = _delete_stray_toc_paragraphs(doc, table)
    existing = _toc_table_paragraphs(table)
    if not existing:
        return changed
    sample_text = ""
    for para in existing:
        if "\u2026" in (para.text or ""):
            sample_text = para.text or ""
            if re.match(r"8\.0", _normalize_preview_text(para.text)):
                break
    sample_rpr = _toc_bold_run_format(existing)
    desired = [line for line in lines if isinstance(line, dict) and str(line.get("text") or "").strip()]
    if len(desired) < 5:
        return changed
    desired_keys = {_toc_entry_key(str(line.get("text") or "")) for line in desired}
    used: set[int] = set()
    cursor = None
    template = existing[0]._element
    for line in desired:
        raw = str(line.get("text") or "")
        key = _toc_entry_key(raw)
        match_at = None
        for index, para in enumerate(existing):
            if index in used:
                continue
            if key and _toc_entry_key(para.text) == key:
                match_at = index
                break
        if match_at is not None:
            used.add(match_at)
            para = existing[match_at]
            page = _normalize_preview_text(str(line.get("page") or ""))
            _prefix, leader, _stored_page = _toc_leader_parts(para.text or "")
            # Original lines already use the long ellipsis. Only a line saved
            # with short periods needs to be stretched to the 7.0 / 8.0 column.
            short_periods = bool(leader) and "\u2026" not in leader
            if not short_periods:
                if page and (
                    _apply_toc_page_edit(para, raw) or _replace_trailing_page(para, page)
                ):
                    changed += 1
            else:
                # The added line was saved with short periods. Stretch it to the 8.0 page column.
                _set_paragraph_text(
                    para,
                    _format_toc_line(
                        str(line.get("section") or ""),
                        str(line.get("title") or raw),
                        page,
                        sample_text,
                    ),
                )
                changed += 1
            if _stamp_toc_run_format(para, sample_rpr):
                changed += 1
            cursor = para._element
            continue
        new_el = deepcopy(template)
        new_para = Paragraph(new_el, existing[0]._parent)
        _clear_paragraph_text_keep_images(new_para)
        _remove_paragraph_numbering(new_para)
        _set_paragraph_text(
            new_para,
            _format_toc_line(
                str(line.get("section") or ""),
                str(line.get("title") or raw),
                str(line.get("page") or ""),
                sample_text,
            ),
        )
        _stamp_toc_run_format(new_para, sample_rpr)
        if cursor is not None:
            cursor.addnext(new_el)
        else:
            existing[0]._element.addprevious(new_el)
        cursor = new_el
        changed += 1
    for index, para in enumerate(existing):
        if index in used:
            continue
        if _toc_entry_key(para.text) in desired_keys:
            continue
        parent = para._element.getparent()
        if parent is not None:
            parent.remove(para._element)
            changed += 1
    return changed


def apply_indexed_edits_to_docx(
    docx_bytes: bytes,
    edits: list[dict],
    table_ops: list[dict] | None = None,
    cell_edits: list[dict] | None = None,
    checkbox_ops: list[dict] | None = None,
    structure_ops: list[dict] | None = None,
    toc_lines: list | None = None,
) -> tuple[bytes, dict]:
    doc = Document(io.BytesIO(docx_bytes))
    # Do not delete header rows here. Merged heading rows then crash the save
    # ("no tr above topmost tr") and the edited file is never stored.
    foreign_header_rows = _strip_body_sentences_from_header(doc)
    _dedupe_consecutive_body_paragraphs(doc)
    annex_boxes_removed = _strip_checkboxes_from_annex_name_cells(doc)
    identity_boxes_removed = _strip_checkboxes_from_identity_labels(doc)
    ghost_removed = _remove_ghost_numbered_paragraphs(doc)
    targets = collect_docx_text_targets(doc)
    toc_protected = _build_toc_protected_indexes(targets)

    applied = 0
    skipped = 0
    rejected = 0
    remapped = 0
    unchanged = 0
    redirected = 0
    label_blocked = 0
    written: set[int] = set()
    # A cleared or contents line is finished. A later edit must not write it back.
    settled: set[int] = set()
    if structure_ops is None:
        structure_ops = []

    for edit in edits or []:
        if edit.get("toc_page"):
            if _apply_toc_page_by_title(doc, edit):
                applied += 1
            else:
                label_blocked += 1
            continue
        if edit.get("index") is None:
            skipped += 1
            continue

        try:
            requested_index = int(edit.get("index"))
        except (TypeError, ValueError):
            skipped += 1
            continue

        new_preview = _normalize_preview_text(_edit_new_text(edit))
        expected_preview = _normalize_preview_text(edit.get("original_text") or "")
        toc_edit = _is_toc_slot_text(new_preview) or _same_toc_entry(expected_preview, new_preview)

        if requested_index in toc_protected and not toc_edit:
            label_blocked += 1
            continue

        resolved = _resolve_target_index(targets, edit)
        if resolved is None:
            rejected += 1
            continue

        if resolved in toc_protected and not toc_edit:
            label_blocked += 1
            continue

        if resolved != requested_index:
            remapped += 1

        # Keep a body sentence on its own paragraph. A shifted index must not
        # drop section 11 / utilities text into the contact-surface table.
        if (
            expected_preview
            and not edit.get("clear")
            and not edit.get("toc_line")
            and not edit.get("toc_page")
        ):
            retargeted = _retarget_shifted_paragraph(
                targets, resolved, expected_preview, new_preview
            )
            if retargeted is None:
                rejected += 1
                continue
            if retargeted != resolved:
                remapped += 1
                resolved = retargeted

        new_text = _edit_new_text(edit)
        kind, obj, zone = targets[resolved]
        if resolved in settled:
            skipped += 1
            continue
        edit_zone = str(edit.get("zone") or "").strip().lower()
        # A body paragraph must not be written into the repeating page heading.
        if zone in ("header", "footer") and edit_zone == "body":
            label_blocked += 1
            continue
        if zone in ("header", "footer") and len(_normalize_preview_text(new_text)) > 160:
            label_blocked += 1
            continue
        if kind == "para" and _para_has_numbering(obj):
            new_text = _strip_preview_list_prefix(new_text, obj.text or "")
        old_norm = _get_target_text(kind, obj)
        new_norm = _normalize_preview_text(new_text)

        expected = edit.get("original_text")
        expected_norm = (
            _normalize_preview_text(expected) if expected is not None else old_norm
        )

        # Deleted text must not come back on the next open.
        wants_clear = bool(edit.get("clear"))
        if wants_clear:
            if zone in ("header", "footer"):
                label_blocked += 1
                continue
            # A training date picker replaces the paragraph inside the cell.
            # That is not a delete. Cell edits write the date; the name,
            # department, signature, and training text must stay.
            if kind == "para" and _para_is_inside_table(obj):
                skipped += 1
                continue
            if _is_protected_approval_tail(old_norm) or _is_protected_approval_tail(
                expected if expected is not None else ""
            ):
                skipped += 1
                continue
            _clear_user_removed_text(kind, obj)
            written.add(resolved)
            settled.add(resolved)
            applied += 1
            continue

        # A contents line stays on the same row. Only its page number changes.
        # Rewriting the whole paragraph dropped the tabs, the link, and the
        # font, and the preview then treated the line as body text.
        if edit.get("toc_line") and kind == "para":
            if _apply_toc_page_edit(obj, new_text):
                written.add(resolved)
                settled.add(resolved)
                applied += 1
            else:
                skipped += 1
            continue

        # Model No. / Serial No. / Purchase Order etc. are one-line "Label: value"
        # paragraphs. Never redirect their typed value into the next empty Word slot —
        # that left labels blank and dumped values into unrelated blanks (redirected: 5).
        # Adjacent empty *table cells* are handled in _apply_cell_edits instead.
        extra = _extract_typed_value_from_label(old_norm, new_norm)
        if not extra and expected_norm:
            extra = _extract_typed_value_from_label(expected_norm, new_norm)
        if extra:
            if resolved not in toc_protected and not _is_toc_slot_text(old_norm):
                # Keep full "Label: value" on the same paragraph/cell slot.
                _set_target_text(kind, obj, new_text)
                written.add(resolved)
                applied += 1
            else:
                label_blocked += 1
            continue

        # Unchanged when both collapsed form and raw multiline text match.
        if kind == "para":
            old_raw = (obj.text or "").replace("\u00a0", " ").replace("\r\n", "\n").replace("\r", "\n")
        elif kind == "cell":
            old_raw = (obj.text or "").replace("\u00a0", " ").replace("\r\n", "\n").replace("\r", "\n")
        else:
            old_raw = old_norm
        if old_norm == new_norm and old_raw == new_text:
            unchanged += 1
            continue

        # A Table of Contents line may be edited (page number). Keep that
        # paragraph. Still refuse body text written into a TOC line, or a TOC
        # line written into another field.
        old_toc = _is_toc_slot_text(old_norm) or _is_toc_slot_text(expected_norm or "")
        new_toc = _is_toc_slot_text(new_text)
        same_toc = _same_toc_entry(old_norm, new_norm) or _same_toc_entry(expected_norm or "", new_norm)
        if same_toc and kind == "para" and _apply_toc_page_edit(obj, new_text):
            written.add(resolved)
            applied += 1
            continue
        if old_toc and new_toc:
            pass
        elif old_toc or new_toc:
            label_blocked += 1
            continue

        # Block only short form-label → short form-label chrome swaps.
        # Body/prose lines (incl. "… condition/s:") must still save.
        old_is_label = _looks_like_label_slot(old_norm) or _looks_like_label_slot(
            expected_norm or ""
        )
        if old_is_label and _looks_like_label_slot(new_norm):
            label_blocked += 1
            continue

        # Never dump Model No. / Serial No. body labels into table cells (S. No.,
        # Annexure Yes/NA, etc.) when the mapped index points at the wrong slot.
        if kind == "para" and _para_is_inside_table(obj):
            if _is_identity_field_label(new_norm) and not _is_identity_field_label(old_norm):
                label_blocked += 1
                continue
        if kind == "cell":
            if _is_identity_field_label(new_norm) and not _is_identity_field_label(old_norm):
                label_blocked += 1
                continue

        # Index 61 in this file is an empty line inside the check table.
        # Body text typed on the page was saved there and also shifted the contents list.
        # Keep that text as a paragraph above the list, not inside the table.
        if kind == "para" and _para_is_inside_table(obj) and old_norm == "" and new_norm != "":
            # A contents line typed into its own row stays there.
            # Other page text must not fall into an empty check-table cell.
            if _is_toc_slot_text(new_text):
                _set_target_text(kind, obj, new_text)
                written.add(resolved)
                settled.add(resolved)
                applied += 1
                continue
            structure_ops.append({
                "op": "insert_paragraph",
                "after_index": resolved,
                "text": new_text,
                "outside_table": 1,
            })
            label_blocked += 1
            continue

        if not new_norm and (
            _is_protected_approval_tail(old_norm)
            or _is_protected_approval_tail(expected_norm)
        ):
            skipped += 1
            continue
        _set_target_text(kind, obj, new_text)
        written.add(resolved)
        applied += 1

    cells_applied = _apply_cell_edits(doc, cell_edits or [])
    # The contents list is rewritten from the screen after other inserts,
    # so a new contents line is not also copied above the list.
    structure_for_apply = structure_ops or []
    if toc_lines:
        structure_for_apply = [op for op in structure_for_apply if not op.get("toc_insert")]
    structure_applied = _apply_structure_ops(doc, targets, structure_for_apply)
    toc_synced = _sync_toc_entries(doc, toc_lines)
    # Re-collect after structure inserts is not required for table ops on original tables.
    rows_inserted, rows_deleted = _apply_table_ops(doc, table_ops or [])
    # Checkboxes last so text/cell/structure writes cannot leave stale empty drawings.
    checkbox_applied = _apply_checkbox_ops(doc, targets, checkbox_ops or [])
    _strip_title_and_serial_checkboxes(doc)
    foreign_header_rows += _strip_body_sentences_from_header(doc)
    _dedupe_consecutive_body_paragraphs(doc)
    # Restore verified-by / section banners that were split into column cells.
    banner_merges_repaired = _repair_component_matrix_banner_merges(doc)
    safety_by_merges_repaired = _repair_safety_by_row_merges(doc)
    _repair_exploded_full_width_cells(doc)
    serial_autonumber_removed = _strip_manual_serial_autonumber(doc)
    empty_gap_rows_removed = _drop_empty_component_rows_between_content(doc)
    serial_style_fixed = _align_serial_number_cells(doc)
    # Word download ignores preview CSS. Keep original columns; wrap inside them.
    cells_wrapped = _keep_filled_text_inside_cells(doc)
    _join_split_esign_dates(doc)
    _normalize_header_table_fonts(doc, font_name="Arial", font_size_pt=12.0)

    total_applied = (
        applied
        + cells_applied
        + checkbox_applied
        + structure_applied
        + toc_synced
        + rows_inserted
        + rows_deleted
        + ghost_removed
        + foreign_header_rows
        + annex_boxes_removed
        + identity_boxes_removed
        + banner_merges_repaired
        + safety_by_merges_repaired
        + serial_autonumber_removed
        + serial_style_fixed
    )
    # Do not invent or increment Revision No. on save.
    # A blank history cell was becoming 01, then 02, then 03 on later saves.
    revision_bumped = False

    out = io.BytesIO()
    doc.save(out)

    return out.getvalue(), {
        "targets": len(targets),
        "edits": len(edits or []),
        "applied": total_applied + (1 if revision_bumped else 0),
        "cells_applied": cells_applied,
        "checkbox_applied": checkbox_applied,
        "structure_applied": structure_applied,
        "toc_synced": toc_synced,
        "ghost_paras_removed": ghost_removed,
        "unchanged": unchanged,
        "skipped": skipped,
        "rejected": rejected,
        "remapped": remapped,
        "redirected": redirected,
        "label_blocked": label_blocked,
        "rows_inserted": rows_inserted,
        "rows_deleted": rows_deleted,
        "revision_bumped": revision_bumped,
    }
