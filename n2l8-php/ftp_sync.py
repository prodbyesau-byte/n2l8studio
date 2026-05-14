#!/usr/bin/env python3
"""
N2L8Studio — FTP Sync Script
Uploads the PHP site to the live server (local → server, one-way).

Usage:
  python ftp_sync.py            # dry run (shows what would be uploaded)
  python ftp_sync.py --upload   # actually uploads

Credentials are read from .ftpcredentials (never uploaded — in .ftpignore).
"""

import os
import sys
import io
import ftplib
import configparser
from pathlib import Path

# Force UTF-8 output on Windows
if sys.stdout.encoding != 'utf-8':
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

# ── Paths ────────────────────────────────────────────────────────────────────
SCRIPT_DIR   = Path(__file__).parent.resolve()
CREDS_FILE   = SCRIPT_DIR / '.ftpcredentials'
IGNORE_FILE  = SCRIPT_DIR / '.ftpignore'

# ── Load credentials ─────────────────────────────────────────────────────────
if not CREDS_FILE.exists():
    print("❌  .ftpcredentials not found. Create it first.")
    sys.exit(1)

cfg = configparser.ConfigParser()
cfg.read(CREDS_FILE)

FTP_HOST   = cfg['ftp']['host']
FTP_PORT   = int(cfg['ftp'].get('port', 21))
FTP_USER   = cfg['ftp']['user']
FTP_PASS   = cfg['ftp']['password']
FTP_REMOTE = cfg['ftp'].get('remote', '/public_html/').rstrip('/') + '/'

# ── Load ignore patterns ──────────────────────────────────────────────────────
ignore_patterns = set()
if IGNORE_FILE.exists():
    for line in IGNORE_FILE.read_text(encoding='utf-8').splitlines():
        line = line.strip()
        if line and not line.startswith('#'):
            ignore_patterns.add(line.rstrip('/'))

def should_ignore(rel_path: str) -> bool:
    """Return True if this relative path should be excluded from upload."""
    parts = Path(rel_path).parts
    for pattern in ignore_patterns:
        pat_parts = Path(pattern).parts
        # Check if path starts with pattern or equals it
        if parts[:len(pat_parts)] == pat_parts:
            return True
        # Wildcard suffix (e.g. *.pyc)
        if pattern.startswith('*') and rel_path.endswith(pattern[1:]):
            return True
    return False

# ── Collect files to upload ───────────────────────────────────────────────────
def collect_files():
    upload_list = []
    for local_path in SCRIPT_DIR.rglob('*'):
        if local_path.is_dir():
            continue
        rel = local_path.relative_to(SCRIPT_DIR).as_posix()
        if not should_ignore(rel):
            upload_list.append((local_path, rel))
    return upload_list

# ── FTP helpers ───────────────────────────────────────────────────────────────
def ftp_mkdirs(ftp: ftplib.FTP, remote_dir: str):
    """Recursively create remote directories."""
    parts = remote_dir.strip('/').split('/')
    path  = ''
    for part in parts:
        path = path + '/' + part
        try:
            ftp.mkd(path)
        except ftplib.error_perm:
            pass  # already exists

def upload_file(ftp: ftplib.FTP, local_path: Path, remote_path: str, retries: int = 3):
    remote_dir = os.path.dirname(remote_path)
    ftp_mkdirs(ftp, remote_dir)
    for attempt in range(1, retries + 1):
        try:
            with open(local_path, 'rb') as f:
                ftp.storbinary(f'STOR {remote_path}', f)
            return
        except Exception as e:
            if attempt < retries:
                print(f"\n    ⚠ Timeout, retrying ({attempt}/{retries-1})...", end=' ', flush=True)
                # Reconnect on failure
                try:
                    ftp.quit()
                except Exception:
                    pass
                import configparser
                cfg2 = configparser.ConfigParser()
                cfg2.read(CREDS_FILE)
                ftp.connect(cfg2['ftp']['host'], int(cfg2['ftp'].get('port', 21)), timeout=60)
                ftp.login(cfg2['ftp']['user'], cfg2['ftp']['password'])
                ftp.set_pasv(True)
            else:
                raise

# ── Main ──────────────────────────────────────────────────────────────────────
def main():
    dry_run = '--upload' not in sys.argv

    files = collect_files()
    total = len(files)
    total_size = sum(p.stat().st_size for p, _ in files)

    print(f"\n{'=' * 60}")
    print(f"  N2L8Studio FTP Sync")
    print(f"  Host:   {FTP_HOST}:{FTP_PORT}")
    print(f"  Remote: {FTP_REMOTE}")
    print(f"  Files:  {total}  ({total_size / 1024 / 1024:.1f} MB)")
    print(f"  Mode:   {'DRY RUN (add --upload to actually sync)' if dry_run else '🚀 UPLOADING'}")
    print(f"{'=' * 60}\n")

    if dry_run:
        for _, rel in files:
            print(f"  [would upload] {rel}")
        print(f"\n✅ Dry run complete. {total} files would be uploaded.")
        print("   Run with --upload to sync.\n")
        return

    print("Connecting to FTP server...")
    try:
        ftp = ftplib.FTP()
        ftp.connect(FTP_HOST, FTP_PORT, timeout=30)
        ftp.login(FTP_USER, FTP_PASS)
        ftp.set_pasv(True)
        print(f"✅ Connected: {ftp.getwelcome()}\n")
    except Exception as e:
        print(f"❌  FTP connection failed: {e}")
        sys.exit(1)

    errors = []
    for i, (local_path, rel) in enumerate(files, 1):
        remote_path = FTP_REMOTE + rel
        size_kb = local_path.stat().st_size / 1024
        print(f"  [{i}/{total}] {rel}  ({size_kb:.1f} KB)", end=' ... ', flush=True)
        try:
            upload_file(ftp, local_path, remote_path)
            print("✅")
        except Exception as e:
            print(f"❌  {e}")
            errors.append((rel, str(e)))

    ftp.quit()

    print(f"\n{'=' * 60}")
    if errors:
        print(f"⚠️   FTP sync complete with {len(errors)} error(s):")
        for rel, err in errors:
            print(f"     {rel}: {err}")
    else:
        print(f"✅  All {total} files uploaded successfully.")
    print(f"    Site: https://n2l8studio.dk/")
    print(f"{'=' * 60}\n")

    # ── Auto-run DB sync after upload ────────────────────────────────────────
    print("Running database sync (db_setup.py)...\n")
    import subprocess as sp
    result = sp.run([sys.executable, str(SCRIPT_DIR / 'db_setup.py')], check=False)
    if result.returncode != 0:
        print("⚠️  db_setup.py exited with errors — check output above.")

if __name__ == '__main__':
    main()
