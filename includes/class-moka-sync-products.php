<?php
namespace MokaPOS;

if (!defined('ABSPATH')) exit;

class Sync_Products {
    
    /**
     * Обработчик обновления товара в WooCommerce
     */
    public static function on_product_update($product_id) {
        if (!get_option('mokapos_sync_prices', true)) {
            return;
        }
        
        $product = wc_get_product($product_id);
        if (!$product) return;
        
        // Пропускаем, если товар не привязан к Moka
        $moka_item_id = get_post_meta($product_id, '_moka_item_id', true);
        if (!$moka_item_id) {
            // Пытаемся найти и привязать автоматически
            self::auto_link_product($product);
            return;
        }
        
        self::sync_single_product($product, $moka_item_id);
    }
    
    /**
     * Обработчик обновления остатка
     */
    public static function on_stock_update($product) {
        if (!get_option('mokapos_sync_stock', true)) {
            return;
        }
        
        $product_id = $product instanceof \WC_Product ? $product->get_id() : $product;
        $moka_item_id = get_post_meta($product_id, '_moka_item_id', true);
        
        if ($moka_item_id) {
            self::sync_single_product(wc_get_product($product_id), $moka_item_id, true);
        }
    }
    
    /**
     * Синхронизация одного товара
     */
    private static function sync_single_product($product, $moka_item_id, $stock_only = false) {
        $api = new API_Client();
        $outlet_id = get_post_meta($product->get_id(), '_moka_outlet_id', true) 
                   ?: get_option('mokapos_outlet_id');
        
        if (!$outlet_id) {
            Logger::error('Outlet ID missing for product sync', ['product_id' => $product->get_id()]);
            return false;
        }
        
        $update_data = [];
        
        if (!$stock_only && get_option('mokapos_sync_prices', true)) {
            $update_data['price'] = (float) $product->get_price();
        }
        
        if (get_option('mokapos_sync_stock', true) && $product->managing_stock()) {
            $update_data['stock_quantity'] = (int) $product->get_stock_quantity();
        }
        
        if (empty($update_data)) {
            return true; // Нечего синхронизировать
        }
        
        $endpoint = sprintf('/v1/outlets/%d/items/%d', $outlet_id, $moka_item_id);
        $response = $api->request('PUT', $endpoint, $update_data);
        
        if (is_wp_error($response)) {
            Logger::error('Product sync failed', [
                'product_id' => $product->get_id(),
                'moka_item_id' => $moka_item_id,
                'error' => $response->get_error_message()
            ]);
            return false;
        }
        
        Logger::info('Product synced', [
            'product_id' => $product->get_id(),
            'moka_item_id' => $moka_item_id,
            'data' => $update_data
        ]);
        
        return true;
    }
    
    /**
     * Автоматическая привязка товара по SKU
     */
    private static function auto_link_product($product) {
        $sku = $product->get_sku();
        if (!$sku) {
            return false;
        }
        
        $api = new API_Client();
        $outlet_id = get_option('mokapos_outlet_id');
        
        if (!$outlet_id) {
            return false;
        }
        
        // Поиск товара в Moka по SKU
        $response = $api->request('GET', '/v1/items', [], [
            'outlet_id' => $outlet_id,
            'search' => $sku,
            'per_page' => 20
        ]);
        
        if (is_wp_error($response) || empty($response['data'])) {
            return false;
        }
        
        // Ищем точное совпадение по SKU
        foreach ($response['data'] as $item) {
            if (strcasecmp($item['sku'], $sku) === 0) {
                // Сохраняем привязку
                update_post_meta($product->get_id(), '_moka_item_id', $item['id']);
                update_post_meta($product->get_id(), '_moka_outlet_id', $outlet_id);
                
                Logger::info('Product auto-linked', [
                    'wc_product_id' => $product->get_id(),
                    'moka_item_id' => $item['id'],
                    'sku' => $sku
                ]);
                
                // Сразу синхронизируем
                return self::sync_single_product($product, $item['id']);
            }
        }
        
        return false;
    }
    
    /**
     * Массовая синхронизация (для cron)
     */
    public static function batch_sync($limit = 50) {
        $args = [
            'post_type' => 'product',
            'posts_per_page' => $limit,
            'meta_query' => [
                [
                    'key' => '_moka_item_id',
                    'value' => '',
                    'compare' => '!='
                ]
            ],
            'fields' => 'ids'
        ];
        
        $product_ids = get_posts($args);
        $synced = 0;
        
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            $moka_item_id = get_post_meta($product_id, '_moka_item_id', true);
            
            if ($product && $moka_item_id) {
                if (self::sync_single_product($product, $moka_item_id)) {
                    $synced++;
                }
                // Небольшая пауза для rate limiting
                usleep(200000); // 0.2 секунды
            }
        }
        
        Logger::info('Batch sync completed', ['synced' => $synced, 'total' => count($product_ids)]);
        return $synced;
    }
    
    /**
     * Ручная привязка товара из админки
     */
    public static function manual_link($product_id, $moka_item_id, $outlet_id) {
        update_post_meta($product_id, '_moka_item_id', $moka_item_id);
        update_post_meta($product_id, '_moka_outlet_id', $outlet_id);
        
        Logger::info('Product manually linked', [
            'wc_product_id' => $product_id,
            'moka_item_id' => $moka_item_id,
            'outlet_id' => $outlet_id
        ]);
        
        // Сразу синхронизируем
        $product = wc_get_product($product_id);
        return $product ? self::sync_single_product($product, $moka_item_id) : false;
    }
}