import ftplib
from pathlib import Path

FTP_HOST = "linux60.unoeuro.com"
FTP_USER = "n2l8studio.dk"
FTP_PASS = "y2Bf39FwbgkxGa6DctRE"
REMOTE_DIR = "/n2l8studios.com/"

base = Path(__file__).parent.parent

files = ["admin/test_hotmail_delivery.php"]

print("Connecting to FTP...")
ftp = ftplib.FTP(FTP_HOST)
ftp.login(FTP_USER, FTP_PASS)

for rel_path in files:
    local_file = base / rel_path
    remote_path = REMOTE_DIR + rel_path
    print(f"Uploading {rel_path}")
    with open(local_file, 'rb') as f:
        ftp.storbinary(f'STOR {remote_path}', f)

ftp.quit()
print("Done!")
