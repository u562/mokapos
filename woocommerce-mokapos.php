<?php
/**
 * Plugin Name: WooCommerce MokaPOS Integration
 * Plugin URI: https://mokapos.com
 * Description: Синхронизация товаров, цен, остатков и заказов между WooCommerce и Moka POS CRM
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yoursite.com
 * Text Domain: woocommerce-mokapos
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 *
 * @package WooCommerce_MokaPOS
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('MOKAPOS_PLUGIN_VERSION', '1.0.0');
define('MOKAPOS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MOKAPOS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MOKAPOS_API_BASE', 'https://api.mokapos.com');
define('MOKAPOS_LOG_DIR', wp_upload_dir()['basedir'] . '/mokapos-logs/');

// Проверка зависимостей
function mokapos_check_dependencies() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>MokaPOS Integration:</strong> Требуется активный плагин WooCommerce.</p></div>';
        });
        return false;
    }
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>MokaPOS Integration:</strong> Требуется PHP 7.4 или выше.</p></div>';
        });
        return false;
    }
    return true;
}

// Инициализация плагина
function mokapos_init() {
    if (!mokapos_check_dependencies()) {
        return;
    }

    // Создание директории для логов
    if (!file_exists(MOKAPOS_LOG_DIR)) {
        wp_mkdir_p(MOKAPOS_LOG_DIR);
    }

    // Автозагрузка классов
    spl_autoload_register(function($class) {
        $prefix = 'MokaPOS\\';
        $base_dir = MOKAPOS_PLUGIN_DIR . 'includes/';
        
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        
        $relative_class = substr($class, $len);
        $file = $base_dir . 'class-' . str_replace('_', '-', strtolower($relative_class)) . '.php';
        
        if (file_exists($file)) {
            require $file;
        }
    });

    // Инициализация компонентов
    if (is_admin()) {
        new MokaPOS\Admin();
    }
    
    new MokaPOS\Cron();
    new MokaPOS\Webhooks();
    
    // Хуки для синхронизации
    if (get_option('mokapos_sync_prices', true)) {
        add_action('woocommerce_update_product', ['MokaPOS\Sync_Products', 'on_product_update'], 10, 1);
    }
    
    if (get_option('mokapos_sync_stock', true)) {
        add_action('woocommerce_variation_set_stock', ['MokaPOS\Sync_Products', 'on_stock_update'], 10, 1);
        add_action('woocommerce_product_set_stock', ['MokaPOS\Sync_Products', 'on_stock_update'], 10, 1);
    }
    
    if (get_option('mokapos_send_orders', true)) {
        add_action('woocommerce_order_status_processing', ['MokaPOS\Orders', 'on_order_processing'], 10, 1);
        add_action('woocommerce_order_status_completed', ['MokaPOS\Orders', 'on_order_completed'], 10, 1);
    }
    
    // REST API endpoints для webhook'ов от Moka
    add_action('rest_api_init', function() {
        register_rest_route('mokapos/v1', '/order/(?P<status>accepted|completed|rejected)', [
            'methods' => 'POST',
            'callback' => ['MokaPOS\Webhooks', 'handle_order_status'],
            'permission_callback' => ['MokaPOS\Webhooks', 'verify_webhook']
        ]);
    });
}
add_action('plugins_loaded', 'mokapos_init', 20);

// Регистрация хуков активации/деактивации
register_activation_hook(__FILE__, ['MokaPOS\Cron', 'activate']);
register_deactivation_hook(__FILE__, ['MokaPOS\Cron', 'deactivate']);
register_uninstall_hook(__FILE__, 'mokapos_uninstall');

function mokapos_uninstall() {
    // Очистка опций при удалении (опционально)
    // delete_option('mokapos_client_id');
    // и т.д.
}