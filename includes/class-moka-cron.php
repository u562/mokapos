<?php
namespace MokaPOS;

if (!defined('ABSPATH')) exit;

class Cron {
    
    const CRON_HOOK = 'mokapos_scheduled_sync';
    
    public static function activate() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $interval = get_option('mokapos_cron_interval', 'hourly');
            wp_schedule_event(time(), $interval, self::CRON_HOOK);
        }
    }
    
    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }
    
    public function __construct() {
        add_action(self::CRON_HOOK, [$this, 'run_scheduled_sync']);
        
        // Обновление расписания при изменении настроек
        add_action('update_option_mokapos_cron_interval', [$this, 'reschedule'], 10, 2);
    }
    
    public function reschedule($old_value, $new_value) {
        self::deactivate();
        self::activate();
    }
    
    public function run_scheduled_sync() {
        Logger::info('Starting scheduled sync job');
        
        // 1. Синхронизация товаров
        if (get_option('mokapos_sync_prices', true) || get_option('mokapos_sync_stock', true)) {
            $synced = Sync_Products::batch_sync(50);
            Logger::info('Scheduled sync: products synced', ['count' => $synced]);
        }
        
        // 2. Очистка старых логов
        Logger::cleanup_old_logs(7);
        
        Logger::info('Scheduled sync job completed');
    }
    
    /**
     * Ручной запуск синхронизации из админки
     */
    public static function run_manual_sync() {
        if (!current_user_can('manage_woocommerce')) {
            return new \WP_Error('forbidden', 'Нет прав');
        }
        
        return Sync_Products::batch_sync(100);
    }
}