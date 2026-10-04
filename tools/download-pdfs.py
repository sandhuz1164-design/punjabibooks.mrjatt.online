#!/usr/bin/env python3
"""Download book PDFs from the Internet Archive into a local library folder.

Resumable and safe to re-run: a file is only re-fetched when it is missing or
its size differs from the size the manifest expects.

  python3 tools/download-pdfs.py --workers 8 --limit 50      # trial run
  python3 tools/download-pdfs.py --workers 8                 # keep going
  python3 tools/download-pdfs.py --status                    # progress only
"""

import argparse
import concurrent.futures as futures
import hashlib
import json
import os
import shutil
import sys
import threading
import time
import urllib.error
import urllib.request

DEFAULT_MANIFEST = "/tmp/opencode/pdf-manifest.json"
DEFAULT_DEST = "/run/media/jatt/9890EF3990EF1C8E/ubuntujatt/booksdata"
USER_AGENT = "StrandBookstoreLocalReader/1.0 (+local library builder)"

_print_lock = threading.Lock()
_lock = threading.Lock()
stats = {"done": 0, "skip": 0, "fail": 0, "bytes": 0, "retried": 0}
_total = 0


def shard_for(pid: str) -> str:
    """Two-level fan-out so no single directory holds thousands of files."""
    h = hashlib.sha1(pid.encode()).hexdigest()
    return os.path.join(h[0], h[1])


def local_path(dest: str, pid: str) -> str:
    return os.path.join(dest, "pdf", shard_for(pid), pid + ".pdf")


def human(n: float) -> str:
    for unit in ("B", "KB", "MB", "GB", "TB"):
        if n < 1024:
            return f"{n:.1f}{unit}"
        n /= 1024
    return f"{n:.1f}PB"


def fetch(item: dict, dest: str, timeout: int, tries: int) -> tuple[str, str]:
    """Return (status, detail). status is done | skip | fail."""
    pid = item["pid"]
    url = item["url"]
    want = int(item.get("size") or 0)
    path = local_path(dest, pid)

    if os.path.exists(path):
        have = os.path.getsize(path)
        if want == 0 or abs(have - want) <= max(1024, want * 0.001):
            return "skip", ""
        os.remove(path)

    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp = path + ".part"
    last = "unknown"
    for attempt in range(1, tries + 1):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
            with urllib.request.urlopen(req, timeout=timeout) as resp, open(tmp, "wb") as fh:
                shutil.copyfileobj(resp, fh, length=1 << 20)
            got = os.path.getsize(tmp)
            if got == 0:
                raise IOError("empty response")
            if want and abs(got - want) > max(1024, want * 0.001):
                raise IOError(f"size mismatch got={got} want={want}")
            os.replace(tmp, path)
            return "done", got
        except Exception as exc:  # noqa: BLE001 - report and retry
            last = f"{type(exc).__name__}: {exc}"
            if os.path.exists(tmp):
                os.remove(tmp)
            if attempt < tries:
                with _lock:
                    stats["retried"] += 1
                time.sleep(min(30, 2 ** attempt))
    return "fail", last


def worker(item: dict, dest: str, timeout: int, tries: int) -> None:
    global _total
    status, detail = fetch(item, dest, timeout, tries)
    with _lock:
        stats[status if status != "skip" else "skip"] += 1
        if status == "done":
            stats["bytes"] += int(detail or 0)
        _total += 1
        n = _total
        every = 5 if status != "fail" else 1
        if n % every == 0 or status == "fail":
            with _print_lock:
                tag = {"done": "ok  ", "skip": "have", "fail": "FAIL"}[status]
                extra = f"  <- {detail[:90]}" if status == "fail" else ""
                print(
                    f"[{n}/{_total}] {tag} {item['pid'][:52]:52} "
                    f"{human(stats['bytes'])}{extra}",
                    flush=True,
                )


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--manifest", default=DEFAULT_MANIFEST)
    ap.add_argument("--dest", default=DEFAULT_DEST)
    ap.add_argument("--workers", type=int, default=6)
    ap.add_argument("--timeout", type=int, default=90)
    ap.add_argument("--tries", type=int, default=3)
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--max-mb", type=int, default=0, help="skip PDFs larger than this")
    ap.add_argument("--status", action="store_true")
    args = ap.parse_args()

    with open(args.manifest, encoding="utf-8") as fh:
        items = json.load(fh)

    todo = []
    have_bytes = 0
    for it in items:
        p = local_path(args.dest, it["pid"])
        size = int(it.get("size") or 0)
        if os.path.exists(p):
            have_bytes += os.path.getsize(p)
            continue
        if args.max_mb and size > args.max_mb * 1024 * 1024:
            continue
        todo.append(it)
    if args.limit:
        todo = todo[: args.limit]

    print(f"manifest      : {len(items)} books")
    print(f"already local : {len(items) - len(todo)}  ({human(have_bytes)})")
    print(f"to download   : {len(todo)}  ({human(sum(int(i.get('size') or 0) for i in todo))})")
    print(f"destination   : {args.dest}/pdf")
    print(f"workers       : {args.workers}\n")

    if args.status or not todo:
        return 0

    free = shutil.disk_usage(args.dest).free
    need = sum(int(i.get("size") or 0) for i in todo)
    if need > free * 0.9:
        print(f"REFUSING: need {human(need)} but only {human(free)} free", file=sys.stderr)
        return 1

    started = time.time()
    with futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
        futs = [pool.submit(worker, it, args.dest, args.timeout, args.tries) for it in todo]
        for f in futures.as_completed(futs):
            f.result()

    dt = time.time() - started
    rate = stats["bytes"] / dt if dt > 0 else 0
    print(
        f"\nfinished in {dt/60:.1f} min | downloaded {stats['done']} "
        f"({human(stats['bytes'])}, {human(rate)}/s) | already had {stats['skip']} "
        f"| failed {stats['fail']} | retries {stats['retried']}"
    )
    return 0 if stats["fail"] == 0 else 2


if __name__ == "__main__":
    sys.exit(main())
