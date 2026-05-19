import ftplib

FTP_HOST = "linux60.unoeuro.com"
FTP_USER = "n2l8studio.dk"
FTP_PASS = "y2Bf39FwbgkxGa6DctRE"

print("Connecting to FTP...")
ftp = ftplib.FTP(FTP_HOST)
ftp.login(FTP_USER, FTP_PASS)
ftp.cwd("/public_html/includes")

print("Files in remote includes folder:")
print(ftp.nlst())

print("\nReading remote config.php:")
lines = []
try:
    ftp.retrlines("RETR config.php", lines.append)
    print("\n".join(lines))
except Exception as e:
    print("❌ Error reading config.php:", e)

ftp.quit()
