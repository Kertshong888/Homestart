<?php
// ============================================================
// config.php
// Central configuration file - NEVER commit your real API key to Git
// Every file that needs the Google Maps key does: require_once 'config.php';
// ============================================================

// -----------------------------------------------------------
// Google Maps Distance Matrix API Key
// How to get one (free tier sufficient for testing):
//   1. Go to https://console.cloud.google.com/
//   2. Create a new project (e.g. "HomeStart Portal")
//   3. Navigate to APIs & Services > Library
//   4. Search for "Distance Matrix API" and click Enable
//   5. Go to APIs & Services > Credentials
//   6. Click Create Credentials > API Key
//   7. Copy the key and paste it below
//   8. Recommended: restrict the key to Distance Matrix API only
//      (Credentials > edit key > API restrictions)
//
// Free tier: 200 USD credit/month (~40,000 Distance Matrix requests)
// For testing this project that is more than enough.
// -----------------------------------------------------------
define('GOOGLE_MAPS_API_KEY', 'AIzaSyCb5rET0bj29cK-EaYRyeJqrVM8a4Ynx8U');

// -----------------------------------------------------------
// Cache directory for Google Maps API responses
// match.php stores responses here to avoid burning API quota.
// The directory is created automatically if it doesn't exist.
// -----------------------------------------------------------
define('CACHE_DIR', __DIR__ . '/cache/');

// How long (seconds) to reuse a cached travel-time result.
// 86400 = 24 hours. Travel times don't change minute to minute.
define('CACHE_TTL', 86400);
