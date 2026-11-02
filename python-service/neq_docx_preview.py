"""
Image placement extraction for New Equipment Qualification preview only.

Resolves header/body embedded images through OOXML relationship chains and
applies Word crop rectangles (a:srcRect) so sprite-sheet PNGs display the
correct logo region.
"""

from __future__ import annotations

import base64
import io
import re
import zipfile
from typing import Any, Dict, List, Optional, Tuple

from lxml import etree
from PIL import Image

# WordprocessingML namespace for document/header/footer XML parts.
WNS = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
# Relationship namespace — links embed IDs to media files inside the DOCX zip.
RNS = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
# DrawingML namespace — holds blip fills and srcRect crop attributes.
ANS = "http://schemas.openxmlformats.org/drawingml/2006/main"

# Map file extensions to MIME types for data-URL construction.
MIME_BY_EXT = {
    "png": "image/png",
    "jpg": "image/jpeg",
    "jpeg": "image/jpeg",
    "gif": "image/gif",
    "bmp": "image/bmp",
    "webp": "image/webp",
    "svg": "image/svg+xml",
}


# Guess image MIME type from the media path extension; default to PNG.
def _mime_for_path(path: str) -> str:
    ext = path.rsplit(".", 1)[-1].lower()
    return MIME_BY_EXT.get(ext, "image/png")


# Parse word/_rels/{part}.rels and return embed-ID → word/media/... path map.
def _load_relationships(zipf: zipfile.ZipFile, part_name: str) -> Dict[str, str]:
    rels_path = f"word/_rels/{part_name}.rels"
    relationships: Dict[str, str] = {}

    try:
        root = etree.fromstring(zipf.read(rels_path))
    except KeyError:
        return relationships

    for rel in root:
        rel_type = rel.get("Type") or ""
        # Only keep image relationships; skip hyperlinks and other part types.
        if "image" not in rel_type:
            continue

        rel_id = rel.get("Id")
        target = (rel.get("Target") or "").lstrip("./").replace("../", "")
        if not rel_id or not target:
            continue

        # Normalize target path to an absolute path inside the DOCX archive.
        if target.startswith("media/"):
            relationships[rel_id] = f"word/{target}"
        else:
            relationships[rel_id] = f"word/media/{target.split('/')[-1]}"

    return relationships


# Convert Word a:srcRect crop percentages (l/t/r/b) to integer edge offsets.
def _parse_src_rect(element) -> Optional[Dict[str, int]]:
    if element is None:
        return None

    values = {}
    for edge in ("l", "t", "r", "b"):
        raw = element.get(edge)
        values[edge] = int(raw) if raw is not None else 0

    # Zero crop on all sides means no cropping — return None to skip PIL step.
    if all(value == 0 for value in values.values()):
        return None

    return values


# Walk all embedded blips in a part XML and map each r:embed ID to its crop rect.
def _find_embed_crops(part_xml: bytes) -> Dict[str, Optional[Dict[str, int]]]:
    root = etree.fromstring(part_xml)
    crops: Dict[str, Optional[Dict[str, int]]] = {}

    for blip in root.xpath(".//a:blip[@r:embed]", namespaces={"a": ANS, "r": RNS}):
        embed_id = blip.get(f"{{{RNS}}}embed")
        if not embed_id:
            continue

        blip_fill = blip.getparent()
        src_rect_el = None
        if blip_fill is not None:
            src_rect_el = blip_fill.find(f"{{{ANS}}}srcRect")

        crops[embed_id] = _parse_src_rect(src_rect_el)

    return crops


# Apply Word crop rectangle to raw image bytes; return original bytes if crop is invalid.
def _crop_image_bytes(raw_bytes: bytes, src_rect: Optional[Dict[str, int]]) -> bytes:
    if not src_rect:
        return raw_bytes

    image = Image.open(io.BytesIO(raw_bytes))
    width, height = image.size

    # Word stores crop margins as parts per 100000 of image width/height.
    left = int(width * src_rect["l"] / 100000)
    top = int(height * src_rect["t"] / 100000)
    right = int(width * (1 - src_rect["r"] / 100000))
    bottom = int(height * (1 - src_rect["b"] / 100000))

    if right <= left or bottom <= top:
        return raw_bytes

    cropped = image.crop((left, top, right, bottom))

    output = io.BytesIO()
    cropped.save(output, format=image.format or "PNG")
    return output.getvalue()


# Build a base64 data URL from cropped image bytes and the source media path.
def _build_data_url(raw_bytes: bytes, media_path: str, src_rect: Optional[Dict[str, int]] = None) -> str:
    processed = _crop_image_bytes(raw_bytes, src_rect)
    encoded = base64.b64encode(processed).decode("ascii")
    return f"data:{_mime_for_path(media_path)};base64,{encoded}"


# Main entry: scan document/header/footer parts and return image placement records for NEQ preview.
def extract_image_placements(docx_bytes: bytes) -> Tuple[List[Dict[str, Any]], List[str]]:
    placements: List[Dict[str, Any]] = []
    messages: List[str] = []

    with zipfile.ZipFile(io.BytesIO(docx_bytes)) as zipf:
        # Only scan body and header/footer XML parts that can contain embedded images.
        xml_parts = sorted(
            name
            for name in zipf.namelist()
            if re.match(r"word/(document|header\d+|footer\d+)\.xml$", name)
        )

        for part_path in xml_parts:
            part_name = part_path.replace("word/", "")
            part_xml = zipf.read(part_path)
            rels = _load_relationships(zipf, part_name)
            embed_crops = _find_embed_crops(part_xml)

            for embed_id, media_path in rels.items():
                # Skip embed IDs that appear in rels but have no blip in this part XML.
                if embed_id not in embed_crops:
                    continue

                try:
                    raw_bytes = zipf.read(media_path)
                except KeyError:
                    messages.append(f"Missing media file for {part_name} {embed_id}: {media_path}")
                    continue

                data_url = _build_data_url(raw_bytes, media_path, embed_crops.get(embed_id))
                placements.append(
                    {
                        "part": part_name,
                        "embed_id": embed_id,
                        "media_path": media_path,
                        "media_filename": media_path.split("/")[-1],
                        "data_url": data_url,
                        "has_crop": embed_crops.get(embed_id) is not None,
                        # Header images are tagged for logo-cell placement; body images are generic.
                        "target": "header_logo_cell" if part_name.startswith("header") else "body",
                    }
                )
                messages.append(
                    f"Resolved {part_name} {embed_id} -> {media_path}"
                    + (" (cropped)" if embed_crops.get(embed_id) else "")
                )

    return placements, messages
