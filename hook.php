<?php
/**
 * Install / uninstall hooks for the Webhooks plugin.
 */

function plugin_webhooks_install(): bool
{
    global $DB;

    if (!$DB->tableExists('glpi_plugin_webhooks_webhooks')) {
        $DB->runFile(Plugin::getPhpDir('webhooks') . '/sql/empty-1.0.0.sql');
    } else {
        plugin_webhooks_migrate($DB);
    }

    PluginWebhooksProfile::initProfile();
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        PluginWebhooksProfile::createFirstAccess((int) $_SESSION['glpiactiveprofile']['id']);
    }

    CronTask::register(
        'PluginWebhooksCrontask',
        'expirationcheck',
        DAY_TIMESTAMP,
        [
            'state' => CronTask::STATE_WAITING,
            'mode'  => CronTask::MODE_EXTERNAL,
        ]
    );

    return true;
}

/**
 * Brings existing installations up to date without requiring uninstall.
 */
function plugin_webhooks_migrate($DB): void
{
    $migration = new Migration(PLUGIN_WEBHOOKS_VERSION);

    if (!$DB->fieldExists('glpi_plugin_webhooks_webhooks', 'http_method')) {
        $migration->addField(
            'glpi_plugin_webhooks_webhooks',
            'http_method',
            "VARCHAR(10) NOT NULL DEFAULT 'POST'",
            ['after' => 'url']
        );
    }
    if (!$DB->fieldExists('glpi_plugin_webhooks_webhooks', 'headers')) {
        $migration->addField('glpi_plugin_webhooks_webhooks', 'headers', 'text', ['after' => 'http_method']);
    }
    if (!$DB->fieldExists('glpi_plugin_webhooks_webhooks', 'anticipation_days')) {
        $migration->addField(
            'glpi_plugin_webhooks_webhooks',
            'anticipation_days',
            "INT NOT NULL DEFAULT 30",
            ['after' => 'itemtypes']
        );
    }
    if (!$DB->fieldExists('glpi_plugin_webhooks_webhooks', 'payload_template')) {
        $migration->addField('glpi_plugin_webhooks_webhooks', 'payload_template', 'longtext', ['after' => 'anticipation_days']);
    }
    foreach (
        [
            'last_sent_date'     => "TIMESTAMP NULL DEFAULT NULL",
            'last_http_status'   => "INT DEFAULT NULL",
            'last_error'         => 'text',
            'last_test_date'     => "TIMESTAMP NULL DEFAULT NULL",
            'last_test_status'   => "INT DEFAULT NULL",
            'last_test_response' => 'text',
        ] as $col => $type
    ) {
        if (!$DB->fieldExists('glpi_plugin_webhooks_webhooks', $col)) {
            $migration->addField('glpi_plugin_webhooks_webhooks', $col, $type);
        }
    }

    if ($DB->tableExists('glpi_plugin_webhooks_sent')) {
        foreach (
            [
                'http_status'      => "INT DEFAULT NULL",
                'response_excerpt' => 'text',
                'error'            => 'text',
            ] as $col => $type
        ) {
            if (!$DB->fieldExists('glpi_plugin_webhooks_sent', $col)) {
                $migration->addField('glpi_plugin_webhooks_sent', $col, $type);
            }
        }
    }

    $migration->executeMigration();
}

function plugin_webhooks_uninstall(): bool
{
    global $DB;

    CronTask::unregister('webhooks');

    foreach (
        [
            'glpi_plugin_webhooks_webhooks',
            'glpi_plugin_webhooks_sent',
            'glpi_plugin_webhooks_profiles',
        ] as $table
    ) {
        if ($DB->tableExists($table)) {
            $DB->dropTable($table);
        }
    }

    foreach (
        [
            'glpi_displaypreferences',
            'glpi_logs',
        ] as $glpi_table
    ) {
        $DB->delete($glpi_table, ['itemtype' => ['LIKE', 'PluginWebhooks%']]);
    }

    return true;
}
