<?php
/**
 * Kiffmeister's Daily Raindrop to HTML File Generator
 * Fetches bookmarks with #DFC tag and creates formatted HTML file
 */

// ========================================
// CONFIGURATION - UPDATE THESE VALUES
// ========================================

// Raindrop.io credentials
define('RAINDROP_ACCESS_TOKEN', getenv('RAINDROP_ACCESS_TOKEN') ?: 'your_access_token_here');

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
 * Generate beautiful HTML content for blog post
 */
function generateBlogPostHTML($bookmarks, $title) {
    $date = date('F j, Y');
    $count = count($bookmarks);
    
    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$title - $date</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            color: #333;
            background: #fafafa;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
        }
        .meta {
            color: #7f8c8d;
            font-size: 0.9em;
            margin-bottom: 30px;
            font-style: italic;
        }
        .intro {
            background: #ecf0f1;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 30px;
        }
        .bookmark {
            margin-bottom: 25px;
            padding: 20px;
            border-left: 4px solid #3498db;
            background: #f8f9fa;
            border-radius: 0 5px 5px 0;
        }
        .bookmark h3 {
            margin: 0 0 10px 0;
            color: #2c3e50;
        }
        .bookmark h3 a {
            color: #2980b9;
            text-decoration: none;
            font-weight: 600;
        }
        .bookmark h3 a:hover {
            color: #3498db;
            text-decoration: underline;
        }
        .bookmark p {
            margin: 10px 0 0 0;
            color: #555;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #bdc3c7;
            text-align: center;
            color: #7f8c8d;
            font-size: 0.9em;
        }
        .copy-button {
            background: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin: 20px 0;
        }
        .copy-button:hover {
            background: #2980b9;
        }
        .blog-content {
            border: 1px solid #ddd;
            padding: 20px;
            margin: 20px 0;
            background: #fff;
            border-radius: 5px;
        }
        .instructions {
            background: #e8f4f8;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #3498db;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Daily Roundup Generated Successfully!</h1>
        
        <div class="instructions">
            <strong>📋 Ready to Copy!</strong> The formatted blog post content is below. 
            Simply copy everything in the gray box and paste it into your blog editor.
        </div>
        
        <button class="copy-button" onclick="copyContent()">📋 Copy Blog Post Content</button>
        
        <div class="blog-content" id="blogContent">
HTML;

    // Add the actual blog post content
    $html .= "<h2>$title - $date</h2>\n\n";
    
    if (empty($bookmarks)) {
        $html .= "<p>No new bookmarks found with the #" . TARGET_TAG . " tag since the last run.</p>\n";
    } else {
        $html .= "<p>Here are today's $count curated links on digital finance and CBDCs:</p>\n\n";
        
        foreach ($bookmarks as $bookmark) {
            $title = htmlspecialchars($bookmark['title']);
            $url = htmlspecialchars($bookmark['link']);
            $note = !empty($bookmark['note']) ? htmlspecialchars($bookmark['note']) : '';
            
            $html .= "<h3><a href=\"$url\" target=\"_blank\">$title</a></h3>\n";
            
            if ($note) {
                $html .= "<p>$note</p>\n";
            }
            
            $html .= "\n";
        }
        
        $html .= "<hr>\n<p><em>Curated from my <a href=\"https://raindrop.io\" target=\"_blank\">Raindrop.io</a> bookmarks</em></p>\n";
    }
    
    $html .= <<<HTML
        </div>
        
        <div class="footer">
            <p><strong>What to do next:</strong></p>
            <ol style="text-align: left; display: inline-block;">
                <li>Click the "Copy Blog Post Content" button above</li>
                <li>Go to your blog editor (WordPress, Blogger, etc.)</li>
                <li>Paste the content into a new post</li>
                <li>Publish!</li>
            </ol>
            <p>Generated automatically by Daily Roundup Script • $date</p>
        </div>
    </div>
    
    <script>
        function copyContent() {
            const content = document.getElementById('blogContent');
            const range = document.createRange();
            range.selectNode(content);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(range);
            
            try {
                document.execCommand('copy');
                const button = document.querySelector('.copy-button');
                const originalText = button.innerHTML;
                button.innerHTML = '✅ Copied!';
                button.style.background = '#27ae60';
                
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.style.background = '#3498db';
                }, 2000);
            } catch (err) {
                alert('Please manually select and copy the content in the gray box.');
            }
            
            window.getSelection().removeAllRanges();
        }
    </script>
</body>
</html>
HTML;

    return $html;
}

/**
 * Save HTML file as artifact for download
 */
function saveHTMLFile($html, $filename) {
    file_put_contents($filename, $html);
    echo "✅ HTML file generated: $filename\n";
    echo "📁 File size: " . number_format(strlen($html)) . " bytes\n";
    
    // For GitHub Actions, we can make the file available as an artifact
    // The file will be in the workspace and can be downloaded
    echo "🔗 File saved to workspace for download\n";
}

/**
 * Test Raindrop connection
 */
function testRaindropConnection() {
    try {
        echo "Testing Raindrop.io connection...\n";
        
        // Test by getting a few recent bookmarks
        $bookmarks = fetchRaindropBookmarks(TARGET_TAG, date('Y-m-d', strtotime('-7 days')));
        
        echo "✅ Connected to Raindrop.io successfully!\n";
        echo "📚 Found " . count($bookmarks) . " bookmarks with #" . TARGET_TAG . " tag in the last 7 days\n";
        
        return true;
        
    } catch (Exception $e) {
        echo "❌ Raindrop connection test failed: " . $e->getMessage() . "\n";
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
        echo "=== Kiffmeister's Daily Roundup Script (HTML Generator Version) ===\n";
        echo "Starting at: " . date('Y-m-d H:i:s') . "\n\n";
        
        // Test Raindrop connection if requested
        if ($test) {
            return testRaindropConnection();
        }
        
        // Test Raindrop connection first
        if (!testRaindropConnection()) {
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
            echo "✅ No new bookmarks found. Generating empty roundup file.\n";
            $bookmarks = []; // Generate file anyway to show it works
        } else {
            echo "Found " . count($bookmarks) . " bookmark(s)\n";
        }
        
        // Generate content
        $post_title = POST_TITLE . " - " . date('F j, Y');
        $html_content = generateBlogPostHTML($bookmarks, $post_title);
        
        // Save HTML file
        $filename = 'daily_roundup_' . date('Y-m-d') . '.html';
        saveHTMLFile($html_content, $filename);
        
        echo "🎉 Daily roundup HTML file generated successfully!\n";
        echo "📋 Copy the content from the HTML file to your blog\n";
        
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
    echo "Running RAINDROP CONNECTION TEST mode\n\n";
} elseif ($force) {
    echo "Running in FORCE mode (will generate file even if no new bookmarks)\n\n";
}

// Run the roundup
runDailyRoundup($force, $test);
?>
