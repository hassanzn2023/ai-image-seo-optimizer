<?php
/**
 * Plugin Name:       AI Image SEO Optimizer
 * Plugin URI:        https://github.com/hassanzn2023/ai-image-seo-optimizer
 * Description:       Optimize image title and alt text for Media Library images found on pages using AI. Includes bulk actions on Page Analyzer.
 * Version:           1.2.6
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Hassan Zein
 * Author URI:        https://github.com/hassanzn2023
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-image-seo-optimizer
 * Domain Path:       /languages
 * GitHub Plugin URI: hassanzn2023/ai-image-seo-optimizer
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('AISO_VERSION', '1.2.6'); // <-- تحديث الإصدار
define('AISO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AISO_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once AISO_PLUGIN_DIR . 'includes/plugin-updater.php';

// Define the default prompt content as a constant
define('AISO_DEFAULT_PROMPT', <<<PROMPT
**Role:** You are an intelligent expert in Image SEO, specializing in writing accurate, accessible, and search-optimized descriptive text.

**Task:** Based on the provided image and specified keywords, generate effective and engaging title and alt text attributes.

**Context:**
*   **Image:** (Will be provided via API - Analyze the actual image content, do not describe this instruction.)
*   **Primary Keyword:** '{primary}' (If empty or 'Not provided', focus solely on accurately describing the image.)
*   **Secondary Keywords:** '{secondary}' (If empty or 'Not provided', ignore them.)

**Instructions:**
1.  **Accurate Analysis:** Understand the core visual content and important details of the provided image.
2.  **Title Attribute:**
    *   Create a concise and descriptive title (around 60-70 characters).
    *   Naturally incorporate the primary keyword '{primary}' if provided and visually relevant.
3.  **Alt Text Attribute:**
    *   Create a descriptive and helpful alt text (around 100-125 characters).
    *   **Clearly describe the image** for someone who cannot see it.
    *   **Naturally integrate the primary keyword '{primary}'** (if provided and not 'Not provided') *within the description's context*. Do not force it or list it separately.
    *   Try to naturally include one or two secondary keywords from '{secondary}' (if provided and not 'Not provided') *only if they accurately describe a relevant aspect of the image* and do not compromise the text's flow or sound like stuffing.
    *   Avoid starting with phrases like 'Image of...' or 'Picture showing...'.
4.  **Style:** Be intelligent and natural in your phrasing. Prioritize accurate and helpful description, followed by smart, non-excessive keyword integration (avoid keyword stuffing).
5.  **Output Format:** **Mandatory and Critical:** Your response MUST be a valid JSON object **only**. Do not include any introductory text, explanations, headers, or ```json``` markers before or after the JSON object. The object must contain exactly two string keys: `\"title\"` and `\"alt\"`.
    **Strict Example Format:** `{\"title\": \"Final title here\", \"alt\": \"Final alt text here naturally including keywords\"}`
PROMPT
);
// =========================================================================
// Requirement Checks
// =========================================================================

add_action('plugins_loaded', 'aiso_check_requirements');

/**
 * Check plugin requirements on plugins_loaded hook.
 */
function aiso_check_requirements() {
	// Check for REST API support
	if (!function_exists('register_rest_route')) {
		add_action('admin_notices', function () {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('AI Image SEO Optimizer requires WordPress 4.7 or higher with the REST API enabled.', 'ai-image-seo-optimizer') . '</p></div>';
		});
	}

	// Check for pretty permalinks
	if (get_option('permalink_structure') === '') {
		add_action('admin_notices', function () {
			echo '<div class="notice notice-warning is-dismissible"><p>' . sprintf(
				wp_kses_post(__('AI Image SEO Optimizer works best with pretty permalinks. Please update your <a href="%s">permalink settings</a>.', 'ai-image-seo-optimizer')),
				esc_url(admin_url('options-permalink.php'))
			) . '</p></div>';
		});
	}
}

// =========================================================================
// Helper Functions
// =========================================================================

/**
 * Renders a custom post dropdown.
 *
 * @param array $args Arguments for the dropdown.
 */
function aiso_custom_post_dropdown($args = []) {
	$defaults = [
		'post_type'        => 'page',
		'selected'         => 0,
		'name'             => 'page_id',
		'id'               => '',
		'show_option_none' => '',
		'option_none_value'=> '',
		'post_status'      => ['publish'],
		'sort_column'      => 'post_title',
		'sort_order'       => 'ASC'
	];
	$args = wp_parse_args($args, $defaults);

	$post_types = is_array($args['post_type']) ? $args['post_type'] : [$args['post_type']];

	$query_args = [
		'post_type'              => $post_types,
		'post_status'            => $args['post_status'],
		'orderby'                => $args['sort_column'],
		'order'                  => $args['sort_order'],
		'posts_per_page'         => -1,
		'suppress_filters'       => false, // Allow filters
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false
	];

	$query = new WP_Query($query_args);
	$posts = $query->posts;

	$output = '<select name="' . esc_attr($args['name']) . '" id="' . esc_attr($args['id']) . '">';

	if ($args['show_option_none']) {
		$output .= '<option value="' . esc_attr($args['option_none_value']) . '">' . esc_html($args['show_option_none']) . '</option>';
	}

	foreach ($posts as $post) {
		$post_title = $post->post_title ? $post->post_title : sprintf(__('(no title) #%d', 'ai-image-seo-optimizer'), $post->ID);
		$post_type_obj = get_post_type_object($post->post_type);
		$post_type_name = $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type;
		$output .= '<option value="' . esc_attr($post->ID) . '" ' . selected($args['selected'], $post->ID, false) . '>'
				 . esc_html($post_title) . ' [' . esc_html($post_type_name) . ']'
				 . '</option>';
	}

	$output .= '</select>';

	echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Extract image URLs from post content, with enhanced support for Divi builder modules.
 *
 * @param int    $post_id      The post ID.
 * @param string $post_content The post content.
 * @return array Array of unique image URLs found.
 */
function aiso_extract_images_from_divi_page($post_id, $post_content) {
    // إضافة سجل لتصحيح الأخطاء
    error_log('===== AISO Image Extraction =====');
    error_log('Post ID: ' . $post_id);
    
    $image_urls = array();

    // 1. Standard HTML images
    preg_match_all('/<img[^>]+src\s*=\s*([\'"])(?<src>.*?)\1[^>]*>/i', $post_content, $standard_images);
    if (!empty($standard_images['src'])) {
        $image_urls = array_merge($image_urls, $standard_images['src']);
    }

    // 2. Check if Divi builder is used
    $uses_divi = get_post_meta($post_id, '_et_pb_use_builder', true);
    if ($uses_divi === 'on') {
        // 2.1 Divi Image Modules
        preg_match_all('/\[et_pb_image(?:.+?)src="([^"]+)"(?:.+?)\]/s', $post_content, $image_modules);
        if (!empty($image_modules[1])) {
            $image_urls = array_merge($image_urls, $image_modules[1]);
        }

        // 2.2 Divi Gallery Modules
        preg_match_all('/\[et_pb_gallery(?:.+?)gallery_ids="([^"]+)"(?:.+?)\]/s', $post_content, $gallery_modules);
        if (!empty($gallery_modules[1])) {
            foreach ($gallery_modules[1] as $gallery_ids) {
                $ids = explode(',', $gallery_ids);
                foreach ($ids as $attachment_id) {
                    $img_url = wp_get_attachment_url(trim($attachment_id));
                    if ($img_url) {
                        $image_urls[] = $img_url;
                    }
                }
            }
        }

        // 2.3 Divi Slider Modules
        preg_match_all('/\[et_pb_slide(?:.+?)image="([^"]+)"(?:.+?)\]/s', $post_content, $slide_modules);
        if (!empty($slide_modules[1])) {
            $image_urls = array_merge($image_urls, $slide_modules[1]);
        }

        // 2.4 Divi Carousel Modules (including Divi Pixel carousel)
        preg_match_all('/\[et_pb_module(?:.+?)image="([^"]+)"(?:.+?)\]/s', $post_content, $custom_modules);
        if (!empty($custom_modules[1])) {
            $image_urls = array_merge($image_urls, $custom_modules[1]);
        }

        // 2.5 Scan for additional image attributes in all Divi modules
        preg_match_all('/\[et_(?:pb|db)_[^\]]+\]/s', $post_content, $all_modules);
        if (!empty($all_modules[0])) {
            foreach ($all_modules[0] as $module) {
                // Check for various image attributes
                $img_attrs = array('image', 'src', 'logo', 'portrait', 'background_image', 'bg_img', 'image_src', 'photo');
                foreach ($img_attrs as $attr) {
                    if (preg_match('/' . $attr . '="([^"]+)"/s', $module, $match)) {
                        // Verify it's likely an image URL
                        if (strpos($match[1], '.jpg') !== false || 
                            strpos($match[1], '.jpeg') !== false || 
                            strpos($match[1], '.png') !== false || 
                            strpos($match[1], '.gif') !== false || 
                            strpos($match[1], '.webp') !== false ||
                            preg_match('/^\d+$/', $match[1])) { // Handle numeric IDs
                            
                            // If it's numeric, treat as attachment ID
                            if (is_numeric($match[1])) {
                                $img_url = wp_get_attachment_url(trim($match[1]));
                                if ($img_url) {
                                    $image_urls[] = $img_url;
                                }
                            } else {
                                $image_urls[] = $match[1];
                            }
                        }
                    }
                }
            }
        }

        // 2.6 Background Images (in shortcode attributes)
        preg_match_all('/(?:background_image|bg_img|bg_image|background_url)="([^"]+)"/s', $post_content, $bg_images);
        if (!empty($bg_images[1])) {
            $image_urls = array_merge($image_urls, $bg_images[1]);
        }

        // 3. Check for additional CSS inline styles with background images
        preg_match_all('/style="[^"]*background(?:-image)?:\s*url\([\'"]?([^\'"\)]+)[\'"]?\)[^"]*"/i', $post_content, $style_bg_images);
        if (!empty($style_bg_images[1])) {
            $image_urls = array_merge($image_urls, $style_bg_images[1]);
        }
        
        // مطابقة دقيقة لعناصر dipi-carousel-image مع وسائط الصور داخلها
        preg_match_all('/<span\s+class="[^"]*dipi-carousel-image[^"]*">\s*<img[^>]+src="([^"]+)"[^>]*>/is', $post_content, $dipi_carousel_imgs);
        if (!empty($dipi_carousel_imgs[1])) {
            $image_urls = array_merge($image_urls, $dipi_carousel_imgs[1]);
        }

        // مطابقة قائمة بجميع الصور الموجودة في dipi_carousel_child
        preg_match_all('/<div\s+class="[^"]*dipi_carousel_child[^"]*">.*?<img[^>]+src="([^"]+)"[^>]*>.*?<\/div>/is', $post_content, $dipi_children);
        if (!empty($dipi_children[1])) {
            $image_urls = array_merge($image_urls, $dipi_children[1]);
        }

        // مطابقة أي img داخل عنصر dipi
        preg_match_all('/<div\s+class="[^"]*dipi[^"]*">.*?<img[^>]+src="([^"]+)"[^>]*>.*?<\/div>/is', $post_content, $dipi_any_img);
        if (!empty($dipi_any_img[1])) {
            $image_urls = array_merge($image_urls, $dipi_any_img[1]);
        }

        // البحث بشكل مباشر عن فئة dipi-c-img في الصور
        preg_match_all('/<img[^>]*class="[^"]*dipi-c-img[^"]*"[^>]+src="([^"]+)"[^>]*>/is', $post_content, $dipi_class_imgs);
        if (!empty($dipi_class_imgs[1])) {
            $image_urls = array_merge($image_urls, $dipi_class_imgs[1]);
        }
        
        // 4. Look for image URLs in CSS classes that might contain image paths
        preg_match_all('/class="[^"]*(?:divi-image-wrap|divi-carousel-image|et-pb-slider-image)[^"]*"/i', $post_content, $image_classes);
        if (!empty($image_classes[0])) {
            // For each matching div with image class, try to find an image inside or associated with it
            foreach ($image_classes[0] as $class_match) {
                // This is an approximation - would need to parse DOM properly for accuracy
                $container_pattern = '/<div[^>]*' . preg_quote($class_match, '/') . '[^>]*>.*?<img[^>]+src\s*=\s*([\'"])(?<src>.*?)\1[^>]*>.*?<\/div>/is';
                preg_match_all($container_pattern, $post_content, $container_images);
                if (!empty($container_images['src'])) {
                    $image_urls = array_merge($image_urls, $container_images['src']);
                }
            }
        }
        
        // البحث عن الصور داخل عناصر span بفئة divi-carousel-image
        preg_match_all('/<span class="[^"]*divi-carousel-image[^"]*">\s*<img[^>]+src\s*=\s*([\'"])(?<src>.*?)\1[^>]*>/is', $post_content, $span_images);
        if (!empty($span_images['src'])) {
            $image_urls = array_merge($image_urls, $span_images['src']);
        }

        // البحث عن الصور داخل div بفئة divi-image-wrap
        preg_match_all('/<div class="[^"]*divi-image-wrap[^"]*">\s*(?:<[^>]+>\s*)*<img[^>]+src\s*=\s*([\'"])(?<src>.*?)\1[^>]*>/is', $post_content, $div_images);
        if (!empty($div_images['src'])) {
            $image_urls = array_merge($image_urls, $div_images['src']);
        }

        // البحث عن سمات srcset التي قد تحتوي على صور إضافية
        preg_match_all('/srcset\s*=\s*([\'"])(?<srcset>.*?)\1/is', $post_content, $srcset_matches);
        if (!empty($srcset_matches['srcset'])) {
            foreach ($srcset_matches['srcset'] as $srcset) {
                preg_match_all('/https?:\/\/[^\s,]+/i', $srcset, $srcset_urls);
                if (!empty($srcset_urls[0])) {
                    $image_urls = array_merge($image_urls, $srcset_urls[0]);
                }
            }
        }
        
        // أنماط إضافية لالتقاط الحالات الصعبة
        
        // البحث عن صور داخل عناصر span ذات فئة dipi-carousel-image بسمة href
        preg_match_all('/<span[^>]*class="[^"]*dipi-carousel-image[^"]*"[^>]*href="([^"]+)"/is', $post_content, $span_href_images);
        if (!empty($span_href_images[1])) {
            $image_urls = array_merge($image_urls, $span_href_images[1]);
        }
        
        // البحث عن جميع عناصر img مهما كان موقعها بنطاق autommerce.com
        preg_match_all('/<img[^>]+src="([^"]*autommerce\.com[^"]*)"[^>]*>/i', $post_content, $all_site_images);
        if (!empty($all_site_images[1])) {
            $image_urls = array_merge($image_urls, $all_site_images[1]);
        }
        
        // البحث في جميع بيانات JSON المضمنة التي قد تحتوي على صور
        if (preg_match_all('/data-(?:autoplay|settings|items|json)=([\'"])(.+?)\1/is', $post_content, $json_data)) {
            foreach ($json_data[2] as $json_string) {
                // محاولة العثور على URLs للصور في سلسلة JSON
                preg_match_all('/"(?:image|url|src)":\s*"(https?:\/\/[^"]+\.(?:jpg|jpeg|png|gif|webp))"/i', $json_string, $json_image_urls);
                if (!empty($json_image_urls[1])) {
                    $image_urls = array_merge($image_urls, $json_image_urls[1]);
                }
            }
        }
        
        // البحث عن جميع URLs التي تبدو كصور في المحتوى بأكمله
        preg_match_all('/https?:\/\/[^\s\'"()<>]+\.(?:jpg|jpeg|png|gif|webp)(?:\?[^\s\'"()<>]+)?/i', $post_content, $all_possible_images);
        if (!empty($all_possible_images[0])) {
            $image_urls = array_merge($image_urls, $all_possible_images[0]);
        }
        
        // 5. Divi Meta Fields (_et_pb_*) - More robust check
        global $wpdb;
        $meta_values = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT meta_value FROM $wpdb->postmeta WHERE post_id = %d AND (meta_key LIKE '_et_pb%%' OR meta_key LIKE '_et_module%%' OR meta_key LIKE '_divi_%%' OR meta_key LIKE '_dipi_%%')",
                $post_id
            )
        );

        foreach ($meta_values as $meta_value) {
            // 5.1 Look for full URLs within meta values
            preg_match_all('#https?://[^\s\'",\\\]+\.(?:jpg|jpeg|png|gif|webp)#i', $meta_value, $meta_images);
            if (!empty($meta_images[0])) {
                $image_urls = array_merge($image_urls, $meta_images[0]);
            }

            // 5.2 Look for serialized data that might contain image URLs
            if (is_serialized($meta_value)) {
                $unserialized_data = @unserialize($meta_value);
                if ($unserialized_data !== false) {
                    // Recursively look for image URLs in serialized arrays
                    $found_urls = aiso_find_image_urls_in_array($unserialized_data);
                    if (!empty($found_urls)) {
                        $image_urls = array_merge($image_urls, $found_urls);
                    }
                }
            }

            // 5.3 Look for potential attachment IDs stored as numbers
            if (preg_match_all('/(?:"|\[|,)(\d+)(?:"|,|\])/', $meta_value, $potential_ids)) {
                foreach ($potential_ids[1] as $maybe_id) {
                    if (is_numeric($maybe_id) && $maybe_id > 0) {
                        // Verify if it's actually an image attachment ID
                        $attachment_post = get_post($maybe_id);
                        if ($attachment_post && $attachment_post->post_type == 'attachment' && strpos($attachment_post->post_mime_type, 'image/') === 0) {
                            $img_url = wp_get_attachment_url($maybe_id);
                            if ($img_url) {
                                $image_urls[] = $img_url;
                            }
                        }
                    }
                }
            }
        }
    } // End Divi specific

    // 6. Clean and normalize URLs
    $cleaned_urls = [];
    foreach ($image_urls as $url) {
        $decoded_url = trim(html_entity_decode($url));
        if (!empty($decoded_url) && filter_var($decoded_url, FILTER_VALIDATE_URL)) {
            $parsed_url = wp_parse_url($decoded_url);
            $clean_path = $parsed_url['path'] ?? '';
            // Remove WP thumbnail size suffixes (e.g., -150x150)
            $clean_path = preg_replace('/(-\d+x\d+)(\.(?:jpg|jpeg|png|gif|webp))$/i', '$2', $clean_path);

            if ($parsed_url && isset($parsed_url['scheme']) && isset($parsed_url['host'])) {
                $cleaned_urls[] = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $clean_path;
            }
        }
    }

    // Return unique URLs
    $image_urls = array_values(array_unique($cleaned_urls));
    
    // تصحيح الأخطاء: سجل الصور التي تم العثور عليها
    error_log('Found ' . count($image_urls) . ' images');
    foreach ($image_urls as $idx => $url) {
        error_log("Image $idx: $url");
    }
    error_log('===== End of AISO Image Extraction =====');
    
    return $image_urls;
}
/**
 * Helper function to recursively find image URLs in arrays (for serialized meta data)
 * 
 * @param mixed $data Array or object to search through
 * @return array Found image URLs
 */
function aiso_find_image_urls_in_array($data) {
    $found_urls = array();
    
    if (is_array($data) || is_object($data)) {
        foreach ($data as $key => $value) {
            // Check if the key indicates it might be an image
            $image_related_keys = array('image', 'img', 'src', 'url', 'background', 'bg', 'photo', 'picture', 'thumbnail');
            $key_might_be_image = false;
            
            if (is_string($key)) {
                foreach ($image_related_keys as $img_key) {
                    if (stripos($key, $img_key) !== false) {
                        $key_might_be_image = true;
                        break;
                    }
                }
            }
            
            if (is_string($value)) {
                // If it looks like a URL and ends with an image extension
                if ((stripos($value, 'http') === 0 || stripos($value, '/') === 0) && 
                    preg_match('/\.(jpg|jpeg|png|gif|webp)(\?.*)?$/i', $value)) {
                    $found_urls[] = $value;
                }
                // If it's numeric and key suggests it's an image, it might be an ID
                else if (is_numeric($value) && $key_might_be_image) {
                    $img_url = wp_get_attachment_url((int)$value);
                    if ($img_url) {
                        $found_urls[] = $img_url;
                    }
                }
            } else if (is_array($value) || is_object($value)) {
                // Recursively search nested arrays/objects
                $nested_urls = aiso_find_image_urls_in_array($value);
                if (!empty($nested_urls)) {
                    $found_urls = array_merge($found_urls, $nested_urls);
                }
            }
        }
    }
    
    return $found_urls;
}
// Note: Function aiso_extract_h1_from_divi_page() has been removed.

// =========================================================================
// Settings API
// =========================================================================

add_action('admin_init', 'aiso_register_settings');

/**
 * Get the configured Gemini API Key.
 * @return string API Key or empty string.
 */
function aiso_get_api_key() {
	$options = get_option('aiso_settings');
	return isset($options['gemini_api_key']) ? trim($options['gemini_api_key']) : '';
}

/**
 * Get the configured Gemini Model.
 * @return string Model name ('gemini-1.5-flash' as default).
 */
function aiso_get_gemini_model() {
	$options = get_option('aiso_settings');
	// Default to 1.5 flash if not set or empty
	return isset($options['gemini_model']) && !empty($options['gemini_model']) ? trim($options['gemini_model']) : 'gemini-1.5-flash';
}

/**
 * Get the configured or default AI Prompt.
 * @return string The prompt text.
 */
function aiso_get_custom_prompt() {
    $options = get_option('aiso_settings');
    // إرجاع الـ prompt المحفوظ إذا كان موجوداً وغير فارغ، وإلا إرجاع الافتراضي
    return isset($options['custom_prompt']) && !empty(trim($options['custom_prompt']))
           ? trim($options['custom_prompt'])
           : AISO_DEFAULT_PROMPT;
}

/**
 * Register settings, sections, and fields.
 */
function aiso_register_settings() {
	register_setting('aiso_settings_group', 'aiso_settings', 'aiso_sanitize_settings');

	add_settings_section(
		'aiso_api_settings_section',
		__('Gemini API Settings', 'ai-image-seo-optimizer'),
		'aiso_settings_section_callback',
		'aiso-settings'
	);

	add_settings_field(
		'aiso_gemini_api_key',
		__('Gemini API Key', 'ai-image-seo-optimizer'),
		'aiso_api_key_field_callback',
		'aiso-settings',
		'aiso_api_settings_section'
	);

	add_settings_field(
		'aiso_gemini_model',
		__('Gemini Model', 'ai-image-seo-optimizer'),
		'aiso_model_field_callback',
		'aiso-settings',
		'aiso_api_settings_section'
	);
    // --- أضف الكود التالي هنا ---
    add_settings_field(
        'aiso_custom_prompt', // معرف الحقل الجديد
        __('Custom AI Prompt', 'ai-image-seo-optimizer'), // عنوان الحقل
        'aiso_custom_prompt_field_callback', // الدالة التي ستعرض الحقل
        'aiso-settings', // صفحة الإعدادات
        'aiso_api_settings_section' // القسم الذي ينتمي إليه الحقل
    );
}

/**
 * Sanitize settings input.
 * @param array $input Raw input data.
 * @return array Sanitized input data.
 */
function aiso_sanitize_settings($input) {
	$sanitized_input = [];
	$current_options = get_option('aiso_settings', []); // Get defaults if not set

	// Sanitize API Key
	if (isset($input['gemini_api_key'])) {
		$sanitized_input['gemini_api_key'] = sanitize_text_field(trim($input['gemini_api_key']));
	} else {
		// Retain previous value if not submitted (e.g., field disabled)
		$sanitized_input['gemini_api_key'] = $current_options['gemini_api_key'] ?? '';
	}

	// Sanitize Model Selection
	if (isset($input['gemini_model'])) {
		$allowed_models = [
			'gemini-1.5-pro',
			'gemini-1.5-flash'
		]; // Removed 2.5 preview
		$model = sanitize_text_field(trim($input['gemini_model']));
		// Ensure the selected model is allowed, otherwise fallback to default
		$sanitized_input['gemini_model'] = in_array($model, $allowed_models) ? $model : 'gemini-1.5-flash';
	} else {
		// Retain previous value if not submitted, but ensure it's still valid
		$current_model = $current_options['gemini_model'] ?? 'gemini-1.5-flash';
		$allowed_models_check = [ 'gemini-1.5-pro', 'gemini-1.5-flash' ];
		$sanitized_input['gemini_model'] = in_array($current_model, $allowed_models_check) ? $current_model : 'gemini-1.5-flash';
	}
    
    if (isset($input['custom_prompt'])) {
        // استخدام sanitize_textarea_field للحفاظ على الأسطر الجديدة ولكن إزالة HTML الضار
        $sanitized_input['custom_prompt'] = sanitize_textarea_field(trim($input['custom_prompt']));
        // اختياري: استعادة الافتراضي إذا كان الحقل فارغًا تمامًا بعد التنظيف
        // if (empty($sanitized_input['custom_prompt'])) {
        //     $sanitized_input['custom_prompt'] = AISO_DEFAULT_PROMPT;
        // }
    } else {
        // الاحتفاظ بالقيمة السابقة إذا لم يتم إرسال الحقل
        $sanitized_input['custom_prompt'] = $current_options['custom_prompt'] ?? AISO_DEFAULT_PROMPT;
    }

	return $sanitized_input;
}

/**
 * Callback for settings section description.
 */
function aiso_settings_section_callback() {
	echo '<p>' . esc_html__('Enter your Google Gemini API key and select the model. Get a key from Google AI Studio or Google Cloud Console.', 'ai-image-seo-optimizer') . '</p>';
}

/**
 * Callback for API key field.
 */
function aiso_api_key_field_callback() {
	$api_key = aiso_get_api_key();
	printf(
		'<input type="password" id="aiso_gemini_api_key" name="aiso_settings[gemini_api_key]" value="%s" size="50" class="regular-text" autocomplete="off" />',
		esc_attr($api_key)
	);
	echo '<p class="description">' . esc_html__('Your API key is stored securely.', 'ai-image-seo-optimizer') . '</p>';
}

/**
 * Callback for model selection field.
 */
function aiso_model_field_callback() {
	$model = aiso_get_gemini_model();
	?>
	<select name="aiso_settings[gemini_model]" id="aiso_gemini_model">
		 <option value="gemini-1.5-flash" <?php selected($model, 'gemini-1.5-flash'); ?>>
			 <?php esc_html_e('Gemini 1.5 Flash (Stable & Cost-Effective)', 'ai-image-seo-optimizer'); ?>
		 </option>
		<option value="gemini-1.5-pro" <?php selected($model, 'gemini-1.5-pro'); ?>>
			 <?php esc_html_e('Gemini 1.5 Pro (Stable Advanced)', 'ai-image-seo-optimizer'); ?>
		</option>
	</select>
	<p class="description"><?php esc_html_e('Select the Gemini model. Flash models are generally faster and cheaper than Pro.', 'ai-image-seo-optimizer'); ?></p>
	<?php
}

function aiso_custom_prompt_field_callback() {
    $options = get_option('aiso_settings');
    $custom_prompt = isset($options['custom_prompt']) && !empty(trim($options['custom_prompt']))
                     ? $options['custom_prompt']
                     : AISO_DEFAULT_PROMPT;

    printf(
        '<textarea id="aiso_custom_prompt" name="aiso_settings[custom_prompt]" rows="15" class="large-text code">%s</textarea>',
        esc_textarea($custom_prompt)
    );
    echo '<p class="description">' .
         wp_kses_post(__( 'Customize the prompt sent to the AI. Use placeholders <code>{primary}</code> and <code>{secondary}</code> for keywords. <strong>Important:</strong> The AI response MUST remain a valid JSON object like <code>{"title": "...", "alt": "..."}</code>. Changing this structure will break the plugin.', 'ai-image-seo-optimizer')) .
         '</p>';
    // --- تغيير هنا: أضف ID وأزل onclick ---
    echo '<p><button type="button" id="aiso_restore_default_btn" class="button button-secondary" onclick="if(typeof aiso_default_prompt_text !== \'undefined\') { document.getElementById(\'aiso_custom_prompt\').value = aiso_default_prompt_text; }">' . esc_html__('Restore Default Prompt', 'ai-image-seo-optimizer') . '</button></p>';
    
    echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    var restoreBtn = document.getElementById("aiso_restore_default_btn");
    if (restoreBtn) {
        restoreBtn.addEventListener("click", function(e) {
            e.preventDefault();
            if (typeof aiso_default_prompt_text !== "undefined") {
                document.getElementById("aiso_custom_prompt").value = aiso_default_prompt_text;
            } else {
                console.error("Default prompt text not available");
                alert("Could not restore default prompt. Data missing.");
            }
        });
    }
});
</script>';
    // --- نهاية التغيير ---

    // إضافة النص الافتراضي إلى JavaScript للوصول إليه لاحقًا
    // تأكد من أن هذا الكود يُنفذ فقط في صفحة الإعدادات
    if (isset($_GET['page']) && $_GET['page'] === 'aiso-settings') {
         echo "<script type=\"text/javascript\">";
         echo "var aiso_default_prompt_text = " . json_encode(AISO_DEFAULT_PROMPT) . ";"; // استخدام json_encode آمن هنا
         echo "</script>";
    }
}

// =========================================================================
// Admin Menu & Pages
// =========================================================================

add_action('admin_menu', 'aiso_add_admin_menu');

/**
 * Add admin menu pages.
 */
function aiso_add_admin_menu() {
	add_menu_page(
		__('AI Image SEO', 'ai-image-seo-optimizer'),
		__('AI Image SEO', 'ai-image-seo-optimizer'),
		'manage_options',
		'ai-image-seo-optimizer',
		'aiso_render_admin_page', // Main page callback
		'dashicons-images-alt2',
		80
	);

	add_submenu_page(
		'ai-image-seo-optimizer',
		__('All Images', 'ai-image-seo-optimizer'),
		__('All Images', 'ai-image-seo-optimizer'),
		'manage_options',
		'ai-image-seo-optimizer', // Slug for the main page
		'aiso_render_admin_page'
	);

	add_submenu_page(
		'ai-image-seo-optimizer',
		__('Page Content Analyzer', 'ai-image-seo-optimizer'),
		__('Page Analyzer', 'ai-image-seo-optimizer'),
		'manage_options',
		'aiso-page-content-analyzer',
		'aiso_render_page_content_analyzer_page'
	);

	add_submenu_page(
		'ai-image-seo-optimizer',
		__('Settings', 'ai-image-seo-optimizer'),
		__('Settings', 'ai-image-seo-optimizer'),
		'manage_options',
		'aiso-settings',
		'aiso_render_settings_page'
	);
}

/**
 * Render the main admin page (All Images List).
 */
function aiso_render_admin_page() {
	$api_key = aiso_get_api_key();
	$model   = aiso_get_gemini_model();
	?>
	<div class="wrap aiso-admin-wrap">
		<h1><?php echo esc_html__('AI Image SEO Optimizer - All Images', 'ai-image-seo-optimizer'); ?></h1>

		<?php if (empty($api_key)) : ?>
			<div class="notice notice-warning is-dismissible">
				<p><?php printf(
					wp_kses_post(__('<strong>Warning:</strong> Gemini API Key is not configured. Please go to the <a href="%s">Settings page</a> to add your API key.', 'ai-image-seo-optimizer')),
					esc_url(admin_url('admin.php?page=aiso-settings'))
				); ?></p>
			</div>
		<?php else : ?>
			<div class="notice notice-info is-dismissible aiso-model-notice">
				<p><?php printf(
					esc_html__('Using Gemini model: %s. Keywords are optional. Change model in %s.', 'ai-image-seo-optimizer'),
					'<strong>' . esc_html($model) . '</strong>',
					'<a href="' . esc_url(admin_url('admin.php?page=aiso-settings')) . '">' . esc_html__('Settings', 'ai-image-seo-optimizer') . '</a>'
				); ?></p>
			</div>
		<?php endif; ?>

		<div id="aiso-error-log" class="aiso-error-message notice notice-error is-dismissible" style="display: none;"><p></p></div>
		<div id="aiso-success-log" class="aiso-success-message notice notice-success is-dismissible" style="display: none;"><p></p></div>

		<div class="aiso-bulk-actions">
			<button id="aiso-generate-all-btn" class="button" disabled><?php esc_html_e('Generate All Missing (Coming Soon)', 'ai-image-seo-optimizer'); ?></button>
			<button id="aiso-update-all-btn" class="button" disabled><?php esc_html_e('Update All Generated (Coming Soon)', 'ai-image-seo-optimizer'); ?></button>
			<span class="spinner aiso-bulk-spinner"></span>
			<p class="description"><?php esc_html_e('Bulk actions are currently disabled on this page.', 'ai-image-seo-optimizer'); // Clarified message ?></p>
		</div>

		<form id="aiso-image-form" method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page'] ?? 'ai-image-seo-optimizer'); ?>" />

			<div class="table-responsive">
				<table class="wp-list-table widefat fixed striped table-view-list media aiso-image-table">
					<thead>
						<tr>
							<th scope="col" class="manage-column column-thumbnail column-primary"><?php esc_html_e('Preview', 'ai-image-seo-optimizer'); ?></th>
							<th scope="col" class="manage-column column-image-info"><?php esc_html_e('Image Info', 'ai-image-seo-optimizer'); ?></th>
							<th scope="col" class="manage-column column-current-meta"><?php esc_html_e('Current Meta', 'ai-image-seo-optimizer'); ?></th>
							<th scope="col" class="manage-column column-keywords"><?php esc_html_e('Keywords (Optional)', 'ai-image-seo-optimizer'); ?></th>
							<th scope="col" class="manage-column column-ai-meta"><?php esc_html_e('AI Generated', 'ai-image-seo-optimizer'); ?></th>
							<th scope="col" class="manage-column column-actions"><?php esc_html_e('Actions', 'ai-image-seo-optimizer'); ?></th>
						</tr>
					</thead>
					<tbody id="the-list">
						<?php
						$current_page   = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
						$posts_per_page = 20; // Or make this a setting?
						$args = [
							'post_type'      => 'attachment',
							'post_mime_type' => 'image',
							'post_status'    => 'inherit',
							'posts_per_page' => $posts_per_page,
							'paged'          => $current_page,
							'orderby'        => 'date',
							'order'          => 'DESC'
						];
						$image_query = new WP_Query($args);

						if ($image_query->have_posts()) :
							while ($image_query->have_posts()) :
								$image_query->the_post();
								$image_id = get_the_ID();
								$image_url = wp_get_attachment_url($image_id);
								$image_title = get_the_title($image_id);
								$image_alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
								$file_path = get_attached_file($image_id);
								$file_name = $file_path ? wp_basename($file_path) : __('File not found', 'ai-image-seo-optimizer');
								$edit_link = get_edit_post_link($image_id);
								$can_process = !empty($api_key); // Can process if API key exists
								?>
								<tr id="post-<?php echo esc_attr($image_id); ?>" data-image-id="<?php echo esc_attr($image_id); ?>" class="aiso-library-image">
									<td class="thumbnail column-thumbnail column-primary" data-colname="<?php esc_attr_e('Preview', 'ai-image-seo-optimizer'); ?>">
										<?php echo wp_get_attachment_image($image_id, 'thumbnail', false, ['class' => 'aiso-thumbnail', 'loading' => 'lazy']); ?>
										<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e('Show more details', 'ai-image-seo-optimizer'); ?></span></button>
									</td>
									<td class="column-image-info" data-colname="<?php esc_attr_e('Image Info', 'ai-image-seo-optimizer'); ?>">
										<strong><?php echo esc_html($file_name); ?></strong>
										<div class="row-actions">
											<span class="edit"><a href="<?php echo esc_url($edit_link); ?>" target="_blank"><?php esc_html_e('Edit', 'ai-image-seo-optimizer'); ?></a> | </span>
											<span class="view"><a href="<?php echo esc_url($image_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('View', 'ai-image-seo-optimizer'); ?></a></span>
										</div>
									</td>
									<td class="column-current-meta" data-colname="<?php esc_attr_e('Current Meta', 'ai-image-seo-optimizer'); ?>">
										<div class="aiso-meta-display"><strong><?php esc_html_e('Title:', 'ai-image-seo-optimizer'); ?></strong> <span class="current-title-text"><?php echo esc_html($image_title); ?></span></div>
										<div class="aiso-meta-display"><strong><?php esc_html_e('Alt:', 'ai-image-seo-optimizer'); ?></strong> <span class="current-alt-text"><?php echo esc_html($image_alt); ?></span></div>
									</td>
									<td class="column-keywords" data-colname="<?php esc_attr_e('Keywords (Optional)', 'ai-image-seo-optimizer'); ?>">
										<div>
											<label for="focus-keyword-<?php echo esc_attr($image_id); ?>" class="screen-reader-text"><?php esc_html_e('Focus Keyword (Optional)', 'ai-image-seo-optimizer'); ?></label>
											<input type="text" id="focus-keyword-<?php echo esc_attr($image_id); ?>" class="focus-keyword-input" placeholder="<?php esc_attr_e('Focus Keyword (Optional)', 'ai-image-seo-optimizer'); ?>" <?php disabled(!$can_process); ?> data-image-id="<?php echo esc_attr($image_id); ?>">
										</div>
										<div>
											<label for="secondary-keyword-<?php echo esc_attr($image_id); ?>" class="screen-reader-text"><?php esc_html_e('Secondary Keyword (Optional)', 'ai-image-seo-optimizer'); ?></label>
											<input type="text" id="secondary-keyword-<?php echo esc_attr($image_id); ?>" class="secondary-keyword-input" placeholder="<?php esc_attr_e('Secondary Keyword (Optional)', 'ai-image-seo-optimizer'); ?>" <?php disabled(!$can_process); ?> data-image-id="<?php echo esc_attr($image_id); ?>">
										</div>
									</td>
									<td class="column-ai-meta" data-colname="<?php esc_attr_e('AI Generated', 'ai-image-seo-optimizer'); ?>">
										<div class="ai-output-wrapper">
											<span class="ai-title-output"></span>
											<span class="ai-alt-output"></span>
										</div>
									</td>
									<td class="column-actions" data-colname="<?php esc_attr_e('Actions', 'ai-image-seo-optimizer'); ?>">
										<button type="button" class="button button-secondary generate-ai-button" data-image-id="<?php echo esc_attr($image_id); ?>" <?php disabled(!$can_process); ?>>
											<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
											<?php esc_html_e('Generate', 'ai-image-seo-optimizer'); ?>
										</button>
										<button type="button" class="button button-primary update-meta-button" data-image-id="<?php echo esc_attr($image_id); ?>" disabled>
											<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
											<?php esc_html_e('Update', 'ai-image-seo-optimizer'); ?>
										</button>
										<span class="spinner"></span>
									</td>
								</tr>
							<?php endwhile; ?>
						<?php else : ?>
							<tr>
								<td colspan="6"><?php esc_html_e('No images found in the Media Library.', 'ai-image-seo-optimizer'); ?></td>
							</tr>
						<?php endif; ?>
						<?php wp_reset_postdata(); ?>
					</tbody>
					<tfoot>
						<tr>
							<th scope="col"><?php esc_html_e('Preview', 'ai-image-seo-optimizer'); ?></th>
							<th scope="col"><?php esc_html_e('Image Info', 'ai-image-seo-optimizer'); ?></th>
							<th scope="col"><?php esc_html_e('Current Meta', 'ai-image-seo-optimizer'); ?></th>
							<th scope="col"><?php esc_html_e('Keywords (Optional)', 'ai-image-seo-optimizer'); ?></th>
							<th scope="col"><?php esc_html_e('AI Generated', 'ai-image-seo-optimizer'); ?></th>
							<th scope="col"><?php esc_html_e('Actions', 'ai-image-seo-optimizer'); ?></th>
						</tr>
					</tfoot>
				</table>
			</div>

			<?php
			// Pagination
			$total_pages = $image_query->max_num_pages;
			if ($total_pages > 1) {
				$pagination_args = [
					'base'         => add_query_arg('paged', '%#%'),
					'format'       => '',
					'total'        => $total_pages,
					'current'      => $current_page,
					'show_all'     => false,
					'end_size'     => 1,
					'mid_size'     => 2,
					'prev_next'    => true,
					'prev_text'    => __('« Previous', 'ai-image-seo-optimizer'),
					'next_text'    => __('Next »', 'ai-image-seo-optimizer'),
					'type'         => 'list',
					'add_args'     => false, // important
					'add_fragment' => '',
				];
				echo '<div class="tablenav bottom"><div class="tablenav-pages aiso-pagination">'
					. paginate_links($pagination_args) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. '</div></div>';
			}
			?>
		</form>
	</div>
	<?php
}

/**
 * Render the Page Content Analyzer page.
 */
function aiso_render_page_content_analyzer_page() {
	$api_key          = aiso_get_api_key();
	$model            = aiso_get_gemini_model();
	$selected_page_id = 0;
	$library_images_data = [];
	$post_to_analyze = null; // Initialize post object

	// Process form submission to fetch page content
	if (isset($_POST['aiso_fetch_page_content']) && isset($_POST['aiso_page_content_nonce_field'])) {
		if (wp_verify_nonce(sanitize_key($_POST['aiso_page_content_nonce_field']), 'aiso_page_content_nonce')) {
			$selected_page_id = isset($_POST['aiso_select_page_for_content']) ? absint($_POST['aiso_select_page_for_content']) : 0;

			if ($selected_page_id > 0) {
				$post_to_analyze = get_post($selected_page_id);

				// Validate the selected post
				if ($post_to_analyze && $post_to_analyze->post_type === 'page' && in_array($post_to_analyze->post_status, ['publish', 'private', 'draft'], true)) {
					$post_content = $post_to_analyze->post_content;

					// Extract images (without H1)
					$image_urls = aiso_extract_images_from_divi_page($selected_page_id, $post_content);

					if (!empty($image_urls)) {
						foreach ($image_urls as $image_url) {
							$attachment_id = attachment_url_to_postid($image_url);
							if ($attachment_id) {
								$image_data = [
									'url'             => $image_url,
									'id'              => $attachment_id,
									'filename'        => '',
									'title'           => '',
									'alt'             => '',
									'thumbnail_html'  => '',
									'edit_link'       => '',
									'is_library_item' => true
								];

								$attached_file = get_attached_file($attachment_id);
								$image_data['filename'] = $attached_file ? wp_basename($attached_file) : __('File not found', 'ai-image-seo-optimizer');
								$image_data['title'] = get_the_title($attachment_id);
								$image_data['alt'] = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
								$image_data['thumbnail_html'] = wp_get_attachment_image($attachment_id, 'thumbnail', false, ['class' => 'aiso-thumbnail', 'loading' => 'lazy']);
								$image_data['edit_link'] = get_edit_post_link($attachment_id);

								$library_images_data[$attachment_id] = $image_data; // Use ID as key to avoid duplicates
							}
						}
						$library_images_data = array_values($library_images_data); // Re-index array
					}
				} else {
					// Invalid post selected
					add_action('admin_notices', function () {
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Selected item is not a valid Page or is not accessible.', 'ai-image-seo-optimizer') . '</p></div>';
					});
					$selected_page_id = 0; // Reset selection
					$post_to_analyze = null;
				}
			} else {
				// No page selected
				add_action('admin_notices', function () {
					echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('Please select a page to analyze.', 'ai-image-seo-optimizer') . '</p></div>';
				});
				$selected_page_id = 0;
			}
		} else {
			// Nonce check failed
			add_action('admin_notices', function () {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Security check failed.', 'ai-image-seo-optimizer') . '</p></div>';
			});
			$selected_page_id = 0;
		}
	} // End form processing
	?>
	<div class="wrap aiso-admin-wrap aiso-page-analyzer-wrap">
		<h1><?php echo esc_html__('Page Content Analyzer', 'ai-image-seo-optimizer'); ?></h1>
		<p><?php echo esc_html__('Select a page to find Media Library images within its content. You can then generate SEO meta using optional keywords.', 'ai-image-seo-optimizer'); // Updated description ?></p>

		<?php if (empty($api_key)) : ?>
			<div class="notice notice-warning is-dismissible">
				<p><?php printf(
					wp_kses_post(__('<strong>Warning:</strong> Gemini API Key is not configured. Please go to the <a href="%s">Settings page</a>.', 'ai-image-seo-optimizer')),
					esc_url(admin_url('admin.php?page=aiso-settings'))
				); ?></p>
			</div>
		<?php else : ?>
			<div class="notice notice-info is-dismissible aiso-model-notice">
				<p><?php printf(
					esc_html__('Using Gemini model: %s. Keywords are optional. Change model in %s.', 'ai-image-seo-optimizer'), // Updated description
					'<strong>' . esc_html($model) . '</strong>',
					'<a href="' . esc_url(admin_url('admin.php?page=aiso-settings')) . '">' . esc_html__('Settings', 'ai-image-seo-optimizer') . '</a>'
				); ?></p>
			</div>
		<?php endif; ?>

		<?php do_action('admin_notices'); // Display any notices added above ?>

		<form method="post" action="<?php echo esc_url(admin_url('admin.php?page=aiso-page-content-analyzer')); ?>" class="aiso-page-select-form">
			<?php wp_nonce_field('aiso_page_content_nonce', 'aiso_page_content_nonce_field'); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row">
						<label for="aiso_select_page_for_content"><?php esc_html_e('Select Page to Analyze', 'ai-image-seo-optimizer'); ?></label>
					</th>
					<td>
						<?php
						aiso_custom_post_dropdown([
							'post_type'        => 'page',
							'post_status'      => ['publish', 'private', 'draft'],
							'show_option_none' => esc_html__('-- Select a Page --', 'ai-image-seo-optimizer'),
							'option_none_value'=> '0',
							'name'             => 'aiso_select_page_for_content',
							'id'               => 'aiso_select_page_for_content',
							'selected'         => $selected_page_id,
							'sort_column'      => 'post_title',
							'sort_order'       => 'ASC'
						]);
						?>
					</td>
					<td>
						<?php submit_button(__('Fetch Images', 'ai-image-seo-optimizer'), 'primary', 'aiso_fetch_page_content', false); ?>
					</td>
				</tr>
			</table>
		</form>

		<?php if ($selected_page_id > 0 && isset($post_to_analyze)) : ?>
			<hr>
			<h2 class="aiso-results-header"><?php printf(
				esc_html__('Media Library Images found in: %s', 'ai-image-seo-optimizer'),
				'<em>' . esc_html(get_the_title($selected_page_id)) . '</em>'
			); ?></h2>

			<?php // H1 display block removed ?>

			<?php if (!empty($library_images_data) && !empty($api_key)) : ?>
				<div class="aiso-analyzer-bulk-actions">
					<button type="button" id="aiso-analyzer-generate-all-btn" class="button button-secondary">
						<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><?php esc_html_e('Generate All', 'ai-image-seo-optimizer'); ?>
					</button>
					<button type="button" id="aiso-analyzer-update-all-btn" class="button button-primary">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><?php esc_html_e('Update All Generated', 'ai-image-seo-optimizer'); ?>
					</button>
					<span class="spinner aiso-analyzer-bulk-spinner"></span>
					<span class="aiso-bulk-progress"></span>
					<p class="description"><?php esc_html_e('Process all images listed below.', 'ai-image-seo-optimizer'); ?></p>
				</div>
			<?php endif; ?>

			<div id="aiso-error-log" class="aiso-error-message notice notice-error is-dismissible" style="display: none;"><p></p></div>
			<div id="aiso-success-log" class="aiso-success-message notice notice-success is-dismissible" style="display: none;"><p></p></div>

			<?php if (!empty($library_images_data)) : ?>
				<form id="aiso-image-form-analyzer" method="get">
					<div class="table-responsive">
						<table class="wp-list-table widefat fixed striped table-view-list media aiso-image-table aiso-analyzer-table">
							<thead>
								<tr>
									<th scope="col" class="manage-column column-thumbnail column-primary"><?php esc_html_e('Preview', 'ai-image-seo-optimizer'); ?></th>
									<th scope="col" class="manage-column column-image-info"><?php esc_html_e('Image Info', 'ai-image-seo-optimizer'); ?></th>
									<th scope="col" class="manage-column column-current-meta"><?php esc_html_e('Current Meta', 'ai-image-seo-optimizer'); ?></th>
									<th scope="col" class="manage-column column-keywords"><?php esc_html_e('Keywords (Optional)', 'ai-image-seo-optimizer'); ?></th>
									<th scope="col" class="manage-column column-ai-meta"><?php esc_html_e('AI Generated', 'ai-image-seo-optimizer'); ?></th>
									<th scope="col" class="manage-column column-actions"><?php esc_html_e('Actions', 'ai-image-seo-optimizer'); ?></th>
								</tr>
							</thead>
							<tbody id="the-list">
								<?php
								foreach ($library_images_data as $img) :
									$image_id = $img['id'];
									$row_id   = 'post-' . $image_id;
									$can_process = !empty($api_key);
								?>
									<tr id="<?php echo esc_attr($row_id); ?>" data-image-id="<?php echo esc_attr($image_id); ?>" class="aiso-library-image">
										<td class="thumbnail column-thumbnail column-primary" data-colname="<?php esc_attr_e('Preview', 'ai-image-seo-optimizer'); ?>">
											<?php echo $img['thumbnail_html']; // Already escaped by WP ?>
											<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e('Show more details', 'ai-image-seo-optimizer'); ?></span></button>
										</td>
										<td class="column-image-info" data-colname="<?php esc_attr_e('Image Info', 'ai-image-seo-optimizer'); ?>">
											<strong><?php echo esc_html($img['filename']); ?></strong>
											<?php if ($img['edit_link']) : ?>
												<div class="row-actions">
													<span class="edit"><a href="<?php echo esc_url($img['edit_link']); ?>" target="_blank"><?php esc_html_e('Edit Media', 'ai-image-seo-optimizer'); ?></a> | </span>
													<span class="view"><a href="<?php echo esc_url($img['url']); ?>" target="_blank" rel="noopener"><?php esc_html_e('View', 'ai-image-seo-optimizer'); ?></a></span>
												</div>
											<?php endif; ?>
										</td>
										<td class="column-current-meta" data-colname="<?php esc_attr_e('Current Meta', 'ai-image-seo-optimizer'); ?>">
											<div class="aiso-meta-display"><strong><?php esc_html_e('Title:', 'ai-image-seo-optimizer'); ?></strong> <span class="current-title-text"><?php echo esc_html($img['title']); ?></span></div>
											<div class="aiso-meta-display"><strong><?php esc_html_e('Alt:', 'ai-image-seo-optimizer'); ?></strong> <span class="current-alt-text"><?php echo esc_html($img['alt']); ?></span></div>
										</td>
										<td class="column-keywords" data-colname="<?php esc_attr_e('Keywords (Optional)', 'ai-image-seo-optimizer'); ?>"> <?php // Removed aiso-keywords-h1-cell class ?>
											<div>
												<label for="focus-keyword-<?php echo esc_attr($row_id); ?>" class="screen-reader-text"><?php esc_html_e('Focus Keyword (Optional)', 'ai-image-seo-optimizer'); ?></label>
												<input type="text" id="focus-keyword-<?php echo esc_attr($row_id); ?>" class="focus-keyword-input" placeholder="<?php esc_attr_e('Focus Keyword (Optional)', 'ai-image-seo-optimizer'); ?>" <?php disabled(!$can_process); ?> data-image-id="<?php echo esc_attr($image_id); ?>">
											</div>
											<div>
												<label for="secondary-keyword-<?php echo esc_attr($row_id); ?>" class="screen-reader-text"><?php esc_html_e('Secondary Keyword (Optional)', 'ai-image-seo-optimizer'); ?></label>
												<input type="text" id="secondary-keyword-<?php echo esc_attr($row_id); ?>" class="secondary-keyword-input" placeholder="<?php esc_attr_e('Secondary Keyword (Optional)', 'ai-image-seo-optimizer'); ?>" <?php disabled(!$can_process); ?> data-image-id="<?php echo esc_attr($image_id); ?>">
											</div>
											<?php // H1 context div removed ?>
										</td>
										<td class="column-ai-meta" data-colname="<?php esc_attr_e('AI Generated', 'ai-image-seo-optimizer'); ?>">
											<div class="ai-output-wrapper">
												<span class="ai-title-output"></span>
												<span class="ai-alt-output"></span>
											</div>
										</td>
										<td class="column-actions" data-colname="<?php esc_attr_e('Actions', 'ai-image-seo-optimizer'); ?>">
											<button type="button" class="button button-secondary generate-ai-button" data-image-id="<?php echo esc_attr($image_id); ?>" <?php disabled(!$can_process); ?>>
												<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
												<?php esc_html_e('Generate', 'ai-image-seo-optimizer'); ?>
											</button>
											<button type="button" class="button button-primary update-meta-button" data-image-id="<?php echo esc_attr($image_id); ?>" disabled>
												<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
												<?php esc_html_e('Update', 'ai-image-seo-optimizer'); ?>
											</button>
											<span class="spinner"></span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
							<tfoot>
								<tr>
									<th scope="col"><?php esc_html_e('Preview', 'ai-image-seo-optimizer'); ?></th>
									<th scope="col"><?php esc_html_e('Image Info', 'ai-image-seo-optimizer'); ?></th>
									<th scope="col"><?php esc_html_e('Current Meta', 'ai-image-seo-optimizer'); ?></th>
									<th scope="col"><?php esc_html_e('Keywords (Optional)', 'ai-image-seo-optimizer'); ?></th>
									<th scope="col"><?php esc_html_e('AI Generated', 'ai-image-seo-optimizer'); ?></th>
									<th scope="col"><?php esc_html_e('Actions', 'ai-image-seo-optimizer'); ?></th>
								</tr>
							</tfoot>
						</table>
					</div>
				</form>
			<?php elseif ($selected_page_id > 0 && isset($post_to_analyze) && empty($library_images_data)) : ?>
				<div class="notice notice-info inline">
					<p><?php printf(
						esc_html__('No Media Library images were found in the content of the page "%s". External images (if any) were ignored.', 'ai-image-seo-optimizer'),
						esc_html(get_the_title($selected_page_id))
					); ?></p>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render the settings page.
 */
function aiso_render_settings_page() {
	$api_test_result = null;
	if (isset($_POST['test_api_key']) && check_admin_referer('aiso_test_api')) {
		$api_test_result = aiso_test_api_key();
	}

	$rest_test_result = null;
	if (isset($_POST['test_rest_api']) && check_admin_referer('aiso_test_rest')) {
		$rest_test_result = aiso_test_rest_availability();
	}
	?>
	<div class="wrap aiso-settings-wrap">
		<h1><?php echo esc_html__('AI Image SEO Optimizer Settings', 'ai-image-seo-optimizer'); ?></h1>

		<?php settings_errors(); ?>

		<?php if ($api_test_result): ?>
			<div id="setting-error-api_test" class="notice notice-<?php echo $api_test_result['success'] ? 'success' : 'error'; ?> settings-error is-dismissible">
				<p><strong><?php esc_html_e('API Key Test:', 'ai-image-seo-optimizer'); ?></strong> <?php echo esc_html($api_test_result['message']); ?></p>
			</div>
		<?php endif; ?>

		<?php if ($rest_test_result): ?>
			<div id="setting-error-rest_test" class="notice notice-<?php echo $rest_test_result['success'] ? 'success' : 'error'; ?> settings-error is-dismissible">
				<p><strong><?php esc_html_e('REST API Test:', 'ai-image-seo-optimizer'); ?></strong> <?php echo esc_html($rest_test_result['message']); ?></p>
			</div>
		<?php endif; ?>

		<div class="aiso-settings-forms">
			<form method="post" action="options.php">
				<?php
				settings_fields('aiso_settings_group');
				do_settings_sections('aiso-settings');
				submit_button(__('Save Settings', 'ai-image-seo-optimizer'));
				?>
			</form>

			<div class="aiso-tests-section">
				<h2><?php esc_html_e('Test Connections', 'ai-image-seo-optimizer'); ?></h2>
				<p><?php esc_html_e('Use these buttons to verify your setup after saving settings.', 'ai-image-seo-optimizer'); ?></p>
				<form method="post" style="margin-bottom: 15px;">
					<?php wp_nonce_field('aiso_test_api'); ?>
					<input type="submit" name="test_api_key" class="button button-secondary" value="<?php esc_attr_e('Test Gemini API Connection', 'ai-image-seo-optimizer'); ?>">
				</form>
				<form method="post">
					<?php wp_nonce_field('aiso_test_rest'); ?>
					<input type="submit" name="test_rest_api" class="button button-secondary" value="<?php esc_attr_e('Test WordPress REST API', 'ai-image-seo-optimizer'); ?>">
				</form>
			</div>
		</div>

		<div class="aiso-model-info notice notice-info inline">
			<h2><?php esc_html_e('About Gemini Models', 'ai-image-seo-optimizer'); ?></h2>
			<?php // Removed 2.5 description ?>
			<p><strong><?php esc_html_e('Gemini 1.5 Flash:', 'ai-image-seo-optimizer'); ?></strong> <?php esc_html_e('Stable, cost-effective, and good for tasks like summarization, chat, and captioning.', 'ai-image-seo-optimizer'); ?></p>
			<p><strong><?php esc_html_e('Gemini 1.5 Pro:', 'ai-image-seo-optimizer'); ?></strong> <?php esc_html_e('Stable, more advanced model, better for complex reasoning, coding, and creative generation tasks.', 'ai-image-seo-optimizer'); ?></p>
			<p><?php echo wp_kses_post(sprintf(
				__('Refer to the <a href="%s" target="_blank" rel="noopener">official Google AI documentation</a> for details on models and pricing.', 'ai-image-seo-optimizer'),
				'https://ai.google.dev/models/gemini'
			)); ?></p>
		</div>
	</div>
	<?php
}

/**
 * Test REST API availability.
 * @return array Test result status and message.
 */
function aiso_test_rest_availability() {
	// Test core REST API
	$core_rest_url = rest_url('wp/v2/types/post');
	$core_response = wp_remote_get($core_rest_url, ['timeout' => 15]);

	if (is_wp_error($core_response)) {
		return [
			'success' => false,
			'message' => sprintf(__('Error reaching core REST API: %s', 'ai-image-seo-optimizer'), $core_response->get_error_message())
		];
	}
	$core_response_code = wp_remote_retrieve_response_code($core_response);
	if ($core_response_code >= 400) {
		$possible_issue = '';
		if ($core_response_code === 401 || $core_response_code === 403) {
			$possible_issue = __('This might be caused by authentication issues or a security plugin blocking REST API access.', 'ai-image-seo-optimizer');
		}
		return [
			'success' => false,
			'message' => sprintf(
				__('Core REST API returned status code %d. %s There might be a problem with your server configuration or security settings.', 'ai-image-seo-optimizer'),
				$core_response_code,
				$possible_issue
			)
		];
	}

	// Test plugin REST API base
	$plugin_rest_url = rest_url('aiso/v1/');
	$plugin_response = wp_remote_get($plugin_rest_url, ['timeout' => 15]);

	if (is_wp_error($plugin_response)) {
		return [
			'success' => false,
			'message' => sprintf(__('Core REST API works, but error reaching plugin REST API: %s', 'ai-image-seo-optimizer'), $plugin_response->get_error_message())
		];
	}
	$plugin_response_code = wp_remote_retrieve_response_code($plugin_response);
	// 404 is expected for the base route if no routes are defined yet or if it needs authentication not provided here
	if ($plugin_response_code >= 500) {
		return [
			'success' => false,
			'message' => sprintf(__('Plugin REST API base returned server error code %d. Check server error logs.', 'ai-image-seo-optimizer'), $plugin_response_code)
		];
	}

	// If core worked and plugin didn't give a 5xx error, assume it's okay
	return [
		'success' => true,
		'message' => __('WordPress REST API seems to be accessible. Core responded and Plugin base route did not return a server error.', 'ai-image-seo-optimizer') // Updated message
	];
}

/**
 * Test Gemini API Key validity and model support.
 * @return array Test result status and message.
 */
function aiso_test_api_key() {
	$api_key = aiso_get_api_key();
	$model   = aiso_get_gemini_model();

	if (empty($api_key)) {
		return ['success' => false, 'message' => __('API Key is missing.', 'ai-image-seo-optimizer')];
	}

	// Use the models list endpoint for testing key validity and model availability
	$test_url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . $api_key;
	$response = wp_remote_get($test_url, [
		'timeout' => 30,
		'headers' => ['Content-Type' => 'application/json'],
		'sslverify' => true // Should generally be true
	]);

	if (is_wp_error($response)) {
		$error_message = $response->get_error_message();
		// Add context if possible (e.g., cURL errors)
		return ['success' => false, 'message' => sprintf(__('Connection Error: %s', 'ai-image-seo-optimizer'), $error_message)];
	}

	$code = wp_remote_retrieve_response_code($response);
	$body = wp_remote_retrieve_body($response);
	$data = json_decode($body, true);

	if ($code >= 200 && $code < 300) {
		// API key is valid, now check if the selected model exists and supports generation
		$model_found     = false;
		$model_supported = false;
		$target_model_name = 'models/' . $model; // Format: models/gemini-1.5-flash

		if (isset($data['models']) && is_array($data['models'])) {
			foreach ($data['models'] as $model_info) {
				if (isset($model_info['name']) && $model_info['name'] === $target_model_name) {
					$model_found = true;
					// Check if the model supports the 'generateContent' method (needed for text/vision)
					if (isset($model_info['supportedGenerationMethods']) && in_array('generateContent', $model_info['supportedGenerationMethods'], true)) {
						$model_supported = true;
					}
					break; // Found the model, no need to loop further
				}
			}
		}

		if ($model_found && $model_supported) {
			return ['success' => true, 'message' => sprintf(__('API Key is valid and the selected model "%s" supports content generation!', 'ai-image-seo-optimizer'), $model)];
		} elseif ($model_found) {
			// Model exists but doesn't explicitly list 'generateContent'
			return ['success' => false, 'message' => sprintf(__('API Key is valid, but the selected model "%s" was found but does not list support for "generateContent". Check Google AI documentation or model capabilities.', 'ai-image-seo-optimizer'), $model)];
		} else {
			// Model was not found in the list for this API key
			return ['success' => false, 'message' => sprintf(__('API Key is valid, but the selected model "%s" was NOT found in the list of available models for your key. Check model name or API key permissions.', 'ai-image-seo-optimizer'), $model)];
		}

	} else {
		// API returned an error code (e.g., 400 Bad Request, 403 Forbidden)
		$error_message = __('Unknown API error.', 'ai-image-seo-optimizer');
		if (isset($data['error']['message'])) {
			$error_message = $data['error']['message'];
		}
		// Provide more specific feedback for common errors
		if ($code === 400 && strpos(strtolower($error_message), 'api key not valid') !== false) {
			$error_message = __('Invalid API Key format or key not recognized.', 'ai-image-seo-optimizer');
		} elseif ($code === 403) {
			$error_message = __('API Key is likely valid but forbidden. Check Google Project/API permissions (ensure Generative Language API is enabled).', 'ai-image-seo-optimizer');
		}
		return ['success' => false, 'message' => sprintf(__('API Error (%d): %s', 'ai-image-seo-optimizer'), $code, $error_message)];
	}
}

// =========================================================================
// Enqueue Scripts and Styles
// =========================================================================

add_action('admin_enqueue_scripts', 'aiso_enqueue_admin_assets');

/**
 * Enqueue admin scripts and styles.
 * @param string $hook_suffix The current admin page hook.
 */
function aiso_enqueue_admin_assets($hook_suffix) {
	$main_page_hook     = 'toplevel_page_ai-image-seo-optimizer';
	$analyzer_page_hook = 'ai-image-seo_page_aiso-page-content-analyzer';
	$settings_page_hook = 'ai-image-seo_page_aiso-settings';
	$plugin_pages = [$main_page_hook, $analyzer_page_hook, $settings_page_hook];

	// Enqueue common styles on all plugin pages
	if (in_array($hook_suffix, $plugin_pages, true)) {
		wp_enqueue_style('aiso-admin-style', AISO_PLUGIN_URL . 'assets/css/admin-style.css', [], AISO_VERSION);
	}

	// Enqueue scripts only on pages that need AJAX interaction
	if ($hook_suffix === $main_page_hook || $hook_suffix === $analyzer_page_hook) {
		wp_enqueue_script('aiso-admin-script', AISO_PLUGIN_URL . 'assets/js/admin-script.js', ['jquery', 'wp-i18n'], AISO_VERSION, true);

		// Localize script with necessary data
		$localize_data = [
			'rest_url' => rest_url('aiso/v1/'),
			'nonce'    => wp_create_nonce('wp_rest'),
			'model'    => aiso_get_gemini_model(),
			'has_api_key' => !empty(aiso_get_api_key()),
			'debug_mode'  => defined('WP_DEBUG') && WP_DEBUG,
			// Translations for JS
			'text_processing' => __('Processing...', 'ai-image-seo-optimizer'),
			'text_generate'   => __('Generate', 'ai-image-seo-optimizer'),
			'text_update'     => __('Update', 'ai-image-seo-optimizer'),
			'text_updated'    => __('Updated', 'ai-image-seo-optimizer'),
			'text_error'      => __('Error', 'ai-image-seo-optimizer'),
			'text_success'    => __('Success', 'ai-image-seo-optimizer'),
			'text_error_occurred' => __('An error occurred. Check console or error log.', 'ai-image-seo-optimizer'),
			'text_error_update' => __('An error occurred during update.', 'ai-image-seo-optimizer'),
			'text_no_ai_data' => __('No valid AI data generated yet.', 'ai-image-seo-optimizer'),
			'text_api_key_missing' => __('API Key is missing. Please configure it in Settings.', 'ai-image-seo-optimizer'),
			'text_update_success' => __('Image metadata updated successfully.', 'ai-image-seo-optimizer'),
			'text_dismiss' => __('Dismiss', 'ai-image-seo-optimizer'),
			'text_generate_all' => __('Generate All', 'ai-image-seo-optimizer'),
			'text_update_all' => __('Update All Generated', 'ai-image-seo-optimizer'),
			'text_generating_progress' => __('Generating %d / %d...', 'ai-image-seo-optimizer'),
			'text_updating_progress' => __('Updating %d / %d...', 'ai-image-seo-optimizer'),
			'text_bulk_complete' => __('Bulk processing complete. %d succeeded, %d failed.', 'ai-image-seo-optimizer'),
			'text_bulk_generate_confirm' => __('Are you sure you want to generate meta for all %d images? This may take time and consume API credits.', 'ai-image-seo-optimizer'),
			'text_bulk_update_confirm' => __('Are you sure you want to update meta for all %d images with generated data? This action cannot be undone easily.', 'ai-image-seo-optimizer'),
			'current_page' => ($hook_suffix === $analyzer_page_hook) ? 'analyzer' : 'all_images' // Identify current page context for JS
		];
		wp_localize_script('aiso-admin-script', 'aiso_ajax_object', $localize_data);

		// Enable script translations
		wp_set_script_translations('aiso-admin-script', 'ai-image-seo-optimizer', AISO_PLUGIN_DIR . 'languages');
	}
}

// =========================================================================
// REST API Setup
// =========================================================================

add_action('rest_api_init', 'aiso_register_rest_routes');

/**
 * Register REST API routes.
 */
function aiso_register_rest_routes() {
	// Route for generating AI meta
	register_rest_route('aiso/v1', '/generate', [
		'methods'             => WP_REST_Server::CREATABLE, // POST
		'callback'            => 'aiso_generate_ai_meta_rest',
		'permission_callback' => 'aiso_rest_permission_check',
		'args'                => [
			'image_id' => [
				'required'          => true,
				'validate_callback' => function ($param) {
					return is_numeric($param) && intval($param) > 0;
				},
				'sanitize_callback' => 'absint',
				'description'       => __('The ID of the image attachment.', 'ai-image-seo-optimizer'),
			],
			'focus_keyword' => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __('Optional primary keyword for SEO focus hint.', 'ai-image-seo-optimizer'),
				'default'           => '', // Provide default value
			],
			'secondary_keyword' => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __('Optional secondary keyword hint.', 'ai-image-seo-optimizer'),
				'default'           => '', // Provide default value
			],
			// page_h1 argument removed
		],
	]);

	// Route for updating image meta
	register_rest_route('aiso/v1', '/update', [
		'methods'             => WP_REST_Server::EDITABLE, // POST, PUT, PATCH
		'callback'            => 'aiso_update_image_meta_rest',
		'permission_callback' => 'aiso_rest_permission_check',
		'args'                => [
			'image_id' => [
				'required'          => true,
				'validate_callback' => function ($param) {
					return is_numeric($param) && intval($param) > 0;
				},
				'sanitize_callback' => 'absint',
			],
			'new_title' => [
				'required'          => false,
				'validate_callback' => function ($param) {
					// Allow empty string but check type if not null
					return is_null($param) || is_string($param);
				},
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => null,
			],
			'new_alt' => [
				'required'          => false,
				'validate_callback' => function ($param) {
					return is_null($param) || is_string($param);
				},
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => null,
			],
		],
	]);
}

/**
 * Permission check callback for REST routes.
 * Allows users who can edit posts or upload files.
 *
 * @param WP_REST_Request $request The request object.
 * @return bool|WP_Error True if allowed, WP_Error otherwise.
 */
function aiso_rest_permission_check(WP_REST_Request $request) {
	// Check capabilities more specific to media library modification
	if (current_user_can('edit_posts') || current_user_can('upload_files')) {
		return true;
	}
	// Check if user can edit the specific attachment post type
	// Note: 'edit_post' capability check for the image_id would be better inside the callback
	//       after validating the image_id exists. Here, we check general capability.
	if (current_user_can(get_post_type_object('attachment')->cap->edit_posts)) {
		 return true;
	}

	return new WP_Error(
		'rest_forbidden',
		esc_html__('You do not have permission to perform this action.', 'ai-image-seo-optimizer'),
		['status' => rest_authorization_required_code()] // Typically 401 or 403
	);
}

// =========================================================================
// REST API Callbacks
// =========================================================================

/**
 * REST API callback to generate AI metadata for an image.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error Response object on success, WP_Error on failure.
 */
function aiso_generate_ai_meta_rest(WP_REST_Request $request) {
	// --- Initial Checks & Setup ---
	$image_id = $request['image_id']; // Already sanitized by args definition

	// Double-check image validity (although validate_callback should catch non-numeric)
	if (!wp_attachment_is_image($image_id)) {
		return new WP_Error('aiso_invalid_image', __('Invalid or non-existent image ID.', 'ai-image-seo-optimizer'), ['status' => 404]);
	}

	// Check if the current user can edit this specific attachment
	if (!current_user_can('edit_post', $image_id)) {
		return new WP_Error('aiso_permission_denied_on_item', __('You do not have permission to edit this specific image.', 'ai-image-seo-optimizer'), ['status' => 403]);
	}

	$focus_keyword = $request['focus_keyword']; // Already sanitized
	$secondary_keyword = $request['secondary_keyword']; // Already sanitized

	$api_key = aiso_get_api_key();
	if (empty($api_key)) {
		return new WP_Error('aiso_api_key_missing', __('Gemini API Key is missing. Please configure it in Settings.', 'ai-image-seo-optimizer'), ['status' => 400]);
	}
	$model = aiso_get_gemini_model();

	// Get image file path and MIME type safely
	$attached_file = get_attached_file($image_id);
	if (!$attached_file || !file_exists($attached_file) || !is_readable($attached_file)) {
		 $error_msg = 'Could not access image file path on server.';
		 if (!$attached_file) $error_msg = 'Attached file path not found.';
		 elseif (!file_exists($attached_file)) $error_msg = 'Image file does not exist at path: ' . esc_html($attached_file);
		 elseif (!is_readable($attached_file)) $error_msg = 'Image file is not readable at path: ' . esc_html($attached_file);
		 error_log("AISO Error: {$error_msg} for image ID {$image_id}.");
		 return new WP_Error('aiso_file_path_error', __($error_msg, 'ai-image-seo-optimizer'), ['status' => 500]);
	}
	$image_mime_type = get_post_mime_type($image_id);
	if (!$image_mime_type) {
		// Attempt to get MIME type from file if WP function fails
		$image_mime_type = mime_content_type($attached_file);
		if (!$image_mime_type || strpos($image_mime_type, 'image/') !== 0) {
			error_log("AISO Error: Could not reliably determine image MIME type for image ID {$image_id}. Path: " . esc_html($attached_file));
			$image_mime_type = 'image/jpeg'; // Last resort fallback
		}
	}

   // احصل على الـ prompt الحالي (المخصص أو الافتراضي) من الإعدادات
    $prompt_template_to_use = aiso_get_custom_prompt();

	$primary_value = !empty($focus_keyword) ? $focus_keyword : 'Not provided';
	$secondary_value = !empty($secondary_keyword) ? $secondary_keyword : 'Not provided';

	$prompt = str_replace(
		['{primary}', '{secondary}'],
		[$primary_value, $secondary_value],
		$prompt_template_to_use // <-- استخدم المتغير الجديد هنا
	);

	// --- Prepare API Request Body ---
	$image_data_base64 = '';
	$file_content = file_get_contents($attached_file);
	if ($file_content === false) {
		error_log("AISO Error: Failed to read file content for image ID {$image_id} from path: {$attached_file}");
		return new WP_Error('aiso_file_read_error', __('Could not read image file content from server.', 'ai-image-seo-optimizer'), ['status' => 500]);
	}
	$image_data_base64 = base64_encode($file_content);
	unset($file_content); // Free memory

	// Check base64 encoding result
	if (empty($image_data_base64)) {
		error_log("AISO Error: Base64 encoding of image file failed or resulted in empty string for image ID {$image_id}.");
		return new WP_Error('aiso_base64_error', __('Failed to prepare image data for AI.', 'ai-image-seo-optimizer'), ['status' => 500]);
	}

	$request_body_data = [
		'contents' => [
			[
				'parts' => [
					['text' => $prompt], // Part 1: Prompt Text
					[                 // Part 2: Image Data
						'inline_data' => [
							'mime_type' => $image_mime_type,
							'data'      => $image_data_base64
						]
					]
				]
			]
		],
		'generationConfig' => [
			'temperature'     => 0.5,
			'topP'            => 0.95,
			'topK'            => 40,
			'candidateCount'  => 1,
			'maxOutputTokens' => 250,
			'stopSequences'   => [],
		],
		'safetySettings' => [
			['category' => 'HARM_CATEGORY_HARASSMENT',       'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
			['category' => 'HARM_CATEGORY_HATE_SPEECH',      'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
			['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT','threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
			['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT','threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
		]
	];

	$request_body = wp_json_encode($request_body_data); // Use wp_json_encode for consistency
	unset($image_data_base64); // Free memory
	unset($request_body_data); // Free memory

	if ($request_body === false || json_last_error() !== JSON_ERROR_NONE) {
		 error_log("AISO Error: Failed to encode request body to JSON for Image ID {$image_id}. Error: " . json_last_error_msg());
		 return new WP_Error('aiso_json_encode_error', __('Failed to prepare request data.', 'ai-image-seo-optimizer'), ['status' => 500]);
	}

	// --- Log Request (optional) ---
	if (defined('WP_DEBUG') && WP_DEBUG) {
		error_log("AISO Debug: Sending prompt to Gemini for Image ID {$image_id}:\n" . $prompt);
		error_log("AISO Debug: Keywords - Primary: {$primary_value}, Secondary: {$secondary_value}");
		// Log request body without base64 data for brevity
		$debug_body_for_log = json_decode($request_body, true);
		if (isset($debug_body_for_log['contents'][0]['parts'][1]['inline_data']['data'])) {
			 $debug_body_for_log['contents'][0]['parts'][1]['inline_data']['data'] = '...base64_data_omitted (' . strlen($request_body_data['contents'][0]['parts'][1]['inline_data']['data'] ?? '') . ' bytes)...';
		}
		error_log("AISO Debug: Request Body Sent: " . wp_json_encode($debug_body_for_log)); // Use wp_json_encode
		unset($debug_body_for_log);
	}

	// --- Execute API Call ---
	$api_endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;
	$response = wp_remote_post($api_endpoint, [
		'method'    => 'POST',
		'headers'   => ['Content-Type' => 'application/json'],
		'body'      => $request_body,
		'timeout'   => 90, // Increased timeout
		'sslverify' => true,
	]);
	unset($request_body); // Free memory

	// --- Process Response ---
	if (is_wp_error($response)) {
		error_log("AISO Gemini API Connection Error for Image ID {$image_id}: " . $response->get_error_message());
		// Provide specific user feedback if possible (e.g., timeout)
		$user_message = __('Failed to connect to Gemini API: ', 'ai-image-seo-optimizer') . $response->get_error_message();
		if (strpos($response->get_error_message(), 'timed out') !== false) {
			$user_message = __('Connection to Gemini API timed out. Please try again later or increase timeout setting if possible.', 'ai-image-seo-optimizer');
		}
		return new WP_Error('aiso_api_connection_error', $user_message, ['status' => 503]); // 503 Service Unavailable
	}

	$response_code = wp_remote_retrieve_response_code($response);
	$response_body = wp_remote_retrieve_body($response);
	$result_data   = json_decode($response_body, true);
	$json_error    = json_last_error();

	// Log basic response info
	error_log("AISO Trace: Received response from Gemini for Image ID {$image_id}. Code: {$response_code}. Body Length: " . strlen($response_body) . ". JSON Error Code: " . $json_error);
	// Log raw body only if debugging and reasonably small
	if (defined('WP_DEBUG') && WP_DEBUG && strlen($response_body) < 5000) {
		error_log("AISO Trace: Raw Response Body:\n" . $response_body);
	}

	// Handle HTTP Errors (4xx, 5xx)
	if ($response_code >= 400) {
		$error_message = __('Unknown API error.', 'ai-image-seo-optimizer');
		if ($json_error === JSON_ERROR_NONE && isset($result_data['error']['message'])) {
			$error_message = $result_data['error']['message'];
		} elseif (!empty($response_body)) {
			$error_message = substr(strip_tags($response_body), 0, 150) . '...'; // Fallback to raw body snippet
		}

		error_log("AISO Gemini API Error ({$response_code}) for Image ID {$image_id}: " . ($json_error === JSON_ERROR_NONE && isset($result_data['error']['message']) ? $result_data['error']['message'] : $response_body));

		// Provide user-friendly messages
		$user_message = sprintf(__('Gemini API Error (%s)', 'ai-image-seo-optimizer'), $response_code);
		if ($response_code === 400 && strpos(strtolower($error_message), 'api key not valid') !== false) { $user_message = __('Invalid API Key. Check Settings.', 'ai-image-seo-optimizer'); }
		elseif ($response_code === 403) { $user_message = __('API Key Forbidden. Check Google Project/API permissions.', 'ai-image-seo-optimizer'); }
		elseif ($response_code === 429) { $user_message = __('API Quota Exceeded or Rate Limit hit. Please wait and try again.', 'ai-image-seo-optimizer'); }
		elseif ($response_code === 400 && strpos(strtolower($error_message), 'user location is not supported') !== false) { $user_message = __('API access denied based on user location.', 'ai-image-seo-optimizer'); }
		elseif (strpos(strtolower($error_message), 'model') !== false && strpos(strtolower($error_message), 'not found') !== false) { $user_message = sprintf(__('Model "%s" not found or inaccessible with your API key.', 'ai-image-seo-optimizer'), $model); }
		elseif ($json_error === JSON_ERROR_NONE && isset($result_data['error']['details'])) { $user_message .= ': ' . esc_html($result_data['error']['message']) . ' Details: ' . wp_json_encode($result_data['error']['details']); }
		else { $user_message .= ': ' . esc_html($error_message); }

		return new WP_Error('aiso_gemini_api_error', $user_message, ['status' => $response_code]);
	}

	// Handle non-JSON success response (unexpected)
	if ($json_error !== JSON_ERROR_NONE) {
		error_log("AISO Error: Successful HTTP response (Code {$response_code}) but invalid JSON received for Image ID {$image_id}. JSON Parse Error: " . json_last_error_msg() . ". Raw Body: " . $response_body);
		return new WP_Error('aiso_invalid_success_json', __('Received an unexpected non-JSON response from the AI service despite a success code.', 'ai-image-seo-optimizer'), ['status' => 500]);
	}

	// Validate basic structure of successful response
	if (!is_array($result_data)) {
		error_log("AISO Error: Successful HTTP response but result data is not an array for Image ID {$image_id}. Raw Body: " . $response_body);
		return new WP_Error('aiso_invalid_data_type', __('Received unexpected data type from the AI service.', 'ai-image-seo-optimizer'), ['status' => 500]);
	}

	// Check for blocked prompt
	if (isset($result_data['promptFeedback']['blockReason'])) {
		$block_reason = $result_data['promptFeedback']['blockReason'];
		$safety_ratings = isset($result_data['promptFeedback']['safetyRatings']) ? wp_json_encode($result_data['promptFeedback']['safetyRatings']) : 'N/A';
		error_log("AISO Gemini: Prompt Blocked for Image ID {$image_id}. Reason: {$block_reason}. Ratings: {$safety_ratings}. Raw Response: " . $response_body);
		return new WP_Error('aiso_gemini_blocked', sprintf(__('AI generation blocked due to safety settings (%s).', 'ai-image-seo-optimizer'), $block_reason), ['status' => 400, 'block_reason' => $block_reason]);
	}

	// Check for candidates array
	if (!isset($result_data['candidates']) || !is_array($result_data['candidates'])) {
		 error_log("AISO Error: Missing or invalid 'candidates' array in successful response for Image ID {$image_id}. Response: " . $response_body);
		 $finish_reason = 'UNKNOWN (Missing candidates array)';
		 $user_message = __('AI response structure is missing expected data (candidates).', 'ai-image-seo-optimizer');
		 return new WP_Error('aiso_missing_candidates', $user_message, ['status' => 500, 'finish_reason' => $finish_reason]);
	}

	 // Check if candidates array is empty (often indicates finish reason other than STOP)
	 if (empty($result_data['candidates'])) {
		// Try to get finishReason from candidate if available (even if empty, might be present in structure)
		$finish_reason = $result_data['candidates'][0]['finishReason'] ?? $result_data['promptFeedback']['candidates'][0]['finishReason'] ?? 'UNKNOWN (Empty candidates array)';
		error_log("AISO Gemini: Empty candidates array for Image ID {$image_id}. Finish Reason: {$finish_reason}. Raw Response: " . $response_body);
		$user_message = sprintf(__('AI returned no content. Finish Reason: %s.', 'ai-image-seo-optimizer'), $finish_reason);

		if ($finish_reason === 'MAX_TOKENS') { $user_message = __('AI generation stopped because the maximum output length was reached. Try a shorter prompt or check model limits.', 'ai-image-seo-optimizer'); }
		elseif ($finish_reason === 'SAFETY') { $user_message = sprintf(__('AI generation blocked due to safety settings (%s). Check safety ratings in logs if debug enabled.', 'ai-image-seo-optimizer'), $finish_reason); }
		elseif ($finish_reason === 'RECITATION') { $user_message = sprintf(__('AI generation stopped due to potential recitation issues (%s).', 'ai-image-seo-optimizer'), $finish_reason); }
		elseif ($finish_reason !== 'STOP' && $finish_reason !== 'REASON_UNSPECIFIED' && strpos($finish_reason, 'UNKNOWN') === false) { $user_message = sprintf(__('AI generation finished unexpectedly (%s).', 'ai-image-seo-optimizer'), $finish_reason); }

		return new WP_Error('aiso_gemini_empty_candidates', $user_message, ['status' => 500, 'finish_reason' => $finish_reason]);
	 }

	// --- Extract and Parse AI Response Text ---
	$generated_text = '';
	if (isset($result_data['candidates'][0]['content']['parts'][0]['text'])) {
		$generated_text = trim($result_data['candidates'][0]['content']['parts'][0]['text']);
	} else {
		// Should not happen if candidates[0] exists, but check anyway
		error_log("AISO Error: Could not extract text part from candidate[0] for Image ID {$image_id}. Response: " . $response_body);
		return new WP_Error('aiso_missing_text_part', __('Could not extract text part from AI response.', 'ai-image-seo-optimizer'), ['status' => 500]);
	}

	// Clean potential markdown and extract JSON object robustly
	$generated_text_cleaned = preg_replace('/^```json\s*/i', '', $generated_text);
	$generated_text_cleaned = preg_replace('/\s*```$/', '', $generated_text_cleaned);
	$generated_text_cleaned = trim($generated_text_cleaned);

	$first_brace = strpos($generated_text_cleaned, '{');
	$last_brace = strrpos($generated_text_cleaned, '}');
	$potential_json = $generated_text_cleaned; // Default to cleaned text

	if ($first_brace !== false && $last_brace !== false && $last_brace > $first_brace) {
		$potential_json = substr($generated_text_cleaned, $first_brace, $last_brace - $first_brace + 1);
	} else {
		 error_log("AISO Warning: Could not find JSON structure '({...})' in AI response for Image ID {$image_id}. Trying to decode cleaned text directly. Raw text: " . esc_html($generated_text));
	}

	$ai_output = json_decode($potential_json, true);
	$json_parse_error = json_last_error();

	// --- Final Validation and Return Success ---
	if ($json_parse_error !== JSON_ERROR_NONE || !is_array($ai_output) || !isset($ai_output['title']) || !isset($ai_output['alt']) || !is_string($ai_output['title']) || !is_string($ai_output['alt'])) {
		$json_error_msg = json_last_error_msg();
		error_log("AISO Error: Final JSON parsing failed ({$json_error_msg}) or invalid structure for Image ID {$image_id}. Original Raw text: " . esc_html($generated_text) . " | Attempted JSON part: " . esc_html($potential_json));
		$debug_info = ' Raw AI text: "' . esc_html(mb_substr($generated_text, 0, 200)) . '..."';
		return new WP_Error(
			'aiso_invalid_json_format',
			__('AI response was not in the expected JSON format {\'title\': \'\', \'alt\': \'\'}. ', 'ai-image-seo-optimizer') . " (Error: {$json_error_msg})." . $debug_info,
			[
				'status' => 500,
				'raw_text_snippet' => mb_substr($generated_text, 0, 200),
				'json_error' => $json_error_msg
			]
		);
	}

	// Sanitize the final output from AI
	$final_title = sanitize_text_field($ai_output['title']);
	$final_alt = sanitize_text_field($ai_output['alt']);

	// Check if AI returned literally empty strings after potential parsing/sanitizing
	if (empty($final_title) && empty($final_alt)) {
		error_log("AISO Warning: AI returned empty title and alt for Image ID {$image_id} after processing. Raw text: " . esc_html($generated_text));
		// Return success but indicate empty result to JS via a specific code or message?
		// For now, return an error that JS can interpret as non-blocking.
		return new WP_Error(
			'aiso_ai_returned_empty',
			__('AI generated empty title and alt text.', 'ai-image-seo-optimizer'),
			['status' => 200] // Use 200 so JS retry doesn't trigger, but handle this code in JS
		);
	}

	// Success! Return the generated data
	$response_data = [
		'title' => $final_title,
		'alt'   => $final_alt
	];
	return new WP_REST_Response($response_data, 200);
}


/**
 * REST API callback to update image metadata.
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response|WP_Error Response object on success, WP_Error on failure.
 */
function aiso_update_image_meta_rest(WP_REST_Request $request) {
	$image_id  = $request['image_id']; // Sanitized by args
	$new_title = $request['new_title']; // Sanitized by args (allows null)
	$new_alt   = $request['new_alt'];   // Sanitized by args (allows null)

	// Validate image ID again just in case
	if (!wp_attachment_is_image($image_id)) {
		return new WP_Error('aiso_invalid_image', __('Invalid or non-existent image ID.', 'ai-image-seo-optimizer'), ['status' => 404]);
	}

	// Check permissions for this specific image
	if (!current_user_can('edit_post', $image_id)) {
		return new WP_Error('aiso_permission_denied_on_item', __('You do not have permission to edit this specific image.', 'ai-image-seo-optimizer'), ['status' => 403]);
	}

	// Check if there's anything to update
	if (is_null($new_title) && is_null($new_alt)) {
		return new WP_Error('aiso_nothing_to_update', __('No new title or alt text provided for update.', 'ai-image-seo-optimizer'), ['status' => 400]);
	}

	$update_errors  = [];
	$updated_fields = [];

	// Update Title if provided
	if (!is_null($new_title)) {
		$post_data = [
			'ID'         => $image_id,
			'post_title' => $new_title // Already sanitized
		];
		$post_updated = wp_update_post($post_data, true); // Pass true for WP_Error return

		if (is_wp_error($post_updated)) {
			$update_errors['title'] = __('Failed to update title: ', 'ai-image-seo-optimizer') . $post_updated->get_error_message();
			error_log('AISO Error updating title for image ID ' . $image_id . ': ' . $post_updated->get_error_message());
		} else {
			$updated_fields['title'] = $new_title;
		}
	}

	// Update Alt Text if provided
	if (!is_null($new_alt)) {
		$old_alt = get_post_meta($image_id, '_wp_attachment_image_alt', true);
		// Only update if the value is actually different
		if ($old_alt !== $new_alt) {
			$alt_updated = update_post_meta($image_id, '_wp_attachment_image_alt', $new_alt); // $new_alt already sanitized

			if ($alt_updated === false) {
				// update_post_meta returning false usually indicates failure (though sometimes means value was the same)
				$update_errors['alt'] = __('Failed to update alt text (update_post_meta failed).', 'ai-image-seo-optimizer');
				error_log('AISO Error updating alt text for image ID: ' . $image_id . ' - update_post_meta returned false.');
			} elseif ($alt_updated === true) {
				// Value was updated
				$updated_fields['alt'] = $new_alt;
			} else {
				// Value was likely the same as existing, treat as success for UI
				$updated_fields['alt'] = $new_alt;
			}
		} else {
			// Value was already the same, no update needed but report success
			$updated_fields['alt'] = $new_alt;
		}
	}

	// Check for errors and return response
	if (empty($update_errors)) {
		// Get the potentially updated values to return them
		$final_title = $updated_fields['title'] ?? get_the_title($image_id);
		$final_alt = $updated_fields['alt'] ?? get_post_meta($image_id, '_wp_attachment_image_alt', true);

		$response_data = [
			'message'       => __('Image metadata updated successfully.', 'ai-image-seo-optimizer'),
			'updated_title' => $final_title,
			'updated_alt'   => $final_alt,
		];
		return new WP_REST_Response($response_data, 200);
	} else {
		// Return error response
		$error_data = [
			'status' => 500, // Internal Server Error (or maybe 400 Bad Request?)
			'errors' => $update_errors
		];
		return new WP_Error(
			'aiso_update_failed',
			__('One or more fields failed to update.', 'ai-image-seo-optimizer'),
			$error_data
		);
	}
}


// =========================================================================
// Plugin Action Links & Activation/Deactivation
// =========================================================================

$plugin_basename = plugin_basename(__FILE__);
add_filter("plugin_action_links_{$plugin_basename}", 'aiso_settings_link');

/**
 * Add Settings link to plugin actions.
 * @param array $links Existing links.
 * @return array Modified links.
 */
function aiso_settings_link($links) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url(admin_url('admin.php?page=aiso-settings')),
		__('Settings', 'ai-image-seo-optimizer')
	);
	array_unshift($links, $settings_link); // Add to the beginning
	return $links;
}

/**
 * Activation hook (placeholder).
 */
// register_activation_hook(__FILE__, 'aiso_activate');
// function aiso_activate() {
	// Actions on activation (e.g., set default options)
// }

/**
 * Deactivation hook (placeholder).
 */
// register_deactivation_hook(__FILE__, 'aiso_deactivate');
// function aiso_deactivate() {
	// Actions on deactivation (e.g., cleanup options if needed)
// }

?>
