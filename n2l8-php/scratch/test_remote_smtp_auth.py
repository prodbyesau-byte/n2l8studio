import smtplib
import ssl

SMTP_HOST = 'websmtp.simply.com'
SMTP_PORT = 587
SMTP_USER = 'admin@n2l8studio.dk'
SMTP_PASS = 'N2L8-Studio-Vault-2026!'

print(f"Connecting to {SMTP_HOST}:{SMTP_PORT}...")
try:
    # Connect
    server = smtplib.SMTP(SMTP_HOST, SMTP_PORT, timeout=10)
    server.set_debuglevel(1)
    
    print("Sending EHLO...")
    server.ehlo()
    
    print("Starting TLS...")
    context = ssl.create_default_context()
    server.starttls(context=context)
    
    print("Sending EHLO after TLS...")
    server.ehlo()
    
    print(f"Attempting login as {SMTP_USER}...")
    server.login(SMTP_USER, SMTP_PASS)
    
    print("✅ SMTP AUTHENTICATION SUCCESSFUL!")
    server.quit()
except Exception as e:
    print(f"❌ SMTP AUTHENTICATION FAILED: {e}")
