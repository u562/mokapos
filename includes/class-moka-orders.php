<?php
namespace MokaPOS;

if (!defined('ABSPATH')) exit;

class Orders {
    
    /**
     * Отправка заказа при переходе в статус "processing"
     */
    public static function on_order_processing($order_id) {
        self::send_order($order_id, 'new');
    }
    
    /**
     * Обновление заказа при завершении
     */
    public static function on_order_completed($order_id) {
        // Moka может требовать отдельный вызов для завершения
        // Реализуется при необходимости
    }
    
    /**
     * Основная логика отправки заказа
     */
    public static function send_order($order_id, $action = 'new') {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        // Проверяем, не отправлен ли уже заказ
        $moka_order_id = $order->get_meta('_moka_order_id');
        if ($moka_order_id && $action === 'new') {
            Logger::info('Order already sent to Moka', ['order_id' => $order_id, 'moka_order_id' => $moka_order_id]);
            return true;
        }
        
        $outlet_id = get_option('mokapos_outlet_id');
        if (!$outlet_id) {
            Logger::error('Cannot send order: Outlet ID not configured', ['order_id' => $order_id]);
            return false;
        }
        
        $api = new API_Client();
        
        // Формирование данных заказа
        $order_data = self::format_order_data($order, $outlet_id);
        
        $endpoint = sprintf('/v1/outlets/%d/advanced-orderings/orders', $outlet_id);
        $response = $api->request('POST', $endpoint, $order_data);
        
        if (is_wp_error($response)) {
            Logger::error('Failed to send order to Moka', [
                'order_id' => $order_id,
                'error' => $response->get_error_message()
            ]);
            $order->add_order_note('❌ Ошибка отправки в Moka POS: ' . $response->get_error_message());
            return false;
        }
        
        // Сохраняем ID заказа Moka
        $moka_order_id = $response['data'][0]['id'] ?? null;
        if ($moka_order_id) {
            $order->update_meta_data('_moka_order_id', $moka_order_id);
            $order->save_meta_data();
            
            Logger::info('Order sent to Moka', [
                'wc_order_id' => $order_id,
                'moka_order_id' => $moka_order_id
            ]);
            
            $order->add_order_note(sprintf('✅ Заказ отправлен в Moka POS. ID: %s', $moka_order_id));
        }
        
        return true;
    }
    
    /**
     * Форматирование данных заказа для API Moka
     */
    private static function format_order_data($order, $outlet_id) {
        $billing = $order->get_address('billing');
        
        return [
            'application_order_id' => (string) $order->get_id(),
            'outlet_id' => (int) $outlet_id,
            'customer_name' => trim($billing['first_name'] . ' ' . $billing['last_name']),
            'customer_phone_number' => preg_replace('/[^0-9+]/', '', $billing['phone'] ?? ''),
            'customer_email' => $order->get_billing_email(),
            'customer_address_detail' => $billing['address_1'] . ' ' . ($billing['address_2'] ?? ''),
            'customer_sub_district' => '', // Можно расширить
            'customer_district' => $billing['city'] ?? '',
            'customer_city' => $billing['city'] ?? '',
            'customer_province' => $billing['state'] ?? '',
            'customer_postal_code' => $billing['postcode'] ?? '',
            'customer_country' => $billing['country'] ?? '',
            'payment_type' => self::map_payment_method($order->get_payment_method()),
            'note' => $order->get_customer_note(),
            'order_type' => 'delivery', // или 'pickup' — настраивается
            
            // Callback URLs
            'accept_order_notification_url' => rest_url('mokapos/v1/order/accepted'),
            'complete_order_notification_url' => rest_url('mokapos/v1/order/completed'),
            'cancel_order_notification_url' => rest_url('mokapos/v1/order/rejected'),
            
            // Товары
            'order_items' => self::format_order_items($order),
            
            // Доставка (опционально)
            'delivery_fee' => (float) $order->get_shipping_total(),
            'discount_amount' => (float) $order->get_discount_total(),
            'tax_amount' => (float) $order->get_total_tax(),
            'grand_total' => (float) $order->get_total(),
        ];
    }
    
    /**
     * Форматирование товаров заказа
     */
    private static function format_order_items($order) {
        $items = [];
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            
            $moka_item_id = get_post_meta($product->get_id(), '_moka_item_id', true);
            if (!$moka_item_id) {
                Logger::warning('Order item not linked to Moka', [
                    'order_id' => $order->get_id(),
                    'product_id' => $product->get_id(),
                    'sku' => $product->get_sku()
                ]);
                continue;
            }
            
            $items[] = [
                'item_id' => (int) $moka_item_id,
                'item_name' => $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'item_price_library' => (float) $product->get_price(),
                'discount_amount' => (float) $item->get_subtotal() - (float) $item->get_total(),
                'note' => implode(', ', wp_list_pluck($item->get_meta_data(), 'value')),
                'item_modifiers' => [] // Расширение для модификаторов
            ];
        }
        
        return $items;
    }
    
    /**
     * Маппинг методов оплаты
     */
    private static function map_payment_method($wc_method) {
        $mapping = [
            'cod' => 'cash',
            'bacs' => 'bank_transfer',
            'paypal' => 'paypal',
            'stripe' => 'credit_card',
            'ppcp-gateway' => 'paypal',
            'yookassa' => 'bank_transfer',
        ];
        
        return $mapping[$wc_method] ?? 'other';
    }
    
    /**
     * Обновление статуса заказа из webhook Moka
     */
    public static function update_order_status($wc_order_id, $moka_status) {
        $order = wc_get_order($wc_order_id);
        if (!$order) {
            return false;
        }
        
        $status_map = [
            'accepted' => 'processing',
            'completed' => 'completed',
            'rejected' => 'cancelled',
            'cancelled' => 'cancelled'
        ];
        
        $wc_status = $status_map[$moka_status] ?? null;
        if ($wc_status) {
            $order->update_status($wc_status, 'Обновлено из Moka POS');
            Logger::info('Order status updated from Moka', [
                'order_id' => $wc_order_id,
                'moka_status' => $moka_status,
                'wc_status' => $wc_status
            ]);
            return true;
        }
        
        return false;
    }
}