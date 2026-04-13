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

/**
 * Простая функция логирования для использования до загрузки классов
 */
function mokapos_log_message($message, $level = 'info') {
    $log_file = MOKAPOS_LOG_DIR . 'mokapos-' . date('Y-m-d') . '.log';
    
    // Создаем директорию если не существует
    if (!file_exists(MOKAPOS_LOG_DIR)) {
        wp_mkdir_p(MOKAPOS_LOG_DIR);
    }
    
    $timestamp = current_time('mysql');
    $log_entry = "[$timestamp] [$level] $message\n";
    
    file_put_contents($log_file, $log_entry, FILE_APPEND);
    error_log('MokaPOS [' . $level . ']: ' . $message);
}

/**
 * Обработка запроса на подключение к MokaPOS
 */
function mokapos_handle_connect_request() {
    try {
        // Включаем отображение ошибок для отладки
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        
        // Проверяем nonce
        if (!isset($_POST['mokapos_nonce']) || !wp_verify_nonce($_POST['mokapos_nonce'], 'mokapos_connect_nonce')) {
            mokapos_log_message('Invalid nonce in connect request', 'error');
            wp_die('Ошибка безопасности: неверный nonce');
        }
        
        $client_id = sanitize_text_field($_POST['mokapos_client_id'] ?? '');
        $client_secret = sanitize_text_field($_POST['mokapos_client_secret'] ?? '');
        
        mokapos_log_message('Connect request started. Client ID: ' . ($client_id ? 'present' : 'empty'), 'debug');
        
        if (empty($client_id) || empty($client_secret)) {
            mokapos_log_message('Missing credentials', 'error');
            wp_redirect(add_query_arg([
                'page' => 'mokapos-settings',
                'error' => 'missing_credentials'
            ], admin_url('options-general.php')));
            exit;
        }
        
        // Сохраняем credentials
        update_option('mokapos_client_id', $client_id);
        update_option('mokapos_client_secret', $client_secret);
        
        // Формируем URL для авторизации
        $redirect_uri = admin_url('admin-post.php?action=mokapos_callback', 'https');
        
        // Сохраняем redirect_uri для последующего использования
        update_option('mokapos_redirect_uri', $redirect_uri);
        
        mokapos_log_message('OAuth authorize request. Redirect URI: ' . $redirect_uri, 'debug');
        
        $auth_url = add_query_arg([
            'client_id' => $client_id,
            'redirect_uri' => urlencode($redirect_uri),
            'response_type' => 'code',
            'scope' => 'profile sales_type checkout checkout_api transaction library customer report'
        ], 'https://service-goauth.mokapos.com/oauth/authorize');
        
        mokapos_log_message('Redirecting to: ' . $auth_url, 'debug');
        
        // Перенаправляем пользователя на Moka для авторизации
        wp_redirect($auth_url);
        exit;
    } catch (\Exception $e) {
        mokapos_log_message('Exception in handle_connect_request: ' . $e->getMessage(), 'error');
        error_log('MokaPOS Exception: ' . $e->getMessage());
        wp_die('Ошибка подключения: ' . $e->getMessage());
    }
}

/**
 * Обработка callback от MokaPOS после авторизации
 */
function mokapos_handle_oauth_callback() {
    try {
        // Включаем отображение ошибок для отладки
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        
        $code = sanitize_text_field($_GET['code'] ?? '');
        
        mokapos_log_message('OAuth callback received. Code: ' . ($code ? 'present' : 'empty'), 'debug');
        
        if (empty($code)) {
            mokapos_log_message('No code received in callback', 'error');
            wp_redirect(add_query_arg([
                'page' => 'mokapos-settings',
                'error' => 'no_code'
            ], admin_url('options-general.php')));
            exit;
        }
        
        $client_id = get_option('mokapos_client_id');
        $client_secret = get_option('mokapos_client_secret');
        $redirect_uri = get_option('mokapos_redirect_uri', admin_url('admin-post.php?action=mokapos_callback'));
        
        mokapos_log_message('Exchanging code for token. Redirect URI: ' . $redirect_uri, 'debug');
        
        // Обмениваем code на access token
        $response = wp_remote_post('https://api.mokapos.com/oauth/token', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            'body' => http_build_query([
                'grant_type' => 'authorization_code',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'code' => $code,
                'redirect_uri' => $redirect_uri,
            ]),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            mokapos_log_message('OAuth token request error: ' . $response->get_error_message(), 'error');
            wp_redirect(add_query_arg([
                'page' => 'mokapos-settings',
                'error' => 'token_request_failed'
            ], admin_url('options-general.php')));
            exit;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        mokapos_log_message('OAuth token response (status: ' . $status_code . '): ' . print_r($result, true), 'debug');
        
        if ($status_code === 200 && isset($result['access_token'])) {
            update_option('mokapos_access_token', $result['access_token']);
            
            if (isset($result['refresh_token'])) {
                update_option('mokapos_refresh_token', $result['refresh_token']);
            }
            
            mokapos_log_message('Successfully obtained access token', 'info');
            
            wp_redirect(add_query_arg([
                'page' => 'mokapos-settings',
                'success' => 'connected'
            ], admin_url('options-general.php')));
            exit;
        } else {
            mokapos_log_message('OAuth token exchange failed (status: ' . $status_code . '): ' . $body, 'error');
            wp_redirect(add_query_arg([
                'page' => 'mokapos-settings',
                'error' => 'token_exchange_failed'
            ], admin_url('options-general.php')));
            exit;
        }
    } catch (\Exception $e) {
        mokapos_log_message('Exception in handle_oauth_callback: ' . $e->getMessage(), 'error');
        error_log('MokaPOS Exception: ' . $e->getMessage());
        wp_die('Ошибка обработки callback: ' . $e->getMessage());
    }
}

/**
 * Отключение от MokaPOS
 */
function mokapos_handle_disconnect() {
    // Проверяем nonce
    if (!isset($_GET['mokapos_nonce']) || !wp_verify_nonce($_GET['mokapos_nonce'], 'mokapos_disconnect_nonce')) {
        wp_die('Ошибка безопасности: неверный nonce');
    }
    
    delete_option('mokapos_access_token');
    delete_option('mokapos_refresh_token');
    
    wp_redirect(add_query_arg([
        'page' => 'mokapos-settings',
        'disconnected' => '1'
    ], admin_url('options-general.php')));
    exit;
}

// Регистрируем хуки OAuth внутри hooks_loaded чтобы они сработали корректно
add_action('plugins_loaded', function() {
    add_action('admin_post_mokapos_connect', 'mokapos_handle_connect_request');
    add_action('admin_post_mokapos_callback', 'mokapos_handle_oauth_callback');
    add_action('admin_post_mokapos_disconnect', 'mokapos_handle_disconnect');
}, 5);

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
    // Сначала загружаем Logger, так как он нужен другим классам
    if (!class_exists('MokaPOS\\Logger')) {
        $logger_file = MOKAPOS_PLUGIN_DIR . 'includes/class-logger.php';
        if (file_exists($logger_file)) {
            require_once $logger_file;
        }
    }
    
    // Затем загружаем API_Client, если нужен
    if (!class_exists('MokaPOS\\API_Client')) {
        $api_file = MOKAPOS_PLUGIN_DIR . 'includes/class-api-client.php';
        if (file_exists($api_file)) {
            require_once $api_file;
        }
    }
    
    // Загружаем Cron
    if (!class_exists('MokaPOS\\Cron')) {
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
    // Сначала загружаем Logger, так как он нужен другим классам
    if (!class_exists('MokaPOS\\Logger')) {
        $logger_file = MOKAPOS_PLUGIN_DIR . 'includes/class-logger.php';
        if (file_exists($logger_file)) {
            require_once $logger_file;
        }
    }
    
    // Загружаем Cron
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
    // delete_option('mokapos_webhook_secret');
    
    // Удаляем директорию с логами
    if (file_exists(MOKAPOS_LOG_DIR)) {
        global $wp_filesystem;
        if (null === $wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        $wp_filesystem->delete(MOKAPOS_LOG_DIR, true);
    }
}
