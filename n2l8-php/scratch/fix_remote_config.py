import ftplib
import configparser
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

# 1. Download current remote config
print("\nDownloading remote config.php...")
lines = []
try:
    ftp.retrlines("RETR /n2l8studios.com/includes/config.php", lines.append)
    content = "\n".join(lines)
    print("Download successful.")
except Exception as e:
    print("[ERROR] Error downloading config.php:", e)
    ftp.quit()
    exit(1)

# 2. Modify SMTP_USER definition
old_line = "define('SMTP_USER',        'admin@n2l8studios.com');"
new_line = "define('SMTP_USER',        'admin@n2l8studio.dk');"

if old_line in content:
    content = content.replace(old_line, new_line)
    print("Updated SMTP_USER definition in memory.")
else:
    # Try with different spacing in case it was modified
    import re
    content, count = re.subn(
        r"define\s*\(\s*['\"]SMTP_USER['\"]\s*,\s*['\"]admin@n2l8studios\.com['\"]\s*\)\s*;",
        "define('SMTP_USER',        'admin@n2l8studio.dk');",
        content
    )
    if count > 0:
        print(f"Updated {count} matching SMTP_USER definition(s) via regex.")
    else:
        print("[WARNING] SMTP_USER define('SMTP_USER', 'admin@n2l8studios.com') was not found in remote config.php. Please review:")
        print(content)
        ftp.quit()
        exit(1)

# 3. Upload fixed config back to server
print("\nUploading corrected config.php...")
import io
try:
    buf = io.BytesIO(content.encode('utf-8'))
    ftp.storbinary('STOR /n2l8studios.com/includes/config.php', buf)
    print("[OK] config.php updated successfully on the production server!")
except Exception as e:
    print("[ERROR] Error uploading config.php:", e)

ftp.quit()
print("\nOperation complete!")
