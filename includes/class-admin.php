<?php
/**
 * Класс административной панели MokaPOS
 * 
 * @package WooCommerce_MokaPOS
 */

namespace MokaPOS;

if (!defined('ABSPATH')) {
    exit;
}

class Admin {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('plugin_action_links_' . plugin_basename(MOKAPOS_PLUGIN_DIR . '../woocommerce-mokapos.php'), [$this, 'add_plugin_links']);
    }
    
    /**
     * Добавление страницы настроек в админку
     */
    public function add_admin_menu() {
        add_options_page(
            __('MokaPOS Настройки', 'woocommerce-mokapos'),
            __('MokaPOS', 'woocommerce-mokapos'),
            'manage_woocommerce',
            'mokapos-settings',
            [$this, 'render_settings_page']
        );
    }
    
    /**
     * Регистрация настроек
     */
    public function register_settings() {
        register_setting('mokapos_settings_group', 'mokapos_client_id');
        register_setting('mokapos_settings_group', 'mokapos_client_secret');
        register_setting('mokapos_settings_group', 'mokapos_access_token');
        register_setting('mokapos_settings_group', 'mokapos_refresh_token');
        register_setting('mokapos_settings_group', 'mokapos_sync_prices');
        register_setting('mokapos_settings_group', 'mokapos_sync_stock');
        register_setting('mokapos_settings_group', 'mokapos_send_orders');
        register_setting('mokapos_settings_group', 'mokapos_webhook_secret');
        
        // Добавляем обработку действий OAuth
        add_action('admin_post_mokapos_connect', [$this, 'handle_connect_request']);
        add_action('admin_post_mokapos_callback', [$this, 'handle_oauth_callback']);
        add_action('admin_post_mokapos_disconnect', [$this, 'handle_disconnect']);
    }
    
    /**
     * Обработка запроса на подключение к MokaPOS
     */
    public function handle_connect_request() {
        check_admin_referer('mokapos_connect_nonce', 'mokapos_nonce');
        
        $client_id = sanitize_text_field($_POST['mokapos_client_id'] ?? '');
        $client_secret = sanitize_text_field($_POST['mokapos_client_secret'] ?? '');
        
        if (empty($client_id) || empty($client_secret)) {
            wp_redirect(add_query_arg([
                'page' => 'mokapos-settings',
                'error' => 'missing_credentials'
            ], admin_url('options-general.php')));
            exit;
        }
        
        // Сохраняем credentials
        update_option('mokapos_client_id', $client_id);
        update_option('mokapos_client_secret', $client_secret);
        
        // Формируем URL для авторизации
        $redirect_uri = admin_url('admin-post.php?action=mokapos_callback');
        $auth_url = add_query_arg([
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'scope' => 'read write'
        ], 'https://backoffice.mokapos.com/oauth/authorize');
        
        // Перенаправляем пользователя на Moka для авторизации
        wp_redirect($auth_url);
        exit;
    }
    
    /**
     * Обработка callback от MokaPOS после авторизации
     */
    public function handle_oauth_callback() {
        check_admin_referer('mokapos_connect_nonce', 'mokapos_nonce');
        
        $code = sanitize_text_field($_GET['code'] ?? '');
        
        if (empty($code)) {
            wp_redirect(add_query_arg([
                'page' => 'mokapos-settings',
                'error' => 'no_code'
            ], admin_url('options-general.php')));
            exit;
        }
        
        $client_id = get_option('mokapos_client_id');
        $client_secret = get_option('mokapos_client_secret');
        $redirect_uri = admin_url('admin-post.php?action=mokapos_callback');
        
        // Обмениваем code на access token
        $response = wp_remote_post('https://api.mokapos.com/oauth/token', [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            'body' => http_build_query([
                'grant_type' => 'authorization_code',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'code' => $code,
                'redirect_uri' => $redirect_uri,
            ]),
            'timeout' => 30,
        ]);
        
        if (is_wp_error($response)) {
            wp_redirect(add_query_arg([
                'page' => 'mokapos-settings',
                'error' => 'token_request_failed'
            ], admin_url('options-general.php')));
            exit;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);
        
        if ($status_code === 200 && isset($result['access_token'])) {
            update_option('mokapos_access_token', $result['access_token']);
            
            if (isset($result['refresh_token'])) {
                update_option('mokapos_refresh_token', $result['refresh_token']);
            }
            
            wp_redirect(add_query_arg([
                'page' => 'mokapos-settings',
                'success' => 'connected'
            ], admin_url('options-general.php')));
            exit;
        } else {
            wp_redirect(add_query_arg([
                'page' => 'mokapos-settings',
                'error' => 'token_exchange_failed'
            ], admin_url('options-general.php')));
            exit;
        }
    }
    
    /**
     * Отключение от MokaPOS
     */
    public function handle_disconnect() {
        check_admin_referer('mokapos_disconnect_nonce', 'mokapos_nonce');
        
        delete_option('mokapos_access_token');
        delete_option('mokapos_refresh_token');
        
        wp_redirect(add_query_arg([
            'page' => 'mokapos-settings',
            'disconnected' => '1'
        ], admin_url('options-general.php')));
        exit;
    }
    
    /**
     * Рендеринг страницы настроек
     */
    public function render_settings_page() {
        $is_connected = !empty(get_option('mokapos_access_token'));
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Настройки MokaPOS Integration', 'woocommerce-mokapos'); ?></h1>
            
            <?php if (isset($_GET['success']) && $_GET['success'] === 'connected'): ?>
                <div class="notice notice-success">
                    <p><?php echo esc_html__('Успешно подключено к MokaPOS!', 'woocommerce-mokapos'); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['disconnected']) && $_GET['disconnected'] === '1'): ?>
                <div class="notice notice-warning">
                    <p><?php echo esc_html__('Отключено от MokaPOS.', 'woocommerce-mokapos'); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error'])): ?>
                <div class="notice notice-error">
                    <p><?php 
                    switch ($_GET['error']) {
                        case 'missing_credentials':
                            echo esc_html__('Ошибка: Необходимо ввести Client ID и Client Secret.', 'woocommerce-mokapos');
                            break;
                        case 'no_code':
                            echo esc_html__('Ошибка: Не получен код авторизации от MokaPOS.', 'woocommerce-mokapos');
                            break;
                        case 'token_request_failed':
                            echo esc_html__('Ошибка: Не удалось получить токен доступа.', 'woocommerce-mokapos');
                            break;
                        case 'token_exchange_failed':
                            echo esc_html__('Ошибка: Не удалось обменять код на токен доступа.', 'woocommerce-mokapos');
                            break;
                        default:
                            echo esc_html__('Произошла неизвестная ошибка.', 'woocommerce-mokapos');
                    }
                    ?></p>
                </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('mokapos_settings_group'); ?>
                <?php do_settings_sections('mokapos_settings_group'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Client ID', 'woocommerce-mokapos'); ?></th>
                        <td>
                            <input type="text" name="mokapos_client_id" value="<?php echo esc_attr(get_option('mokapos_client_id')); ?>" class="regular-text" <?php disabled($is_connected); ?> />
                            <p class="description"><?php echo esc_html__('Получите Client ID в настройках вашего приложения на backoffice.mokapos.com', 'woocommerce-mokapos'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Client Secret', 'woocommerce-mokapos'); ?></th>
                        <td>
                            <input type="password" name="mokapos_client_secret" value="<?php echo esc_attr(get_option('mokapos_client_secret')); ?>" class="regular-text" <?php disabled($is_connected); ?> />
                            <p class="description"><?php echo esc_html__('Получите Client Secret в настройках вашего приложения на backoffice.mokapos.com', 'woocommerce-mokapos'); ?></p>
                        </td>
                    </tr>
                    
                    <?php if (!$is_connected): ?>
                    <tr>
                        <th scope="row"></th>
                        <td>
                            <?php wp_nonce_field('mokapos_connect_nonce', 'mokapos_nonce'); ?>
                            <button type="submit" name="action" value="connect" formaction="<?php echo esc_url(admin_url('admin-post.php?action=mokapos_connect')); ?>" class="button button-primary">
                                <?php echo esc_html__('Подключиться к MokaPOS', 'woocommerce-mokapos'); ?>
                            </button>
                            <p class="description"><?php echo esc_html__('После нажатия вы будете перенаправлены на сайт MokaPOS для подтверждения доступа.', 'woocommerce-mokapos'); ?></p>
                        </td>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Access Token', 'woocommerce-mokapos'); ?></th>
                        <td>
                            <input type="text" value="<?php echo esc_attr(substr(get_option('mokapos_access_token'), 0, 20) . '...'); ?>" class="large-text" readonly />
                            <p class="description"><?php echo esc_html__('Токен получен автоматически. Хранится в безопасности.', 'woocommerce-mokapos'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"></th>
                        <td>
                            <?php wp_nonce_field('mokapos_disconnect_nonce', 'mokapos_nonce'); ?>
                            <button type="submit" name="action" value="disconnect" formaction="<?php echo esc_url(admin_url('admin-post.php?action=mokapos_disconnect')); ?>" class="button button-secondary" onclick="return confirm('<?php echo esc_js(__('Вы уверены, что хотите отключиться от MokaPOS?', 'woocommerce-mokapos')); ?>')">
                                <?php echo esc_html__('Отключиться', 'woocommerce-mokapos'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <tr>
                        <th scope="row"><?php echo esc_html__('Webhook Secret', 'woocommerce-mokapos'); ?></th>
                        <td>
                            <input type="password" name="mokapos_webhook_secret" value="<?php echo esc_attr(get_option('mokapos_webhook_secret')); ?>" class="regular-text" />
                            <p class="description"><?php echo esc_html__('Опционально. Нужен только если используются webhook\'и от MokaPOS.', 'woocommerce-mokapos'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Синхронизация цен', 'woocommerce-mokapos'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="mokapos_sync_prices" value="1" <?php checked(get_option('mokapos_sync_prices', true)); ?> />
                                <?php echo esc_html__('Включить синхронизацию цен', 'woocommerce-mokapos'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Синхронизация остатков', 'woocommerce-mokapos'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="mokapos_sync_stock" value="1" <?php checked(get_option('mokapos_sync_stock', true)); ?> />
                                <?php echo esc_html__('Включить синхронизацию остатков', 'woocommerce-mokapos'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Отправка заказов', 'woocommerce-mokapos'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="mokapos_send_orders" value="1" <?php checked(get_option('mokapos_send_orders', true)); ?> />
                                <?php echo esc_html__('Отправлять заказы в MokaPOS', 'woocommerce-mokapos'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <?php if (!$is_connected): ?>
                <?php submit_button(); ?>
                <?php endif; ?>
            </form>
            
            <hr>
            
            <h2><?php echo esc_html__('Статус подключения', 'woocommerce-mokapos'); ?></h2>
            <p>
                <?php
                if ($is_connected) {
                    echo '<span style="color: green;">✓ ' . esc_html__('Подключено к MokaPOS', 'woocommerce-mokapos') . '</span>';
                } else {
                    echo '<span style="color: red;">✗ ' . esc_html__('Не подключено. Нажмите кнопку "Подключиться к MokaPOS" выше.', 'woocommerce-mokapos') . '</span>';
                }
                ?>
            </p>
            
            <hr>
            
            <h3><?php echo esc_html__('Как получить Client ID и Client Secret?', 'woocommerce-mokapos'); ?></h3>
            <ol>
                <li><?php echo esc_html__('Зайдите на', 'woocommerce-mokapos'); ?> <a href="https://api.mokapos.com/oauth/applications/" target="_blank">api.mokapos.com</a> <?php echo esc_html__('и войдите в свой аккаунт.', 'woocommerce-mokapos'); ?></li>
                <li><?php echo esc_html__('Откройте ваше приложение и нажмите "Edit".', 'woocommerce-mokapos'); ?></li>
                <li><?php echo esc_html__('Запомните Application ID из URL (например, в https://api.mokapos.com/oauth/applications/1234/edit это 1234).', 'woocommerce-mokapos'); ?></li>
                <li><?php echo esc_html__('Перейдите на', 'woocommerce-mokapos'); ?> <a href="https://backoffice.mokapos.com/" target="_blank">backoffice.mokapos.com</a> <?php echo esc_html__('и войдите.', 'woocommerce-mokapos'); ?></li>
                <li><?php echo esc_html__('Откройте ссылку:', 'woocommerce-mokapos'); ?> <code>https://backoffice.mokapos.com/apps/{ваш_app_id}/learn-more</code></li>
                <li><?php echo esc_html__('Нажмите "Get Started" → "Allow".', 'woocommerce-mokapos'); ?></li>
                <li><?php echo esc_html__('После подтверждения вы увидите Client ID и Client Secret в настройках приложения.', 'woocommerce-mokapos'); ?></li>
            </ol>
        </div>
        <?php
    }
    
    /**
     * Добавление ссылок на страницу плагинов
     */
    public function add_plugin_links($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=mokapos-settings') . '">' . __('Настройки', 'woocommerce-mokapos') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
}
