<?php
/**
 * تحديث الإضافة من GitHub
 * 
 * يسمح هذا الملف بتحديث الإضافة تلقائيًا من GitHub عند توفر إصدار جديد
 */

if (!defined('ABSPATH')) {
    exit;
}

class AISO_GitHub_Updater {
    private $slug;
    private $plugin_data;
    private $username;
    private $repo;
    private $plugin_file;
    private $github_api_result;
    private $access_token;

    /**
     * إنشاء الكائن
     *
     * @param string $plugin_file الملف الرئيسي للإضافة
     * @param string $github_username اسم المستخدم على GitHub
     * @param string $github_repo_name اسم المستودع على GitHub
     * @param string $access_token رمز الوصول (اختياري)
     */
    public function __construct($plugin_file, $github_username, $github_repo_name, $access_token = '') {
        $this->plugin_file = $plugin_file;
        $this->username = $github_username;
        $this->repo = $github_repo_name;
        $this->access_token = $access_token;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'set_transient'));
        add_filter('plugins_api', array($this, 'set_plugin_info'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'post_install'), 10, 3);
    }

    /**
     * الحصول على معلومات الإضافة من الملف الرئيسي
     */
    private function init_plugin_data() {
        $this->slug = plugin_basename($this->plugin_file);
        $this->plugin_data = get_plugin_data($this->plugin_file);
    }

    /**
     * الحصول على معلومات الإصدار من GitHub
     *
     * @return object|bool معلومات الإصدار أو false في حالة الفشل
     */
    private function get_repo_release_info() {
        if (!empty($this->github_api_result)) {
            return $this->github_api_result;
        }

        $url = "https://api.github.com/repos/{$this->username}/{$this->repo}/releases/latest";
        
        if (!empty($this->access_token)) {
            $url = add_query_arg(array('access_token' => $this->access_token), $url);
        }

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Accept' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $response_body = wp_remote_retrieve_body($response);
        $this->github_api_result = json_decode($response_body);

        return $this->github_api_result;
    }

    /**
     * تحديث معلومات التحديثات
     *
     * @param object $transient الكائن المؤقت الحالي
     * @return object الكائن المؤقت المعدل
     */
    public function set_transient($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $this->init_plugin_data();
        $release_info = $this->get_repo_release_info();
        
        if (false === $release_info || !isset($release_info->tag_name)) {
            return $transient;
        }

        // إزالة 'v' من بداية رقم الإصدار إذا كان موجودًا
        $release_version = preg_replace('/^v/', '', $release_info->tag_name);
        
        if (version_compare($release_version, $this->plugin_data['Version'], '>')) {
            $download_link = isset($release_info->zipball_url) ? $release_info->zipball_url : false;
            
            if ($download_link) {
                $transient->response[$this->slug] = (object) array(
                    'slug' => $this->slug,
                    'new_version' => $release_version,
                    'url' => $this->plugin_data['PluginURI'],
                    'package' => $download_link,
                );
            }
        }

        return $transient;
    }

    /**
     * تعيين معلومات الإضافة في نافذة تحديث ووردبريس
     *
     * @param false|object|array $result نتيجة API الحالية
     * @param string $action الإجراء المطلوب
     * @param object $args بيانات الطلب
     * @return object|false بيانات الإضافة المعدلة
     */
    public function set_plugin_info($result, $action, $args) {
        $this->init_plugin_data();
        
        if (empty($args->slug) || $args->slug != $this->slug) {
            return $result;
        }
        
        $release_info = $this->get_repo_release_info();
        
        if (!isset($release_info->tag_name)) {
            return $result;
        }
        
        $release_version = preg_replace('/^v/', '', $release_info->tag_name);
        
        $response = new stdClass();
        $response->last_updated = isset($release_info->published_at) ? $release_info->published_at : null;
        $response->slug = $this->slug;
        $response->name = $this->plugin_data['Name'];
        $response->plugin_name = $this->plugin_data['Name'];
        $response->version = $release_version;
        $response->author = $this->plugin_data['AuthorName'];
        $response->homepage = $this->plugin_data['PluginURI'];
        
        // الوصف وسجل التغييرات
        if (isset($release_info->body)) {
            $response->sections = array(
                'description' => $this->plugin_data['Description'],
                'changelog' => nl2br($release_info->body)
            );
        }
        
        // رابط التحميل
        if (isset($release_info->zipball_url)) {
            $response->download_link = $release_info->zipball_url;
        }
        
        return $response;
    }

    /**
     * إعادة تسمية مجلد الإضافة بعد التثبيت
     *
     * @param bool $true قيمة ترجع إلى المنادي
     * @param array $hook_extra معلومات إضافية عن الإضافة
     * @param array $result نتيجة التثبيت
     * @return array نتيجة التثبيت المعدلة
     */
    public function post_install($true, $hook_extra, $result) {
        $this->init_plugin_data();
        $was_activated = is_plugin_active($this->slug);
        
        global $wp_filesystem;
        
        $plugin_folder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname($this->slug);
        $wp_filesystem->move($result['destination'], $plugin_folder);
        $result['destination'] = $plugin_folder;
        
        if ($was_activated) {
            activate_plugin($this->slug);
        }
        
        return $result;
    }
}

/**
 * دالة بديلة للتحقق من وجود تحديثات من خلال ملف version.json
 * 
 * @param object $transient كائن التخزين المؤقت للتحديثات
 * @return object كائن التخزين المؤقت بعد التحديث
 */
function aiso_check_for_updates_via_json($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }

    // تحديد ملف الإضافة
    $plugin_slug = 'ai-image-seo-optimizer';
    $plugin_file = 'ai-image-seo-optimizer/ai-image-seo-optimizer.php';
    
    // البحث عن الملف الرئيسي للإضافة
    $plugin_files = array_keys($transient->checked);
    $found_plugin = preg_grep('/' . preg_quote(basename($plugin_file), '/') . '$/', $plugin_files);
    
    if (!empty($found_plugin)) {
        $plugin_file = reset($found_plugin);
    } else {
        error_log('AISO: Plugin file not found for update check');
        return $transient;
    }

    // النسخة الحالية المثبتة
    $current_version = $transient->checked[$plugin_file];
    error_log("AISO: Checking for updates. Current version: {$current_version}");

    // الحصول على معلومات الإصدار من ملف version.json
    $remote_version_url = 'https://raw.githubusercontent.com/hassanzn2023/ai-image-seo-optimizer/main/version.json';
    $response = wp_remote_get($remote_version_url, array('timeout' => 10));

    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        error_log('AISO: Failed to fetch version info: ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response)));
        return $transient;
    }

    $data = json_decode(wp_remote_retrieve_body($response));

    if (!isset($data->new_version) || !isset($data->download_url)) {
        error_log('AISO: Invalid version data received');
        return $transient;
    }

    error_log("AISO: Remote version: {$data->new_version}");

    // تنظيف صيغة الإصدار (إزالة 'v' في بداية الإصدار إن وجدت)
    $remote_version = preg_replace('/^v/', '', $data->new_version);
    
    // مقارنة الإصدارات
    if (version_compare($current_version, $remote_version, '<')) {
        error_log("AISO: Update available: {$current_version} -> {$remote_version}");
        
        $transient->response[$plugin_file] = (object) [
            'slug' => $plugin_slug,
            'new_version' => $remote_version,
            'url' => $data->url ?? "https://github.com/hassanzn2023/ai-image-seo-optimizer",
            'package' => $data->download_url,
            'plugin' => $plugin_file,
        ];
    } else {
        error_log("AISO: No update available. Current: {$current_version}, Remote: {$remote_version}");
    }

    return $transient;
}

/**
 * معالجة عملية تنزيل التحديث
 * 
 * @param mixed $reply رد النظام الافتراضي
 * @param string $package عنوان URL الخاص بالحزمة
 * @param object $upgrader كائن المحدّث
 * @return mixed
 */
function aiso_filter_upgrader_pre_download($reply, $package, $upgrader) {
    if (!$package) {
        return $reply;
    }

    $plugin = isset($upgrader->skin->plugin) ? $upgrader->skin->plugin : '';
    $plugin_slug = 'ai-image-seo-optimizer';
    
    // إذا كان التحديث متعلق بإضافتنا
    if (strpos($plugin, $plugin_slug) !== false) {
        error_log("AISO: Processing download for plugin: {$plugin}");
        error_log("AISO: Package URL: {$package}");
        
        // يمكنك هنا إضافة أي منطق خاص للتعامل مع حزمة التنزيل
        // مثل تحويل عنوان URL أو التحقق من الأمان
        
        // في معظم الحالات، يمكننا ترك الأمر للنظام الافتراضي ليقوم بالتنزيل
        return $reply;
    }

    return $reply;
}

/**
 * تهيئة محدث الإضافة
 */
function aiso_init_github_updater() {
    // للتطوير والاختبار فقط، يمكنك تعطيل تفعيل المحدث القديم عن طريق إضافة علامة تعليق
    // إذا كان المحدث الجديد يعمل بشكل صحيح، يمكنك إزالة الكود أدناه
    
    /*
    // معلومات المستودع
    $github_username = 'hassanzn2023';
    $github_repo_name = 'ai-image-seo-optimizer';
    
    // مسار ملف الإضافة الرئيسي - قد تحتاج لتعديله حسب هيكلية مشروعك
    $plugin_file = dirname(__FILE__) . '/../ai-image-seo-optimizer.php';
    
    // إنشاء كائن المحدث
    new AISO_GitHub_Updater($plugin_file, $github_username, $github_repo_name);
    */
    
    // نقوم بتسجيل دالة تشخيص للتعرف على إصدار الإضافة الحالي
    error_log('AISO: Plugin init. Current version: ' . AISO_VERSION);
}

// تشغيل المحدث عند تحميل ووردبريس
add_action('init', 'aiso_init_github_updater');

// إضافة الفلتر للتحقق من التحديثات
add_filter('pre_set_site_transient_update_plugins', 'aiso_check_for_updates_via_json');

// فلتر لمعالجة التنزيل
add_filter('upgrader_pre_download', 'aiso_filter_upgrader_pre_download', 10, 3);

/**
 * دالة معالجة طلبات معلومات الإضافة
 * توفر معلومات تفصيلية عن الإضافة عند النقر على "عرض التفاصيل"
 * 
 * @param mixed $result النتيجة الحالية
 * @param string $action الإجراء المطلوب
 * @param object $args معلومات الطلب
 * @return object معلومات الإضافة
 */
function aiso_custom_plugin_information($result, $action, $args) {
    // التحقق من أن الإجراء هو طلب معلومات الإضافة
    if ($action !== 'plugin_information') {
        return $result;
    }
    
    // التحقق من أن الإضافة المطلوبة هي إضافتنا
    if (!isset($args->slug) || strpos($args->slug, 'ai-image-seo-optimizer') === false) {
        return $result;
    }
    
    // الحصول على معلومات الإصدار من ملف version.json
    $remote_version_url = 'https://raw.githubusercontent.com/hassanzn2023/ai-image-seo-optimizer/main/version.json';
    $response = wp_remote_get($remote_version_url, array('timeout' => 10));
    
    // التحقق من نجاح الطلب
    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        error_log('AISO: Failed to fetch version info: ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response)));
        return $result;
    }
    
    $data = json_decode(wp_remote_retrieve_body($response));
    
    // التحقق من صحة البيانات
    if (!isset($data->new_version)) {
        error_log('AISO: Invalid version data received');
        return $result;
    }
    
    // إنشاء كائن المعلومات
    $info = new stdClass();
    $info->slug = 'ai-image-seo-optimizer';
    $info->name = 'AI Image SEO Optimizer';
    $info->plugin_name = 'AI Image SEO Optimizer';
    $info->version = $data->new_version;
    $info->author = isset($data->author) ? $data->author : '<a href="https://github.com/hassanzn2023">Hassan Zein</a>';
    $info->requires = isset($data->requires) ? $data->requires : '5.2';
    $info->tested = isset($data->tested) ? $data->tested : '6.4';
    $info->requires_php = isset($data->requires_php) ? $data->requires_php : '7.2';
    $info->last_updated = isset($data->last_updated) ? $data->last_updated : date('Y-m-d');
    $info->download_link = $data->download_url;
    $info->banners = isset($data->banners) ? (array)$data->banners : array(
        'high' => '',
        'low' => ''
    );
    $info->icons = isset($data->icons) ? (array)$data->icons : array(
        'default' => '',
    );
    
    // إضافة الأقسام
    if (isset($data->sections) && is_object($data->sections)) {
        $info->sections = (array)$data->sections;
    } else {
        // الأقسام الافتراضية إذا لم يتم توفيرها
        $info->sections = array(
            'description' => 'Optimize image title and alt text for Media Library images found on pages using AI. Includes bulk actions on Page Analyzer.',
            'installation' => '<ol><li>Upload the plugin files to the <code>/wp-content/plugins/ai-image-seo-optimizer</code> directory, or install the plugin through the WordPress plugins screen directly.</li><li>Activate the plugin through the \'Plugins\' screen in WordPress</li><li>Use the Settings page to configure the plugin</li></ol>',
            'changelog' => '<h3>' . $data->new_version . '</h3><ul><li>تحسينات عديدة</li></ul>',
            'screenshots' => '',
            'faq' => '<h3>How do I set up API key?</h3><p>You can set up your API key in the plugin settings page.</p>',
        );
    }
    
    // إضافة روابط مفيدة
    $info->external = isset($data->url) ? $data->url : 'https://github.com/hassanzn2023/ai-image-seo-optimizer';
    $info->homepage = isset($data->url) ? $data->url : 'https://github.com/hassanzn2023/ai-image-seo-optimizer';
    
    // تسجيل بعض معلومات التصحيح إذا كان وضع التطوير مفعل
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('AISO: Returning plugin info for slug: ' . $args->slug);
        error_log('AISO: Version: ' . $info->version);
    }
    
    return $info;
}

// إضافة فلتر لمعالجة طلبات معلومات الإضافة
add_filter('plugins_api', 'aiso_custom_plugin_information', 10, 3);
