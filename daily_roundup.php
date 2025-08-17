<?php
/**
 * Kiffmeister's Daily Raindrop to Blogger Roundup (OAuth Version)
 * Fetches bookmarks with #DFC tag and publishes to Blogger
 */

// ========================================
// CONFIGURATION - UPDATE THESE VALUES
// ========================================

// Raindrop.io credentials
define('RAINDROP_ACCESS_TOKEN', getenv('RAINDROP_ACCESS_TOKEN') ?: 'your_access_token_here');

// Blogger OAuth credentials
define('BLOGGER_CLIENT_ID', getenv('BLOGGER_CLIENT_ID') ?: 'your_client_id_here');
define('BLOGGER_CLIENT_SECRET', getenv('BLOGGER_CLIENT_SECRET') ?: 'your_client_secret_here');
define('BLOGGER_REFRESH_TOKEN', getenv('BLOGGER_REFRESH_TOKEN') ?: 'your_refresh_token_here');
define('BLOGGER_BLOG_ID', '8452170067331693828');

// Roundup settings
define('TARGET_TAG', 'DFC');
define('POST_TITLE', "Kiffmeister's Global Digital Money News Digest");

// File to track last run date
define('LAST_RUN_FILE', 'last_roundup_date.txt');

// ========================================
// FUNCTIONS
// ========================================

/**
 * Fetch bookmarks from Raindrop.io with specific tag
 */
function fetchRaindropBookmarks($tag, $since_date = null) {
    $access_token = RAINDROP_ACCESS_TOKEN;
    
    // Build API URL
    $url = 'https://api.raindrop.io/rest/v1/raindrops/0'; // 0 = all collections
    
    // Add search parameter for tag
    $params = [
        'search' => '#' . $tag,
        'perpage' => 50
    ];
    
    $url .= '?' . http_build_query($params);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        throw new Exception("Raindrop API error ($http_code): $response");
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['items'])) {
        return [];
    }
    
    // Filter by date if specified
    if ($since_date) {
        $since_timestamp = strtotime($since_date);
        $data['items'] = array_filter($data['items'], function($item) use ($since_timestamp) {
            $created_timestamp = strtotime($item['created']);
            return $created_timestamp >= $since_timestamp;
        });
    }
    
    return $data['items'];
}

/**
 * Format bookmarks for Blogger post (HTML)
 */
function formatBookmarksForBlogger($bookmarks) {
    if (empty($bookmarks)) {
        return '';
    }
    
    $content = "<p>Here are today's curated links on digital finance and CBDCs:</p>\n\n";
    
    foreach ($bookmarks as $bookmark) {
        $title = htmlspecialchars($bookmark['title']);
        $url = htmlspecialchars($bookmark['link']);
        $note = !empty($bookmark['note']) ? htmlspecialchars($bookmark['note']) : '';
        
        $content .= "<h3><a href=\"{$url}\" target=\"_blank\">{$title}</a></h3>\n";
        
        if ($note) {
            $content .= "<p>{$note}</p>\n";
        }
        
        $content .= "\n";
    }
    
    $content .= "<hr>\n<p><em>Curated from my <a href=\"https://raindrop.io\" target=\"_blank\">Raindrop.io</a> bookmarks</em></p>";
    
    return $content;
}

/**
 * Get fresh access token using refresh token
 */
function getBloggerAccessToken() {
    $token_url = 'https://oauth2.googleapis.com/token';
    
    $token_data = [
        'client_id' => BLOGGER_CLIENT_ID,
        'client_secret' => BLOGGER_CLIENT_SECRET,
        'refresh_token' => BLOGGER_REFRESH_TOKEN,
        'grant_type' => 'refresh_token'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $token_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($token_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        throw new Exception("OAuth token refresh failed ($http_code): $response");
    }
    
    $token_data = json_decode($response, true);
    
    if (!isset($token_data['access_token'])) {
        throw new Exception("No access token in response: $response");
    }
    
    return $token_data['access_token'];
}

/**
 * Test Blogger OAuth connection
 */
function testBloggerConnection() {
    try {
        echo "Testing Blogger OAuth connection...\n";
        
        // Get access token
        $access_token = getBloggerAccessToken();
        echo "✅ OAuth token refreshed successfully\n";
        
        // Test by getting blog info
        $api_url = "https://www.googleapis.com/blogger/v3/blogs/" . BLOGGER_BLOG_ID;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'User-Agent: Daily-Roundup-Bot/1.0'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("Connection error: $error");
        }
        
        if ($http_code !== 200) {
            throw new Exception("Blogger API error ($http_code): $response");
        }
        
        $blog_data = json_decode($response, true);
        
        if (isset($blog_data['name'])) {
            echo "✅ Connected to blog: " . $blog_data['name'] . "\n";
            return true;
        } else {
            throw new Exception("Invalid blog response");
        }
        
    } catch (Exception $e) {
        echo "❌ Blogger connection test failed: " . $e->getMessage() . "\n";
        return false;
    }
}

/**
 * Publish post to Blogger using OAuth
 */
function publishToBlogger($title, $content) {
    // Get fresh access token
    $access_token = getBloggerAccessToken();
    
    $api_url = "https://www.googleapis.com/blogger/v3/blogs/" . BLOGGER_BLOG_ID . "/posts";
    
    $post_data = [
        'title' => $title,
        'content' => $content
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token,
        'User-Agent: Daily-Roundup-Bot/1.0'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("Connection error: $error");
    }
    
    if ($http_code !== 200 && $http_code !== 201) {
        throw new Exception("Blogger API error ($http_code): $response");
    }
    
    $post_response = json_decode($response, true);
    
    if (!isset($post_response['url'])) {
        throw new Exception("Invalid post response: $response");
    }
    
    return $post_response;
}

/**
 * Generate OAuth authorization URL for setup
 */
function generateOAuthURL() {
    $auth_url = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query([
        'client_id' => BLOGGER_CLIENT_ID,
        'redirect_uri' => 'http://localhost:8080/callback',
        'scope' => 'https://www.googleapis.com/auth/blogger',
        'response_type' => 'code',
        'access_type' => 'offline',
        'prompt' => 'consent'
    ]);
    
    return $auth_url;
}

/**
 * Get last run date
 */
function getLastRunDate() {
    if (file_exists(LAST_RUN_FILE)) {
        return trim(file_get_contents(LAST_RUN_FILE));
    }
    return null;
}

/**
 * Update last run date
 */
function updateLastRunDate() {
    file_put_contents(LAST_RUN_FILE, date('Y-m-d H:i:s'));
}

/**
 * Main execution function
 */
function runDailyRoundup($force = false, $test = false, $setup = false) {
    try {
        echo "=== Kiffmeister's Daily Roundup Script (Blogger OAuth Version) ===\n";
        echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";
        
        // Setup mode - generate OAuth URL
        if ($setup) {
            echo "🔧 SETUP MODE: OAuth Authorization Required\n\n";
            echo "Step 1: Visit this URL to authorize the application:\n";
            echo generateOAuthURL() . "\n\n";
            echo "Step 2: After authorization, you'll get a code. Use that code to get a refresh token.\n";
            return;
        }
        
        // Check if refresh token is configured
        if (BLOGGER_REFRESH_TOKEN === 'your_refresh_token_here') {
            echo "⚠️  Blogger OAuth not configured!\n";
            echo "Run with --setup flag to get authorization URL.\n\n";
            return;
        }
        
        // Test Blogger connection if requested
        if ($test) {
            return testBloggerConnection();
        }
        
        // Test Blogger connection first
        if (!testBloggerConnection()) {
            return;
        }
        
        echo "\n";
        
        // Get last run date
        $last_run = getLastRunDate();
        $since_date = $last_run ?: date('Y-m-d', strtotime('-1 day'));
        
        echo "Fetching bookmarks with #{" . TARGET_TAG . "} tag since: $since_date\n";
        
        // Fetch bookmarks
        $bookmarks = fetchRaindropBookmarks(TARGET_TAG, $since_date);
        
        if (empty($bookmarks) && !$force) {
            echo "✅ No new bookmarks found. No post needed.\n";
            return;
        }
        
        echo "Found " . count($bookmarks) . " bookmark(s)\n";
        
        // Format content
        $post_content = formatBookmarksForBlogger($bookmarks);
        $post_title = POST_TITLE . " - " . date('F j, Y');
        
        echo "Publishing post: $post_title\n";
        
        // Publish to Blogger
        $result = publishToBlogger($post_title, $post_content);
        
        echo "✅ Post published successfully!\n";
        echo "Post URL: " . $result['url'] . "\n";
        echo "Post ID: " . $result['id'] . "\n";
        
        // Update last run date
        updateLastRunDate();
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// ========================================
// EXECUTION
// ========================================

// Check command line arguments
$force = isset($argv[1]) && $argv[1] === '--force';
$test = isset($argv[1]) && $argv[1] === '--test';
$setup = isset($argv[1]) && $argv[1] === '--setup';

if ($setup) {
    echo "Running OAUTH SETUP mode\n\n";
} elseif ($test) {
    echo "Running BLOGGER CONNECTION TEST mode\n\n";
} elseif ($force) {
    echo "Running in FORCE mode (will post even if no new bookmarks)\n\n";
}

// Run the roundup
runDailyRoundup($force, $test, $setup);
?>
