<?php
/**
 * Model Signing Script
 * 
 * Command line script to generate a JavaScript file containing the signed model string
 * Uses HMAC-SHA256 signing to prevent tampering with model parameter
 * 
 * Usage: php signModel.php [model_name] [output_file]
 */

// Only allow command line execution
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

try {
    // Load config file to get HMAC secret
    require_once __DIR__ . '/../config/config.php';
    
    if (!defined('OPENAI_HMAC_SECRET') || empty(OPENAI_HMAC_SECRET)) {
        throw new Exception('HMAC secret not configured in config.php');
    }
    
    // Default model to sign
    $model = 'gpt-4o-realtime-preview';
    
    // Allow model parameter to be passed as command line argument
    if (isset($argv[1]) && !empty($argv[1])) {
        $requestedModel = $argv[1];
        
        // Whitelist allowed models for security
        $allowedModels = [
            'gpt-4o-mini-realtime-preview',
            'gpt-4o-realtime-preview-2024-10-01',
            'gpt-4o-realtime-preview-2024-12-17', 
            'gpt-4o-realtime-preview',
            'gpt-4o-transcribe'
        ];
        
        if (in_array($requestedModel, $allowedModels)) {
            $model = $requestedModel;
        } else {
            throw new Exception('Model not allowed: ' . $requestedModel);
        }
    }
    
    // Generate HMAC signature for the model
    $signature = hash_hmac('sha256', $model, OPENAI_HMAC_SECRET);
    
    // Create signed model string
    $signedModel = $model . '.' . $signature;
    
    // Determine output file
    $outputFile = isset($argv[2]) ? $argv[2] : __DIR__ . '/../public/signedModel.js';
    
    // Generate JavaScript content
    $jsContent = "// Generated signed model - " . date('Y-m-d H:i:s') . "\n";
    $jsContent .= "// Model: " . $model . "\n";
    $jsContent .= "const SIGNED_MODEL = '" . $signedModel . "';\n";
    
    // Write to file
    if (file_put_contents($outputFile, $jsContent) === false) {
        throw new Exception('Failed to write to output file: ' . $outputFile);
    }
    
    echo "Successfully generated signed model JavaScript file:\n";
    echo "Model: " . $model . "\n";
    echo "Output: " . $outputFile . "\n";
    echo "Signed model: " . $signedModel . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>