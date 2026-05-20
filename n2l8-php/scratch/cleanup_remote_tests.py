import ftplib

FTP_HOST = "linux60.unoeuro.com"
FTP_USER = "n2l8studio.dk"
FTP_PASS = "y2Bf39FwbgkxGa6DctRE"
REMOTE_DIR = "/n2l8studios.com/"

# Test files to clean up from the remote server
test_files = [
    "admin/smtp_quick_test.php",
    "admin/test_approval_email.php",
    "admin/test_hotmail_delivery.php",
    "admin/send_test_email.php",
]

print("Connecting to FTP...")
ftp = ftplib.FTP(FTP_HOST)
ftp.login(FTP_USER, FTP_PASS)

for rel_path in test_files:
    remote_path = REMOTE_DIR + rel_path
    try:
        ftp.delete(remote_path)
        print(f"  Deleted: {remote_path}")
    except Exception as e:
        print(f"  Skip (not found): {remote_path}")

ftp.quit()
print("Remote cleanup done!")
