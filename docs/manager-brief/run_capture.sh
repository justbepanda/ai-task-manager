#!/usr/bin/env bash
set -euo pipefail
ROOT="/home/mof/ai-tasks-manager"
OUT="${ROOT}/docs/manager-brief/screenshots"
SCRIPT="${ROOT}/docs/manager-brief/capture_screenshots.js"
NM="${ROOT}/docs/manager-brief/.playwright-npm"
NETWORK="${1:-ai-tasks-manager-network}"
BASE="${2:-http://web}"

mkdir -p "$OUT" "$NM"

if [[ ! -d "${NM}/node_modules/playwright" ]]; then
  echo "Installing playwright@1.48.0 into ${NM} (one-time)..."
  docker run --rm -v "${NM}:/work" mcr.microsoft.com/playwright:v1.48.0-jammy \
    bash -lc 'cd /work && npm init -y >/dev/null 2>&1 && npm i playwright@1.48.0 --omit=dev --no-audit --no-fund'
fi

docker run --rm \
  --network "$NETWORK" \
  -v "${OUT}:/out" \
  -v "${SCRIPT}:/tmp/capture.js:ro" \
  -v "${ROOT}/docs/manager-brief/ids.json:/ids.json:ro" \
  -v "${NM}/node_modules:/node_modules:ro" \
  -e "BASE_URL=${BASE}" \
  -e OUT_DIR=/out \
  -e CAPTURE_MODE="${CAPTURE_MODE:-both}" \
  -e NODE_PATH=/node_modules \
  -e PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
  mcr.microsoft.com/playwright:v1.48.0-jammy \
  node /tmp/capture.js

echo "--- PNG verify ---"
python3 - <<PY
from PIL import Image
from pathlib import Path
import json
out = Path("${OUT}")
manifest = json.loads((out / "pages.json").read_text(encoding="utf-8"))
for entry in manifest:
    prefix = entry["prefix"]
    parts = sorted(
        [p for p in out.glob(f"{prefix}-*.png") if p.stem.split("-")[-1].isdigit()],
        key=lambda p: int(p.stem.split("-")[-1]),
    )
    if not parts:
        raise SystemExit(f"missing shots for {prefix}")
    expected = "single→1" if entry["mode"] == "single" else "blocks→1+"
    print(f"{prefix} mode={entry['mode']} shots={len(parts)} ({expected})")
    if entry["mode"] == "single" and len(parts) != 1:
        raise SystemExit(f"{prefix} should be single shot, got {len(parts)}")
    for p in parts:
        im = Image.open(p)
        w, h = im.size
        print(f"  {p.name}: {w}x{h}")
        if w < 1400:
            raise SystemExit(f"{p.name} width {w} < 1400")
print(f"pages in manifest: {len(manifest)}")
PY

python3 "${ROOT}/docs/manager-brief/build_test_pdf.py"
echo "PDF: ${ROOT}/docs/manager-brief/AI-PM-MVP-test-brief.pdf"
