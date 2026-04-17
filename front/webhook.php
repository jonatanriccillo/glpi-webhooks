<?php
include('../../../inc/includes.php');

Session::checkRight('plugin_webhooks', READ);

Html::header(
    PluginWebhooksWebhook::getTypeName(2),
    $_SERVER['PHP_SELF'],
    'config',
    'PluginWebhooksMenu'
);

Search::show('PluginWebhooksWebhook');

Html::footer();
