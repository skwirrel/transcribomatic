<?php
/**
 * Authentication and Validation Functions
 * 
 * Shared functions for token validation and user management
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Generate a random URL-safe unique ID
 * @param int $length Length of the unique ID (default 10)
 * @return string URL-safe base64 encoded unique ID
 */
function generateUniqueId($length = 10) {
    $bytes = random_bytes(ceil($length * 3 / 4));
    return substr(rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='), 0, $length);
}

/**
 * Generate HMAC signature for a given value and context
 * @param string $value The value to sign
 * @param string $context The context (e.g., 'manage', 'user')
 * @return string HMAC signature
 */
function generateHmac($value, $context) {
    $secret = OPENAI_HMAC_SECRET . $context;
    return hash_hmac('sha256', $value, $secret);
}

/**
 * Validate token and extract unique ID
 * @param string $token Token in format uniqueId:hmac
 * @param string $context Context for HMAC validation ('manage' or 'user')
 * @return string|false Unique ID if valid, false otherwise
 * @throws Exception If weekly cost limit is exceeded
 */
function validateToken($token, $context) {
    if (empty($token)) {
        return false;
    }
    
    $parts = explode(':', $token);
    if (count($parts) !== 2) {
        return false;
    }
    
    list($uniqueId, $providedHmac) = $parts;
    
    $expectedHmac = generateHmac($uniqueId, $context);
    
    if (!hash_equals($expectedHmac, $providedHmac)) {
        return false;
    }
    
    // Check weekly cost limit for user tokens (not management tokens)
    if ($context === 'user') {
        $weeklySpend = getOptimizedWeeklyCost($uniqueId);
        if ($weeklySpend >= WEEKLY_COST_LIMIT) {
            throw new Exception("Weekly cost limit of $" . number_format(WEEKLY_COST_LIMIT, 2) . " exceeded. Current spend: $" . number_format($weeklySpend, 4));
        }
    }
    
    return $uniqueId;
}

/**
 * Get database connection
 * @return PDO Database connection
 * @throws Exception If connection fails
 */
function getDbConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }
    
    return $pdo;
}

/**
 * Get user configuration from database
 * @param string $uniqueId User's unique ID
 * @return array|false User configuration or false if not found
 */
function getUserConfig($uniqueId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM Users WHERE uniqueId = ? AND enabled = 1");
        $stmt->execute([$uniqueId]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log('Error getting user config: ' . $e->getMessage());
        return false;
    }
}

/**
 * Create or update user configuration
 * @param string $uniqueId User's unique ID
 * @param bool $showTranscription Show transcription setting
 * @param bool $showParalanguage Show paralanguage context setting
 * @param bool $showImage Show image setting
 * @return bool Success status
 */
function saveUserConfig($uniqueId, $showTranscription, $showParalanguage, $showImage) {
    try {
        $pdo = getDbConnection();
        
        // Check if user exists
        $stmt = $pdo->prepare("SELECT id FROM Users WHERE uniqueId = ?");
        $stmt->execute([$uniqueId]);
        $exists = $stmt->fetch();
        
        if ($exists) {
            // Update existing user
            $stmt = $pdo->prepare("UPDATE Users SET showTranscription = ?, showParalanguage = ?, showImage = ?, updatedAt = UNIX_TIMESTAMP() WHERE uniqueId = ?");
            $stmt->execute([$showTranscription ? 1 : 0, $showParalanguage ? 1 : 0, $showImage ? 1 : 0, $uniqueId]);
        } else {
            // Create new user
            $stmt = $pdo->prepare("INSERT INTO Users (uniqueId, showTranscription, showParalanguage, showImage, enabled, createdAt, updatedAt) VALUES (?, ?, ?, ?, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())");
            $stmt->execute([$uniqueId, $showTranscription ? 1 : 0, $showParalanguage ? 1 : 0, $showImage ? 1 : 0]);
        }
        
        return true;
    } catch (Exception $e) {
        error_log('Error saving user config: ' . $e->getMessage());
        return false;
    }
}

/**
 * Log API usage for tracking
 * @param string $uniqueId User's unique ID
 * @param string $action Action type ('login', 'picture', 'transcription')
 * @param string $details Details about the action (empty for login, text content for picture/transcription)
 * @return bool Success status
 */
function logApiUsage($uniqueId, $action, $details = '') {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO ApiUsage (uniqueId, action, details, createdAt) VALUES (?, ?, ?, UNIX_TIMESTAMP())");
        $stmt->execute([$uniqueId, $action, $details]);
        return true;
    } catch (Exception $e) {
        error_log('Error logging API usage: ' . $e->getMessage());
        return false;
    }
}

/**
 * Log detailed token usage from OpenAI response
 * @param string $uniqueId User's unique ID
 * @param array $usage Usage data from OpenAI response
 * @return bool Success status
 */
function logTokenUsage($uniqueId, $usage) {
    try {
        $pdo = getDbConnection();
        
        // Extract usage data with defaults
        $totalTokens = $usage['total_tokens'] ?? 0;
        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;
        $cachedTokens = $usage['input_token_details']['cached_tokens'] ?? 0;
        $inputTextTokens = $usage['input_token_details']['text_tokens'] ?? 0;
        $inputAudioTokens = $usage['input_token_details']['audio_tokens'] ?? 0;
        $cachedTextTokens = $usage['input_token_details']['cached_tokens_details']['text_tokens'] ?? 0;
        $cachedAudioTokens = $usage['input_token_details']['cached_tokens_details']['audio_tokens'] ?? 0;
        $outputTextTokens = $usage['output_token_details']['text_tokens'] ?? 0;
        $outputAudioTokens = $usage['output_token_details']['audio_tokens'] ?? 0;
        
        $stmt = $pdo->prepare("INSERT INTO UsageLog (uniqueId, totalTokens, inputTokens, outputTokens, cachedTokens, inputTextTokens, inputAudioTokens, cachedTextTokens, cachedAudioTokens, outputTextTokens, outputAudioTokens, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP())");
        
        $stmt->execute([
            $uniqueId,
            $totalTokens,
            $inputTokens,
            $outputTokens,
            $cachedTokens,
            $inputTextTokens,
            $inputAudioTokens,
            $cachedTextTokens,
            $cachedAudioTokens,
            $outputTextTokens,
            $outputAudioTokens
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log('Error logging token usage: ' . $e->getMessage());
        return false;
    }
}

/**
 * Calculate the total cost for a user since a reference date
 * @param string $uniqueId User's unique ID
 * @param int $sinceTimestamp UNIX timestamp to calculate costs from
 * @return float Total cost in dollars
 */
function calculateCostSince($uniqueId, $sinceTimestamp) {
    try {
        $pdo = getDbConnection();
        
        // Calculate cost from token usage (UsageLog table)
        $tokenCostQuery = "
            SELECT 
                COALESCE(SUM(
                    -- Text token costs
                    (inputTextTokens * " . OPENAI_COST_TEXT_INPUT . " / 1000000) +
                    (cachedTextTokens * " . OPENAI_COST_TEXT_CACHED . " / 1000000) +
                    (outputTextTokens * " . OPENAI_COST_TEXT_OUTPUT . " / 1000000) +
                    -- Audio token costs
                    (inputAudioTokens * " . OPENAI_COST_AUDIO_INPUT . " / 1000000) +
                    (cachedAudioTokens * " . OPENAI_COST_AUDIO_CACHED . " / 1000000) +
                    (outputAudioTokens * " . OPENAI_COST_AUDIO_OUTPUT . " / 1000000)
                ), 0) as tokenCost
            FROM UsageLog 
            WHERE uniqueId = ? AND createdAt >= ?
        ";
        
        $stmt = $pdo->prepare($tokenCostQuery);
        $stmt->execute([$uniqueId, $sinceTimestamp]);
        $tokenCost = $stmt->fetchColumn() ?: 0;
        
        // Calculate cost from image generation (ApiUsage table)
        $imageCostQuery = "
            SELECT COUNT(*) as imageCount
            FROM ApiUsage 
            WHERE uniqueId = ? AND action = 'picture' AND createdAt >= ?
        ";
        
        $stmt = $pdo->prepare($imageCostQuery);
        $stmt->execute([$uniqueId, $sinceTimestamp]);
        $imageCount = $stmt->fetchColumn() ?: 0;
        $imageCost = $imageCount * OPENAI_COST_IMAGE;
        
        return (float)($tokenCost + $imageCost);
        
    } catch (Exception $e) {
        error_log('Error calculating cost since ' . $sinceTimestamp . ': ' . $e->getMessage());
        return 0; // Return 0 on error to avoid blocking users
    }
}

/**
 * Calculate the total cost for a user in the last 7 days
 * @param string $uniqueId User's unique ID
 * @return float Total cost in dollars
 */
function calculateWeeklyCost($uniqueId) {
    $weekAgo = time() - (7 * 24 * 60 * 60); // 7 days ago in UNIX timestamp
    return calculateCostSince($uniqueId, $weekAgo);
}

/**
 * Get detailed cost breakdown for a user in the last 7 days
 * @param string $uniqueId User's unique ID
 * @return array Cost breakdown
 */
function getWeeklyCostBreakdown($uniqueId) {
    try {
        $pdo = getDbConnection();
        
        $weekAgo = time() - (7 * 24 * 60 * 60); // 7 days ago in UNIX timestamp
        
        // Get token usage breakdown
        $tokenQuery = "
            SELECT 
                COALESCE(SUM(inputTextTokens), 0) as inputTextTokens,
                COALESCE(SUM(cachedTextTokens), 0) as cachedTextTokens,
                COALESCE(SUM(outputTextTokens), 0) as outputTextTokens,
                COALESCE(SUM(inputAudioTokens), 0) as inputAudioTokens,
                COALESCE(SUM(cachedAudioTokens), 0) as cachedAudioTokens,
                COALESCE(SUM(outputAudioTokens), 0) as outputAudioTokens
            FROM UsageLog 
            WHERE uniqueId = ? AND createdAt >= ?
        ";
        
        $stmt = $pdo->prepare($tokenQuery);
        $stmt->execute([$uniqueId, $weekAgo]);
        $tokens = $stmt->fetch();
        
        // Get image count
        $imageQuery = "
            SELECT COUNT(*) as imageCount
            FROM ApiUsage 
            WHERE uniqueId = ? AND action = 'picture' AND createdAt >= ?
        ";
        
        $stmt = $pdo->prepare($imageQuery);
        $stmt->execute([$uniqueId, $weekAgo]);
        $imageCount = $stmt->fetchColumn() ?: 0;
        
        // Calculate costs
        $costs = [
            'textInputCost' => ($tokens['inputTextTokens'] * OPENAI_COST_TEXT_INPUT) / 1000000,
            'textCachedCost' => ($tokens['cachedTextTokens'] * OPENAI_COST_TEXT_CACHED) / 1000000,
            'textOutputCost' => ($tokens['outputTextTokens'] * OPENAI_COST_TEXT_OUTPUT) / 1000000,
            'audioInputCost' => ($tokens['inputAudioTokens'] * OPENAI_COST_AUDIO_INPUT) / 1000000,
            'audioCachedCost' => ($tokens['cachedAudioTokens'] * OPENAI_COST_AUDIO_CACHED) / 1000000,
            'audioOutputCost' => ($tokens['outputAudioTokens'] * OPENAI_COST_AUDIO_OUTPUT) / 1000000,
            'imageCost' => $imageCount * OPENAI_COST_IMAGE,
            'imageCount' => $imageCount,
            'tokens' => $tokens
        ];
        
        $costs['totalCost'] = array_sum(array_slice($costs, 0, 7)); // Sum all cost fields except counts/tokens
        
        return $costs;
        
    } catch (Exception $e) {
        error_log('Error getting cost breakdown: ' . $e->getMessage());
        return ['totalCost' => 0];
    }
}

/**
 * Get transcription word count statistics for a user in the last 7 days
 * @param string $uniqueId User's unique ID
 * @return array Word count statistics
 */
function getWeeklyWordStats($uniqueId) {
    try {
        $pdo = getDbConnection();
        $weekAgo = time() - (7 * 24 * 60 * 60);
        
        $query = "
            SELECT 
                COUNT(*) as transcriptionCount,
                COALESCE(SUM(CAST(details AS UNSIGNED)), 0) as totalWords,
                COALESCE(AVG(CAST(details AS UNSIGNED)), 0) as avgWordsPerTranscription
            FROM ApiUsage 
            WHERE uniqueId = ? AND action = 'transcription' AND createdAt >= ?
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$uniqueId, $weekAgo]);
        
        return $stmt->fetch();
        
    } catch (Exception $e) {
        error_log('Error getting word stats: ' . $e->getMessage());
        return ['transcriptionCount' => 0, 'totalWords' => 0, 'avgWordsPerTranscription' => 0];
    }
}

/**
 * Get the last UsageCost entry timestamp for a user
 * @param string $uniqueId User's unique ID
 * @return int|null Last UsageCost timestamp or null if no entries
 */
function getLastUsageCostTimestamp($uniqueId) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT MAX(createdAt) FROM UsageCost WHERE uniqueId = ?");
        $stmt->execute([$uniqueId]);
        $result = $stmt->fetchColumn();
        return $result ? (int)$result : null;
    } catch (Exception $e) {
        error_log('Error getting last usage cost timestamp: ' . $e->getMessage());
        return null;
    }
}

/**
 * Add a new UsageCost entry
 * @param string $uniqueId User's unique ID
 * @param float $cost Cost amount
 * @return bool Success status
 */
function addUsageCost($uniqueId, $cost) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("INSERT INTO UsageCost (uniqueId, cost, createdAt) VALUES (?, ?, UNIX_TIMESTAMP())");
        $stmt->execute([$uniqueId, $cost]);
        return true;
    } catch (Exception $e) {
        error_log('Error adding usage cost: ' . $e->getMessage());
        return false;
    }
}

/**
 * Update cost tracking for a user on login
 * Creates a new UsageCost entry if more than 7 days have passed since the last one
 * @param string $uniqueId User's unique ID
 * @return bool Success status
 */
function updateCostTracking($uniqueId) {
    try {
        $lastCostTimestamp = getLastUsageCostTimestamp($uniqueId);
        $currentTime = time();
        $weekAgo = $currentTime - (7 * 24 * 60 * 60);
        
        // If no previous cost entry or last entry is older than 7 days
        if ($lastCostTimestamp === null || $lastCostTimestamp < $weekAgo) {
            // Calculate cost since last entry (or since beginning if no entry)
            $sinceTimestamp = $lastCostTimestamp ?? 0;
            $costSinceLastEntry = calculateCostSince($uniqueId, $sinceTimestamp);
            
            // Only create entry if there's actual cost
            if ($costSinceLastEntry > 0) {
                return addUsageCost($uniqueId, $costSinceLastEntry);
            }
        }
        
        return true; // No update needed
    } catch (Exception $e) {
        error_log('Error updating cost tracking: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get optimized weekly cost using UsageCost table for efficiency
 * This maintains the exact 7-day window while leveraging UsageCost for optimization
 * @param string $uniqueId User's unique ID
 * @return float Total cost in last 7 days
 */
function getOptimizedWeeklyCost($uniqueId) {
    try {
        // For now, just use the standard weekly cost calculation
        // The UsageCost table is mainly for historical tracking and reporting
        // The weekly limit check needs to be precise to exactly 7 days
        return calculateWeeklyCost($uniqueId);
        
        // Future optimization: We could use UsageCost entries to avoid recalculating
        // very old data, but for a 7-day window with proper indexes, the current
        // approach is fast enough and guarantees accuracy
        
    } catch (Exception $e) {
        error_log('Error getting optimized weekly cost: ' . $e->getMessage());
        return calculateWeeklyCost($uniqueId);
    }
}