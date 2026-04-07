<?php
namespace MokaPOS;

if (!defined('ABSPATH')) exit;

class API_Client {
    
    private $access_token;
    private $refresh_token;
    private $api_base;
    
    public function __construct($access_token = null, $refresh_token = null) {
        $this->access_token = $access_token ?: get_option('mokapos_access_token');
        $this->refresh_token = $refresh_token ?: get_option('mokapos_refresh_token');
        $this->api_base = MOKAPOS_API_BASE;
    }
    
    /**
     * Выполнение HTTP-запроса к API Moka
     */
    public function request($method, $endpoint, $data = [], $params = []) {
        $url = $this->api_base . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->access_token,
            'User-Agent' => 'WooCommerce-MokaPOS/' . MOKAPOS_PLUGIN_VERSION
        ];
        
        $args = [
            'method' => strtoupper($method),
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => true
        ];
        
        if (!empty($data) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            Logger::error('API Request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $response->get_error_message()
            ]);
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        // Обработка 401 — токен истёк
        if ($status_code === 401 && $this->refresh_token) {
            Logger::info('Token expired, attempting refresh');
            if ($this->refresh_access_token()) {
                return $this->request($method, $endpoint, $data, $params);
            }
        }
        
        // Логирование ошибок API
        if ($status_code >= 400) {
            Logger::error('API Error Response', [
                'status' => $status_code,
                'endpoint' => $endpoint,
                'response' => $body
            ]);
            return new \WP_Error('moka_api_error', 'API error', ['status' => $status_code, 'body' => $body]);
        }
        
        return $body;
    }
    
    /**
     * Обновление access_token через refresh_token
     */
    public function refresh_access_token() {
        $auth = new Auth();
        $result = $auth->refresh_token($this->refresh_token);
        
        if (isset($result['access_token'])) {
            $this->access_token = $result['access_token'];
            $this->refresh_token = $result['refresh_token'] ?? $this->refresh_token;
            
            update_option('mokapos_access_token', $this->access_token);
            if ($result['refresh_token'] ?? false) {
                update_option('mokapos_refresh_token', $result['refresh_token']);
            }
            
            Logger::info('Access token refreshed successfully');
            return true;
        }
        
        Logger::error('Failed to refresh token', $result);
        return false;
    }
    
    /**
     * Проверка валидности токена
     */
    public function test_connection() {
        $response = $this->request('GET', '/v1/user/profile');
        return !is_wp_error($response) && isset($response['data']);
    }
    
    /**
     * Getter для токена (для отладки)
     */
    public function get_access_token() {
        return $this->access_token;
    }
}