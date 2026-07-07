<?php
/**
 * Ticket lifecycle → webhook dispatch.
 *
 * Second feature family of the plugin (the first being the expiration cron).
 * Instead of scanning on a schedule, this reacts to GLPI lifecycle hooks on
 * Ticket and its satellite itemtypes, normalises each into an "event", and
 * POSTs the configured payload to every active webhook with
 * trigger_type = 'ticket' that is subscribed to that event, in scope for the
 * ticket's entity, and matches its optional criteria filter.
 *
 * The event catalog intentionally mirrors GLPI's own Ticket notification
 * events (see core NotificationTargetTicket::getEvents() /
 * NotificationTargetCommonITILObject::getEvents()) instead of an ad-hoc list:
 * same keys, same meaning, same granularity — so "Cambio de estado" isn't a
 * separate generic bucket that overlaps with "Resuelto"/"Cerrado", the same
 * way core doesn't have one either. A handful of native events are NOT
 * offered here because they are raised by GLPI's own cron/rule engine, not by
 * a single lifecycle action, and reproducing that logic is out of scope for
 * this real-time hook-based feature: `validation_reminder`, `alertnotclosed`,
 * `recall`, `recall_ola`, `user_mention`, `auto_reminder`, `pendingreason_close`.
 *
 * These handlers run INSIDE the request that saves the record, so they must
 * never throw (a failed webhook must not break the save) and must not block
 * for long — the HTTP send uses a short timeout.
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can\'t access this file directly');
}

class PluginWebhooksTicketEvent
{
    /**
     * Event catalog: key => human label. Keys match GLPI's native Ticket
     * notification event keys 1:1 (see class docblock).
     */
    public const EVENTS = [
        'new'               => 'Ticket nuevo',
        'update'            => 'Actualización de ticket',
        'solved'            => 'Ticket resuelto',
        'rejectsolution'    => 'Solución rechazada',
        'closed'            => 'Cierre del ticket',
        'delete'            => 'Eliminación del ticket',
        'requester_user'    => 'Nuevo usuario solicitante',
        'requester_group'   => 'Nuevo grupo solicitante',
        'observer_user'     => 'Nuevo usuario observador',
        'observer_group'    => 'Nuevo grupo observador',
        'assign_user'       => 'Nuevo técnico asignado',
        'assign_group'      => 'Nuevo grupo asignado',
        'assign_supplier'   => 'Nuevo proveedor asignado',
        'add_task'          => 'Nueva tarea',
        'update_task'       => 'Actualización de tarea',
        'delete_task'       => 'Eliminación de tarea',
        'add_followup'      => 'Nuevo seguimiento',
        'update_followup'   => 'Actualización de seguimiento',
        'delete_followup'   => 'Eliminación de seguimiento',
        'add_document'      => 'Nuevo documento adjunto',
        'validation'        => 'Solicitud de aprobación',
        'validation_answer' => 'Respuesta de aprobación',
        'satisfaction'      => 'Encuesta de satisfacción enviada',
        'replysatisfaction' => 'Encuesta de satisfacción respondida',
        'pendingreason_add' => 'Motivo de pausa agregado',
        'pendingreason_del' => 'Motivo de pausa quitado',
    ];

    private const EMOJIS = [
        'new'               => ':new:',
        'update'             => ':arrows_counterclockwise:',
        'solved'            => ':white_check_mark:',
        'rejectsolution'    => ':x:',
        'closed'            => ':lock:',
        'delete'            => ':wastebasket:',
        'assign_user'       => ':bust_in_silhouette:',
        'assign_group'      => ':busts_in_silhouette:',
        'assign_supplier'   => ':package:',
        'add_task'          => ':clipboard:',
        'add_followup'      => ':speech_balloon:',
        'add_document'      => ':paperclip:',
        'validation'        => ':raised_hand:',
        'validation_answer' => ':ballot_box_with_check:',
        'satisfaction'      => ':star:',
        'replysatisfaction' => ':star2:',
    ];

    /** HTTP timeout (seconds) for event sends — short, we run in the save path. */
    private const SEND_TIMEOUT = 8;

    public static function eventLabel(string $key): string
    {
        return self::EVENTS[$key] ?? $key;
    }

    // -----------------------------------------------------------------------
    // Lifecycle hook entry points (registered in setup.php)
    // -----------------------------------------------------------------------

    public static function onTicketAdd($item): void
    {
        self::guard(static function () use ($item) {
            if (!($item instanceof Ticket)) {
                return;
            }
            self::dispatch('new', $item, []);
            // A ticket can be created already solved/closed (e.g. import).
            $status = (int) ($item->fields['status'] ?? 0);
            if (in_array($status, Ticket::getSolvedStatusArray(), true)) {
                self::dispatch('solved', $item, []);
            }
            if (in_array($status, Ticket::getClosedStatusArray(), true)) {
                self::dispatch('closed', $item, []);
            }
        });
    }

    public static function onTicketPreDelete($item): void
    {
        self::guard(static function () use ($item) {
            if (!($item instanceof Ticket)) {
                return;
            }
            self::dispatch('delete', $item, []);
        });
    }

    public static function onTicketUpdate($item): void
    {
        self::guard(static function () use ($item) {
            if (!($item instanceof Ticket)) {
                return;
            }
            // $item->oldvalues only holds the fields that actually changed.
            $old = $item->oldvalues ?? [];
            if ($old === []) {
                return;
            }

            self::dispatch('update', $item, []);

            if (array_key_exists('status', $old)) {
                $new = (int) ($item->fields['status'] ?? 0);
                if (in_array($new, Ticket::getSolvedStatusArray(), true)) {
                    self::dispatch('solved', $item, []);
                }
                if (in_array($new, Ticket::getClosedStatusArray(), true)) {
                    self::dispatch('closed', $item, []);
                }
            }
        });
    }

    public static function onFollowupAdd($item): void
    {
        self::guard(static function () use ($item) {
            self::emitLinked($item, 'ITILFollowup', 'add_followup', [
                'content' => (string) ($item->fields['content'] ?? ''),
            ]);
        });
    }

    public static function onFollowupUpdate($item): void
    {
        self::guard(static function () use ($item) {
            self::emitLinked($item, 'ITILFollowup', 'update_followup', [
                'content' => (string) ($item->fields['content'] ?? ''),
            ]);
        });
    }

    public static function onFollowupDelete($item): void
    {
        self::guard(static function () use ($item) {
            self::emitLinked($item, 'ITILFollowup', 'delete_followup', []);
        });
    }

    public static function onTaskAdd($item): void
    {
        self::guard(static function () use ($item) {
            if (!($item instanceof TicketTask)) {
                return;
            }
            self::emit('add_task', (int) ($item->fields['tickets_id'] ?? 0), [
                'content' => (string) ($item->fields['content'] ?? ''),
            ]);
        });
    }

    public static function onTaskUpdate($item): void
    {
        self::guard(static function () use ($item) {
            if (!($item instanceof TicketTask)) {
                return;
            }
            self::emit('update_task', (int) ($item->fields['tickets_id'] ?? 0), [
                'content' => (string) ($item->fields['content'] ?? ''),
            ]);
        });
    }

    public static function onTaskDelete($item): void
    {
        self::guard(static function () use ($item) {
            if (!($item instanceof TicketTask)) {
                return;
            }
            self::emit('delete_task', (int) ($item->fields['tickets_id'] ?? 0), []);
        });
    }

    public static function onSolutionUpdate($item): void
    {
        self::guard(static function () use ($item) {
            if (!($item instanceof ITILSolution)) {
                return;
            }
            if ((string) ($item->fields['itemtype'] ?? '') !== 'Ticket') {
                return;
            }
            $old = $item->oldvalues ?? [];
            if (!array_key_exists('status', $old)) {
                return;
            }
            if ((int) ($item->fields['status'] ?? 0) !== CommonITILValidation::REFUSED) {
                return;
            }
            self::emit('rejectsolution', (int) ($item->fields['items_id'] ?? 0), [
                'content' => (string) ($item->fields['content'] ?? ''),
            ]);
        });
    }

    public static function onActorAdd($item): void
    {
        self::guard(static function () use ($item) {
            if (!($item instanceof Ticket_User)) {
                return;
            }
            $has_identity = (int) ($item->fields['users_id'] ?? 0) > 0
                || !empty($item->fields['alternative_email']);
            if (!$has_identity) {
                return;
            }
            $event = self::actorEvent((int) ($item->fields['type'] ?? 0), 'user');
            if ($event === null) {
                return;
            }
            self::emit($event, (int) ($item->fields['tickets_id'] ?? 0), []);
        });
    }

    public static function onGroupActorAdd($item): void
    {
        self::guard(static function () use ($item) {
            if (!($item instanceof Group_Ticket)) {
                return;
            }
            if ((int) ($item->fields['groups_id'] ?? 0) <= 0) {
                return;
            }
            $event = self::actorEvent((int) ($item->fields['type'] ?? 0), 'group');
            if ($event === null) {
                return;
            }
            self::emit($event, (int) ($item->fields['tickets_id'] ?? 0), []);
        });
    }

    public static function onSupplierActorAdd($item): void
    {
        self::guard(static function () use ($item) {
            if (!($item instanceof Supplier_Ticket)) {
                return;
            }
            if ((int) ($item->fields['suppliers_id'] ?? 0) <= 0) {
                return;
            }
            // Native GLPI only defines "New supplier in assignees" — there is
            // no requester/observer-supplier event in the reference catalog.
            if ((int) ($item->fields['type'] ?? 0) !== CommonITILActor::ASSIGN) {
                return;
            }
            self::emit('assign_supplier', (int) ($item->fields['tickets_id'] ?? 0), []);
        });
    }

    public static function onDocumentAdd($item): void
    {
        self::guard(static function () use ($item) {
            self::emitLinked($item, 'Document_Item', 'add_document', []);
        });
    }

    public static function onValidationAdd($item): void
    {
        self::guard(static function () use ($item) {
            if (!($item instanceof TicketValidation)) {
                return;
            }
            self::emit('validation', (int) ($item->fields['tickets_id'] ?? 0), [
                'content' => (string) ($item->fields['comment_submission'] ?? ''),
            ]);
        });
    }

    public static function onValidationUpdate($item): void
    {
        self::guard(static function () use ($item) {
            if (!($item instanceof TicketValidation)) {
                return;
            }
            $old = $item->oldvalues ?? [];
            if (!array_key_exists('status', $old)) {
                return;
            }
            $new = (int) ($item->fields['status'] ?? 0);
            if (!in_array($new, [CommonITILValidation::ACCEPTED, CommonITILValidation::REFUSED], true)) {
                return;
            }
            self::emit('validation_answer', (int) ($item->fields['tickets_id'] ?? 0), [
                'content' => (string) ($item->fields['comment_validation'] ?? ''),
            ]);
        });
    }

    public static function onSatisfactionAdd($item): void
    {
        self::guard(static function () use ($item) {
            if (!($item instanceof TicketSatisfaction)) {
                return;
            }
            self::emit('satisfaction', (int) ($item->fields['tickets_id'] ?? 0), []);
        });
    }

    public static function onSatisfactionUpdate($item): void
    {
        self::guard(static function () use ($item) {
            if (!($item instanceof TicketSatisfaction)) {
                return;
            }
            $old = $item->oldvalues ?? [];
            if (!array_key_exists('date_answered', $old) && !array_key_exists('satisfaction', $old)) {
                return;
            }
            self::emit('replysatisfaction', (int) ($item->fields['tickets_id'] ?? 0), [
                'content' => (string) ($item->fields['comment'] ?? ''),
            ]);
        });
    }

    public static function onPendingReasonAdd($item): void
    {
        self::guard(static function () use ($item) {
            self::emitLinked($item, 'PendingReason_Item', 'pendingreason_add', []);
        });
    }

    public static function onPendingReasonDelete($item): void
    {
        self::guard(static function () use ($item) {
            self::emitLinked($item, 'PendingReason_Item', 'pendingreason_del', []);
        });
    }

    /**
     * REQUESTER/OBSERVER/ASSIGN → the matching native event key for a
     * user or group actor row, or null if the actor type is unrecognised.
     */
    private static function actorEvent(int $type, string $kind): ?string
    {
        $map = [
            CommonITILActor::REQUESTER => "requester_$kind",
            CommonITILActor::OBSERVER  => "observer_$kind",
            CommonITILActor::ASSIGN    => "assign_$kind",
        ];
        return $map[$type] ?? null;
    }

    // -----------------------------------------------------------------------
    // Dispatch pipeline
    // -----------------------------------------------------------------------

    /**
     * For itemtype+items_id linked satellite objects (ITILFollowup,
     * Document_Item, PendingReason_Item…): confirms the link points at a
     * Ticket, then emits.
     */
    private static function emitLinked($item, string $expected_class, string $event, array $extra): void
    {
        if (!($item instanceof $expected_class)) {
            return;
        }
        if ((string) ($item->fields['itemtype'] ?? '') !== 'Ticket') {
            return;
        }
        self::emit($event, (int) ($item->fields['items_id'] ?? 0), $extra);
    }

    /** Loads the parent ticket, then dispatches. */
    private static function emit(string $event, int $ticket_id, array $extra): void
    {
        if ($ticket_id <= 0) {
            return;
        }
        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticket_id)) {
            return;
        }
        self::dispatch($event, $ticket, $extra);
    }

    private static function dispatch(string $event, Ticket $ticket, array $extra): void
    {
        $ticket_id  = (int) $ticket->fields['id'];
        $ticket_ent = (int) ($ticket->fields['entities_id'] ?? 0);

        foreach (self::activeTicketWebhooks() as $webhook) {
            $events = json_decode((string) ($webhook['ticket_events'] ?? ''), true);
            if (!is_array($events) || !in_array($event, $events, true)) {
                continue;
            }
            if (!self::entityMatches((int) $webhook['entities_id'], (int) $webhook['is_recursive'], $ticket_ent)) {
                continue;
            }
            if (!self::matchesCriteria((string) ($webhook['ticket_criteria'] ?? ''), $ticket_id)) {
                continue;
            }

            $webhook_id = (int) $webhook['id'];
            $ctx        = self::buildContext($event, $ticket, $extra);

            $template = trim((string) ($webhook['payload_template'] ?? ''));
            if ($template === '') {
                $template = PluginWebhooksSender::DEFAULT_TICKET_TEMPLATE;
            }

            [$payload, $err] = PluginWebhooksSender::render($template, $ctx);
            if ($payload === null) {
                self::recordEvent($webhook_id, $ticket_id, $event, 0, '', (string) $err);
                PluginWebhooksSender::recordResult(
                    $webhook_id,
                    ['ok' => false, 'status' => 0, 'response' => '', 'error' => $err],
                    false
                );
                continue;
            }

            $headers = PluginWebhooksSender::parseHeaders($webhook['headers'] ?? null);
            $method  = (string) ($webhook['http_method'] ?? 'POST');
            $result  = PluginWebhooksSender::send(
                (string) $webhook['url'],
                $payload,
                $method,
                $headers,
                self::SEND_TIMEOUT
            );
            PluginWebhooksSender::recordResult($webhook_id, $result, false);
            self::recordEvent(
                $webhook_id,
                $ticket_id,
                $event,
                (int) $result['status'],
                (string) $result['response'],
                $result['error'] !== null ? (string) $result['error'] : ($result['ok'] ? '' : 'HTTP ' . $result['status'])
            );
        }
    }

    private static function activeTicketWebhooks(): array
    {
        global $DB;

        $out = [];
        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_webhooks_webhooks',
            'WHERE' => ['is_active' => 1, 'trigger_type' => 'ticket'],
        ]);
        foreach ($iter as $row) {
            $out[] = $row;
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Filter: native GLPI criteria matching
    // -----------------------------------------------------------------------

    /**
     * True if the ticket matches the webhook's own criteria filter (or there is
     * no filter). $ticket_criteria_json is the same criteria array GLPI's own
     * search builder produces (see PluginWebhooksWebhook::showFiltersTab),
     * JSON-encoded. Evaluated via GLPI's own search engine: the stored criteria
     * plus an "id equals <ticket>" lock; a non-empty result means it matches.
     */
    private static function matchesCriteria(string $ticket_criteria_json, int $ticket_id): bool
    {
        $stored = trim($ticket_criteria_json) === '' ? [] : json_decode($ticket_criteria_json, true);
        if (!is_array($stored) || $stored === []) {
            return true; // no filter configured
        }

        try {
            // Lock to this ticket, then AND the stored criteria as a *group* so a
            // filter built with OR links can't leak past the id lock.
            $criteria = [
                ['field' => self::ticketIdSearchOption(), 'searchtype' => 'equals', 'value' => $ticket_id],
                ['link' => 'AND', 'criteria' => $stored],
            ];

            $data = Search::getDatas('Ticket', [
                'criteria'   => $criteria,
                'is_deleted' => 0,
                'start'      => 0,
            ]);
            return (int) ($data['data']['totalcount'] ?? 0) > 0;
        } catch (\Throwable $e) {
            // A broken filter must not spam: fail closed and log.
            Toolbox::logError('Webhooks criteria match failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Search-option number of Ticket's own database id (usually 2, but resolved
     * dynamically so a wrong hardcode can't silently break every match).
     */
    private static function ticketIdSearchOption(): int
    {
        if (method_exists('Search', 'getOptions')) {
            try {
                foreach (Search::getOptions('Ticket') as $num => $opt) {
                    if (is_array($opt)
                        && ($opt['field'] ?? null) === 'id'
                        && ($opt['table'] ?? null) === Ticket::getTable()
                    ) {
                        return (int) $num;
                    }
                }
            } catch (\Throwable $e) {
                // fall through to default
            }
        }
        return 2;
    }

    // -----------------------------------------------------------------------
    // Context building
    // -----------------------------------------------------------------------

    private static function buildContext(string $event, Ticket $ticket, array $extra): array
    {
        global $CFG_GLPI;

        $tid    = (int) $ticket->fields['id'];
        $status = (int) ($ticket->fields['status'] ?? 0);

        $statuses    = Ticket::getAllStatusArray();
        $status_label = (string) ($statuses[$status] ?? $status);

        $entity_name = '';
        $entity = new Entity();
        if ($entity->getFromDB((int) ($ticket->fields['entities_id'] ?? 0))) {
            $entity_name = (string) $entity->fields['completename'];
        }

        $url = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . '/front/ticket.form.php?id=' . $tid;

        return [
            'event'          => $event,
            'event_label'    => self::eventLabel($event),
            'ticket_id'      => $tid,
            'title'          => (string) ($ticket->fields['name'] ?? ''),
            'status_label'   => $status_label,
            'priority_label' => CommonITILObject::getPriorityName((int) ($ticket->fields['priority'] ?? 0)),
            'urgency_label'  => CommonITILObject::getUrgencyName((int) ($ticket->fields['urgency'] ?? 0)),
            'impact_label'   => CommonITILObject::getImpactName((int) ($ticket->fields['impact'] ?? 0)),
            'type_label'     => self::typeLabel((int) ($ticket->fields['type'] ?? 0)),
            'category'       => self::categoryName((int) ($ticket->fields['itilcategories_id'] ?? 0)),
            'requester'      => self::actorNames($tid, CommonITILActor::REQUESTER),
            'assigned'       => self::actorNames($tid, CommonITILActor::ASSIGN),
            'content'        => PluginWebhooksSender::excerpt(self::plainText((string) ($ticket->fields['content'] ?? '')), 1500),
            'event_content'  => PluginWebhooksSender::excerpt(self::plainText((string) ($extra['content'] ?? '')), 1500),
            'author'         => (string) ($_SESSION['glpifriendlyname'] ?? $_SESSION['glpiname'] ?? 'GLPI'),
            'entity'         => $entity_name,
            'ticket_url'     => $url,
            'emoji'          => self::EMOJIS[$event] ?? ':bell:',
            'date'           => (string) ($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')),
            // Silent aliases shared with the expiration placeholder set.
            'id'             => $tid,
            'name'           => (string) ($ticket->fields['name'] ?? ''),
            'item_url'       => $url,
        ];
    }

    private static function typeLabel(int $type): string
    {
        if ($type === Ticket::INCIDENT_TYPE) {
            return __('Incident');
        }
        if ($type === Ticket::DEMAND_TYPE) {
            return __('Request');
        }
        return (string) $type;
    }

    private static function categoryName(int $id): string
    {
        if ($id <= 0) {
            return '—';
        }
        $name = Dropdown::getDropdownName('glpi_itilcategories', $id);
        $name = trim(html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return ($name === '' || $name === '&nbsp;') ? '—' : $name;
    }

    /**
     * Comma-joined actor names for the given actor type (users + groups).
     */
    private static function actorNames(int $tid, int $type): string
    {
        global $DB;

        $out = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_tickets_users',
            'WHERE' => ['tickets_id' => $tid, 'type' => $type],
        ]) as $r) {
            $uid = (int) ($r['users_id'] ?? 0);
            if ($uid > 0) {
                $n = trim((string) getUserName($uid));
                if ($n !== '') {
                    $out[] = $n;
                }
            } elseif (!empty($r['alternative_email'])) {
                $out[] = (string) $r['alternative_email'];
            }
        }
        foreach ($DB->request([
            'FROM'  => 'glpi_groups_tickets',
            'WHERE' => ['tickets_id' => $tid, 'type' => $type],
        ]) as $r) {
            $gid = (int) ($r['groups_id'] ?? 0);
            if ($gid > 0) {
                $gn = trim((string) Dropdown::getDropdownName('glpi_groups', $gid));
                if ($gn !== '' && $gn !== '&nbsp;') {
                    $out[] = $gn;
                }
            }
        }

        $out = array_values(array_unique(array_filter($out)));
        return $out === [] ? '—' : implode(', ', $out);
    }

    private static function plainText(string $html): string
    {
        $txt = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = strip_tags($txt);
        $txt = html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = preg_replace('/\s+/', ' ', $txt);
        return trim((string) $txt);
    }

    // -----------------------------------------------------------------------
    // Persistence + utilities
    // -----------------------------------------------------------------------

    private static function recordEvent(int $webhooks_id, int $items_id, string $event, int $http_status, string $response, string $error): void
    {
        global $DB;

        $DB->insert('glpi_plugin_webhooks_events', [
            'webhooks_id'      => $webhooks_id,
            'itemtype'         => 'Ticket',
            'items_id'         => $items_id,
            'event'            => $event,
            'http_status'      => $http_status,
            'response_excerpt' => PluginWebhooksSender::excerpt($response),
            'error'            => $error !== '' ? PluginWebhooksSender::excerpt($error) : null,
            'event_date'       => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ]);
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

    /** Runs $fn, swallowing any error so a hook never breaks the ticket save. */
    private static function guard(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Toolbox::logError('Webhooks ticket-event hook failed: ' . $e->getMessage());
        }
    }
}
