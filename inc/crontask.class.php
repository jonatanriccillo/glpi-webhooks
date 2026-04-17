<?php
/**
 * Expiration detection + dispatch pipeline.
 *
 * Entry points:
 *   - cronExpirationcheck: scheduled run (all active webhooks).
 *   - sendPendingForWebhook: manual "send now" for a single webhook.
 *   - collectUpcomingForWebhook: returns annotated list for the Upcoming tab.
 *
 * Scanning is independent of GLPI's per-entity alert flags. Each webhook
 * filters by its own anticipation_days. Dedupe table guarantees a single
 * dispatch per (webhook, itemtype, items_id, expiration_date).
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can\'t access this file directly');
}

class PluginWebhooksCrontask extends CommonDBTM
{
    public static function getTypeName($nb = 0): string
    {
        return 'Webhooks';
    }

    public static function cronInfo($name): array
    {
        if ($name === 'expirationcheck') {
            return ['description' => __('Chequear vencimientos y enviar webhooks', 'webhooks')];
        }
        return [];
    }

    // -----------------------------------------------------------------------
    // Scheduled entry point
    // -----------------------------------------------------------------------

    public static function cronExpirationcheck(CronTask $task): int
    {
        $processed = 0;
        foreach (self::supportedItemtypes() as $itemtype) {
            foreach (self::collectByItemtype($itemtype) as $row) {
                $webhooks = PluginWebhooksWebhook::findActiveForItemtype($itemtype, (int) ($row['entities_id'] ?? 0));
                foreach ($webhooks as $webhook) {
                    $outcome = self::dispatchOne($webhook, $itemtype, $row, false);
                    if ($outcome === 'sent') {
                        $processed++;
                        $task->log(sprintf('Sent %s #%d to "%s"', $itemtype, (int) $row['id'], $webhook['name']));
                    } elseif ($outcome === 'failed') {
                        $task->log(sprintf('Failed %s #%d to "%s"', $itemtype, (int) $row['id'], $webhook['name']));
                    }
                }
            }
        }

        $task->addVolume($processed);
        return $processed > 0 ? 1 : 0;
    }

    /**
     * Manual run for every active webhook. Updates the cron's lastrun so the
     * "Scheduler" card reflects the execution.
     */
    public static function runNowForAllWebhooks(): array
    {
        global $DB;

        $aggregate = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'webhooks' => 0];

        $iter = $DB->request([
            'FROM'  => PluginWebhooksWebhook::getTable(),
            'WHERE' => ['is_active' => 1],
        ]);

        foreach ($iter as $row) {
            $webhook = new PluginWebhooksWebhook();
            if (!$webhook->getFromDB((int) $row['id'])) {
                continue;
            }
            $aggregate['webhooks']++;
            $stats = self::sendPendingForWebhook($webhook);
            foreach (['sent', 'failed', 'skipped'] as $k) {
                $aggregate[$k] += $stats[$k];
            }
        }

        $task = new CronTask();
        if ($task->getFromDBbyName('PluginWebhooksCrontask', 'expirationcheck')) {
            $DB->update(
                'glpi_crontasks',
                ['lastrun' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')],
                ['id' => (int) $task->fields['id']]
            );
        }

        return $aggregate;
    }

    // -----------------------------------------------------------------------
    // Manual entry point (button in the Upcoming tab)
    // -----------------------------------------------------------------------

    public static function sendPendingForWebhook(PluginWebhooksWebhook $webhook): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $webhook_row = $webhook->fields;

        foreach (self::upcomingRowsForWebhook($webhook) as $entry) {
            if (!$entry['is_actionable']) {
                $stats['skipped']++;
                continue;
            }

            $outcome = self::dispatchOne($webhook_row, $entry['itemtype'], $entry['row'], true);
            if ($outcome === 'sent') {
                $stats['sent']++;
            } elseif ($outcome === 'failed') {
                $stats['failed']++;
            } else {
                $stats['skipped']++;
            }
        }
        return $stats;
    }

    /**
     * Returns upcoming items for the given webhook, annotated with
     * `already_sent` so the Upcoming tab can render the status column.
     */
    public static function collectUpcomingForWebhook(PluginWebhooksWebhook $webhook): array
    {
        return self::upcomingRowsForWebhook($webhook);
    }

    // -----------------------------------------------------------------------
    // Shared pipeline
    // -----------------------------------------------------------------------

    /**
     * @param array $webhook Raw row from glpi_plugin_webhooks_webhooks.
     * @return 'sent'|'failed'|'skipped'
     */
    private static function dispatchOne(array $webhook, string $itemtype, array $row, bool $bypass_entity_check): string
    {
        $webhook_id  = (int) $webhook['id'];
        $entities_id = (int) ($row['entities_id'] ?? 0);

        if (!$bypass_entity_check
            && !self::entityMatches((int) $webhook['entities_id'], (int) $webhook['is_recursive'], $entities_id)
        ) {
            return 'skipped';
        }

        $anticipation = (int) ($webhook['anticipation_days'] ?? 30);
        if ($anticipation <= 0) {
            $anticipation = 30;
        }
        $days_left = (int) $row['days_left'];
        // No lower bound: already-expired items get alerted once too.
        if ($days_left > $anticipation) {
            return 'skipped';
        }

        if (self::alreadySent($webhook_id, $itemtype, (int) $row['id'], $row['expiration_date'])) {
            return 'skipped';
        }

        $ctx      = self::buildContext($itemtype, $row);
        $template = trim((string) ($webhook['payload_template'] ?? ''));
        if ($template === '') {
            $template = PluginWebhooksSender::DEFAULT_TEMPLATE;
        }

        [$payload, $err] = PluginWebhooksSender::render($template, $ctx);
        if ($payload === null) {
            self::recordSent($webhook_id, $itemtype, (int) $row['id'], $row['expiration_date'], 0, '', (string) $err);
            return 'failed';
        }

        $headers = PluginWebhooksSender::parseHeaders($webhook['headers'] ?? null);
        $method  = (string) ($webhook['http_method'] ?? 'POST');
        $result  = PluginWebhooksSender::send((string) $webhook['url'], $payload, $method, $headers);
        PluginWebhooksSender::recordResult($webhook_id, $result, false);

        self::recordSent(
            $webhook_id, $itemtype, (int) $row['id'], $row['expiration_date'],
            (int) $result['status'], (string) $result['response'],
            $result['error'] !== null ? (string) $result['error'] : ($result['ok'] ? '' : 'HTTP ' . $result['status'])
        );

        return $result['ok'] ? 'sent' : 'failed';
    }

    /**
     * Returns EVERY item in the webhook's scope (itemtypes + entity), sorted
     * urgency-first. Each row has a `status` field:
     *
     *   - 'overdue':  days_left < 0, not yet notified → pending action.
     *   - 'pending':  0 <= days_left <= anticipation, not yet notified → pending action.
     *   - 'sent':     already notified for this (item, expiration_date).
     *   - 'future':   days_left > anticipation, nothing to do yet.
     */
    private static function upcomingRowsForWebhook(PluginWebhooksWebhook $webhook): array
    {
        $anticipation = (int) ($webhook->fields['anticipation_days'] ?? 30);
        if ($anticipation <= 0) {
            $anticipation = 30;
        }
        $webhook_id = (int) $webhook->fields['id'];
        $entity_wh  = (int) $webhook->fields['entities_id'];
        $recursive  = (int) $webhook->fields['is_recursive'];

        $out = [];
        foreach ($webhook->getSelectedItemtypes() as $itemtype) {
            foreach (self::collectByItemtype($itemtype) as $row) {
                if (!self::entityMatches($entity_wh, $recursive, (int) ($row['entities_id'] ?? 0))) {
                    continue;
                }

                $days_left    = (int) $row['days_left'];
                $last_attempt = self::getLastAttempt(
                    $webhook_id, $itemtype, (int) $row['id'], (string) $row['expiration_date']
                );
                $last_status  = $last_attempt !== null ? (int) ($last_attempt['http_status'] ?? 0) : null;
                $already_sent = $last_status !== null && $last_status >= 200 && $last_status < 300;
                $last_failed  = $last_attempt !== null && !$already_sent;

                if ($already_sent) {
                    $status = 'sent';
                } elseif ($days_left < 0) {
                    $status = 'overdue';
                } elseif ($days_left <= $anticipation) {
                    $status = 'pending';
                } else {
                    $status = 'future';
                }

                $out[] = [
                    'itemtype'     => $itemtype,
                    'row'          => $row,
                    'status'       => $status,
                    'already_sent' => $already_sent,
                    'last_attempt' => $last_attempt,
                    'last_failed'  => $last_failed,
                    'is_actionable'=> in_array($status, ['overdue', 'pending'], true),
                ];
            }
        }

        // Urgency order: overdue → pending → sent → future. Within each,
        // sort by days_left ascending (most urgent first).
        $rank = ['overdue' => 0, 'pending' => 1, 'sent' => 2, 'future' => 3];
        usort($out, function ($a, $b) use ($rank) {
            $ra = $rank[$a['status']];
            $rb = $rank[$b['status']];
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return (int) $a['row']['days_left'] <=> (int) $b['row']['days_left'];
        });

        return $out;
    }

    // -----------------------------------------------------------------------
    // Collectors dispatcher
    // -----------------------------------------------------------------------

    public static function supportedItemtypes(): array
    {
        return ['Contract', 'SoftwareLicense', 'Certificate', 'Domain', 'Infocom'];
    }

    public static function collectByItemtype(string $itemtype): array
    {
        switch ($itemtype) {
            case 'Contract':        return self::collectContracts();
            case 'SoftwareLicense': return self::collectLicenses();
            case 'Certificate':     return self::collectCertificates();
            case 'Domain':          return self::collectDomains();
            case 'Infocom':         return self::collectWarranties();
            default:                return [];
        }
    }

    // -----------------------------------------------------------------------
    // Per-itemtype collectors (no entity-alert filtering — we return
    // everything, each webhook decides via its own anticipation).
    // -----------------------------------------------------------------------

    private static function collectContracts(): array
    {
        global $DB;
        $out = [];

        $iter = $DB->request([
            'SELECT' => [
                'glpi_contracts.id',
                'glpi_contracts.name',
                'glpi_contracts.entities_id',
                'glpi_contracts.begin_date',
                'glpi_contracts.duration',
            ],
            'FROM'  => 'glpi_contracts',
            'WHERE' => [
                'glpi_contracts.is_deleted'  => 0,
                'glpi_contracts.is_template' => 0,
                ['NOT' => ['glpi_contracts.begin_date' => null]],
                ['glpi_contracts.duration' => ['>', 0]],
            ],
        ]);

        foreach ($iter as $row) {
            $expires_ts = strtotime("+{$row['duration']} month", strtotime((string) $row['begin_date']));
            if ($expires_ts === false) {
                continue;
            }
            $out[] = [
                'id'              => (int) $row['id'],
                'name'            => (string) $row['name'],
                'entities_id'     => (int) $row['entities_id'],
                'expiration_date' => date('Y-m-d', $expires_ts),
                'days_left'       => self::daysLeft($expires_ts),
            ];
        }
        return $out;
    }

    private static function collectLicenses(): array
    {
        global $DB;
        $out = [];

        $iter = $DB->request([
            'SELECT' => [
                'glpi_softwarelicenses.id',
                'glpi_softwarelicenses.name',
                'glpi_softwarelicenses.entities_id',
                'glpi_softwarelicenses.expire',
            ],
            'FROM'  => 'glpi_softwarelicenses',
            'WHERE' => [
                'glpi_softwarelicenses.is_deleted'  => 0,
                'glpi_softwarelicenses.is_template' => 0,
                ['NOT' => ['glpi_softwarelicenses.expire' => null]],
            ],
        ]);

        foreach ($iter as $row) {
            $expires_ts = strtotime((string) $row['expire']);
            if ($expires_ts === false) {
                continue;
            }
            $out[] = [
                'id'              => (int) $row['id'],
                'name'            => (string) $row['name'],
                'entities_id'     => (int) $row['entities_id'],
                'expiration_date' => date('Y-m-d', $expires_ts),
                'days_left'       => self::daysLeft($expires_ts),
            ];
        }
        return $out;
    }

    private static function collectCertificates(): array
    {
        global $DB;
        $out = [];

        $iter = $DB->request([
            'SELECT' => [
                'glpi_certificates.id',
                'glpi_certificates.name',
                'glpi_certificates.entities_id',
                'glpi_certificates.date_expiration',
            ],
            'FROM'  => 'glpi_certificates',
            'WHERE' => [
                'glpi_certificates.is_deleted' => 0,
                ['NOT' => ['glpi_certificates.date_expiration' => null]],
            ],
        ]);

        foreach ($iter as $row) {
            $expires_ts = strtotime((string) $row['date_expiration']);
            if ($expires_ts === false) {
                continue;
            }
            $out[] = [
                'id'              => (int) $row['id'],
                'name'            => (string) $row['name'],
                'entities_id'     => (int) $row['entities_id'],
                'expiration_date' => date('Y-m-d', $expires_ts),
                'days_left'       => self::daysLeft($expires_ts),
            ];
        }
        return $out;
    }

    private static function collectDomains(): array
    {
        global $DB;
        $out = [];
        if (!$DB->tableExists('glpi_domains')) {
            return $out;
        }

        $iter = $DB->request([
            'SELECT' => [
                'glpi_domains.id',
                'glpi_domains.name',
                'glpi_domains.entities_id',
                'glpi_domains.date_expiration',
            ],
            'FROM'  => 'glpi_domains',
            'WHERE' => [
                'glpi_domains.is_deleted' => 0,
                ['NOT' => ['glpi_domains.date_expiration' => null]],
            ],
        ]);

        foreach ($iter as $row) {
            $expires_ts = strtotime((string) $row['date_expiration']);
            if ($expires_ts === false) {
                continue;
            }
            $out[] = [
                'id'              => (int) $row['id'],
                'name'            => (string) $row['name'],
                'entities_id'     => (int) $row['entities_id'],
                'expiration_date' => date('Y-m-d', $expires_ts),
                'days_left'       => self::daysLeft($expires_ts),
            ];
        }
        return $out;
    }

    private static function collectWarranties(): array
    {
        global $DB;
        $out = [];

        $iter = $DB->request([
            'SELECT' => [
                'glpi_infocoms.id',
                'glpi_infocoms.itemtype',
                'glpi_infocoms.items_id',
                'glpi_infocoms.entities_id',
                'glpi_infocoms.warranty_date',
                'glpi_infocoms.warranty_duration',
            ],
            'FROM'  => 'glpi_infocoms',
            'WHERE' => [
                ['NOT' => ['glpi_infocoms.warranty_date' => null]],
                ['glpi_infocoms.warranty_duration' => ['>', 0]],
            ],
        ]);

        foreach ($iter as $row) {
            $expires_ts = strtotime("+{$row['warranty_duration']} month", strtotime((string) $row['warranty_date']));
            if ($expires_ts === false) {
                continue;
            }
            $out[] = [
                'id'              => (int) $row['items_id'],
                'itemtype'        => (string) $row['itemtype'],
                'name'            => self::resolveInfocomName((string) $row['itemtype'], (int) $row['items_id']),
                'entities_id'     => (int) $row['entities_id'],
                'expiration_date' => date('Y-m-d', $expires_ts),
                'days_left'       => self::daysLeft($expires_ts),
            ];
        }
        return $out;
    }

    private static function resolveInfocomName(string $itemtype, int $items_id): string
    {
        if (!class_exists($itemtype)) {
            return "{$itemtype} #{$items_id}";
        }
        $item = new $itemtype();
        if ($item->getFromDB($items_id)) {
            return (string) ($item->fields['name'] ?? "{$itemtype} #{$items_id}");
        }
        return "{$itemtype} #{$items_id}";
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    private static function daysLeft(int $expires_ts): int
    {
        return (int) floor(($expires_ts - strtotime('today')) / 86400);
    }

    private static function entityMatches(int $webhook_entity, int $is_recursive, int $target_entity): bool
    {
        if ($webhook_entity === $target_entity) {
            return true;
        }
        if (!$is_recursive) {
            return false;
        }
        $ancestors = getAncestorsOf('glpi_entities', $target_entity);
        return in_array($webhook_entity, array_map('intval', $ancestors), true);
    }

    /**
     * True only if there is a successful (2xx) attempt. Failed attempts do
     * NOT block retries.
     */
    private static function alreadySent(int $webhooks_id, string $itemtype, int $items_id, string $expiration_date): bool
    {
        $row = self::getLastAttempt($webhooks_id, $itemtype, $items_id, $expiration_date);
        if ($row === null) {
            return false;
        }
        $status = (int) ($row['http_status'] ?? 0);
        return $status >= 200 && $status < 300;
    }

    public static function getLastAttempt(int $webhooks_id, string $itemtype, int $items_id, string $expiration_date): ?array
    {
        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_webhooks_sent',
            'WHERE' => [
                'webhooks_id'     => $webhooks_id,
                'itemtype'        => $itemtype,
                'items_id'        => $items_id,
                'expiration_date' => $expiration_date,
            ],
            'LIMIT' => 1,
        ]);

        foreach ($iter as $row) {
            return $row;
        }
        return null;
    }

    /**
     * Upsert — one row per (webhook, itemtype, item, expiration_date).
     * Failed attempts overwrite the previous failed attempt; a later 2xx
     * overwrites the failure. Keeps the log compact and the last outcome
     * visible.
     */
    private static function recordSent(int $webhooks_id, string $itemtype, int $items_id, string $expiration_date, int $http_status, string $response, string $error): void
    {
        global $DB;

        $where = [
            'webhooks_id'     => $webhooks_id,
            'itemtype'        => $itemtype,
            'items_id'        => $items_id,
            'expiration_date' => $expiration_date,
        ];

        $data = [
            'http_status'      => $http_status,
            'response_excerpt' => PluginWebhooksSender::excerpt($response),
            'error'            => $error !== '' ? PluginWebhooksSender::excerpt($error) : null,
            'sent_date'        => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ];

        if (countElementsInTable('glpi_plugin_webhooks_sent', $where) > 0) {
            $DB->update('glpi_plugin_webhooks_sent', $data, $where);
        } else {
            $DB->insert('glpi_plugin_webhooks_sent', $data + $where);
        }
    }

    private static function buildContext(string $itemtype, array $row): array
    {
        global $CFG_GLPI;

        $effective_itemtype = $row['itemtype'] ?? $itemtype;
        $effective_id       = (int) $row['id'];

        $entity_name = '';
        $entity      = new Entity();
        if ($entity->getFromDB((int) ($row['entities_id'] ?? 0))) {
            $entity_name = (string) $entity->fields['completename'];
        }

        $item_url = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
            . '/front/' . strtolower($effective_itemtype) . '.form.php?id=' . $effective_id;

        return [
            'itemtype'        => $itemtype,
            'itemtype_label'  => PluginWebhooksWebhook::itemtypeLabel($itemtype),
            'id'              => $effective_id,
            'name'            => (string) $row['name'],
            'entity'          => $entity_name,
            'expiration_date' => (string) $row['expiration_date'],
            'days_left'       => (int) $row['days_left'],
            'days_left_label' => PluginWebhooksSender::daysLeftLabel((int) $row['days_left']),
            'item_url'        => $item_url,
            'emoji'           => PluginWebhooksSender::emojiFor((int) $row['days_left']),
        ];
    }
}
