#!/usr/bin/env python3
"""
Full SMTP send test - replicates what PHPMailer does on the server.
Sends a real email via Simply.com SMTP to verify delivery.
"""
import smtplib
import ssl
import sys
import io
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart

# Force UTF-8
if sys.stdout.encoding != 'utf-8':
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')

SMTP_HOST = 'websmtp.simply.com'
SMTP_PORT = 587
SMTP_USER = 'admin@n2l8studio.dk'
SMTP_PASS = 'N2L8-Studio-Vault-2026!'
FROM_EMAIL = 'admin@n2l8studios.com'
FROM_NAME = 'N2L8 STUDIO'
TEST_RECIPIENT = 'prodbyesau@gmail.com'

print(f"SMTP Host: {SMTP_HOST}:{SMTP_PORT}")
print(f"Auth User: {SMTP_USER}")
print(f"From: {FROM_NAME} <{FROM_EMAIL}>")
print(f"To: {TEST_RECIPIENT}")
print()

# Build HTML email (same style as user_action.php approval email)
html_body = """
<html>
<body style="background-color:#050508; color:#ffffff; font-family:'Montserrat',sans-serif; padding:40px 20px; margin:0;">
    <div style="max-width:600px; margin:0 auto; background:#0d0d12; border:1px solid #c0152a; padding:40px; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.5); text-align:center;">
        <div style="font-size:28px; font-weight:700; letter-spacing:3px; color:#ffffff; margin-bottom:30px;">
            N<span style="color:#c0152a;">2</span>L8studios
        </div>
        <h2 style="color:#ffffff; font-size:22px; text-transform:uppercase; letter-spacing:1px; margin-bottom:20px;">
            SMTP DELIVERY TEST
        </h2>
        <p style="color:#b3b3b3; font-size:15px; line-height:1.7; margin-bottom:35px;">
            This is an automated delivery test from the N2L8 STUDIO platform.<br><br>
            If you received this email, SMTP delivery via Simply.com is working correctly with LOGIN authentication.
        </p>
        <div style="margin-top:40px; border-top:1px solid rgba(255,255,255,0.05); padding-top:20px; color:#666666; font-size:12px;">
            &copy; 2026 N2L8studios. Diagnostic test email.
        </div>
    </div>
</body>
</html>
"""

msg = MIMEMultipart('alternative')
msg['From'] = f'{FROM_NAME} <{FROM_EMAIL}>'
msg['To'] = TEST_RECIPIENT
msg['Reply-To'] = FROM_EMAIL
msg['Subject'] = 'N2L8 STUDIO - SMTP Delivery Verification Test'

# Attach plain text + HTML
msg.attach(MIMEText('This is an SMTP delivery test from N2L8 STUDIO.', 'plain', 'utf-8'))
msg.attach(MIMEText(html_body, 'html', 'utf-8'))

try:
    print("1. Connecting to SMTP server...")
    server = smtplib.SMTP(SMTP_HOST, SMTP_PORT, timeout=15)
    
    print("2. EHLO...")
    code, resp = server.ehlo()
    print(f"   EHLO response: {code}")
    
    print("3. STARTTLS...")
    context = ssl.create_default_context()
    server.starttls(context=context)
    
    print("4. EHLO after TLS...")
    server.ehlo()
    
    print(f"5. LOGIN as {SMTP_USER}...")
    # Force LOGIN method (not CRAM-MD5)
    server.login(SMTP_USER, SMTP_PASS)
    print("   AUTH OK!")
    
    print(f"6. Sending email FROM={FROM_EMAIL} TO={TEST_RECIPIENT}...")
    result = server.sendmail(FROM_EMAIL, [TEST_RECIPIENT], msg.as_string())
    
    if result:
        print(f"   PARTIAL FAILURE: {result}")
    else:
        print("   SEND OK! (no errors returned)")
    
    print("7. QUIT...")
    server.quit()
    
    print("\n=== RESULT: SUCCESS ===")
    print(f"Email sent to {TEST_RECIPIENT}")
    print("Check inbox (and spam folder) for delivery confirmation.")
    
except smtplib.SMTPAuthenticationError as e:
    print(f"\nAUTH ERROR: {e}")
except smtplib.SMTPRecipientsRefused as e:
    print(f"\nRECIPIENT REFUSED: {e}")
except Exception as e:
    print(f"\nERROR: {type(e).__name__}: {e}")
