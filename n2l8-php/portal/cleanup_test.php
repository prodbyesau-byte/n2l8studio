<?php
// Cleanup temporary test scripts
@unlink(__DIR__ . '/test_pending.php');
@unlink(__FILE__);
echo "Cleaned up remote test files!";
