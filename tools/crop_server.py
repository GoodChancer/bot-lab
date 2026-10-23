"""Tiny local server for the icon crop tool.

Run:  python3 tools/crop_server.py   then open http://localhost:8765
Saves cropped icons into art/icons/.
"""
import base64
import json
import re
from http.server import HTTPServer, SimpleHTTPRequestHandler
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
ICONS = ROOT / "art" / "icons"
PORT = 8765


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(ROOT), **kwargs)

    def do_GET(self):
        if self.path in ("/", "/index.html"):
            self.path = "/tools/crop.html"
        return super().do_GET()

    def do_POST(self):
        if self.path not in ("/save", "/colors"):
            return self.send_error(404)
        body = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        name = body.get("name", "")
        if not re.fullmatch(r"[a-z0-9-]+-icon\.png", name):
            return self.send_error(400, "bad file name")
        ICONS.mkdir(parents=True, exist_ok=True)

        if self.path == "/save":
            data = body.get("png", "").split(",", 1)[-1]
            (ICONS / name).write_bytes(base64.b64decode(data))
            saved = f"art/icons/{name}"
        else:
            color = body.get("color", "")
            if not re.fullmatch(r"#[0-9a-f]{6}", color):
                return self.send_error(400, "bad color")
            path = ICONS / "colors.json"
            colors = json.loads(path.read_text()) if path.exists() else {}
            colors[name] = color
            path.write_text(json.dumps(colors, indent=2) + "\n")
            saved = "art/icons/colors.json"

        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(json.dumps({"saved": saved}).encode())


if __name__ == "__main__":
    print(f"Crop tool: http://localhost:{PORT}")
    HTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
