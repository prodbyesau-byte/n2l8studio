import ftplib

FTP_HOST = "linux60.unoeuro.com"
FTP_USER = "n2l8studio.dk"
FTP_PASS = "y2Bf39FwbgkxGa6DctRE"

print("Connecting to FTP...")
ftp = ftplib.FTP(FTP_HOST)
ftp.login(FTP_USER, FTP_PASS)

print("\nRemote root contents:")
print(ftp.nlst())

print("\nRemote n2l8studios.com contents:")
try:
    ftp.cwd("/n2l8studios.com")
    print(ftp.nlst())
except Exception as e:
    print("❌ Error:", e)

ftp.quit()
