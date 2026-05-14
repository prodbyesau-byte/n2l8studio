<?php
// N2L8Studio — Configuration Template
// Copy this to config.php and fill in your values.
// On production: config.php is uploaded manually or via host control panel.

define('DB_HOST',   'your-mysql-host');
define('DB_PORT',   '3306');
define('DB_USER',   'your_db_user');
define('DB_PASS',   'your_db_password');
define('DB_NAME',   'your_db_name');

define('SECRET_KEY',   'change-this-to-a-long-random-string');
define('UPLOAD_DIR',   __DIR__ . '/../static/uploads/');
define('UPLOAD_URL',   '/static/uploads/');
