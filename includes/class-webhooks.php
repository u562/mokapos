<?php
/**
 * Класс для обработки webhook'ов от MokaPOS
 * 
 * @package WooCommerce_MokaPOS
 */

namespace MokaPOS;

if (!defined('ABSPATH')) {
    exit;
}

class Webhooks {
    
    /**
     * Обработка изменения статуса заказа от MokaPOS
     */
    public static function handle_order_status($request) {
        try {
            $params = $request->get_params();
            $status = $params['status'];
            
            // Получаем данные из тела запроса
            $data = $request->get_json_params();
            
            if (empty($data['order_id'])) {
                return new \WP_Error('missing_order_id', 'Order ID is required', ['status' => 400]);
            }
            
            $external_order_id = $data['order_id'];
            
            // Ищем заказ в WooCommerce по external_id
            $orders = wc_get_orders([
                'meta_key' => '_mokapos_order_id',
                'meta_value' => $external_order_id,
                'limit' => 1,
            ]);
            
            if (empty($orders)) {
                // Пытаемся найти по external_id если это ID заказа WooCommerce
                $woo_order = wc_get_order($external_order_id);
                
                if (!$woo_order) {
                    Logger::error("Заказ не найден: {$external_order_id}");
                    return new \WP_Error('order_not_found', 'Order not found', ['status' => 404]);
                }
            } else {
                $woo_order = reset($orders);
            }
            
            $order_id = $woo_order->get_id();
            
            // Маппинг статусов MokaPOS -> WooCommerce
            $status_map = [
                'accepted' => 'processing',
                'completed' => 'completed',
                'rejected' => 'cancelled',
            ];
            
            $new_status = isset($status_map[$status]) ? $status_map[$status] : null;
            
            if ($new_status) {
                $woo_order->update_status($new_status, 'Обновлено из MokaPOS');
                Logger::info("Статус заказа ID {$order_id} обновлен: {$new_status} (из MokaPOS: {$status})");
            }
            
            return rest_ensure_response([
                'success' => true,
                'order_id' => $order_id,
                'new_status' => $new_status,
            ]);
            
        } catch (\Exception $e) {
            Logger::error('Ошибка обработки webhook: ' . $e->getMessage());
            return new \WP_Error('webhook_error', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Проверка подписи webhook
     */
    public static function verify_webhook($request) {
        $webhook_secret = get_option('mokapos_webhook_secret');
        
        // Если секрет не настроен, разрешаем все запросы (для разработки)
        if (empty($webhook_secret)) {
            Logger::warning('Webhook secret не настроен. Запросы принимаются без проверки.');
            return true;
        }
        
        // Получаем подпись из заголовка
        $signature = $request->get_header('X-MokaPOS-Signature');
        
        if (empty($signature)) {
            Logger::warning('Webhook запрос без подписи');
            return false;
        }
        
        // Получаем тело запроса
        $body = $request->get_body();
        
        // Вычисляем ожидаемую подпись
        $expected_signature = hash_hmac('sha256', $body, $webhook_secret);
        
        // Сравниваем подписи
        if (!hash_equals($expected_signature, $signature)) {
            Logger::error('Неверная подпись webhook');
            return false;
        }
        
        return true;
    }
}
