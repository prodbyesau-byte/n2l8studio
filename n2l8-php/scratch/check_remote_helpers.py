import ftplib

FTP_HOST = "linux60.unoeuro.com"
FTP_USER = "n2l8studio.dk"
FTP_PASS = "y2Bf39FwbgkxGa6DctRE"

print("Connecting to FTP...")
ftp = ftplib.FTP(FTP_HOST)
ftp.login(FTP_USER, FTP_PASS)
ftp.cwd("/n2l8studios.com/includes")

print("\nReading remote helpers.php SMTP section:")
lines = []
try:
    ftp.retrlines("RETR helpers.php", lines.append)
    content = "\n".join(lines)
    # find send_platform_email
    idx = content.find("function send_platform_email")
    if idx != -1:
        print(content[idx:idx+1500])
    else:
        print("Could not find send_platform_email in remote helpers.php")
except Exception as e:
    print("❌ Error reading helpers.php:", e)

ftp.quit()
