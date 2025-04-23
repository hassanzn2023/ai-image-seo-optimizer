<?php
// إذا تم الوصول للملف مباشرة
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// حذف خيارات الإضافة
delete_option('aiso_settings');

// حذف البيانات الوصفية المؤقتة
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_aiso_temp_%'");