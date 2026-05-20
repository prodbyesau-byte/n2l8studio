import ftplib
from pathlib import Path

FTP_HOST = "linux60.unoeuro.com"
FTP_USER = "n2l8studio.dk"
FTP_PASS = "y2Bf39FwbgkxGa6DctRE"
REMOTE_DIR = "/n2l8studios.com/"

local_file = Path(__file__).parent.parent / "admin" / "smtp_quick_test.php"

print("Connecting to FTP...")
ftp = ftplib.FTP(FTP_HOST)
ftp.login(FTP_USER, FTP_PASS)

remote_path = REMOTE_DIR + "admin/smtp_quick_test.php"
print(f"Uploading {local_file.name} -> {remote_path}")
with open(local_file, 'rb') as f:
    ftp.storbinary(f'STOR {remote_path}', f)
print("Upload complete!")

ftp.quit()
