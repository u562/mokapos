<?php
namespace MokaPOS;

if (!defined('ABSPATH')) exit;

class Auth {
    
    private $client_id;
    private $client_secret;
    private $redirect_uri;
    private $api_base;
    
    public function __construct() {
        $this->client_id = get_option('mokapos_client_id');
        $this->client_secret = get_option('mokapos_client_secret');
        $this->redirect_uri = rest_url('mokapos/v1/oauth/callback');
        $this->api_base = MOKAPOS_API_BASE;
    }
    
    /**
     * URL для авторизации пользователя в Moka
     */
    public function get_authorize_url($scopes = ['library', 'checkout', 'transaction']) {
        if (!$this->client_id) {
            return new \WP_Error('missing_config', 'Client ID not configured');
        }
        
        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => wp_generate_password(32, false)
        ];
        
        // Сохраняем state для проверки после редиректа
        set_transient('mokapos_oauth_state', $params['state'], 15 * MINUTE_IN_SECONDS);
        
        return $this->api_base . '/oauth/authorize?' . http_build_query($params);
    }
    
    /**
     * Обмен authorization code на токены
     */
    public function exchange_token($code, $state) {
        // Проверка state для защиты от CSRF
        $saved_state = get_transient('mokapos_oauth_state');
        if (!$saved_state || $saved_state !== $state) {
            return new \WP_Error('invalid_state', 'OAuth state mismatch');
        }
        delete_transient('mokapos_oauth_state');
        
        $response = wp_remote_post($this->api_base . '/oauth/token', [
            'body' => json_encode([
                'grant_type' => 'authorization_code',
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'code' => $code,
                'redirect_uri' => $this->redirect_uri
            ]),
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            Logger::error('Token exchange failed', ['error' => $response->get_error_message()]);
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status = wp_remote_retrieve_response_code($response);
        
        if ($status !== 200 || !isset($body['access_token'])) {
            Logger::error('Invalid token response', ['status' => $status, 'body' => $body]);
            return new \WP_Error('token_error', 'Failed to obtain access token', $body);
        }
        
        // Сохранение токенов
        update_option('mokapos_access_token', $body['access_token']);
        update_option('mokapos_refresh_token', $body['refresh_token'] ?? '');
        update_option('mokapos_token_expires', time() + ($body['expires_in'] ?? 3600));
        update_option('mokapos_connected', true);
        
        Logger::info('OAuth successful, tokens saved');
        
        return [
            'success' => true,
            'expires_in' => $body['expires_in'] ?? 3600
        ];
    }
    
    /**
     * Обновление токена через refresh_token
     */
    public function refresh_token($refresh_token) {
        $response = wp_remote_post($this->api_base . '/oauth/token', [
            'body' => json_encode([
                'grant_type' => 'refresh_token',
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'refresh_token' => $refresh_token
            ]),
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ],
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['access_token'])) {
            return new \WP_Error('refresh_failed', 'Could not refresh token', $body);
        }
        
        return [
            'access_token' => $body['access_token'],
            'refresh_token' => $body['refresh_token'] ?? $refresh_token,
            'expires_in' => $body['expires_in'] ?? 3600
        ];
    }
    
    /**
     * Отзыв токенов (выход)
     */
    public function revoke_tokens() {
        $access_token = get_option('mokapos_access_token');
        if (!$access_token) {
            return true;
        }
        
        wp_remote_post($this->api_base . '/oauth/revoke', [
            'body' => json_encode([
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'token' => $access_token
            ]),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10
        ]);
        
        // Очистка локальных данных
        delete_option('mokapos_access_token');
        delete_option('mokapos_refresh_token');
        delete_option('mokapos_token_expires');
        delete_option('mokapos_connected');
        
        Logger::info('Tokens revoked, connection removed');
        return true;
    }
    
    /**
     * Проверка: подключён ли плагин к Moka
     */
    public function is_connected() {
        $expires = get_option('mokapos_token_expires', 0);
        return get_option('mokapos_connected', false) && time() < $expires;
    }
}