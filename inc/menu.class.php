<?php
/**
 * Sidebar menu entry under Setup.
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can\'t access this file directly');
}

class PluginWebhooksMenu extends CommonGLPI
{
    public static $rightname = 'plugin_webhooks';

    public static function getTypeName($nb = 0): string
    {
        return 'Webhooks';
    }

    public static function getMenuName(): string
    {
        return self::getTypeName();
    }

    public static function getMenuContent()
    {
        $front = '/plugins/webhooks/front';

        $menu = [
            'title'   => self::getMenuName(),
            'page'    => $front . '/webhook.php',
            'icon'    => 'ti ti-bell',
            'links'   => [
                'search' => $front . '/webhook.php',
                'add'    => $front . '/webhook.form.php',
            ],
        ];

        return $menu;
    }

    public static function getIcon(): string
    {
        return 'ti ti-bell';
    }
}
