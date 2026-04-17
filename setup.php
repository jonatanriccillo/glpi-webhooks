<?php
/**
 * Webhooks plugin for GLPI 11.
 *
 * Generic webhook registry: each webhook entry has a name, a target URL and
 * a set of itemtypes it reacts to. Features register themselves against this
 * registry. The first shipped feature is an expiration-check cron that POSTs
 * a JSON payload when Contracts, SoftwareLicenses, Certificates, Domains or
 * Infocom warranties are approaching expiration; more features will be added
 * later against the same plugin surface.
 *
 * Licensed under GPLv3.
 */

define('PLUGIN_WEBHOOKS_VERSION', '1.0.0');
define('PLUGIN_WEBHOOKS_MIN_GLPI', '11.0.0');
define('PLUGIN_WEBHOOKS_MAX_GLPI', '11.9.99');

function plugin_init_webhooks(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['webhooks']  = true;
    $PLUGIN_HOOKS['change_profile']['webhooks']  = ['PluginWebhooksProfile', 'initProfile'];

    Plugin::registerClass('PluginWebhooksProfile', ['addtabon' => 'Profile']);

    if (!Session::getLoginUserID()) {
        return;
    }

    if (Session::haveRight('plugin_webhooks', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['webhooks'] = ['config' => 'PluginWebhooksMenu'];
    }
}

function plugin_version_webhooks(): array
{
    return [
        'name'         => 'Webhooks',
        'version'      => PLUGIN_WEBHOOKS_VERSION,
        'author'       => 'Jonatan Riccillo',
        'license'      => 'GPLv3',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_WEBHOOKS_MIN_GLPI,
                'max' => PLUGIN_WEBHOOKS_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_webhooks_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_WEBHOOKS_MIN_GLPI, 'lt')) {
        if (method_exists('Plugin', 'messageIncompatible')) {
            echo Plugin::messageIncompatible('core', PLUGIN_WEBHOOKS_MIN_GLPI);
        }
        return false;
    }
    return true;
}

function plugin_webhooks_check_config($verbose = false): bool
{
    return true;
}
