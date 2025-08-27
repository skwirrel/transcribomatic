<?php
#!/usr/bin/env php
/**
 * Generate Management Tokens Script
 * 
 * Usage: php generateTokens.php <number_of_tokens>
 * Example: php generateTokens.php 5
 */

require_once __DIR__ . '/../lib/auth.php';

// Check command line arguments
if ($argc !== 2 || !is_numeric($argv[1]) || $argv[1] <= 0) {
    echo "Usage: php generateTokens.php <number_of_tokens>\n";
    echo "Example: php generateTokens.php 5\n";
    exit(1);
}

$numTokens = intval($argv[1]);

if (!defined('BASE_URL')) {
    echo "Error: BASE_URL not defined in config.php\n";
    exit(1);
}

echo "Generating $numTokens management tokens...\n\n";

for ($i = 1; $i <= $numTokens; $i++) {
    // Generate unique ID
    $uniqueId = generateUniqueId(10);
    
    // Generate management token (uniqueId:HMAC with 'manage' context)
    $managementHmac = generateHmac($uniqueId, 'manage');
    $managementToken = $uniqueId . ':' . $managementHmac;
    
    // Generate user token (uniqueId:HMAC with 'user' context)  
    $userHmac = generateHmac($uniqueId, 'user');
    $userToken = $uniqueId . ':' . $userHmac;
    
    // Build URLs
    $managementUrl = BASE_URL . '/manage.php?token=' . urlencode($managementToken);
    $userUrl = BASE_URL . '/?token=' . urlencode($userToken);
    
    echo "Token Set $i:\n";
    echo "  User ID: $uniqueId\n";
    echo "  Management URL: $managementUrl\n";
    echo "  User URL: $userUrl\n";
    echo "\n";
}

echo "Done! Share the Management URLs to set up user accounts.\n";
echo "Users will get their personal User URLs from the management interface.\n";