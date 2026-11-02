#!/usr/bin/env python3
"""CLI runner: apply NEQ indexed edits and write DOCX to stdout."""

from __future__ import annotations

import json
import sys

from neq_docx_save import apply_indexed_edits_to_docx


def main() -> int:
    if len(sys.argv) != 3:
        print("Usage: run_neq_save.py <source.docx> <edits.json>", file=sys.stderr)
        return 1

    source_path = sys.argv[1]
    edits_path = sys.argv[2]

    with open(source_path, "rb") as handle:
        docx_bytes = handle.read()

    with open(edits_path, "r", encoding="utf-8") as handle:
        payload = json.load(handle)

    if isinstance(payload, list):
        edits = payload
        table_ops = []
        cell_edits = []
        checkbox_ops = []
        structure_ops = []
        toc_lines = []
    elif isinstance(payload, dict):
        edits = payload.get("edits") or []
        table_ops = payload.get("table_ops") or []
        cell_edits = payload.get("cell_edits") or []
        checkbox_ops = payload.get("checkbox_ops") or []
        structure_ops = payload.get("structure_ops") or []
        toc_lines = payload.get("toc_lines") or []
    else:
        print("Edits JSON must be a list or object.", file=sys.stderr)
        return 1

    output_bytes, stats = apply_indexed_edits_to_docx(
        docx_bytes, edits, table_ops, cell_edits, checkbox_ops, structure_ops, toc_lines
    )
    sys.stderr.write(json.dumps({"status": "success", "stats": stats}) + "\n")
    sys.stdout.buffer.write(output_bytes)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
