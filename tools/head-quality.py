#!/usr/bin/env python3
"""
Measure how much of the cached book text is actually readable.

This exists because Archive.org's OCR quality is bimodal, not a spectrum. Some
Punjabi items come through clean and readable; others - mostly older lithographs
scanned from microfilm - are character soup that would be worse than no text at
all on a book page. So every head has to be scored before it is used, and the
score has to be measured across the catalogue rather than assumed.

Score = share of non-whitespace characters that are real letters (Gurmukhi,
Devanagari or Latin) rather than digits, punctuation and OCR debris. Titles,
publishers and opening lines are then only lifted from heads that pass.
"""
import json
import os
import re
import sys
import unicodedata
from pathlib import Path

OUT = Path(os.environ.get("TEXT_OUT", "/run/media/jatt/9890EF3990EF1C8E/bookstore-text"))

# A real word is a run of letters, allowing apostrophes and the Gurmukhi
# addak (਼ ਼ਾ ਸ਼੍ਰੀ and friends) that OCR often detaches.
WORD = re.compile(r"[\u0A00-\u0A7F\u0900-\u097F][\u0A00-\u0A7F\u0900-\u097F\u0A3E\u0A4B\u0A3F\u0A70\u0A4D']{1,}|\b[A-Za-z][A-Za-z'-]{1,}\b")
DIGIT_RUN = re.compile(r"[0-9]{2,}[^\w\s]{0,2}")
GURMUKHI = re.compile(r"[\u0A00-\u0A7F]")

# Lines worth keeping: mostly letters, not mostly punctuation.
def line_is_text(line: str) -> bool:
    s = line.strip()
    if len(s) < 4:
        return False
    words = WORD.findall(s)
    if len(words) < 1:
        return False
    letters = sum(1 for ch in s if ch.isalpha())
    # Reject lines dominated by punctuation/digits, which is the OCR-noise signature.
    return letters / max(1, len(s)) >= 0.55 and len("".join(words)) / len(s) >= 0.4


def clean(text: str) -> str:
    t = text.replace("\r", "")
    t = DIGIT_RUN.sub(" ", t)
    t = re.sub(r"[|¦¢€%\$\^~`<>{}_\[\]\\|]+", " ", t)
    t = re.sub(r"[ \t]+", " ", t)
    t = re.sub(r"\n{3,}", "\n\n", t)
    return t.strip()


def score(text: str) -> float:
    body = re.sub(r"\s", "", text)
    if not body:
        return 0.0
    good = sum(len(w) for w in WORD.findall(text))
    return good / len(body)


def main():
    manifest = OUT / "manifest.jsonl"
    if not manifest.exists():
        print("  no manifest - run the fetcher first")
        return 1

    rows = []
    for line in manifest.read_text(errors="replace").split("\n"):
        if not line.strip():
            continue
        try:
            rows.append(json.loads(line))
        except json.JSONDecodeError:
            continue

    ok = [r for r in rows if r.get("status") == "ok"]
    buckets = {"excellent (>=0.75)": 0, "usable (0.55-0.75)": 0,
               "poor (0.35-0.55)": 0, "unusable (<0.35)": 0}
    samples = {"excellent": None, "usable": None, "poor": None}

    for r in ok:
        f = OUT / "heads" / f"{r['pid'].replace(chr(92) + 'W', '_')}.txt"
        # the fetcher sanitises with a character class; rebuild the same name
        safe = re.sub(r"[^\w.-]", "_", r["pid"]) + ".txt"
        f = OUT / "heads" / safe
        if not f.exists():
            continue
        text = f.read_text(errors="replace")
        s = score(text)
        if s >= 0.75:
            buckets["excellent (>=0.75)"] += 1
            k = "excellent"
        elif s >= 0.55:
            buckets["usable (0.55-0.75)"] += 1
            k = "usable"
        elif s >= 0.35:
            buckets["poor (0.35-0.55)"] += 1
            k = "poor"
        else:
            buckets["unusable (<0.35)"] += 1
            continue
        if samples[k] is None:
            lines = [l for l in clean(text).split("\n") if line_is_text(l)]
            samples[k] = (r["pid"], lines[:6])

    total = sum(buckets.values())
    print(f"  scored {total} of {len(rows)} fetched\n")
    for k, v in buckets.items():
        pct = v / max(1, total) * 100
        print(f"    {k:<24} {v:5d}  ({pct:.0f}%)")
    good = buckets["excellent (>=0.75)"] + buckets["usable (0.55-0.75)"]
    print(f"\n    publishable (>=0.55): {good} of {total}  ({good/max(1,total)*100:.0f}%)")

    for k, v in samples.items():
        if not v:
            continue
        pid, lines = v
        print(f"\n  sample - {k} ({pid})")
        for l in lines:
            print(f"    {l[:90]}")
    return 0


if __name__ == "__main__":
    sys.exit(main())