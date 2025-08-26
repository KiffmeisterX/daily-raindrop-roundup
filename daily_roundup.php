<?php
/**
 * Daily Raindrop to HTML Generator (Persistent Timestamp Version)
 * Fixes duplicate detection by tracking exact last run time in repository
 */

// Configuration - uses environment variables from GitHub Actions
define('RAINDROP_ACCESS_TOKEN', getenv('RAINDROP_ACCESS_TOKEN') ?: 'your_access_token_here');
define('TARGET_TAG', 'DFC'); // Change this to your tag (without #)
define('POST_TITLE', "Your Blog Title Here"); // Customize your post title
define('LAST_RUN_FILE', 'last_roundup_timestamp.txt');

/**
 * Fetch bookmarks from Raindrop.io with specific tag
 */
function fetchRaindropBookmarks($tag, $since_timestamp = null) {
    $access_token = RAINDROP_ACCESS_TOKEN;
    $url = 'https://api.raindrop.io/rest/v1/raindrops/0';
    $params = ['search' => '#' . $tag, 'perpage' => 50];
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
    if (!$data || !isset($data['items'])) return [];
    
    // Filter by timestamp if specified
    if ($since_timestamp) {
        echo "DEBUG: Filtering bookmarks created after: " . date('Y-m-d H:i:s T', $since_timestamp) . "\n";
        
        $filtered_items = [];
        foreach ($data['items'] as $item) {
            $bookmark_timestamp = strtotime($item['created']);
            $bookmark_date = date('Y-m-d H:i:s T', $bookmark_timestamp);
            
            if ($bookmark_timestamp > $since_timestamp) {
                echo "DEBUG: Including bookmark: '{$item['title']}' created at $bookmark_date\n";
                $filtered_items[] = $item;
            } else {
                echo "DEBUG: Excluding bookmark: '{$item['title']}' created at $bookmark_date (too old)\n";
            }
        }
        return $filtered_items;
    }
    
    return $data['items'];
}

/**
 * Generate beautiful HTML for blog post
 */
function generateBlogPostHTML($bookmarks, $title) {
    $date = date('F j, Y');
    $count = count($bookmarks);
    
    // Beautiful HTML template with copy button
    $html = '<!DOCTYPE html>
<html><head>
<title>' . $title . ' - ' . $date . '</title>
<style>
body { font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; padding: 20px; }
.copy-button { background: #007cba; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; }
.blog-content { border: 1px solid #ddd; padding: 20px; margin: 20px 0; background: #f9f9f9; }
.bookmark { margin-bottom: 20px; }
</style>
</head><body>
<h1>Ready to Copy!</h1>
<button class="copy-button" onclick="copyContent()">📋 Copy Blog Post</button>
<div class="blog-content" id="blogContent">';
    
    // Add blog post content
    $html .= "<h2>$title - $date</h2>\n";
    
    if (empty($bookmarks)) {
        $html .= "<p>No new bookmarks found with the #" . TARGET_TAG . " tag since the last update.</p>\n";
    } else {
        $html .= "<p>Here are today's $count curated links:</p>\n\n";
        
        foreach ($bookmarks as $bookmark) {
            $title = htmlspecialchars($bookmark['title']);
            $url = htmlspecialchars($bookmark['link']);
            $note = !empty($bookmark['note']) ? htmlspecialchars($bookmark['note']) : '';
            
            $html .= "<h3><a href=\"$url\" target=\"_blank\">$title</a></h3>\n";
            if ($note) $html .= "<p>$note</p>\n";
            $html .= "\n";
        }
        
        $html .= "<hr><p><em>Curated from my bookmarks</em></p>\n";
    }
    
    $html .= '</div>
<script>
function copyContent() {
    const content = document.getElementById("blogContent");
    const range = document.createRange();
    range.selectNode(content);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    document.execCommand("copy");
    
    const button = document.querySelector(".copy-button");
    button.innerHTML = "✅ Copied!";
    setTimeout(() => button.innerHTML = "📋 Copy Blog Post", 2000);
    window.getSelection().removeAllRanges();
}
</script></body></html>';
    
    return $html;
}

function testRaindropConnection() {
    try {
        echo "Testing Raindrop.io connection...\n";
        $bookmarks = fetchRaindropBookmarks(TARGET_TAG);
        echo "✅ Connected! Found " . count($bookmarks) . " total bookmarks with #" . TARGET_TAG . " tag\n";
        return true;
    } catch (Exception $e) {
        echo "❌ Connection failed: " . $e->getMessage() . "\n";
        return false;
    }
}

function getLastRunTimestamp() {
    if (file_exists(LAST_RUN_FILE)) {
        $timestamp = (int)trim(file_get_contents(LAST_RUN_FILE));
        echo "DEBUG: Found last run timestamp: " . date('Y-m-d H:i:s T', $timestamp) . "\n";
        return $timestamp;
    }
    
    // Default to 24 hours ago if no previous run
    $default_timestamp = time() - (24 * 60 * 60);
    echo "DEBUG: No previous run found, defaulting to 24 hours ago: " . date('Y-m-d H:i:s T', $default_timestamp) . "\n";
    return $default_timestamp;
}

function updateLastRunTimestamp() {
    $current_timestamp = time();
    file_put_contents(LAST_RUN_FILE, $current_timestamp);
    echo "DEBUG: Updated last run timestamp to: " . date('Y-m-d H:i:s T', $current_timestamp) . "\n";
    
    // Commit the timestamp file back to repository so it persists
    commitTimestampFile();
}

function commitTimestampFile() {
    // GitHub Actions cache will handle persistence
    echo "DEBUG: Timestamp file saved for next run via GitHub cache\n";
}

/**
 * Main function - does all the work
 */
function runDailyRoundup($force = false, $test = false) {
    try {
        echo "=== Daily Roundup Script (Enhanced Version) ===\n";
        echo "Current time: " . date('Y-m-d H:i:s T') . "\n";
        echo "Target tag: #" . TARGET_TAG . "\n\n";
        
        if ($test) return testRaindropConnection();
        if (!testRaindropConnection()) return;
        
        echo "\n";
        
        // Get precise last run timestamp
        $last_run_timestamp = getLastRunTimestamp();
        
        echo "Fetching new bookmarks created after last run...\n";
        $bookmarks = fetchRaindropBookmarks(TARGET_TAG, $last_run_timestamp);
        
        if (empty($bookmarks) && !$force) {
            echo "✅ No new bookmarks found since last run.\n";
            // Still update timestamp to prevent drift
            updateLastRunTimestamp();
            
            // Generate empty roundup file
            $post_title = POST_TITLE . " - " . date('F j, Y');
            $html_content = generateBlogPostHTML([], $post_title);
            $filename = 'daily_roundup_' . date('Y-m-d') . '.html';
            file_put_contents($filename, $html_content);
            echo "📄 Generated empty roundup file: $filename\n";
            
            return;
        }
        
        echo "Found " . count($bookmarks) . " new bookmark(s)\n";
        
        // Generate HTML file
        $post_title = POST_TITLE . " - " . date('F j, Y');
        $html_content = generateBlogPostHTML($bookmarks, $post_title);
        $filename = 'daily_roundup_' . date('Y-m-d') . '.html';
        
        file_put_contents($filename, $html_content);
        echo "✅ HTML file generated: $filename\n";
        
        // Update the last run timestamp AFTER successful processing
        updateLastRunTimestamp();
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Run the script
$force = isset($argv[1]) && $argv[1] === '--force';
$test = isset($argv[1]) && $argv[1] === '--test';

if ($test) {
    echo "Running CONNECTION TEST mode\n\n";
} elseif ($force) {
    echo "Running in FORCE mode (will generate file even if no new bookmarks)\n\n";
}

runDailyRoundup($force, $test);
?>
