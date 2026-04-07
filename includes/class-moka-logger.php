<?php
namespace MokaPOS;

if (!defined('ABSPATH')) exit;

class Logger {
    
    private static function get_log_file() {
        return MOKAPOS_LOG_DIR . 'mokapos-' . date('Y-m-d') . '.log';
    }
    
    private static function format_message($level, $message, $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        $prefix = strtoupper($level);
        
        $log_line = "[$timestamp] [$prefix] $message";
        
        if (!empty($context)) {
            $log_line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        
        return $log_line . PHP_EOL;
    }
    
    public static function log($level, $message, $context = []) {
        if (!wp_is_writable(MOKAPOS_LOG_DIR)) {
            error_log("[MokaPOS] Log directory not writable: " . MOKAPOS_LOG_DIR);
            return;
        }
        
        $log_entry = self::format_message($level, $message, $context);
        file_put_contents(self::get_log_file(), $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    public static function info($message, $context = []) {
        self::log('INFO', $message, $context);
    }
    
    public static function warning($message, $context = []) {
        self::log('WARNING', $message, $context);
    }
    
    public static function error($message, $context = []) {
        self::log('ERROR', $message, $context);
        // Также записываем в системный лог для критических ошибок
        error_log("[MokaPOS ERROR] $message " . json_encode($context));
    }
    
    /**
     * Получение последних записей лога для админки
     */
    public static function get_recent_logs($lines = 100) {
        $log_file = self::get_log_file();
        if (!file_exists($log_file)) {
            return [];
        }
        
        $content = file_get_contents($log_file);
        $all_lines = explode("\n", trim($content));
        
        return array_slice(array_filter($all_lines), -$lines, $lines, true);
    }
    
    /**
     * Очистка старых логов (старше 7 дней)
     */
    public static function cleanup_old_logs($days = 7) {
        $files = glob(MOKAPOS_LOG_DIR . 'mokapos-*.log');
        $cutoff = strtotime("-$days days");
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}