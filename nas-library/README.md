# NAS Library - EPUB cleanup, search page, Send to Kindle

Three pieces:

| File | What it does |
|---|---|
| `cleanup_fb2.py` | Removes FB2 (loose `.fb2`, `.fb2.zip`, Flibusta zip packs). Dry run by default. |
| `build_index.py` | Scans all EPUBs, reads author/title from metadata, builds `library.db` (SQLite full-text). |
| `www/` | Search page for Web Station: search by author/title, download, one-click Send to Kindle. |

Everything is stdlib Python + plain PHP. No pip, no composer.

---

## 1. Get the files onto the NAS

Copy this folder to the NAS, e.g. `/volume1/homes/<you>/nas-library`.
Enable SSH: **Control Panel > Terminal & SNMP > Enable SSH**, then `ssh you@nas-ip`.

Check Python: `python3 --version`. If missing, install **Python 3** from Package Center.

## 2. Ditch FB2

```bash
cd ~/nas-library
sudo python3 cleanup_fb2.py /volume1/Books
```

This only reports. Read the numbers:

- **with EPUB twin** - safe, the same book exists as EPUB.
- **orphans** - FB2 with no EPUB match by filename. These are listed in `fb2_orphans.txt`.

Then:

```bash
sudo python3 cleanup_fb2.py /volume1/Books --delete         # only FB2 that have an EPUB
sudo python3 cleanup_fb2.py /volume1/Books --delete --all   # everything FB2, orphans included
```

If orphans come back as nearly 100%, the FB2 and EPUB files are named differently (common in Flibusta
dumps) - it does not mean you are missing books. Look at a few names in `fb2_orphans.txt` vs your EPUB
folder; if the EPUB set is complete, just use `--all`.

If Recycle Bin is on for the share, empty it afterwards or you won't get the space back.

## 3. Build the search index

```bash
sudo mkdir -p /volume1/web/library
sudo python3 build_index.py /volume1/Books /volume1/web/library/library.db
```

First run on a full Flibusta EPUB set (hundreds of thousands of files) takes a while - expect
1-3 hours on a typical NAS. Re-runs only touch new/changed files.

Automate it: **Control Panel > Task Scheduler > Create > Scheduled Task > User-defined script**,
user `root`, daily at night:

```bash
python3 /volume1/homes/<you>/nas-library/build_index.py /volume1/Books /volume1/web/library/library.db
```

## 4. Web Station

1. Package Center: install **Web Station** and **PHP 8.2**.
2. Web Station > **Script Language Settings** > PHP > Create profile:
   - PHP 8.2
   - Extensions: tick `pdo_sqlite`, `sqlite3`, `mbstring`, `openssl`
   - `open_basedir`: add `/volume1/web/library:/volume1/Books`
3. Copy `www/*` to `/volume1/web/library/`, then `cp config.sample.php config.php` and edit it.
4. Web Station > **Web Service** > Create > Native script language website > PHP profile from step 2,
   document root `/volume1/web/library`.
5. Web Station > **Web Portal** > Create > port-based (e.g. 8088) or name-based, pointing at that service.
6. Permissions: the `http` user needs **Read** on the `Books` share
   (Control Panel > Shared Folder > Books > Edit > Permissions > System internal user > http).

Open `http://nas-ip:8088`.

## 5. Send to Kindle

Amazon accepts EPUB by email and converts it.

1. Amazon > **Manage Your Content and Devices > Preferences > Personal Document Settings**:
   copy your `@kindle.com` address, and add your sending Gmail to the **Approved Personal Document E-mail List**.
2. Gmail: turn on 2FA, create an app password at myaccount.google.com/apppasswords.
3. Put both in `config.php` (`kindle_email`, `smtp_user`, `smtp_pass`, `from_email`).

Then the **Kindle** button on any result emails the book. It shows up on the device in a few minutes.

Limits worth knowing:

- 50 MB per email. Page refuses anything over 48 MB.
- Amazon rejects some EPUBs (bad markup, mostly auto-converted ones). You get a failure email from
  Amazon, not an error on the page. For those, download and run through Calibre, or use USB.

## Security

If the page is only on your LAN, fine as is. If you expose it via QuickConnect or port forwarding,
set `page_password` in `config.php` and put it behind HTTPS (Web Portal > HTTPS + DSM certificate).
Otherwise anyone who finds it can download your library and spam your Kindle.
