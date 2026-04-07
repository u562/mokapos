<?php
/**
 * Класс для работы с заказами MokaPOS
 * 
 * @package WooCommerce_MokaPOS
 */

namespace MokaPOS;

if (!defined('ABSPATH')) {
    exit;
}

class Orders {
    
    /**
     * Обработка заказа при переходе в статус "processing"
     */
    public static function on_order_processing($order_id) {
        if (!get_option('mokapos_send_orders', true)) {
            return;
        }
        
        self::send_order_to_moka($order_id, 'accepted');
    }
    
    /**
     * Обработка заказа при переходе в статус "completed"
     */
    public static function on_order_completed($order_id) {
        if (!get_option('mokapos_send_orders', true)) {
            return;
        }
        
        self::send_order_to_moka($order_id, 'completed');
    }
    
    /**
     * Отправка заказа в MokaPOS
     */
    private static function send_order_to_moka($order_id, $status) {
        try {
            $order = wc_get_order($order_id);
            
            if (!$order) {
                Logger::error("Заказ ID {$order_id} не найден");
                return;
            }
            
            // Проверяем, был ли уже отправлен этот заказ
            $moka_order_id = get_post_meta($order_id, '_mokapos_order_id', true);
            
            $api = new API_Client();
            
            // Формируем данные заказа для MokaPOS
            $items = [];
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                $sku = $product ? $product->get_sku() : '';
                
                // Пытаемся найти ID товара в MokaPOS по SKU
                $moka_product_id = null;
                
                if ($product) {
                    $moka_product_id = get_post_meta($product->get_id(), '_mokapos_product_id', true);
                    
                    if (empty($moka_product_id) && !empty($sku)) {
                        $products = $api->get_products(['sku' => $sku]);
                        
                        if (!is_wp_error($products) && !empty($products)) {
                            $moka_product_id = $products[0]['id'];
                            update_post_meta($product->get_id(), '_mokapos_product_id', $moka_product_id);
                        }
                    }
                }
                
                $items[] = [
                    'product_id' => $moka_product_id,
                    'sku' => $sku,
                    'name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'price' => floatval($order->get_item_subtotal($item, false)),
                    'total' => floatval($item->get_total()),
                ];
            }
            
            $order_data = [
                'external_id' => (string) $order_id,
                'status' => $status,
                'customer' => [
                    'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'phone' => $order->get_billing_phone(),
                    'email' => $order->get_billing_email(),
                ],
                'shipping_address' => [
                    'address' => $order->get_billing_address_1(),
                    'city' => $order->get_billing_city(),
                    'postal_code' => $order->get_billing_postcode(),
                    'country' => $order->get_billing_country(),
                ],
                'items' => $items,
                'subtotal' => floatval($order->get_subtotal()),
                'shipping_total' => floatval($order->get_shipping_total()),
                'tax_total' => floatval($order->get_total_tax()),
                'total' => floatval($order->get_total()),
                'currency' => $order->get_currency(),
                'created_at' => $order->get_date_created()->format('Y-m-d H:i:s'),
            ];
            
            if ($moka_order_id) {
                // Обновляем существующий заказ
                $result = $api->update_order_status($moka_order_id, $status);
                
                if (is_wp_error($result)) {
                    Logger::error("Ошибка обновления статуса заказа ID {$order_id} в MokaPOS: " . $result->get_error_message());
                } else {
                    Logger::info("Статус заказа ID {$order_id} обновлен в MokaPOS: {$status}");
                }
            } else {
                // Создаем новый заказ
                $result = $api->create_order($order_data);
                
                if (is_wp_error($result)) {
                    Logger::error("Ошибка создания заказа ID {$order_id} в MokaPOS: " . $result->get_error_message());
                } else {
                    if (isset($result['id'])) {
                        update_post_meta($order_id, '_mokapos_order_id', $result['id']);
                        Logger::info("Заказ ID {$order_id} создан в MokaPOS с ID {$result['id']}");
                    }
                }
            }
            
        } catch (\Exception $e) {
            Logger::error('Ошибка отправки заказа в MokaPOS: ' . $e->getMessage());
        }
    }
}
