<?php
/**
 * Класс для управления Cron задачами MokaPOS
 * 
 * @package WooCommerce_MokaPOS
 */

namespace MokaPOS;

if (!defined('ABSPATH')) {
    exit;
}

class Cron {
    
    /**
     * Активация плагина - регистрация cron задач
     */
    public static function activate() {
        // Явно загружаем класс Logger если он еще не загружен
        if (!class_exists('MokaPOS\\Logger')) {
            $logger_file = MOKAPOS_PLUGIN_DIR . 'includes/class-logger.php';
            if (file_exists($logger_file)) {
                require_once $logger_file;
            }
        }
        
        // Регистрация расписаний
        if (!wp_next_scheduled('mokapos_sync_prices_event')) {
            wp_schedule_event(time(), 'hourly', 'mokapos_sync_prices_event');
        }
        
        if (!wp_next_scheduled('mokapos_sync_stock_event')) {
            wp_schedule_event(time(), 'hourly', 'mokapos_sync_stock_event');
        }
        
        if (!wp_next_scheduled('mokapos_sync_orders_event')) {
            wp_schedule_event(time(), 'twicedaily', 'mokapos_sync_orders_event');
        }
        
        // Добавляем хуки для cron задач
        add_action('mokapos_sync_prices_event', [__CLASS__, 'sync_prices']);
        add_action('mokapos_sync_stock_event', [__CLASS__, 'sync_stock']);
        add_action('mokapos_sync_orders_event', [__CLASS__, 'sync_orders']);
        
        if (class_exists('MokaPOS\\Logger')) {
            Logger::info('Cron задачи активированы');
        }
    }
    
    /**
     * Деактивация плагина - удаление cron задач
     */
    public static function deactivate() {
        // Явно загружаем класс Logger если он еще не загружен
        if (!class_exists('MokaPOS\\Logger')) {
            $logger_file = MOKAPOS_PLUGIN_DIR . 'includes/class-logger.php';
            if (file_exists($logger_file)) {
                require_once $logger_file;
            }
        }
        
        wp_clear_scheduled_hook('mokapos_sync_prices_event');
        wp_clear_scheduled_hook('mokapos_sync_stock_event');
        wp_clear_scheduled_hook('mokapos_sync_orders_event');
        
        if (class_exists('MokaPOS\\Logger')) {
            Logger::info('Cron задачи деактивированы');
        }
    }
    
    /**
     * Синхронизация цен
     */
    public static function sync_prices() {
        if (!get_option('mokapos_sync_prices', true)) {
            return;
        }
        
        // Явно загружаем классы если они еще не загружены
        if (!class_exists('MokaPOS\\Logger')) {
            $logger_file = MOKAPOS_PLUGIN_DIR . 'includes/class-logger.php';
            if (file_exists($logger_file)) {
                require_once $logger_file;
            }
        }
        
        if (!class_exists('MokaPOS\\API_Client')) {
            $api_file = MOKAPOS_PLUGIN_DIR . 'includes/class-api-client.php';
            if (file_exists($api_file)) {
                require_once $api_file;
            }
        }
        
        try {
            $api = class_exists('MokaPOS\\API_Client') ? new API_Client() : null;
            
            if (!$api) {
                error_log('MokaPOS: Класс API_Client не найден');
                return;
            }
            
            // Получаем все товары из MokaPOS
            $moka_products = $api->get_products();
            
            if (is_wp_error($moka_products)) {
                if (class_exists('MokaPOS\\Logger')) {
                    Logger::error('Ошибка получения товаров из MokaPOS: ' . $moka_products->get_error_message());
                }
                return;
            }
            
            $updated = 0;
            
            foreach ($moka_products as $moka_product) {
                $woo_product_id = get_post_meta($moka_product['id'], '_mokapos_product_id', true);
                
                if (!$woo_product_id) {
                    continue;
                }
                
                $product = wc_get_product($woo_product_id);
                
                if (!$product) {
                    continue;
                }
                
                // Обновляем цену если она отличается
                $current_price = $product->get_regular_price();
                $moka_price = isset($moka_product['price']) ? $moka_product['price'] : null;
                
                if ($moka_price && $current_price != $moka_price) {
                    $product->set_regular_price($moka_price);
                    $product->save();
                    $updated++;
                    
                    if (class_exists('MokaPOS\\Logger')) {
                        Logger::info("Цена товара ID {$woo_product_id} обновлена: {$current_price} -> {$moka_price}");
                    }
                }
            }
            
            if (class_exists('MokaPOS\\Logger')) {
                Logger::info("Синхронизация цен завершена. Обновлено товаров: {$updated}");
            }
            
        } catch (\Exception $e) {
            if (class_exists('MokaPOS\\Logger')) {
                Logger::error('Ошибка синхронизации цен: ' . $e->getMessage());
            } else {
                error_log('MokaPOS: Ошибка синхронизации цен: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Синхронизация остатков
     */
    public static function sync_stock() {
        if (!get_option('mokapos_sync_stock', true)) {
            return;
        }
        
        // Явно загружаем классы если они еще не загружены
        if (!class_exists('MokaPOS\\Logger')) {
            $logger_file = MOKAPOS_PLUGIN_DIR . 'includes/class-logger.php';
            if (file_exists($logger_file)) {
                require_once $logger_file;
            }
        }
        
        if (!class_exists('MokaPOS\\API_Client')) {
            $api_file = MOKAPOS_PLUGIN_DIR . 'includes/class-api-client.php';
            if (file_exists($api_file)) {
                require_once $api_file;
            }
        }
        
        try {
            $api = class_exists('MokaPOS\\API_Client') ? new API_Client() : null;
            
            if (!$api) {
                error_log('MokaPOS: Класс API_Client не найден');
                return;
            }
            
            // Получаем все товары из MokaPOS с остатками
            $moka_products = $api->get_products(['include_stock' => true]);
            
            if (is_wp_error($moka_products)) {
                if (class_exists('MokaPOS\\Logger')) {
                    Logger::error('Ошибка получения товаров из MokaPOS: ' . $moka_products->get_error_message());
                }
                return;
            }
            
            $updated = 0;
            
            foreach ($moka_products as $moka_product) {
                $woo_product_id = get_post_meta($moka_product['id'], '_mokapos_product_id', true);
                
                if (!$woo_product_id) {
                    continue;
                }
                
                $product = wc_get_product($woo_product_id);
                
                if (!$product) {
                    continue;
                }
                
                // Обновляем остаток если он отличается
                $current_stock = $product->get_stock_quantity();
                $moka_stock = isset($moka_product['stock_quantity']) ? $moka_product['stock_quantity'] : null;
                
                if ($moka_stock !== null && $current_stock != $moka_stock) {
                    $product->set_stock_quantity($moka_stock);
                    $product->set_manage_stock(true);
                    $product->save();
                    $updated++;
                    
                    if (class_exists('MokaPOS\\Logger')) {
                        Logger::info("Остаток товара ID {$woo_product_id} обновлен: {$current_stock} -> {$moka_stock}");
                    }
                }
            }
            
            if (class_exists('MokaPOS\\Logger')) {
                Logger::info("Синхронизация остатков завершена. Обновлено товаров: {$updated}");
            }
            
        } catch (\Exception $e) {
            if (class_exists('MokaPOS\\Logger')) {
                Logger::error('Ошибка синхронизации остатков: ' . $e->getMessage());
            } else {
                error_log('MokaPOS: Ошибка синхронизации остатков: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Синхронизация заказов
     */
    public static function sync_orders() {
        // Эта задача может использоваться для получения статусов заказов из MokaPOS
        Logger::info('Задача синхронизации заказов выполнена');
    }
}
