"""CLI runner: print NEQ body text targets as JSON on stdout."""

from __future__ import annotations

import json
import sys

from neq_docx_save import serialize_text_targets


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: run_neq_targets.py <source.docx>", file=sys.stderr)
        return 1

    source_path = sys.argv[1]
    with open(source_path, "rb") as handle:
        docx_bytes = handle.read()

    targets = serialize_text_targets(docx_bytes)
    print(json.dumps({"targets": targets, "count": len(targets)}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
