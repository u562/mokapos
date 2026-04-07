<?php
/**
 * Класс для логирования событий MokaPOS
 * 
 * @package WooCommerce_MokaPOS
 */

namespace MokaPOS;

if (!defined('ABSPATH')) {
    exit;
}

class Logger {
    
    /**
     * Логирование информационного сообщения
     */
    public static function info($message) {
        self::log('INFO', $message);
    }
    
    /**
     * Логирование предупреждения
     */
    public static function warning($message) {
        self::log('WARNING', $message);
    }
    
    /**
     * Логирование ошибки
     */
    public static function error($message) {
        self::log('ERROR', $message);
    }
    
    /**
     * Логирование отладочной информации
     */
    public static function debug($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            self::log('DEBUG', $message);
        }
    }
    
    /**
     * Основная функция логирования
     */
    private static function log($level, $message) {
        $timestamp = current_time('mysql');
        $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        // Логируем в файл
        $log_file = MOKAPOS_LOG_DIR . 'mokapos-' . date('Y-m-d') . '.log';
        
        if (!file_exists(MOKAPOS_LOG_DIR)) {
            wp_mkdir_p(MOKAPOS_LOG_DIR);
        }
        
        file_put_contents($log_file, $log_entry, FILE_APPEND);
        
        // Также логируем в стандартный лог WordPress
        error_log("MokaPOS [{$level}]: {$message}");
    }
    
    /**
     * Очистка старых логов (старше 30 дней)
     */
    public static function cleanup_old_logs($days = 30) {
        if (!file_exists(MOKAPOS_LOG_DIR)) {
            return;
        }
        
        $files = glob(MOKAPOS_LOG_DIR . '*.log');
        $cutoff = strtotime("-{$days} days");
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                wp_delete_file($file);
            }
        }
    }
}
