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

define('MOKAPOS_PLUGIN_VERSION', '1.0.1');
define('MOKAPOS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MOKAPOS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MOKAPOS_API_BASE', 'https://api.mokapos.com');

// Безопасное получение директории для логов
$upload_dir = wp_upload_dir();
if (!empty($upload_dir['error'])) {
    error_log('MokaPOS: Ошибка получения директории загрузок: ' . $upload_dir['error']);
    define('MOKAPOS_LOG_DIR', WP_CONTENT_DIR . '/uploads/mokapos-logs/');
} else {
    define('MOKAPOS_LOG_DIR', $upload_dir['basedir'] . '/mokapos-logs/');
}
unset($upload_dir);

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
    
    // Хуки для синхронизации - используем wrapper-функции для безопасной загрузки классов
    if (get_option('mokapos_sync_prices', true)) {
        add_action('woocommerce_update_product', 'mokapos_on_product_update', 10, 1);
    }
    
    if (get_option('mokapos_sync_stock', true)) {
        add_action('woocommerce_variation_set_stock', 'mokapos_on_stock_update', 10, 1);
        add_action('woocommerce_product_set_stock', 'mokapos_on_stock_update', 10, 1);
    }
    
    if (get_option('mokapos_send_orders', true)) {
        add_action('woocommerce_order_status_processing', 'mokapos_on_order_processing', 10, 1);
        add_action('woocommerce_order_status_completed', 'mokapos_on_order_completed', 10, 1);
    }
    
    // REST API endpoints для webhook'ов от Moka
    add_action('rest_api_init', 'mokapos_register_rest_routes');
}

/**
 * Регистрация REST маршрутов
 */
function mokapos_register_rest_routes() {
    register_rest_route('mokapos/v1', '/order/(?P<status>accepted|completed|rejected)', [
        'methods' => 'POST',
        'callback' => 'mokapos_handle_order_status',
        'permission_callback' => 'mokapos_verify_webhook'
    ]);
}

/**
 * Wrapper функции для безопасного вызова методов классов
 */

function mokapos_on_product_update($product_id) {
    if (!class_exists('MokaPOS\Sync_Products')) {
        mokapos_load_class('Sync_Products');
    }
    
    if (class_exists('MokaPOS\Sync_Products')) {
        MokaPOS\Sync_Products::on_product_update($product_id);
    }
}

function mokapos_on_stock_update($product) {
    if (!class_exists('MokaPOS\Sync_Products')) {
        mokapos_load_class('Sync_Products');
    }
    
    if (class_exists('MokaPOS\Sync_Products')) {
        MokaPOS\Sync_Products::on_stock_update($product);
    }
}

function mokapos_on_order_processing($order_id) {
    if (!class_exists('MokaPOS\Orders')) {
        mokapos_load_class('Orders');
    }
    
    if (class_exists('MokaPOS\Orders')) {
        MokaPOS\Orders::on_order_processing($order_id);
    }
}

function mokapos_on_order_completed($order_id) {
    if (!class_exists('MokaPOS\Orders')) {
        mokapos_load_class('Orders');
    }
    
    if (class_exists('MokaPOS\Orders')) {
        MokaPOS\Orders::on_order_completed($order_id);
    }
}

function mokapos_handle_order_status($request) {
    if (!class_exists('MokaPOS\Webhooks')) {
        mokapos_load_class('Webhooks');
    }
    
    if (class_exists('MokaPOS\Webhooks')) {
        return MokaPOS\Webhooks::handle_order_status($request);
    }
    
    return new WP_Error('class_not_found', 'Class not found', ['status' => 500]);
}

function mokapos_verify_webhook($request) {
    if (!class_exists('MokaPOS\Webhooks')) {
        mokapos_load_class('Webhooks');
    }
    
    if (class_exists('MokaPOS\Webhooks')) {
        return MokaPOS\Webhooks::verify_webhook($request);
    }
    
    return false;
}

/**
 * Функция для загрузки класса по имени
 */
function mokapos_load_class($class_name) {
    $file = MOKAPOS_PLUGIN_DIR . 'includes/class-' . str_replace('_', '-', strtolower($class_name)) . '.php';
    
    if (file_exists($file)) {
        require_once $file;
        return true;
    }
    
    error_log('MokaPOS: Файл класса не найден: ' . $file);
    return false;
}
add_action('plugins_loaded', 'mokapos_init', 20);

// Регистрация хуков активации/деактивации - используем функции-обертки для безопасности
register_activation_hook(__FILE__, 'mokapos_activate');
register_deactivation_hook(__FILE__, 'mokapos_deactivate');
register_uninstall_hook(__FILE__, 'mokapos_uninstall');

/**
 * Активация плагина
 */
function mokapos_activate() {
    // Проверяем, что класс загружен (на случай если плагины загружены)
    if (!class_exists('MokaPOS\\Cron')) {
        // Пытаемся загрузить вручную, если автозагрузчик еще не зарегистрирован
        $cron_file = MOKAPOS_PLUGIN_DIR . 'includes/class-cron.php';
        if (file_exists($cron_file)) {
            require_once $cron_file;
        }
    }
    
    if (class_exists('MokaPOS\\Cron')) {
        MokaPOS\Cron::activate();
    }
    
    // Создаем директорию для логов при активации
    if (!file_exists(MOKAPOS_LOG_DIR)) {
        wp_mkdir_p(MOKAPOS_LOG_DIR);
    }
    
    // Очищаем кэш перезаписи правил
    flush_rewrite_rules();
}

/**
 * Деактивация плагина
 */
function mokapos_deactivate() {
    // Проверяем, что класс загружен
    if (!class_exists('MokaPOS\\Cron')) {
        $cron_file = MOKAPOS_PLUGIN_DIR . 'includes/class-cron.php';
        if (file_exists($cron_file)) {
            require_once $cron_file;
        }
    }
    
    if (class_exists('MokaPOS\\Cron')) {
        MokaPOS\Cron::deactivate();
    }
    
    // Очищаем кэш перезаписи правил
    flush_rewrite_rules();
}

/**
 * Удаление плагина
 */
function mokapos_uninstall() {
    // Очистка опций при удалении (опционально, раскомментируйте при необходимости)
    // delete_option('mokapos_client_id');
    // delete_option('mokapos_client_secret');
    // delete_option('mokapos_access_token');
    // delete_option('mokapos_refresh_token');
    // delete_option('mokapos_sync_prices');
    // delete_option('mokapos_sync_stock');
    // delete_option('mokapos_send_orders');
    
    // Удаляем директорию с логами (опционально)
    // if (file_exists(MOKAPOS_LOG_DIR)) {
    //     wp_delete_file(MOKAPOS_LOG_DIR);
    // }
}
