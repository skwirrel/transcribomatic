<?php
/**
 * OpenAI Image Generation API
 * 
 * Generates images using OpenAI's DALL-E API
 * Takes a description parameter from GET or POST and returns image data
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Allow GET and POST requests
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use GET or POST.']);
    exit;
}

try {
    // Load API key from config file
    require_once '../config/config.php';
    require_once '../lib/auth.php';
    
    if (!defined('OPENAI_API_KEY') || empty(OPENAI_API_KEY)) {
        throw new Exception('OPENAI_API_KEY not defined in config.php');
    }
    
    // Authenticate user
    $token = '';
    $description = '';
    $uniqueId = null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $requestData = json_decode(file_get_contents('php://input'), true);
        if (isset($requestData['token'])) {
            $token = $requestData['token'];
        }
        if (isset($requestData['description'])) {
            $description = trim($requestData['description']);
        }
    } elseif (isset($_GET['token']) && isset($_GET['description'])) {
        $token = $_GET['token'];
        $description = trim($_GET['description']);
    }
    
    // Validate token
    if (empty($token)) {
        throw new Exception('Authentication token required');
    }
    
    $uniqueId = validateToken($token, 'user');
    if (!$uniqueId) {
        throw new Exception('Invalid or expired authentication token');
    }
    
    // Check if user exists and is enabled
    $userConfig = getUserConfig($uniqueId);
    if (!$userConfig) {
        throw new Exception('User not found or disabled');
    }
    
    // Check if user has image generation enabled
    if (!$userConfig['showImage']) {
        throw new Exception('Image generation disabled for this account');
    }
    
    if (empty($description)) {
        throw new Exception('Description parameter is required');
    }
    
    // Build headers with optional organization and project IDs
    $headers = [
        'Authorization: Bearer ' . OPENAI_API_KEY,
        'Content-Type: application/json'
    ];
    
    // Add optional OpenAI organization ID if defined
    if (defined('OPENAI_ORG_ID') && !empty(OPENAI_ORG_ID)) {
        $headers[] = 'OpenAI-Organization: ' . OPENAI_ORG_ID;
    }
    
    // Add optional OpenAI project ID if defined
    if (defined('OPENAI_PROJECT_ID') && !empty(OPENAI_PROJECT_ID)) {
        $headers[] = 'OpenAI-Project: ' . OPENAI_PROJECT_ID;
    }
    
    // Prepare image generation request
    $imageData = [
        'prompt' => 'DO NOT INCLUDE ANY TEXT. Simple black and white PICTOGRAM to convey the message: '.$description,
        'model' => 'gpt-image-1',
        'quality' => 'low',
        'output_compression' => 50,
        'output_format' => 'jpeg',
        #'model' => 'dall-e-3',
        #'quality' => 'standard',
        'n' => 1,
        'size' => '1024x1024',
    ];
    
    // Make API call to OpenAI images endpoint
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/images/generations');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($imageData));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception('cURL error: ' . $error);
    }
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from OpenAI API');
        }
        
        if (isset($data['data'][0]['b64_json'])) {
            // Log the image generation
            logApiUsage($uniqueId, 'picture', $description);
            
            // Decode base64 image data
            $imageData = base64_decode($data['data'][0]['b64_json']);
            
            // Set appropriate headers for JPEG image
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . strlen($imageData));
            header('Cache-Control: max-age=3600');
            
            // Output the raw image data
            echo $imageData;
            exit;
        } else {
            throw new Exception('No image data in response');
        }
    }
    
    // Handle API errors
    $errorData = json_decode($response, true);
    $errorMessage = 'Unknown API error';
    
    if ($errorData && isset($errorData['error']['message'])) {
        $errorMessage = $errorData['error']['message'];
    } elseif ($response) {
        $errorMessage = $response;
    }
    
    throw new Exception('OpenAI API error (HTTP ' . $httpCode . '): ' . $errorMessage);
    
} catch (Exception $e) {
    error_log('Image Generation Error: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
