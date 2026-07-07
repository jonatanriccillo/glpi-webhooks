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
    // Grant to Super-Admin always (handled inside createFirstAccess) and, when
    // installed from a logged-in session, to the installer's own profile too.
    // No session gate: a CLI install (bin/console) has no session, and gating on
    // it left every profile — including Super-Admin — without the right.
    PluginWebhooksProfile::createFirstAccess((int) ($_SESSION['glpiactiveprofile']['id'] ?? 0));

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
    // Ticket-events feature: a webhook is either 'expiration' (cron) or 'ticket'
    // (lifecycle hooks). Existing rows default to 'expiration' so nothing breaks.
    if (!$DB->fieldExists('glpi_plugin_webhooks_webhooks', 'trigger_type')) {
        $migration->addField(
            'glpi_plugin_webhooks_webhooks',
            'trigger_type',
            "VARCHAR(20) NOT NULL DEFAULT 'expiration'",
            ['after' => 'headers']
        );
    }
    if (!$DB->fieldExists('glpi_plugin_webhooks_webhooks', 'ticket_events')) {
        $migration->addField('glpi_plugin_webhooks_webhooks', 'ticket_events', 'text', ['after' => 'anticipation_days']);
    }
    // Filter for ticket_events: an embedded GLPI criteria builder, not a
    // reference to a saved search (superseded during 1.1.0 development).
    if ($DB->fieldExists('glpi_plugin_webhooks_webhooks', 'savedsearches_id')) {
        $migration->dropField('glpi_plugin_webhooks_webhooks', 'savedsearches_id');
    }
    if (!$DB->fieldExists('glpi_plugin_webhooks_webhooks', 'ticket_criteria')) {
        $migration->addField('glpi_plugin_webhooks_webhooks', 'ticket_criteria', 'text', ['after' => 'ticket_events']);
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

    // Ticket-events log table. The SQL uses CREATE TABLE IF NOT EXISTS, so
    // re-running the install file only creates what is missing.
    if (!$DB->tableExists('glpi_plugin_webhooks_events')) {
        $DB->runFile(Plugin::getPhpDir('webhooks') . '/sql/empty-1.0.0.sql');
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
            'glpi_plugin_webhooks_events',
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
