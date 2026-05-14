import ftplib, configparser, io

cfg = configparser.ConfigParser()
cfg.read('.ftpcredentials')

content = """<?php
// N2L8Studio — Production Configuration
define('DB_HOST',   'mysql63.unoeuro.com');
define('DB_PORT',   '3306');
define('DB_USER',   'n2l8studio_dk');
define('DB_PASS',   'y2Bf39FwbgkxGa6DctRE');
define('DB_NAME',   'n2l8studio_dk_db');

define('SECRET_KEY',   'n2l8studio_secret_key_1950s');
define('UPLOAD_DIR',   __DIR__ . '/../static/uploads/');
define('UPLOAD_URL',   '/static/uploads/');

// PayPal Settings
define('PAYPAL_SANDBOX', true);
define('PAYPAL_SANDBOX_CLIENT_ID', 'test');
define('PAYPAL_SANDBOX_SECRET',    'test');
define('PAYPAL_LIVE_CLIENT_ID',    '');
define('PAYPAL_LIVE_SECRET',       '');
define('PAYPAL_CLIENT_ID', PAYPAL_SANDBOX ? PAYPAL_SANDBOX_CLIENT_ID : PAYPAL_LIVE_CLIENT_ID);
?>"""

ftp = ftplib.FTP()
ftp.connect(cfg['ftp']['host'], 21, timeout=30)
ftp.login(cfg['ftp']['user'], cfg['ftp']['password'])
ftp.set_pasv(True)
ftp.cwd('/public_html/includes')
ftp.storbinary('STOR config.php', io.BytesIO(content.encode('utf-8')))
ftp.quit()
print('config.php fixed on server.')
