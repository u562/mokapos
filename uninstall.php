<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Очистка опций при полном удалении (раскомментируйте при необходимости)
/*
delete_option('mokapos_client_id');
delete_option('mokapos_client_secret');
delete_option('mokapos_outlet_id');
delete_option('mokapos_access_token');
delete_option('mokapos_refresh_token');
delete_option('mokapos_token_expires');
delete_option('mokapos_connected');
delete_option('mokapos_sync_prices');
delete_option('mokapos_sync_stock');
delete_option('mokapos_send_orders');
delete_option('mokapos_cron_interval');

// Очистка мета-полей товаров
global $wpdb;
$wpdb->query("DELETE FROM $wpdb->postmeta WHERE meta_key LIKE '_moka_%'");

// Удаление логов
if (defined('MOKAPOS_LOG_DIR') && file_exists(MOKAPOS_LOG_DIR)) {
    array_map('unlink', glob(MOKAPOS_LOG_DIR . '*.log'));
    @rmdir(MOKAPOS_LOG_DIR);
}
*/