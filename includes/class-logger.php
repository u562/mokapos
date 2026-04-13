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
        // Всегда логируем в debug режиме, независимо от WP_DEBUG
        self::log('DEBUG', $message);
    }
    
    /**
     * Основная функция логирования
     */
    private static function log($level, $message) {
        $timestamp = current_time('mysql');
        $log_entry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        // Проверяем наличие константы и определяем fallback если нужно
        if (!defined('MOKAPOS_LOG_DIR')) {
            $log_dir = WP_CONTENT_DIR . '/uploads/mokapos-logs/';
        } else {
            $log_dir = MOKAPOS_LOG_DIR;
        }
        
        // Логируем в файл
        $log_file = $log_dir . 'mokapos-' . date('Y-m-d') . '.log';
        
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        file_put_contents($log_file, $log_entry, FILE_APPEND);
        
        // Также логируем в стандартный лог WordPress
        error_log("MokaPOS [{$level}]: {$message}");
        
        // Если включен WP_DEBUG_LOG, пишем в debug.log
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log("MokaPOS [{$level}]: {$message}", 3, WP_CONTENT_DIR . '/debug.log');
        }
    }
    
    /**
     * Очистка старых логов (старше 30 дней)
     */
    public static function cleanup_old_logs($days = 30) {
        // Проверяем наличие константы и определяем fallback если нужно
        if (!defined('MOKAPOS_LOG_DIR')) {
            $log_dir = WP_CONTENT_DIR . '/uploads/mokapos-logs/';
        } else {
            $log_dir = MOKAPOS_LOG_DIR;
        }
        
        if (!file_exists($log_dir)) {
            return;
        }
        
        $files = glob($log_dir . '*.log');
        $cutoff = strtotime("-{$days} days");
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                wp_delete_file($file);
            }
        }
    }
}
