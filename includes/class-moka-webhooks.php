<?php
namespace MokaPOS;

if (!defined('ABSPATH')) exit;

class Webhooks {
    
    /**
     * Проверка подписи webhook от Moka
     */
    public static function verify_webhook(\WP_REST_Request $request) {
        // Moka может отправлять подпись в заголовке
        // Реализуйте проверку при наличии документации по подписям
        // Пока разрешаем все запросы (добавьте валидацию при необходимости)
        return true;
    }
    
    /**
     * Обработчик изменения статуса заказа
     */
    public static function handle_order_status(\WP_REST_Request $request) {
        $params = $request->get_params();
        $status = $params['status']; // accepted|completed|rejected
        $body = $request->get_json_params();
        
        Logger::info('Webhook received', ['status' => $status, 'body' => $body]);
        
        // Извлекаем ID заказа WooCommerce
        $app_order_id = $body['application_order_id'] ?? null;
        if (!$app_order_id || !is_numeric($app_order_id)) {
            return new \WP_REST_Response(['error' => 'Invalid application_order_id'], 400);
        }
        
        $wc_order_id = (int) $app_order_id;
        
        // Обновляем статус заказа
        if (Orders::update_order_status($wc_order_id, $status)) {
            return new \WP_REST_Response(['success' => true], 200);
        }
        
        return new \WP_REST_Response(['error' => 'Failed to update order'], 500);
    }
    
    /**
     * Регистрация REST-маршрутов (вызывается из главного файла)
     */
    public static function register_routes() {
        // Регистрация происходит в главном файле через rest_api_init
    }
}


// Для обработки OAuth2 callback от Moka
add_action('rest_api_init', function() {
    register_rest_route('mokapos/v1', '/oauth/callback', [
        'methods' => 'GET',
        'callback' => function(\WP_REST_Request $request) {
            $code = $request->get_param('code');
            $state = $request->get_param('state');
            $error = $request->get_param('error');
            
            if ($error) {
                Logger::error('OAuth error', ['error' => $error]);
                wp_redirect(admin_url('admin.php?page=mokapos-settings&mokapos_error=' . urlencode($error)));
                exit;
            }
            
            if (!$code) {
                wp_redirect(admin_url('admin.php?page=mokapos-settings&mokapos_error=no_code'));
                exit;
            }
            
            $auth = new \MokaPOS\Auth();
            $result = $auth->exchange_token($code, $state);
            
            if (is_wp_error($result)) {
                wp_redirect(admin_url('admin.php?page=mokapos-settings&mokapos_error=' . urlencode($result->get_error_message())));
                exit;
            }
            
            wp_redirect(admin_url('admin.php?page=mokapos-settings&mokapos_connected=1'));
            exit;
        },
        'permission_callback' => '__return_true'
    ]);
});