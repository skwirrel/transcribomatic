<?php
/**
 * OpenAI Session Key Generator
 * 
 * Generates ephemeral session tokens for OpenAI Realtime API
 * Called by LanguageTutor class for authentication
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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
    
    // Handle login mode
    if (isset($_GET['mode']) && $_GET['mode'] === 'login') {
        handleLogin();
        exit;
    }
    
    // Handle usage logging mode
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $requestData = json_decode(file_get_contents('php://input'), true);
        if (isset($requestData['mode']) && $requestData['mode'] === 'log_usage') {
            handleUsageLogging($requestData);
            exit;
        }
    }
    
    // Get and validate model parameter
    $model = 'gpt-4o-realtime-preview'; // Default model
    $requestData = null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $requestData = json_decode(file_get_contents('php://input'), true);
        if (isset($requestData['model'])) {
            $model = validateAndExtractModel($requestData['model']);
        }
    } elseif (isset($_GET['model'])) {
        $model = validateAndExtractModel($_GET['model']);
    }
    
    if (!defined('OPENAI_API_KEY') || empty(OPENAI_API_KEY)) {
        throw new Exception('OPENAI_API_KEY not defined in config.php');
    }
    
    // Build headers with optional organization and project IDs
    $headers = [
        'Authorization: Bearer ' . OPENAI_API_KEY,
        'Content-Type: application/json',
        'OpenAI-Beta: realtime=v1'
    ];
    
    // Add optional OpenAI organization ID if defined
    if (defined('OPENAI_ORG_ID') && !empty(OPENAI_ORG_ID)) {
        $headers[] = 'OpenAI-Organization: ' . OPENAI_ORG_ID;
    }
    
    // Add optional OpenAI project ID if defined
    if (defined('OPENAI_PROJECT_ID') && !empty(OPENAI_PROJECT_ID)) {
        $headers[] = 'OpenAI-Project: ' . OPENAI_PROJECT_ID;
    }
    
    // Handle transcription models differently
    if ($model === 'gpt-4o-transcribe') {
        $data = createTranscriptionSession($headers);
        
        if (isset($data['client_secret']['value'])) {
            echo json_encode([
                'session_token' => $data['client_secret']['value'],
                'session_type' => 'transcription',
                'expires_in' => 60,
                'generated_at' => time()
            ]);
            exit;
        } else {
            throw new Exception('No client_secret found in transcription session response');
        }
    }
    
    // Generate session token for regular Realtime API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/realtime/sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => $model,
        'voice' => 'alloy'
    ]));
    
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
        
        // Check if we got a client_secret or if we should use the API key directly
        if (isset($data['client_secret']['value'])) {
            echo json_encode([
                'session_token' => $data['client_secret']['value'],
                'session_type' => 'realtime',
                'expires_in' => 60, // Session tokens expire in 60 seconds
                'generated_at' => time()
            ]);
            exit;
        }
    }
    // Try to parse error response
    $errorData = json_decode($response, true);
    $errorMessage = 'Unknown API error';
    
    if ($errorData && isset($errorData['error']['message'])) {
        $errorMessage = $errorData['error']['message'];
    } elseif ($response) {
        $errorMessage = $response;
    }
    
    throw new Exception('OpenAI API error (HTTP ' . $httpCode . '): ' . $errorMessage);
    
} catch (Exception $e) {
    error_log('OpenAI Key Generator Error: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'timestamp' => time()
    ]);
}

/**
 * Validates HMAC signature and extracts the model name
 * @param string $signedModel The model name with HMAC signature appended
 * @return string The validated model name
 * @throws Exception If validation fails
 */
function validateAndExtractModel($signedModel) {
    if (!defined('OPENAI_HMAC_SECRET') || empty(OPENAI_HMAC_SECRET)) {
        throw new Exception('HMAC secret not configured');
    }
    
    // Split the signed model to get model and signature
    $parts = explode('.', $signedModel);
    if (count($parts) !== 2) {
        throw new Exception('Invalid signed model format');
    }
    
    list($model, $signature) = $parts;
    
    // Verify HMAC signature
    $expectedSignature = hash_hmac('sha256', $model, OPENAI_HMAC_SECRET);
    
    if (!hash_equals($expectedSignature, $signature)) {
        throw new Exception('Invalid model signature');
    }
    
    // Whitelist allowed models for security
    $allowedModels = [
        'gpt-4o-mini-realtime-preview',
        'gpt-4o-realtime-preview-2024-10-01',
        'gpt-4o-realtime-preview-2024-12-17',
        'gpt-4o-realtime-preview',
        'gpt-4o-transcribe'
    ];
    
    if (!in_array($model, $allowedModels)) {
        throw new Exception('Model not allowed: ' . $model);
    }
    
    return $model;
}

/**
 * Create a transcription session for gpt-4o-transcribe model
 * @param array $headers The headers to use for the request
 * @return array The transcription session response
 * @throws Exception If the request fails
 */
function createTranscriptionSession($headers) {
    $transcriptionData = [
        'input_audio_format' => 'pcm16',
        'input_audio_transcription' => [
            'model' => 'gpt-4o-transcribe',
            'language' => 'en',
            'prompt' => 'Transcribe speech accurately, including proper punctuation and capitalization.'
        ],
        'turn_detection' => [
            'type' => 'server_vad',
            'threshold' => 0.6,
            'prefix_padding_ms' => 300,
            'silence_duration_ms' => 800
        ]
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/realtime/transcription_sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($transcriptionData));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception('cURL error: ' . $error);
    }
    
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = 'Unknown API error';
        
        if ($errorData && isset($errorData['error']['message'])) {
            $errorMessage = $errorData['error']['message'];
        } elseif ($response) {
            $errorMessage = $response;
        }
        
        throw new Exception('OpenAI API error (HTTP ' . $httpCode . '): ' . $errorMessage);
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON response from transcription sessions API');
    }
    
    return $data;
}

/**
 * Handle login authentication
 */
function handleLogin() {
    try {
        $token = $_GET['token'] ?? '';
        
        if (empty($token)) {
            http_response_code(400);
            echo json_encode(['error' => 'Token required']);
            return;
        }
        
        $uniqueId = validateToken($token, 'user');
        
        if (!$uniqueId) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid or expired token']);
            return;
        }
        
        $userConfig = getUserConfig($uniqueId);
        
        if (!$userConfig) {
            http_response_code(404);
            echo json_encode(['error' => 'User not found or disabled']);
            return;
        }
        
        // Log login attempt
        logApiUsage($uniqueId, 'login');
        
        // Update cost tracking (create UsageCost entry if needed)
        updateCostTracking($uniqueId);
        
        // Return success with user configuration
        echo json_encode([
            'success' => true,
            'config' => [
                'showTranscription' => (bool)$userConfig['showTranscription'],
                'showParalanguage' => (bool)$userConfig['showParalanguage'],
                'showImage' => (bool)$userConfig['showImage']
            ]
        ]);
        
    } catch (Exception $e) {
        error_log('Login error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error']);
    }
}

/**
 * Handle usage data logging
 */
function handleUsageLogging($requestData) {
    try {
        $token = $requestData['token'] ?? '';
        $usage = $requestData['usage'] ?? null;
        $wordCount = $requestData['wordCount'] ?? 0;
        
        if (empty($token)) {
            http_response_code(400);
            echo json_encode(['error' => 'Token required']);
            return;
        }
        
        if (empty($usage)) {
            http_response_code(400);
            echo json_encode(['error' => 'Usage data required']);
            return;
        }
        
        $uniqueId = validateToken($token, 'user');
        
        if (!$uniqueId) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid or expired token']);
            return;
        }
        
        // Log the detailed token usage
        $tokenLogged = logTokenUsage($uniqueId, $usage);
        
        // Log the transcription event with word count (if word count > 0)
        $transcriptionLogged = true;
        if ($wordCount > 0) {
            $transcriptionLogged = logApiUsage($uniqueId, 'transcription', (string)$wordCount);
        }
        
        if ($tokenLogged && $transcriptionLogged) {
            echo json_encode(['success' => true]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to log usage data']);
        }
        
    } catch (Exception $e) {
        error_log('Usage logging error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error']);
    }
}
