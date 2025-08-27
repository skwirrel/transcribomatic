<?php
/**
 * User Management Interface
 * 
 * Allows users to configure their transcribomatic settings
 */

require_once '../lib/auth.php';

header('Content-Type: text/html; charset=utf-8');

$error = '';
$success = '';
$uniqueId = '';
$userConfig = null;
$userToken = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mode']) && $_POST['mode'] === 'update') {
    $token = $_POST['token'] ?? '';
    $uniqueId = validateToken($token, 'manage');
    
    if ($uniqueId) {
        $showTranscription = isset($_POST['showTranscription']) ? true : false;
        $showParalanguage = isset($_POST['showParalanguage']) ? true : false;
        $showImage = isset($_POST['showImage']) ? true : false;
        
        if (saveUserConfig($uniqueId, $showTranscription, $showParalanguage, $showImage)) {
            $success = 'Configuration saved successfully!';
            $userConfig = getUserConfig($uniqueId);
            
            // Log the configuration update
            $configDetails = sprintf(
                'Config updated: transcription=%s, paralanguage=%s, image=%s',
                $showTranscription ? 'enabled' : 'disabled',
                $showParalanguage ? 'enabled' : 'disabled',
                $showImage ? 'enabled' : 'disabled'
            );
            logApiUsage($uniqueId, 'manage', $configDetails);
        } else {
            $error = 'Failed to save configuration. Please try again.';
        }
    } else {
        $error = 'Invalid or expired token.';
    }
}

// Validate token from URL
if (empty($error) && isset($_GET['token'])) {
    $token = $_GET['token'];
    $uniqueId = validateToken($token, 'manage');
    
    if ($uniqueId) {
        // Get existing user config
        $userConfig = getUserConfig($uniqueId);
        
        // If user doesn't exist, create them with default settings
        if ($userConfig === false) {
            if (saveUserConfig($uniqueId, true, true, true)) {
                $userConfig = getUserConfig($uniqueId);
                $success = 'New user account created with default settings.';
                // Log the user creation
                logApiUsage($uniqueId, 'manage', 'User account created');
            } else {
                $error = 'Failed to create user account. Please try again.';
            }
        } else {
            // Log management page access for existing user
            logApiUsage($uniqueId, 'manage', 'Management page accessed');
        }
        
        // Generate user token for display
        $userHmac = generateHmac($uniqueId, 'user');
        $userToken = $uniqueId . ':' . $userHmac;
    } else {
        $error = 'Invalid or expired management token.';
    }
} elseif (empty($error) && empty($_POST)) {
    $error = 'No management token provided.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transcribomatic - Account Management</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .error {
            background-color: #fee;
            color: #c33;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #fcc;
        }
        .success {
            background-color: #efe;
            color: #363;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #cfc;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        .checkbox-group input[type="checkbox"] {
            margin-right: 10px;
            scale: 1.2;
            margin-top: 0;
            vertical-align: middle;
        }
        .checkbox-group label {
            margin-bottom: 0;
            line-height: 1.4;
            display: flex;
            align-items: center;
        }
        .user-link {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            border: 1px solid #dee2e6;
        }
        .user-link strong {
            color: #495057;
        }
        .user-link code {
            background-color: white;
            padding: 8px;
            border-radius: 3px;
            display: block;
            margin-top: 10px;
            word-break: break-all;
            font-size: 14px;
        }
        .url-container {
            display: block;
            width: 100%;
        }
        .copy-button {
            background: #007bff;
            color: white;
            border: none;
            border-radius: 3px;
            padding: 6px 12px;
            cursor: pointer;
            font-size: 12px;
            transition: background-color 0.2s;
            margin-top: 8px;
            display: inline-block;
        }
        .copy-button:hover {
            background: #0056b3;
        }
        .copy-message {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #28a745;
            color: white;
            padding: 12px 24px;
            border-radius: 5px;
            font-size: 14px;
            z-index: 1000;
            display: none;
            animation: fadeInOut 2s ease-in-out;
        }
        @keyframes fadeInOut {
            0% { opacity: 0; }
            20% { opacity: 1; }
            80% { opacity: 1; }
            100% { opacity: 0; }
        }
        button {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: #0056b3;
        }
        .info {
            background-color: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #b8daff;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        h2 {
            color: #555;
            margin-bottom: 15px;
            margin-top: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Transcribomatic Account Management</h1>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <?php if ($uniqueId && empty($error)): ?>
            <div class="info">
                <strong>User ID:</strong> <?php echo htmlspecialchars($uniqueId); ?>
            </div>
            
            <form method="POST">
                <input type="hidden" name="mode" value="update">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token'] ?? $_POST['token'] ?? ''); ?>">
                
                <h2>Configuration Options</h2>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="showTranscription" name="showTranscription" 
                               <?php echo ($userConfig === false || $userConfig['showTranscription']) ? 'checked' : ''; ?>>
                        <label for="showTranscription">Show transcription</label>
                    </div>
                    <small>Display real-time speech-to-text transcription</small>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="showParalanguage" name="showParalanguage" 
                               <?php echo ($userConfig === false || $userConfig['showParalanguage']) ? 'checked' : ''; ?>>
                        <label for="showParalanguage">Show paralanguage context</label>
                    </div>
                    <small>Display emotional and contextual information about speech</small>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="showImage" name="showImage" 
                               <?php echo ($userConfig === false || $userConfig['showImage']) ? 'checked' : ''; ?>>
                        <label for="showImage">Show visual aids</label>
                    </div>
                    <small>Generate AI pictograms to illustrate key concepts</small>
                </div>
                
                <button type="submit">Save Configuration</button>
            </form>
            
            <h2>Personal Access Link</h2>
            <div class="user-link">
                <strong>Give this URL to anyone who will use your Transcribomatic account:</strong>
                <div class="url-container">
                    <code id="userUrl"><?php echo BASE_URL; ?>/?token=<?php echo urlencode($userToken); ?></code>
                    <br>
                    <button class="copy-button" onclick="copyToClipboard()">📋 Copy URL</button>
                </div>
            </div>
            
        <?php endif; ?>
    </div>
    
    <div id="copyMessage" class="copy-message">URL copied to clipboard!</div>
    
    <script>
        async function copyToClipboard() {
            const urlElement = document.getElementById('userUrl');
            const url = urlElement.textContent;
            
            try {
                await navigator.clipboard.writeText(url);
                showCopyMessage();
            } catch (err) {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = url;
                document.body.appendChild(textArea);
                textArea.select();
                textArea.setSelectionRange(0, 99999);
                document.execCommand('copy');
                document.body.removeChild(textArea);
                showCopyMessage();
            }
        }
        
        function showCopyMessage() {
            const message = document.getElementById('copyMessage');
            message.style.display = 'block';
            setTimeout(() => {
                message.style.display = 'none';
            }, 2000);
        }
    </script>
</body>
</html>