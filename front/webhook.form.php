<?php
include('../../../inc/includes.php');

Session::checkRight('plugin_webhooks', READ);

$webhook = new PluginWebhooksWebhook();

if (isset($_POST['add'])) {
    $webhook->check(-1, CREATE, $_POST);
    $webhook->add($_POST);
    Html::back();

} elseif (isset($_POST['update'])) {
    $webhook->check($_POST['id'], UPDATE);
    $webhook->update($_POST);
    Html::back();

} elseif (isset($_POST['delete'])) {
    $webhook->check($_POST['id'], DELETE);
    $webhook->delete($_POST);
    $webhook->redirectToList();

} elseif (isset($_POST['purge'])) {
    $webhook->check($_POST['id'], PURGE);
    $webhook->delete($_POST, true);
    $webhook->redirectToList();

} elseif (isset($_POST['restore'])) {
    $webhook->check($_POST['id'], PURGE);
    $webhook->restore($_POST);
    Html::back();

} elseif (isset($_POST['save_template'])) {
    $webhook->check((int) $_POST['id'], UPDATE);
    $update = [
        'id'               => (int) $_POST['id'],
        'payload_template' => (string) ($_POST['payload_template'] ?? ''),
    ];
    if ($webhook->update($update)) {
        Session::addMessageAfterRedirect(__('Template guardado.', 'webhooks'));
    }
    Html::back();

} elseif (isset($_POST['reset_template'])) {
    $webhook->check((int) $_POST['id'], UPDATE);
    $webhook->update([
        'id'               => (int) $_POST['id'],
        'payload_template' => PluginWebhooksSender::DEFAULT_TEMPLATE,
    ]);
    Session::addMessageAfterRedirect(__('Template por defecto restaurado.', 'webhooks'));
    Html::back();

} elseif (isset($_POST['run_scheduler'])) {
    Session::checkRight('plugin_webhooks', UPDATE);
    $stats = PluginWebhooksCrontask::runNowForAllWebhooks();
    $msg = sprintf(
        __('Scheduler ejecutado sobre %d webhook(s) activo(s): %d enviados, %d fallidos, %d omitidos.', 'webhooks'),
        $stats['webhooks'],
        $stats['sent'],
        $stats['failed'],
        $stats['skipped']
    );
    if ($stats['failed'] === 0) {
        Session::addMessageAfterRedirect($msg, false, INFO);
    } else {
        Session::addMessageAfterRedirect($msg, false, ERROR);
    }
    Html::back();

} elseif (isset($_POST['send_pending'])) {
    $id = (int) $_POST['id'];
    $webhook->check($id, UPDATE);
    $webhook->getFromDB($id);

    $stats = PluginWebhooksCrontask::sendPendingForWebhook($webhook);

    $msg = sprintf(
        __('Despachados: %d enviados, %d fallidos, %d omitidos.', 'webhooks'),
        $stats['sent'],
        $stats['failed'],
        $stats['skipped']
    );

    if ($stats['sent'] > 0 && $stats['failed'] === 0) {
        Session::addMessageAfterRedirect($msg, false, INFO);
    } elseif ($stats['failed'] > 0) {
        Session::addMessageAfterRedirect($msg, false, ERROR);
    } else {
        Session::addMessageAfterRedirect($msg, false, WARNING);
    }
    Html::back();

} elseif (isset($_POST['test_send'])) {
    $id = (int) $_POST['id'];
    $webhook->check($id, UPDATE);
    $webhook->getFromDB($id);

    $template = (string) ($webhook->fields['payload_template'] ?? '');
    if (trim($template) === '') {
        $template = PluginWebhooksSender::DEFAULT_TEMPLATE;
    }

    $ctx = PluginWebhooksSender::buildTestContext($webhook);
    [$payload, $err] = PluginWebhooksSender::render($template, $ctx);

    if ($payload === null) {
        Session::addMessageAfterRedirect(
            __('Error del template: ', 'webhooks') . $err,
            false,
            ERROR
        );
        PluginWebhooksSender::recordResult($id, [
            'ok'       => false,
            'status'   => 0,
            'response' => '',
            'error'    => $err,
        ], true);
    } else {
        $headers = PluginWebhooksSender::parseHeaders($webhook->fields['headers'] ?? null);
        $method  = (string) ($webhook->fields['http_method'] ?? 'POST');
        $result  = PluginWebhooksSender::send((string) $webhook->fields['url'], $payload, $method, $headers);
        PluginWebhooksSender::recordResult($id, $result, true);

        if ($result['ok']) {
            Session::addMessageAfterRedirect(
                sprintf(__('Prueba enviada OK (HTTP %d).', 'webhooks'), $result['status']),
                false,
                INFO
            );
        } else {
            Session::addMessageAfterRedirect(
                sprintf(
                    __('Prueba fallida (HTTP %d): %s', 'webhooks'),
                    $result['status'],
                    $result['error'] ?? ''
                ),
                false,
                ERROR
            );
        }
    }
    Html::back();

} else {
    Html::header(
        PluginWebhooksWebhook::getTypeName(2),
        $_SERVER['PHP_SELF'],
        'config',
        'PluginWebhooksMenu'
    );

    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    $webhook->display(['id' => $id]);

    Html::footer();
}
