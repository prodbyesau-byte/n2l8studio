import ftplib

ftp = ftplib.FTP()
ftp.connect('linux60.unoeuro.com', 21, timeout=15)
ftp.login('n2l8studio.dk', 'y2Bf39FwbgkxGa6DctRE')

print("--- FTP ROOT LISTING ---")
ftp.cwd('/')
ftp.retrlines('LIST')

print("\n--- N2L8STUDIOS.COM LISTING ---")
ftp.cwd('/n2l8studios.com/')
ftp.retrlines('LIST')

ftp.quit()
