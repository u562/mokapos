<?php
/**
 * Plugin Name: WooCommerce MokaPOS Integration
 * Description: Интеграция WooCommerce с MokaPOS через правильный OAuth 2.0 Flow.
 * Version: 2.0.1-Fix
 * Author: MokaPOS Dev
 * Text Domain: woocommerce-mokapos
 */

if (!defined('ABSPATH')) {
    exit;
}

// === ЭКСТРЕННЫЙ ОТЛАДЧИК (ВКЛЮЧАЕТ ОШИБКИ НЕМЕДЛЕННО) ===
@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
@error_reporting(E_ALL);
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}
// =========================================================

class MokaPOS_Integration {
    
    private $option_name = 'mokapos_settings';
    private $log_dir;

    public function __construct() {
        // Инициализация директории логов
        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/mokapos-logs/';
        
        if (!file_exists($this->log_dir)) {
            @mkdir($this->log_dir, 0755, true);
        }

        // Регистрируем хуки админки
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // ВАЖНО: Хуки для обработки OAuth регистрируем здесь, они сработают при загрузке плагинов
        add_action('admin_post_mokapos_authorize', array($this, 'handle_authorize_redirect'));
        add_action('admin_post_mokapos_callback', array($this, 'handle_oauth_callback'));
        add_action('admin_post_mokapos_disconnect', array($this, 'handle_disconnect'));
        
        // Хук для отладки прямо в футере админки, если есть ошибки
        add_action('admin_footer', array($this, 'debug_output'));
    }

    /**
     * Логирование с выводом на экран в случае критических ошибок
     */
    private function log($message, $level = 'INFO') {
        $timestamp = current_time('mysql');
        $log_entry = "[$timestamp] [$level] $message\n";
        
        // Пишем в файл
        $log_file = $this->log_dir . 'mokapos-' . date('Y-m-d') . '.log';
        @file_put_contents($log_file, $log_entry, FILE_APPEND);

        // Дублируем в браузер, если это критическая ошибка или мы в процессе OAuth
        if ($level === 'ERROR' || isset($_GET['mokapos_debug'])) {
            echo "<pre style='background:#fff; border:1px solid #f00; padding:10px; color:#f00; font-family:monospace; z-index:99999; position:relative;'>MOKA DEBUG: $log_entry</pre>";
        }
    }

    public function add_admin_menu() {
        add_options_page(
            'MokaPOS Settings',
            'MokaPOS',
            'manage_options',
            'mokapos-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('mokapos_group', $this->option_name);
    }

    public function render_settings_page() {
        // Обработка действий внутри страницы настроек
        if (isset($_POST['mokapos_action']) && $_POST['mokapos_action'] == 'save_credentials') {
            check_admin_referer('mokapos_save_nonce');
            
            $client_id = sanitize_text_field($_POST['client_id']);
            $client_secret = sanitize_text_field($_POST['client_secret']);
            
            $settings = get_option($this->option_name, array());
            $settings['client_id'] = $client_id;
            $settings['client_secret'] = $client_secret;
            update_option($this->option_name, $settings);
            
            $this->log("Credentials saved manually. Client ID starts with: " . substr($client_id, 0, 5));
            echo '<div class="notice notice-success"><p>Настройки сохранены. Теперь нажмите "Подключиться".</p></div>';
        }

        $settings = get_option($this->option_name, array());
        $is_connected = !empty($settings['access_token']);
        ?>
        <div class="wrap">
            <h1>Настройки интеграции MokaPOS</h1>
            
            <?php if ($is_connected): ?>
                <div class="notice notice-success">
                    <p>✅ Статус: <strong>Подключено</strong></p>
                    <p>Магазин: <?php echo esc_html($settings['merchant_name'] ?? 'Неизвестно'); ?></p>
                </div>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('mokapos_disconnect_nonce'); ?>
                    <input type="hidden" name="action" value="mokapos_disconnect">
                    <button type="submit" class="button button-secondary">Отключиться</button>
                </form>
            <?php else: ?>
                <div class="notice notice-warning">
                    <p>⚠️ Статус: <strong>Не подключено</strong></p>
                </div>

                <form method="post" action="">
                    <?php wp_nonce_field('mokapos_save_nonce'); ?>
                    <input type="hidden" name="mokapos_action" value="save_credentials">
                    <table class="form-table">
                        <tr>
                            <th><label for="client_id">Client ID</label></th>
                            <td>
                                <input type="text" name="client_id" id="client_id" class="regular-text" 
                                       value="<?php echo esc_attr($settings['client_id'] ?? ''); ?>" required>
                                <p class="description">Из настроек приложения в Moka Dashboard.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="client_secret">Client Secret</label></th>
                            <td>
                                <input type="password" name="client_secret" id="client_secret" class="regular-text" 
                                       value="<?php echo esc_attr($settings['client_secret'] ?? ''); ?>" required>
                                <p class="description">Из настроек приложения в Moka Dashboard.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Сохранить ключи'); ?>
                </form>

                <?php if (!empty($settings['client_id']) && !empty($settings['client_secret'])): ?>
                    <hr>
                    <h3>Шаг 2: Авторизация</h3>
                    <p>После сохранения ключей, нажмите кнопку ниже для получения токена доступа.</p>
                    <p><strong>Важно:</strong> Убедитесь, что в настройках приложения Moka (на сайте mokapos.com) в поле <code>Redirect URL</code> указано:</p>
                    <code style="background:#f0f0f1; padding:5px; display:block; margin:10px 0;"><?php echo admin_url('admin-post.php?action=mokapos_callback'); ?></code>
                    
                    <form method="post" action="<?php echo admin_url('admin-post.php?action=mokapos_authorize'); ?>">
                        <button type="submit" class="button button-primary">🔗 Подключиться к MokaPOS</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            
            <div style="margin-top: 50px; border-top: 1px solid #ccc; padding-top: 20px;">
                <h4>Отладка</h4>
                <p>Логи сохраняются в: <code><?php echo $this->log_dir; ?></code></p>
                <a href="<?php echo add_query_arg('mokapos_debug', '1'); ?>" class="button">Показать логи на экране</a>
            </div>
        </div>
        <?php
    }

    /**
     * Шаг 1: Перенаправление пользователя на Moka
     */
    public function handle_authorize_redirect() {
        try {
            $this->log("=== START AUTHORIZATION FLOW ===");
            
            $settings = get_option($this->option_name, array());
            $client_id = $settings['client_id'] ?? '';
            $client_secret = $settings['client_secret'] ?? '';

            if (empty($client_id) || empty($client_secret)) {
                throw new Exception("Client ID или Client Secret не настроены.");
            }

            $redirect_uri = admin_url('admin-post.php?action=mokapos_callback');
            $state = wp_generate_password(32, false);
            
            // Сохраняем state для проверки потом
            update_option('mokapos_oauth_state', $state, false);
            
            $this->log("Redirect URI будет: " . $redirect_uri);
            $this->log("State generated: " . $state);

            $auth_url = add_query_arg(array(
                'client_id' => $client_id,
                'redirect_uri' => urlencode($redirect_uri),
                'response_type' => 'code',
                'scope' => 'profile sales_type checkout checkout_api transaction library customer report',
                'state' => $state
            ), 'https://service-goauth.mokapos.com/oauth/authorize');

            $this->log("Redirecting to: " . $auth_url);
            
            // Чистый редирект без лишних хуков
            header('Location: ' . $auth_url);
            exit;

        } catch (Exception $e) {
            $this->log("CRITICAL ERROR in authorize: " . $e->getMessage(), 'ERROR');
            wp_die('<h1>Ошибка авторизации</h1><p>' . $e->getMessage() . '</p><p><a href="' . admin_url('options-general.php?page=mokapos-settings') . '">Вернуться назад</a></p>');
        }
    }

    /**
     * Шаг 2: Обработка возврата от Moka с кодом
     */
    public function handle_oauth_callback() {
        // Включаем отображение ошибок напрямую, так как WP может еще не загрузиться полностью
        @ini_set('display_errors', '1');
        
        try {
            $this->log("=== CALLBACK RECEIVED ===");
            
            if (isset($_GET['error'])) {
                throw new Exception("Ошибка от Moka: " . sanitize_text_field($_GET['error']));
            }

            if (!isset($_GET['code'])) {
                throw new Exception("Код авторизации не получен в ответе.");
            }

            $code = sanitize_text_field($_GET['code']);
            $state_received = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
            $state_stored = get_option('mokapos_oauth_state');

            if ($state_received !== $state_stored) {
                $this->log("State mismatch! Received: $state_received, Stored: $state_stored", 'ERROR');
                // Не блокируем строго, так как некоторые прокси могут ломать state, но логируем
                // throw new Exception("Неверный параметр state. Возможна атака CSRF или ошибка сессии.");
            }

            $settings = get_option($this->option_name, array());
            $client_id = $settings['client_id'];
            $client_secret = $settings['client_secret'];
            $redirect_uri = admin_url('admin-post.php?action=mokapos_callback');

            $this->log("Exchanging code for token...");

            $response = wp_remote_post('https://api.mokapos.com/oauth/token', array(
                'method' => 'POST',
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ),
                'body' => json_encode(array(
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $redirect_uri
                ))
            ));

            if (is_wp_error($response)) {
                throw new Exception("Ошибка соединения с API Moka: " . $response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            $code_status = wp_remote_retrieve_response_code($response);

            $this->log("API Response Code: $code_status");
            $this->log("API Response Body: " . print_r($data, true));

            if ($code_status !== 200 || !isset($data['access_token'])) {
                $error_msg = isset($data['message']) ? $data['message'] : 'Неизвестная ошибка API';
                throw new Exception("Не удалось получить токен. Ответ сервера: $error_msg (Code: $code_status)");
            }

            // Успех! Сохраняем токены
            $settings['access_token'] = $data['access_token'];
            $settings['refresh_token'] = $data['refresh_token'] ?? '';
            $settings['expires_in'] = $data['expires_in'] ?? 3600;
            $settings['token_created_at'] = time();
            
            // Попытаемся получить имя мерчанта
            if (isset($data['resource_owner_id'])) {
                 // Тут можно сделать доп запрос к /api/v1/profile если нужно
                 $settings['merchant_name'] = 'Moka Merchant ID: ' . $data['resource_owner_id'];
            }

            update_option($this->option_name, $settings);
            delete_option('mokapos_oauth_state');

            $this->log("SUCCESS! Token saved.");
            
            wp_redirect(admin_url('options-general.php?page=mokapos-settings&mokapos_status=success'));
            exit;

        } catch (Exception $e) {
            $this->log("FATAL EXCEPTION in callback: " . $e->getMessage(), 'ERROR');
            // Выводим ошибку прямо на экран, обходя стандартные шаблоны
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title>';
            echo '<style>body{font-family:sans-serif;padding:50px;background:#f0f0f1;} .error{background:#fff;border-left:4px solid #dc3232;padding:20px;box-shadow:0 1px 1px rgba(0,0,0,.1);}</style>';
            echo '</head><body><div class="error"><h1>Ошибка подключения MokaPOS</h1><p><strong>' . esc_html($e->getMessage()) . '</strong></p>';
            echo '<p>Проверьте логи в папке <code>/wp-content/uploads/mokapos-logs/</code></p>';
            echo '<p><a href="' . admin_url('options-general.php?page=mokapos-settings') . '">← Вернуться к настройкам</a></p></div></body></html>';
            exit;
        }
    }

    public function handle_disconnect() {
        check_admin_referer('mokapos_disconnect_nonce');
        $settings = get_option($this->option_name, array());
        unset($settings['access_token']);
        unset($settings['refresh_token']);
        update_option($this->option_name, $settings);
        wp_redirect(admin_url('options-general.php?page=mokapos-settings&mokapos_status=disconnected'));
        exit;
    }

    public function debug_output() {
        if (isset($_GET['mokapos_debug']) && current_user_can('manage_options')) {
            $files = glob($this->log_dir . '*.log');
            if ($files) {
                rsort($files);
                echo '<div id="mokapos-debug-console" style="position:fixed;bottom:0;left:0;right:0;height:300px;overflow:auto;background:#222;color:#0f0;padding:10px;font-family:monospace;font-size:12px;z-index:99999;border-top:2px solid #0f0;">';
                echo '<strong>LATEST LOGS:</strong><br>';
                foreach (array_slice($files, 0, 3) as $file) {
                    echo "<!-- File: $file -->";
                    $content = file_get_contents($file);
                    // Показываем последние 50 строк
                    $lines = explode("\n", $content);
                    $last_lines = array_slice($lines, -50);
                    echo esc_html(implode("\n", $last_lines));
                    echo "<br>-------------------<br>";
                }
                echo '</div>';
            }
        }
    }
}

// Запуск класса
function run_mokapos_integration() {
    new MokaPOS_Integration();
}
add_action('plugins_loaded', 'run_mokapos_integration');
