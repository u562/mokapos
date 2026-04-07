<?php
namespace MokaPOS;

if (!defined('ABSPATH')) exit;

class Admin {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        
        // AJAX для тестирования подключения
        add_action('wp_ajax_mokapos_test_connection', [$this, 'ajax_test_connection']);
        
        // Мета-бокс в товаре для ручной привязки
        add_action('add_meta_boxes_product', [$this, 'add_product_meta_box']);
        add_action('save_post_product', [$this, 'save_product_meta']);
    }
    
    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            'MokaPOS Integration',
            'MokaPOS',
            'manage_woocommerce',
            'mokapos-settings',
            [$this, 'render_settings_page']
        );
    }
    
    public function register_settings() {
        register_setting('mokapos_settings', 'mokapos_client_id', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('mokapos_settings', 'mokapos_client_secret', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('mokapos_settings', 'mokapos_outlet_id', ['sanitize_callback' => 'absint']);
        register_setting('mokapos_settings', 'mokapos_sync_prices', ['type' => 'boolean', 'default' => true]);
        register_setting('mokapos_settings', 'mokapos_sync_stock', ['type' => 'boolean', 'default' => true]);
        register_setting('mokapos_settings', 'mokapos_send_orders', ['type' => 'boolean', 'default' => true]);
        register_setting('mokapos_settings', 'mokapos_cron_interval', [
            'type' => 'string',
            'default' => 'hourly',
            'sanitize_callback' => function($val) {
                return in_array($val, ['hourly', 'twicedaily', 'daily']) ? $val : 'hourly';
            }
        ]);
    }
    
    public function render_settings_page() {
        $auth = new Auth();
        $is_connected = $auth->is_connected();
        ?>
        <div class="wrap">
            <h1>🔗 Интеграция с Moka POS</h1>
            
            <?php if (!$is_connected): ?>
                <div class="notice notice-warning">
                    <p>⚠️ Плагин не подключён к Moka POS. 
                    <a href="<?php echo esc_url($auth->get_authorize_url()); ?>" class="button button-primary">
                        Подключить аккаунт Moka
                    </a></p>
                </div>
            <?php else: ?>
                <div class="notice notice-success">
                    <p>✅ Подключено к Moka POS. 
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=mokapos-settings&mokapos_disconnect=1'), 'mokapos_disconnect')); ?>" 
                       class="button button-secondary">Отключить</a></p>
                </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php settings_fields('mokapos_settings'); ?>
                
                <div class="card">
                    <h2 class="title">Настройки подключения</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Client ID</th>
                            <td>
                                <input type="text" name="mokapos_client_id" 
                                       value="<?php echo esc_attr(get_option('mokapos_client_id')); ?>" 
                                       class="regular-text code">
                                <p class="description">Получите в <a href="https://connect.mokapos.com" target="_blank">Moka Connect</a></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Client Secret</th>
                            <td>
                                <input type="password" name="mokapos_client_secret" 
                                       value="<?php echo esc_attr(get_option('mokapos_client_secret')); ?>" 
                                       class="regular-text code">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Outlet ID</th>
                            <td>
                                <input type="number" name="mokapos_outlet_id" 
                                       value="<?php echo esc_attr(get_option('mokapos_outlet_id')); ?>" 
                                       class="small-text">
                                <p class="description">ID торговой точки в Moka</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="card">
                    <h2 class="title">Настройки синхронизации</h2>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Синхронизация цен</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="mokapos_sync_prices" value="1" 
                                           <?php checked(get_option('mokapos_sync_prices', true), true); ?>>
                                    Автоматически обновлять цены в Moka при изменении в WooCommerce
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Синхронизация остатков</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="mokapos_sync_stock" value="1"
                                           <?php checked(get_option('mokapos_sync_stock', true), true); ?>>
                                    Двусторонняя синхронизация остатков товаров
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Отправка заказов</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="mokapos_send_orders" value="1"
                                           <?php checked(get_option('mokapos_send_orders', true), true); ?>>
                                    Отправлять заказы в Moka при статусе "В обработке"
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Интервал фоновой синхронизации</th>
                            <td>
                                <select name="mokapos_cron_interval">
                                    <option value="hourly" <?php selected(get_option('mokapos_cron_interval'), 'hourly'); ?>>Каждый час</option>
                                    <option value="twicedaily" <?php selected(get_option('mokapos_cron_interval'), 'twicedaily'); ?>>2 раза в день</option>
                                    <option value="daily" <?php selected(get_option('mokapos_cron_interval'), 'daily'); ?>>Раз в день</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <div class="card">
                    <h2 class="title">Диагностика</h2>
                    <p>
                        <button type="button" id="mokapos-test-connection" class="button">
                            🔍 Проверить соединение с API
                        </button>
                        <span id="mokapos-test-result"></span>
                    </p>
                    <p>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mokapos-logs')); ?>" class="button">
                            📋 Просмотр логов
                        </a>
                    </p>
                </div>
                
                <?php submit_button(); ?>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#mokapos-test-connection').on('click', function() {
                var $btn = $(this);
                var $result = $('#mokapos-test-result');
                
                $btn.prop('disabled', true).text('Проверка...');
                $result.html('');
                
                $.post(ajaxurl, {
                    action: 'mokapos_test_connection',
                    nonce: '<?php echo wp_create_nonce("mokapos_test"); ?>'
                }, function(response) {
                    $btn.prop('disabled', false).text('🔍 Проверить соединение с API');
                    
                    if (response.success) {
                        $result.html('<span style="color:green">✅ Подключение успешно!</span>');
                    } else {
                        $result.html('<span style="color:red">❌ Ошибка: ' + (response.data?.message || 'Неизвестная ошибка') + '</span>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false);
                    $result.html('<span style="color:red">❌ Ошибка AJAX-запроса</span>');
                });
            });
        });
        </script>
        <?php
    }
    
    public function enqueue_assets($hook) {
        if ($hook !== 'woocommerce_page_mokapos-settings') {
            return;
        }
        wp_enqueue_script('mokapos-admin', MOKAPOS_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], MOKAPOS_PLUGIN_VERSION, true);
        wp_enqueue_style('mokapos-admin', MOKAPOS_PLUGIN_URL . 'assets/css/admin.css', [], MOKAPOS_PLUGIN_VERSION);
    }
    
    public function ajax_test_connection() {
        check_ajax_referer('mokapos_test', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Нет прав'], 403);
        }
        
        $api = new API_Client();
        $result = $api->test_connection();
        
        if ($result === true) {
            wp_send_json_success();
        } else {
            $error_msg = is_wp_error($result) ? $result->get_error_message() : 'Неизвестная ошибка';
            wp_send_json_error(['message' => $error_msg]);
        }
    }
    
    public function add_product_meta_box() {
        add_meta_box(
            'mokapos_product_link',
            '🔗 Moka POS привязка',
            [$this, 'render_product_meta_box'],
            'product',
            'side',
            'default'
        );
    }
    
    public function render_product_meta_box($post) {
        wp_nonce_field('mokapos_product_meta', 'mokapos_product_meta_nonce');
        
        $moka_item_id = get_post_meta($post->ID, '_moka_item_id', true);
        $outlet_id = get_post_meta($post->ID, '_moka_outlet_id', true);
        ?>
        <p>
            <label for="mokapos_item_id"><strong>Moka Item ID:</strong></label><br>
            <input type="number" name="mokapos_item_id" id="mokapos_item_id" 
                   value="<?php echo esc_attr($moka_item_id); ?>" 
                   class="small-text" placeholder="Например: 12345">
        </p>
        <p>
            <label for="mokapos_outlet_id"><strong>Outlet ID:</strong></label><br>
            <input type="number" name="mokapos_outlet_id" id="mokapos_outlet_id" 
                   value="<?php echo esc_attr($outlet_id ?: get_option('mokapos_outlet_id')); ?>" 
                   class="small-text">
        </p>
        <p class="description">
            Оставьте пустым для авто-привязки по SKU.
        </p>
        <?php
    }
    
    public function save_product_meta($post_id) {
        if (!isset($_POST['mokapos_product_meta_nonce']) || 
            !wp_verify_nonce($_POST['mokapos_product_meta_nonce'], 'mokapos_product_meta')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        
        if (isset($_POST['mokapos_item_id'])) {
            $item_id = absint($_POST['mokapos_item_id']);
            $outlet_id = absint($_POST['mokapos_outlet_id']);
            
            if ($item_id > 0) {
                update_post_meta($post_id, '_moka_item_id', $item_id);
                if ($outlet_id > 0) {
                    update_post_meta($post_id, '_moka_outlet_id', $outlet_id);
                }
            } else {
                delete_post_meta($post_id, '_moka_item_id');
                delete_post_meta($post_id, '_moka_outlet_id');
            }
        }
    }
}