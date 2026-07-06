<?php
/**
 * Child Theme Functions File
 *
 * This file contains all custom functionality for the child theme.
 */

// ==========================================
// === חסימה אגרסיבית של כל סקריפטי המעקב ===
// ==========================================
function taliamichaeli_aggressive_blocking() {
    $consent_type = isset($_COOKIE['gdpr_consent_type']) ? $_COOKIE['gdpr_consent_type'] : false;
    
    if (!$consent_type || $consent_type === 'essential') {
        // חסום לחלוטין את Facebook SDK
        add_action('template_redirect', function() {
            ob_start(function($buffer) {
                // הסר כל קריאה ל-Facebook SDK
                $buffer = preg_replace('/<script[^>]*connect\.facebook\.net[^>]*>.*?<\/script>/si', '', $buffer);
                $buffer = preg_replace('/fbq\([^)]*\);?/i', '', $buffer);
                return $buffer;
            });
        }, -1);
        
        // חסום את כל התוספים של פייסבוק
        deactivate_plugins(array(
            'official-facebook-pixel/facebook-for-wordpress.php',
            'pixelyoursite/pixelyoursite.php',
            'pixelyoursite-pro/pixelyoursite-pro.php',
            'facebook-for-woocommerce/facebook-for-woocommerce.php'
        ));
        
        // הסר hooks של Elementor Custom Code
        remove_action('wp_head', 'elementor_pro_custom_code', 1);
        remove_action('wp_footer', 'elementor_pro_custom_code', 99);
        
        // חסום סקריפטים דרך wp_enqueue_scripts
        add_action('wp_enqueue_scripts', function() {
            wp_deregister_script('facebook-pixel');
            wp_deregister_script('facebook-sdk');
            wp_deregister_script('fbevents');
        }, 1);
        
        // הוסף סקריפט שחוסם את Facebook Pixel בצד הלקוח
        add_action('wp_head', function() {
            ?>
            <script>
            // חסימת Facebook Pixel לחלוטין
            window.fbq = function() { 
                console.log('🚫 Facebook Pixel blocked due to cookie preferences');
                return false; 
            };
            
            // חסום טעינת הסקריפט
            (function() {
                var originalAppendChild = Node.prototype.appendChild;
                Node.prototype.appendChild = function(child) {
                    if (child && child.src && child.src.includes('connect.facebook.net')) {
                        console.log('🚫 Blocked Facebook SDK loading attempt');
                        return child;
                    }
                    return originalAppendChild.call(this, child);
                };
            })();
            </script>
            <?php
        }, 1);
    }
}
add_action('init', 'taliamichaeli_aggressive_blocking', 0);



/**
 * Delay WP Rocket's Translation Loading Until the 'init' Hook
 */
add_action( 'after_setup_theme', function() {
    if ( function_exists( 'rocket_load_textdomain' ) ) {
        error_log( 'Delaying WP Rocket translation loading: moving rocket_load_textdomain from plugins_loaded to init.' );
        remove_action( 'plugins_loaded', 'rocket_load_textdomain' );
        add_action( 'init', 'rocket_load_textdomain', 0 );
    }
} );

/**
 * Enqueue Parent and Child Styles
 */
if ( ! function_exists( 'talia_enqueue_styles' ) ) {
    function talia_enqueue_styles() {
        $parent_style = 'hello-elementor-style';
        wp_enqueue_style( $parent_style, get_template_directory_uri() . '/style.css' );
        wp_enqueue_style( 'child-style',
            get_stylesheet_directory_uri() . '/style.css',
            array( $parent_style ),
            wp_get_theme()->get('Version')
        );
    }
    add_action( 'wp_enqueue_scripts', 'talia_enqueue_styles' );
}

/**
 * Default Category Assignment for Video Posts
 */
if ( ! function_exists( 'set_default_video_category' ) ) {
    function set_default_video_category( $post_id ) {
        error_log( "set_default_video_category triggered for post ID: $post_id" );

        // Skip autosaves and revisions.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            error_log( "Exiting: DOING_AUTOSAVE is true." );
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            error_log( "Exiting: Post is a revision." );
            return;
        }

        // Only proceed for posts of type "video".
        $post_type = get_post_type( $post_id );
        error_log( "Post type: $post_type" );
        if ( 'video' !== $post_type ) {
            error_log( "Exiting: Post type is not video." );
            return;
        }

        // Check whether any category is already assigned.
        $current_terms = wp_get_object_terms( $post_id, 'category', array( 'fields' => 'slugs' ) );
        if ( ! empty( $current_terms ) ) {
            error_log( "Post already has categories: " . implode( ', ', $current_terms ) );
            return;
        }

        // Get all categories.
        $categories = get_terms( array(
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ) );
        if ( is_wp_error( $categories ) ) {
            error_log( "Error retrieving categories: " . $categories->get_error_message() );
            return;
        }
        error_log( "Total categories found: " . count( $categories ) );

        // Search for a matching category by name or slug using regex.
        $default_term_slug = '';
        foreach ( $categories as $category ) {
            error_log( "Checking category – Name: {$category->name}, Slug: {$category->slug}" );
            if ( preg_match( '/(סרטו|video)/i', $category->name ) || preg_match( '/(סרטו|video)/i', $category->slug ) ) {
                $default_term_slug = $category->slug;
                error_log( "Matched category found: {$category->name} (Slug: {$category->slug})" );
                break;
            }
        }

        // If a matching category is found, assign it.
        if ( ! empty( $default_term_slug ) ) {
            $result = wp_set_object_terms( $post_id, $default_term_slug, 'category', false );
            error_log( "Assigned default category: $default_term_slug. Result: " . print_r( $result, true ) );
        } else {
            error_log( "No matching category found using regex." );
        }
    }
    add_action( 'acf/save_post', 'set_default_video_category', 20 );
}

/**
 * שורטקוד עבור הצגת כל התגיות
 */
if ( ! function_exists( 'display_all_tags_shortcode' ) ) {
    function display_all_tags_shortcode($atts) {
        // הגדרת ברירות מחדל
        $atts = shortcode_atts(array(
            'sorting' => 'alphabetical',
            'amount' => -1
        ), $atts);
        
        // הגדרת פרמטרים לשאילתה
        $args = array(
            'number' => ($atts['amount'] == -1) ? 0 : intval($atts['amount'])
        );
        
        // קביעת סדר הסידור
        if ($atts['sorting'] == 'popular') {
            $args['orderby'] = 'count';
            $args['order'] = 'DESC';
        } else {
            $args['orderby'] = 'name';
            $args['order'] = 'ASC';
        }
        
        // שליפת התגיות
        $tags = get_tags($args);
        
        if ($tags) {
            $output = '<div class="custom-tags-list">';
            foreach ($tags as $tag) {
                $output .= '<a href="' . get_tag_link($tag->term_id) . '" class="custom-tag-link" title="' . $tag->count . ' פוסטים">';
                $output .= $tag->name;
                if ($atts['sorting'] == 'popular') {
                    $output .= ' (' . $tag->count . ')';
                }
                $output .= '</a> ';
            }
            $output .= '</div>';
            return $output;
        }
        
        return 'אין תגיות זמינות';
    }
    add_shortcode('all_tags', 'display_all_tags_shortcode');
} // This closing brace was missing!

// --------------------------------------------------------
// Add Instagram to Elementor Pro Share Buttons
// --------------------------------------------------------

// Add Instagram to the networks array using reflection
add_action('elementor_pro/init', function() {
    if (class_exists('\ElementorPro\Modules\ShareButtons\Module')) {
        try {
            $module_class = new ReflectionClass('\ElementorPro\Modules\ShareButtons\Module');
            $networks_property = $module_class->getProperty('networks');
            $networks_property->setAccessible(true);
            
            $networks = $networks_property->getValue();
            $networks['instagram'] = [
                'title' => 'Instagram',
                'has_counter' => false,
            ];
            
            $networks_property->setValue(null, $networks);
        } catch (Exception $e) {
            error_log('Could not add Instagram to share buttons: ' . $e->getMessage());
        }
    }
});

// Handle Instagram share button functionality with native share API
add_action('wp_footer', function() {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Handle clicks on Instagram share buttons
        document.addEventListener('click', function(e) {
            // Find the Instagram share button
            var instagramBtn = e.target.closest('.elementor-share-btn_instagram');
            if (!instagramBtn) return;
            
            e.preventDefault();
            e.stopPropagation();
            
            // Get the URL and title to share
            var shareUrl = window.location.href;
            var shareTitle = document.title;
            var shareText = 'Check this out: ';
            
            // Try to get custom URL from widget settings
            var widget = instagramBtn.closest('.elementor-widget-share-buttons');
            if (widget && window.elementorFrontend && window.elementorFrontend.config) {
                var widgetId = widget.getAttribute('data-id');
                if (widgetId && window.elementorFrontend.config.elements && 
                    window.elementorFrontend.config.elements.data && 
                    window.elementorFrontend.config.elements.data[widgetId]) {
                    var settings = window.elementorFrontend.config.elements.data[widgetId].settings;
                    if (settings && settings.share_url && settings.share_url.url) {
                        shareUrl = settings.share_url.url;
                    }
                }
            }
            
            // Detect if mobile
            var isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
            
            if (isMobile) {
                // Use Web Share API for mobile - this opens the native share dialog
                if (navigator.share) {
                    // Modern mobile browsers support Web Share API
                    navigator.share({
                        title: shareTitle,
                        text: shareText,
                        url: shareUrl
                    }).then(function() {
                        console.log('Shared successfully');
                    }).catch(function(error) {
                        // User cancelled or error occurred
                        if (error.name !== 'AbortError') {
                            console.log('Share failed:', error);
                            // Fallback to copying URL
                            fallbackShareMethod(shareUrl);
                        }
                    });
                } else {
                    // Fallback for older mobile browsers
                    fallbackShareMethod(shareUrl);
                }
            } else {
                // Desktop behavior - open Instagram in popup
                desktopInstagramShare(shareUrl);
            }
        }, true);
        
        // Fallback method for mobile when Web Share API isn't available
        function fallbackShareMethod(url) {
            // Copy to clipboard first
            copyToClipboard(url, function(success) {
                if (success) {
                    showInstagramNotification('Link copied! Opening Instagram...');
                }
                
                // Detect platform
                var isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
                var isAndroid = /Android/i.test(navigator.userAgent);
                
                if (isAndroid) {
                    // For Android: Try multiple methods
                    // Method 1: Android intent
                    window.location.href = 'intent://instagram.com/#Intent;scheme=https;package=com.instagram.android;end';
                    
                    // Method 2: Fallback to instagram:// scheme
                    setTimeout(function() {
                        if (document.hasFocus()) {
                            window.location.href = 'instagram://app';
                        }
                    }, 500);
                    
                } else if (isIOS) {
                    // For iOS: Use instagram:// scheme
                    window.location.href = 'instagram://app';
                    
                    // Fallback to App Store if app not installed
                    setTimeout(function() {
                        if (document.hasFocus()) {
                            window.location.href = 'https://apps.apple.com/app/instagram/id389801252';
                        }
                    }, 1500);
                }
            });
        }
        
        // Desktop Instagram share
        function desktopInstagramShare(url) {
            // Copy URL first
            copyToClipboard(url, function(success) {
                if (success) {
                    showInstagramNotification('Link copied! Share it on Instagram');
                }
                
                // Open Instagram in popup
                var width = 600;
                var height = 500;
                var left = (screen.width - width) / 2;
                var top = (screen.height - height) / 2;
                
                window.open(
                    'https://www.instagram.com/',
                    'instagram-share',
                    'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top + ',toolbar=0,status=0'
                );
            });
        }
        
        // Copy to clipboard with callback
        function copyToClipboard(text, callback) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    callback(true);
                }).catch(function() {
                    fallbackCopyToClipboard(text, callback);
                });
            } else {
                fallbackCopyToClipboard(text, callback);
            }
        }
        
        // Fallback copy method
        function fallbackCopyToClipboard(text, callback) {
            var textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.top = '0';
            textArea.style.left = '-9999px';
            textArea.style.opacity = '0';
            document.body.appendChild(textArea);
            
            // For iOS, we need to handle this differently
            if (/iPad|iPhone|iPod/.test(navigator.userAgent)) {
                var range = document.createRange();
                range.selectNodeContents(textArea);
                var selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
                textArea.setSelectionRange(0, 999999);
            } else {
                textArea.select();
            }
            
            try {
                var successful = document.execCommand('copy');
                callback(successful);
            } catch (err) {
                callback(false);
            }
            
            document.body.removeChild(textArea);
        }
        
        // Show notification
        function showInstagramNotification(message) {
            // Remove any existing notification
            var existing = document.querySelector('.instagram-share-notification');
            if (existing) existing.remove();
            
            // Create notification
            var notification = document.createElement('div');
            notification.className = 'instagram-share-notification';
            notification.textContent = message;
            document.body.appendChild(notification);
            
            // Show notification
            setTimeout(function() {
                notification.classList.add('show');
            }, 10);
            
            // Hide and remove after 3 seconds
            setTimeout(function() {
                notification.classList.remove('show');
                setTimeout(function() {
                    notification.remove();
                }, 300);
            }, 3000);
        }
    });
    </script>
    
    <style>
    /* Instagram icon */
    .elementor-share-btn_instagram .elementor-share-btn__icon i:before {
        content: "\f16d";
        font-family: "Font Awesome 5 Brands";
        font-weight: 400;
    }
    
    /* Instagram brand colors */
    .elementor-share-buttons--color-official .elementor-share-btn_instagram {
        --e-share-buttons-primary-color: #E4405F;
        --e-share-buttons-secondary-color: #bc2a8d;
    }
    
    /* Notification styles */
    .instagram-share-notification {
        position: fixed;
        bottom: 20px;
        left: 50%;
        transform: translateX(-50%) translateY(100px);
        background: #333;
        color: #fff;
        padding: 12px 24px;
        border-radius: 6px;
        font-size: 14px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        z-index: 999999;
        transition: transform 0.3s ease, opacity 0.3s ease;
        opacity: 0;
        pointer-events: none;
    }
    
    .instagram-share-notification.show {
        transform: translateX(-50%) translateY(0);
        opacity: 1;
    }
    
    /* Mobile adjustments */
    @media (max-width: 768px) {
        .instagram-share-notification {
            bottom: 60px;
            width: 90%;
            max-width: 300px;
            text-align: center;
        }
    }
    </style>
    <?php
});




// --------------------------------------------------------
// Fix Remaining Font and CSS Errors
// --------------------------------------------------------

// 1. Force creation of missing font CSS files
add_action('init', function() {
    if (!is_admin()) return;
    
    // Create the directories if they don't exist
    $upload_dir = wp_upload_dir();
    $font_css_dir = $upload_dir['basedir'] . '/elementor/google-fonts/css/';
    
    if (!file_exists($font_css_dir)) {
        wp_mkdir_p($font_css_dir);
    }
    
    // Define the font files that should exist
    $required_fonts = [
        'rubik' => 'https://fonts.googleapis.com/css2?family=Rubik:wght@300;400;500;600;700;800;900&display=swap',
        'secularone' => 'https://fonts.googleapis.com/css2?family=Secular+One&display=swap',
        'roboto' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@100;300;400;500;700;900&display=swap',
        'notosanshebrew' => 'https://fonts.googleapis.com/css2?family=Noto+Sans+Hebrew:wght@100;200;300;400;500;600;700;800;900&display=swap'
    ];
    
    // Create the font CSS files if they don't exist
    foreach ($required_fonts as $font_name => $google_url) {
        $font_file = $font_css_dir . $font_name . '.css';
        
        if (!file_exists($font_file)) {
            // Fetch the Google Fonts CSS
            $response = wp_remote_get($google_url);
            
            if (!is_wp_error($response)) {
                $css_content = wp_remote_retrieve_body($response);
                
                // Replace Google's font URLs with local ones
                $css_content = str_replace('https://fonts.gstatic.com', $upload_dir['baseurl'] . '/elementor/google-fonts', $css_content);
                
                // Write the CSS file
                file_put_contents($font_file, $css_content);
            }
        }
    }
});

// 2. Fix the JavaScript error (Cו undefined)
add_action('wp_head', function() {
    ?>
    <script>
    // Fix undefined Hebrew character variable
    if (typeof window.Cו === 'undefined') {
        window.Cו = '';
    }
    // Also check for any other Hebrew variables that might be undefined
    var hebrewVars = ['Cו', 'Cא', 'Cב', 'Cג', 'Cד', 'Cה', 'Cז', 'Cח', 'Cט', 'Cי'];
    hebrewVars.forEach(function(varName) {
        if (typeof window[varName] === 'undefined') {
            window[varName] = '';
        }
    });
    </script>
    <?php
}, 1);

// 3. Force regeneration of missing Elementor CSS files
add_action('admin_init', function() {
    if (isset($_GET['fix_elementor_css']) && current_user_can('manage_options')) {
        // Clear all Elementor CSS
        if (class_exists('\Elementor\Plugin')) {
            \Elementor\Plugin::instance()->files_manager->clear_cache();
        }
        
        // Delete all CSS meta
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '%_elementor_css%'");
        
        // Force regeneration
        delete_option('_elementor_global_css');
        delete_option('elementor-custom-breakpoints-files');
        
        wp_redirect(admin_url('?css_fixed=1'));
        exit;
    }
});

// 4. Add admin notice to fix CSS if needed
add_action('admin_notices', function() {
    if (current_user_can('manage_options')) {
        // Check if CSS files exist
        $upload_dir = wp_upload_dir();
        $missing_files = [];
        
        $check_files = [
            'custom-frontend-rtl.min.css',
            'custom-pro-widget-nav-menu-rtl.min.css',
            'custom-apple-webkit.min.css'
        ];
        
        foreach ($check_files as $file) {
            if (!file_exists($upload_dir['basedir'] . '/elementor/css/' . $file)) {
                $missing_files[] = $file;
            }
        }
        
        if (!empty($missing_files)) {
            ?>
            <div class="notice notice-warning">
                <p><strong>Missing Elementor CSS files detected:</strong> <?php echo implode(', ', $missing_files); ?></p>
                <p>
                    <a href="<?php echo admin_url('?fix_elementor_css=1'); ?>" class="button button-primary">Fix CSS Files</a>
                    <a href="<?php echo admin_url('admin.php?page=elementor-tools#tab-regenerate'); ?>" class="button">Elementor Tools</a>
                </p>
            </div>
            <?php
        }
        
        if (isset($_GET['css_fixed'])) {
            ?>
            <div class="notice notice-success is-dismissible">
                <p>Elementor CSS cache cleared! Visit your pages to regenerate the CSS files.</p>
            </div>
            <?php
        }
    }
});

// 5. Handle 404 errors for font files by creating them on the fly
add_action('template_redirect', function() {
    $request_uri = $_SERVER['REQUEST_URI'];
    
    // Check if this is a request for a missing font CSS file
    if (strpos($request_uri, '/elementor/google-fonts/css/') !== false && strpos($request_uri, '.css') !== false) {
        $font_name = basename($request_uri, '.css');
        $font_name = preg_replace('/\?.*/', '', $font_name); // Remove query string
        
        $font_urls = [
            'rubik' => 'https://fonts.googleapis.com/css2?family=Rubik:wght@300;400;500;600;700;800;900&display=swap',
            'secularone' => 'https://fonts.googleapis.com/css2?family=Secular+One&display=swap',
            'roboto' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@100;300;400;500;700;900&display=swap',
            'notosanshebrew' => 'https://fonts.googleapis.com/css2?family=Noto+Sans+Hebrew:wght@100;200;300;400;500;600;700;800;900&display=swap'
        ];
        
        if (isset($font_urls[$font_name])) {
            // Fetch and serve the font CSS
            $response = wp_remote_get($font_urls[$font_name]);
            
            if (!is_wp_error($response)) {
                $css_content = wp_remote_retrieve_body($response);
                
                // Set proper headers
                header('Content-Type: text/css; charset=UTF-8');
                header('Cache-Control: public, max-age=31536000');
                
                echo $css_content;
                exit;
            }
        }
    }
});

// 6. Disable debug display to clean up console
if (!defined('WP_DEBUG_DISPLAY')) {
    define('WP_DEBUG_DISPLAY', false);
}
@ini_set('display_errors', 0);

// 7. Remove the temporary Google Fonts after fonts are properly loaded
add_action('wp_footer', function() {
    ?>
    <script>
    // Check if fonts are loaded properly
    document.addEventListener('DOMContentLoaded', function() {
        // Check if font files are loading
        var fontLinks = document.querySelectorAll('link[href*="/elementor/google-fonts/"]');
        var fontsLoaded = 0;
        
        fontLinks.forEach(function(link) {
            // Check if the stylesheet loaded successfully
            if (link.sheet && link.sheet.cssRules && link.sheet.cssRules.length > 0) {
                fontsLoaded++;
            }
        });
        
        // If all fonts loaded, remove temporary styles
        if (fontsLoaded === fontLinks.length && fontsLoaded > 0) {
            var tempFonts = document.getElementById('temp-google-fonts');
            if (tempFonts) {
                tempFonts.remove();
            }
        }
    });
    </script>
    <?php
});

// 8. Clean up the console errors for blocked resources
add_action('wp_head', function() {
    ?>
    <script>
    // Suppress certain console errors
    window.addEventListener('error', function(e) {
        // Suppress Facebook pixel errors and Chrome extension errors
        if (e.message && (
            e.message.includes('connect.facebook.net') || 
            e.message.includes('chrome-extension://') ||
            e.message.includes('fbevents.js')
        )) {
            e.preventDefault();
            return false;
        }
    }, true);
    </script>
    <?php
}, 1);

// --------------------------
// פונקציה להוספת דיאלוג GDPR
// --------------------------
// === גרסה מתוקנת - החלף את כל הקוד הקודם בזה ===

// הגדרת העוגיות והסקריפטים
function taliamichaeli_define_cookie_categories() {
    return array(
        'essential' => array(
            'name' => 'עוגיות נחוצות',
            'description' => 'עוגיות חיוניות לתפקוד האתר',
            'scripts' => array()
        ),
        'analytics' => array(
            'name' => 'עוגיות אנליטיקה',
            'description' => 'מעקב אחר ביצועי האתר',
            'scripts' => array(
                'google-analytics' => array(
                    'id' => 'G-XXXXXXXXXX', // החלף ב-ID האמיתי שלך
                    'type' => 'gtag'
                ),
                'google-tag-manager' => array(
                    'id' => 'GTM-XXXXXXX', // החלף ב-ID האמיתי שלך
                    'type' => 'gtm'
                )
            )
        ),
        'marketing' => array(
            'name' => 'עוגיות שיווק',
            'description' => 'פרסומות ממוקדות',
            'scripts' => array(
                'facebook-pixel' => array(
                    'id' => 'XXXXXXXXXXXXXXX', // החלף ב-ID האמיתי שלך
                    'type' => 'fbpixel'
                )
            )
        )
    );
}

// טעינת סקריפטים מותנית - גרסה מתוקנת
function taliamichaeli_conditional_script_loader() {
    $consent_type = isset($_COOKIE['gdpr_consent_type']) ? $_COOKIE['gdpr_consent_type'] : false;
    
    // הוסף console log לדיבאג
    ?>
    <script>
    console.log('🍪 GDPR Cookie Consent Status');
    console.log('Consent Type:', '<?php echo $consent_type ? $consent_type : "No consent given yet"; ?>');
    console.log('Consent Cookie Exists:', <?php echo isset($_COOKIE['gdpr_consent']) ? 'true' : 'false'; ?>);
    </script>
    <?php
    
    // אם אין הסכמה או הסכמה רק לעוגיות נחוצות - אל תטען כלום
    if (!$consent_type || $consent_type === 'essential') {
        ?>
        <script>
        <?php if (!$consent_type): ?>
        console.log('⚠️ No consent given - blocking all tracking scripts');
        <?php else: ?>
        console.log('🔒 Essential cookies only - blocking tracking scripts');
        <?php endif; ?>
        </script>
        <?php
        return; // חשוב! יוצאים מהפונקציה כאן
    }
    
    // טעינת סקריפטים רק אם יש הסכמה מלאה
    if ($consent_type === 'all') {
        $categories = taliamichaeli_define_cookie_categories();
        ?>
        <script>
        console.log('✅ Full consent given - loading all scripts');
        
        // Google Analytics
        <?php if (isset($categories['analytics']['scripts']['google-analytics'])): 
            $ga_id = $categories['analytics']['scripts']['google-analytics']['id'];
            if ($ga_id !== 'G-XXXXXXXXXX'): // טען רק אם יש ID אמיתי
        ?>
        console.log('📊 Loading Google Analytics: <?php echo $ga_id; ?>');
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '<?php echo $ga_id; ?>');
        <?php else: ?>
        console.warn('⚠️ Google Analytics ID not configured');
        <?php endif; endif; ?>
        
        // Facebook Pixel
        <?php if (isset($categories['marketing']['scripts']['facebook-pixel'])): 
            $fb_id = $categories['marketing']['scripts']['facebook-pixel']['id'];
            if ($fb_id !== 'XXXXXXXXXXXXXXX'): // טען רק אם יש ID אמיתי
        ?>
        console.log('📘 Loading Facebook Pixel: <?php echo $fb_id; ?>');
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '<?php echo $fb_id; ?>');
        fbq('track', 'PageView');
        <?php else: ?>
        console.warn('⚠️ Facebook Pixel ID not configured');
        <?php endif; endif; ?>
        </script>
        
        <!-- Google Analytics Script -->
        <?php if (isset($categories['analytics']['scripts']['google-analytics']) && $ga_id !== 'G-XXXXXXXXXX'): ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $ga_id; ?>"></script>
        <?php endif; ?>
        
        <!-- Facebook Pixel noscript -->
        <?php if (isset($categories['marketing']['scripts']['facebook-pixel']) && $fb_id !== 'XXXXXXXXXXXXXXX'): ?>
        <noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id=<?php echo $fb_id; ?>&ev=PageView&noscript=1"/></noscript>
        <?php endif; ?>
        <?php
    }
}
add_action('wp_head', 'taliamichaeli_conditional_script_loader', 1);

// חסימת plugins שמוסיפים סקריפטים
function taliamichaeli_block_third_party_cookies() {
    $consent_type = isset($_COOKIE['gdpr_consent_type']) ? $_COOKIE['gdpr_consent_type'] : false;
    
    // חסום הכל אם אין הסכמה או יש הסכמה רק לעוגיות נחוצות
    if (!$consent_type || $consent_type === 'essential') {
        // חסום Google Site Kit
        remove_action('wp_head', 'googlesitekit_analytics');
        remove_action('wp_head', array('Google\Site_Kit\Core\Analytics\Analytics', 'render_gtag'));
        
        // חסום MonsterInsights
        remove_action('wp_head', 'monsterinsights_tracking_script');
        remove_action('wp_footer', 'monsterinsights_tracking_script');
        
        // חסום Jetpack
        remove_action('wp_footer', 'stats_footer', 101);
        remove_action('wp_head', 'jetpack_og_tags');
        
        // חסום WooCommerce Analytics
        remove_action('wp_head', 'wc_google_analytics');
        remove_action('wp_footer', 'wc_google_analytics');
        
        // חסום Pixel Your Site
        remove_action('wp_head', 'pys_head');
        remove_action('wp_footer', 'pys_footer');
        
        // חסום Facebook for WooCommerce
        remove_action('wp_head', 'facebook_for_woocommerce');
        
        // הוסף פילטרים לחסימה מוחלטת
        add_filter('googlesitekit_analytics_tracking_disabled', '__return_true');
        add_filter('monsterinsights_tracking_analytics_script', '__return_false');
    }
}
add_action('init', 'taliamichaeli_block_third_party_cookies', 1);

// פונקציה למחיקת עוגיות - גרסה משופרת
function taliamichaeli_clear_tracking_cookies() {
    $consent_type = isset($_COOKIE['gdpr_consent_type']) ? $_COOKIE['gdpr_consent_type'] : false;
    
    // מחק עוגיות רק אם בחרו "essential"
    if ($consent_type === 'essential') {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🧹 Cleaning tracking cookies...');
            
            // רשימת עוגיות למחיקה
            var trackingCookies = ['_ga', '_gid', '_gat', '_fbp', '_gcl_au', 'fr'];
            
            trackingCookies.forEach(function(cookieName) {
                // מחק מכל הדומיינים האפשריים
                document.cookie = cookieName + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
                document.cookie = cookieName + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=." + window.location.hostname;
                document.cookie = cookieName + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; domain=" + window.location.hostname;
            });
            
            // נקה localStorage
            if (typeof(Storage) !== "undefined") {
                Object.keys(localStorage).forEach(function(key) {
                    if (key.match(/^(_ga|_gid|_fbp)/)) {
                        localStorage.removeItem(key);
                    }
                });
            }
        });
        </script>
        <?php
    }
}
add_action('wp_footer', 'taliamichaeli_clear_tracking_cookies', 999);

// === חלק 4: הדיאלוג המעודכן עם הלוגיקה החדשה ===
function taliamichaeli_gdpr_consent_banner() {
    if (!isset($_COOKIE['gdpr_consent'])) {
        ?>
        <div id="gdpr-banner" class="gdpr-consent-banner">
            <div class="gdpr-content">
                <h3>אנחנו משתמשים בעוגיות</h3>
                <p>האתר עושה שימוש בעוגיות לשיפור חוויית הגלישה, ניתוח תנועת גולשים, והתאמת תכנים והצעות אישיות. חלק מהעוגיות חיוניות לפעילות התקינה של האתר.</p>
                
                <div class="gdpr-buttons">
                    <button id="accept-all" class="gdpr-btn gdpr-btn-primary">כל העוגיות</button>
                    <button id="accept-essential" class="gdpr-btn gdpr-btn-secondary">רק עוגיות נחוצות</button>
                </div>
                
                <div class="gdpr-footer">
                    <a href="#" id="read-more-link">קרא עוד ←</a>
                </div>
                
                <div id="gdpr-accordion" class="gdpr-accordion">
                    <div class="gdpr-accordion-content">
                        <h4>עוגיות נחוצות</h4>
                        <p>עוגיות אלו חיוניות לתפקוד הבסיסי של האתר. הן מאפשרות ניווט בין עמודים, גישה לאזורים מאובטחים ושמירת העדפות בסיסיות. ללא עוגיות אלו, האתר לא יוכל לפעול כראוי.</p>
                        
                        <h4>עוגיות אנליטיקה ושיווק</h4>
                        <p>עוגיות אלו עוזרות לנו להבין כיצד משתמשים מבקרים באתר, אילו עמודים פופולריים ואיך לשפר את חוויית המשתמש. בנוסף, הן מאפשרות להציג פרסומות רלוונטיות ולמדוד את יעילותן. המידע נאסף באופן אנונימי.</p>
                    </div>
                </div>
            </div>
        </div>

<style>
.gdpr-consent-banner {
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: #ffffff;
    border-radius: 6px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
    padding: 30px;
    max-width: 500px;
    width: 90%;
    z-index: 999999;
    font-family: var(--e-global-typography-secondary-font-family), -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    direction: rtl;
    animation: slideUp 0.3s ease-out;
}

@keyframes slideUp {
    from {
        transform: translateX(-50%) translateY(100px);
        opacity: 0;
    }
    to {
        transform: translateX(-50%) translateY(0);
        opacity: 1;
    }
}

.gdpr-content h3 {
    margin: 0 0 15px 0;
    font-size: 20px;
    color: #2c3e50;
    font-weight: 600;
 	font-family: var(--e-global-typography-primary-font-family),-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;;
}

.gdpr-content p {
    margin: 0 0 20px 0;
    font-size: 14px;
    line-height: 1.6;
    color: #546e7a;
}

.gdpr-buttons {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

.gdpr-btn {
    flex: 1;
    padding: 12px 20px;
    border: none;
    border-radius: 6px;
    font-size: 16px;
    font-weight: 500;
    cursor: pointer;
    transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
    font-family: inherit;
    outline: none;
}

.gdpr-btn:focus {
    outline: none;
    box-shadow: none;
}

/* כפתור "כל העוגיות" */
.gdpr-btn-primary {
    background: var(--e-global-color-accent);
    color: white;
    border: 2px solid var(--e-global-color-accent);
}

.gdpr-btn-primary:hover {
    background: var(--e-global-color-e4c37b3);
    border-color: var(--e-global-color-e4c37b3);
}

/* כפתור "רק עוגיות נחוצות" - עדכון לפי הבקשה */
.gdpr-btn-secondary {
    background: #ffffff !important;
    color: var(--e-global-color-accent) !important;
    border: 2px solid var(--e-global-color-accent);
}

.gdpr-btn-secondary:hover {
    background: #ffffff !important;
    color: var(--e-global-color-e4c37b3) !important;
    border-color: var(--e-global-color-e4c37b3);
}

.gdpr-footer {
    text-align: center;
    margin-top: 10px;
}

.gdpr-footer a {
    color: var(--e-global-color-accent);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
}

.gdpr-footer a:hover {
    text-decoration: underline;
    color: var(--e-global-color-e4c37b3);
}

/* אקורדיון סטיילינג */
.gdpr-accordion {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease-out, margin-top 0.3s ease-out;
    margin-top: 0;
}

.gdpr-accordion.active {
    max-height: 300px;
    margin-top: 20px;
}

.gdpr-accordion-content {
    padding: 15px;
    background: #f8f9fa;
    border-radius: 6px;
    margin-top: 10px;
}

.gdpr-accordion-content h4 {
    margin: 0 0 8px 0;
    font-size: 15px;
    color: #2c3e50;
    font-weight: 600;
}

.gdpr-accordion-content h4:not(:first-child) {
    margin-top: 15px;
}

.gdpr-accordion-content p {
    margin: 0 0 10px 0;
    font-size: 13px;
    line-height: 1.5;
    color: #607d8b;
}

.gdpr-accordion-content p:last-child {
    margin-bottom: 0;
}

.gdpr-hidden {
    display: none !important;
}

/* רספונסיב למובייל - עדכון כדי שהכפתורים יישארו זה לצד זה */
@media (max-width: 480px) {
    .gdpr-consent-banner {
        bottom: 10px;
        padding: 20px;
    }
    
    .gdpr-buttons {
        display: flex;
        flex-direction: row;
        gap: 8px;
    }
    
    .gdpr-btn {
        padding: 10px 12px;
        font-size: 13px;
    }
    
    .gdpr-accordion.active {
        max-height: 400px;
    }
}

/* אנימציה להסתרת הבאנר */
@keyframes slideDown {
    from {
        transform: translateX(-50%) translateY(0);
        opacity: 1;
    }
    to {
        transform: translateX(-50%) translateY(100px);
        opacity: 0;
    }
}
</style>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var banner = document.getElementById('gdpr-banner');

            // הדף מוגש לעיתים משכבת קאש שלא מתחשבת בעוגיות (x-cacheable: Forced),
            // כך שהבאנר עלול להופיע גם למי שכבר נתן הסכמה. נסתיר אותו מיידית בצד הלקוח.
            if (document.cookie.indexOf('gdpr_consent=true') !== -1) {
                banner.classList.add('gdpr-hidden');
                return;
            }

            var acceptAllBtn = document.getElementById('accept-all');
            var acceptEssentialBtn = document.getElementById('accept-essential');
            var readMoreLink = document.getElementById('read-more-link');
            var accordion = document.getElementById('gdpr-accordion');

            // פונקציה לפתיחה/סגירה של האקורדיון
            readMoreLink.addEventListener('click', function(e) {
                e.preventDefault();
                accordion.classList.toggle('active');
                
                if (accordion.classList.contains('active')) {
                    readMoreLink.innerHTML = 'סגור ↑';
                } else {
                    readMoreLink.innerHTML = 'קרא עוד ←';
                }
            });
            
            // פונקציה לשמירת העדפות
            function setGDPRCookie(consent_type) {
                var date = new Date();
                date.setTime(date.getTime() + (365 * 24 * 60 * 60 * 1000)); // 365 ימים
                var expires = "; expires=" + date.toUTCString();
                document.cookie = "gdpr_consent=true" + expires + "; path=/; SameSite=Strict";
                document.cookie = "gdpr_consent_type=" + consent_type + expires + "; path=/; SameSite=Strict";
            }
            
            // פונקציה להסתרת הבאנר
            // הערה: בעבר בוצע כאן window.location.reload() אחרי ההסתרה, כדי לגרום
            // לטעינה/אי-טעינה של סקריפטי המעקב. אבל כשהדף מוגש מקאש (ר' לעיל),
            // הרענון פשוט טוען מחדש את אותו HTML שהיה בקאש - עם הבאנר בחזרה - ולכן
            // נראה כאילו הלחיצה "לא עובדת". ההסתרה כאן היא אך ורק בצד הלקוח,
            // וסקריפטי המעקב ייטענו/לא ייטענו לפי העוגייה בטעינת העמוד הבאה.
            function hideBanner() {
                banner.style.animation = 'slideDown 0.3s ease-out';
                setTimeout(function() {
                    banner.classList.add('gdpr-hidden');
                }, 300);
            }
            
            // כפתור "כל העוגיות"
            acceptAllBtn.addEventListener('click', function() {
                setGDPRCookie('all');
                hideBanner();
            });
            
            // כפתור "רק עוגיות נחוצות"
            acceptEssentialBtn.addEventListener('click', function() {
                setGDPRCookie('essential');
                hideBanner();
            });
        });
        </script>
        <?php
    }
}
add_action('wp_footer', 'taliamichaeli_gdpr_consent_banner');

// === חלק 6: פונקציה לבדיקת סוג ההסכמה ===
function get_gdpr_consent_type() {
    if (isset($_COOKIE['gdpr_consent_type'])) {
        return $_COOKIE['gdpr_consent_type'];
    }
    return false;
}

// === חלק 7: פונקציות עזר נוספות ===

// פונקציה לבדיקה האם המשתמש נתן הסכמה
function has_gdpr_consent() {
    return isset($_COOKIE['gdpr_consent']);
}

// פונקציה לבדיקה האם המשתמש הסכים לכל העוגיות
function has_full_consent() {
    return isset($_COOKIE['gdpr_consent_type']) && $_COOKIE['gdpr_consent_type'] === 'all';
}

// פונקציה לאיפוס ההסכמה (לשימוש בעמוד הגדרות פרטיות למשל)
function reset_gdpr_consent() {
    ?>
    <script>
    function resetGDPRConsent() {
        document.cookie = "gdpr_consent=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
        document.cookie = "gdpr_consent_type=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
        window.location.reload();
    }
    </script>
    <?php
}

// === חלק 8: Shortcode לכפתור איפוס הסכמה (אופציונלי) ===
function gdpr_reset_button_shortcode() {
    ob_start();
    ?>
    <button onclick="resetGDPRConsent()" style="padding: 10px 20px; background: #4a90e2; color: white; border: none; border-radius: 5px; cursor: pointer;">
        שנה העדפות עוגיות
    </button>
    <script>
    function resetGDPRConsent() {
        document.cookie = "gdpr_consent=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
        document.cookie = "gdpr_consent_type=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
        window.location.reload();
    }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('gdpr_reset_button', 'gdpr_reset_button_shortcode');


// Add noindex, nofollow to a specific page
function add_nofollow_meta_tag() {
    // Get the current page URL
    $current_url = ( is_ssl() ? "https://" : "http://" ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

    // Check if it's the target page
    if ( $current_url === "https://taliamichaeli.com/shadow-talk/" ) {
        echo '<meta name="robots" content="noindex, nofollow">' . "\n";
    }
}
add_action( 'wp_head', 'add_nofollow_meta_tag' );



?>