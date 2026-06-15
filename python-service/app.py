from fastapi import FastAPI, UploadFile, File, HTTPException, Form
from dotenv import load_dotenv
from doc_parser import (
    parse_docx,
    PARA_BLANK_RE,
    PARA_COLON_BLANK_RE,
    dedupe_colon_label,
    has_paragraph_blank,
    collect_paragraph_texts,
    iter_unique_textboxes,
    is_border_blank_paragraph,
    is_checkbox_cell,
    is_yes_no_radio_cell,
    is_paragraph_yes_no_radio,
    paragraph_yes_no_label,
    is_list_of_attachment_table,
    is_report_approval_block_table,
    get_table_column_headers,
    resolve_sl_no_column_index,
    infer_field_widget,
    is_report_number_label,
    is_date_field_label,
    element_has_drawing_checkbox,
    is_toc_table,
    is_toc_list_table,
    is_toc_entry_text,
)
from report_generator import fill_docx, _normalize_text_value
from pydantic import BaseModel, field_validator
from typing import List, Optional, Any
import json
import logging
import os
import re
import shutil
import uuid
import subprocess

logger = logging.getLogger("valiflow.docx")


load_dotenv()

# ── Pydantic Models ──────────────────────────
# FieldValue  — single field submission for /fill
# FillRequest — full /fill payload: file_id + fields list
class FieldValue(BaseModel):
    location: str
    value: str
    locked: bool = False
    type: str = ""

class FillRequest(BaseModel):
    file_id: str
    original_filename: str
    fields: List[FieldValue]
    attachment_links: Optional[dict[str, Any]] = None

    @field_validator("attachment_links", mode="before")
    @classmethod
    def coerce_attachment_links(cls, value):
        """Laravel may JSON-encode an empty PHP array as [] instead of {}."""
        if value is None:
            return {}
        if isinstance(value, list):
            return {} if len(value) == 0 else dict(value)
        return value

app = FastAPI(title="Valiflow DOCX Service", version="1.0.0")

UPLOAD_PATH = os.getenv("UPLOAD_PATH",
    r"D:\lupin\valiflow-main (3)\valiflow-main\storage\app\public\templates\docx")
GENERATED_REPORTS_PATH = os.getenv("GENERATED_REPORTS_PATH",
    r"D:\lupin\valiflow-main (3)\valiflow-main\storage\app\public\generated_reports")
ATTACHMENTS_PATH = os.getenv("ATTACHMENTS_PATH",
    r"D:\lupin\valiflow-main (3)\valiflow-main\storage\app\public\attachments")

LIBREOFFICE = r"C:\Program Files\LibreOffice\program\soffice.exe"


# ── convert_doc_to_docx() ───────────────────────────
# Converts a legacy .doc file to .docx using LibreOffice headless.
# Parameters: doc_path — absolute path to the uploaded .doc file
# Returns: absolute path to the generated .docx in the same folder
# Key steps:
#   1. Verifies LibreOffice (soffice.exe) is installed
#   2. Runs --headless --convert-to docx into the source directory
#   3. Returns the .docx path or raises if conversion fails
def convert_doc_to_docx(doc_path: str) -> str:
    if not os.path.exists(LIBREOFFICE):
        raise Exception("LibreOffice install nahi hai!")

    output_dir = os.path.dirname(doc_path)

    result = subprocess.run([
        LIBREOFFICE,
        "--headless",
        "--convert-to", "docx",
        "--outdir", output_dir,
        doc_path
    ], capture_output=True, text=True, timeout=60)

    docx_path = doc_path.replace(".doc", ".docx")

    if not os.path.exists(docx_path):
        raise Exception(f"Conversion failed: {result.stderr}")

    return docx_path


# ── root() ──────────────────────────────────────────
# Simple health/root endpoint to confirm the service is running.
# Returns: JSON {status, service}
@app.get("/")
def root():
    return {"status": "running", "service": "Valiflow Python Microservice"}


# ── health() ────────────────────────────────────────
# Detailed health check with configured storage paths and LibreOffice status.
# Returns: JSON with status, upload_path, generated_reports_path, etc.
@app.get("/health")
def health():
    return {
        "status": "ok",
        "preview_renderer": "v5-fill-fixes",
        "upload_path": UPLOAD_PATH,
        "generated_reports_path": GENERATED_REPORTS_PATH,
        "attachments_path": ATTACHMENTS_PATH,
        "libreoffice": os.path.exists(LIBREOFFICE),
    }


# ── parse_document() ────────────────────────────────
# POST /parse — upload a DOC/DOCX, detect fillable fields, save template copy.
# Parameters: file — UploadFile from multipart form (field name must be "file")
# Returns: JSON with file_id, fields list, summary counts, paragraphs metadata
# Key steps:
#   1. Validates .doc or .docx extension
#   2. Saves file as {uuid}.docx or {uuid}.doc under UPLOAD_PATH
#   3. Converts .doc to .docx via LibreOffice if needed
#   4. Calls parse_docx() on the saved file (body tables/paragraphs only)
#   5. Returns file_id UUID and fillable_fields for Laravel to store on report
@app.post("/parse")
async def parse_document(file: UploadFile = File(...)):
    if not file.filename.endswith(('.docx', '.doc')):
        raise HTTPException(
            status_code=400,
            detail="allwed only .doc ya .docx file"
        )

    file_id = str(uuid.uuid4())
    file_ext = os.path.splitext(file.filename)[1]
    save_filename = f"{file_id}{file_ext}"
    save_path = os.path.join(UPLOAD_PATH, save_filename)

    try:
        with open(save_path, "wb") as buffer:
            shutil.copyfileobj(file.file, buffer)
    except Exception as e:
        raise HTTPException(
            status_code=500,
            detail=f"File save error: {str(e)}"
        )

    if save_path.endswith(".doc"):
        try:
            save_path = convert_doc_to_docx(save_path)
        except Exception as e:
            raise HTTPException(
                status_code=500,
                detail=f"DOC convert error: {str(e)}"
            )

    try:
        result = parse_docx(save_path)
    except Exception as e:
        if os.path.exists(save_path):
            os.remove(save_path)
        raise HTTPException(
            status_code=500,
            detail=f"Parse error: {str(e)}"
        )

    editable_count = sum(1 for f in result["fillable_fields"] if not f["locked"])
    locked_count = sum(1 for f in result["fillable_fields"] if f["locked"])

    return {
        "status": "success",
        "file_id": file_id,
        "original_filename": file.filename,
        "saved_as": os.path.basename(save_path),
        "summary": {
            "tables": len(result["tables"]),
            "paragraphs": len(result["paragraphs"]),
            "total_fields": len(result["fillable_fields"]),
            "editable_fields": editable_count,
            "locked_fields": locked_count,
        },
        "fields": result["fillable_fields"],
        "paragraphs": result["paragraphs"],
    }


# ── fill_document() ─────────────────────────────────
# POST /fill — inject user values into a previously parsed template.
# Parameters: FillRequest JSON body (file_id, original_filename, fields[])
# Returns: JSON with output_filename and path under GENERATED_REPORTS_PATH
# Key steps:
#   1. Locates template at UPLOAD_PATH/{file_id}.docx (or .doc)
#   2. Builds output path as {file_id}_filled.docx in GENERATED_REPORTS_PATH
#   3. Calls fill_docx() — body tables/paragraphs only; footers preserved
#   4. Returns output_filename for Laravel to store as docx_filled_path
@app.post("/fill")
async def fill_document(request: FillRequest):
    template_path = None
    for ext in [".docx", ".doc"]:
        candidate = os.path.join(UPLOAD_PATH, f"{request.file_id}{ext}")
        if os.path.exists(candidate):
            template_path = candidate
            break

    if not template_path:
        raise HTTPException(
            status_code=404,
            detail=f"Template file not found: {request.file_id}"
        )

    os.makedirs(GENERATED_REPORTS_PATH, exist_ok=True)
    output_filename = f"{request.file_id}_filled.docx"
    output_path = os.path.join(GENERATED_REPORTS_PATH, output_filename)

    field_values = [
        {
            "location": f.location,
            "value":    _normalize_text_value(f.value),
            "locked":   f.locked,
            "type":     getattr(f, "type", None) or "",
        }
        for f in request.fields
    ]

    for field in field_values[:5]:
        print(f"Field value raw: {repr(field['value'])}")

    try:
        result_path = fill_docx(template_path, field_values, output_path, request.attachment_links or {})
    except Exception as e:
        raise HTTPException(
            status_code=500,
            detail=f"Fill error: {str(e)}"
        )

    filled_count = sum(1 for f in request.fields if not f.locked)
    skipped_count = sum(1 for f in request.fields if f.locked)

    return {
        "status":            "success",
        "file_id":           request.file_id,
        "output_filename":   output_filename,
        "output_path":       result_path,
        "summary": {
            "total_fields":   len(request.fields),
            "filled_fields":  filled_count,
            "skipped_locked": skipped_count,
        }
    }


# ── preview_document() ──────────────────────────────
# POST /preview — render DOCX body as HTML with editable input boxes.
# Parameters: file_id (form), optional fields_json (form) with pre-filled values
# Returns: JSON {status, html} for embedding in Laravel fill.blade.php
# Key steps:
#   1. Locates template file by file_id under UPLOAD_PATH
#   2. Loads fields from fields_json or re-parses the template
#   3. Calls build_fill_html() for document-order table/paragraph preview
#   4. Does not modify the DOCX — read-only preview for the fill form
@app.post("/preview")
async def preview_document(
    file_id: str = Form(...),
    fields_json: Optional[str] = Form(None),
    mode: str = Form("edit"),
):
    template_path = None
    for ext in [".docx", ".doc"]:
        candidate = os.path.join(UPLOAD_PATH, f"{file_id}{ext}")
        if os.path.exists(candidate):
            template_path = candidate
            break

    if not template_path:
        raise HTTPException(status_code=404, detail=f"File nahi mila: {file_id}")

    try:
        # Always re-parse so new blank-detection rules apply; merge stored values by location.
        fresh = parse_docx(template_path)["fillable_fields"]
        stored = json.loads(fields_json) if fields_json else []
        stored_by_loc = {f.get("location"): f for f in stored if f.get("location")}
        legacy_para_by_index: dict[int, dict] = {}
        for sf in stored:
            sloc = sf.get("location") or ""
            if not sloc.startswith("para_"):
                continue
            try:
                para_idx = int(sloc.split("_")[1])
            except (IndexError, ValueError):
                continue
            if sloc == f"para_{para_idx}" or sloc.startswith(f"para_{para_idx}_blank_"):
                legacy_para_by_index.setdefault(para_idx, sf)

        fields = []
        for ff in fresh:
            loc = ff.get("location")
            if loc in stored_by_loc:
                sv = stored_by_loc[loc]
                if sv.get("value") not in (None, ""):
                    ff["value"] = sv["value"]
                # Respect explicit locked flag from Laravel when present
                if "locked" in sv:
                    ff["locked"] = sv["locked"]
            else:
                parent_para = ff.get("parent_para")
                if parent_para is not None and parent_para in legacy_para_by_index:
                    sv = legacy_para_by_index[parent_para]
                    if sv.get("value") not in (None, ""):
                        ff["value"] = sv["value"]
                    if "locked" in sv:
                        ff["locked"] = sv["locked"]
            fields.append(ff)

        html = build_fill_html(template_path, fields, mode=mode)
        debug_widgets = _collect_widget_debug(fields)
        _log_widget_debug(debug_widgets)
        return {"status": "success", "html": html, "debug_widgets": debug_widgets}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"


# ── _escape_html() ──────────────────────────────────
# Escapes a string for safe embedding in HTML preview output.
# Parameters: value — raw text from DOCX cell or paragraph
# Returns: HTML-escaped string
def _escape_html(value: str) -> str:
    if not value:
        return ""
    return (
        str(value)
        .replace("&", "&amp;")
        .replace('"', "&quot;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
    )


READONLY_BG = "#F9F9F9"
DOCX_CELL_BORDER = "border:1px solid #000;padding:6px 8px;vertical-align:middle;"


def _inline_cell_style(cell_style: str = "", *, readonly: bool = False, value_cell: bool = False) -> str:
    """Inline styles so approver view matches fill form without page-specific CSS."""
    parts = [DOCX_CELL_BORDER, cell_style]
    if readonly and value_cell:
        parts.append(f"background:{READONLY_BG};")
    return "".join(parts)


def _is_readonly_mode(mode: str) -> bool:
    return (mode or "edit").strip().lower() == "readonly"


def _format_readonly_date(value: str) -> str:
    value = (value or "").strip()
    if not value:
        return "—"
    match = re.match(r"^(\d{4})-(\d{2})-(\d{2})$", value)
    if match:
        return f"{match.group(3)}-{match.group(2)}-{match.group(1)}"
    return _escape_html(value)


def _format_readonly_display(value, field: dict | None = None) -> str:
    raw = "" if value is None else str(value).strip()
    if field:
        field_type = field.get("type", "")
        widget = _field_widget(field)
        if field_type in ("paragraph_checkbox", "table_radio", "paragraph_radio") or widget == "checkbox":
            if raw.lower() in ("yes", "1", "true", "on"):
                return "☑" if field_type == "paragraph_checkbox" else "☑ Yes"
            if raw.lower() == "no":
                return "☐" if field_type == "paragraph_checkbox" else "☐ No"
            return "☐"
        if widget == "date" and "\n" not in raw and " " not in raw.strip():
            return _format_readonly_date(raw)
    if raw == "":
        return "—"
    if "\n" in raw:
        return "<br>".join(_escape_html(part) for part in raw.split("\n"))
    return _escape_html(raw)


def _render_readonly_span(value, field: dict | None = None, location: str = "") -> str:
    display = _format_readonly_display(value, field)
    loc_attr = f' data-field="{_escape_html(location)}"' if location else ""
    return f'<span class="docx-readonly-value"{loc_attr}>{display}</span>'


def _readonly_td(
    content: str,
    *,
    cell_style: str = "",
    rowspan_attr: str = "",
    location: str = "",
    extra_class: str = "readonly",
) -> str:
    style = _inline_cell_style(cell_style, readonly=True, value_cell=True)
    data_attr = f' data-field="{_escape_html(location)}"' if location else ""
    return (
        f'<td class="docx-cell {extra_class}" style="{style}"'
        f'{rowspan_attr}{data_attr}>{content}</td>'
    )


# ── _para_text_from_element() ───────────────────────
# Extracts plain text from a raw OOXML paragraph element.
# Parameters: p_element — lxml element from document body
# Returns: stripped text string
def _para_text_from_element(p_element, exclude_textboxes: bool = False) -> str:
    if exclude_textboxes:
        from doc_parser import _para_text_from_element as _parser_para_text

        return _parser_para_text(p_element, exclude_textboxes=True)
    texts = [t.text for t in p_element.findall(f".//{{{W_NS}}}t") if t.text]
    return "".join(texts).strip()


# ── _collect_widget_debug() ─────────────────────────
# Returns widget assignments for known-problem fields (logged to Laravel on preview).
def _collect_widget_debug(fields: list) -> list:
    watch_terms = ("effective date", "comment", "new/", "first installation", "yes", "no")
    debug_rows = []
    for field in fields:
        label = (field.get("label") or "").lower()
        loc = field.get("location") or ""
        if not any(term in label for term in watch_terms) and field.get("type") not in (
            "table_radio",
            "paragraph_checkbox",
        ):
            if not loc.startswith(("table_0_row_0", "header_")):
                continue
        debug_rows.append({
            "location": loc,
            "label": field.get("label", ""),
            "type": field.get("type", ""),
            "widget": _field_widget(field),
            "locked": field.get("locked", False),
        })
    return debug_rows


def _log_widget_debug(debug_rows: list) -> None:
    for row in debug_rows:
        logger.info(
            "DOCX widget debug: location=%s widget=%s type=%s locked=%s label=%s",
            row.get("location"),
            row.get("widget"),
            row.get("type"),
            row.get("locked"),
            (row.get("label") or "")[:80],
        )


# ── _field_widget() ─────────────────────────────────
# Resolves HTML widget type from field metadata (date, textarea, checkbox, text).
def _field_widget(field: dict | None) -> str:
    if not field:
        return "text"
    field_type = field.get("type", "")
    label = field.get("label", "")
    if field_type in (
        "table_multicol",
        "table_1col",
        "table_2col",
        "paragraph_blank",
        "paragraph_blank_part",
        "textbox_blank",
        "textbox_blank_part",
        "textbox_line",
        "header_cell",
    ):
        return infer_field_widget(label, field_type)
    widget = field.get("widget")
    if widget and not (is_report_number_label(label) and str(widget) == "date"):
        return str(widget)
    return infer_field_widget(label, field_type)


# ── _cell_rowspan() ─────────────────────────────────
# Returns 0 for vMerge continuation rows (skip duplicate), 1 otherwise.
def _cell_rowspan(cell) -> int:
    from docx.oxml.ns import qn

    tc_pr = cell._tc.tcPr
    if tc_pr is None:
        return 1
    v_merge = tc_pr.find(qn("w:vMerge"))
    if v_merge is not None and v_merge.get(qn("w:val")) == "continue":
        return 0
    return 1


# ── _is_textarea_cell() ─────────────────────────────
# Large comment/observation cells or vertically merged cells use <textarea>.
def _is_textarea_cell(field: dict | None, row_label: str, rowspan: int) -> bool:
    if rowspan > 1:
        return True
    label = f"{field.get('label', '') if field else ''} {row_label}".lower()
    return any(k in label for k in ("comment", "remarks", "observation", "description"))


# ── _render_paragraph_checkbox() ────────────────────
# Renders a single checklist checkbox for paragraph_checkbox fields (drawing-based).
def _strip_checkbox_symbols(text: str) -> str:
    """Remove leading ☑/☐ symbols from label text for clean checkbox display."""
    return re.sub(r"^[\u2611☑✓☐□\u25a1\u2610]\s*", "", (text or "").strip())


def _render_paragraph_checkbox(para_index: int, para_text: str, field: dict, mode: str = "edit") -> str:
    loc = _escape_html(f"para_{para_index}")
    label = _escape_html(_strip_checkbox_symbols(para_text))
    locked = _is_field_locked(field)
    if _is_readonly_mode(mode) and not locked:
        mark = _format_readonly_display(field.get("value", ""), field)
        return (
            f'<p class="docx-para docx-para-checkbox">'
            f'<span class="docx-readonly-value">{mark}</span> {label}'
            f"</p>"
        )
    checked = "checked" if field.get("value") or para_text.strip().startswith(("☑", "\u2611")) else ""
    if locked:
        return (
            f'<p class="docx-para docx-para-checkbox">'
            f'<label class="docx-check-label">'
            f'<input type="checkbox" checked disabled> {label}'
            f"</label></p>"
        )
    return (
        f'<p class="docx-para docx-para-editable docx-para-checkbox">'
        f'<label class="docx-check-label">'
        f'<input type="checkbox" name="fields[{loc}]" value="1" {checked}> {label}'
        f"</label></p>"
    )


def _render_textbox_html(
    txbx_index: int,
    txbx_element,
    field_map: dict,
    mode: str = "edit",
) -> str:
    """Render a bordered text box (History of Equipment section) with per-line inputs."""
    from doc_parser import _para_text_from_element

    W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
    inner_paras = txbx_element.findall(f"{{{W_NS}}}p")
    lines: list[str] = []
    after_recommendation = False
    recommendation_line = 0

    for inner_index, p_element in enumerate(inner_paras):
        inner_text = _para_text_from_element(p_element).strip()
        location_base = f"txbx_{txbx_index}_p_{inner_index}"
        ll = inner_text.lower()

        if ll.startswith("recommendation"):
            after_recommendation = True
            recommendation_line = 0
            lines.append(
                f'<p class="docx-para docx-textbox-line">{_escape_html(inner_text)}</p>'
            )
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
            recommendation_line += 1
            field = field_map.get(location_base) or {
                "label": f"Recommendation line {recommendation_line}",
                "value": "",
                "type": "textbox_line",
                "widget": "text",
            }
            if _is_readonly_mode(mode):
                display = _render_readonly_span(
                    field.get("value", ""), field, location_base
                )
                lines.append(
                    f'<p class="docx-para docx-textbox-line docx-textbox-blank">{display}</p>'
                )
            else:
                inp = _render_field_input(
                    location_base,
                    field,
                    css_class="docx-input docx-textbox-input",
                    value=field.get("value", ""),
                )
                lines.append(
                    f'<p class="docx-para docx-textbox-line docx-textbox-blank">{inp}</p>'
                )
            continue

        if after_recommendation and inner_text:
            after_recommendation = False

        if "from date" in ll and "to" in ll:
            parts_html = [_escape_html("History card from date:")]
            for blank_index in range(2):
                loc = f"{location_base}_blank_{blank_index}"
                field = field_map.get(loc) or {
                    "label": "History card date",
                    "value": "",
                    "widget": "date",
                }
                if blank_index == 1:
                    parts_html.append(_escape_html(" to "))
                if _is_readonly_mode(mode):
                    parts_html.append(
                        _render_readonly_span(field.get("value", ""), field, loc)
                    )
                else:
                    parts_html.append(
                        _render_field_input(
                            loc,
                            field,
                            css_class="docx-input-inline",
                            value=field.get("value", ""),
                            inline=True,
                        )
                    )
            lines.append(
                f'<p class="docx-para docx-textbox-line docx-para-editable">{"".join(parts_html)}</p>'
            )
            continue

        if "reviewed by" in ll:
            parts_html = [_escape_html("Reviewed by")]
            loc = f"{location_base}_blank_0"
            field = field_map.get(loc) or {
                "label": "Reviewed by",
                "value": "",
                "widget": "text",
            }
            if _is_readonly_mode(mode):
                parts_html.append(
                    _render_readonly_span(field.get("value", ""), field, loc)
                )
            else:
                parts_html.append(
                    _render_field_input(
                        loc,
                        field,
                        css_class="docx-input-inline",
                        value=field.get("value", ""),
                        inline=True,
                    )
                )
            parts_html.append(_escape_html("(Engineering Department)"))
            lines.append(
                f'<p class="docx-para docx-textbox-line docx-para-editable">{"".join(parts_html)}</p>'
            )
            continue

        field = field_map.get(location_base)
        colon_text = dedupe_colon_label(inner_text)
        if inner_text and has_paragraph_blank(inner_text) and PARA_COLON_BLANK_RE.search(
            colon_text.strip()
        ):
            if field is None:
                display_label = re.sub(r":\s*$", "", colon_text).strip() or colon_text
                field = {
                    "label": display_label,
                    "value": "",
                    "locked": False,
                    "type": "textbox_blank",
                    "location": location_base,
                    "widget": infer_field_widget(display_label, "textbox_blank"),
                }
            label_part = re.sub(r":\s*$", ": ", colon_text)
            if _is_readonly_mode(mode):
                display = _render_readonly_span(
                    field.get("value", ""), field, location_base
                )
                lines.append(
                    f'<p class="docx-para docx-textbox-line docx-para-editable">'
                    f"{_escape_html(label_part)}{display}</p>"
                )
            else:
                inp = _render_field_input(
                    location_base,
                    field,
                    css_class="docx-input-inline",
                    value=field.get("value", ""),
                    inline=True,
                )
                lines.append(
                    f'<p class="docx-para docx-textbox-line docx-para-editable">'
                    f"{_escape_html(label_part)}{inp}</p>"
                )
            continue

        if inner_text:
            lines.append(
                f'<p class="docx-para docx-textbox-line">{_escape_html(inner_text)}</p>'
            )

    return (
        f'<div class="docx-textbox" data-txbx="{txbx_index}">{"".join(lines)}</div>'
    )


def _render_paragraph_multi_blank(
    para_index: int, para_text: str, blank_fields: list, mode: str = "edit"
) -> str:
    """Render multiple inline inputs for paragraphs with several dot/underscore blanks."""
    parts = []
    last_end = 0
    for blank_index, match in enumerate(PARA_BLANK_RE.finditer(para_text)):
        parts.append(_escape_html(para_text[last_end : match.start()]))
        loc = f"para_{para_index}_blank_{blank_index}"
        sub_field = blank_fields[blank_index][1] if blank_index < len(blank_fields) else {
            "label": f"blank {blank_index + 1}",
            "value": "",
        }
        if _is_readonly_mode(mode):
            parts.append(
                _render_readonly_span(sub_field.get("value", ""), sub_field, loc)
            )
        else:
            parts.append(
                _render_field_input(
                    loc,
                    sub_field,
                    css_class="docx-input-inline",
                    placeholder=sub_field.get("label", "")[:40],
                    value=sub_field.get("value", ""),
                    inline=True,
                )
            )
        last_end = match.end()
    parts.append(_escape_html(para_text[last_end:]))
    css = "docx-para" if _is_readonly_mode(mode) else "docx-para docx-para-editable"
    return f'<p class="{css}">{"".join(parts)}</p>'


def _render_paragraph_yes_no_radio(
    para_index: int, para_text: str, field: dict, mode: str = "edit"
) -> str:
    loc = _escape_html(f"para_{para_index}")
    label = _escape_html(paragraph_yes_no_label(para_text) + ": ")
    if _is_readonly_mode(mode):
        display = _format_readonly_display(field.get("value", ""), field)
        return (
            f'<p class="docx-para docx-para-checkbox">'
            f"{label}<span class=\"docx-readonly-value\">{display}</span></p>"
        )
    value = (field.get("value") or "").strip().lower()
    yes_checked = "checked" if value == "yes" else ""
    no_checked = "checked" if value == "no" else ""
    return (
        f'<p class="docx-para docx-para-editable docx-para-checkbox">'
        f"{label}"
        f'<label class="docx-check-label">'
        f'<input type="radio" name="fields[{loc}]" value="Yes" {yes_checked}> Yes'
        f"</label> "
        f'<label class="docx-check-label">'
        f'<input type="radio" name="fields[{loc}]" value="No" {no_checked}> No'
        f"</label></p>"
    )


# ── _render_radio_yes_no_cell() ─────────────────────
# Renders Yes/No radio pair for table cells with Yes □ No □ pattern.
def _render_radio_yes_no_cell(
    location: str, field: dict, cell_text: str, mode: str = "edit"
) -> str:
    loc = _escape_html(location)
    if _is_readonly_mode(mode):
        display = _format_readonly_display(field.get("value", ""), field)
        return _readonly_td(display, location=location)
    value = (field.get("value") or "").strip().lower()
    yes_checked = "checked" if value == "yes" else ""
    no_checked = "checked" if value == "no" else ""
    return (
        f'<td class="docx-cell editable docx-checkbox-cell">'
        f'<label class="docx-check-label">'
        f'<input type="radio" name="fields[{loc}]" value="Yes" {yes_checked}> Yes'
        f"</label> "
        f'<label class="docx-check-label">'
        f'<input type="radio" name="fields[{loc}]" value="No" {no_checked}> No'
        f"</label>"
        f"</td>"
    )


# ── _render_approver_cell() ─────────────────────────
# Renders a placeholder user dropdown for signature-table Name & Designation cells.
# Laravel fill.blade.php JS hydrates options from $users and syncs POST approver IDs.
def _render_approval_stamp_cell(field: dict, mode: str = "edit") -> str:
    """Render locked Name / Signature / Date cells on the APPROVAL PAGE table."""
    location = field.get("location", "")
    field_type = field.get("type", "")
    value = (field.get("value") or "").strip()
    style = _inline_cell_style("", readonly=True, value_cell=True)
    style += "background:#f0f0f0;color:#555;"

    if value:
        content = _escape_html(value)
    else:
        content = '<span class="docx-approval-pending">—</span>'

    loc_attr = f' data-field="{_escape_html(location)}"' if location else ""
    return (
        f'<td class="docx-cell locked approval-stamp-cell" style="{style}"{loc_attr}>'
        f"{content}</td>"
    )


def _render_approver_cell(field: dict, mode: str = "edit") -> str:
    location = _escape_html(field.get("location", ""))
    role = _escape_html(field.get("approver_role", ""))
    date_loc = _escape_html(field.get("signature_date_location", ""))
    if _is_readonly_mode(mode):
        display = _render_readonly_span(field.get("value", ""), field, field.get("location", ""))
        return _readonly_td(
            display,
            location=field.get("location", ""),
            extra_class="readonly docx-approver-readonly",
        )
    required = " required" if role else ""
    return (
        f'<td class="docx-cell editable docx-approver-cell">'
        f'<select class="docx-input approver-field" '
        f'data-approver-role="{role}" '
        f'data-field-location="{location}" '
        f'data-signature-date-location="{date_loc}"{required}>'
        f'<option value="">-- Select User --</option>'
        f"</select>"
        f'<input type="hidden" name="fields[{location}]" '
        f'class="approver-name-field" data-approver-role="{role}" '
        f'data-field-location="{location}" value="">'
        f"</td>"
    )


# ── _render_attachment_cell() ───────────────────────
# Renders a file upload input for certificate / proof attachment columns.
def _render_attachment_cell(field: dict, mode: str = "edit") -> str:
    """Render one file-upload cell; data-attachment-location is used when converting to a single download link."""
    location = _escape_html(field.get("location", ""))
    value = _escape_html((field.get("value") or "").strip())
    if _is_readonly_mode(mode):
        display_value = (field.get("value") or "").strip()
        display = _render_readonly_span(display_value, field, field.get("location", ""))
        style = _inline_cell_style("", readonly=True, value_cell=True)
        return (
            f'<td class="docx-cell readonly docx-attachment-cell" '
            f'data-attachment-location="{location}" '
            f'style="{style}" data-field="{location}">'
            f"{display}</td>"
        )
    value_attr = f' value="{value}"' if value else ""

    return (
        f'<td class="docx-cell editable docx-attachment-cell" data-attachment-location="{location}">'
        f'<input type="file" class="docx-attachment-field" '
        f'name="attachments[{location}]" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">'
        f'<small class="text-muted d-block mt-1">Upload PDF/DOCX</small>'
        f'<input type="hidden" name="fields[{location}]" '
        f'class="attachment-name-field" data-attachment-location="{location}"{value_attr}>'
        f"</td>"
    )


# ── _render_field_input() ───────────────────────────
# Builds a single text/date input or textarea for a table or paragraph field.
def _render_field_input(
    location: str,
    field: dict,
    *,
    css_class: str = "docx-input",
    placeholder: str = "",
    value: str = "",
    inline: bool = False,
) -> str:
    widget = _field_widget(field)
    loc = _escape_html(location)
    ph = _escape_html(placeholder or field.get("label", ""))
    val = _escape_html(value or field.get("value", "") or "")

    if widget == "textarea":
        return (
            f'<textarea name="fields[{loc}]" class="{css_class}" rows="4" '
            f'style="width:100%;" placeholder="{ph}">{val}</textarea>'
        )

    input_type = "date" if widget == "date" else "text"
    style = "" if not inline else ""
    return (
        f'<input type="{input_type}" name="fields[{loc}]" class="{css_class}" '
        f'placeholder="{ph}" value="{val}"{style}>'
    )


# ── _para_index_for_element() ───────────────────────
# Maps a body <p> element to doc_parser's para_{n} index.
# Uses enumerate(doc.paragraphs) order; first match wins when elements are reused.
def _para_index_for_element(p_element, paragraphs) -> int | None:
    for i, para in enumerate(paragraphs):
        if para._element is p_element:
            return i
    return None


# ── _render_paragraph() ─────────────────────────────
# Renders one body paragraph for the fill preview.
# para_index must match doc_parser.py enumerate(doc.paragraphs) — not a body-walk counter.
def _render_paragraph(
    para_index: int,
    para_text: str,
    field: dict | None,
    field_map: dict,
    mode: str = "edit",
) -> str:
    loc = f"para_{para_index}"

    blank_parts = sorted(
        [(k, v) for k, v in field_map.items() if k.startswith(f"para_{para_index}_blank_")],
        key=lambda item: int(item[0].rsplit("_blank_", 1)[1]),
    )
    if len(blank_parts) > 1 or (
        len(blank_parts) == 1 and field and field.get("type") == "paragraph_blank_part"
    ):
        if len(list(PARA_BLANK_RE.finditer(para_text))) > 1:
            return _render_paragraph_multi_blank(para_index, para_text, blank_parts, mode)

    if field and field.get("type") == "paragraph_checkbox":
        return _render_paragraph_checkbox(para_index, para_text, field, mode)

    if field and field.get("type") == "paragraph_radio":
        return _render_paragraph_yes_no_radio(para_index, para_text, field, mode)

    if (
        field is None
        and not _is_readonly_mode(mode)
        and is_paragraph_yes_no_radio(para_text)
    ):
        pseudo = {"type": "paragraph_radio", "label": paragraph_yes_no_label(para_text), "value": ""}
        return _render_paragraph_yes_no_radio(para_index, para_text, pseudo, mode)

    # Body-walk text may expose a colon-blank label while para.text was empty at parse time.
    if (
        (field is None or field.get("type") == "paragraph_checkbox")
        and not _is_readonly_mode(mode)
        and has_paragraph_blank(para_text)
    ):
        normalized = dedupe_colon_label(para_text)
        display_label = re.sub(r":\s*$", "", normalized).strip() or normalized
        if is_date_field_label(display_label) or PARA_COLON_BLANK_RE.search(normalized):
            field = {
                "label": display_label,
                "value": "",
                "locked": False,
                "type": "paragraph_blank",
                "location": loc,
                "widget": infer_field_widget(display_label, "paragraph_blank"),
            }

    if field and not _is_field_locked(field):
        if _is_readonly_mode(mode):
            display = _render_readonly_span(field.get("value", ""), field, loc)
            label_text = field.get("label") or para_text
            blank_source = (
                para_text
                if "{{BLANK}}" in para_text
                else (label_text if "{{BLANK}}" in label_text else "")
            )
            if blank_source:
                parts = blank_source.split("{{BLANK}}")
                html_parts = [_escape_html(parts[0])]
                for segment in parts[1:]:
                    html_parts.append(display)
                    html_parts.append(_escape_html(segment))
                return f'<p class="docx-para">{"".join(html_parts)}</p>'
            if PARA_COLON_BLANK_RE.search(dedupe_colon_label(para_text).strip()):
                label_part = re.sub(r":\s*$", ": ", dedupe_colon_label(para_text))
                return f'<p class="docx-para">{_escape_html(label_part)}{display}</p>'
            match = PARA_BLANK_RE.search(para_text)
            if match:
                before = _escape_html(para_text[: match.start()])
                after = _escape_html(para_text[match.end() :])
                return f'<p class="docx-para">{before}{display}{after}</p>'
            if para_text:
                return f'<p class="docx-para">{_escape_html(para_text)} {display}</p>'
            return f'<p class="docx-para">{display}</p>'

        inp = _render_field_input(
            loc,
            field,
            css_class="docx-input-inline",
            placeholder=re.sub(r"\{\{BLANK\}\}", "", (field.get("label") or "fill here"))[:80],
            value=field.get("value", ""),
            inline=True,
        )

        # Parser template marker {{BLANK}} — render label text + input (not literal {{BLANK}}).
        label_text = field.get("label") or para_text
        blank_source = (
            para_text
            if "{{BLANK}}" in para_text
            else (label_text if "{{BLANK}}" in label_text else "")
        )
        if blank_source:
            parts = blank_source.split("{{BLANK}}")
            html_parts = [_escape_html(parts[0])]
            for segment in parts[1:]:
                html_parts.append(inp)
                html_parts.append(_escape_html(segment))
            return f'<p class="docx-para docx-para-editable">{"".join(html_parts)}</p>'

        # Colon-only blank: "Model No.:" → label + input
        colon_text = dedupe_colon_label(para_text)
        if PARA_COLON_BLANK_RE.search(colon_text.strip()):
            label_part = re.sub(r":\s*$", ": ", colon_text)
            return (
                f'<p class="docx-para docx-para-editable">'
                f"{_escape_html(label_part)}{inp}</p>"
            )

        # Underscore / dot leader blank — inline input at first placeholder
        match = PARA_BLANK_RE.search(para_text)
        if match:
            before = _escape_html(para_text[: match.start()])
            after = _escape_html(para_text[match.end() :])
            return f'<p class="docx-para docx-para-editable">{before}{inp}{after}</p>'

        if para_text:
            return (
                f'<p class="docx-para docx-para-editable">{_escape_html(para_text)} {inp}</p>'
            )
        return f'<p class="docx-para docx-para-editable">{inp}</p>'

    if para_text:
        return f'<p class="docx-para">{_escape_html(para_text)}</p>'

    return ""


# ── _is_field_locked() ──────────────────────────────
# Normalises locked flag from JSON (bool / 0|1 / "false" strings).
def _is_field_locked(field: dict) -> bool:
    if field.get("type") == "table_sl_no":
        return True
    locked = field.get("locked", False)
    if isinstance(locked, str):
        return locked.strip().lower() in ("1", "true", "yes")
    return bool(locked)


def _table_sl_no_col_index(table) -> int | None:
    """Column index for Sl. No. when the first header cell matches (same rules as doc_parser)."""
    if not table.rows:
        return None
    header_row = [cell.text.strip() for cell in table.rows[0].cells]
    report_approval_block = is_report_approval_block_table(table)
    col_headers = get_table_column_headers(table, report_approval_block)
    return resolve_sl_no_column_index(col_headers, header_row)


_SL_NO_VALUE_RE = re.compile(r"^\d+[\.\)]?$|^\d+\.\d+$")


def _looks_like_sl_no_value(value: str) -> bool:
    """True for 1, 1., 1.0, 2) — not random user text like 'jjghj'."""
    return bool(_SL_NO_VALUE_RE.match((value or "").strip()))


def _format_sl_no_display(r_idx: int, field: dict | None, cell_text: str) -> str:
    """
    Display value for a locked Sl. No. cell.
    Keeps pre-filled DOCX values (1., 1.0); auto-generates 1, 2, 3 for empty data rows.
    Ignores junk typed into legacy editable Sl. No. fields.
    """
    if r_idx == 0:
        return cell_text
    for candidate in (
        (field or {}).get("value", ""),
        cell_text,
    ):
        val = (candidate or "").strip()
        if val and _looks_like_sl_no_value(val):
            return val
    auto = (field or {}).get("auto_serial")
    if auto is not None and str(auto).strip() != "":
        return str(auto)
    return str(r_idx)


def _render_sl_no_cell(
    r_idx: int,
    field: dict | None,
    cell_text: str,
    *,
    cell_style: str = "",
    rowspan_attr: str = "",
    location: str = "",
    readonly: bool = False,
) -> str:
    """Render Sl. No. column as plain locked text — never an input."""
    display = _format_sl_no_display(r_idx, field, cell_text)
    style = _inline_cell_style(cell_style, readonly=True, value_cell=True)
    style += "text-align:center;"
    loc_attr = f' data-field="{_escape_html(location)}"' if location else ""
    content = _escape_html(display)
    extra = " locked readonly" if r_idx > 0 else ""
    return (
        f'<td class="docx-cell{extra}" style="{style}"{rowspan_attr}{loc_attr}>'
        f"{content}</td>"
    )


# ── _resolve_cell_field() ───────────────────────────
# Finds the doc_parser field for a rendered cell.
# Uses the same col_index rules as doc_parser.py (enumerate row.cells).
# For merged cells, multiple col indices share one tc — scan all of them.
def _resolve_cell_field(
    t_idx: int,
    r_idx: int,
    col_idx: int,
    cell,
    cells,
    is_two_col: bool,
    field_map: dict,
):
    loc_2col = f"table_{t_idx}_row_{r_idx}"
    if is_two_col:
        col_field = field_map.get(f"table_{t_idx}_row_{r_idx}_col_{col_idx}")
        if col_field:
            return col_field
        if col_idx == 1:
            return field_map.get(loc_2col)
        return None

    tc_id = id(cell._tc)
    field = field_map.get(f"table_{t_idx}_row_{r_idx}_col_{col_idx}")
    if field:
        return field

    for ci, c in enumerate(cells):
        if id(c._tc) == tc_id:
            alt = field_map.get(f"table_{t_idx}_row_{r_idx}_col_{ci}")
            if alt:
                return alt

    return field_map.get(loc_2col)


# ── _render_table() ─────────────────────────────────
# Renders one body table as an HTML table with input boxes for editable cells.
# Parameters: table — python-docx Table; t_idx — table index; field_map — location→field
# Returns: HTML string for one <table class="docx-table">
# Key steps:
#   1. De-duplicates merged cells in each row (skip duplicate tc ids)
#   2. Uses len(row.cells) for 2-col detection — must match doc_parser.py
#   3. Uses original col_index from enumerate(row.cells) for location keys
def _render_table(table, t_idx: int, field_map: dict, mode: str = "edit") -> str:
    html = ['<table class="docx-table">']
    readonly = _is_readonly_mode(mode)
    sl_no_col = _table_sl_no_col_index(table)
    toc_table = is_toc_table(table) or is_toc_list_table(table)

    for r_idx, row in enumerate(table.rows):
        html.append("<tr>")

        cells = row.cells
        is_two_col = len(cells) == 2
        is_one_col = len(cells) == 1
        row_label = cells[0].text.strip() if cells else ""
        seen_tc_ids = set()

        for col_idx, cell in enumerate(cells):
            tc_id = id(cell._tc)
            if tc_id in seen_tc_ids:
                continue
            seen_tc_ids.add(tc_id)

            rowspan = _cell_rowspan(cell)
            if rowspan == 0:
                continue

            text = dedupe_colon_label(cell.text.strip())
            is_header = r_idx == 0
            cell_style = "font-weight:bold;" if is_header else ""
            rowspan_attr = ""

            if toc_table or is_toc_entry_text(text):
                style = _inline_cell_style(cell_style, readonly=readonly)
                html.append(
                    f'<td class="docx-cell" style="{style}"{rowspan_attr}>'
                    f"{_escape_html(text)}</td>"
                )
                continue

            field = _resolve_cell_field(
                t_idx, r_idx, col_idx, cell, cells, is_two_col, field_map
            )

            # Sl. No. / component serial column — locked auto-numbered text.
            is_sl_no_cell = (sl_no_col is not None and col_idx == sl_no_col) or (
                field and field.get("type") == "table_sl_no"
            )
            if is_sl_no_cell:
                loc = (
                    (field or {}).get("location")
                    or f"table_{t_idx}_row_{r_idx}_col_{col_idx}"
                )
                html.append(
                    _render_sl_no_cell(
                        r_idx,
                        field,
                        text,
                        cell_style=cell_style,
                        rowspan_attr=rowspan_attr,
                        location=loc,
                        readonly=readonly or r_idx > 0,
                    )
                )
                continue

            if field and str(field.get("type", "")).startswith("approval_stamp"):
                html.append(_render_approval_stamp_cell(field, mode))
                continue

            if field and field.get("type") == "approver" and not _is_field_locked(field):
                html.append(_render_approver_cell(field, mode))
                continue

            if field and field.get("type") == "attachment" and not _is_field_locked(field):
                html.append(_render_attachment_cell(field, mode))
                continue

            if field and field.get("type") == "table_radio" and not _is_field_locked(field):
                html.append(_render_radio_yes_no_cell(field["location"], field, text, mode))
                continue

            if (is_yes_no_radio_cell(text) or is_checkbox_cell(text)) and field is None:
                loc = f"table_{t_idx}_row_{r_idx}_col_{col_idx}"
                pseudo = {"type": "table_radio", "label": row_label, "value": text}
                html.append(_render_radio_yes_no_cell(loc, pseudo, text, mode))
                continue

            if is_two_col and col_idx == 0 and not (field and not _is_field_locked(field)):
                style = _inline_cell_style(cell_style, readonly=readonly)
                html.append(
                    f'<td class="docx-cell" style="{style}"{rowspan_attr}>'
                    f"{_escape_html(text)}</td>"
                )
            elif is_one_col and field and not _is_field_locked(field):
                if readonly:
                    display = _render_readonly_span(
                        field.get("value", ""), field, field["location"]
                    )
                    label_part = (
                        re.sub(r":\s*$", ": ", text)
                        if PARA_COLON_BLANK_RE.search(dedupe_colon_label(text))
                        else ""
                    )
                    content = f"{_escape_html(label_part)}{display}" if label_part else display
                    html.append(
                        _readonly_td(
                            content,
                            cell_style=cell_style,
                            rowspan_attr=rowspan_attr,
                            location=field["location"],
                        )
                    )
                else:
                    widget = _field_widget(field)
                    if widget == "textarea":
                        inp = _render_field_input(
                            field["location"],
                            field,
                            css_class="docx-input",
                            value=field.get("value", ""),
                        )
                        html.append(
                            f'<td class="docx-cell editable" style="{cell_style}"{rowspan_attr}>'
                            f"{inp}</td>"
                        )
                    else:
                        label_part = (
                            re.sub(r":\s*$", ": ", text)
                            if PARA_COLON_BLANK_RE.search(dedupe_colon_label(text))
                            else text
                        )
                        inp = _render_field_input(
                            field["location"],
                            field,
                            css_class="docx-input",
                            value=field.get("value", ""),
                        )
                        html.append(
                            f'<td class="docx-cell editable" style="{cell_style}"{rowspan_attr}>'
                            f"{_escape_html(label_part)}{inp}</td>"
                        )
            elif field and not _is_field_locked(field):
                value = field.get("value", "") or ""
                if readonly:
                    display = _render_readonly_span(value or text, field, field["location"])
                    html.append(
                        _readonly_td(
                            display,
                            cell_style=cell_style,
                            rowspan_attr=rowspan_attr,
                            location=field["location"],
                        )
                    )
                else:
                    use_textarea = _is_textarea_cell(field, row_label, rowspan)
                    if use_textarea:
                        widget_field = dict(field)
                        widget_field["widget"] = "textarea"
                        inp = _render_field_input(
                            field["location"],
                            widget_field,
                            css_class="docx-input",
                            value=value or text,
                        )
                    else:
                        inp = _render_field_input(
                            field["location"],
                            field,
                            css_class="docx-input",
                            value=value or text,
                        )
                    html.append(
                        f'<td class="docx-cell editable" style="{cell_style}"{rowspan_attr}>'
                        f"{inp}</td>"
                    )
            elif field and _is_field_locked(field):
                style = _inline_cell_style(cell_style, readonly=readonly)
                locked_bg = f"background:#f5f5f5;" if readonly else ""
                locked_val = (field.get("value") or "").strip() or text
                if readonly:
                    display = _render_readonly_span(locked_val, field, field.get("location", ""))
                    html.append(
                        _readonly_td(
                            display,
                            cell_style=cell_style,
                            rowspan_attr=rowspan_attr,
                            location=field.get("location", ""),
                            extra_class="locked",
                        )
                    )
                else:
                    html.append(
                        f'<td class="docx-cell locked" style="{style}{locked_bg}"{rowspan_attr}>'
                        f"{_escape_html(locked_val)}</td>"
                    )
            else:
                style = _inline_cell_style(cell_style, readonly=readonly)
                html.append(
                    f'<td class="docx-cell" style="{style}"{rowspan_attr}>'
                    f"{_escape_html(text)}</td>"
                )

        html.append("</tr>")

    html.append("</table>")
    return "\n".join(html)


# ── build_fill_html() ───────────────────────────────
# Builds HTML preview of the DOCX body in document order (tables + paragraphs).
# Parameters: template_path — DOCX file path; fields — parsed/prefilled field list
# Returns: HTML string wrapped in <div class="docx-preview">
# Key steps:
#   1. Walks doc.element.body children in order (tbl / p tags)
#   2. Renders each table via _render_table() and each paragraph inline
#   3. Paragraph locations use enumerate(doc.paragraphs) indices (same as doc_parser.py)
#   4. De-duplicates repeated <p> element references in the body walk
def build_fill_html(template_path: str, fields: list, mode: str = "edit") -> str:
    """
    Generate interactive HTML form from DOCX structure.

    Signature tables (Prepared By / Checked By / Approved By) render Name & Designation
    cells with class 'approver-field' — Laravel JS replaces options from the users table.
    Attachment columns render as file upload inputs with class 'attachment-field'.
    """
    from docx import Document

    doc = Document(template_path)
    field_map = {f["location"]: f for f in fields}
    paragraphs = doc.paragraphs

    # Body walk can surface the same <p> element more than once; keep the longest text
    # per para_index so blank-field paragraphs are not lost on an empty first visit.
    para_text_by_index: dict = {}
    for child in doc.element.body:
        tag = child.tag.split("}")[-1] if "}" in child.tag else child.tag
        if tag != "p":
            continue
        para_index = _para_index_for_element(child, paragraphs)
        if para_index is None:
            continue
        text = _para_text_from_element(child, exclude_textboxes=True)
        prev = para_text_by_index.get(para_index, "")
        if len(text) >= len(prev):
            para_text_by_index[para_index] = text

    seen_para_indices: set = set()
    textbox_by_parent_para: dict[int, tuple[int, object]] = {}
    for txbx_index, parent_para_index, txbx_element in iter_unique_textboxes(doc):
        textbox_by_parent_para[parent_para_index] = (txbx_index, txbx_element)

    preview_class = "docx-preview docx-preview-readonly" if _is_readonly_mode(mode) else "docx-preview"
    html_parts = [f'<div class="{preview_class}">']
    table_idx = 0

    for child in doc.element.body:
        tag = child.tag.split("}")[-1] if "}" in child.tag else child.tag

        if tag == "tbl":
            if table_idx < len(doc.tables):
                html_parts.append(
                    _render_table(doc.tables[table_idx], table_idx, field_map, mode)
                )
            table_idx += 1

        elif tag == "p":
            para_index = _para_index_for_element(child, paragraphs)
            if para_index is None:
                para_text = _para_text_from_element(child)
                if para_text:
                    html_parts.append(f'<p class="docx-para">{_escape_html(para_text)}</p>')
                continue

            if para_index in seen_para_indices:
                continue
            seen_para_indices.add(para_index)

            if para_index in textbox_by_parent_para:
                heading = (
                    paragraphs[para_index].text.strip()
                    or para_text_by_index.get(para_index, "")
                )
                txbx_index, txbx_element = textbox_by_parent_para[para_index]
                if heading:
                    from doc_parser import _para_text_from_element as _parser_para_text

                    W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
                    inner_paras = txbx_element.findall(f"{{{W_NS}}}p")
                    inner_heading = (
                        _parser_para_text(inner_paras[0]).strip() if inner_paras else ""
                    )
                    if dedupe_colon_label(heading) != dedupe_colon_label(inner_heading):
                        html_parts.append(
                            f'<p class="docx-para">{_escape_html(heading)}</p>'
                        )
                html_parts.append(
                    _render_textbox_html(txbx_index, txbx_element, field_map, mode)
                )
                continue

            para_text = para_text_by_index.get(
                para_index, _para_text_from_element(child, exclude_textboxes=True)
            )
            field = field_map.get(f"para_{para_index}")
            if field is None:
                blank_key = f"para_{para_index}_blank_0"
                field = field_map.get(blank_key)
            rendered = _render_paragraph(para_index, para_text, field, field_map, mode)
            if rendered:
                html_parts.append(rendered)

    html_parts.append("</div>")
    return "\n".join(html_parts)


# ── docx_image_map() ──────────────────────────────
# POST /docx-image-map — resolve embedded DOCX images with correct
# relationship chains and Word crop rectangles for NEQ preview only.
@app.post("/docx-image-map")
async def docx_image_map(file: UploadFile = File(...)):
    from neq_docx_preview import extract_image_placements

    try:
        content = await file.read()
        if not content:
            raise HTTPException(status_code=400, detail="Empty DOCX upload.")

        placements, messages = extract_image_placements(content)
        return {
            "status": "success",
            "placements": placements,
            "messages": messages,
        }
    except HTTPException:
        raise
    except Exception as exc:
        logger.exception("docx-image-map failed")
        raise HTTPException(status_code=500, detail=str(exc)) from exc


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("app:app",
                host=os.getenv("HOST", "127.0.0.1"),
                port=int(os.getenv("PORT", 8001)),
                reload=True)
