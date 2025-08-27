<?php

define('OPENAI_API_KEY', 'your-openai-api-key-here');
define('OPENAI_HMAC_SECRET', 'your-secret-signing-key-here');

// Domain and path configuration
define('BASE_URL', 'https://your-domain.com/transcribomatic');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'transcribomatic');
define('DB_USER', 'transcribomatic_user');
define('DB_PASS', 'your-database-password');

// OpenAI API Costs (per 1M tokens, except images which are per image)
define('OPENAI_COST_TEXT_INPUT', 5.00);      // $5.00 / 1M input tokens
define('OPENAI_COST_TEXT_CACHED', 2.50);     // $2.50 / 1M cached input tokens  
define('OPENAI_COST_TEXT_OUTPUT', 20.00);    // $20.00 / 1M output tokens
define('OPENAI_COST_AUDIO_INPUT', 40.00);    // $40.00 / 1M input tokens
define('OPENAI_COST_AUDIO_CACHED', 2.50);    // $2.50 / 1M cached input tokens
define('OPENAI_COST_AUDIO_OUTPUT', 80.00);   // $80.00 / 1M output tokens
define('OPENAI_COST_IMAGE', 0.011);          // $0.011 per image

// Weekly cost limit
define('WEEKLY_COST_LIMIT', 2.00);           // $2.00 weekly limit per user