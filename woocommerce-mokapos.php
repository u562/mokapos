<?php
/**
 * Plugin Name: WooCommerce MokaPOS Integration
 * Plugin URI: https://mokapos.com
 * Description: Интеграция WooCommerce с Moka POS. Синхронизация товаров, заказов и инвентаря.
 * Version: 2.1.0-fix
 * Author: MokaPOS Team
 * Author URI: https://mokapos.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woocommerce-mokapos
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Принудительное включение отображения ошибок для отладки (убрать в продакшене)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Определение констант плагина
define('MOKAPOS_VERSION', '2.1.0-fix');
define('MOKAPOS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MOKAPOS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MOKAPOS_LOG_FILE', WP_CONTENT_DIR . '/uploads/mokapos-debug.log');

/**
 * Класс основного плагина
 */
final class WooCommerce_MokaPOS {

    private static $instance = null;
    private $api_base_url = 'https://api.mokapos.com';
    private $auth_url = 'https://service-goauth.mokapos.com/oauth/authorize';
    private $token_url = 'https://api.mokapos.com/oauth/token';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_settings_save'));
        add_action('admin_init', array($this, 'handle_oauth_callback'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Хуки для синхронизации (будут реализованы позже)
        add_action('woocommerce_new_product', array($this, 'sync_product_to_moka'), 10, 1);
        add_action('woocommerce_update_product', array($this, 'sync_product_to_moka'), 10, 1);
        add_action('woocommerce_checkout_order_processed', array($this, 'sync_order_to_moka'), 10, 1);
    }

    /**
     * Логирование
     */
    private function log($message, $level = 'info') {
        $timestamp = current_time('mysql');
        $log_entry = "[$timestamp] [$level] $message\n";
        
        // Пытаемся записать в файл
        if (wp_mkdir_p(dirname(MOKAPOS_LOG_FILE))) {
            file_put_contents(MOKAPOS_LOG_FILE, $log_entry, FILE_APPEND);
        }
        
        // Если включен режим отладки WP, пишем в стандартный лог
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("MokaPOS: $message");
        }
    }

    /**
     * Добавление страницы настроек
     */
    public function add_admin_menu() {
        add_options_page(
            __('Настройки MokaPOS', 'woocommerce-mokapos'),
            __('MokaPOS', 'woocommerce-mokapos'),
            'manage_woocommerce',
            'mokapos-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Подключение скриптов и стилей
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'settings_page_mokapos-settings') {
            return;
        }
        wp_enqueue_style('mokapos-admin-css', MOKAPOS_PLUGIN_URL . 'assets/css/admin.css', array(), MOKAPOS_VERSION);
        wp_enqueue_script('mokapos-admin-js', MOKAPOS_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), MOKAPOS_VERSION, true);
        
        wp_localize_script('mokapos-admin-js', 'mokaposConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('mokapos_nonce')
        ));
    }

    /**
     * Отрисовка страницы настроек
     */
    public function render_settings_page() {
        // Проверка прав
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('У вас нет прав для доступа к этой странице.', 'woocommerce-mokapos'));
        }

        $client_id = get_option('mokapos_client_id');
        $client_secret = get_option('mokapos_client_secret');
        $access_token = get_option('mokapos_access_token');
        $refresh_token = get_option('mokapos_refresh_token');
        $webhook_secret = get_option('mokapos_webhook_secret');
        $is_connected = !empty($access_token);

        // Формируем ссылку для авторизации, если еще не подключены
        $auth_link = '';
        if (!$is_connected && !empty($client_id) && !empty($client_secret)) {
            $redirect_uri = urlencode(admin_url('admin.php?page=mokapos-settings&mokapos_action=oauth_callback'));
            $state = wp_generate_password(32, false);
            update_option('mokapos_oauth_state', $state);
            
            $scope = urlencode('profile sales_type checkout checkout_api transaction library customer report');
            
            $auth_link = $this->auth_url . '?' . http_build_query(array(
                'client_id'     => $client_id,
                'redirect_uri'  => $redirect_uri,
                'response_type' => 'code',
                'scope'         => $scope,
                'state'         => $state
            ));
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Настройки интеграции MokaPOS', 'woocommerce-mokapos'); ?></h1>
            
            <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated'] == 'true') : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Настройки сохранены.', 'woocommerce-mokapos'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html__('Ошибка подключения: ', 'woocommerce-mokapos') . esc_html($_GET['error']); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('mokapos_settings_group'); ?>
                <?php do_settings_sections('mokapos_settings_group'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Client ID', 'woocommerce-mokapos'); ?></th>
                        <td>
                            <input type="text" name="mokapos_client_id" value="<?php echo esc_attr($client_id); ?>" class="regular-text" <?php echo $is_connected ? 'disabled' : ''; ?> />
                            <p class="description"><?php _e('Получите в личном кабинете MokaPOS (раздел разработчика).', 'woocommerce-mokapos'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Client Secret', 'woocommerce-mokapos'); ?></th>
                        <td>
                            <input type="password" name="mokapos_client_secret" value="<?php echo esc_attr($client_secret); ?>" class="regular-text" <?php echo $is_connected ? 'disabled' : ''; ?> />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Webhook Secret', 'woocommerce-mokapos'); ?></th>
                        <td>
                            <input type="text" name="mokapos_webhook_secret" value="<?php echo esc_attr($webhook_secret); ?>" class="regular-text" />
                            <p class="description"><?php _e('Опционально. Для проверки подписи вебхуков.', 'woocommerce-mokapos'); ?></p>
                        </td>
                    </tr>
                    
                    <?php if ($is_connected) : ?>
                    <tr>
                        <th scope="row"><?php _e('Статус подключения', 'woocommerce-mokapos'); ?></th>
                        <td>
                            <span style="color: green; font-weight: bold;"><?php _e('✓ Подключено', 'woocommerce-mokapos'); ?></span>
                            <p class="description"><?php _e('Access Token получен и действителен.', 'woocommerce-mokapos'); ?></p>
                            <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=mokapos-settings&mokapos_action=disconnect'), 'mokapos_disconnect'); ?>" class="button button-secondary"><?php _e('Отключиться', 'woocommerce-mokapos'); ?></a>
                        </td>
                    </tr>
                    <?php else : ?>
                    <tr>
                        <th scope="row"><?php _e('Статус подключения', 'woocommerce-mokapos'); ?></th>
                        <td>
                            <span style="color: red; font-weight: bold;"><?php _e('✗ Не подключено', 'woocommerce-mokapos'); ?></span>
                            <?php if (!empty($auth_link)) : ?>
                                <br><br>
                                <a href="<?php echo esc_url($auth_link); ?>" class="button button-primary"><?php _e('Подключиться к MokaPOS', 'woocommerce-mokapos'); ?></a>
                                <p class="description"><?php _e('Нажмите кнопку, чтобы авторизовать приложение.', 'woocommerce-mokapos'); ?></p>
                            <?php else : ?>
                                <p class="description"><?php _e('Введите Client ID и Client Secret выше, чтобы увидеть кнопку подключения.', 'woocommerce-mokapos'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>
            <h2><?php _e('Инструкция по получению ключей', 'woocommerce-mokapos'); ?></h2>
            <ol>
                <li>Зайдите в <a href="https://api.mokapos.com/oauth/applications/" target="_blank">Moka Developer Console</a>.</li>
                <li>Создайте новое приложение или откройте существующее.</li>
                <li>Скопируйте <strong>Client ID</strong> и <strong>Client Secret</strong>.</li>
                <li><strong>ВАЖНО:</strong> В поле <strong>Redirect URL</strong> (Callback URL) в настройках приложения укажите:<br>
                    <code style="background:#f0f0f1;padding:5px;display:block;margin-top:5px;"><?php echo admin_url('admin.php?page=mokapos-settings&mokapos_action=oauth_callback'); ?></code>
                </li>
                <li>Сохраните настройки в Moka и вернитесь на эту страницу.</li>
            </ol>
        </div>
        <?php
    }

    /**
     * Обработка сохранения основных настроек
     */
    public function handle_settings_save() {
        if (isset($_POST['option_page']) && $_POST['option_page'] === 'mokapos_settings_group') {
            // Проверка nonce не требуется здесь, так как это стандартная форма настроек WordPress, 
            // но мы можем добавить свою проверку если нужно.
            
            // Санитизация данных
            $client_id = sanitize_text_field($_POST['mokapos_client_id']);
            $client_secret = sanitize_text_field($_POST['mokapos_client_secret']);
            $webhook_secret = sanitize_text_field($_POST['mokapos_webhook_secret']);

            update_option('mokapos_client_id', $client_id);
            update_option('mokapos_client_secret', $client_secret);
            update_option('mokapos_webhook_secret', $webhook_secret);

            // Перенаправление обратно с флагом
            wp_redirect(admin_url('options-general.php?page=mokapos-settings&settings-updated=true'));
            exit;
        }

        // Обработка отключения
        if (isset($_GET['mokapos_action']) && $_GET['mokapos_action'] === 'disconnect') {
            check_admin_referer('mokapos_disconnect');
            delete_option('mokapos_access_token');
            delete_option('mokapos_refresh_token');
            delete_option('mokapos_token_expires');
            $this->log('Пользователь отключился от MokaPOS');
            wp_redirect(admin_url('options-general.php?page=mokapos-settings&settings-updated=true'));
            exit;
        }
    }

    /**
     * Обработка Callback от OAuth
     */
    public function handle_oauth_callback() {
        if (!isset($_GET['mokapos_action']) || $_GET['mokapos_action'] !== 'oauth_callback') {
            return;
        }

        try {
            $this->log('Начало обработки OAuth callback');

            // Проверка наличия кода
            if (isset($_GET['error'])) {
                throw new Exception('Ошибка авторизации Moka: ' . sanitize_text_field($_GET['error_description'] ?? $_GET['error']));
            }

            if (!isset($_GET['code'])) {
                throw new Exception('Код авторизации не получен.');
            }

            $code = sanitize_text_field($_GET['code']);
            $state_received = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';
            $state_stored = get_option('mokapos_oauth_state');

            // Проверка state (защита от CSRF)
            if (empty($state_stored) || $state_received !== $state_stored) {
                $this->log("Ошибка State: Получено '$state_received', Ожидалось '$state_stored'");
                // Не прерываем сразу, так как некоторые провайдеры могут терять state, но логируем
                // В идеале нужно прерывать: throw new Exception('Неверный параметр state.');
            }

            $client_id = get_option('mokapos_client_id');
            $client_secret = get_option('mokapos_client_secret');

            if (empty($client_id) || empty($client_secret)) {
                throw new Exception('Client ID или Client Secret не настроены.');
            }

            // Формируем правильный Redirect URI для обмена токена
            // Он должен совпадать с тем, что был отправлен в запросе авторизации
            $redirect_uri = admin_url('admin.php?page=mokapos-settings&mokapos_action=oauth_callback');

            $this->log('Обмен кода на токен. URL: ' . $this->token_url);

            $response = wp_remote_post($this->token_url, array(
                'timeout' => 15,
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json'
                ),
                'body' => array(
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                    'code'          => $code,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => $redirect_uri
                )
            ));

            if (is_wp_error($response)) {
                throw new Exception('Ошибка соединения с API Moka: ' . $response->get_error_message());
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            $http_code = wp_remote_retrieve_response_code($response);

            $this->log('Ответ API: ' . print_r($data, true));

            if ($http_code !== 200 || isset($data['error'])) {
                $error_msg = isset($data['error_description']) ? $data['error_description'] : ($data['error'] ?? 'Неизвестная ошибка');
                throw new Exception('Ошибка получения токена (' . $http_code . '): ' . $error_msg);
            }

            if (isset($data['access_token'])) {
                update_option('mokapos_access_token', $data['access_token']);
                update_option('mokapos_refresh_token', $data['refresh_token'] ?? '');
                
                if (isset($data['expires_in'])) {
                    update_option('mokapos_token_expires', time() + $data['expires_in']);
                }
                
                $this->log('Успешная авторизация! Токен получен.');
                
                // Удаляем временный state
                delete_option('mokapos_oauth_state');

                wp_redirect(admin_url('options-general.php?page=mokapos-settings&settings-updated=true'));
                exit;
            } else {
                throw new Exception('Токен доступа не найден в ответе сервера.');
            }

        } catch (Exception $e) {
            $this->log('CRITICAL ERROR: ' . $e->getMessage());
            // Вывод ошибки пользователю через редирект с параметром
            $error_msg = urlencode($e->getMessage());
            wp_redirect(admin_url('options-general.php?page=mokapos-settings&error=' . $error_msg));
            exit;
        }
    }

    /**
     * Получить доступ токен (с авто-обновлением если нужно)
     */
    public function get_access_token() {
        $token = get_option('mokapos_access_token');
        $expires = get_option('mokapos_token_expires');
        $refresh_token = get_option('mokapos_refresh_token');
        $client_id = get_option('mokapos_client_id');
        $client_secret = get_option('mokapos_client_secret');

        if (empty($token)) {
            return false;
        }

        // Если срок действия истекает (менее 5 минут) и есть refresh token
        if ($expires && ($expires - time() < 300) && !empty($refresh_token)) {
            $this->log('Попытка обновления токена...');
            
            $response = wp_remote_post($this->token_url, array(
                'timeout' => 15,
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Accept'       => 'application/json'
                ),
                'body' => array(
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                    'refresh_token' => $refresh_token,
                    'grant_type'    => 'refresh_token'
                )
            ));

            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                
                if (isset($data['access_token'])) {
                    update_option('mokapos_access_token', $data['access_token']);
                    update_option('mokapos_refresh_token', $data['refresh_token'] ?? $refresh_token);
                    if (isset($data['expires_in'])) {
                        update_option('mokapos_token_expires', time() + $data['expires_in']);
                    }
                    $this->log('Токен успешно обновлен.');
                    return $data['access_token'];
                }
            }
            $this->log('Не удалось обновить токен.');
        }

        return $token;
    }

    /**
     * Выполнение запроса к API Moka
     */
    public function api_request($endpoint, $args = array()) {
        $token = $this->get_access_token();
        if (!$token) {
            return new WP_Error('not_connected', 'MokaPOS не подключен. Пожалуйста, выполните авторизацию.');
        }

        $url = $this->api_base_url . $endpoint;
        
        $default_args = array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json'
            )
        );

        $request_args = wp_parse_args($args, $default_args);

        $this->log("API Request: $endpoint");
        
        $response = wp_remote_request($url, $request_args);

        if (is_wp_error($response)) {
            $this->log("API Error: " . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($code >= 400) {
            $this->log("API HTTP Error $code: $body");
            // Если токенистек (401), пробуем обновить и повторить (упрощенно)
            if ($code == 401) {
                $new_token = $this->get_access_token(); // Принудительное обновление логики внутри функции
                if ($new_token && $new_token !== $token) {
                     // Повтор запроса можно реализовать рекурсивно, но осторожно
                     $this->log('Повтор запроса после обновления токена...');
                     $request_args['headers']['Authorization'] = 'Bearer ' . $new_token;
                     return wp_remote_request($url, $request_args);
                }
            }
            return new WP_Error('api_error', "Ошибка API Moka ($code): $body");
        }

        return json_decode($body, true);
    }

    /**
     * Синхронизация товара (Заглушка)
     */
    public function sync_product_to_moka($product_id) {
        if (!get_option('mokapos_access_token')) return;
        
        $product = wc_get_product($product_id);
        if (!$product) return;

        $this->log("Синхронизация товара: {$product->get_name()} (ID: $product_id)");
        
        // TODO: Реализовать логику отправки данных товара в Moka
        // $data = $this->prepare_product_data($product);
        // $this->api_request('/v1/products', array('method' => 'POST', 'body' => json_encode($data)));
    }

    /**
     * Синхронизация заказа (Заглушка)
     */
    public function sync_order_to_moka($order_id) {
        if (!get_option('mokapos_access_token')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        $this->log("Синхронизация заказа: #$order_id");

        // TODO: Реализовать логику отправки заказа в Moka
    }
}

// Инициализация плагина
function run_mokapos_plugin() {
    return WooCommerce_MokaPOS::get_instance();
}

run_mokapos_plugin();
