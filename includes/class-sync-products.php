<?php
/**
 * Класс для синхронизации товаров с MokaPOS
 * 
 * @package WooCommerce_MokaPOS
 */

namespace MokaPOS;

if (!defined('ABSPATH')) {
    exit;
}

class Sync_Products {
    
    /**
     * Обработка обновления товара в WooCommerce
     */
    public static function on_product_update($product_id) {
        if (!get_option('mokapos_sync_prices', true)) {
            return;
        }
        
        try {
            $product = wc_get_product($product_id);
            
            if (!$product) {
                return;
            }
            
            // Получаем ID товара в MokaPOS
            $moka_product_id = get_post_meta($product_id, '_mokapos_product_id', true);
            
            if (empty($moka_product_id)) {
                // Пытаемся найти товар по SKU
                $sku = $product->get_sku();
                
                if (empty($sku)) {
                    Logger::debug("Товар ID {$product_id} не имеет SKU и не связан с MokaPOS");
                    return;
                }
                
                $api = new API_Client();
                $products = $api->get_products(['sku' => $sku]);
                
                if (!is_wp_error($products) && !empty($products)) {
                    $moka_product_id = $products[0]['id'];
                    update_post_meta($product_id, '_mokapos_product_id', $moka_product_id);
                    Logger::info("Товар ID {$product_id} связан с MokaPOS ID {$moka_product_id}");
                } else {
                    Logger::debug("Товар с SKU {$sku} не найден в MokaPOS");
                    return;
                }
            }
            
            $api = new API_Client();
            
            $data = [
                'price' => floatval($product->get_regular_price()),
            ];
            
            $result = $api->update_product($moka_product_id, $data);
            
            if (is_wp_error($result)) {
                Logger::error("Ошибка обновления цены товара ID {$product_id}: " . $result->get_error_message());
            } else {
                Logger::info("Цена товара ID {$product_id} обновлена в MokaPOS");
            }
            
        } catch (\Exception $e) {
            Logger::error('Ошибка синхронизации товара: ' . $e->getMessage());
        }
    }
    
    /**
     * Обработка изменения остатка товара
     */
    public static function on_stock_update($product) {
        if (!get_option('mokapos_sync_stock', true)) {
            return;
        }
        
        try {
            // Получаем ID товара
            $product_id = is_object($product) ? $product->get_id() : absint($product);
            $product = wc_get_product($product_id);
            
            if (!$product) {
                return;
            }
            
            // Получаем ID товара в MokaPOS
            $moka_product_id = get_post_meta($product_id, '_mokapos_product_id', true);
            
            if (empty($moka_product_id)) {
                return;
            }
            
            $api = new API_Client();
            
            $stock_quantity = $product->get_stock_quantity();
            
            $data = [
                'stock_quantity' => $stock_quantity !== null ? intval($stock_quantity) : 0,
            ];
            
            $result = $api->update_product($moka_product_id, $data);
            
            if (is_wp_error($result)) {
                Logger::error("Ошибка обновления остатка товара ID {$product_id}: " . $result->get_error_message());
            } else {
                Logger::info("Остаток товара ID {$product_id} обновлен в MokaPOS: {$stock_quantity}");
            }
            
        } catch (\Exception $e) {
            Logger::error('Ошибка синхронизации остатка: ' . $e->getMessage());
        }
    }
}
