from fastapi import FastAPI, UploadFile, File, HTTPException, Form
from dotenv import load_dotenv
from doc_parser import (
    parse_docx,
    PARA_BLANK_RE,
    PARA_COLON_BLANK_RE,
    dedupe_colon_label,
    dedupe_repeated_text,
    has_paragraph_blank,
    find_paragraph_blanks,
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
    is_signature_table,
    get_table_column_headers,
    resolve_sl_no_column_index,
    infer_field_widget,
    is_report_number_label,
    is_date_field_label,
    is_signature_date_column,
    _is_junk_signature_placeholder,
    element_has_drawing_checkbox,
    is_two_col_header_row,
    is_two_col_grid_table,
    is_toc_table,
    is_toc_list_table,
    is_toc_entry_text,
    is_equipment_section_banner_row,
    is_verified_by_sign_row,
    is_safety_by_sign_row,
    is_repeated_column_header_row,
    _match_cover_label_cell,
    _cell_plain_text,
)
from docx.oxml.ns import qn
from report_generator import fill_docx, _normalize_text_value, normalize_existing_filled_docx
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
    import_source: Optional[str] = None

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

# [LIBREOFFICE] Path to soffice.exe (set LIBREOFFICE_BIN in .env if needed)
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
# Converts old .doc → .docx so the app can parse it.
# [LIBREOFFICE] Tried first: soffice --headless --convert-to docx
# [WORD COM]    Backup on Windows if LibreOffice is missing
# Used by upload/import APIs when the template is still a legacy .doc
def _converted_docx_path(doc_path: str) -> str:
    return os.path.splitext(doc_path)[0] + ".docx"


def _docx_ready(docx_path: str) -> bool:
    return os.path.isfile(docx_path) and os.path.getsize(docx_path) > 0


def _convert_doc_with_libreoffice(doc_path: str, docx_path: str) -> None:
    # [LIBREOFFICE] Command: soffice --headless --convert-to docx --outdir <folder> <file.doc>
    command = [
        LIBREOFFICE,
        "--headless",
        "--convert-to",
        "docx",
        "--outdir",
        os.path.dirname(doc_path),
        doc_path,
    ]
    result = subprocess.run(command, capture_output=True, text=True, timeout=180)
    if not _docx_ready(docx_path):
        detail = (result.stderr or result.stdout or "LibreOffice produced no .docx").strip()
        raise Exception(detail)


def _convert_doc_with_word(doc_path: str, docx_path: str) -> None:
    """[WORD COM] Windows backup when LibreOffice is not installed. Saves a real .docx."""
    if os.name != "nt":
        raise Exception("Microsoft Word conversion is only available on Windows")

    in_path = os.path.abspath(doc_path).replace("'", "''")
    out_path = os.path.abspath(docx_path).replace("'", "''")
    script = f"""
$ErrorActionPreference = 'Stop'
$word = $null
$doc = $null
try {{
  $word = New-Object -ComObject Word.Application
  $word.Visible = $false
  $word.DisplayAlerts = 0
  $doc = $word.Documents.Open('{in_path}', $false, $false, $false)
  $doc.SaveAs('{out_path}', 16)
}} finally {{
  if ($doc -ne $null) {{ $doc.Close($false) | Out-Null }}
  if ($word -ne $null) {{ $word.Quit() | Out-Null }}
  [GC]::Collect()
}}
"""
    result = subprocess.run(
        ["powershell", "-NoProfile", "-NonInteractive", "-Command", script],
        capture_output=True,
        text=True,
        timeout=180,
    )
    if result.returncode != 0 or not _docx_ready(docx_path):
        detail = (result.stderr or result.stdout or "Microsoft Word produced no .docx").strip()
        raise Exception(detail)


def convert_doc_to_docx(doc_path: str) -> str:
    docx_path = _converted_docx_path(doc_path)
    if _docx_ready(docx_path):
        return docx_path

    errors = []
    for converter in (_convert_doc_with_libreoffice, _convert_doc_with_word):
        try:
            converter(doc_path, docx_path)
        except FileNotFoundError:
            errors.append(f"{converter.__name__}: program not found")
        except subprocess.TimeoutExpired:
            errors.append(f"{converter.__name__}: timed out")
        except Exception as exc:
            errors.append(f"{converter.__name__}: {exc}")
        if _docx_ready(docx_path):
            logger.info("Converted legacy .doc to .docx: %s", docx_path)
            return docx_path

    raise Exception(
        "Could not convert .doc to .docx. Install LibreOffice or Microsoft Word. "
        + " | ".join(errors)
    )


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
# [API] GET /health — also reports if [LIBREOFFICE] soffice is installed.
@app.get("/health")
def health():
    return {
        "status": "ok",
        "preview_renderer": "v5-fill-fixes",
        "upload_path": UPLOAD_PATH,
        "generated_reports_path": GENERATED_REPORTS_PATH,
        "attachments_path": ATTACHMENTS_PATH,
        # true when LibreOffice (soffice) can be found
        "libreoffice": bool(shutil.which(LIBREOFFICE) or os.path.exists(LIBREOFFICE)),
    }


# ── parse_document() ────────────────────────────────
# [API] POST /parse — upload DOC/DOCX and detect fillable fields.
# If file is old .doc: [LIBREOFFICE] converts it to .docx first (Word COM backup).
@app.post("/parse")
async def parse_document(file: UploadFile = File(...)):
    # Reject uploads that are not .doc or .docx.
    original_name = file.filename or ""
    file_ext = os.path.splitext(original_name)[1].lower()
    if file_ext not in (".docx", ".doc"):
        raise HTTPException(
            status_code=400,
            detail="allwed only .doc ya .docx file"
        )

    file_id = str(uuid.uuid4())
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

    if save_path.lower().endswith(".doc"):
        # Convert legacy .doc to .docx (LibreOffice, then Microsoft Word) before parsing.
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
# [API] POST /fill — write field values into the DOCX template.
# Called by Laravel (DocxImportController) on submit / approval / download regen.
#
# Inside fill_docx() TOC pages are fixed like this:
#   [WORD COM]     Microsoft Word reads real page numbers (preferred)
#   [LIBREOFFICE]  soffice DOCX→PDF only if Word is missing/fails
#   then returns toc_field_values to Laravel
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
        # [API] POST /fill → fill_docx()
        # Inside fill_docx, TOC pages are fixed with:
        #   [WORD COM] first, then [LIBREOFFICE] DOCX→PDF backup if Word fails.
        result_path, repaired_values = fill_docx(
            template_path,
            field_values,
            output_path,
            request.attachment_links or {},
            request.equipment_id or "",
            request.table_ops or [],
            request.import_source or "",
        )
    except Exception as e:
        raise HTTPException(
            status_code=500,
            detail=f"Fill error: {str(e)}"
        )

    filled_count = sum(1 for f in request.fields if not f.locked)
    skipped_count = sum(1 for f in request.fields if f.locked)

    # TOC digits come back from fill_docx (Word/LibreOffice layout step above).
    toc_field_values = {
        loc: val
        for loc, val in (repaired_values or {}).items()
        if str(loc).startswith("toc_sdt_") or "_toc_" in str(loc)
    }

    return {
        "status":            "success",
        "file_id":           request.file_id,
        "output_filename":   output_filename,
        "output_path":       result_path,
        "repaired_field_values": repaired_values,
        "toc_field_values": toc_field_values,
        "summary": {
            "total_fields":   len(request.fields),
            "filled_fields":  filled_count,
            "skipped_locked": skipped_count,
            "toc_updated":    len(toc_field_values),
        }
    }


class NormalizeLayoutRequest(BaseModel):
    """Absolute or storage-relative path to an already-filled DOCX."""
    path: str
    import_source: Optional[str] = None


@app.post("/normalize-layout")
async def normalize_layout(request: NormalizeLayoutRequest):
    """
    Apply post-fill layout fixes onto an existing DOCX.
    NEQ-only layout_v2 rewrite; requal/reval are no-ops (legacy path preserved).
    """
    raw = (request.path or "").strip()
    if not raw:
        raise HTTPException(status_code=400, detail="path is required")

    candidates = [raw]
    # Laravel often sends storage-relative paths like generated_reports/foo_filled.docx
    if not os.path.isabs(raw):
        candidates.append(str(Path(GENERATED_REPORTS_PATH) / raw))
        candidates.append(str(Path(GENERATED_REPORTS_PATH) / Path(raw).name))

    docx_path = next((p for p in candidates if p and os.path.isfile(p)), None)
    if not docx_path:
        raise HTTPException(status_code=404, detail=f"DOCX not found: {raw}")

    try:
        normalize_existing_filled_docx(docx_path, import_source=request.import_source or "")
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Normalize layout failed: {e}") from e

    return {"status": "success", "path": docx_path}


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
    import_source: Optional[str] = Form(None),
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
        # Requal/reval treat every *_col_0 as a label. NEQ edit mode must keep
        # Comments / Severity S / Revision No. fillable when PHP unlocks them.
        is_requal_col0_lock = mode in ("pre_approval", "requalification")

        def _is_sr_no_field(field: dict) -> bool:
            """True for Sl./Sr. No. / Revision No. data cells — editable in pre-approval."""
            ftype = str(field.get("type") or "").lower()
            flabel = str(field.get("label") or "").lower().strip()
            floc = str(field.get("location") or "")
            if ftype == "table_sl_no":
                return True
            if re.match(r"^(sr\.?\s*no\.?|s\.?\s*no\.?|sl\.?\s*no\.?|#)\b", flabel):
                return True
            # 15.0 REVISION HISTORY — Revision No. is a number (1/2/3), never a PQ label.
            if re.match(r"^(revision\s*no\.?|rev\.?\s*no\.?)\b", flabel):
                return True
            if re.search(r"_row_[1-9]\d*_col_0$", floc) and field.get("auto_serial") not in (
                None,
                "",
            ):
                return True
            return False

        def _is_revision_history_field(field: dict) -> bool:
            flabel = str(field.get("label") or "").lower().strip()
            return bool(
                re.search(r"\brevision\s*no\.?\b", flabel)
                or re.search(r"\breason\s+for\s+revision\b", flabel)
                or re.search(r"\beffective\s+date\b", flabel)
            )

        def _is_qualification_history_template_lock(field: dict, stored: dict | None = None) -> bool:
            """
            Word Qualification History:
            - Lock Initial Qualification (PQ) / Periodic labels (col_0)
            - Lock true sub-header cells whose value is Report No. / Approval Date
            - Initial 2nd sub-row + Periodic detail blanks stay fillable
            Never apply to 15.0 REVISION HISTORY (Reason may say Initial Qualification).
            """
            if _is_revision_history_field(field) or _is_sr_no_field(field):
                return False
            stored = stored or {}
            val = str(stored.get("value") or field.get("value") or "").strip()
            loc = str(field.get("location") or stored.get("location") or "")
            if re.match(r"(?i)^initial\s+qualification\b", val):
                return True
            if re.match(r"(?i)^periodic\s+requalification\b", val):
                return bool(loc.endswith("_col_0"))
            # Real sub-header text cells from Word.
            if re.match(r"(?i)^report\s*no\.?$", val) or re.match(r"(?i)^approval\s*date$", val):
                return True
            return False

        # Seed Initial Qualification row markers from stored + fresh so sibling cells lock.
        # Only for Qualification History — NEVER copy into REVISION HISTORY Revision No.
        _revision_row_keys: set[str] = set()
        for _src in (stored_by_loc.values(), fresh):
            for _f in _src:
                if not isinstance(_f, dict):
                    continue
                if _is_revision_history_field(_f) or _is_sr_no_field(_f):
                    _loc = str(_f.get("location") or "")
                    _m = re.match(r"^(table_\d+_row_\d+)_col_", _loc)
                    if _m:
                        _revision_row_keys.add(_m.group(1))
        for _src in (stored_by_loc.values(), fresh):
            for _f in _src:
                if not isinstance(_f, dict):
                    continue
                _loc = str(_f.get("location") or "")
                _val = str(_f.get("value") or "").strip()
                if re.match(r"(?i)^initial\s+qualification\b", _val):
                    _m = re.match(r"^(table_\d+_row_\d+)_col_", _loc)
                    if not _m:
                        continue
                    _row_key = _m.group(1)
                    if _row_key in _revision_row_keys:
                        continue
                    if f"{_row_key}_col_0" not in stored_by_loc:
                        stored_by_loc[f"{_row_key}_col_0"] = {
                            "value": _val,
                            "location": f"{_row_key}_col_0",
                        }

        fields = []
        for ff in fresh:
            loc = ff.get("location")
            lbl_lower = (ff.get("label") or "").lower()
            val_lower = (str(ff.get("value") or "")).lower()
            is_heading_or_fixed = (
                any(k in lbl_lower for k in ("methodology", "report approval"))
                or any(k in val_lower for k in ("methodology", "report approval"))
            )
            # Sr./Sl. No. col_0 must NOT be treated as a locked label.
            is_col_0_label = ff.get("type") == "table_2col_label" or (
                is_requal_col0_lock
                and bool(loc and loc.endswith("_col_0"))
                and not _is_sr_no_field(ff)
            )

            if loc in stored_by_loc:
                sv = stored_by_loc[loc]
                parse_prefilled = bool(ff.get("prefilled"))
                parse_type = ff.get("type")
                parse_role = ff.get("approver_role")
                parse_widget = ff.get("widget")
                parse_sig_date_loc = ff.get("signature_date_location")
                if sv.get("value") not in (None, ""):
                    stored_val = sv.get("value")
                    # Never re-inject template junk (lone "S") into Signature & Date.
                    if _is_signature_date_field(ff) and _is_junk_signature_placeholder(
                        str(stored_val or "")
                    ):
                        ff["value"] = ""
                        ff["stamp_eligible"] = True
                        ff["prefilled"] = False
                    elif (
                        _is_sr_no_field(ff)
                        and re.match(r"(?i)^initial\s+qualification\b", str(stored_val or "").strip())
                    ):
                        # Bad draft: Qualification History PQ text landed in Revision No.
                        # Keep Word number from fresh parse (e.g. "1").
                        pass
                    else:
                        ff["value"] = stored_val
                if is_heading_or_fixed or is_col_0_label:
                    ff["locked"] = True
                # In pre-approval mode, unlock content fields (except row_0 headers and col_0 labels)
                elif is_pre_approval_mode:
                    if _is_qualification_history_template_lock(ff, sv):
                        # Word layout: PQ label locked; its Report No./Approval Date
                        # DATA cells stay blank (never date pickers / never label text).
                        ff["locked"] = True
                        ff["widget"] = "text"
                        ff["prefilled"] = True
                        ff["draft_editable"] = False
                        ff["user_filled"] = False
                        loc_l = str(loc or "")
                        val_now = str(ff.get("value") or "").strip()
                        # Sub-header cells keep their Report No. / Approval Date text.
                        if re.match(r"(?i)^report\s*no\.?$", val_now) or re.match(
                            r"(?i)^approval\s*date$", val_now
                        ):
                            pass
                        elif re.match(r"(?i)^initial\s+qualification\b", val_now):
                            pass
                        elif re.search(r"_col_[12]$", loc_l):
                            # Initial data-row blanks under the sub-headers.
                            ff["value"] = ""
                    elif loc and loc.endswith("_row_0_col_1") and ff.get("type") == "table_2col":
                        ff["locked"] = False
                    elif _is_sr_no_field(ff):
                        # Pre-approval: Sr. No. stays editable with default serial.
                        ff["type"] = "table_multicol"
                        ff["widget"] = "text"
                        ff["locked"] = False
                        ff["draft_editable"] = True
                        ff["user_filled"] = True
                        ff["prefilled"] = False
                        if not str(ff.get("value") or "").strip() and ff.get("auto_serial") not in (
                            None,
                            "",
                        ):
                            try:
                                ff["value"] = f"{int(ff['auto_serial'])}."
                            except (TypeError, ValueError):
                                pass
                    elif (
                        sv.get("locked") in (True, 1, "1", "true", "yes")
                        and sv.get("draft_editable") is False
                        and sv.get("user_filled") is not True
                        and (
                            is_heading_or_fixed
                            or is_col_0_label
                            or (loc and re.search(r"_row_0(_col_\d+)?$", str(loc)))
                        )
                    ):
                        # Only keep true template locks (headings / col_0 labels / row 0).
                        ff["locked"] = True
                        if sv.get("widget"):
                            ff["widget"] = sv.get("widget")
                    elif ff.get("type") not in ("approval_stamp",) and not (
                        loc and loc.endswith("_row_0")
                    ):
                        # Pre-approval fill: unlock content cells (Checks / Acceptance / etc.).
                        ff["locked"] = False
                        ff["draft_editable"] = True
                        ff["user_filled"] = True
                        ff["prefilled"] = False
                        # Qualification History Periodic row: blank text cells, not date pickers.
                        m_per = re.match(r"^(table_\d+_row_\d+)_col_([12])$", str(loc or ""))
                        if m_per:
                            col0 = stored_by_loc.get(f"{m_per.group(1)}_col_0") or {}
                            if not re.match(
                                r"(?i)^periodic\s+requalification\b",
                                str(col0.get("value") or "").strip(),
                            ):
                                # Also check fresh/sibling value already on this row.
                                col0 = next(
                                    (
                                        x
                                        for x in fresh
                                        if isinstance(x, dict)
                                        and str(x.get("location") or "") == f"{m_per.group(1)}_col_0"
                                    ),
                                    col0,
                                )
                            if re.match(
                                r"(?i)^periodic\s+requalification\b",
                                str(col0.get("value") or "").strip(),
                            ):
                                _pcol = int(m_per.group(2))
                                ff["widget"] = "date" if _pcol == 2 else "text"
                                ff["label"] = "Approval Date" if _pcol == 2 else "Report No."
                                if re.match(
                                    r"(?i)^(report\s*no\.?|approval\s*date)$",
                                    str(ff.get("value") or "").strip(),
                                ):
                                    ff["value"] = ""
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
                    # Paragraphs / 2-col labels stay locked; col_0 only for requal modes.
                    elif ff.get("type") in ("paragraph_content", "table_2col_label") or (
                        is_requal_col0_lock and loc and loc.endswith("_col_0")
                    ):
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
                    "department_location",
                    "signature_location",
                    "stamp_eligible",
                    "is_pre_approver",
                    "prefilled",
                    "user_filled",
                    "auto_from_name",
                ):
                    if meta_key not in sv:
                        continue
                    val = sv[meta_key]
                    if meta_key in (
                        "stamp_eligible",
                        "is_pre_approver",
                        "prefilled",
                        "user_filled",
                        "auto_from_name",
                    ) or val not in (None, ""):
                        ff[meta_key] = val
                # Word-prefilled template text stays locked unless the user typed over it.
                # Exception: NEQ letterhead / protocol cover cells stay fillable so
                # FACILITY, EQUIPMENT, REF, DOCUMENT No., VERSION can be edited.
                is_letterhead_cover = (
                    str(ff.get("type") or "") in ("protocol_cover", "header_cell")
                    or str(sv.get("type") or "") in ("protocol_cover", "header_cell")
                    or bool(ff.get("cover_field") or sv.get("cover_field"))
                    or str(loc or "").startswith("header_")
                )
                if is_letterhead_cover:
                    # Prefer PHP unlock (locked=false / draft_editable) for letterhead.
                    if "locked" in sv:
                        sv_locked = sv["locked"]
                        if isinstance(sv_locked, str):
                            ff["locked"] = sv_locked.strip().lower() in ("1", "true", "yes")
                        else:
                            ff["locked"] = bool(sv_locked)
                    else:
                        ff["locked"] = False
                    if sv.get("draft_editable") is True or ff.get("draft_editable") is True:
                        ff["locked"] = False
                        ff["draft_editable"] = True
                elif (
                    (parse_prefilled or ff.get("prefilled") or sv.get("prefilled"))
                    and not sv.get("user_filled")
                    and not ff.get("user_filled")
                    # Send-back / draft unlock: PHP locked=false + draft_editable must win.
                    and sv.get("locked") is not False
                    and sv.get("draft_editable") is not True
                ):
                    ff["locked"] = True
                    ff["prefilled"] = True
                # Fresh parse wins for 8.0 Report Approval Name dropdowns — older drafts may
                # have demoted them to plain text inputs via scrubFalseApprovalStampFields.
                if parse_type == "approver" or (
                    isinstance(parse_role, str)
                    and parse_role.startswith("post_")
                    and parse_type != "approval_stamp_signature"
                    and parse_type != "approval_stamp_date"
                    and not (isinstance(parse_type, str) and parse_type.startswith("approval_stamp"))
                ):
                    # Only Name cells become dropdowns — never Signature/Date stamp cells.
                    loc_s = str(loc or "")
                    if not re.search(r"_col_[23]$", loc_s):
                        ff["type"] = "approver"
                        ff["widget"] = parse_widget or "approver"
                        stored_role = str(sv.get("approver_role") or "")
                        # Prefer Laravel POST roles. Legacy parse used pre-approval
                        # approved_by_user_name for Approved By → User/Validation.
                        if stored_role.startswith("post_"):
                            ff["approver_role"] = stored_role
                        elif parse_role == "approved_by_user_name" and mode == "edit":
                            ff["approver_role"] = "post_approved_by_user_name"
                        elif parse_role:
                            ff["approver_role"] = parse_role
                        elif stored_role:
                            ff["approver_role"] = stored_role
                        if parse_sig_date_loc and not sv.get("signature_date_location"):
                            ff["signature_date_location"] = parse_sig_date_loc
                        # POST Name dropdowns must stay selectable in edit/fill mode.
                        role_now = str(ff.get("approver_role") or "")
                        if role_now.startswith("post_") or (
                            mode == "edit" and role_now == "post_approved_by_user_name"
                        ):
                            ff["locked"] = False
                            ff["is_pre_approver"] = False
                        elif not ff.get("user_filled") and not sv.get("is_pre_approver"):
                            ff["locked"] = False
                # Signature / Date always stay locked stamps from the fresh parse.
                if isinstance(parse_type, str) and parse_type.startswith("approval_stamp"):
                    ff["type"] = parse_type
                    ff["widget"] = parse_widget or (
                        "date" if parse_type == "approval_stamp_date" else "text"
                    )
                    ff["locked"] = True
                    if parse_role:
                        ff["approver_role"] = parse_role
                # Sr. No. / auto_serial: editable on fill; locked only in readonly.
                if (
                    parse_type == "table_sl_no"
                    or ff.get("auto_serial") not in (None, "")
                    or _is_sr_no_field(ff)
                ):
                    if not str(ff.get("value") or "").strip():
                        auto_serial = ff.get("auto_serial")
                        if auto_serial not in (None, ""):
                            try:
                                ff["value"] = f"{int(auto_serial)}."
                            except (TypeError, ValueError):
                                pass
                    if str(ff.get("label") or "").strip().lower() in {
                        "main body",
                        "mechanical components",
                        "electrical components",
                        "pneumatic components",
                    }:
                        ff["label"] = "Sr. No."
                    if mode == "readonly":
                        ff["type"] = "table_sl_no"
                        ff["locked"] = True
                    else:
                        ff["type"] = "table_multicol"
                        ff["widget"] = "text"
                        ff["locked"] = False
                        ff["draft_editable"] = True
                        ff["user_filled"] = True
                        ff["prefilled"] = False
                # Empty Sr. No. cells stay fillable — do not keep legacy locked table_sl_no.
                elif (
                    parse_type == "table_multicol"
                    and str(sv.get("type") or "") == "table_sl_no"
                    and not str(ff.get("value") or "").strip()
                    and not str(sv.get("value") or "").strip()
                    and re.search(r"_col_0$", str(loc or ""))
                ):
                    ff["type"] = "table_multicol"
                    ff["widget"] = "text"
                    ff["locked"] = False
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
                        elif ff.get("type") in ("paragraph_content", "table_2col_label") or (
                            is_requal_col0_lock and loc and loc.endswith("_col_0")
                        ):
                            ff["locked"] = True
                elif is_pre_approval_mode:
                    if _is_qualification_history_template_lock(ff, None):
                        ff["locked"] = True
                        ff["widget"] = "text"
                        ff["prefilled"] = True
                        ff["draft_editable"] = False
                        loc_l = str(loc or "")
                        val_now = str(ff.get("value") or "").strip()
                        if re.search(r"_col_[12]$", loc_l) and not (
                            re.match(r"(?i)^report\s*no\.?$", val_now)
                            or re.match(r"(?i)^approval\s*date$", val_now)
                        ):
                            ff["value"] = ""
                    elif loc and loc.endswith("_row_0_col_1") and ff.get("type") == "table_2col":
                        ff["locked"] = False
                    elif _is_sr_no_field(ff):
                        ff["type"] = "table_multicol"
                        ff["widget"] = "text"
                        ff["locked"] = False
                        ff["draft_editable"] = True
                        if not str(ff.get("value") or "").strip() and ff.get("auto_serial") not in (
                            None,
                            "",
                        ):
                            try:
                                ff["value"] = f"{int(ff['auto_serial'])}."
                            except (TypeError, ValueError):
                                pass
                    elif ff.get("type") not in ("approval_stamp",) and not (
                        loc and loc.endswith("_row_0")
                    ):
                        # Pre-approval: keep Checks / Acceptance / DESCRIPTION editable.
                        ff["locked"] = False
                        ff["draft_editable"] = True
                        ff["user_filled"] = True
                        ff["prefilled"] = False
                else:
                    if _is_sr_no_field(ff) and mode != "readonly":
                        if not str(ff.get("value") or "").strip() and ff.get("auto_serial") not in (None, ""):
                            try:
                                ff["value"] = f"{int(ff['auto_serial'])}."
                            except (TypeError, ValueError):
                                pass
                        ff["type"] = "table_multicol"
                        ff["widget"] = "text"
                        ff["locked"] = False
                        ff["draft_editable"] = True
                    elif ff.get("type") in ("paragraph_content", "table_2col_label") or (
                        is_requal_col0_lock and loc and loc.endswith("_col_0") and not _is_sr_no_field(ff)
                    ):
                        ff["locked"] = True
                    elif ff.get("type") == "table_2col" or (loc and (loc.endswith("_col_1") or re.match(r"^table_\d+_row_\d+$", loc))):
                        # Fill modes: DESCRIPTION / details stay editable until post-approval.
                        ff["locked"] = False
            fields.append(ff)

        # Draft-only synthetic locations (not in Word parse) must still reach
        # field_map — e.g. 8.4 Verification P&ID notes (para_N_body).
        _have_locs = {
            str(f.get("location") or "")
            for f in fields
            if isinstance(f, dict)
        }
        for _sloc, _sv in list(stored_by_loc.items()):
            if not isinstance(_sv, dict) or not _sloc or _sloc in _have_locs:
                continue
            _sval = str(_sv.get("value") or "").strip()
            _keep = (
                _sloc.endswith("_body")
                or "verification_pid" in _sloc
                or bool(_sv.get("user_filled"))
                or bool(_sv.get("draft_editable"))
                or _sval != ""
            )
            if not _keep:
                continue
            # Skip pure template table cells that fresh parse intentionally dropped.
            if re.match(r"^table_\d+_row_\d+_col_\d+$", _sloc) and not (
                _sv.get("user_filled") or _sv.get("draft_editable") or _sval
            ):
                continue
            _seed = dict(_sv)
            _seed.setdefault("location", _sloc)
            if _sloc.endswith("_body") or "verification_pid" in _sloc:
                _seed.setdefault("type", "paragraph_content")
                _seed.setdefault("widget", "textarea")
                _seed["locked"] = False
                _seed["draft_editable"] = True
                if _sval:
                    _seed["user_filled"] = True
            fields.append(_seed)
            _have_locs.add(_sloc)

        # Final pass: Qualification History must match Word even if earlier
        # auto_serial / prefilled / meta-merge re-unlocked cells.
        if is_pre_approval_mode:
            _qual_initial_rows: set[str] = set()
            _qual_periodic_rows: set[str] = set()
            _qual_subheader_rows: set[str] = set()
            _qual_by_row: dict[str, dict[int, dict]] = {}
            for _f in fields:
                if not isinstance(_f, dict):
                    continue
                _loc = str(_f.get("location") or "")
                _m = re.match(r"^(table_\d+_row_\d+)_col_(\d+)$", _loc)
                if not _m:
                    continue
                _row, _col = _m.group(1), int(_m.group(2))
                _qual_by_row.setdefault(_row, {})[_col] = _f
                _val = str(_f.get("value") or "").strip()
                if re.match(r"(?i)^initial\s+qualification\b", _val):
                    _qual_initial_rows.add(_row)
                if re.match(r"(?i)^periodic\s+requalification\b", _val):
                    _qual_periodic_rows.add(_row)
            for _row, _cols in _qual_by_row.items():
                _c1 = str((_cols.get(1) or {}).get("value") or "").strip()
                _c2 = str((_cols.get(2) or {}).get("value") or "").strip()
                if re.match(r"(?i)^report\s*no\.?$", _c1) and re.match(
                    r"(?i)^approval\s*date$", _c2
                ):
                    _qual_subheader_rows.add(_row)
            for _f in fields:
                if not isinstance(_f, dict):
                    continue
                _loc = str(_f.get("location") or "")
                _m = re.match(r"^(table_\d+_row_\d+)_col_(\d+)$", _loc)
                if not _m:
                    continue
                _row, _col = _m.group(1), int(_m.group(2))
                _val = str(_f.get("value") or "").strip()
                if _row in _qual_subheader_rows:
                    _f["locked"] = True
                    _f["widget"] = "text"
                    _f["draft_editable"] = False
                    _f["user_filled"] = False
                    _f["prefilled"] = True
                    continue
                if re.match(r"(?i)^initial\s+qualification\b", _val) or re.match(
                    r"(?i)^periodic\s+requalification\b", _val
                ):
                    _f["locked"] = True
                    _f["widget"] = "text"
                    _f["draft_editable"] = False
                    _f["user_filled"] = False
                    _f["prefilled"] = True
                    continue
                # Initial 2nd sub-row + Periodic details: fillable (text / date).
                if (
                    (
                        (_row in _qual_initial_rows and _row not in _qual_subheader_rows)
                        or _row in _qual_periodic_rows
                    )
                    and _col > 0
                ):
                    if re.match(r"(?i)^(report\s*no\.?|approval\s*date)$", _val):
                        _f["value"] = ""
                    _f["locked"] = False
                    _f["widget"] = "date" if _col == 2 else "text"
                    _f["label"] = "Approval Date" if _col == 2 else "Report No."
                    _f["draft_editable"] = True
                    _f["user_filled"] = True
                    _f["prefilled"] = False

            # Ensure Qualification History locations from Laravel exist in the
            # field list even when the fresh parse omitted template-static cells.
            _have_locs = {
                str(f.get("location") or "")
                for f in fields
                if isinstance(f, dict)
            }
            for _sv in stored_by_loc.values():
                if not isinstance(_sv, dict):
                    continue
                _sloc = str(_sv.get("location") or "")
                if not re.match(r"^table_\d+_row_\d+_col_\d+$", _sloc):
                    continue
                _sval = str(_sv.get("value") or "").strip()
                _is_qual_seed = bool(
                    re.match(r"(?i)^initial\s+qualification\b", _sval)
                    or re.match(r"(?i)^periodic\s+requalification\b", _sval)
                    or re.match(r"(?i)^report\s*no\.?$", _sval)
                    or re.match(r"(?i)^approval\s*date$", _sval)
                    or (
                        _sv.get("draft_editable") is False
                        and _sv.get("locked") in (True, 1, "1", "true", "yes")
                        and re.search(r"_col_[12]$", _sloc)
                    )
                )
                if not _is_qual_seed or _sloc in _have_locs:
                    continue
                _seed = dict(_sv)
                _seed.setdefault("type", "table_multicol")
                _seed.setdefault("widget", "text")
                fields.append(_seed)
                _have_locs.add(_sloc)

            # Re-run lock rules after seeding any missing Qual History cells.
            _qual_initial_rows = set()
            _qual_periodic_rows = set()
            _qual_subheader_rows = set()
            _qual_by_row = {}
            for _f in fields:
                if not isinstance(_f, dict):
                    continue
                _loc = str(_f.get("location") or "")
                _m = re.match(r"^(table_\d+_row_\d+)_col_(\d+)$", _loc)
                if not _m:
                    continue
                _row, _col = _m.group(1), int(_m.group(2))
                _qual_by_row.setdefault(_row, {})[_col] = _f
                _val = str(_f.get("value") or "").strip()
                if re.match(r"(?i)^initial\s+qualification\b", _val):
                    _qual_initial_rows.add(_row)
                if re.match(r"(?i)^periodic\s+requalification\b", _val):
                    _qual_periodic_rows.add(_row)
            for _row, _cols in _qual_by_row.items():
                _c1 = str((_cols.get(1) or {}).get("value") or "").strip()
                _c2 = str((_cols.get(2) or {}).get("value") or "").strip()
                if re.match(r"(?i)^report\s*no\.?$", _c1) and re.match(
                    r"(?i)^approval\s*date$", _c2
                ):
                    _qual_subheader_rows.add(_row)
            for _f in fields:
                if not isinstance(_f, dict):
                    continue
                _loc = str(_f.get("location") or "")
                _m = re.match(r"^(table_\d+_row_\d+)_col_(\d+)$", _loc)
                if not _m:
                    continue
                _row, _col = _m.group(1), int(_m.group(2))
                _val = str(_f.get("value") or "").strip()
                if _row in _qual_subheader_rows:
                    _f["locked"] = True
                    _f["widget"] = "text"
                    _f["draft_editable"] = False
                    _f["user_filled"] = False
                    _f["prefilled"] = True
                    continue
                if re.match(r"(?i)^initial\s+qualification\b", _val) or re.match(
                    r"(?i)^periodic\s+requalification\b", _val
                ):
                    _f["locked"] = True
                    _f["widget"] = "text"
                    _f["draft_editable"] = False
                    _f["user_filled"] = False
                    _f["prefilled"] = True
                    continue
                # Initial 2nd sub-row + Periodic details: fillable (text / date).
                if (
                    (
                        (_row in _qual_initial_rows and _row not in _qual_subheader_rows)
                        or _row in _qual_periodic_rows
                    )
                    and _col > 0
                ):
                    if re.match(r"(?i)^(report\s*no\.?|approval\s*date)$", _val):
                        _f["value"] = ""
                    _f["locked"] = False
                    _f["widget"] = "date" if _col == 2 else "text"
                    _f["label"] = "Approval Date" if _col == 2 else "Report No."
                    _f["draft_editable"] = True
                    _f["user_filled"] = True
                    _f["prefilled"] = False

        html = build_fill_html(
            template_path,
            fields,
            mode=mode,
            import_source=import_source or "",
        )
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


def _is_signature_date_field(field: dict | None) -> bool:
    """True for Signature & Date / signature stamp columns in approval tables."""
    if not field:
        return False
    label = str(field.get("label") or "")
    ftype = str(field.get("type") or "")
    if ftype.startswith("approval_stamp"):
        return True
    if is_signature_date_column(label):
        return True
    low = label.lower()
    return "signature" in low and "date" in low


def _clean_signature_cell_value(field: dict | None, value: str, fallback_text: str = "") -> str:
    """
    Drop template leftovers like a lone 'S' from Signature & Date cells.
    Parse clears them, but render used to restore DOCX cell text via `value or text`.
    """
    raw = "" if value is None else str(value).strip()
    if not raw and fallback_text:
        raw = str(fallback_text).strip()
    if not raw:
        return ""
    if _is_signature_date_field(field) and _is_junk_signature_placeholder(raw):
        return ""
    if not field and _is_junk_signature_placeholder(raw) and len(raw) <= 2:
        # Bare cell text with no field metadata — still drop lone S/XX junk.
        return ""
    return raw


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


_BULLET_GLYPH_MAP = {
    "\uf0b7": "•",
    "\uf0d8": "➢",
    "\uf0a7": "▪",
    "\uf076": "❖",
    "\uf0fc": "✓",
    "\uf0a8": "▪",
    "o": "◦",
}


def _int_to_roman(num: int, lower: bool = False) -> str:
    """Convert a positive integer into Roman numerals."""
    if num <= 0:
        return str(num)
    pairs = (
        (1000, "M"), (900, "CM"), (500, "D"), (400, "CD"),
        (100, "C"), (90, "XC"), (50, "L"), (40, "XL"),
        (10, "X"), (9, "IX"), (5, "V"), (4, "IV"), (1, "I"),
    )
    out = []
    rem = num
    for val, sym in pairs:
        while rem >= val:
            out.append(sym)
            rem -= val
    res = "".join(out)
    return res.lower() if lower else res


def _int_to_alpha(num: int, lower: bool = False) -> str:
    """Convert 1-based counter to Word alphabetic list marker (a..z, aa..)."""
    if num <= 0:
        return str(num)
    chars = []
    n = num
    while n > 0:
        n, r = divmod(n - 1, 26)
        chars.append(chr((97 if lower else 65) + r))
    return "".join(reversed(chars))


def _format_num_counter(value: int, num_fmt: str) -> str:
    """Format a single Word numbering level value according to w:numFmt."""
    if num_fmt == "decimalZero":
        return f"{value:02d}"
    if num_fmt == "lowerLetter":
        return _int_to_alpha(value, lower=True)
    if num_fmt == "upperLetter":
        return _int_to_alpha(value, lower=False)
    if num_fmt == "lowerRoman":
        return _int_to_roman(value, lower=True)
    if num_fmt == "upperRoman":
        return _int_to_roman(value, lower=False)
    if num_fmt == "ordinal":
        return f"{value}."
    return str(value)


class _WordNumberingResolver:
    """
    Resolve Word OOXML <w:numPr> section numbers and bullets (e.g. 1.0, 2.0, 8.1, 9.1)
    by interpreting word/numbering.xml (abstractNum, numStyleLink, lvlOverride) and styles.

    Decimal/outline counters are keyed by abstractNumId so .doc→.docx conversions that
    emit a fresh numId (startOverride=1) per heading still sequence 1.0, 2.0, ….
    Frozen lvlText literals like '1.0' (no %1) are normalized before expansion.
    """

    def __init__(self, doc):
        self.abstract_nums: dict[str, dict] = {}
        self.style_link_levels: dict[str, dict[int, dict]] = {}
        self.nums: dict[str, dict] = {}
        self.style_numpr: dict[str, tuple[str | None, int | None]] = {}
        self.counters: dict[str, dict[int, int]] = {}
        self._p_cache: dict = {}
        self._load_styles(doc)
        self._load_numbering(doc)

    @staticmethod
    def _parse_lvl_elem(lvl_el) -> dict:
        fmt_el = lvl_el.find(qn("w:numFmt"))
        txt_el = lvl_el.find(qn("w:lvlText"))
        start_el = lvl_el.find(qn("w:start"))
        restart_el = lvl_el.find(qn("w:lvlRestart"))
        lgl_el = lvl_el.find(qn("w:isLgl"))
        try:
            start_val = int(start_el.get(qn("w:val"))) if start_el is not None else 1
        except (TypeError, ValueError):
            start_val = 1
        try:
            restart_val = int(restart_el.get(qn("w:val"))) if restart_el is not None else None
        except (TypeError, ValueError):
            restart_val = None
        return {
            "fmt": fmt_el.get(qn("w:val")) if fmt_el is not None else "decimal",
            "text": txt_el.get(qn("w:val")) if txt_el is not None else "",
            "start": start_val,
            "restart": restart_val,
            "is_lgl": lgl_el is not None and lgl_el.get(qn("w:val"), "1") not in ("0", "false"),
        }

    def _load_styles(self, doc) -> None:
        styles = getattr(doc, "styles", None)
        if not styles:
            return
        by_id = {}
        for st in styles:
            sid = getattr(st, "style_id", None)
            if sid:
                by_id[sid] = st
        for sid in by_id:
            cur = by_id[sid]
            visited = set()
            nid = None
            ilvl = None
            while cur is not None and getattr(cur, "style_id", None) not in visited:
                visited.add(getattr(cur, "style_id", None))
                el = getattr(cur, "_element", None)
                pPr = el.find(qn("w:pPr")) if el is not None else None
                numPr = pPr.find(qn("w:numPr")) if pPr is not None else None
                if numPr is not None:
                    nid_el = numPr.find(qn("w:numId"))
                    ilvl_el = numPr.find(qn("w:ilvl"))
                    if nid is None and nid_el is not None:
                        nid = nid_el.get(qn("w:val"))
                    if ilvl is None and ilvl_el is not None:
                        try:
                            ilvl = int(ilvl_el.get(qn("w:val")))
                        except (TypeError, ValueError):
                            pass
                    if nid is not None and ilvl is not None:
                        break
                cur = getattr(cur, "base_style", None)
            if nid is not None or ilvl is not None:
                self.style_numpr[sid] = (nid, ilvl)

    def _load_numbering(self, doc) -> None:
        try:
            numbering_part = doc.part.numbering_part
        except Exception:
            numbering_part = None
        if numbering_part is None:
            return
        root = numbering_part.numbering_definitions._numbering
        for ab in root.findall(qn("w:abstractNum")):
            aid = ab.get(qn("w:abstractNumId"))
            if aid is None:
                continue
            levels: dict[int, dict] = {}
            for lvl in ab.findall(qn("w:lvl")):
                try:
                    il = int(lvl.get(qn("w:ilvl")))
                except (TypeError, ValueError):
                    continue
                levels[il] = self._parse_lvl_elem(lvl)
            nsl = ab.find(qn("w:numStyleLink"))
            sl = ab.find(qn("w:styleLink"))
            num_style_link = nsl.get(qn("w:val")) if nsl is not None else None
            style_link = sl.get(qn("w:val")) if sl is not None else None
            if style_link and levels:
                self.style_link_levels[style_link] = levels
            self.abstract_nums[aid] = {
                "levels": levels,
                "num_style_link": num_style_link,
            }

        for info in self.abstract_nums.values():
            if not info["levels"] and info["num_style_link"]:
                linked = self.style_link_levels.get(info["num_style_link"])
                if linked:
                    info["levels"] = linked

        for num in root.findall(qn("w:num")):
            nid = num.get(qn("w:numId"))
            abs_el = num.find(qn("w:abstractNumId"))
            if nid is None or abs_el is None:
                continue
            aid = abs_el.get(qn("w:val"))
            overrides: dict[int, dict] = {}
            for ov in num.findall(qn("w:lvlOverride")):
                try:
                    il = int(ov.get(qn("w:ilvl")))
                except (TypeError, ValueError):
                    continue
                st_ov = ov.find(qn("w:startOverride"))
                lvl_ov = ov.find(qn("w:lvl"))
                ov_dict: dict = {}
                if st_ov is not None:
                    try:
                        ov_dict["start"] = int(st_ov.get(qn("w:val")))
                    except (TypeError, ValueError):
                        pass
                if lvl_ov is not None:
                    ov_dict["lvl"] = self._parse_lvl_elem(lvl_ov)
                overrides[il] = ov_dict
            self.nums[nid] = {"abstract_id": aid, "overrides": overrides}

    def _effective_level(self, num_id: str, ilvl: int) -> dict | None:
        num_info = self.nums.get(num_id)
        if not num_info:
            return None
        abs_info = self.abstract_nums.get(num_info["abstract_id"])
        base_levels = abs_info["levels"] if abs_info else {}
        lvl = dict(base_levels.get(ilvl, {}))
        ov = num_info["overrides"].get(ilvl)
        if ov:
            if "lvl" in ov:
                lvl = dict(ov["lvl"])
            if "start" in ov:
                lvl["start"] = ov["start"]
        return lvl or None

    def _effective_numpr(self, p_element) -> tuple[str | None, int]:
        pPr = p_element.find(qn("w:pPr"))
        nid = None
        ilvl = None
        if pPr is not None:
            numPr = pPr.find(qn("w:numPr"))
            if numPr is not None:
                nid_el = numPr.find(qn("w:numId"))
                ilvl_el = numPr.find(qn("w:ilvl"))
                if nid_el is not None:
                    nid = nid_el.get(qn("w:val"))
                if ilvl_el is not None:
                    try:
                        ilvl = int(ilvl_el.get(qn("w:val")))
                    except (TypeError, ValueError):
                        pass
            if nid == "0":
                return None, 0
            if nid is None:
                pStyle_el = pPr.find(qn("w:pStyle"))
                if pStyle_el is not None:
                    st_nid, st_ilvl = self.style_numpr.get(pStyle_el.get(qn("w:val"), ""), (None, None))
                    nid = st_nid
                    if ilvl is None:
                        ilvl = st_ilvl
        if not nid or nid == "0":
            return None, 0
        return nid, (0 if ilvl is None else ilvl)

    @staticmethod
    def _normalize_lvl_text(lvl_text: str, ilvl: int) -> str:
        """
        Ensure lvlText has a %N placeholder so counters can expand.
        Broken .doc→.docx conversions often freeze literals like '1.0' with no %1.
        """
        text = (lvl_text or "").strip()
        if not text:
            return f"%{ilvl + 1}."
        if "%" in text:
            return text
        # Frozen "1.0" / "1." / "8.1" → "%1.0" / "%1." / "%1.1" (leading digits only)
        if re.fullmatch(r"\d+(?:\.\d+)*\.?", text):
            return re.sub(r"^\d+", f"%{ilvl + 1}", text, count=1)
        return text

    def _counter_key(self, nid: str, fmt: str) -> str:
        """
        Share sequence across numIds that point at the same abstractNum.
        .doc conversion often gives each heading its own numId + startOverride=1,
        which otherwise restarts every section at 1.0. Bullets stay per-numId.
        """
        if fmt == "bullet":
            return f"n:{nid}"
        aid = (self.nums.get(nid) or {}).get("abstract_id")
        if aid is not None:
            return f"a:{aid}"
        return f"n:{nid}"

    def prefix_for_p(self, p_element) -> tuple[str, bool]:
        """Advance numbering state for p_element once and cache (prefix, is_bullet)."""
        if p_element is None or not self.nums:
            return "", False
        if p_element in self._p_cache:
            return self._p_cache[p_element]
        nid, ilvl = self._effective_numpr(p_element)
        if not nid or nid not in self.nums:
            self._p_cache[p_element] = ("", False)
            return "", False
        lvl_spec = self._effective_level(nid, ilvl)
        if not lvl_spec:
            self._p_cache[p_element] = ("", False)
            return "", False

        fmt = lvl_spec.get("fmt", "decimal")
        lvl_text = lvl_spec.get("text", "")
        if fmt == "none":
            self._p_cache[p_element] = ("", False)
            return "", False
        if fmt == "bullet":
            # Advance/cache under per-numId key so bullet lists stay independent.
            state = self.counters.setdefault(self._counter_key(nid, fmt), {})
            if ilvl not in state:
                state[ilvl] = int(lvl_spec.get("start", 1))
            else:
                state[ilvl] += 1
            glyph = _BULLET_GLYPH_MAP.get(lvl_text, lvl_text or "•")
            if glyph and ord(glyph[0]) >= 0xF000:
                glyph = "•"
            res = (glyph.strip(), True)
            self._p_cache[p_element] = res
            return res

        # Decimal / outline section numbers (1.0, 2.0, …): share abstractNum counter.
        state = self.counters.setdefault(self._counter_key(nid, fmt), {})
        for anc in range(0, ilvl):
            if anc not in state:
                anc_spec = self._effective_level(nid, anc) or {}
                state[anc] = int(anc_spec.get("start", 1))

        if ilvl not in state:
            state[ilvl] = int(lvl_spec.get("start", 1))
        else:
            # Continue sequence even when a new numId carries startOverride=1.
            state[ilvl] += 1

        for deeper in [k for k in state if k > ilvl]:
            deeper_spec = self._effective_level(nid, deeper) or {}
            if deeper_spec.get("restart") != 0:
                del state[deeper]

        lvl_text = self._normalize_lvl_text(lvl_text, ilvl)
        is_lgl = bool(lvl_spec.get("is_lgl"))

        def _replace_marker(m):
            idx = int(m.group(1)) - 1
            spec = self._effective_level(nid, idx) or {}
            val = state.get(idx, int(spec.get("start", 1)))
            marker_fmt = "decimal" if is_lgl else spec.get("fmt", "decimal")
            return _format_num_counter(val, marker_fmt)

        resolved = re.sub(r"%([1-9])", _replace_marker, lvl_text).strip()
        res = (resolved, False)
        self._p_cache[p_element] = res
        return res


def _strip_duplicate_prefix(para_text: str, num_prefix: str, is_bullet: bool) -> str:
    """Return num_prefix only when para_text does not already carry it."""
    if not num_prefix:
        return ""
    trimmed = (para_text or "").lstrip()
    if not trimmed:
        return ""
    if is_bullet:
        if trimmed[0] in ("•", "-", "*", "➢", "▪", "◦", "✓"):
            return ""
        return num_prefix
    if trimmed.startswith(num_prefix) or re.match(r"^\d+(?:\.\d+)+\b", trimmed):
        return ""
    return num_prefix


def _num_prefix_span(num_prefix: str, is_bullet: bool, *, bold: bool = False) -> str:
    """Format an inline HTML span for a resolved list/section prefix."""
    if not num_prefix:
        return ""
    weight = "700" if (bold or not is_bullet) else "400"
    min_w = "20px" if is_bullet else "40px"
    return (
        f'<span class="docx-num-prefix" style="display:inline-block; min-width:{min_w}; '
        f'font-weight:{weight}; margin-right:6px;">{_escape_html(num_prefix)}</span>'
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


def _is_toc_title_text(text: str) -> bool:
    """True for the lone TOC heading Word places before the TOC SDT."""
    t = re.sub(r"\s+", " ", (text or "").strip())
    t = re.sub(r"^[📋\s]+", "", t)
    t = re.sub(r"[^\w\s]", "", t).strip().upper()
    return t in {"TABLE OF CONTENTS", "TABLE OF CONTENT", "CONTENTS"}


def _is_verification_pid_heading(text: str) -> bool:
    """8.4 Verification with the current P & ID — Word leaves blank lines for notes."""
    return bool(
        re.search(
            r"verification\s+with\s+the\s+current\s+p\s*&?\s*id",
            text or "",
            re.I,
        )
    )


def _render_section_notes_textarea(
    location: str,
    field_map: dict,
    mode: str = "edit",
    placeholder: str = "Enter notes / observations",
    rows: int = 3,
) -> str:
    """Editable body box under a locked section heading (e.g. 8.4 P&ID)."""
    field = None
    if field_map:
        field = field_map.get(location)
        # Alias: draft may be stored under para_verification_pid_body or para_N_body.
        if field is None and location != "para_verification_pid_body":
            field = field_map.get("para_verification_pid_body")
        if field is None:
            for _k, _f in field_map.items():
                if not isinstance(_f, dict):
                    continue
                if str(_k).endswith("_body") and str(_f.get("value") or "").strip():
                    field = _f
                    break
    val = ""
    if field and field.get("value") is not None:
        val = str(field.get("value") or "")
    if _is_readonly_mode(mode):
        display = _escape_html(val) if val.strip() else "&nbsp;"
        return (
            f'<div class="docx-para-edit-wrapper" data-field="{_escape_html(location)}">'
            f'<div class="docx-para-content" style="min-height:48px;white-space:pre-wrap;">{display}</div></div>'
        )
    return (
        f'<div class="docx-para-edit-wrapper" data-field="{_escape_html(location)}">'
        f'<textarea name="fields[{_escape_html(location)}]" class="docx-input docx-para-content" '
        f'rows="{rows}" placeholder="{_escape_html(placeholder)}" '
        f'style="width:100%; box-sizing:border-box; font-family:inherit; font-size:inherit; '
        f'line-height:1.5; resize:vertical; padding:6px 10px; border:1px solid #cbd5e1; '
        f'border-radius:4px; background:#fffde7;">{_escape_html(val)}</textarea></div>'
    )


def _append_verification_pid_notes(
    html_parts: list,
    para_index: int | None,
    para_text: str,
    field_map: dict,
    mode: str,
) -> bool:
    """Word leaves blank lines under 8.4 — expose a notes textarea there."""
    if not _is_verification_pid_heading(para_text):
        return False
    loc = f"para_{para_index}_body" if para_index is not None else "para_verification_pid_body"
    html_parts.append(
        _render_section_notes_textarea(
            loc,
            field_map,
            mode,
            placeholder="Enter P & ID verification notes / observations",
            rows=4,
        )
    )
    return True


def _split_toc_entry_lines(text: str) -> list[str]:
    """
    Split TOC cell/paragraph text into one entry per section.
    Handles Word cells that pack '7.0 … 5\\n8.0 … 6' (or a lost newline) into one cell.
    """
    if not text or not str(text).strip():
        return []
    raw = str(text).replace("\r\n", "\n").replace("\r", "\n")
    out: list[str] = []
    for ln in raw.split("\n"):
        ln = ln.strip()
        if not ln:
            continue
        # Split merged entries when a second 'N.N TITLE' starts mid-line.
        parts = re.split(r"(?=(?<!\d)\d+\.\d+\s+[A-Za-z(])", ln)
        parts = [p.strip() for p in parts if p.strip()]
        if len(parts) > 1:
            out.extend(parts)
        else:
            out.append(ln)
    return out


def _parse_toc_line_title_page(line: str) -> tuple[str, str]:
    """Return (title, page) from a TOC entry line; page may be empty."""
    line = (line or "").strip()
    if not line:
        return "", ""
    # Word TOC1: "1.0\\tOBJECTIVE\\t2"
    if "\t" in line:
        parts = [p.strip() for p in line.split("\t") if p.strip()]
        if len(parts) >= 2 and re.fullmatch(r"\d+", parts[-1]):
            return re.sub(r"\s+", " ", " ".join(parts[:-1])), parts[-1]
    m = re.search(r"^(.*?)[ \t\.\u2026\u22ef]{3,}\s*(\d+)\s*$", line)
    if m:
        return m.group(1).strip(), m.group(2).strip()
    m2 = re.search(r"^(.*?)[ \t\.\u2026\u22ef]+\s*(\d+)\s*$", line)
    if m2:
        return m2.group(1).strip(), m2.group(2).strip()
    # Stuck page: "1.0 OBJECTIVE2" / "OBJECTIVE2" (tab lost in HTML/plain extract)
    m3 = re.match(r"^(\d+(?:\.\d+)*\s+.+?)(\d+)\s*$", line)
    if m3 and not re.search(r"\d$", m3.group(1).rstrip()):
        return m3.group(1).strip(), m3.group(2).strip()
    return line, ""


def _render_toc_line_html(
    title_val: str,
    page_val: str,
    loc: str,
    title_loc: str,
    readonly: bool,
) -> str:
    """One Word-style TOC row: title + dotted leaders + page number."""
    m_sec = re.match(r"^(\d+(?:\.\d+)*)\s*(.*)$", title_val or "")
    sec_num = m_sec.group(1) if m_sec else ""
    clean_title = m_sec.group(2) if m_sec else (title_val or "")
    # Character leaders so PDF / print still show dots (border-bottom alone often vanishes).
    dots = (
        '<span class="toc-dots" aria-hidden="true">'
        ".................................................."
        "</span>"
    )
    if readonly:
        page_html = (
            f'<span class="toc-page" data-field="{_escape_html(loc)}" '
            f'data-toc-sec="{_escape_html(sec_num)}" data-toc-title="{_escape_html(clean_title)}">'
            f"{_escape_html(page_val)}</span>"
        )
        title_html = f'<span class="toc-title">{_escape_html(title_val)}</span>'
    else:
        title_html = (
            f'<span class="toc-title">{_escape_html(title_val)}</span>'
            f'<input type="hidden" name="fields[{title_loc}]" '
            f'class="docx-toc-title-input" value="{_escape_html(title_val)}">'
        )
        page_html = (
            f'<input type="number" min="1" max="999" name="fields[{loc}]" '
            f'class="docx-input docx-toc-page-input" '
            f'data-toc-sec="{_escape_html(sec_num)}" '
            f'data-toc-title="{_escape_html(clean_title)}" '
            f'value="{_escape_html(page_val)}">'
        )
    return (
        f'<div class="docx-toc-line" data-toc-sec="{_escape_html(sec_num)}" '
        f'data-toc-title="{_escape_html(clean_title)}">'
        f"{title_html}{dots}{page_html}</div>"
    )


def _render_toc_list_table_as_card(table, t_idx: int, field_map: dict, mode: str = "edit") -> str:
    """
    Att14-style single-column TOC tables → Word-like TOC card (one line per section).
    Keeps 7.0 / 8.0 on separate rows even when Word packed them in one cell.
    """
    readonly = _is_readonly_mode(mode)
    lines_html: list[str] = []
    for r_idx, row in enumerate(table.rows):
        cell = row.cells[0] if row.cells else None
        text = (cell.text or "").strip() if cell is not None else ""
        # Per-row l_idx keeps locations stable (table_0_row_8_toc_0 / _toc_1 for 7.0/8.0).
        for l_idx, line in enumerate(_split_toc_entry_lines(text)):
            title_part, page_part = _parse_toc_line_title_page(line)
            if not title_part:
                continue
            loc = f"table_{t_idx}_row_{r_idx}_toc_{l_idx}"
            title_loc = f"{loc}_title"
            page_val = page_part
            title_val = title_part
            if field_map and loc in field_map:
                stored = field_map[loc].get("value")
                if stored is not None and str(stored).strip() != "":
                    page_val = str(stored).strip()
            if field_map and title_loc in field_map:
                stored_title = field_map[title_loc].get("value")
                if stored_title is not None and str(stored_title).strip() != "":
                    title_val = str(stored_title).strip()
            lines_html.append(
                _render_toc_line_html(title_val, page_val, loc, title_loc, readonly)
            )
    if not lines_html:
        return ""
    return (
        '<div class="docx-toc-card" data-toc-table="'
        + str(t_idx)
        + '">'
        + '<div class="docx-toc-heading">TABLE OF CONTENTS</div>'
        + "".join(lines_html)
        + "</div>"
    )


def _paragraph_style_val(p_element) -> str:
    """Return Word paragraph style id (e.g. TOC1 / toc 1) from a p element."""
    try:
        from docx.oxml.ns import qn as _qn

        p_pr = p_element.find(_qn("w:pPr"))
        if p_pr is None:
            return ""
        ps = p_pr.find(_qn("w:pStyle"))
        if ps is None:
            return ""
        return (ps.get(_qn("w:val")) or "").strip()
    except Exception:
        return ""


def _is_word_toc_body_line(p_element, para_text: str = "") -> bool:
    """
    True for IOQ body TOC rows (style toc 1 / TOC1) like '1.0\\tOBJECTIVE\\t2'.
    These must render as dotted toc-lines — never plain 'OBJECTIVE2' text.
    """
    style = _paragraph_style_val(p_element).lower().replace(" ", "")
    title, page = _parse_toc_entry_element(p_element)
    if not title or not page:
        # Fall back to visible text (tabs may already be spaces).
        title2, page2 = _parse_toc_line_title_page(para_text or "")
        title, page = title2, page2
    if not title or not page:
        return False
    if style.startswith("toc"):
        return True
    raw = para_text or ""
    return ("\t" in raw) or bool(re.search(r"[\.\u2026]{3,}", raw)) or is_toc_entry_text(raw)


def _parse_toc_entry_element(p_element) -> tuple[str, str]:
    """Extract clean title and page number from a Word TOC paragraph."""
    tokens = []
    for elem in p_element.iter():
        tag = elem.tag.split("}")[-1]
        if tag == "t" and elem.text:
            tokens.append(elem.text)
        elif tag == "tab":
            tokens.append("\t")
    raw = "".join(tokens).strip()
    if not raw or _is_toc_title_text(raw):
        return "", ""

    # Word TOC1 often uses tabs: "1.0\\tOBJECTIVE\\t2"
    if "\t" in raw:
        parts = [p.strip() for p in raw.split("\t") if p.strip()]
        if len(parts) >= 2 and re.fullmatch(r"\d+", parts[-1]):
            page_num = parts[-1]
            title_part = " ".join(parts[:-1]).strip()
            title_part = re.sub(r"[\.\u2026\u22ef_:]+$", "", title_part).strip()
            return re.sub(r"\s+", " ", title_part), page_num

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
    # Honor explicit widget from Laravel (e.g. Qualification History blanks must
    # stay text — never infer date from an "Approval Date" column label).
    explicit = field.get("widget")
    if explicit not in (None, ""):
        if is_report_number_label(label) and str(explicit) == "date":
            return "text"
        return str(explicit)
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


def _count_shared_tc_rowspan(table, r_idx: int, col_idx: int, cell) -> int:
    """
    Count consecutive rows that share the same tc for this column.
    python-docx returns the merge-start cell for vertically merged cells, so
    shared identity is the reliable rowspan signal (OOXML may mark both as restart).
    """
    tc = cell._tc
    span = 1
    for nxt in range(r_idx + 1, len(table.rows)):
        nxt_cells = table.rows[nxt].cells
        if col_idx < len(nxt_cells) and nxt_cells[col_idx]._tc is tc:
            span += 1
        else:
            break
    return span


# ── _is_textarea_cell() ─────────────────────────────
# Large comment/observation cells or vertically merged cells use <textarea>.
def _is_textarea_cell(
    field: dict | None,
    row_label: str,
    rowspan: int,
    cell_text: str = "",
) -> bool:
    if rowspan > 1:
        return True
    if cell_text and "\n" in cell_text:
        return True
    label = f"{field.get('label', '') if field else ''} {row_label}".lower()
    return any(
        k in label
        for k in (
            "comment",
            "remarks",
            "observation",
            "description",
            "acceptance",
            "methodology",
            "corrective",
            "preventive",
            # 8.1: Instrument ID + Checked By are multi-line; calibration dates use calendar.
            "instrument identification",
            "identification number",
            "checked. by",
            "checked by",
        )
    )


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

    # Never emit an empty bordered shell (looked like a stray fill box under Remarks).
    if not "".join(lines).strip():
        return ""
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
    role = str(field.get("approver_role", "") or "")
    # NEQ POST Approved By → User/Validation must never use the pre-approval key.
    if role == "approved_by_user_name" and mode == "edit" and not field.get("is_pre_approver"):
        role = "post_approved_by_user_name"
        field = dict(field)
        field["approver_role"] = role
        field["locked"] = False
    date_loc = _escape_html(field.get("signature_date_location", ""))
    value = field.get("value", "")
    user_id = field.get("user_id", "")
    # Pre-approver lock is role/flag based only — never table_1/2/3 index.
    # NEQ Att11 APPROVAL PAGE is often table_3 and must stay a user dropdown.
    # post_* roles are always POST workflow selects (never pre-approval readonly).
    is_pre = (
        (not role.startswith("post_"))
        and (
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
        )
    )
    role_raw = str(field.get("approver_role") or "")
    # POST Name cells must remain user selects during edit/fill even if a stale
    # locked/prefilled flag survived merge.
    force_post_select = (
        mode == "edit"
        and not _is_readonly_mode(mode)
        and role_raw.startswith("post_")
    )
    if force_post_select:
        is_pre = False
        field = dict(field)
        field["locked"] = False
    role = _escape_html(role_raw)
    if (
        _is_readonly_mode(mode)
        or (is_pre and mode != "pre_approval")
        or (not force_post_select and _is_field_locked(field))
    ):
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
    # Att01 personnel table Name / Training Imparted by are optional data entry.
    # Only real POST workflow roles (e.g. Validation/QA) are HTML-required.
    optional_roles = {
        "training_personnel_name",
        "training_imparted_by_name",
    }
    required = ""
    if not is_pre and mode != "pre_approval" and role and role not in optional_roles:
        required = " required"
    user_id_attr = f' data-user-id="{user_id}"' if user_id else ""
    val_attr = f' value="{_escape_html(value)}"' if value else ' value=""'
    dept_loc = _escape_html(str(field.get("department_location") or ""))
    sig_loc = _escape_html(str(field.get("signature_location") or ""))
    dept_attr = f' data-department-location="{dept_loc}"' if dept_loc else ""
    sig_attr = f' data-signature-location="{sig_loc}"' if sig_loc else ""
    return (
        f'<td class="docx-cell editable docx-approver-cell"{colspan_attr}>'
        f'<select class="docx-input approver-field" '
        f'data-approver-role="{role}" '
        f'data-field-location="{location}" '
        f'data-signature-date-location="{date_loc}"{dept_attr}{sig_attr}{user_id_attr}{is_pre_attr}{required}>'
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
    # Signature & Date: keep the column label as placeholder when empty.
    default_ph = field.get("label", "") if not _is_signature_date_field(field) else (
        field.get("label") or "Signature & Date"
    )
    ph = _escape_html(placeholder or default_ph)
    cleaned = _clean_signature_cell_value(field, value or field.get("value", "") or "")
    val = _escape_html(cleaned)
    if val.strip() in ("—", "-", "–"):
        val = ""

    if widget == "textarea":
        # Grow with content so multi-line Acceptance criteria is fully visible.
        line_count = max(1, str(cleaned or "").count("\n") + 1)
        rows = max(2, min(12, line_count))
        return (
            f'<textarea name="fields[{loc}]" class="{css_class}" rows="{rows}" '
            f'style="width:100%; white-space:pre-wrap;" placeholder="{ph}">{val}</textarea>'
        )

    if widget in ("datetime", "datetime-local"):
        input_type = "datetime-local"
    elif widget in ("date", "time"):
        input_type = widget
        # Invalid leftovers like "S" break date inputs — never emit them as value.
        if val and not re.match(r"^\d{4}-\d{2}-\d{2}", val) and widget == "date":
            val = ""
        if val and widget == "time" and not re.match(r"^\d{1,2}:\d{2}", val):
            val = ""
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
# Set during build_fill_html for NEQ so body lines under 7.1 are not forced bold.
_NEQ_HTML_MODE = False


def _render_heading_paragraph(
    para_text: str,
    num_prefix: str = "",
    is_bullet: bool = False,
) -> str | None:
    """Render section headings (including numbered headings like 1.0, 8.5) with target metadata."""
    if not para_text or not para_text.strip():
        return None
    # TOC title lives with the SDT card — never emit a free heading that can
    # orphan onto the 1.0 OBJECTIVE sheet (Word keeps it only on the TOC page).
    if _is_toc_title_text(para_text):
        return None
    clean_p = dedupe_colon_label(dedupe_repeated_text(para_text)).strip()
    if _is_toc_title_text(clean_p):
        return None
    eff_prefix = _strip_duplicate_prefix(clean_p, num_prefix, is_bullet)
    prefixed_clean_p = f"{eff_prefix} {clean_p}".strip() if eff_prefix else clean_p
    clean_upper = prefixed_clean_p.upper()
    is_num_section = bool(
        eff_prefix
        and not is_bullet
        and re.match(r"^\d+(?:\.\d+)+$", eff_prefix)
        and len(clean_p) < 120
        and not has_paragraph_blank(para_text)
        and not is_paragraph_yes_no_radio(para_text)
    )
    # NEQ: only real section / short subsection titles are bold — not body lines
    # under 7.1 / 7.2 / 14.0 that share list numbering.
    if _NEQ_HTML_MODE and is_num_section:
        letters = [c for c in clean_p if c.isalpha()]
        upper_ratio = (sum(1 for c in letters if c.isupper()) / len(letters)) if letters else 0
        is_major = bool(re.match(r"^\d+\.0$", eff_prefix or ""))
        is_sub_title = (
            clean_p.endswith(":")
            or upper_ratio >= 0.75
            or (len(clean_p.split()) <= 10 and not clean_p.endswith("."))
        )
        if not (is_major or is_sub_title):
            is_num_section = False
    if (
        is_locked_section_title(prefixed_clean_p)
        or is_section_heading_paragraph(prefixed_clean_p)
        or is_num_section
        or re.match(
            r"^(?:\d+(?:\.\d+)*\s*)?(?:METHODOLOGY|REPORT APPROVAL|APPROVAL PAGE)\b",
            clean_upper,
        )
    ):
        heading_prefix = _num_prefix_span(eff_prefix, is_bullet, bold=True)
        return (
            f'<p class="docx-para docx-heading-target" style="font-weight:700;" '
            f'data-heading-text="{_escape_html(clean_upper)}">{heading_prefix}{_escape_html(clean_p)}</p>'
        )
    return None


def _render_paragraph(
    para_index: int,
    para_text: str,
    field: dict | None,
    field_map: dict,
    mode: str = "edit",
    num_prefix: str = "",
    is_bullet: bool = False,
) -> str:
    # Empty spacer paras normally skip; keep them when a fillable field is bound
    # (e.g. Summary and Conclusion body under a locked heading).
    if (not para_text or not para_text.strip()) and not field:
        return ""
    # Standalone "TABLE OF CONTENTS" is shown via the TOC card header only.
    if para_text and _is_toc_title_text(para_text) and not field:
        return ""
    if para_text and para_text.strip():
        heading_html = _render_heading_paragraph(para_text, num_prefix=num_prefix, is_bullet=is_bullet)
        if heading_html:
            return heading_html
        # Heading helper returns None for TOC titles — do not fall through to a plain <p>.
        if _is_toc_title_text(para_text):
            return ""
    para_text = dedupe_repeated_text(para_text or "")
    clean_p = dedupe_colon_label(para_text).strip()
    if clean_p and _is_toc_title_text(clean_p) and not field:
        return ""
    eff_prefix = _strip_duplicate_prefix(clean_p, num_prefix, is_bullet) if clean_p else ""
    prefixed_clean_p = f"{eff_prefix} {clean_p}".strip() if eff_prefix else clean_p
    prefix_html = _num_prefix_span(eff_prefix, is_bullet)

    # Word: "Remarks / Recommendation (if any):" is a label only — dotted lines below
    # are the fill targets. Never render a bordered textarea on this heading.
    if re.search(r"(?i)^remarks\s*/\s*recommendation\b", clean_p or ""):
        return (
            f'<p class="docx-para docx-heading-target" style="font-weight:700;" '
            f'data-heading-text="{_escape_html((clean_p or "").upper())}">'
            f"{prefix_html}{_escape_html(clean_p)}</p>"
        )

    if field and field.get("type") == "paragraph_content" and para_text and PARA_BLANK_RE.search(para_text):
        field["type"] = "paragraph_blank"

    if field and field.get("type") == "paragraph_content":
        val = field.get("value")
        if val is None or val == "":
            val = para_text or ""
        val = dedupe_repeated_text(val)
        val_prefix = _strip_duplicate_prefix(val, eff_prefix, is_bullet)
        val_prefixed = f"{val_prefix} {val}".strip() if val_prefix else val
        val_prefix_html = _num_prefix_span(val_prefix, is_bullet)
        if _is_readonly_mode(mode) or mode != "pre_approval" or _is_field_locked(field):
            h_cls = " docx-heading-target" if (val and (is_section_heading_paragraph(val_prefixed) or is_locked_section_title(val_prefixed))) else ""
            h_attr = f' data-heading-text="{_escape_html(val_prefixed.upper().strip())}"' if h_cls else ""
            h_style = ' style="font-weight:700;"' if h_cls else ""
            h_pref = _num_prefix_span(val_prefix, is_bullet, bold=bool(h_cls))
            return f'<p class="docx-para{h_cls}"{h_style}{h_attr}>{h_pref}{_escape_html(val)}</p>'
        else:
            loc = _escape_html(field.get("location", f"para_{para_index}"))
            val_esc = _escape_html(val)
            calc_rows = max(2, min(6, (len(val) // 80 + 1) if val else 2))
            ph = _escape_html(field.get("label") or "Enter summary and conclusion...")
            if val_prefix_html:
                return (
                    f'<div class="docx-para-edit-wrapper" style="margin: 6px 0; display:flex; align-items:flex-start; gap:6px;">'
                    f'<span class="docx-num-prefix" style="padding-top:6px; flex-shrink:0;">{val_prefix_html}</span>'
                    f'<textarea name="fields[{loc}]" class="docx-input docx-para-content" '
                    f'rows="{calc_rows}" placeholder="{ph}" style="flex:1; width:100%; box-sizing:border-box; font-family:inherit; font-size:inherit; line-height:1.5; resize:vertical; padding:6px 10px; border:1px solid #facc15; border-bottom:2px solid #4e73df; border-radius:4px; background-color:#fffde7 !important;">{val_esc}</textarea>'
                    f'</div>'
                )
            return (
                f'<div class="docx-para-edit-wrapper" style="margin: 6px 0;">'
                f'<textarea name="fields[{loc}]" class="docx-input docx-para-content" '
                f'rows="{calc_rows}" placeholder="{ph}" style="width:100%; box-sizing:border-box; font-family:inherit; font-size:inherit; line-height:1.5; resize:vertical; padding:6px 10px; border:1px solid #facc15; border-bottom:2px solid #4e73df; border-radius:4px; background-color:#fffde7 !important;">{val_esc}</textarea>'
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

    # Att01 Validation /Quality Assurance: — user select (POST approval assignee).
    if field and field.get("type") == "approver":
        label = dedupe_colon_label(para_text or field.get("label") or "").strip()
        if label and not label.endswith(":"):
            label = f"{label}:"
        if _is_readonly_mode(mode) or _is_field_locked(field):
            display = _render_readonly_span(field.get("value", ""), field, field.get("location", loc))
            return f'<p class="docx-para">{_escape_html(label + " ") if label else ""}{display}</p>'
        select_html = _render_approver_cell(field, mode=mode)
        # Strip outer <td>…</td> so the select sits inline after the paragraph label.
        inner = re.sub(r"^<td[^>]*>|</td>$", "", select_html.strip(), flags=re.I)
        return (
            f'<p class="docx-para docx-para-editable docx-para-approver">'
            f"{_escape_html(label + ' ') if label else ''}{inner}</p>"
        )

    if (
        field is None
        and not _is_readonly_mode(mode)
        and is_paragraph_yes_no_radio(para_text)
    ):
        pseudo = {"type": "paragraph_radio", "label": paragraph_yes_no_label(para_text), "value": ""}
        return _render_paragraph_yes_no_radio(para_index, para_text, pseudo, mode)

    # Body-walk text may expose a blank while para was skipped at parse time
    # (e.g. ALL-CAPS "(IOQ) REPORT FOR OF ……….. MACHINE" mis-classified as heading).
    if (
        (field is None or field.get("type") == "paragraph_checkbox")
        and not _is_readonly_mode(mode)
        and has_paragraph_blank(para_text)
    ):
        normalized = dedupe_colon_label(para_text)
        display_label = re.sub(r":\s*$", "", normalized).strip() or normalized
        if (
            is_date_field_label(display_label)
            or PARA_COLON_BLANK_RE.search(normalized)
            or find_paragraph_blanks(normalized)
        ):
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
        bold_title = bool(
            re.search(
                r"\(IOQ\)\s*REPORT\s+FOR|Identification\s*No\.?\s*:",
                para_text or label_text or "",
                re.I,
            )
        )
        bold_cls = " docx-para-bold-title" if bold_title else ""
        bold_style = ' style="font-weight:700;"' if bold_title else ""
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
            return f'<p class="docx-para{bold_cls}"{bold_style}>{"".join(html_parts)}</p>'
        if PARA_COLON_BLANK_RE.search(dedupe_colon_label(para_text).strip()):
            if _value_repeats_colon_label(para_text, field.get("value", "")):
                return f'<p class="docx-para{bold_cls}"{bold_style}>{_escape_html(dedupe_colon_label(para_text))}</p>'
            label_part = re.sub(r":\s*$", ": ", dedupe_colon_label(para_text))
            return f'<p class="docx-para{bold_cls}"{bold_style}>{_escape_html(label_part)}{display}</p>'
        match = PARA_BLANK_RE.search(para_text)
        if match:
            before = _escape_html(para_text[: match.start()])
            after = _escape_html(para_text[match.end() :])
            return f'<p class="docx-para{bold_cls}"{bold_style}>{before}{display}{after}</p>'
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
        # Att14: "(IOQ) REPORT FOR … MACHINE" / Identification titles are bold in Word.
        bold_title = bool(
            re.search(
                r"\(IOQ\)\s*REPORT\s+FOR|Identification\s*No\.?\s*:",
                para_text or "",
                re.I,
            )
        )
        bold_cls = " docx-para-bold-title" if bold_title else ""
        bold_style = ' style="font-weight:700;"' if bold_title else ""
        if match:
            before = _escape_html(para_text[: match.start()])
            after = _escape_html(para_text[match.end() :])
            return (
                f'<p class="docx-para docx-para-editable{bold_cls}"{bold_style}>'
                f"{before}{inp}{after}</p>"
            )

        if para_text:
            return (
                f'<p class="docx-para docx-para-editable{bold_cls}"{bold_style}>'
                f"{prefix_html}{_escape_html(para_text)} {inp}</p>"
            )
        return f'<p class="docx-para docx-para-editable{bold_cls}"{bold_style}>{inp}</p>'

    # Locked in edit mode: show stored value (or template text) without an input.
    if field and _is_field_locked(field):
        locked_val = (field.get("value") or "").strip()
        if locked_val:
            if _value_repeats_colon_label(para_text, locked_val):
                return f'<p class="docx-para">{prefix_html}{_escape_html(dedupe_colon_label(para_text))}</p>'
            if PARA_COLON_BLANK_RE.search(dedupe_colon_label(para_text).strip()):
                label_part = re.sub(r":\s*$", ": ", dedupe_colon_label(para_text))
                return (
                    f'<p class="docx-para">'
                    f"{prefix_html}{_escape_html(label_part)}"
                    f"{_render_readonly_span(locked_val, field, loc)}</p>"
                )
            return f'<p class="docx-para">{prefix_html}{_render_readonly_span(locked_val, field, loc)}</p>'
        if para_text:
            return f'<p class="docx-para">{prefix_html}{_escape_html(dedupe_colon_label(para_text))}</p>'
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
                    f"{prefix_html}{_escape_html(label_part)}{inp}</p>"
                )
        if mode == "pre_approval" and not _is_readonly_mode(mode) and not is_locked_section_title(prefixed_clean_p) and not is_section_heading_paragraph(prefixed_clean_p) and len(para_text.strip()) > 5:
            loc = f"para_{para_index}"
            val_esc = _escape_html(para_text)
            calc_rows = max(2, min(8, len(para_text) // 70 + 1))
            if prefix_html:
                return (
                    f'<div class="docx-para-edit-wrapper" style="margin: 6px 0; display:flex; align-items:flex-start; gap:6px;">'
                    f'<span class="docx-num-prefix" style="padding-top:6px; flex-shrink:0;">{prefix_html}</span>'
                    f'<textarea name="fields[{loc}]" class="docx-input docx-para-content" '
                    f'rows="{calc_rows}" style="flex:1; width:100%; box-sizing:border-box; font-family:inherit; font-size:inherit; line-height:1.5; resize:vertical; padding:6px 10px; border:1px solid #facc15; border-bottom:2px solid #4e73df; border-radius:4px; background-color:#fffde7 !important;">{val_esc}</textarea>'
                    f'</div>'
                )
            return (
                f'<div class="docx-para-edit-wrapper" style="margin: 6px 0;">'
                f'<textarea name="fields[{loc}]" class="docx-input docx-para-content" '
                f'rows="{calc_rows}" style="width:100%; box-sizing:border-box; font-family:inherit; font-size:inherit; line-height:1.5; resize:vertical; padding:6px 10px; border:1px solid #facc15; border-bottom:2px solid #4e73df; border-radius:4px; background-color:#fffde7 !important;">{val_esc}</textarea>'
                f'</div>'
            )
        return f'<p class="docx-para">{prefix_html}{_escape_html(dedupe_colon_label(para_text))}</p>'

    return ""


# ── _is_field_locked() ──────────────────────────────
# Normalises locked flag from JSON (bool / 0|1 / "false" strings).
def _is_field_locked(field: dict) -> bool:
    # Honor explicit locked flag — Sr. No. may be editable (HVAC methodology).
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


# Integer serials only — Word outline "10.0" must not be treated as Sl. No.
_SL_NO_VALUE_RE = re.compile(r"^\d+[\.\)]?$")


def _looks_like_sl_no_value(value: str) -> bool:
    """True for 1, 1., 2) — not outline numbers like 10.0 / 10.1."""
    return bool(_SL_NO_VALUE_RE.match((value or "").strip()))


def _format_sl_no_display(r_idx: int, field: dict | None, cell_text: str) -> str:
    """
    Display value for a locked Sl. No. cell.
    Prefers live DOCX cell text (1., 2.). When Word left the cell empty but the
    parser assigned an auto_serial (Att07 Main Body / Components blocks), show that.
    Otherwise empty Word cells stay blank — do not invent row-index serials.
    """
    if r_idx == 0:
        return cell_text
    cell_val = (cell_text or "").strip()
    if cell_val and _looks_like_sl_no_value(cell_val):
        return cell_val
    field_val = ((field or {}).get("value") or "").strip()
    if field_val and _looks_like_sl_no_value(field_val):
        return field_val
    auto_serial = (field or {}).get("auto_serial")
    if auto_serial not in (None, ""):
        try:
            return f"{int(auto_serial)}."
        except (TypeError, ValueError):
            pass
    # Empty in Word with no assigned serial → blank (merged Sr. No. continuation).
    if not cell_val:
        return ""
    return cell_val


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
    """Render Sl. No. — editable on fill; locked only in readonly preview."""
    display = _format_sl_no_display(r_idx, field, cell_text)
    # Drop outline junk (10.0) left over from Word list numbering.
    if display and not _looks_like_sl_no_value(display):
        auto_serial = (field or {}).get("auto_serial")
        if auto_serial not in (None, ""):
            try:
                display = f"{int(auto_serial)}."
            except (TypeError, ValueError):
                display = f"{r_idx}." if r_idx > 0 else ""
        else:
            display = f"{r_idx}." if r_idx > 0 else ""
    if not display and r_idx > 0:
        display = f"{r_idx}."

    # Fill / pre-approval: keep Sr. No. editable. Readonly preview stays plain text.
    force_locked = readonly
    if force_locked:
        style = _inline_cell_style(cell_style, readonly=True, value_cell=True)
        style += "text-align:center;"
        loc_attr = f' data-field="{_escape_html(location)}"' if location else ""
        content = _escape_html(display)
        extra = " locked readonly" if r_idx > 0 else ""
        return (
            f'<td class="docx-cell{extra}" style="{style}"{rowspan_attr}{colspan_attr}{loc_attr}>'
            f"{content}</td>"
        )

    loc = location or (field or {}).get("location") or ""
    inp = _render_field_input(
        loc,
        field
        if isinstance(field, dict)
        else {
            "location": loc,
            "label": "Sr. No.",
            "type": "table_multicol",
            "widget": "text",
        },
        css_class="docx-input",
        placeholder="Sr. No.",
        value=display,
    )
    style = _inline_cell_style(cell_style, readonly=False, value_cell=True)
    style += "text-align:center;"
    return (
        f'<td class="docx-cell editable" style="{style}"{rowspan_attr}{colspan_attr} '
        f'data-field="{_escape_html(loc)}">{inp}</td>'
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
def _cell_numbered_text(cell, num_resolver) -> str:
    """Resolve cell paragraph text including Word <w:numPr> prefixes (idempotent via resolver cache)."""
    tc = getattr(cell, "_tc", None)
    if tc is None or num_resolver is None:
        return cell.text.strip()
    lines = []
    for p_el in tc.findall(qn("w:p")):
        p_txt = _para_text_from_element(p_el)
        pref, is_bul = num_resolver.prefix_for_p(p_el)
        if p_txt:
            eff_pref = _strip_duplicate_prefix(p_txt, pref, is_bul)
            lines.append(f"{eff_pref} {p_txt}".strip() if eff_pref else p_txt)
        elif pref:
            # Att02 Sr. No.: empty run text, number comes only from w:numPr (1., 2., …).
            lines.append(str(pref).strip())
        else:
            lines.append("")
    return "\n".join(ln for ln in lines if ln).strip() or cell.text.strip()


def _find_table_column_header_row(table):
    """Return the first real column-header row (Checks/Methodology/Sr. No.), not a banner."""
    if not table.rows:
        return None
    headers = get_table_column_headers(table, is_report_approval_block_table(table))
    for row in table.rows[:8]:
        if is_repeated_column_header_row(row, headers):
            return row
    for row in table.rows[:4]:
        if not is_equipment_section_banner_row(row):
            return row
    return table.rows[0]


def _render_table_header_row_html(table, t_idx: int, readonly: bool = False) -> str:
    """Clone the real column-header row after a mid-table page break."""
    if not table.rows:
        return ""
    row = _find_table_column_header_row(table) or table.rows[0]
    cells = row.cells
    parts = ['<tr data-row-index="0" data-cont-header="1">']
    seen: set = set()
    for cell in cells:
        tc = cell._tc
        if tc in seen:
            continue
        seen.add(tc)
        text = dedupe_colon_label(cell.text.strip())
        colspan = _cell_colspan(cell, cells)
        colspan_attr = f' colspan="{colspan}"' if colspan > 1 else ""
        style = _inline_cell_style("font-weight:bold;background:#f1f5f9; color:#1e293b;", readonly=readonly)
        parts.append(
            f'<th class="docx-cell docx-table-header docx-heading-target" '
            f'data-heading-text="{_escape_html(text.upper())}" style="{style}"{colspan_attr}>'
            f"{_escape_html(text)}</th>"
        )
    parts.append("</tr>")
    return "".join(parts)


def _table_grid_col_widths_dxa(table) -> list[int]:
    """Return Word tblGrid column widths in twips (dxa), or [] when unavailable."""
    try:
        grid = table._tbl.find(qn("w:tblGrid"))
        if grid is None:
            return []
        widths: list[int] = []
        for col in grid.findall(qn("w:gridCol")):
            raw = col.get(qn("w:w"))
            if raw and str(raw).isdigit() and int(raw) > 0:
                widths.append(int(raw))
        return widths
    except Exception:
        return []


def _is_dept_signature_layout_table(table) -> bool:
    """
    Prepared/Checked/Approved By tables: Department | Name & Designation | Signature & Date.
    Long department labels (e.g. Head - Quality Assurance) must stay clipped in col_0.
    """
    if not table.rows:
        return False
    try:
        header = [c.text.strip() for c in table.rows[0].cells]
    except Exception:
        return False
    if not is_signature_table(header):
        return False
    blob = " ".join(header).lower()
    return (
        "name" in blob
        and ("designation" in blob or "signature" in blob or "date" in blob)
        and any(k in blob for k in ("prepared by", "checked by", "approved by"))
    )


def _render_table_colgroup(table) -> str:
    """Emit <colgroup> from Word grid so signature columns match the original DOCX."""
    widths = _table_grid_col_widths_dxa(table)
    if len(widths) < 2:
        return ""
    total = sum(widths) or 1
    parts = ["<colgroup>"]
    for w in widths:
        pct = max(8.0, round((w / total) * 1000) / 10.0)
        parts.append(f'<col style="width:{pct}%;">')
    parts.append("</colgroup>")
    return "".join(parts)


def _render_table(table, t_idx: int, field_map: dict, mode: str = "edit", page_tracker: list = None, header_tpl: str = "", footer_tpl: str = "", num_resolver=None, hard_page_breaks_only: bool = False) -> str:
    # Att14 Discussion Report: 1-col TOC list → card with one line per section
    # (Word packs 7.0 + 8.0 in one cell; card split keeps them separate).
    if is_toc_list_table(table):
        return _render_toc_list_table_as_card(table, t_idx, field_map, mode)

    dept_sig = _is_dept_signature_layout_table(table)
    extra_cls = " docx-dept-signature-table" if dept_sig else ""
    html = [f'<table class="docx-table{extra_cls}" data-table-index="{t_idx}">']
    colgroup = _render_table_colgroup(table)
    if colgroup:
        html.append(colgroup)
    readonly = _is_readonly_mode(mode)
    sl_no_col = _table_sl_no_col_index(table)
    toc_table = is_toc_table(table) or is_toc_list_table(table)
    col_headers = get_table_column_headers(table, is_report_approval_block_table(table))
    # Track vertically merged cells so Sr. No. (and other merges) render once with rowspan.
    # Hold the tc element objects themselves — id() integers are unsafe across rows (GC reuse).
    rendered_tc_els: set = set()
    rows_since_break = 0
    column_header_seen = False
    total_rows = len(table.rows)
    try:
        ncols = len(table.columns)
    except Exception:
        ncols = len(table.rows[0].cells) if table.rows else 0
    # Server-chunk only tall Functional Tests style sheets (Sr.No + Acceptance).
    # 9.2 General Checks / Major components must stay ONE table — splitting every
    # ~6 rows hid "Supply HEPA housing Box", orphaned the 9.2 heading, and made
    # "Fresh air filter" look duplicated across continuation headers.
    _hdr_blob = " ".join(col_headers or []).lower()
    _is_functional_tests_sheet = bool(
        ncols >= 4
        and total_rows > 12
        and re.search(r"acceptance\s*criteria", _hdr_blob)
        and re.search(r"(sr\.?\s*no|identification|position\s*of\s*valve)", _hdr_blob)
    )
    _is_major_components_sheet = bool(
        re.search(r"methodology", _hdr_blob)
        or re.search(r"observation\s*/\s*result", _hdr_blob)
        or re.search(r"major\s*components", _hdr_blob)
    )
    chunk_large = (
        (not readonly)
        and page_tracker is not None
        and _is_functional_tests_sheet
        and not _is_major_components_sheet
    )

    def _open_continued_table_page() -> None:
        page_tracker[0] += 1
        next_p = page_tracker[0]
        html.append("</table></div>")
        prev_f = footer_tpl.replace("{PAGE}", str(next_p - 1)) if footer_tpl else ""
        html.append(f"{prev_f}</div>")
        html.append(
            f'<div class="docx-page-break-indicator no-print" data-next-page="{next_p}">\n'
            f'  <div class="docx-page-break-line"></div>\n'
            f'  <div class="docx-page-break-badge"><i class="fas fa-file-alt mr-1"></i> Page {next_p}</div>\n'
            f'  <div class="docx-page-break-line"></div>\n'
            f'</div>'
        )
        next_h = header_tpl.replace("{PAGE}", str(next_p)) if header_tpl else ""
        html.append(
            f'<div class="docx-a4-page" data-page-number="{next_p}" id="docxPage{next_p}">\n'
            f'{next_h}\n'
            f'<div class="docx-page-body">\n'
            f'<table class="docx-table" data-table-index="{t_idx}">'
        )
        # Repeat column headers on the continuation sheet.
        html.append(_render_table_header_row_html(table, t_idx, readonly=readonly))

    for r_idx, row in enumerate(table.rows):
        is_row_page_break = False
        if r_idx > 0:
            try:
                from lxml import etree
                xml_b = etree.tostring(row._tr)
                if b'type="page"' in xml_b or b"<w:pageBreakBefore" in xml_b:
                    is_row_page_break = True
            except Exception:
                pass

        # Duplicate Checks/Methodology rows are Word soft page-break repeats.
        # First one is the real header; later ones mark a natural sheet break
        # (9.2 Mechanical → coils/HEPA → Electrical) so those rows stay visible.
        if is_repeated_column_header_row(row, col_headers):
            if column_header_seen:
                if (
                    page_tracker is not None
                    and not readonly
                    and rows_since_break > 0
                    and _is_major_components_sheet
                ):
                    _open_continued_table_page()
                    rows_since_break = 0
                continue
            column_header_seen = True

        row0_text = ""
        try:
            row0_text = (row.cells[0].text or "").strip().lower() if row.cells else ""
        except Exception:
            row0_text = ""
        is_perf_banner = bool(re.search(r"machine\s+performance", row0_text))
        # Functional Tests: break before Sr.No grows past ~5 data rows on a
        # sheet (plus banner). Breaking later lets client re-pull clip 8–12
        # and show a 7→13 jump under overflow:hidden.
        force_chunk = (
            chunk_large
            and r_idx > 1
            and (
                rows_since_break >= 5
                or (is_perf_banner and rows_since_break >= 2)
            )
        )

        if r_idx > 0 and page_tracker is not None and (is_row_page_break or force_chunk):
            _open_continued_table_page()
            rows_since_break = 0

        html.append(f'<tr data-row-index="{r_idx}">')
        if r_idx > 0:
            rows_since_break += 1

        cells = row.cells
        # Att07 / Att3: "Verified by sign and date :" is an e-sign line, not a section banner.
        if is_verified_by_sign_row(row):
            colspan = len(cells) or 1
            colspan_attr = f' colspan="{colspan}"' if colspan > 1 else ""
            loc = f"table_{t_idx}_row_{r_idx}_col_0"
            field = field_map.get(loc)
            if field is None:
                # Merged footer may be stored under another col index of the same tc.
                for ci in range(colspan):
                    alt = field_map.get(f"table_{t_idx}_row_{r_idx}_col_{ci}")
                    if alt:
                        field = alt
                        loc = alt.get("location") or loc
                        break
            label = "Verified by sign and date :"
            raw_label = dedupe_colon_label((cells[0].text if cells else "") or "")
            if raw_label:
                label = raw_label if raw_label.endswith(":") else f"{raw_label.rstrip()} :"
            value = str((field or {}).get("value") or "").strip()
            style = _inline_cell_style("", readonly=readonly, value_cell=not readonly)
            if readonly or _is_field_locked(field or {}):
                display = value or label
                html.append(
                    f'<td class="docx-cell" style="{style}"{colspan_attr} data-field="{_escape_html(loc)}">'
                    f"{_escape_html(display)}</td>"
                )
            else:
                inp = _render_field_input(
                    loc,
                    field
                    if isinstance(field, dict)
                    else {
                        "location": loc,
                        "label": label,
                        "type": "table_multicol",
                        "widget": "text",
                    },
                    css_class="docx-input",
                    placeholder=label,
                    value=value,
                )
                html.append(
                    f'<td class="docx-cell editable" style="{style}"{colspan_attr} '
                    f'data-field="{_escape_html(loc)}">{inp}</td>'
                )
            html.append("</tr>")
            continue
        # Att10: "Safety Checks By (Sign./Date)" is an e-sign line, not a section banner.
        if is_safety_by_sign_row(row):
            colspan = len(cells) or 1
            colspan_attr = f' colspan="{colspan}"' if colspan > 1 else ""
            loc = f"table_{t_idx}_row_{r_idx}_col_0"
            field = field_map.get(loc)
            if field is None:
                for ci in range(colspan):
                    alt = field_map.get(f"table_{t_idx}_row_{r_idx}_col_{ci}")
                    if alt:
                        field = alt
                        loc = alt.get("location") or loc
                        break
            raw_label = re.sub(
                r"\s+",
                " ",
                dedupe_colon_label((cells[0].text if cells else "") or ""),
            ).strip()
            label = raw_label or "Safety Checks By (Sign./Date)"
            if not label.endswith(":"):
                label = f"{label.rstrip()} :"
            value = str((field or {}).get("value") or "").strip()
            # Keep label visible after stamp; blank value still shows the prompt.
            if value and not re.match(r"^safety\s*(?:checks?\s*)?s?\s*by\b", value, re.I):
                value = f"{label.rstrip(' :')} : {value}"
            style = _inline_cell_style("", readonly=readonly, value_cell=not readonly)
            if readonly or _is_field_locked(field or {}):
                display = value or label
                html.append(
                    f'<td class="docx-cell" style="{style}"{colspan_attr} data-field="{_escape_html(loc)}">'
                    f"{_escape_html(display)}</td>"
                )
            else:
                inp = _render_field_input(
                    loc,
                    field
                    if isinstance(field, dict)
                    else {
                        "location": loc,
                        "label": label,
                        "type": "table_multicol",
                        "widget": "text",
                        "stamp_eligible": True,
                    },
                    css_class="docx-input",
                    placeholder=label,
                    value=value,
                )
                html.append(
                    f'<td class="docx-cell editable" style="{style}"{colspan_attr} '
                    f'data-field="{_escape_html(loc)}">{inp}</td>'
                )
            html.append("</tr>")
            continue
        # "Mechanical Adjustment:" / "Machine Performance:" / "Major components :" are merged title rows.
        # Col 0 is the Sr. No. column, so without this they render as the row number.
        if is_equipment_section_banner_row(row):
            raw_banner = _cell_numbered_text(cells[0], num_resolver) if cells else ""
            banner = dedupe_colon_label(raw_banner)
            colspan = len(cells) or 1
            if r_idx == 0:
                style = _inline_cell_style("font-weight:bold;background:#f1f5f9; color:#1e293b;", readonly=readonly)
                html.append(
                    f'<th class="docx-cell docx-table-header docx-heading-target" data-heading-text="{_escape_html(banner.upper())}" colspan="{colspan}" style="{style}">'
                    f"{_escape_html(banner)}</th>"
                )
            else:
                style = _inline_cell_style("font-weight:bold;background:#f8fafc; color:#1e293b;", readonly=readonly)
                html.append(
                    f'<td class="docx-cell docx-heading-target" data-heading-text="{_escape_html(banner.upper())}" colspan="{colspan}" style="{style}">'
                    f"{_escape_html(banner)}</td>"
                )
            html.append("</tr>")
            continue
        # Mid-table Word header repeats are already skipped above when
        # column_header_seen was already True. Do NOT skip the first
        # Checks/Methodology row after a "Major components :" banner (9.2).
        is_two_col = len(cells) == 2
        is_one_col = len(cells) == 1
        row_label = cells[0].text.strip() if cells else ""
        # Column headers may sit on row 1+ when row 0 is "Major components :".
        is_col_header_row = bool(
            is_repeated_column_header_row(row, col_headers)
            or (r_idx == 0 and not is_equipment_section_banner_row(row))
        )
        seen_tc_els: set = set()
        # Always expand vertical merges into per-row cells (no HTML rowspan).
        # Client A4 paginators (fill + 2nd-approval show) move <tr> across pages;
        # HTML rowspan cannot span tables, so Sr.No / Identification vanish and
        # Acceptance criteria shifts left on the next sheet (9.3 Functional Tests).
        expand_vmerge = True

        for col_idx, cell in enumerate(cells):
            tc_el = cell._tc
            if tc_el in seen_tc_els:
                continue
            seen_tc_els.add(tc_el)

            if expand_vmerge:
                # Still remember the master tc, but keep rendering follow rows.
                if tc_el not in rendered_tc_els:
                    rendered_tc_els.add(tc_el)
                rowspan = 1
                rowspan_attr = ""
            else:
                # Legacy path kept for reference; expand_vmerge is always on.
                if tc_el in rendered_tc_els:
                    continue
                rendered_tc_els.add(tc_el)
                if _cell_rowspan(cell) == 0:
                    continue
                rowspan = _count_shared_tc_rowspan(table, r_idx, col_idx, cell)
                rowspan_attr = f' rowspan="{rowspan}"' if rowspan > 1 else ""

            colspan = _cell_colspan(cell, cells)
            colspan_attr = f' colspan="{colspan}"' if colspan > 1 else ""

            raw_cell = (cell.text or "").strip()
            numbered_cell = (
                _cell_numbered_text(cell, num_resolver)
                if (r_idx > 0 and num_resolver is not None)
                else raw_cell
            )
            # dedupe_colon_label() collapses ALL whitespace — never run it on
            # multi-line Acceptance criteria or numbered lists (1..4).
            if "\n" in (numbered_cell or ""):
                text = numbered_cell.strip()
                display_text = text
            else:
                text = dedupe_colon_label(raw_cell)
                display_text = (
                    dedupe_colon_label(numbered_cell)
                    if numbered_cell
                    else text
                )
            is_sl_no_here = (
                r_idx > 0 and sl_no_col is not None and col_idx == sl_no_col
            )
            # Prefer Word list number for Sl. No. only when it is an integer serial (1. / 2.).
            # Outline prefixes like 10.0 / 10.1 must NOT become Sl. No. (scrambles HVAC tables).
            if (
                is_sl_no_here
                and display_text
                and _looks_like_sl_no_value(display_text)
            ):
                text = display_text
            elif (
                is_sl_no_here
                and display_text
                and not text
                and re.match(r"^\d+\.\d+", str(display_text).strip())
            ):
                # Empty Sl. No. cell with outline numPr — leave blank for editable default.
                text = ""
            is_header = is_col_header_row and not (
                is_two_col and not is_two_col_grid_table(table) and not is_two_col_header_row(cells, table.rows)
            )
            cell_style = "font-weight:bold;" if is_header else ""

            if is_header:
                style = _inline_cell_style(cell_style + "background:#f1f5f9; color:#1e293b;", readonly=readonly)
                html.append(
                    f'<th class="docx-cell docx-table-header docx-heading-target" data-heading-text="{_escape_html(text.upper())}" style="{style}"{rowspan_attr}{colspan_attr}>'
                    f"{_escape_html(text)}</th>"
                )
                continue

            if toc_table or is_toc_entry_text(text):
                style = _inline_cell_style(cell_style, readonly=readonly)
                lines = _split_toc_entry_lines(text)
                rendered_lines = []
                for l_idx, line in enumerate(lines):
                    title_part, page_part = _parse_toc_line_title_page(line)
                    if title_part and page_part:
                        loc = f"table_{t_idx}_row_{r_idx}_toc_{l_idx}"
                        title_loc = f"{loc}_title"
                        val = page_part
                        title_val = title_part
                        if field_map and loc in field_map:
                            stored = field_map[loc].get("value")
                            if stored is not None and str(stored).strip() != "":
                                val = str(stored).strip()
                        if field_map and title_loc in field_map:
                            stored_title = field_map[title_loc].get("value")
                            if stored_title is not None and str(stored_title).strip() != "":
                                title_val = str(stored_title).strip()
                        rendered_lines.append(
                            _render_toc_line_html(title_val, val, loc, title_loc, readonly)
                        )
                    else:
                        if toc_table and not readonly:
                            loc = f"table_{t_idx}_row_{r_idx}_col_{col_idx}"
                            if len(lines) > 1:
                                loc = f"{loc}_line_{l_idx}"
                            cell_val = line
                            if field_map and loc in field_map:
                                stored = field_map[loc].get("value")
                                if stored is not None and str(stored).strip() != "":
                                    cell_val = str(stored).strip()
                            rendered_lines.append(
                                f'<div class="docx-toc-line">'
                                f'<span class="toc-title">{_escape_html(cell_val)}</span>'
                                f'<input type="hidden" name="fields[{loc}]" class="docx-toc-title-input" value="{_escape_html(cell_val)}">'
                                f"</div>"
                            )
                        else:
                            rendered_lines.append(
                                f'<div class="docx-toc-line">{_escape_html(line)}</div>'
                            )
                cell_content = "".join(rendered_lines) if rendered_lines else _escape_html(text)
                html.append(
                    f'<td class="docx-cell" style="{style}"{rowspan_attr}{colspan_attr}>'
                    f"{cell_content}</td>"
                )
                continue

            field = _resolve_cell_field(
                t_idx, r_idx, col_idx, cell, cells, is_two_col, field_map
            )
            # Fill / pre-approval: keep HVAC + equipment columns editable until
            # post-approval. Readonly / post-lock modes honor field["locked"].
            if (
                field
                and not readonly
                and r_idx > 0
                and not _is_readonly_mode(mode)
                and mode in ("pre_approval", "edit")
            ):
                _hdr = (
                    col_headers[col_idx].strip().lower()
                    if col_idx < len(col_headers)
                    else ""
                )
                if any(
                    k in _hdr
                    for k in (
                        "acceptance",
                        "identification",
                        "methodology",
                        "checks",
                        "description",
                        "details",
                        "position of valve",
                        "observation",
                        "done by",
                        "remark",
                        "comment",
                        "deviation",
                    )
                ):
                    field = dict(field)
                    field["locked"] = False

            # Sl. No. / Sr. No.: editable during fill / pre-approval.
            word_has_serial = bool((text or "").strip() and _looks_like_sl_no_value(text))
            field_val = str((field or {}).get("value") or "").strip()
            if field_val and not _looks_like_sl_no_value(field_val):
                # Drop outline junk stored as value (e.g. "10.0").
                field_val = ""
            is_sl_no_col_cell = (
                r_idx > 0
                and sl_no_col is not None
                and col_idx == sl_no_col
            ) or (
                field is not None and str(field.get("type") or "") == "table_sl_no" and r_idx > 0
            )
            if is_sl_no_col_cell:
                loc = (
                    (field or {}).get("location")
                    or f"table_{t_idx}_row_{r_idx}_col_{col_idx}"
                )
                # Prefer clean integer serial text; ignore Word outline prefixes.
                cell_for_sl = text if _looks_like_sl_no_value(text) else ""
                html.append(
                    _render_sl_no_cell(
                        r_idx,
                        field,
                        cell_for_sl or field_val,
                        cell_style=cell_style,
                        rowspan_attr=rowspan_attr,
                        colspan_attr=colspan_attr,
                        location=loc,
                        readonly=readonly,
                    )
                )
                continue

            if field and str(field.get("type", "")).startswith("approval_stamp"):
                html.append(_render_approval_stamp_cell(field, mode, colspan_attr=colspan_attr))
                continue

            if field and field.get("type") == "approver":
                # _render_approver_cell decides select vs readonly (POST roles stay selectable).
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

            header_cells = table.rows[0].cells if table.rows else []
            header_texts = [c.text.strip().lower() for c in header_cells]
            this_header = header_texts[col_idx] if col_idx < len(header_texts) else ""
            if (
                not readonly
                and r_idx > 0
                and "deviation" in this_header
                and any("corrective" in h for h in header_texts)
            ):
                dev_loc = (
                    field["location"]
                    if (field and field.get("location"))
                    else f"table_{t_idx}_row_{r_idx}_col_{col_idx}"
                )
                dev_val = (field.get("value") if field else "") or text
                if str(dev_val).strip() in ("—", "-", "–"):
                    dev_val = ""
                widget_field = dict(field) if field else {
                    "location": dev_loc,
                    "label": "Deviation (if any)",
                    "type": "table_2col",
                }
                widget_field["widget"] = "textarea"
                widget_field["locked"] = False
                inp = _render_field_input(
                    dev_loc,
                    widget_field,
                    css_class="docx-input",
                    placeholder="Enter deviation...",
                    value=dev_val,
                )
                html.append(
                    f'<td class="docx-cell editable" style="{cell_style}"{rowspan_attr}{colspan_attr}>'
                    f"{inp}</td>"
                )
                continue

            if is_two_col and col_idx == 0:
                col0_loc = field["location"] if (field and "location" in field) else f"table_{t_idx}_row_{r_idx}_col_0"
                col0_val = (field.get("value") if field else "") or text
                if col0_val.strip() in ("—", "-", "–"):
                    col0_val = ""
                is_label_type = (field and field.get("type") == "table_2col_label") or (field is None)
                field_label = (field.get("label", "") if field else "") or row_label or text
                is_deviation = "deviation" in field_label.lower() or "deviation" in text.lower()
                header_left = table.rows[0].cells[0].text.strip().lower() if table.rows else ""
                header_right = table.rows[0].cells[1].text.strip().lower() if table.rows and len(table.rows[0].cells) > 1 else ""
                deviation_grid = "deviation" in header_left and "corrective" in header_right
                if deviation_grid and r_idx > 0 and mode == "pre_approval" and not readonly:
                    widget_field = dict(field) if field else {"location": col0_loc, "label": "Deviation (if any)", "type": "table_2col"}
                    widget_field["widget"] = "textarea"
                    widget_field["locked"] = False
                    inp = _render_field_input(
                        col0_loc,
                        widget_field,
                        css_class="docx-input",
                        placeholder="Enter deviation...",
                        value=col0_val,
                    )
                    html.append(
                        f'<td class="docx-cell editable" style="{cell_style}"{rowspan_attr}{colspan_attr}>'
                        f"{inp}</td>"
                    )
                    continue
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
                header_right = (
                    table.rows[0].cells[1].text.strip().lower()
                    if table.rows and len(table.rows[0].cells) > 1
                    else ""
                )
                # DESCRIPTION stays editable during fill; locked only in readonly.
                if readonly:
                    display = _render_readonly_span(col1_val, None, col1_loc)
                    html.append(
                        _readonly_td(
                            display,
                            cell_style=cell_style,
                            rowspan_attr=rowspan_attr,
                            colspan_attr=colspan_attr,
                            location=col1_loc,
                            extra_class="readonly",
                        )
                    )
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
                header_label_l = (
                    col_headers[col_idx].strip().lower()
                    if col_idx < len(col_headers)
                    else ""
                )
                is_spec_col = any(
                    k in header_label_l
                    for k in ("acceptance", "identification", "methodology", "checks")
                )
                # 8.1: Instrument ID + Checked By → textarea; Date/Due Date of Calibration → calendar.
                is_calibration_descriptive_col = any(
                    k in header_label_l
                    for k in (
                        "checked. by",
                        "checked by",
                        "instrument identification",
                    )
                )
                # Draft/import often stores Acceptance criteria as one collapsed line
                # (newlines + list numbers stripped). Prefer live DOCX multi-line text.
                if text and "\n" in text and "\n" not in str(value):
                    value = text
                elif text and "\n" in text and not str(value).strip():
                    value = text
                # Signature & Date: never fall back to DOCX junk like a lone "S".
                display_value = _clean_signature_cell_value(field, value, text)
                if readonly:
                    display = _render_readonly_span(display_value, field, field["location"])
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
                    # Spec columns with Word text were locked above; never force-unlock.
                    use_textarea = (
                        is_spec_col
                        or is_calibration_descriptive_col
                        or _is_textarea_cell(
                            field,
                            f"{row_label} {header_label_l}",
                            rowspan,
                            str(display_value or text),
                        )
                        or ("\n" in str(display_value or ""))
                        or len(str(display_value or text or "")) > 48
                    )
                    ph = ""
                    if is_two_col and col_idx == 1:
                        ph = "Enter details..."
                    elif is_two_col and col_idx == 0:
                        ph = "Parameter / Item..."
                    elif _is_signature_date_field(field):
                        ph = field.get("label") or "Signature & Date"
                    if use_textarea:
                        widget_field = dict(field)
                        widget_field["widget"] = "textarea"
                        inp = _render_field_input(
                            field["location"],
                            widget_field,
                            css_class="docx-input",
                            placeholder=ph,
                            value=display_value,
                        )
                    else:
                        inp = _render_field_input(
                            field["location"],
                            field,
                            css_class="docx-input",
                            placeholder=ph,
                            value=display_value,
                        )
                    html.append(
                        f'<td class="docx-cell editable" style="{cell_style}vertical-align:top;"{rowspan_attr}{colspan_attr}>'
                        f"{inp}</td>"
                    )
            elif field and _is_field_locked(field):
                style = _inline_cell_style(cell_style, readonly=readonly)
                locked_bg = f"background:#f5f5f5;" if readonly else ""
                raw_stored = (field.get("value") or "").strip()
                if display_text and "\n" in display_text and "\n" not in raw_stored:
                    locked_val = display_text
                else:
                    locked_val = display_text if (not raw_stored or raw_stored == text) else raw_stored
                locked_val = _clean_signature_cell_value(field, locked_val, "")
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
                    locked_html = "<br>".join(_escape_html(ln) for ln in locked_val.split("\n"))
                    html.append(
                        f'<td class="docx-cell locked" style="{style}{locked_bg}"{rowspan_attr}{colspan_attr}>'
                        f"{locked_html}</td>"
                    )
            elif not readonly and r_idx > 0 and not is_header:
                if mode != "pre_approval" and text.strip() != "":
                    style = _inline_cell_style(cell_style, readonly=readonly)
                    cell_html = "<br>".join(_escape_html(ln) for ln in display_text.split("\n"))
                    html.append(
                        f'<td class="docx-cell" style="{style}"{rowspan_attr}{colspan_attr}>'
                        f"{cell_html}</td>"
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
                cell_html = "<br>".join(_escape_html(ln) for ln in display_text.split("\n"))
                html.append(
                    f'<td class="docx-cell" style="{style}"{rowspan_attr}{colspan_attr}>'
                    f"{cell_html}</td>"
                )

        html.append("</tr>")

    html.append("</table>")
    return "\n".join(html)


def _element_has_page_break(element, *, hard_only: bool = False) -> bool:
    """Detect page breaks. hard_only ignores Word lastRenderedPageBreak soft markers."""
    try:
        from lxml import etree
        xml_b = etree.tostring(element)
        if b'type="page"' in xml_b or b"<w:pageBreakBefore" in xml_b:
            return True
        if hard_only:
            return False
        return b"lastRenderedPageBreak" in xml_b
    except Exception:
        return False


def _collect_live_letterhead_values(field_map: dict | None) -> dict[str, str]:
    """Build cover-key -> non-empty value map from live field_map for A4 letterheads."""
    values: dict[str, str] = {}
    if not field_map:
        return values
    for f in field_map.values():
        if not isinstance(f, dict):
            continue
        ck = str(f.get("cover_field") or "").strip()
        if not ck:
            matched = _match_cover_label_cell(str(f.get("label") or ""))
            if matched:
                ck = matched[0]
        val = str(f.get("value") or "").strip()
        if ck and val and ck not in values:
            values[ck] = val
    return values


def extract_header_and_footer_templates(
    doc,
    field_map: dict | None = None,
    mode: str = "edit",
) -> tuple[str, str]:
    """Extract official Header table and Footer from docx section to render on each A4 sheet."""
    header_html = ""
    # Only show annex footer when the template itself has one (HVAC original has none).
    footer_annex = ""
    live_cover_values = _collect_live_letterhead_values(field_map)
    # NEQ fill (mode=edit): empty / unlocked letterhead value cells become yellow inputs.
    # Requal modes keep display-only header cells (mapping panel still syncs via data-letterhead-field).
    letterhead_inputs = mode == "edit"

    if doc.sections:
        sec = doc.sections[0]
        # Footer text from template — skip invented ANNEX_xxx placeholders.
        for p in sec.footer.paragraphs:
            txt = p.text.strip()
            if not txt:
                continue
            if "ANNEX_xxx" in txt.lower() or "sop_xxxx" in txt.lower():
                continue
            if "ANNEX" in txt or "SOP" in txt or "CLASS" in txt:
                footer_annex = txt
                break

        header_tables = list(sec.header.tables or [])
        if not header_tables and getattr(sec, "first_page_header", None) is not None:
            header_tables = list(sec.first_page_header.tables or [])

        # Header table from template
        if header_tables:
            tbl = header_tables[0]
            rows_html = []
            for r_idx, row in enumerate(tbl.rows):
                seen_tc = set()
                cell_htmls = []
                prev_label_key = ""
                for c_idx, cell in enumerate(row.cells):
                    tc_id = id(cell._tc)
                    if tc_id in seen_tc:
                        continue
                    seen_tc.add(tc_id)

                    colspan = 1
                    for other in row.cells[c_idx + 1:]:
                        if id(other._tc) == tc_id:
                            colspan += 1
                        else:
                            break

                    rowspan = 1
                    for other_r in tbl.rows[r_idx + 1:]:
                        if c_idx < len(other_r.cells) and id(other_r.cells[c_idx]._tc) == tc_id:
                            rowspan += 1
                        else:
                            break

                    is_covered = False
                    for prev_r in tbl.rows[:r_idx]:
                        if c_idx < len(prev_r.cells) and id(prev_r.cells[c_idx]._tc) == tc_id:
                            is_covered = True
                            break
                    if is_covered:
                        continue

                    cs_attr = f' colspan="{colspan}"' if colspan > 1 else ""
                    rs_attr = f' rowspan="{rowspan}"' if rowspan > 1 else ""
                    txt = dedupe_colon_label(_cell_plain_text(cell))
                    own_match = _match_cover_label_cell(txt)
                    is_page_label = bool(re.search(r"^PAGE\s*NO\.?$", txt, re.IGNORECASE))
                    # Only real page text — never hardcode a cell as PAGE (that stole VERSION).
                    looks_like_page = bool(
                        re.search(r"(?:^|\b)page\s+\d+\s+of\s+\d+\b", txt, re.IGNORECASE)
                        or re.search(r"^\d+\s*of\s*\d+$", txt, re.IGNORECASE)
                        or txt.strip() == "24 of 24"
                    )
                    lh_attr = ""
                    style = "border: 1px solid #334155; padding: 3px 6px; vertical-align: middle; font-size: 11px; color: #0f172a; background: #ffffff;"
                    if r_idx == 0 and c_idx == 0:
                        style += " width: 95px; text-align: center; background: #ffffff;"
                        content = '<img src="/storage/lupin.png" alt="Lupin" style="max-height: 40px; width: auto;" onerror="this.outerHTML=\'<b style=\\\'font-size:13px;color:#047857;\\\'>LUPIN</b>\'">'
                        prev_label_key = ""
                    elif r_idx in (0, 1):
                        style += " text-align: center; font-weight: 700; background: #f8fafc; font-size: 11px;"
                        content = _escape_html(txt)
                        prev_label_key = ""
                    elif is_page_label:
                        style += " font-weight: 600; background: #f8fafc;"
                        content = "PAGE NO."
                        prev_label_key = "__page_no__"
                    elif own_match is not None:
                        style += " font-weight: 600; background: #f8fafc;"
                        content = _escape_html(txt)
                        prev_label_key = own_match[0]
                    elif prev_label_key == "__page_no__" or (
                        looks_like_page and prev_label_key == ""
                    ):
                        # PAGE NO. value cell (or bare "N of M" when not under VERSION/etc.).
                        style += " font-weight: 700; text-align: center; background: #ffffff;"
                        content = 'Page <span class="current-page-num">{PAGE}</span> of <span class="total-pages-count">{TOTAL}</span>'
                        prev_label_key = ""
                    elif prev_label_key:
                        loc_key = f"header_0_row_{r_idx}_col_{c_idx}"
                        loc_field = (field_map or {}).get(loc_key) if field_map else None
                        # Cover/protocol locations may also carry the live letterhead value.
                        cover_field = None
                        if field_map:
                            for cand in field_map.values():
                                if not isinstance(cand, dict):
                                    continue
                                cand_key = (
                                    str(cand.get("cover_field") or "").strip()
                                    or str(cand.get("cover_key") or "").strip()
                                    or str(cand.get("protocol_key") or "").strip()
                                )
                                if cand_key == prev_label_key:
                                    cover_field = cand
                                    break
                        # Word letterhead text is source of truth when present.
                        # Stored field wins only for explicit user edits or blank Word cells.
                        stored_val = ""
                        if isinstance(loc_field, dict):
                            stored_val = str(loc_field.get("value") or "").strip()
                        if not stored_val and isinstance(cover_field, dict):
                            stored_val = str(cover_field.get("value") or "").strip()
                        if not stored_val and prev_label_key in live_cover_values:
                            stored_val = live_cover_values[prev_label_key]
                        user_edited = bool(
                            isinstance(loc_field, dict) and loc_field.get("user_filled")
                        )
                        if txt and not user_edited:
                            live_val = txt
                        elif stored_val:
                            live_val = stored_val
                        else:
                            live_val = txt or ""
                        # Never show page chrome inside VERSION / other cover value cells.
                        if prev_label_key == "version" and re.search(
                            r"(?:page\s+)?\d+\s+of\s+\d+", live_val or "", re.I
                        ):
                            live_val = "" if looks_like_page else (txt or "")
                        lh_attr = f' data-letterhead-field="{_escape_html(prev_label_key)}"'
                        field_for_input = loc_field if isinstance(loc_field, dict) else cover_field
                        # Prefer stored/cover value when Word cell text is empty
                        # (e.g. FACILITY restored from parse but header run was blank).
                        if not (live_val or "").strip() and stored_val:
                            live_val = stored_val
                        # Prefer a real parsed location so fill() can write back to the DOCX header.
                        input_loc = loc_key
                        if isinstance(loc_field, dict) and str(loc_field.get("location") or "").strip():
                            input_loc = str(loc_field.get("location"))
                        elif isinstance(cover_field, dict) and str(cover_field.get("location") or "").strip():
                            input_loc = str(cover_field.get("location"))
                        # NEQ edit mode: ALL letterhead cover value cells stay yellow/fillable.
                        if letterhead_inputs:
                            style += " background: #fffde7;"
                            content = _render_field_input(
                                input_loc,
                                field_for_input
                                if isinstance(field_for_input, dict)
                                else {
                                    "location": input_loc,
                                    "label": prev_label_key,
                                    "type": "header_cell",
                                    "widget": "text",
                                    "locked": False,
                                },
                                css_class="docx-input",
                                placeholder=prev_label_key.replace("_", " ").title(),
                                value=live_val,
                            )
                        else:
                            content = _escape_html(live_val)
                        prev_label_key = ""
                    elif c_idx in (1, 3):
                        style += " font-weight: 600; background: #f8fafc;"
                        content = _escape_html(txt)
                        prev_label_key = ""
                    else:
                        content = _escape_html(txt)
                        prev_label_key = ""

                    cell_htmls.append(f'<td{rs_attr}{cs_attr}{lh_attr} style="{style}">{content}</td>')
                rows_html.append("<tr>" + "".join(cell_htmls) + "</tr>")

            header_html = (
                '<div class="docx-page-official-header">\n'
                '  <table class="docx-table header-table" style="width: 100%; border-collapse: collapse; margin-bottom: 0; table-layout: fixed;">\n'
                + "\n".join(rows_html) + "\n"
                "  </table>\n"
                "</div>"
            )

    if not header_html:
        header_html = (
            '<div class="docx-page-official-header">\n'
            '  <table class="docx-table header-table" style="width: 100%; border-collapse: collapse; margin-bottom: 0; table-layout: fixed;">\n'
            '    <tr>\n'
            '      <td style="width: 95px; text-align: center; vertical-align: middle; border: 1px solid #334155; padding: 4px; background: #ffffff;">\n'
            '        <img src="/storage/lupin.png" alt="Lupin" style="max-height: 38px; width: auto;" onerror="this.outerHTML=\'<b style=\\\'color:#047857;\\\'>LUPIN</b>\x27">\n'
            '      </td>\n'
            '      <td style="text-align: center; font-weight: 700; font-size: 12px; border: 1px solid #334155; padding: 4px; background: #f8fafc;">\n'
            '        LUPIN LIMITED\n'
            '        <div style="font-size: 10px; font-weight: 600; color: #475569; margin-top: 2px;">PERIODIC REQUALIFICATION PROTOCOL AND REPORT</div>\n'
            '      </td>\n'
            '      <td style="width: 130px; text-align: center; font-size: 10px; font-weight: 700; border: 1px solid #334155; padding: 4px; background: #f8fafc;">\n'
            '        PAGE NO.<br>\n'
            '        <span style="font-weight: 600;">Page <span class="current-page-num">{PAGE}</span> of <span class="total-pages-count">{TOTAL}</span></span>\n'
            '      </td>\n'
            '    </tr>\n'
            '  </table>\n'
            '</div>'
        )

    footer_html = (
        '<div class="docx-page-official-footer">\n'
        f'  <span class="docx-footer-annex">{_escape_html(footer_annex)}</span>\n'
        '  <span class="docx-footer-page">Page <span class="current-page-num">{PAGE}</span> of <span class="total-pages-count">{TOTAL}</span></span>\n'
        '</div>'
    )
    return header_html, footer_html


# ── build_fill_html() ───────────────────────────────
# Builds HTML preview of the DOCX body in document order (tables + paragraphs).
# Parameters: template_path — DOCX file path; fields — parsed/prefilled field list
# Returns: HTML string wrapped in <div class="docx-preview">
# Key steps:
#   1. Walks doc.element.body children in order (tbl / p tags)
#   2. Renders each table via _render_table() and each paragraph inline
#   3. Breaks document into realistic A4 page sheets (<div class="docx-a4-page">)
#   4. De-duplicates repeated <p> element references in the body walk
def build_fill_html(
    template_path: str,
    fields: list,
    mode: str = "edit",
    import_source: str | None = None,
) -> str:
    """
    Generate interactive HTML form from DOCX structure.

    Signature tables (Prepared By / Checked By / Approved By) render Name & Designation
    cells with class 'approver-field' — Laravel JS replaces options from the users table.
    Attachment columns render as file upload inputs with class 'attachment-field'.
    """
    global _NEQ_HTML_MODE
    from docx import Document

    doc = Document(template_path)
    if normalize_attachment_list_paragraphs_to_table(doc):
        try:
            doc.save(template_path)
        except Exception:
            pass
    field_map = {f["location"]: f for f in fields}
    paragraphs = doc.paragraphs
    # NEQ: ignore Word lastRenderedPageBreak soft markers (they create huge approval-page gaps).
    # Requal/reval keep the previous soft+hard break behavior.
    hard_page_breaks_only = (import_source or "").strip().lower() == "new_equipment_qualification"
    _NEQ_HTML_MODE = hard_page_breaks_only

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

    header_html_tpl, footer_html_tpl = extract_header_and_footer_templates(
        doc, field_map=field_map, mode=mode
    )
    num_resolver = _WordNumberingResolver(doc)
    preview_class = "docx-preview docx-preview-readonly" if _is_readonly_mode(mode) else "docx-preview"
    page_tracker = [1]
    h1 = header_html_tpl.replace("{PAGE}", "1")
    html_parts = [
        f'<div class="{preview_class}">',
        f'<div class="docx-a4-page" data-page-number="1" id="docxPage1">',
        f'{h1}',
        f'<div class="docx-page-body">'
    ]
    table_idx = 0
    has_content_on_page = False
    # Wrap 9.0 + 9.1 + Installation table so the client cannot park headings
    # alone under overflow:hidden on the previous sheet.
    keep_requal_91_open = False

    def _open_requal_91_keep(para_text: str, num_prefix: str = "") -> None:
        nonlocal keep_requal_91_open
        if keep_requal_91_open:
            return
        combined = f"{num_prefix} {para_text}".upper()
        if re.search(r"\b9\.0\s+REQUALIFICATION\s+CHECKS\b", combined) or (
            re.search(r"\bREQUALIFICATION\s+CHECKS\b", combined)
            and re.match(r"^9\.0\b", (num_prefix or "").strip())
        ):
            html_parts.append(
                '<div class="docx-keep-section" data-keep-section="requal-91">'
            )
            keep_requal_91_open = True

    def _close_requal_91_keep_after_table(t_idx: int) -> None:
        nonlocal keep_requal_91_open
        if keep_requal_91_open and t_idx == 12:
            html_parts.append("</div>")
            keep_requal_91_open = False

    def _force_new_page() -> None:
        nonlocal has_content_on_page
        page_tracker[0] += 1
        next_p = page_tracker[0]
        prev_f = footer_html_tpl.replace("{PAGE}", str(next_p - 1))
        html_parts.append(f"</div>\n{prev_f}\n</div>")
        html_parts.append(
            f'<div class="docx-page-break-indicator no-print" data-next-page="{next_p}">\n'
            f'  <div class="docx-page-break-line"></div>\n'
            f'  <div class="docx-page-break-badge"><i class="fas fa-file-alt mr-1"></i> Page {next_p}</div>\n'
            f'  <div class="docx-page-break-line"></div>\n'
            f'</div>\n'
        )
        next_h = header_html_tpl.replace("{PAGE}", str(next_p))
        html_parts.append(
            f'<div class="docx-a4-page" data-page-number="{next_p}" id="docxPage{next_p}">\n'
            f'{next_h}\n'
            f'<div class="docx-page-body">'
        )
        has_content_on_page = False

    def _is_major_tail_section(para_text: str, num_prefix: str = "") -> bool:
        """
        Break before major mid/tail anchors so packed sheets do not clip
        8.2–8.4 / REFERENCES / History / Summary under overflow:hidden.
        Extra pages are OK; hidden content is not.
        """
        combined = f"{num_prefix} {para_text}".upper()
        return bool(
            re.search(
                r"\b("
                r"PREVENTIVE\s+MAINTENANCE\s+DETAILS|"
                r"ANY\s+OTHER\s+SPECIFIC\s+TESTS|"
                r"VERIFICATION\s+WITH\s+THE\s+CURRENT\s+P\s*&\s*ID|"
                r"HISTORY\s+OF\s+EQUIPMENT|"
                r"REVIEW\s+OF\s+CHANGE\s+CONTROLS|"
                r"LIST\s+OF\s+ATTACHMENT|"
                r"DEVIATIONS?\s*\(IF ANY\)|SUMMARY\s*&\s*CONCLUSION|"
                r"REFERENCES|ABBREVIATIONS|REVISION\s+HISTORY|POST-APPROVAL"
                r")\b",
                combined,
            )
        )

    def _is_requal_checks_heading(para_text: str, num_prefix: str = "") -> bool:
        """
        Start 9.0 / 9.2 / 9.3 on clean sheets so Installation, General Checks,
        and Functional Tests intro never sit under a clipped previous table.
        """
        combined = f"{num_prefix} {para_text}".upper()
        return bool(
            re.search(
                r"\b(9\.0\s+REQUALIFICATION\s+CHECKS|"
                r"9\.2\s+GENERAL\s+CHECKS|"
                r"GENERAL\s+CHECKS\s*:\s*VERIFICATION\s+OF\s+MAJOR\s+COMPONENTS|"
                r"9\.3\s+OPERATIONAL\s+QUALIFICATION|"
                r"OPERATIONAL\s+QUALIFICATION\s+CHECKS\s*:\s*FUNCTIONAL)\b",
                combined,
            )
        )

    # Body TOC1 paragraphs (IOQ PR-WBL-046): group into one dotted TOC card.
    toc_card_open = False
    toc_body_idx = 0

    def _close_body_toc_card(*, force_page: bool = False) -> None:
        nonlocal toc_card_open
        if not toc_card_open:
            return
        html_parts.append("</div>")
        toc_card_open = False
        if force_page and not _is_readonly_mode(mode):
            _force_new_page()

    def _open_body_toc_card() -> None:
        nonlocal toc_card_open
        if toc_card_open:
            return
        html_parts.append('<div class="docx-toc-card">')
        if _is_readonly_mode(mode):
            html_parts.append('<div class="docx-toc-heading">TABLE OF CONTENTS</div>')
        else:
            html_parts.append(
                '<div class="docx-toc-heading" style="display: flex; justify-content: space-between; '
                'align-items: center; margin-bottom: 12px;">'
                '<span style="font-weight: 700; font-size: 14px; color: #334155; text-transform: uppercase; '
                'letter-spacing: 0.5px;">TABLE OF CONTENTS</span>'
                '<button type="button" class="btn btn-sm btn-outline-primary btn-sync-toc" id="btnAutoSyncToc" '
                'style="font-size: 11px; padding: 2px 10px; border-radius: 12px; cursor: pointer; '
                'background: #fff; border: 1px solid #007bff; color: #007bff;" '
                'title="Auto-calculate page numbers based on document content length">'
                '<i class="fas fa-sync-alt mr-1"></i> Auto-Calculate Page Numbers'
                "</button>"
                "</div>"
            )
        toc_card_open = True

    def _emit_body_toc_line(p_el, para_text: str = "") -> bool:
        """Render one Word TOC1 body line with dotted leaders. Returns True if emitted."""
        nonlocal toc_body_idx
        title, page = _parse_toc_entry_element(p_el)
        if not title or not page:
            title2, page2 = _parse_toc_line_title_page(para_text or "")
            if title2 and page2:
                title, page = title2, page2
        if not title:
            return False
        _open_body_toc_card()
        loc = f"toc_sdt_{toc_body_idx}"
        title_loc = f"{loc}_title"
        page_val = page or ""
        title_val = title
        if field_map and loc in field_map:
            stored = field_map[loc].get("value")
            if stored is not None and str(stored).strip() != "":
                page_val = str(stored).strip()
        if field_map and title_loc in field_map:
            stored_title = field_map[title_loc].get("value")
            if stored_title is not None and str(stored_title).strip() != "":
                title_val = str(stored_title).strip()
        html_parts.append(
            _render_toc_line_html(
                title_val,
                page_val,
                loc,
                title_loc,
                _is_readonly_mode(mode),
            )
        )
        toc_body_idx += 1
        return True

    # Walk doc.element.body in document order — render tables and paragraphs as they appear.
    for child in doc.element.body:
        tag = child.tag.split("}")[-1] if "}" in child.tag else child.tag

        if tag == "tbl":
            _close_body_toc_card(force_page=True)
            if table_idx < len(doc.tables):
                html_parts.append(
                    _render_table(
                        doc.tables[table_idx],
                        table_idx,
                        field_map,
                        mode,
                        page_tracker=page_tracker,
                        header_tpl=header_html_tpl,
                        footer_tpl=footer_html_tpl,
                        num_resolver=num_resolver,
                        hard_page_breaks_only=hard_page_breaks_only,
                    )
                )
                _close_requal_91_keep_after_table(table_idx)
                has_content_on_page = True
                # End Functional Tests on its own sheet before 10.0 Deviations.
                if (
                    not _is_readonly_mode(mode)
                    and len(doc.tables[table_idx].rows) >= 20
                ):
                    _force_new_page()
            table_idx += 1

        elif tag == "p":
            para_index = _para_index_for_element(child, paragraphs)
            if para_index is None:
                para_text = _para_text_from_element(child)
                if para_text and _is_toc_title_text(para_text):
                    continue
                if _is_word_toc_body_line(child, para_text or ""):
                    if _emit_body_toc_line(child, para_text or ""):
                        has_content_on_page = True
                        continue
                _close_body_toc_card(force_page=True)
                if para_text:
                    num_prefix, is_bullet = num_resolver.prefix_for_p(child)
                    if has_content_on_page and (
                        _element_has_page_break(child, hard_only=hard_page_breaks_only)
                        or _is_major_tail_section(para_text, num_prefix)
                        or _is_requal_checks_heading(para_text, num_prefix)
                    ):
                        _force_new_page()
                    _open_requal_91_keep(para_text, num_prefix)
                    heading_html = _render_heading_paragraph(para_text, num_prefix=num_prefix, is_bullet=is_bullet)
                    if heading_html:
                        html_parts.append(heading_html)
                    else:
                        clean_p = dedupe_colon_label(para_text).strip()
                        if _is_toc_title_text(clean_p):
                            continue
                        eff_pref = _strip_duplicate_prefix(clean_p, num_prefix, is_bullet)
                        pref_span = _num_prefix_span(eff_pref, is_bullet)
                        html_parts.append(f'<p class="docx-para">{pref_span}{_escape_html(clean_p)}</p>')
                    if _append_verification_pid_notes(html_parts, None, para_text, field_map, mode):
                        has_content_on_page = True
                    has_content_on_page = True
                continue

            if para_index in seen_para_indices:
                continue
            seen_para_indices.add(para_index)

            _peek_text = para_text_by_index.get(
                para_index, _para_text_from_element(child, exclude_textboxes=True)
            )
            if _peek_text and _is_toc_title_text(_peek_text):
                continue
            if _is_word_toc_body_line(child, _peek_text or ""):
                # Still advance list counters for ghost numPr on TOC rows.
                num_resolver.prefix_for_p(child)
                if _emit_body_toc_line(child, _peek_text or ""):
                    has_content_on_page = True
                    continue
            _close_body_toc_card(force_page=True)
            # Advance/cache numbering once so major-tail detection sees "10.0 DEVIATIONS".
            _peek_prefix, _ = num_resolver.prefix_for_p(child)
            if has_content_on_page and (
                _element_has_page_break(child, hard_only=hard_page_breaks_only)
                or _is_major_tail_section(_peek_text, _peek_prefix)
                or _is_requal_checks_heading(_peek_text, _peek_prefix)
            ):
                _force_new_page()
            _open_requal_91_keep(_peek_text, _peek_prefix)

            if para_index in textbox_by_parent_para:
                heading = (
                    paragraphs[para_index].text.strip()
                    or para_text_by_index.get(para_index, "")
                )
                # Always advance list counters (empty numPr paras still consume a number).
                num_prefix, is_bullet = num_resolver.prefix_for_p(child)
                txbx_index, txbx_element = textbox_by_parent_para[para_index]
                if heading and has_paragraph_blank(heading):
                    field = field_map.get(f"para_{para_index}")
                    rendered = _render_paragraph(
                        para_index, heading, field, field_map, mode, num_prefix=num_prefix, is_bullet=is_bullet
                    )
                    if rendered:
                        html_parts.append(rendered)
                        has_content_on_page = True
                elif heading:
                    from doc_parser import _para_text_from_element as _parser_para_text

                    W_NS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
                    inner_paras = txbx_element.findall(f"{{{W_NS}}}p")
                    inner_heading = (
                        _parser_para_text(inner_paras[0]).strip() if inner_paras else ""
                    )
                    if dedupe_colon_label(heading) != dedupe_colon_label(inner_heading):
                        heading_html = _render_heading_paragraph(heading, num_prefix=num_prefix, is_bullet=is_bullet)
                        if heading_html:
                            html_parts.append(heading_html)
                        else:
                            clean_h = dedupe_colon_label(heading)
                            eff_pref = _strip_duplicate_prefix(clean_h, num_prefix, is_bullet)
                            pref_span = _num_prefix_span(eff_pref, is_bullet)
                            html_parts.append(
                                f'<p class="docx-para">{pref_span}{_escape_html(clean_h)}</p>'
                            )
                        has_content_on_page = True
                html_parts.append(
                    _render_textbox_html(txbx_index, txbx_element, field_map, mode)
                )
                has_content_on_page = True
                continue

            para_text = para_text_by_index.get(
                para_index, _para_text_from_element(child, exclude_textboxes=True)
            )
            # Always advance list counters even when run text is empty (ghost numPr rows).
            num_prefix, is_bullet = num_resolver.prefix_for_p(child)
            field = field_map.get(f"para_{para_index}")
            if field is None:
                blank_key = f"para_{para_index}_blank_0"
                field = field_map.get(blank_key)
            rendered = _render_paragraph(
                para_index, para_text, field, field_map, mode, num_prefix=num_prefix, is_bullet=is_bullet
            )
            if rendered:
                html_parts.append(rendered)
                has_content_on_page = True
            if para_text and _append_verification_pid_notes(
                html_parts, para_index, para_text, field_map, mode
            ):
                has_content_on_page = True

        elif tag == "sdt":
            _close_body_toc_card(force_page=True)
            if _element_has_page_break(child, hard_only=hard_page_breaks_only) and has_content_on_page:
                page_tracker[0] += 1
                next_p = page_tracker[0]
                prev_f = footer_html_tpl.replace("{PAGE}", str(next_p - 1))
                html_parts.append(f"</div>\n{prev_f}\n</div>")
                html_parts.append(
                    f'<div class="docx-page-break-indicator no-print" data-next-page="{next_p}">\n'
                    f'  <div class="docx-page-break-line"></div>\n'
                    f'  <div class="docx-page-break-badge"><i class="fas fa-file-alt mr-1"></i> Page {next_p}</div>\n'
                    f'  <div class="docx-page-break-line"></div>\n'
                    f'</div>\n'
                )
                next_h = header_html_tpl.replace("{PAGE}", str(next_p))
                html_parts.append(
                    f'<div class="docx-a4-page" data-page-number="{next_p}" id="docxPage{next_p}">\n'
                    f'{next_h}\n'
                    f'<div class="docx-page-body">'
                )

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
                    html_parts.append('<div class="docx-toc-card">')
                    # Title stays inside the TOC card (Word: TOC page only — never
                    # above 1.0 OBJECTIVE on the following sheet).
                    if _is_readonly_mode(mode):
                        html_parts.append(
                            '<div class="docx-toc-heading">TABLE OF CONTENTS</div>'
                        )
                    else:
                        html_parts.append(
                            '<div class="docx-toc-heading" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">'
                            '<span style="font-weight: 700; font-size: 14px; color: #334155; text-transform: uppercase; letter-spacing: 0.5px;">TABLE OF CONTENTS</span>'
                            '<button type="button" class="btn btn-sm btn-outline-primary btn-sync-toc" id="btnAutoSyncToc" style="font-size: 11px; padding: 2px 10px; border-radius: 12px; cursor: pointer; background: #fff; border: 1px solid #007bff; color: #007bff;" title="Auto-calculate page numbers based on document content length">'
                            '<i class="fas fa-sync-alt mr-1"></i> Auto-Calculate Page Numbers'
                            '</button>'
                            '</div>'
                        )
                    for toc_idx, (title, page) in enumerate(toc_entries):
                        loc = f"toc_sdt_{toc_idx}"
                        title_loc = f"toc_sdt_{toc_idx}_title"
                        val = page or ""
                        title_val = title
                        if field_map and loc in field_map:
                            stored = field_map[loc].get("value")
                            if stored is not None and str(stored).strip() != "":
                                val = str(stored).strip()
                        if field_map and title_loc in field_map:
                            stored_title = field_map[title_loc].get("value")
                            if stored_title is not None and str(stored_title).strip() != "":
                                title_val = str(stored_title).strip()
                        if page or val:
                            html_parts.append(
                                _render_toc_line_html(
                                    title_val,
                                    val,
                                    loc,
                                    title_loc,
                                    _is_readonly_mode(mode),
                                )
                            )
                        else:
                            html_parts.append(
                                f'<div class="docx-toc-line">'
                                f'<span class="toc-title">{_escape_html(title_val)}</span>'
                                f'</div>'
                            )
                    html_parts.append('</div>')
                    has_content_on_page = True
                    # Match Word: body (1.0 OBJECTIVE) starts on the next sheet.
                    if not _is_readonly_mode(mode):
                        _force_new_page()

    _close_body_toc_card()
    last_f = footer_html_tpl.replace("{PAGE}", str(page_tracker[0]))
    html_parts.append(f"</div>\n{last_f}\n</div>")  # close .docx-page-body and .docx-a4-page
    html_parts.append("</div>")  # close .docx-preview
    full_html = "\n".join(html_parts)
    full_html = full_html.replace("{TOTAL}", str(page_tracker[0]))
    _NEQ_HTML_MODE = False
    return full_html


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
