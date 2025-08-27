-- Transcribomatic Database Schema
-- Run this to create the required database tables

CREATE DATABASE IF NOT EXISTS transcribomatic;
USE transcribomatic;

-- Users table for configuration settings
CREATE TABLE IF NOT EXISTS Users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uniqueId VARCHAR(10) NOT NULL UNIQUE,
    showTranscription BOOLEAN DEFAULT TRUE,
    showParalanguage BOOLEAN DEFAULT TRUE,
    showImage BOOLEAN DEFAULT TRUE,
    enabled BOOLEAN DEFAULT TRUE,
    createdAt INT UNSIGNED DEFAULT (UNIX_TIMESTAMP()),
    updatedAt INT UNSIGNED DEFAULT (UNIX_TIMESTAMP()),
    INDEX idx_uniqueId (uniqueId),
    INDEX idx_enabled (enabled)
);

-- API Usage logging table
CREATE TABLE IF NOT EXISTS ApiUsage (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uniqueId VARCHAR(10) NOT NULL,
    action VARCHAR(50) NOT NULL,
    details TEXT,
    createdAt INT UNSIGNED DEFAULT (UNIX_TIMESTAMP()),
    INDEX idx_uniqueId_createdAt (uniqueId, createdAt),
    INDEX idx_uniqueId_action_createdAt (uniqueId, action, createdAt),
    INDEX idx_createdAt (createdAt),
    FOREIGN KEY (uniqueId) REFERENCES Users(uniqueId) ON DELETE CASCADE
);

-- Detailed usage tracking table for token usage
CREATE TABLE IF NOT EXISTS UsageLog (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uniqueId VARCHAR(10) NOT NULL,
    totalTokens INT DEFAULT 0,
    inputTokens INT DEFAULT 0,
    outputTokens INT DEFAULT 0,
    cachedTokens INT DEFAULT 0,
    inputTextTokens INT DEFAULT 0,
    inputAudioTokens INT DEFAULT 0,
    cachedTextTokens INT DEFAULT 0,
    cachedAudioTokens INT DEFAULT 0,
    outputTextTokens INT DEFAULT 0,
    outputAudioTokens INT DEFAULT 0,
    createdAt INT UNSIGNED DEFAULT (UNIX_TIMESTAMP()),
    INDEX idx_uniqueId_createdAt (uniqueId, createdAt),
    INDEX idx_createdAt (createdAt),
    FOREIGN KEY (uniqueId) REFERENCES Users(uniqueId) ON DELETE CASCADE
);

-- Cost tracking ledger table
CREATE TABLE IF NOT EXISTS UsageCost (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uniqueId VARCHAR(10) NOT NULL,
    cost DECIMAL(10,6) NOT NULL DEFAULT 0,
    createdAt INT UNSIGNED DEFAULT (UNIX_TIMESTAMP()),
    INDEX idx_uniqueId_createdAt (uniqueId, createdAt),
    INDEX idx_createdAt (createdAt),
    FOREIGN KEY (uniqueId) REFERENCES Users(uniqueId) ON DELETE CASCADE
);