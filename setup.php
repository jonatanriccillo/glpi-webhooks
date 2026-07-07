<?php
/**
 * Webhooks plugin for GLPI 11.
 *
 * Generic webhook registry: each webhook entry has a name, a target URL and a
 * trigger type. Features register themselves against this registry. Two feature
 * families ship today, selected per webhook via `trigger_type`:
 *   - 'expiration': a cron that POSTs when Contracts, SoftwareLicenses,
 *     Certificates, Domains or Infocom warranties approach expiration.
 *   - 'ticket': lifecycle hooks that POST on Ticket events mirroring GLPI's
 *     own notification event catalog (new/update/solved/closed/assign/
 *     followup/task/validation/satisfaction/…), filtered by an embedded
 *     native GLPI search-criteria builder.
 *
 * Licensed under GPLv3.
 */

define('PLUGIN_WEBHOOKS_VERSION', '1.1.0');
define('PLUGIN_WEBHOOKS_MIN_GLPI', '11.0.0');
define('PLUGIN_WEBHOOKS_MAX_GLPI', '11.9.99');

function plugin_init_webhooks(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['webhooks']  = true;
    $PLUGIN_HOOKS['change_profile']['webhooks']  = ['PluginWebhooksProfile', 'initProfile'];

    // Ticket-events feature. Registered unconditionally (no login guard) so
    // tickets created by the mail collector, the API or a cron also fire.
    // Event catalog mirrors GLPI's own NotificationTargetTicket events — see
    // inc/ticketevent.class.php docblock.
    $PLUGIN_HOOKS['item_add']['webhooks'] = [
        'Ticket'            => ['PluginWebhooksTicketEvent', 'onTicketAdd'],
        'ITILFollowup'      => ['PluginWebhooksTicketEvent', 'onFollowupAdd'],
        'TicketTask'        => ['PluginWebhooksTicketEvent', 'onTaskAdd'],
        'Ticket_User'       => ['PluginWebhooksTicketEvent', 'onActorAdd'],
        'Group_Ticket'      => ['PluginWebhooksTicketEvent', 'onGroupActorAdd'],
        'Supplier_Ticket'   => ['PluginWebhooksTicketEvent', 'onSupplierActorAdd'],
        'Document_Item'     => ['PluginWebhooksTicketEvent', 'onDocumentAdd'],
        'TicketValidation'  => ['PluginWebhooksTicketEvent', 'onValidationAdd'],
        'TicketSatisfaction' => ['PluginWebhooksTicketEvent', 'onSatisfactionAdd'],
        'PendingReason_Item' => ['PluginWebhooksTicketEvent', 'onPendingReasonAdd'],
    ];
    $PLUGIN_HOOKS['item_update']['webhooks'] = [
        'Ticket'             => ['PluginWebhooksTicketEvent', 'onTicketUpdate'],
        'ITILFollowup'       => ['PluginWebhooksTicketEvent', 'onFollowupUpdate'],
        'TicketTask'         => ['PluginWebhooksTicketEvent', 'onTaskUpdate'],
        'ITILSolution'       => ['PluginWebhooksTicketEvent', 'onSolutionUpdate'],
        'TicketValidation'   => ['PluginWebhooksTicketEvent', 'onValidationUpdate'],
        'TicketSatisfaction' => ['PluginWebhooksTicketEvent', 'onSatisfactionUpdate'],
    ];
    $PLUGIN_HOOKS['pre_item_delete']['webhooks'] = [
        'Ticket' => ['PluginWebhooksTicketEvent', 'onTicketPreDelete'],
    ];
    $PLUGIN_HOOKS['item_purge']['webhooks'] = [
        'ITILFollowup'       => ['PluginWebhooksTicketEvent', 'onFollowupDelete'],
        'TicketTask'         => ['PluginWebhooksTicketEvent', 'onTaskDelete'],
        'PendingReason_Item' => ['PluginWebhooksTicketEvent', 'onPendingReasonDelete'],
    ];

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
