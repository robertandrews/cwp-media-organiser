<?php

class WP_Media_Organiser_Activator
{
    public static function activate()
    {
        global $wpdb;
        $logger = WP_Media_Organiser_Logger::get_instance();
        $logger->log('Plugin activation started', 'info');

        $table_name = $wpdb->prefix . 'media_organiser_settings';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            setting_name varchar(255) NOT NULL,
            setting_value longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY setting_name (setting_name)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Set default settings
        $default_settings = array(
            'use_post_type' => '1',
            'taxonomy_name' => '',
            'post_identifier' => 'slug',
            'log_levels' => 'ERROR,WARNING',
        );

        foreach ($default_settings as $name => $value) {
            $wpdb->replace(
                $table_name,
                array(
                    'setting_name' => $name,
                    'setting_value' => $value,
                ),
                array('%s', '%s')
            );
            $logger->log("Default setting created: $name = $value", 'info');
        }

        $logger->log('Plugin activation completed', 'info');
    }
}
