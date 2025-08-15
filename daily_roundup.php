<?php
/**
 * Kiffmeister's Daily Raindrop to Blogger Roundup
 * Fetches bookmarks with #DFC tag and publishes to Blogger
 */

// ========================================
// CONFIGURATION - UPDATE THESE VALUES
// ========================================

// Raindrop.io credentials
define('RAINDROP_ACCESS_TOKEN', getenv('RAINDROP_ACCESS_TOKEN') ?: '2b73c6e7-964e-4959-b30a-c4a613ca5a1c');

// Blogger credentials
define('BLOGGER_API_KEY', getenv('BLOGGER_API_KEY') ?: 'AIzaSyBFA0JURGkI-4-f4LThWVRbUQLf9FSsBxQ');
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
 * Test Blogger API connection
 */
function testBloggerConnection() {
    try {
        echo "Testing Blogger API connection...\n";
        
        // Test by getting blog info
        $api_url = "https://www.googleapis.com/blogger/v3/blogs/" . BLOGGER_BLOG_ID . "?key=" . BLOGGER_API_KEY;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Daily-Roundup-Bot/1.0');
        
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
 * Publish post to Blogger
 */
function publishToBlogger($title, $content) {
    $api_url = "https://www.googleapis.com/blogger/v3/blogs/" . BLOGGER_BLOG_ID . "/posts?key=" . BLOGGER_API_KEY;
    
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
function runDailyRoundup($force = false, $test = false) {
    try {
        echo "=== Kiffmeister's Daily Roundup Script (Blogger Version) ===\n";
        echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";
        
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

if ($test) {
    echo "Running BLOGGER CONNECTION TEST mode\n\n";
} elseif ($force) {
    echo "Running in FORCE mode (will post even if no new bookmarks)\n\n";
}

// Run the roundup
runDailyRoundup($force, $test);
?>

// Run the roundup
runDailyRoundup($force, $test);
?>
