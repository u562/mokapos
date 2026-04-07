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
    }
    
    /**
     * Рендеринг страницы настроек
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Настройки MokaPOS Integration', 'woocommerce-mokapos'); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('mokapos_settings_group'); ?>
                <?php do_settings_sections('mokapos_settings_group'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Client ID', 'woocommerce-mokapos'); ?></th>
                        <td><input type="text" name="mokapos_client_id" value="<?php echo esc_attr(get_option('mokapos_client_id')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Client Secret', 'woocommerce-mokapos'); ?></th>
                        <td><input type="password" name="mokapos_client_secret" value="<?php echo esc_attr(get_option('mokapos_client_secret')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Access Token', 'woocommerce-mokapos'); ?></th>
                        <td><input type="text" name="mokapos_access_token" value="<?php echo esc_attr(get_option('mokapos_access_token')); ?>" class="large-text" readonly /></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Webhook Secret', 'woocommerce-mokapos'); ?></th>
                        <td><input type="password" name="mokapos_webhook_secret" value="<?php echo esc_attr(get_option('mokapos_webhook_secret')); ?>" class="regular-text" /></td>
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
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2><?php echo esc_html__('Статус подключения', 'woocommerce-mokapos'); ?></h2>
            <p>
                <?php
                $token = get_option('mokapos_access_token');
                if ($token) {
                    echo '<span style="color: green;">✓ ' . esc_html__('Подключено к MokaPOS', 'woocommerce-mokapos') . '</span>';
                } else {
                    echo '<span style="color: red;">✗ ' . esc_html__('Не подключено. Введите Client ID и Client Secret.', 'woocommerce-mokapos') . '</span>';
                }
                ?>
            </p>
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
