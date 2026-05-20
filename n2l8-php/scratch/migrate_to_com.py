import ftplib
import configparser
import io
from pathlib import Path

# Load credentials
creds_file = Path(__file__).parent.parent / '.ftpcredentials'
cfg = configparser.ConfigParser()
cfg.read(creds_file)

FTP_HOST = cfg['ftp']['host']
FTP_USER = cfg['ftp']['user']
FTP_PASS = cfg['ftp']['password']

print("Connecting to FTP...")
ftp = ftplib.FTP()
ftp.connect(FTP_HOST, 21, timeout=60)
ftp.login(FTP_USER, FTP_PASS)
ftp.set_pasv(True)
print("Connected!")

# Create folders
for folder in ['/n2l8studios.com/includes', '/n2l8studios.com/static', '/n2l8studios.com/static/uploads']:
    try:
        ftp.mkd(folder)
        print(f"Created folder: {folder}")
    except Exception as e:
        print(f"Folder might exist: {folder} ({e})")

# Copy config.php
print("\nCopying config.php...")
try:
    buf = io.BytesIO()
    ftp.retrbinary('RETR /public_html/includes/config.php', buf.write)
    buf.seek(0)
    ftp.storbinary('STOR /n2l8studios.com/includes/config.php', buf)
    print("[OK] config.php copied successfully!")
except Exception as e:
    print(f"[ERROR] Failed to copy config.php: {e}")

# Copy uploads
print("\nListing files in /public_html/static/uploads/...")
try:
    files = ftp.nlst('/public_html/static/uploads/')
    files = [f for f in files if not f.endswith('/.') and not f.endswith('/..')]
    
    for f in files:
        filename = f.split('/')[-1]
        target_path = f"/n2l8studios.com/static/uploads/{filename}"
        print(f"Migrating {filename} ...", end=' ', flush=True)
        try:
            ftp.rename(f, target_path)
            print("[OK] Moved (Instant)")
        except Exception as e:
            print(f"[ERROR] Error moving: {e}")
            
except Exception as e:
    print(f"Error copying uploads: {e}")

ftp.quit()
print("\nMigration complete!")
