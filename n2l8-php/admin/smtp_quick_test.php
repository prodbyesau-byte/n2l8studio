<?php
// SMTP Quick Test with detailed logging
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/config.php';

echo "SMTP Diagnostic Test Page\n";
echo "=========================\n";
echo "PHP Version: " . phpversion() . "\n";
echo "SMTP_ENABLED: " . (defined('SMTP_ENABLED') && SMTP_ENABLED ? 'true' : 'false') . "\n";
echo "SMTP_HOST: " . (defined('SMTP_HOST') ? SMTP_HOST : 'NOT DEFINED') . "\n";
echo "SMTP_PORT: " . (defined('SMTP_PORT') ? SMTP_PORT : 'NOT DEFINED') . "\n";
echo "SMTP_USER: " . (defined('SMTP_USER') ? SMTP_USER : 'NOT DEFINED') . "\n";
echo "SMTP_SECURE: " . (defined('SMTP_SECURE') ? SMTP_SECURE : 'NOT DEFINED') . "\n";
echo "MAIL_FROM_EMAIL: " . (defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'NOT DEFINED') . "\n";
echo "\nTesting PHPMailer SMTP manually with SMTPDebug = 4...\n\n";

try {
    require_once __DIR__ . '/../includes/PHPMailer/Exception.php';
    require_once __DIR__ . '/../includes/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/../includes/PHPMailer/SMTP.php';
    
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    
    // Enable debug output
    $mail->SMTPDebug = 4;
    // Output debug info to standard output
    $mail->Debugoutput = 'echo';
    
    $mail->isSMTP();
    $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : '';
    $mail->SMTPAuth   = true;
    $mail->Username   = defined('SMTP_USER') ? SMTP_USER : '';
    $mail->Password   = defined('SMTP_PASS') ? SMTP_PASS : '';
    $mail->AuthType   = 'LOGIN'; // Force LOGIN authentication
    $mail->Port       = defined('SMTP_PORT') ? (int)SMTP_PORT : 587;
    
    $secure = defined('SMTP_SECURE') ? SMTP_SECURE : 'tls';
    if ($secure === 'ssl') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($secure === 'tls') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        $mail->SMTPSecure = '';
        $mail->SMTPAutoTLS = false;
    }
    
    $from_email = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'admin@n2l8studios.com';
    $from_name  = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'N2L8 STUDIO';
    
    $mail->setFrom($from_email, $from_name);
    $mail->addAddress('prodbyesau@gmail.com'); // We will test sending to user's Gmail
    
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'N2L8 STUDIO - SMTP Diagnostics';
    $mail->Body    = '<h3>SMTP Live Server Diagnostic Test</h3><p>If you see this, PHPMailer successfully bypassed CRAM-MD5 on Simply.com!</p>';
    
    echo "Sending email...\n";
    $success = $mail->send();
    echo "\nResult: " . ($success ? "SUCCESS! Email sent successfully." : "FAILED.") . "\n";
    
} catch (\Throwable $e) {
    echo "\nException caught: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
?>
