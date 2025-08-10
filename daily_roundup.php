<?php
/**
 * Kiffmeister's Daily Raindrop to WordPress Roundup (Final REST API Attempt)
 * Fetches bookmarks with #DFC tag and publishes to WordPress
 */

// ========================================
// CONFIGURATION - UPDATE THESE VALUES
// ========================================

// Raindrop.io credentials
define('RAINDROP_ACCESS_TOKEN', '5274fb6c-af04-474f-9dd1-3255a94f3548');

// WordPress credentials
define('WP_SITE_URL', 'https://kiffmeister.com');
define('WP_USERNAME', 'kiffmeister');
define('WP_PASSWORD', 'AH;2n8<8Y0v;rHZE8[pv');

// Roundup settings
define('TARGET_TAG', 'DFC');
define('POST_TITLE', "Kiffmeister's Global Digital Money News Digest");
define('POST_CATEGORY', 1); // Category ID (1 = Uncategorized)

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
 * Format bookmarks for WordPress post
 */
function formatBookmarksForPost($bookmarks) {
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
 * Test basic WordPress connectivity (no auth)
 */
function testBasicWordPressConnectivity() {
    echo "Testing basic WordPress connectivity...\n";
    
    $test_url = WP_SITE_URL . '/wp-json/wp/v2/posts?per_page=1';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $test_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GitHub-Actions-Bot');
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "HTTP Code: $http_code\n";
    echo "Response length: " . strlen($response) . " bytes\n";
    
    if ($error) {
        echo "Connection error: $error\n";
        return false;
    }
    
    if ($http_code === 200) {
        echo "✅ Basic WordPress REST API accessible from GitHub!\n";
        return true;
    } else {
        echo "❌ WordPress blocked GitHub Actions (HTTP $http_code)\n";
        return false;
    }
}

/**
 * Get WordPress authentication cookie
 */
function getWordPressCookie() {
    $login_url = WP_SITE_URL . '/wp-login.php';
    
    $login_data = [
        'log' => WP_USERNAME,
        'pwd' => WP_PASSWORD,
        'wp-submit' => 'Log In',
        'redirect_to' => WP_SITE_URL . '/wp-admin/',
        'testcookie' => '1'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $login_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($login_data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/wp_cookies.txt');
    curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/wp_cookies.txt');
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        throw new Exception("WordPress login failed: $http_code");
    }
    
    // Check if login was successful by looking for admin content
    if (strpos($response, 'wp-admin') === false && strpos($response, 'dashboard') === false) {
        throw new Exception("WordPress login failed: Invalid credentials");
    }
    
    return '/tmp/wp_cookies.txt';
}

/**
 * Publish post to WordPress using cookie authentication
 */
function publishToWordPressWithCookie($title, $content) {
    try {
        // Get authentication cookie
        echo "Getting WordPress authentication cookie...\n";
        $cookie_file = getWordPressCookie();
        
        // Get WordPress nonce
        echo "Getting WordPress nonce...\n";
        $admin_url = WP_SITE_URL . '/wp-admin/post-new.php';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $admin_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
        
        $admin_response = curl_exec($ch);
        curl_close($ch);
        
        // Extract nonce from the page
        preg_match('/name="_wpnonce" value="([^"]+)"/', $admin_response, $nonce_matches);
        if (empty($nonce_matches[1])) {
            throw new Exception("Could not extract WordPress nonce");
        }
        $nonce = $nonce_matches[1];
        
        // Now use REST API with cookie authentication
        echo "Publishing post via REST API with cookie...\n";
        $wp_url = WP_SITE_URL . '/wp-json/wp/v2/posts';
        
        $post_data = [
            'title' => $title,
            'content' => $content,
            'status' => 'publish',
            'categories' => [POST_CATEGORY]
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $wp_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-WP-Nonce: ' . $nonce
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Clean up cookie file
        if (file_exists($cookie_file)) {
            unlink($cookie_file);
        }
        
        if ($http_code !== 201) {
            throw new Exception("WordPress REST API error ($http_code): $response");
        }
        
        $post_data = json_decode($response, true);
        return $post_data;
        
    } catch (Exception $e) {
        // Clean up cookie file on error
        if (isset($cookie_file) && file_exists($cookie_file)) {
            unlink($cookie_file);
        }
        throw $e;
    }
}

/**
 * Test WordPress connection
 */
function testWordPressConnection() {
    try {
        echo "Testing WordPress login...\n";
        $cookie_file = getWordPressCookie();
        
        echo "✅ WordPress login successful!\n";
        
        // Clean up
        if (file_exists($cookie_file)) {
            unlink($cookie_file);
        }
        
        return true;
        
    } catch (Exception $e) {
        echo "❌ WordPress connection test failed: " . $e->getMessage() . "\n";
        return false;
    }
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
        echo "=== Kiffmeister's Daily Roundup Script (Cookie Auth Version) ===\n";
        echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";
        
        // Test WordPress connection if requested
        if ($test) {
            return testWordPressConnection();
        }
        
        // Test WordPress connection first
        if (!testWordPressConnection()) {
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
        $post_content = formatBookmarksForPost($bookmarks);
        $post_title = POST_TITLE . " - " . date('F j, Y');
        
        echo "Publishing post: $post_title\n";
        
        // Publish to WordPress
        $result = publishToWordPressWithCookie($post_title, $post_content);
        
        echo "✅ Post published successfully!\n";
        echo "Post URL: " . $result['link'] . "\n";
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
    echo "Running CONNECTION TEST mode\n\n";
} elseif ($force) {
    echo "Running in FORCE mode (will post even if no new bookmarks)\n\n";
}

// Run the roundup
runDailyRoundup($force, $test);
?>
