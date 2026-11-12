#!/usr/bin/env python3
"""
Remove FB2 books from the library, keeping EPUB.

DRY RUN by default - it only reports. Nothing is deleted unless you pass --delete.

  python3 cleanup_fb2.py /volume1/Books              # report only
  python3 cleanup_fb2.py /volume1/Books --delete     # delete FB2 that have an EPUB twin
  python3 cleanup_fb2.py /volume1/Books --delete --all   # delete ALL FB2, even orphans

Handles: *.fb2, *.fb2.zip, and Flibusta-style zip archives that contain only .fb2 files.
An FB2 is "matched" when an .epub with the same base name exists anywhere under the root
(e.g. 123456.fb2 <-> 123456.epub, or "Author - Title.fb2" <-> "Author - Title.epub").
Orphans (FB2 with no EPUB) are written to fb2_orphans.txt so you can see what you'd lose.
"""
import argparse, os, sys, zipfile

def stem(name):
    n = os.path.basename(name).lower()
    for ext in (".fb2.zip", ".epub", ".fb2", ".zip"):
        if n.endswith(ext):
            return n[: -len(ext)]
    return n

def fb2_zip_members(path):
    """Return list of fb2 member names if zip contains only fb2 files, else None."""
    try:
        with zipfile.ZipFile(path) as z:
            names = [n for n in z.namelist() if not n.endswith("/")]
    except Exception:
        return None
    if names and all(n.lower().endswith(".fb2") for n in names):
        return names
    return None

def human(n):
    for u in ("B", "KB", "MB", "GB", "TB"):
        if n < 1024:
            return f"{n:.1f} {u}"
        n /= 1024
    return f"{n:.1f} PB"

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("root")
    ap.add_argument("--delete", action="store_true", help="actually delete files")
    ap.add_argument("--all", action="store_true", help="also delete FB2 that have no EPUB twin")
    a = ap.parse_args()

    epubs, fb2s = set(), []   # fb2s: (path, [stems])
    print("Scanning...", file=sys.stderr)
    for dp, dns, fns in os.walk(a.root):
        dns[:] = [d for d in dns if not d.startswith(("@", "#recycle", "."))]  # skip Synology system dirs
        for f in fns:
            p = os.path.join(dp, f)
            fl = f.lower()
            if fl.endswith(".epub"):
                epubs.add(stem(f))
            elif fl.endswith(".fb2") or fl.endswith(".fb2.zip"):
                fb2s.append((p, [stem(f)]))
            elif fl.endswith(".zip"):
                m = fb2_zip_members(p)
                if m:
                    fb2s.append((p, [stem(x) for x in m]))

    matched, orphan = [], []
    orphan_books = 0
    for p, stems in fb2s:
        missing = [s for s in stems if s not in epubs]
        if missing:
            orphan.append((p, missing)); orphan_books += len(missing)
        else:
            matched.append(p)

    size = lambda ps: sum(os.path.getsize(x) for x in ps if os.path.exists(x))
    print(f"EPUB books found:            {len(epubs)}")
    print(f"FB2 files/archives found:    {len(fb2s)}")
    print(f"  with EPUB twin (safe):     {len(matched)}  ({human(size(matched))})")
    print(f"  orphans (no EPUB):         {len(orphan)} files, {orphan_books} books  ({human(size([p for p,_ in orphan]))})")

    with open("fb2_orphans.txt", "w", encoding="utf-8") as fh:
        for p, miss in orphan:
            fh.write(f"{p}\t{len(miss)} book(s) without epub\n")
    print("Orphan list written to fb2_orphans.txt")

    targets = matched + ([p for p, _ in orphan] if a.all else [])
    if not a.delete:
        print(f"\nDRY RUN. Would delete {len(targets)} files ({human(size(targets))}). Add --delete to do it.")
        return
    freed, n = 0, 0
    for p in targets:
        try:
            s = os.path.getsize(p); os.remove(p); freed += s; n += 1
        except OSError as e:
            print(f"FAILED {p}: {e}", file=sys.stderr)
    print(f"\nDeleted {n} files, freed {human(freed)}.")
    print("Note: if Recycle Bin is enabled on the Books share, empty it to actually get the space back.")

if __name__ == "__main__":
    main()
