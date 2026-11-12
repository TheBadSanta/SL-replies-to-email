#!/usr/bin/env python3
"""
Build the SQLite search index for the library web page. Reads author/title from
each EPUB's OPF metadata (falls back to the filename). Stdlib only - runs on DSM's python3.

  python3 build_index.py /volume1/Books /volume1/web/library/library.db

Incremental: files already indexed with the same size+mtime are skipped, deleted files are dropped.
Schedule it in DSM Task Scheduler (e.g. nightly) to pick up new books.
"""
import os, re, sys, sqlite3, zipfile, unicodedata
import xml.etree.ElementTree as ET

DC = "{http://purl.org/dc/elements/1.1/}"

def norm(s):
    s = (s or "").casefold().replace("ё", "е")
    s = unicodedata.normalize("NFKC", s)
    return re.sub(r"\s+", " ", s).strip()

def read_meta(path):
    title, authors, lang, year = None, [], None, None
    try:
        with zipfile.ZipFile(path) as z:
            cont = ET.fromstring(z.read("META-INF/container.xml"))
            rf = next(el for el in cont.iter() if el.tag.endswith("rootfile"))
            opf = ET.fromstring(z.read(rf.attrib["full-path"]))
            for el in opf.iter():
                t = (el.text or "").strip()
                if not t:
                    continue
                if el.tag == DC + "title" and not title:
                    title = t
                elif el.tag == DC + "creator":
                    authors.append(t)
                elif el.tag == DC + "language" and not lang:
                    lang = t[:5]
                elif el.tag == DC + "date" and not year:
                    m = re.search(r"\d{4}", t); year = m.group(0) if m else None
    except Exception:
        pass
    if not title or not authors:
        base = os.path.splitext(os.path.basename(path))[0]
        if " - " in base:
            a, t = base.split(" - ", 1)
            authors = authors or [a.strip()]
            title = title or t.strip()
        else:
            title = title or base
    return title, ", ".join(dict.fromkeys(authors)), lang, year

def main():
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    root, dbp = sys.argv[1], sys.argv[2]
    db = sqlite3.connect(dbp)
    db.executescript("""
    PRAGMA journal_mode=WAL;
    CREATE TABLE IF NOT EXISTS books(
      id INTEGER PRIMARY KEY, path TEXT UNIQUE, title TEXT, author TEXT,
      lang TEXT, year TEXT, size INTEGER, mtime INTEGER, search TEXT);
    CREATE INDEX IF NOT EXISTS ix_author ON books(author);
    """)
    fts = True
    try:
        db.execute("CREATE VIRTUAL TABLE IF NOT EXISTS books_fts USING fts5("
                   "title, author, content='books', content_rowid='id', tokenize='unicode61 remove_diacritics 2')")
    except sqlite3.OperationalError:
        fts = False
        print("FTS5 not available - page will use slower LIKE search", file=sys.stderr)

    known = {p: (s, m) for p, s, m in db.execute("SELECT path,size,mtime FROM books")}
    seen, added, n = set(), 0, 0
    for dp, dns, fns in os.walk(root):
        dns[:] = [d for d in dns if not d.startswith(("@", "#recycle", "."))]
        for f in fns:
            if not f.lower().endswith(".epub"):
                continue
            p = os.path.join(dp, f); seen.add(p)
            st = os.stat(p)
            if known.get(p) == (st.st_size, int(st.st_mtime)):
                continue
            title, author, lang, year = read_meta(p)
            db.execute("""INSERT INTO books(path,title,author,lang,year,size,mtime,search)
                          VALUES(?,?,?,?,?,?,?,?)
                          ON CONFLICT(path) DO UPDATE SET title=excluded.title, author=excluded.author,
                          lang=excluded.lang, year=excluded.year, size=excluded.size,
                          mtime=excluded.mtime, search=excluded.search""",
                       (p, title, author, lang, year, st.st_size, int(st.st_mtime),
                        norm(author) + " | " + norm(title)))
            added += 1; n += 1
            if n % 5000 == 0:
                db.commit(); print(f"  {n} indexed...", file=sys.stderr)
    gone = [p for p in known if p not in seen]
    db.executemany("DELETE FROM books WHERE path=?", [(p,) for p in gone])
    if fts:
        db.execute("INSERT INTO books_fts(books_fts) VALUES('rebuild')")
    db.commit()
    db.execute("PRAGMA journal_mode=DELETE")  # no -wal/-shm files, so the web user only needs read access
    db.close(); os.chmod(dbp, 0o644)
    db = sqlite3.connect(dbp)
    total = db.execute("SELECT COUNT(*) FROM books").fetchone()[0]
    print(f"Done. Added/updated {added}, removed {len(gone)}, total {total}. FTS5: {fts}")

if __name__ == "__main__":
    main()
