<?php
/**
 * Класс для работы с API MokaPOS
 * 
 * @package WooCommerce_MokaPOS
 */

namespace MokaPOS;

if (!defined('ABSPATH')) {
    exit;
}

class API_Client {
    
    private $client_id;
    private $client_secret;
    private $access_token;
    private $refresh_token;
    private $api_base = MOKAPOS_API_BASE;
    
    public function __construct() {
        $this->client_id = get_option('mokapos_client_id');
        $this->client_secret = get_option('mokapos_client_secret');
        $this->access_token = get_option('mokapos_access_token');
        $this->refresh_token = get_option('mokapos_refresh_token');
    }
    
    /**
     * Получение товаров из MokaPOS
     */
    public function get_products($params = []) {
        return $this->request('GET', '/products', $params);
    }
    
    /**
     * Обновление товара в MokaPOS
     */
    public function update_product($product_id, $data) {
        return $this->request('PUT', "/products/{$product_id}", $data);
    }
    
    /**
     * Создание заказа в MokaPOS
     */
    public function create_order($order_data) {
        return $this->request('POST', '/orders', $order_data);
    }
    
    /**
     * Обновление статуса заказа в MokaPOS
     */
    public function update_order_status($order_id, $status) {
        return $this->request('PATCH', "/orders/{$order_id}/status", ['status' => $status]);
    }
    
    /**
     * Выполнение HTTP запроса к API
     */
    private function request($method, $endpoint, $data = []) {
        $url = $this->api_base . $endpoint;
        
        // Если нет токена, пытаемся получить его
        if (empty($this->access_token)) {
            $token_result = $this->get_access_token();
            
            if (is_wp_error($token_result)) {
                return $token_result;
            }
        }
        
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->access_token,
        ];
        
        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
        ];
        
        if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = wp_json_encode($data);
        } elseif (!empty($data) && $method === 'GET') {
            $url = add_query_arg($data, $url);
        }
        
        Logger::debug("API Request: {$method} {$url}");
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            Logger::error('API Request error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        Logger::debug("API Response: {$status_code} - " . substr($body, 0, 200));
        
        // Если токен истек, пробуем обновить
        if ($status_code === 401) {
            $token_result = $this->refresh_access_token();
            
            if (!is_wp_error($token_result)) {
                // Повторяем запрос с новым токеном
                $headers['Authorization'] = 'Bearer ' . $this->access_token;
                $args['headers'] = $headers;
                
                $response = wp_remote_request($url, $args);
                
                if (is_wp_error($response)) {
                    return $response;
                }
                
                $status_code = wp_remote_retrieve_response_code($response);
                $body = wp_remote_retrieve_body($response);
                $result = json_decode($body, true);
            }
        }
        
        if ($status_code >= 400) {
            $error_message = isset($result['message']) ? $result['message'] : 'Unknown API error';
            Logger::error("API Error {$status_code}: {$error_message}");
            return new \WP_Error('mokapos_api_error', $error_message, ['status' => $status_code]);
        }
        
        return $result;
    }
    
    /**
     * Получение access token через client credentials (устаревший метод, оставлен для совместимости)
     * Примечание: MokaPOS требует OAuth Authorization Code Flow, этот метод может не работать
     */
    public function get_access_token() {
        if (empty($this->client_id) || empty($this->client_secret)) {
            return new \WP_Error('missing_credentials', 'Client ID или Client Secret не настроены');
        }
        
        Logger::warning('Попытка получения токена через client_credentials. MokaPOS требует OAuth Authorization Code Flow.');
        
        return new \WP_Error('oauth_required', 'Для подключения используйте кнопку "Подключиться к MokaPOS" в настройках плагина. Прямое получение токена через client_credentials не поддерживается MokaPOS.');
    }
    
    /**
     * Обновление access token через refresh token
     */
    public function refresh_access_token() {
        if (empty($this->refresh_token)) {
            return $this->get_access_token();
        }
        
        $url = $this->api_base . '/oauth/token';
        
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ];
        
        $body = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $this->refresh_token,
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
        ];
        
        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => http_build_query($body),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            Logger::error('Token refresh error: ' . $response->get_error_message());
            return $this->get_access_token();
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if ($status_code === 200 && isset($result['access_token'])) {
            $this->access_token = $result['access_token'];
            update_option('mokapos_access_token', $result['access_token']);
            
            if (isset($result['refresh_token'])) {
                $this->refresh_token = $result['refresh_token'];
                update_option('mokapos_refresh_token', $result['refresh_token']);
            }
            
            Logger::info('Access token обновлен успешно');
            return $result;
        }
        
        Logger::error('Failed to refresh access token: ' . $body);
        return $this->get_access_token();
    }
}
