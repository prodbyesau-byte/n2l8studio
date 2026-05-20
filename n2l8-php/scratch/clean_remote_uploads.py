#!/usr/bin/env python3
import os
import sys
import ftplib
import configparser
from pathlib import Path

# Force UTF-8 output
import io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

SCRIPT_DIR = Path(__file__).parent.parent.resolve()
CREDS_FILE = SCRIPT_DIR / '.ftpcredentials'

if not CREDS_FILE.exists():
    print("❌  .ftpcredentials not found.")
    sys.exit(1)

cfg = configparser.ConfigParser()
cfg.read(CREDS_FILE)

FTP_HOST = cfg['ftp']['host']
FTP_PORT = int(cfg['ftp'].get('port', 21))
FTP_USER = cfg['ftp']['user']
FTP_PASS = cfg['ftp']['password']
FTP_REMOTE = cfg['ftp'].get('remote', '/public_html/').rstrip('/') + '/'

# Targets
target_dir = FTP_REMOTE + 'static/uploads'

print(f"Connecting to FTP server {FTP_HOST}...")
try:
    ftp = ftplib.FTP()
    ftp.connect(FTP_HOST, FTP_PORT)
    ftp.login(FTP_USER, FTP_PASS)
    print("✅ Connected!")

    print(f"Changing directory to: {target_dir}")
    ftp.cwd(target_dir)

    print("Listing files inside remote static/uploads...")
    files = ftp.nlst()
    deleted_count = 0

    for f in files:
        if f.lower().endswith(('.zip', '.wav', '.mp3')):
            print(f"  🗑️  Deleting remote file: {f}...")
            try:
                ftp.delete(f)
                deleted_count += 1
            except Exception as e:
                print(f"  ❌ Failed to delete {f}: {e}")

    print(f"\n============================================================")
    print(f"  ✅ Deleted {deleted_count} remote file(s) successfully!")
    print(f"============================================================")

    ftp.quit()
except Exception as e:
    print(f"❌ FTP operation failed: {e}")
    sys.exit(1)
