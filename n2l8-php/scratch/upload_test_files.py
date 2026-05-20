import ftplib
from pathlib import Path

FTP_HOST = "linux60.unoeuro.com"
FTP_USER = "n2l8studio.dk"
FTP_PASS = "y2Bf39FwbgkxGa6DctRE"
REMOTE_DIR = "/n2l8studios.com/"

files_to_upload = [
    "admin/test_approval_email.php",
]

print("Connecting to FTP...")
ftp = ftplib.FTP(FTP_HOST)
ftp.login(FTP_USER, FTP_PASS)

base = Path(__file__).parent.parent

for rel_path in files_to_upload:
    local_file = base / rel_path
    remote_path = REMOTE_DIR + rel_path
    print(f"Uploading {rel_path} -> {remote_path}")
    with open(local_file, 'rb') as f:
        ftp.storbinary(f'STOR {remote_path}', f)
    print("  OK")

ftp.quit()
print("Done!")
