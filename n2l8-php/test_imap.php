<?php
/**
 * Quick IMAP test — check if php-imap is available and can connect
 */
echo "PHP IMAP Extension: " . (function_exists('imap_open') ? 'AVAILABLE' : 'NOT AVAILABLE') . "\n";
echo "PHP Version: " . phpversion() . "\n";

// List all loaded extensions
$exts = get_loaded_extensions();
sort($exts);
$imap_related = array_filter($exts, fn($e) => stripos($e, 'imap') !== false || stripos($e, 'socket') !== false || stripos($e, 'openssl') !== false);
echo "Relevant extensions: " . implode(', ', $imap_related) . "\n";

if (function_exists('imap_open')) {
    $host = '{imap.simply.com:993/imap/ssl}INBOX';
    $user = 'admin@n2l8studio.dk';
    $pass = 'N2L8-Studio-Vault-2026!';
    
    echo "\nConnecting to IMAP: imap.simply.com:993/ssl\n";
    echo "User: $user\n";
    
    $inbox = @imap_open($host, $user, $pass, 0, 1);
    
    if ($inbox) {
        $info = imap_mailboxmsginfo($inbox);
        echo "SUCCESS! Connected to mailbox.\n";
        echo "Messages: {$info->Nmsgs}\n";
        echo "Recent: {$info->Recent}\n";
        echo "Unread: {$info->Unread}\n";
        echo "Size: {$info->Size} bytes\n";
        
        // List available folders
        $folders = imap_list($inbox, '{imap.simply.com:993/imap/ssl}', '*');
        if ($folders) {
            echo "\nAvailable folders:\n";
            foreach ($folders as $f) {
                echo "  - $f\n";
            }
        }
        
        imap_close($inbox);
    } else {
        echo "FAILED: " . imap_last_error() . "\n";
    }
} else {
    echo "\nFallback: Testing raw socket connection...\n";
    $fp = @fsockopen('ssl://imap.simply.com', 993, $errno, $errstr, 10);
    if ($fp) {
        $greeting = fgets($fp, 1024);
        echo "Socket connected! Server greeting: $greeting\n";
        fclose($fp);
    } else {
        echo "Socket connection failed: $errstr ($errno)\n";
    }
}
