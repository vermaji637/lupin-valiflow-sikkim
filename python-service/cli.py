import sys
import os
import json

# Ensure current directory is on python path
cur_dir = os.path.dirname(os.path.abspath(__file__))
if cur_dir not in sys.path:
    sys.path.insert(0, cur_dir)

import doc_parser
import app

def main():
    if len(sys.argv) < 2:
        print(json.dumps({"status": "error", "message": "Usage: cli.py <parse|preview> [args]"}))
        sys.exit(1)

    cmd = sys.argv[1].lower()

    if cmd == "parse":
        if len(sys.argv) < 3:
            print(json.dumps({"status": "error", "message": "Missing docx_path argument"}))
            sys.exit(1)
        docx_path = sys.argv[2]
        if not os.path.isfile(docx_path):
            print(json.dumps({"status": "error", "message": f"File not found: {docx_path}"}))
            sys.exit(1)
        res = doc_parser.parse_docx(docx_path)
        print(json.dumps({
            "status": "success",
            "fields": res.get("fillable_fields", []),
            "paragraphs": res.get("paragraphs", []),
            "tables": len(res.get("tables", [])),
        }))

    elif cmd == "preview":
        if len(sys.argv) < 3:
            print(json.dumps({"status": "error", "message": "Missing docx_path argument"}))
            sys.exit(1)
        docx_path = sys.argv[2]
        fields_path = sys.argv[3] if len(sys.argv) > 3 else None
        mode = sys.argv[4] if len(sys.argv) > 4 else "edit"

        fields = []
        if fields_path and os.path.isfile(fields_path):
            with open(fields_path, "r", encoding="utf-8") as f:
                fields = json.load(f)

        html = app.build_fill_html(docx_path, fields, mode=mode)
        # Output directly to stdout
        sys.stdout.buffer.write(html.encode("utf-8"))

    elif cmd == "fill":
        if len(sys.argv) < 5:
            print(json.dumps({"status": "error", "message": "Usage: cli.py fill <template_path> <fields_json_path> <output_path>"}))
            sys.exit(1)
        template_path = sys.argv[2]
        fields_path = sys.argv[3]
        output_path = sys.argv[4]
        attachment_links_path = sys.argv[5] if len(sys.argv) > 5 else None
        equipment_id = sys.argv[6] if len(sys.argv) > 6 else ""
        table_ops_path = sys.argv[7] if len(sys.argv) > 7 else None

        fields = []
        if fields_path and os.path.isfile(fields_path):
            with open(fields_path, "r", encoding="utf-8") as f:
                fields = json.load(f)

        att_links = {}
        if attachment_links_path and os.path.isfile(attachment_links_path):
            with open(attachment_links_path, "r", encoding="utf-8") as f:
                att_links = json.load(f)

        table_ops = []
        if table_ops_path and os.path.isfile(table_ops_path):
            with open(table_ops_path, "r", encoding="utf-8") as f:
                table_ops = json.load(f)

        from report_generator import fill_docx
        os.makedirs(os.path.dirname(os.path.abspath(output_path)), exist_ok=True)
        result_path, repaired_values = fill_docx(
            template_path,
            fields,
            output_path,
            att_links,
            equipment_id,
            table_ops,
        )
        print(json.dumps({
            "status": "success",
            "output_path": result_path,
            "output_filename": os.path.basename(output_path),
            "repaired_field_values": repaired_values,
        }))

    else:
        print(json.dumps({"status": "error", "message": f"Unknown command: {cmd}"}))
        sys.exit(1)

if __name__ == "__main__":
    main()

