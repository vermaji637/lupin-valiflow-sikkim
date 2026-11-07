from fastapi import FastAPI, UploadFile, File, HTTPException, Form
from dotenv import load_dotenv
from doc_parser import (
    parse_docx,
    PARA_BLANK_RE,
    PARA_COLON_BLANK_RE,
    dedupe_colon_label,
    dedupe_repeated_text,
    has_paragraph_blank,
    is_locked_section_title,
    is_section_heading_paragraph,
    collect_paragraph_texts,
    iter_unique_textboxes,
    is_border_blank_paragraph,
    is_checkbox_cell,
    is_yes_no_radio_cell,
    is_paragraph_yes_no_radio,
    paragraph_yes_no_label,
    is_list_of_attachment_table,
    normalize_attachment_list_paragraphs_to_table,
    is_attachment_upload_column,
    is_report_approval_block_table,
    get_table_column_headers,
    resolve_sl_no_column_index,
    infer_field_widget,
    is_report_number_label,
    is_date_field_label,
    element_has_drawing_checkbox,
    is_two_col_header_row,
    is_two_col_grid_table,
    is_toc_table,
    is_toc_list_table,
    is_toc_entry_text,
    is_equipment_section_banner_row,
    is_repeated_column_header_row,
)
from docx.oxml.ns import qn
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
import base64
from pathlib import Path

logger = logging.getLogger("valiflow.docx")


DOTENV_PATH = os.path.join(os.path.dirname(__file__), ".env")
load_dotenv(dotenv_path=DOTENV_PATH, override=True)

# ── Pydantic Models ──────────────────────────
# FieldValue  — single field submission for /fill
# FillRequest — full /fill payload: file_id + fields list
# Pydantic model for one field value submitted to POST /fill.
class FieldValue(BaseModel):
    location: str
    value: str
    locked: bool = False
    type: str = ""

# Pydantic model for the full POST /fill payload (file_id + fields list).
class FillRequest(BaseModel):
    file_id: str
    original_filename: str
    fields: List[FieldValue]
    attachment_links: Optional[dict[str, Any]] = None
    equipment_id: Optional[str] = None
    table_ops: Optional[List[dict]] = None

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

PYTHON_SERVICE_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = PYTHON_SERVICE_DIR.parent
DEFAULT_PUBLIC_STORAGE = PROJECT_ROOT / "storage" / "app" / "public"


def _project_storage_path(env_key: str, *relative_parts: str) -> str:
    """Resolve storage dir from .env only when it belongs to this project tree."""
    default = DEFAULT_PUBLIC_STORAGE.joinpath(*relative_parts)
    configured = os.getenv(env_key, "").strip()
    if configured:
        path = Path(configured)
        try:
            resolved = path.resolve()
            project_root = PROJECT_ROOT.resolve()
            if resolved == default.resolve() or project_root in resolved.parents:
                return str(resolved)
        except OSError:
            pass
        logger.warning(
            "Ignoring %s=%s (outside project); using %s",
            env_key,
            configured,
            default,
        )

    return str(default)


UPLOAD_PATH = _project_storage_path("UPLOAD_PATH", "templates", "docx")
GENERATED_REPORTS_PATH = _project_storage_path(
    "GENERATED_REPORTS_PATH",
    "generated_reports",
)
ATTACHMENTS_PATH = _project_storage_path("ATTACHMENTS_PATH", "attachments")

LIBREOFFICE = os.getenv("LIBREOFFICE_BIN", "soffice")
REQUIRED_DIRS = [
    UPLOAD_PATH,
    GENERATED_REPORTS_PATH,
    ATTACHMENTS_PATH,
]


def resolve_docx_template_path(file_id: str) -> str:
    """Find template DOCX for preview/fill; restore from filled output when template is missing."""
    for ext in (".docx", ".doc"):
        candidate = os.path.join(UPLOAD_PATH, f"{file_id}{ext}")
        if os.path.exists(candidate):
            return candidate

    filled_path = os.path.join(GENERATED_REPORTS_PATH, f"{file_id}_filled.docx")
    if os.path.exists(filled_path):
        os.makedirs(UPLOAD_PATH, exist_ok=True)
        restored = os.path.join(UPLOAD_PATH, f"{file_id}.docx")
        if not os.path.exists(restored):
            shutil.copy2(filled_path, restored)
            logger.info(
                "Restored missing template from filled DOCX: %s -> %s",
                filled_path,
                restored,
            )
        return restored if os.path.exists(restored) else filled_path

    raise HTTPException(status_code=404, detail=f"File nahi mila: {file_id}")


@app.on_event("startup")
# Create upload, generated-reports, and attachments directories on service start.
def ensure_required_dirs() -> None:
    for directory in REQUIRED_DIRS:
        os.makedirs(directory, exist_ok=True)


# ── convert_doc_to_docx() ───────────────────────────
# Converts a legacy .doc file to .docx using LibreOffice headless.
# Parameters: doc_path — absolute path to the uploaded .doc file
# Returns: absolute path to the generated .docx in the same folder
# Key steps:
#   1. Verifies LibreOffice (soffice.exe) is installed
#   2. Runs --headless --convert-to docx into the source directory
#   3. Returns the .docx path or raises if conversion fails
def convert_doc_to_docx(doc_path: str) -> str:
    output_dir = os.path.dirname(doc_path)
    command = [
        LIBREOFFICE,
        "--headless",
        "--convert-to",
        "docx",
        "--outdir",
        output_dir,
        doc_path,
    ]
    try:
        result = subprocess.run(command, capture_output=True, text=True, timeout=60)
    except FileNotFoundError as exc:
        raise Exception(
            "LibreOffice binary not found. Set LIBREOFFICE_BIN or install LibreOffice."
        ) from exc

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


# Browsers and Cursor probe /json/version. This service is not a browser debugger,
# so answer 200 instead of leaving a 404 in the log.
@app.get("/json/version")
def json_version():
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
        "libreoffice": bool(shutil.which(LIBREOFFICE) or os.path.exists(LIBREOFFICE)),
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
    # Reject uploads that are not .doc or .docx.
    if not file.filename.endswith(('.docx', '.doc')):
        raise HTTPException(
            status_code=400,
            detail="allwed only .doc ya .docx file"
        )

    file_id = str(uuid.uuid4())
    file_ext = os.path.splitext(file.filename)[1]
    save_filename = f"{file_id}{file_ext}"
    save_path = os.path.join(UPLOAD_PATH, save_filename)
    os.makedirs(os.path.dirname(save_path), exist_ok=True)

    # Save uploaded bytes to UPLOAD_PATH as {uuid}.docx or {uuid}.doc.
    try:
        with open(save_path, "wb") as buffer:
            shutil.copyfileobj(file.file, buffer)
    except Exception as e:
        raise HTTPException(
            status_code=500,
            detail=f"File save error: {str(e)}"
        )

    if save_path.endswith(".doc"):
        # Convert legacy .doc to .docx via LibreOffice before parsing.
        try:
            save_path = convert_doc_to_docx(save_path)
        except Exception as e:
            raise HTTPException(
                status_code=500,
                detail=f"DOC convert error: {str(e)}"
            )

    try:
        # Run doc_parser.parse_docx to detect fillable fields in body tables and paragraphs.
        result = parse_docx(save_path)
    except Exception as e:
        if os.path.exists(save_path):
            os.remove(save_path)
        raise HTTPException(
            status_code=500,
            detail=f"Parse error: {str(e)}"
        )

    editable_count = sum(1 for f in result["fillable_fields"] if not f.get("locked"))
    locked_count = sum(1 for f in result["fillable_fields"] if f.get("locked"))
    prefilled_count = sum(1 for f in result["fillable_fields"] if f.get("prefilled"))
    # Count approver fields still eligible for e-stamp (not prefilled in template).
    stamp_eligible_count = sum(
        1 for f in result["fillable_fields"]
        if f.get("approver_role") and f.get("stamp_eligible", True)
    )

    logger.info(
        "DOCX parse field summary: total=%s locked=%s editable=%s prefilled_signature=%s stamp_eligible_approver=%s",
        len(result["fillable_fields"]),
        locked_count,
        editable_count,
        prefilled_count,
        stamp_eligible_count,
    )

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
            "prefilled_signature_fields": prefilled_count,
            "stamp_eligible_approver_fields": stamp_eligible_count,
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
    template_path = resolve_docx_template_path(request.file_id)

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

    # Debug: print first few normalized field values to stdout.
    for field in field_values[:5]:
        print(f"Field value raw: {repr(field['value'])}")

    try:
        # Write user values into template; locked fields are skipped by report_generator.
        result_path, repaired_values = fill_docx(
            template_path,
            field_values,
            output_path,
            request.attachment_links or {},
            request.equipment_id or "",
            request.table_ops or [],
        )
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
        "repaired_field_values": repaired_values,
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
    template_path = resolve_docx_template_path(file_id)

    try:
        # Always re-parse so new blank-detection rules apply; merge stored values by location.
        fresh = parse_docx(template_path)["fillable_fields"]
        stored = json.loads(fields_json) if fields_json else []
        stored_by_loc = {f.get("location"): f for f in stored if f.get("location")}
        # Legacy para_{n} keys without _blank_ suffix — map by parent para index.
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

        is_pre_approval_mode = (
            mode != "readonly"
            and mode == "pre_approval"
        )

        fields = []
        for ff in fresh:
            loc = ff.get("location")
            lbl_lower = (ff.get("label") or "").lower()
            val_lower = (str(ff.get("value") or "")).lower()
            is_heading_or_fixed = (
                any(k in lbl_lower for k in ("methodology", "report approval"))
                or any(k in val_lower for k in ("methodology", "report approval"))
            )
            is_col_0_label = ff.get("type") == "table_2col_label" or bool(loc and loc.endswith("_col_0"))

            if loc in stored_by_loc:
                sv = stored_by_loc[loc]
                if sv.get("value") not in (None, ""):
                    ff["value"] = sv["value"]
                if is_heading_or_fixed or is_col_0_label:
                    ff["locked"] = True
                # In pre-approval mode, unlock content fields (except row_0 headers and col_0 labels)
                elif is_pre_approval_mode:
                    if loc and loc.endswith("_row_0_col_1") and ff.get("type") == "table_2col":
                        ff["locked"] = False
                    elif ff.get("type") not in ("approval_stamp", "table_sl_no") and not (loc and loc.endswith("_row_0")):
                        ff["locked"] = False
                    else:
                        ff["locked"] = sv.get("locked", ff.get("locked", True))
                else:
                    # In post-pre-approval execution / standard mode:
                    is_pre = (
                        sv.get("is_pre_approver")
                        or ff.get("is_pre_approver")
                        or sv.get("approver_role") in (
                            "prepared_by_engineering_name",
                            "checked_by_engineering_name",
                            "checked_by_production_name",
                            "checked_by_validation_name",
                            "checked_by_quality_name",
                            "approved_by_user_name",
                            "approved_by_quality_name",
                        )
                    )
                    if is_pre:
                        ff["locked"] = True
                        ff["is_pre_approver"] = True
                    elif "locked" in sv:
                        sv_locked = sv["locked"]
                        if isinstance(sv_locked, str):
                            ff["locked"] = sv_locked.strip().lower() in ("1", "true", "yes")
                        else:
                            ff["locked"] = bool(sv_locked)
                    # Paragraphs and 2-col keys (Col 0) are strictly locked!
                    elif ff.get("type") in ("paragraph_content", "table_2col_label") or (loc and loc.endswith("_col_0")):
                        ff["locked"] = True
                    # 2-col values (Col 1) are fillable (excluding approvers and locked fields)!
                    elif (ff.get("type") == "table_2col" or (loc and (loc.endswith("_col_1") or re.match(r"^table_\d+_row_\d+$", loc)))) and ff.get("type") != "approver":
                        ff["locked"] = False
                # Laravel may upgrade approval-page Name cells to approver dropdowns after parse.
                for meta_key in (
                    "type",
                    "widget",
                    "approver_role",
                    "user_id",
                    "signature_date_location",
                    "stamp_eligible",
                    "is_pre_approver",
                ):
                    if meta_key not in sv:
                        continue
                    val = sv[meta_key]
                    if meta_key in ("stamp_eligible", "is_pre_approver") or val not in (None, ""):
                        ff[meta_key] = val
            else:
                parent_para = ff.get("parent_para")
                if is_heading_or_fixed or is_col_0_label:
                    ff["locked"] = True
                elif parent_para is not None and parent_para in legacy_para_by_index:
                    sv = legacy_para_by_index[parent_para]
                    if sv.get("value") not in (None, ""):
                        ff["value"] = sv["value"]
                    if is_pre_approval_mode:
                        ff["locked"] = False
                    else:
                        if "locked" in sv:
                            sv_locked = sv["locked"]
                            if isinstance(sv_locked, str):
                                ff["locked"] = sv_locked.strip().lower() in ("1", "true", "yes")
                            else:
                                ff["locked"] = bool(sv_locked)
                        elif ff.get("type") in ("paragraph_content", "table_2col_label") or (loc and loc.endswith("_col_0")):
                            ff["locked"] = True
                elif is_pre_approval_mode:
                    if loc and loc.endswith("_row_0_col_1") and ff.get("type") == "table_2col":
                        ff["locked"] = False
                    elif ff.get("type") not in ("approval_stamp", "table_sl_no") and not (loc and loc.endswith("_row_0")):
                        ff["locked"] = False
                else:
                    if ff.get("type") in ("paragraph_content", "table_2col_label") or (loc and loc.endswith("_col_0")):
                        ff["locked"] = True
                    elif ff.get("type") == "table_2col" or (loc and (loc.endswith("_col_1") or re.match(r"^table_\d+_row_\d+$", loc))):
                        ff["locked"] = False
            fields.append(ff)

        html = build_fill_html(template_path, fields, mode=mode)
        debug_widgets = _collect_widget_debug(fields)
        _log_widget_debug(debug_widgets)
        # Return HTML for embedding in Laravel fill.blade.php (read-only; does not modify DOCX).
        return {"status": "success", "html": html, "debug_widgets": debug_widgets}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"

# Gray background for readonly preview cells.
READONLY_BG = "#F9F9F9"
DOCX_CELL_BORDER = "border:1px solid #000;padding:6px 8px;vertical-align:middle;"


# Escape raw DOCX text for safe embedding in HTML preview output.
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


def _inline_cell_style(cell_style: str = "", *, readonly: bool = False, value_cell: bool = False) -> str:
    """Inline styles so approver view matches fill form without page-specific CSS."""
    parts = [DOCX_CELL_BORDER, cell_style]
    if readonly and value_cell:
        parts.append(f"background:{READONLY_BG};")
    return "".join(parts)


def _is_readonly_mode(mode: str) -> bool:
    # Preview mode "readonly" shows filled values without editable inputs.
    return (mode or "edit").strip().lower() == "readonly"


# Convert ISO yyyy-mm-dd dates to dd-mm-yyyy for readonly preview display.
def _format_readonly_date(value: str) -> str:
    value = (value or "").strip()
    if not value:
        return "—"
    match = re.match(r"^(\d{4})-(\d{2})-(\d{2})$", value)
    if match:
        return f"{match.group(3)}-{match.group(2)}-{match.group(1)}"
    return _escape_html(value)


# Format a field value for readonly preview (checkbox marks, dates, multiline text).
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


# Wrap a readonly display value in a span with optional data-field location attribute.
def _render_readonly_span(value, field: dict | None = None, location: str = "") -> str:
    display = _format_readonly_display(value, field)
    loc_attr = f' data-field="{_escape_html(location)}"' if location else ""
    return f'<span class="docx-readonly-value"{loc_attr}>{display}</span>'


# Build a readonly table cell with gray background and optional data-field attribute.
def _readonly_td(
    content: str,
    *,
    cell_style: str = "",
    rowspan_attr: str = "",
    colspan_attr: str = "",
    location: str = "",
    extra_class: str = "readonly",
) -> str:
    style = _inline_cell_style(cell_style, readonly=True, value_cell=True)
    data_attr = f' data-field="{_escape_html(location)}"' if location else ""
    return (
        f'<td class="docx-cell {extra_class}" style="{style}"'
        f'{rowspan_attr}{colspan_attr}{data_attr}>{content}</td>'
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


def _parse_toc_entry_element(p_element) -> tuple[str, str]:
    """Extract clean title and page number from a Word TOC paragraph."""
    tokens = []
    for elem in p_element.iter():
        tag = elem.tag.split("}")[-1]
        if tag == "t" and elem.text:
            tokens.append(elem.text)
        elif tag == "tab":
            tokens.append(" ")
    raw = "".join(tokens).strip()
    if not raw or raw.lower() == "table of contents":
        return "", ""

    # Match trailing page number (digits at end)
    m = re.search(r"^(.*?)[ \t\.\u2026\u22ef]+(\d+)\s*$", raw)
    if not m:
        m = re.search(r"^(.*?)(\d+)\s*$", raw)

    if m:
        title_part = m.group(1).strip()
        page_num = m.group(2).strip()
    else:
        title_part = raw
        page_num = ""

    # Clean trailing dots, leader dots, colons from title
    title_part = re.sub(r"[\.\u2026\u22ef\t_:]+$", "", title_part).strip()
    # Ensure space between section number and title, e.g. '1.0OBJECTIVE' -> '1.0 OBJECTIVE'
    title_part = re.sub(r"^(\d+\.\d+)\s*([A-Za-z])", r"\1 \2", title_part)
    title_part = re.sub(r"\s+", " ", title_part)

    return title_part, page_num


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
    if _is_readonly_mode(mode):
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


# Render bordered text box content (History of Equipment, recommendation lines) as HTML.
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

        if re.search(r"\beffective\s*date\b", inner_text, re.IGNORECASE):
            label_part = "EFFECTIVE DATE: "
            eff_val = ""
            field = field_map.get(location_base) or field_map.get(f"{location_base}_blank_0")
            if field:
                eff_val = field.get("value", "") or ""
            if not eff_val:
                for f_item in field_map.values():
                    if f_item.get("cover_field") == "effective_date":
                        eff_val = f_item.get("value", "") or ""
                        field = f_item
                        break
            if _is_readonly_mode(mode):
                if eff_val:
                    display = _render_readonly_span(eff_val, field or {}, location_base)
                    lines.append(
                        f'<p class="docx-para docx-textbox-line docx-para-editable">'
                        f"{_escape_html(label_part)}{display}</p>"
                    )
                else:
                    lines.append(
                        f'<p class="docx-para docx-textbox-line docx-para-editable">'
                        f"{_escape_html(label_part)}</p>"
                    )
            else:
                inp = _render_field_input(
                    location_base,
                    field or {"label": "Effective Date", "value": eff_val, "type": "textbox_blank", "widget": "date"},
                    css_class="docx-input-inline",
                    value=eff_val,
                    inline=True,
                )
                lines.append(
                    f'<p class="docx-para docx-textbox-line docx-para-editable">'
                    f"{_escape_html(label_part)}{inp}</p>"
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


# Render multiple inline inputs for paragraphs with several dot/underscore blanks.
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


# Render Yes/No radio pair for inline paragraph radio fields.
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
    location: str, field: dict, cell_text: str, mode: str = "edit", colspan_attr: str = ""
) -> str:
    loc = _escape_html(location)
    if _is_readonly_mode(mode):
        display = _format_readonly_display(field.get("value", ""), field)
        return _readonly_td(display, colspan_attr=colspan_attr, location=location)
    value = (field.get("value") or "").strip().lower()
    yes_checked = "checked" if value == "yes" else ""
    no_checked = "checked" if value == "no" else ""
    return (
        f'<td class="docx-cell editable docx-checkbox-cell"{colspan_attr}>'
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
# Render locked Name/Signature/Date cells on the APPROVAL PAGE table (stamp placeholders).
def _render_approval_stamp_cell(field: dict, mode: str = "edit", colspan_attr: str = "") -> str:
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
        f'<td class="docx-cell locked approval-stamp-cell" style="{style}"{colspan_attr}{loc_attr}>'
        f"{content}</td>"
    )


# Render approver dropdown placeholder; Laravel JS hydrates options from users table.
def _render_approver_cell(field: dict, mode: str = "edit", colspan_attr: str = "") -> str:
    location = _escape_html(field.get("location", ""))
    role = _escape_html(field.get("approver_role", ""))
    date_loc = _escape_html(field.get("signature_date_location", ""))
    value = field.get("value", "")
    user_id = field.get("user_id", "")
    is_pre = (
        field.get("is_pre_approver")
        or role in (
            "prepared_by_engineering_name",
            "checked_by_engineering_name",
            "checked_by_production_name",
            "checked_by_validation_name",
            "checked_by_quality_name",
            "approved_by_user_name",
            "approved_by_quality_name",
        )
        or bool(re.match(r"^table_[123]_row_\d+_col_\d+$", location))
    )
    if _is_readonly_mode(mode) or _is_field_locked(field) or (is_pre and mode != "pre_approval"):
        display = _render_readonly_span(value, field, location)
        hidden_input = (
            f'<input type="hidden" name="fields[{location}]" value="{_escape_html(value)}">'
            if location else ""
        )
        return _readonly_td(
            f"{display}{hidden_input}",
            colspan_attr=colspan_attr,
            location=location,
            extra_class="readonly docx-approver-readonly font-weight-bold text-dark",
        )
    is_pre_attr = ' data-is-pre-approver="1"' if is_pre else ""
    required = "" if (is_pre or mode == "pre_approval") else (" required" if role else "")
    user_id_attr = f' data-user-id="{user_id}"' if user_id else ""
    val_attr = f' value="{_escape_html(value)}"' if value else ' value=""'
    return (
        f'<td class="docx-cell editable docx-approver-cell"{colspan_attr}>'
        f'<select class="docx-input approver-field" '
        f'data-approver-role="{role}" '
        f'data-field-location="{location}" '
        f'data-signature-date-location="{date_loc}"{user_id_attr}{is_pre_attr}{required}>'
        f'<option value="">-- Select User --</option>'
        f"</select>"
        f'<input type="hidden" name="fields[{location}]" '
        f'class="approver-name-field" data-approver-role="{role}" '
        f'data-field-location="{location}"{is_pre_attr}{val_attr}>'
        f"</td>"
    )


# ── _render_attachment_cell() ───────────────────────
# Renders a file upload input for certificate / proof attachment columns.
def _render_attachment_cell(field: dict, mode: str = "edit", colspan_attr: str = "") -> str:
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
            f'style="{style}"{colspan_attr} data-field="{location}">'
            f"{display}</td>"
        )
    value_attr = f' value="{value}"' if value else ""

    return (
        f'<td class="docx-cell editable docx-attachment-cell" data-attachment-location="{location}"{colspan_attr}>'
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
    if val.strip() in ("—", "-", "–"):
        val = ""

    if widget == "textarea":
        return (
            f'<textarea name="fields[{loc}]" class="{css_class}" rows="4" '
            f'style="width:100%;" placeholder="{ph}">{val}</textarea>'
        )

    if widget in ("datetime", "datetime-local"):
        input_type = "datetime-local"
    elif widget in ("date", "time"):
        input_type = widget
    else:
        input_type = "text"
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


def _value_repeats_colon_label(para_text: str, value: str) -> bool:
    """True when a stored value is just a second copy of the colon label."""
    label = re.sub(r":\s*$", "", dedupe_colon_label(para_text or "")).strip().lower()
    stored = re.sub(r"\s+", " ", (value or "").strip()).rstrip(":").lower()
    return bool(label) and stored == label


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
    if not para_text or not para_text.strip():
        return ""
    para_text = dedupe_repeated_text(para_text)
    clean_p = dedupe_colon_label(para_text).strip()
    clean_upper = clean_p.upper()
    # 1.0 OBJECTIVE:, METHODOLOGY:, REPORT APPROVAL: headings are titles, not fill boxes.
    if (
        is_locked_section_title(para_text)
        or is_section_heading_paragraph(para_text)
        or re.match(r"^(?:\d+(?:\.\d+)*\s*)?(?:METHODOLOGY|REPORT APPROVAL)\b", clean_upper)
    ):
        return f'<p class="docx-para docx-heading-target" data-heading-text="{_escape_html(clean_upper)}">{_escape_html(clean_p)}</p>'

    if field and field.get("type") == "paragraph_content" and PARA_BLANK_RE.search(para_text):
        field["type"] = "paragraph_blank"

    if field and field.get("type") == "paragraph_content":
        val = field.get("value")
        if val is None or val == "":
            val = para_text
        val = dedupe_repeated_text(val)
        if _is_readonly_mode(mode) or mode != "pre_approval" or _is_field_locked(field):
            h_cls = " docx-heading-target" if (is_section_heading_paragraph(val) or is_locked_section_title(val)) else ""
            h_attr = f' data-heading-text="{_escape_html(val.upper().strip())}"' if h_cls else ""
            return f'<p class="docx-para{h_cls}"{h_attr}>{_escape_html(val)}</p>'
        else:
            loc = _escape_html(field.get("location", f"para_{para_index}"))
            val_esc = _escape_html(val)
            calc_rows = max(2, min(8, len(val) // 70 + 1))
            return (
                f'<div class="docx-para-edit-wrapper" style="margin: 6px 0;">'
                f'<textarea name="fields[{loc}]" class="docx-input docx-para-content" '
                f'rows="{calc_rows}" style="width:100%; box-sizing:border-box; font-family:inherit; font-size:inherit; line-height:1.5; resize:vertical; padding:6px 10px; border:1px solid #facc15; border-bottom:2px solid #4e73df; border-radius:4px; background-color:#fffde7 !important;">{val_esc}</textarea>'
                f'</div>'
            )

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

    if field and _is_readonly_mode(mode):
        # Locked-after-submit fields must still show their stored values on
        # second/third approval readonly preview (Remarks dotted lines, Reviewed By, …).
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
            if _value_repeats_colon_label(para_text, field.get("value", "")):
                return f'<p class="docx-para">{_escape_html(dedupe_colon_label(para_text))}</p>'
            label_part = re.sub(r":\s*$", ": ", dedupe_colon_label(para_text))
            return f'<p class="docx-para">{_escape_html(label_part)}{display}</p>'
        match = PARA_BLANK_RE.search(para_text)
        if match:
            before = _escape_html(para_text[: match.start()])
            after = _escape_html(para_text[match.end() :])
            return f'<p class="docx-para">{before}{display}{after}</p>'
        # Leader-dot / ellipsis-only lines: show value instead of empty template dots.
        if PARA_BLANK_RE.fullmatch(para_text.strip() or "") or re.fullmatch(
            r"[\s\.\u2026…]+", para_text or ""
        ):
            return f'<p class="docx-para">{display}</p>'
        if para_text:
            # Prefer stored value when present; keep label text as context.
            stored = (field.get("value") or "").strip()
            if stored and stored.lower() not in para_text.lower():
                return f'<p class="docx-para">{_escape_html(para_text)} {display}</p>'
            if stored:
                return f'<p class="docx-para">{display}</p>'
            return f'<p class="docx-para">{_escape_html(para_text)}</p>'
        return f'<p class="docx-para">{display}</p>'

    if field and not _is_field_locked(field):
        placeholder = re.sub(r"\{\{BLANK\}\}", "", (field.get("label") or "fill here"))[:80]
        visible_label = re.sub(r":\s*$", "", dedupe_colon_label(para_text)).strip().lower()
        if placeholder.strip().lower() == visible_label:
            placeholder = " "
        inp = _render_field_input(
            loc,
            field,
            css_class="docx-input-inline",
            placeholder=placeholder,
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

    # Locked in edit mode: show stored value (or template text) without an input.
    if field and _is_field_locked(field):
        locked_val = (field.get("value") or "").strip()
        if locked_val:
            if _value_repeats_colon_label(para_text, locked_val):
                return f'<p class="docx-para">{_escape_html(dedupe_colon_label(para_text))}</p>'
            if PARA_COLON_BLANK_RE.search(dedupe_colon_label(para_text).strip()):
                label_part = re.sub(r":\s*$", ": ", dedupe_colon_label(para_text))
                return (
                    f'<p class="docx-para">'
                    f"{_escape_html(label_part)}"
                    f"{_render_readonly_span(locked_val, field, loc)}</p>"
                )
            return f'<p class="docx-para">{_render_readonly_span(locked_val, field, loc)}</p>'
        if para_text:
            return f'<p class="docx-para">{_escape_html(dedupe_colon_label(para_text))}</p>'
        return ""

    if para_text:
        if not _is_readonly_mode(mode):
            normalized = dedupe_colon_label(para_text)
            display_label = re.sub(r":\s*$", "", normalized).strip()
            if is_date_field_label(display_label) and (
                PARA_COLON_BLANK_RE.search(normalized.strip())
                or re.search(r"(?i)effective\s*date", para_text)
            ):
                pseudo = {
                    "label": display_label or "Effective Date",
                    "value": (field or {}).get("value", ""),
                    "locked": False,
                    "type": "paragraph_blank",
                    "location": loc,
                    "widget": "date",
                }
                inp = _render_field_input(
                    loc,
                    pseudo,
                    css_class="docx-input-inline",
                    placeholder="dd-mm-yyyy",
                    value=pseudo.get("value", ""),
                    inline=True,
                )
                label_part = re.sub(r":\s*$", ": ", normalized)
                return (
                    f'<p class="docx-para docx-para-editable">'
                    f"{_escape_html(label_part)}{inp}</p>"
                )
        if mode == "pre_approval" and not _is_readonly_mode(mode) and not is_locked_section_title(para_text) and not is_section_heading_paragraph(para_text) and len(para_text.strip()) > 5:
            loc = f"para_{para_index}"
            val_esc = _escape_html(para_text)
            calc_rows = max(2, min(8, len(para_text) // 70 + 1))
            return (
                f'<div class="docx-para-edit-wrapper" style="margin: 6px 0;">'
                f'<textarea name="fields[{loc}]" class="docx-input docx-para-content" '
                f'rows="{calc_rows}" style="width:100%; box-sizing:border-box; font-family:inherit; font-size:inherit; line-height:1.5; resize:vertical; padding:6px 10px; border:1px solid #facc15; border-bottom:2px solid #4e73df; border-radius:4px; background-color:#fffde7 !important;">{val_esc}</textarea>'
                f'</div>'
            )
        return f'<p class="docx-para">{_escape_html(dedupe_colon_label(para_text))}</p>'

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
    colspan_attr: str = "",
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
        f'<td class="docx-cell{extra}" style="{style}"{rowspan_attr}{colspan_attr}{loc_attr}>'
        f"{content}</td>"
    )


def _cell_colspan(cell, cells) -> int:
    """Extract gridSpan from cell tcPr or count occurrences of identical _tc in cells."""
    try:
        tcPr = cell._tc.get_or_add_tcPr()
        grid_span_el = tcPr.find(qn("w:gridSpan"))
        if grid_span_el is not None:
            val = grid_span_el.get(qn("w:val"))
            if val and val.isdigit():
                return int(val)
    except Exception:
        pass
    same_count = sum(1 for c in cells if c._tc == cell._tc)
    if same_count > 1:
        return same_count
    return 1


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
    html = [f'<table class="docx-table" data-table-index="{t_idx}">']
    readonly = _is_readonly_mode(mode)
    sl_no_col = _table_sl_no_col_index(table)
    toc_table = is_toc_table(table) or is_toc_list_table(table)
    col_headers = get_table_column_headers(table, is_report_approval_block_table(table))

    for r_idx, row in enumerate(table.rows):
        html.append(f'<tr data-row-index="{r_idx}">')

        cells = row.cells
        # "Mechanical Adjustment:" / "Machine Performance:" / "Major components :" are merged title rows.
        # Col 0 is the Sr. No. column, so without this they render as the row number.
        if is_equipment_section_banner_row(row):
            banner = dedupe_colon_label(cells[0].text.strip()) if cells else ""
            colspan = len(cells) or 1
            if r_idx == 0:
                style = _inline_cell_style("font-weight:bold;background:#f1f5f9; color:#1e293b;", readonly=readonly)
                html.append(
                    f'<th class="docx-cell docx-table-header" colspan="{colspan}" style="{style}">'
                    f"{_escape_html(banner)}</th>"
                )
            else:
                style = _inline_cell_style("font-weight:bold;background:#f8fafc; color:#1e293b;", readonly=readonly)
                html.append(
                    f'<td class="docx-cell" colspan="{colspan}" style="{style}">'
                    f"{_escape_html(banner)}</td>"
                )
            html.append("</tr>")
            continue
        if r_idx > 0 and is_repeated_column_header_row(row, col_headers):
            seen_header: set[int] = set()
            for cell in cells:
                tc_id = id(cell._tc)
                if tc_id in seen_header:
                    continue
                seen_header.add(tc_id)
                c_span = _cell_colspan(cell, cells)
                c_span_attr = f' colspan="{c_span}"' if c_span > 1 else ""
                style = _inline_cell_style("font-weight:bold;", readonly=readonly)
                html.append(
                    f'<td class="docx-cell"{c_span_attr} style="{style}">'
                    f"{_escape_html(cell.text.strip())}</td>"
                )
            html.append("</tr>")
            continue
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

            colspan = _cell_colspan(cell, cells)
            colspan_attr = f' colspan="{colspan}"' if colspan > 1 else ""

            text = dedupe_colon_label(cell.text.strip())
            is_header = r_idx == 0 and not (is_two_col and not is_two_col_grid_table(table) and not is_two_col_header_row(cells, table.rows))
            cell_style = "font-weight:bold;" if is_header else ""
            rowspan_attr = ""

            if is_header:
                style = _inline_cell_style(cell_style + "background:#f1f5f9; color:#1e293b;", readonly=readonly)
                html.append(
                    f'<th class="docx-cell docx-table-header docx-heading-target" data-heading-text="{_escape_html(text.upper())}" style="{style}"{rowspan_attr}{colspan_attr}>'
                    f"{_escape_html(text)}</th>"
                )
                continue

            if toc_table or is_toc_entry_text(text):
                style = _inline_cell_style(cell_style, readonly=readonly)
                lines = [ln.strip() for ln in text.split("\n") if ln.strip()]
                rendered_lines = []
                for l_idx, line in enumerate(lines):
                    m_toc = re.search(r"^(.*?)[ \t\.\u2026\u22ef]{3,}\s*(\d+)\s*$", line)
                    if m_toc and not readonly:
                        title_part = m_toc.group(1).strip()
                        page_part = m_toc.group(2).strip()
                        loc = f"table_{t_idx}_row_{r_idx}_toc_{l_idx}"
                        val = page_part
                        if field_map and loc in field_map:
                            stored = field_map[loc].get("value")
                            if stored is not None and str(stored).strip() != "":
                                val = str(stored).strip()
                        m_sec = re.match(r"^(\d+(?:\.\d+)*)\s*(.*)$", title_part)
                        sec_num = m_sec.group(1) if m_sec else ""
                        clean_title = m_sec.group(2) if m_sec else title_part
                        rendered_lines.append(
                            f'<div class="docx-toc-line" style="display: flex; align-items: baseline; justify-content: space-between; padding: 2px 0;" data-toc-sec="{_escape_html(sec_num)}" data-toc-title="{_escape_html(clean_title)}">'
                            f'<span class="toc-title">{_escape_html(title_part)}</span>'
                            f'<span class="toc-dots" style="flex-grow: 1; border-bottom: 2px dotted #94a3b8; margin: 0 8px; min-width: 16px; height: 1em;"></span>'
                            f'<input type="number" min="1" max="999" name="fields[{loc}]" '
                            f'class="docx-input docx-toc-page-input" '
                            f'data-toc-sec="{_escape_html(sec_num)}" '
                            f'data-toc-title="{_escape_html(clean_title)}" '
                            f'value="{_escape_html(val)}" '
                            f'style="width: 52px; height: 24px; text-align: center; font-weight: 600; font-size: 12px; padding: 1px 4px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fffde7; margin-left: 6px;">'
                            f'</div>'
                        )
                    else:
                        rendered_lines.append(f'<div>{_escape_html(line)}</div>')
                cell_content = "".join(rendered_lines) if rendered_lines else _escape_html(text)
                html.append(
                    f'<td class="docx-cell" style="{style}"{rowspan_attr}{colspan_attr}>'
                    f"{cell_content}</td>"
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
                        colspan_attr=colspan_attr,
                        location=loc,
                        readonly=readonly or r_idx > 0,
                    )
                )
                continue

            if field and str(field.get("type", "")).startswith("approval_stamp"):
                html.append(_render_approval_stamp_cell(field, mode, colspan_attr=colspan_attr))
                continue

            if field and field.get("type") == "approver" and not _is_field_locked(field):
                html.append(_render_approver_cell(field, mode, colspan_attr=colspan_attr))
                continue

            if field and (field.get("type") == "attachment" or field.get("widget") == "file") and not _is_field_locked(field):
                html.append(_render_attachment_cell(field, mode, colspan_attr=colspan_attr))
                continue

            # List-of-attachment rows skipped at parse time (Dust Extractor, Area Recovery)
            # still need a file control on the Attachment No. column.
            header_label = col_headers[col_idx] if col_idx < len(col_headers) else ""
            if (
                not readonly
                and r_idx > 0
                and is_attachment_upload_column(table, header_label)
                and (field is None or str(field.get("type", "")) == "attachment" or field.get("widget") == "file")
            ):
                upload_field = field or {
                    "location": f"table_{t_idx}_row_{r_idx}_col_{col_idx}",
                    "type": "attachment",
                    "value": "",
                    "label": "Attachment No.",
                }
                html.append(_render_attachment_cell(upload_field, mode, colspan_attr=colspan_attr))
                continue

            if field and field.get("type") == "table_radio" and not _is_field_locked(field):
                html.append(_render_radio_yes_no_cell(field["location"], field, text, mode, colspan_attr=colspan_attr))
                continue

            if (is_yes_no_radio_cell(text) or is_checkbox_cell(text)) and field is None:
                loc = f"table_{t_idx}_row_{r_idx}_col_{col_idx}"
                pseudo = {"type": "table_radio", "label": row_label, "value": text}
                html.append(_render_radio_yes_no_cell(loc, pseudo, text, mode, colspan_attr=colspan_attr))
                continue

            if is_two_col and col_idx == 0:
                col0_loc = field["location"] if (field and "location" in field) else f"table_{t_idx}_row_{r_idx}_col_0"
                col0_val = (field.get("value") if field else "") or text
                if col0_val.strip() in ("—", "-", "–"):
                    col0_val = ""
                is_label_type = (field and field.get("type") == "table_2col_label") or (field is None)
                field_label = (field.get("label", "") if field else "") or row_label or text
                is_deviation = "deviation" in field_label.lower() or "deviation" in text.lower()
                if not is_two_col_grid_table(table) or readonly or (field and _is_field_locked(field)) or (is_label_type and mode != "pre_approval"):
                    display = _render_readonly_span(text or col0_val, field, col0_loc)
                    html.append(_readonly_td(display, cell_style=cell_style, rowspan_attr=rowspan_attr, colspan_attr=colspan_attr, location=col0_loc))
                    continue
                else:
                    use_textarea = is_deviation or _is_textarea_cell(field, row_label, rowspan)
                    ph = "Enter deviation..." if is_deviation else "Parameter / Item..."
                    if use_textarea:
                        widget_field = dict(field) if field else {"location": col0_loc, "label": text, "type": "table_2col_grid"}
                        widget_field["widget"] = "textarea"
                        inp = _render_field_input(
                            col0_loc,
                            widget_field,
                            css_class="docx-input",
                            placeholder=ph,
                            value=col0_val,
                        )
                    else:
                        inp = _render_field_input(
                            col0_loc,
                            field or {"location": col0_loc, "label": text, "type": "table_2col_label"},
                            css_class="docx-input",
                            placeholder=ph,
                            value=col0_val,
                        )
                    html.append(
                        f'<td class="docx-cell editable" style="{cell_style}"{rowspan_attr}{colspan_attr}>'
                        f"{inp}</td>"
                    )
                continue

            if is_two_col and col_idx == 1 and field is None:
                col1_loc = f"table_{t_idx}_row_{r_idx}_col_1"
                col1_val = text
                if text.strip().lower() == row.cells[0].text.strip().lower():
                    col1_val = ""
                if readonly:
                    display = _render_readonly_span(col1_val, None, col1_loc)
                    html.append(_readonly_td(display, cell_style=cell_style, rowspan_attr=rowspan_attr, colspan_attr=colspan_attr, location=col1_loc))
                else:
                    inp = _render_field_input(
                        col1_loc,
                        {"location": col1_loc, "label": row.cells[0].text.strip(), "type": "table_2col"},
                        css_class="docx-input",
                        placeholder="Enter details...",
                        value=col1_val,
                    )
                    html.append(
                        f'<td class="docx-cell editable" style="{cell_style}"{rowspan_attr}{colspan_attr}>'
                        f"{inp}</td>"
                    )
                continue
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
                            colspan_attr=colspan_attr,
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
                            f'<td class="docx-cell editable" style="{cell_style}"{rowspan_attr}{colspan_attr}>'
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
                            f'<td class="docx-cell editable" style="{cell_style}"{rowspan_attr}{colspan_attr}>'
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
                            colspan_attr=colspan_attr,
                            location=field["location"],
                        )
                    )
                else:
                    use_textarea = _is_textarea_cell(field, row_label, rowspan)
                    ph = ""
                    if is_two_col and col_idx == 1:
                        ph = "Enter details..."
                    elif is_two_col and col_idx == 0:
                        ph = "Parameter / Item..."
                    if use_textarea:
                        widget_field = dict(field)
                        widget_field["widget"] = "textarea"
                        inp = _render_field_input(
                            field["location"],
                            widget_field,
                            css_class="docx-input",
                            placeholder=ph,
                            value=value or text,
                        )
                    else:
                        inp = _render_field_input(
                            field["location"],
                            field,
                            css_class="docx-input",
                            placeholder=ph,
                            value=value or text,
                        )
                    html.append(
                        f'<td class="docx-cell editable" style="{cell_style}"{rowspan_attr}{colspan_attr}>'
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
                            colspan_attr=colspan_attr,
                            location=field.get("location", ""),
                            extra_class="locked",
                        )
                    )
                else:
                    html.append(
                        f'<td class="docx-cell locked" style="{style}{locked_bg}"{rowspan_attr}{colspan_attr}>'
                        f"{_escape_html(locked_val)}</td>"
                    )
            elif not readonly and r_idx > 0 and not is_header:
                if mode != "pre_approval" and text.strip() != "":
                    style = _inline_cell_style(cell_style, readonly=readonly)
                    html.append(
                        f'<td class="docx-cell" style="{style}"{rowspan_attr}{colspan_attr}>'
                        f"{_escape_html(text)}</td>"
                    )
                else:
                    cell_loc = f"table_{t_idx}_row_{r_idx}_col_{col_idx}"
                    inp = _render_field_input(
                        cell_loc,
                        {"location": cell_loc, "label": header_label, "type": "table_multicol"},
                        css_class="docx-input",
                        placeholder="Enter details...",
                        value=text,
                    )
                    html.append(
                        f'<td class="docx-cell editable" style="{cell_style}"{rowspan_attr}{colspan_attr}>'
                        f"{inp}</td>"
                    )
            else:
                style = _inline_cell_style(cell_style, readonly=readonly)
                html.append(
                    f'<td class="docx-cell" style="{style}"{rowspan_attr}{colspan_attr}>'
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
    if normalize_attachment_list_paragraphs_to_table(doc):
        try:
            doc.save(template_path)
        except Exception:
            pass
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

    # Walk doc.element.body in document order — render tables and paragraphs as they appear.
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
                if heading and has_paragraph_blank(heading):
                    field = field_map.get(f"para_{para_index}")
                    rendered = _render_paragraph(
                        para_index, heading, field, field_map, mode
                    )
                    if rendered:
                        html_parts.append(rendered)
                elif heading:
                    from doc_parser import _para_text_from_element as _parser_para_text

                    W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
                    inner_paras = txbx_element.findall(f"{{{W_NS}}}p")
                    inner_heading = (
                        _parser_para_text(inner_paras[0]).strip() if inner_paras else ""
                    )
                    if dedupe_colon_label(heading) != dedupe_colon_label(inner_heading):
                        html_parts.append(
                            f'<p class="docx-para">{_escape_html(dedupe_colon_label(heading))}</p>'
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

        elif tag == "sdt":
            # Word Structured Document Tag (Table of Contents)
            W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            sdt_paras = child.findall(f".//{{{W_NS}}}p")
            if sdt_paras:
                toc_entries = []
                for sp in sdt_paras:
                    title, page = _parse_toc_entry_element(sp)
                    if title:
                        toc_entries.append((title, page))
                if toc_entries:
                    html_parts.append(
                        '<div class="docx-toc-card" style="margin: 16px 0; padding: 16px 20px; '
                        'background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-family: inherit;">'
                    )
                    if _is_readonly_mode(mode):
                        html_parts.append(
                            '<div style="font-weight: 700; font-size: 14px; color: #334155; '
                            'margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px;">'
                            '📋 Table of Contents</div>'
                        )
                    else:
                        html_parts.append(
                            '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">'
                            '<span style="font-weight: 700; font-size: 14px; color: #334155; text-transform: uppercase; letter-spacing: 0.5px;">📋 Table of Contents</span>'
                            '<button type="button" class="btn btn-sm btn-outline-primary btn-sync-toc" id="btnAutoSyncToc" style="font-size: 11px; padding: 2px 10px; border-radius: 12px; cursor: pointer; background: #fff; border: 1px solid #007bff; color: #007bff;" title="Auto-calculate page numbers based on document content length">'
                            '<i class="fas fa-sync-alt mr-1"></i> Auto-Calculate Page Numbers'
                            '</button>'
                            '</div>'
                        )
                    for toc_idx, (title, page) in enumerate(toc_entries):
                        loc = f"toc_sdt_{toc_idx}"
                        val = page
                        if field_map and loc in field_map:
                            stored = field_map[loc].get("value")
                            if stored is not None and str(stored).strip() != "":
                                val = str(stored).strip()
                        m_sec = re.match(r"^(\d+(?:\.\d+)*)\s*(.*)$", title)
                        sec_num = m_sec.group(1) if m_sec else ""
                        clean_title = m_sec.group(2) if m_sec else title
                        if page:
                            if _is_readonly_mode(mode):
                                page_html = f'<span class="toc-page" style="flex-shrink: 0; font-weight: 600; color: #1e293b;">{_escape_html(val)}</span>'
                            else:
                                page_html = (
                                    f'<span class="toc-page-wrap" style="flex-shrink: 0; display: inline-flex; align-items: center;">'
                                    f'<input type="number" min="1" max="999" name="fields[{loc}]" '
                                    f'class="docx-input docx-toc-page-input" '
                                    f'data-toc-sec="{_escape_html(sec_num)}" '
                                    f'data-toc-title="{_escape_html(clean_title)}" '
                                    f'value="{_escape_html(val)}" '
                                    f'style="width: 52px; height: 26px; text-align: center; font-weight: 600; font-size: 13px; padding: 1px 4px; border: 1px solid #cbd5e1; border-radius: 4px; background: #fffde7; margin-left: 6px;">'
                                    f'</span>'
                                )
                            html_parts.append(
                                f'<div class="docx-toc-line" style="display: flex; align-items: baseline; justify-content: space-between; '
                                f'padding: 4px 0; font-size: 13px; color: #334155; line-height: 1.6;" '
                                f'data-toc-sec="{_escape_html(sec_num)}" data-toc-title="{_escape_html(clean_title)}">'
                                f'<span class="toc-title" style="flex-shrink: 0; font-weight: 500;">{_escape_html(title)}</span>'
                                f'<span class="toc-dots" style="flex-grow: 1; border-bottom: 2px dotted #94a3b8; margin: 0 10px; min-width: 24px; height: 1em;"></span>'
                                f'{page_html}'
                                f'</div>'
                            )
                        else:
                            html_parts.append(
                                f'<div class="docx-toc-line" style="padding: 4px 0; font-size: 13px; color: #334155; line-height: 1.6;">'
                                f'<span class="toc-title" style="font-weight: 500;">{_escape_html(title)}</span>'
                                f'</div>'
                            )
                    html_parts.append('</div>')

    html_parts.append("</div>")
    return "\n".join(html_parts)


# ── docx_image_map() ──────────────────────────────
# POST /docx-image-map — resolve embedded DOCX images with correct
# relationship chains and Word crop rectangles for NEQ preview only.
@app.post("/neq/save-as")
async def neq_save_as(
    file: UploadFile = File(...),
    edits: str = Form("[]"),
    table_ops: str = Form("[]"),
    cell_edits: str = Form("[]"),
    checkbox_ops: str = Form("[]"),
    structure_ops: str = Form("[]"),
    toc_lines: str = Form("[]"),
):
    """Apply indexed text edits from NEQ preview onto the original DOCX."""
    # Delegate to neq_docx_save to patch DOCX bytes with preview edit list.
    from neq_docx_save import apply_indexed_edits_to_docx

    try:
        content = await file.read()
        if not content:
            raise HTTPException(status_code=400, detail="Empty DOCX upload.")

        try:
            edits_list = json.loads(edits or "[]")
            table_ops_list = json.loads(table_ops or "[]")
            cell_edits_list = json.loads(cell_edits or "[]")
            checkbox_ops_list = json.loads(checkbox_ops or "[]")
            structure_ops_list = json.loads(structure_ops or "[]")
            toc_lines_list = json.loads(toc_lines or "[]")
        except json.JSONDecodeError as exc:
            raise HTTPException(status_code=400, detail="Invalid edits JSON.") from exc

        if not isinstance(edits_list, list):
            raise HTTPException(status_code=400, detail="Edits payload must be a list.")
        if not isinstance(table_ops_list, list):
            raise HTTPException(status_code=400, detail="table_ops payload must be a list.")
        if not isinstance(cell_edits_list, list):
            raise HTTPException(status_code=400, detail="cell_edits payload must be a list.")
        if not isinstance(checkbox_ops_list, list):
            raise HTTPException(status_code=400, detail="checkbox_ops payload must be a list.")
        if not isinstance(structure_ops_list, list):
            raise HTTPException(status_code=400, detail="structure_ops payload must be a list.")
        if not isinstance(toc_lines_list, list):
            raise HTTPException(status_code=400, detail="toc_lines payload must be a list.")

        output_bytes, stats = apply_indexed_edits_to_docx(
            content,
            edits_list,
            table_ops_list,
            cell_edits_list,
            checkbox_ops_list,
            structure_ops_list,
            toc_lines_list,
        )

        # Return modified DOCX as base64 for Laravel to save as a new file.
        return {
            "status": "success",
            "stats": stats,
            "output_base64": base64.b64encode(output_bytes).decode("ascii"),
        }
    except HTTPException:
        raise
    except Exception as exc:
        logger.exception("neq/save-as failed")
        raise HTTPException(status_code=500, detail=str(exc)) from exc


@app.post("/neq/text-targets")
async def neq_text_targets(file: UploadFile = File(...)):
    """Return body text targets with stable indexes for NEQ preview edit mapping."""
    from neq_docx_save import serialize_text_targets

    try:
        content = await file.read()
        if not content:
            raise HTTPException(status_code=400, detail="Empty DOCX upload.")

        targets = serialize_text_targets(content)
        return {
            "status": "success",
            "targets": targets,
            "count": len(targets),
        }
    except HTTPException:
        raise
    except Exception as exc:
        logger.exception("neq/text-targets failed")
        raise HTTPException(status_code=500, detail=str(exc)) from exc


@app.post("/docx-image-map")
async def docx_image_map(file: UploadFile = File(...)):
    # Resolve embedded images with crop rects for NEQ header logo preview.
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
