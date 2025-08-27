# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Transcribomatic is a real-time speech transcription web application that uses OpenAI's Realtime API via WebRTC. It provides live audio-to-text conversion with AI-generated visual aids (pictograms) and accessibility features.

## Tech Stack

- **Frontend**: Pure HTML/CSS/JavaScript (no build system)
- **Backend**: PHP for API endpoints
- **APIs**: OpenAI Realtime API (WebRTC), OpenAI Images API (DALL-E)
- **Architecture**: Single Page Application with server-side proxy APIs

## Development Commands

This project has no build system. Development involves direct file editing:

- **Local Development**: Serve files from `public/` directory with PHP support
- **Testing**: Manual browser testing (requires HTTPS for microphone access)
- **Deployment**: Direct file copy to web server

## Project Structure

```
config/config.php          # API keys and HMAC secrets (not in git)
public/index.html          # Main SPA (~830 lines, contains all frontend code)
public/openai.php          # Session token generator with HMAC validation
public/generate_image.php  # DALL-E image generation endpoint
```

## Key Architecture Patterns

### Authentication & Security
- **HMAC Model Validation**: Models are pre-signed to prevent tampering
- **Ephemeal Session Tokens**: 60-second expiring tokens from server
- **API Key Proxy**: Server-side endpoints hide OpenAI keys from frontend
- **Model Whitelisting**: Only approved models (gpt-4o-realtime-preview) allowed

### Real-time Communication
- **WebRTC Data Channels**: Direct connection to OpenAI Realtime API
- **Server-side VAD**: Voice Activity Detection handled by OpenAI
- **JSON Message Protocol**: Events and responses via data channel
- **Auto-disconnect**: 1-minute activity timeout for resource management

### Frontend Architecture
- **Class-based Design**: `LiveTranscription` class manages all application state
- **Responsive UI**: CSS Grid/Flexbox with mobile viewport handling
- **Progressive Enhancement**: Graceful degradation for connection/API errors

## Configuration Requirements

### Required Setup
1. **OpenAI API Key**: Must have Realtime API access
2. **HTTPS Server**: Required for microphone access
3. **PHP Support**: For server-side API endpoints
4. **HMAC Secret**: For model signature validation

### Browser Requirements
- Modern browser with WebRTC support
- Microphone permissions required
- JavaScript enabled

## Important Implementation Details

### Session Token Generation
- Tokens expire in 60 seconds for security
- HMAC validation prevents model parameter tampering
- Generated server-side to protect API keys

### Audio Processing Flow
1. Browser captures microphone audio via WebRTC
2. Audio streams to OpenAI via data channel
3. Real-time transcription received via WebRTC events
4. Simplified utterances trigger DALL-E image generation
5. Images displayed as visual aids alongside transcript

### Error Handling
- Connection cleanup and restart capabilities
- Progressive error states with user feedback
- Server errors propagated to frontend with user-friendly messages

## Development Workflow

Since there's no build system, development is straightforward:
1. Edit files directly in the `public/` directory
2. Test changes by refreshing browser (must be HTTPS)
3. Monitor errors via browser console and PHP error logs
4. Deploy by copying files to production server

## Security Notes

- Never commit `config/config.php` (contains API keys)
- All API communication goes through server-side proxies
- Model parameters are HMAC-validated to prevent tampering
- CORS headers configured for legitimate API access only