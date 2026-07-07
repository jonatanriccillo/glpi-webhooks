<?php
/**
 * Webhook target.
 *
 * Tabs:
 *   - Main form (default): connection settings (URL, method, headers, scope).
 *   - Payload: JSON template editor, clickable placeholder chips, live preview.
 *   - Test & status: "Send test now" button, last test + last real send status.
 *   - Sent log: history of real sends with HTTP status badges.
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can\'t access this file directly');
}

class PluginWebhooksWebhook extends CommonDBTM
{
    public static $rightname = 'plugin_webhooks';

    public $dohistory = true;

    public const SUPPORTED_ITEMTYPES = [
        'Contract'        => ['label_key' => 'Contract',        'icon' => 'ti ti-file-description'],
        'SoftwareLicense' => ['label_key' => 'Software license','icon' => 'ti ti-certificate'],
        'Certificate'     => ['label_key' => 'Certificate',     'icon' => 'ti ti-shield-check'],
        'Domain'          => ['label_key' => 'Domain',          'icon' => 'ti ti-world'],
        'Infocom'         => ['label_key' => 'Warranty',        'icon' => 'ti ti-device-desktop'],
    ];

    public const HTTP_METHODS = ['POST', 'PUT', 'PATCH'];

    public static function getTypeName($nb = 0): string
    {
        return _n('Webhook', 'Webhooks', $nb, 'webhooks');
    }

    public static function getIcon(): string
    {
        return 'ti ti-webhook';
    }

    public function isEntityAssign(): bool
    {
        return true;
    }

    public function maybeRecursive(): bool
    {
        return true;
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(self::class, $tabs, $options);
        $this->addStandardTab('Log', $tabs, $options);
        return $tabs;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof self && $item->fields['id'] > 0) {
            if ($item->isTicketType()) {
                return [
                    1 => self::createTabEntry(__('Payload', 'webhooks'), 0, null, 'ti ti-braces'),
                    5 => self::createTabEntry(__('Filtros', 'webhooks'), 0, null, 'ti ti-filter'),
                    3 => self::createTabEntry(__('Prueba y estado', 'webhooks'), 0, null, 'ti ti-send'),
                    6 => self::createTabEntry(__('Registro de eventos', 'webhooks'), 0, null, 'ti ti-history'),
                ];
            }
            return [
                1 => self::createTabEntry(__('Payload', 'webhooks'), 0, null, 'ti ti-braces'),
                2 => self::createTabEntry(__('Por vencer', 'webhooks'), 0, null, 'ti ti-calendar-stats'),
                3 => self::createTabEntry(__('Prueba y estado', 'webhooks'), 0, null, 'ti ti-send'),
                4 => self::createTabEntry(__('Registro de envíos', 'webhooks'), 0, null, 'ti ti-history'),
            ];
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof self) {
            switch ((int) $tabnum) {
                case 1: $item->showPayloadTab(); break;
                case 2: $item->showUpcomingTab(); break;
                case 3: $item->showTestTab(); break;
                case 4: $item->showLogTab(); break;
                case 5: $item->showFiltersTab(); break;
                case 6: $item->showEventLogTab(); break;
            }
        }
        return true;
    }

    public function isTicketType(): bool
    {
        return ($this->fields['trigger_type'] ?? 'expiration') === 'ticket';
    }

    public function getSelectedEvents(): array
    {
        if (empty($this->fields['ticket_events'])) {
            return [];
        }
        $decoded = json_decode((string) $this->fields['ticket_events'], true);
        return is_array($decoded) ? $decoded : [];
    }

    public function rawSearchOptions(): array
    {
        $t = $this->getTable();
        return [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            ['id' => 1, 'table' => $t, 'field' => 'name', 'name' => __('Name'), 'datatype' => 'itemlink', 'massiveaction' => false],
            ['id' => 2, 'table' => $t, 'field' => 'is_active', 'name' => __('Active'), 'datatype' => 'bool'],
            ['id' => 3, 'table' => $t, 'field' => 'url', 'name' => __('URL'), 'datatype' => 'text'],
            ['id' => 4, 'table' => $t, 'field' => 'http_method', 'name' => __('Método HTTP', 'webhooks'), 'datatype' => 'string'],
            ['id' => 20, 'table' => $t, 'field' => 'trigger_type', 'name' => __('Disparador', 'webhooks'), 'datatype' => 'string'],
            ['id' => 5, 'table' => $t, 'field' => 'itemtypes', 'name' => __('Tipos de ítem', 'webhooks'), 'datatype' => 'text'],
            ['id' => 6, 'table' => $t, 'field' => 'anticipation_days', 'name' => __('Anticipación (días)', 'webhooks'), 'datatype' => 'integer'],
            ['id' => 7, 'table' => $t, 'field' => 'last_sent_date', 'name' => __('Último envío', 'webhooks'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 8, 'table' => $t, 'field' => 'last_http_status', 'name' => __('Último HTTP', 'webhooks'), 'datatype' => 'integer', 'massiveaction' => false],
            ['id' => 9, 'table' => $t, 'field' => 'comment', 'name' => __('Comments'), 'datatype' => 'text'],
            ['id' => 80, 'table' => 'glpi_entities', 'field' => 'completename', 'name' => __('Entity'), 'datatype' => 'dropdown', 'massiveaction' => false],
            ['id' => 86, 'table' => $t, 'field' => 'is_recursive', 'name' => __('Child entities'), 'datatype' => 'bool'],
            ['id' => 121, 'table' => $t, 'field' => 'date_creation', 'name' => __('Creation date'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 19, 'table' => $t, 'field' => 'date_mod', 'name' => __('Last update'), 'datatype' => 'datetime', 'massiveaction' => false],
        ];
    }

    public function prepareInputForAdd($input)
    {
        $trigger = in_array(($input['trigger_type'] ?? 'expiration'), ['expiration', 'ticket'], true)
            ? $input['trigger_type']
            : 'expiration';

        if (!isset($input['payload_template']) || trim((string) $input['payload_template']) === '') {
            $input['payload_template'] = $trigger === 'ticket'
                ? PluginWebhooksSender::DEFAULT_TICKET_TEMPLATE
                : PluginWebhooksSender::DEFAULT_TEMPLATE;
        }
        if (!isset($input['anticipation_days']) || (int) $input['anticipation_days'] <= 0) {
            $input['anticipation_days'] = 30;
        }
        // itemtypes is NOT NULL; ticket webhooks don't use it, so default to [].
        if ($trigger === 'ticket' && empty($input['itemtypes'])) {
            $input['itemtypes'] = json_encode([]);
        }
        return $this->sanitizeInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->sanitizeInput($input);
    }

    private function sanitizeInput(array $input)
    {
        if (isset($input['trigger_type'])) {
            $input['trigger_type'] = in_array($input['trigger_type'], ['expiration', 'ticket'], true)
                ? $input['trigger_type']
                : 'expiration';
        }

        if (isset($input['ticket_events']) && is_array($input['ticket_events'])) {
            $keep = [];
            foreach ($input['ticket_events'] as $event) {
                if (array_key_exists($event, PluginWebhooksTicketEvent::EVENTS)) {
                    $keep[] = $event;
                }
            }
            $input['ticket_events'] = json_encode(array_values(array_unique($keep)));
        }

        if (isset($input['itemtypes']) && is_array($input['itemtypes'])) {
            $keep = [];
            foreach ($input['itemtypes'] as $itemtype) {
                if (array_key_exists($itemtype, self::SUPPORTED_ITEMTYPES)) {
                    $keep[] = $itemtype;
                }
            }
            $input['itemtypes'] = json_encode(array_values(array_unique($keep)));
        }

        if (isset($input['url'])) {
            $input['url'] = trim((string) $input['url']);
            if ($input['url'] !== '' && !preg_match('~^https?://~i', $input['url'])) {
                Session::addMessageAfterRedirect(__('La URL debe empezar con http:// o https://', 'webhooks'), false, ERROR);
                return false;
            }
        }

        if (isset($input['http_method'])) {
            $input['http_method'] = strtoupper(trim((string) $input['http_method']));
            if (!in_array($input['http_method'], self::HTTP_METHODS, true)) {
                $input['http_method'] = 'POST';
            }
        }

        if (isset($input['anticipation_days'])) {
            $v = (int) $input['anticipation_days'];
            $input['anticipation_days'] = ($v > 0 && $v <= 3650) ? $v : 30;
        }

        if (isset($input['payload_template']) && $input['payload_template'] !== '') {
            $decoded = json_decode((string) $input['payload_template'], true);
            if (!is_array($decoded)) {
                Session::addMessageAfterRedirect(
                    __('El template del payload no es JSON válido: ', 'webhooks') . json_last_error_msg(),
                    false,
                    ERROR
                );
                return false;
            }
        }

        return $input;
    }

    public function getSelectedItemtypes(): array
    {
        if (empty($this->fields['itemtypes'])) {
            return [];
        }
        $decoded = json_decode((string) $this->fields['itemtypes'], true);
        return is_array($decoded) ? $decoded : [];
    }

    // -----------------------------------------------------------------------
    // Default form: connection settings
    // -----------------------------------------------------------------------

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        // showFormHeader() opens <table class='tab_cadre_fixe'><tr><td colspan=4>
        // (GLPI's standard header cell). Close it and the table immediately —
        // everything below uses Bootstrap's row/col grid instead of table rows,
        // like the Payload/Filtros/Prueba tabs already do. A <table> forces
        // every row sharing it to reconcile the SAME column widths, so a wide
        // colspan=3 cell in one row could squeeze/overflow a discrete-column
        // row elsewhere (this was pushing "Activo" off-screen). Bootstrap rows
        // are independent flex containers — no such cross-row reconciliation.
        // showFormButtons() later echoes its own "</table>"; with no table
        // open at that point it's just an unmatched closing tag, which
        // browsers ignore harmlessly.
        echo '</td></tr></table>';

        $selected = $this->getSelectedItemtypes();
        $trigger  = $this->fields['trigger_type'] ?? 'expiration';

        echo '<div class="card mb-3">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-router"></i> '
            . __('Tipo de webhook', 'webhooks') . '</h3></div>';
        echo '<div class="card-body">';
        echo '<div class="row g-3"><div class="col-md-6">';
        echo '<label class="form-label"><i class="ti ti-bolt"></i> ' . __('Disparador', 'webhooks') . ' <span class="red">*</span></label>';
        echo "<select name='trigger_type' id='webhooks_trigger_type' class='form-select' onchange='webhooksToggleTrigger(this.value)'>";
        echo "<option value='expiration'" . ($trigger === 'expiration' ? ' selected' : '') . '>'
            . __('Vencimientos (cron)', 'webhooks') . '</option>';
        echo "<option value='ticket'" . ($trigger === 'ticket' ? ' selected' : '') . '>'
            . __('Eventos de ticket (tiempo real)', 'webhooks') . '</option>';
        echo '</select>';
        echo '<div class="form-text">'
            . __('“Vencimientos” escanea contratos/licencias/etc. por cron. “Eventos de ticket” dispara al crear o cambiar tickets, en tiempo real.', 'webhooks')
            . '</div>';
        echo '</div></div>'; // col, row
        echo '</div></div>'; // card-body, card

        echo '<div class="card mb-3">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-plug"></i> '
            . __('Conexión', 'webhooks') . '</h3></div>';
        echo '<div class="card-body">';

        echo '<div class="row g-3 mb-1">';
        echo '<div class="col-md-8">';
        echo '<label class="form-label"><i class="ti ti-tag"></i> ' . __('Name') . ' <span class="red">*</span></label>';
        echo Html::input('name', ['value' => $this->fields['name'] ?? '']);
        echo '</div>';
        echo '<div class="col-md-4">';
        echo '<label class="form-label"><i class="ti ti-bolt"></i> ' . __('Active') . '</label><br>';
        Dropdown::showYesNo('is_active', $this->fields['is_active'] ?? 1);
        echo '</div>';
        echo '</div>'; // row

        echo '<div class="row g-3 mb-1"><div class="col-12">';
        echo '<label class="form-label"><i class="ti ti-link"></i> ' . __('URL') . ' <span class="red">*</span></label>';
        echo Html::input('url', [
            'value' => $this->fields['url'] ?? '',
            'type'  => 'url',
            'placeholder' => 'https://hooks.slack.com/services/... (o cualquier endpoint JSON)',
        ]);
        echo '<div class="form-text">' . __('Endpoint HTTP(S) que va a recibir el payload JSON.', 'webhooks') . '</div>';
        echo '</div></div>'; // col, row

        echo '<div class="row g-3"><div class="col-md-3">';
        echo '<label class="form-label"><i class="ti ti-arrow-right"></i> ' . __('Método HTTP', 'webhooks') . '</label>';
        Dropdown::showFromArray(
            'http_method',
            array_combine(self::HTTP_METHODS, self::HTTP_METHODS),
            ['value' => $this->fields['http_method'] ?? 'POST']
        );
        echo '</div>';
        echo '<div class="col-md-9">';
        echo '<label class="form-label"><i class="ti ti-key"></i> ' . __('Headers custom', 'webhooks') . '</label>';
        echo "<textarea name='headers' rows='3' class='form-control' style='font-family:monospace; font-size:12px;' "
            . "placeholder='Authorization: Bearer xxx&#10;X-Custom: value'>"
            . htmlspecialchars((string) ($this->fields['headers'] ?? ''))
            . '</textarea>';
        echo '<div class="form-text">' . __('Un "Clave: Valor" por línea. Dejar vacío para usar los valores por defecto.', 'webhooks') . '</div>';
        echo '</div></div>'; // col, row
        echo '</div></div>'; // card-body, card

        // --- Expiration scope (trigger_type = expiration) ---
        echo '<div class="card mb-3 wh-row-expiration">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-target"></i> '
            . __('Alcance y disparador', 'webhooks') . '</h3></div>';
        echo '<div class="card-body">';

        echo '<label class="form-label"><i class="ti ti-list-check"></i> ' . __('Tipos de ítem', 'webhooks') . ' <span class="red">*</span></label>';
        echo "<div style='display:flex; flex-wrap:wrap; gap:10px;' class='mb-3'>";
        foreach (self::SUPPORTED_ITEMTYPES as $itemtype => $meta) {
            $checked = in_array($itemtype, $selected, true) ? 'checked' : '';
            $label   = self::itemtypeLabel($itemtype);
            echo "<label style='display:inline-flex; align-items:center; gap:6px; padding:6px 12px; "
                . "border:1px solid #dee2e6; border-radius:20px; cursor:pointer; background:#f8f9fa;'>"
                . "<input type='checkbox' name='itemtypes[]' value='" . htmlspecialchars($itemtype) . "' {$checked}> "
                . "<i class='" . $meta['icon'] . "'></i> "
                . htmlspecialchars($label)
                . '</label>';
        }
        echo '</div>';

        echo '<label class="form-label"><i class="ti ti-calendar-clock"></i> ' . __('Anticipación (días)', 'webhooks') . '</label><br>';
        $anticip = (int) ($this->fields['anticipation_days'] ?? 30);
        if ($anticip <= 0) {
            $anticip = 30;
        }
        echo "<input type='number' name='anticipation_days' value='" . $anticip
            . "' min='1' max='3650' step='1' class='form-control d-inline-block' style='width:120px;'>";
        echo '<span class="text-muted">&nbsp; ' . __('días antes del vencimiento para empezar a alertar', 'webhooks') . '</span>';
        echo '</div></div>'; // card-body, card

        // --- Ticket events (trigger_type = ticket) ---
        echo '<div class="card mb-3 wh-row-ticket">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-ticket"></i> '
            . __('Eventos de ticket', 'webhooks') . '</h3></div>';
        echo '<div class="card-body">';
        echo '<div class="row g-3 mb-1"><div class="col-md-3">';
        echo '<label class="form-label"><i class="ti ti-category"></i> ' . __('Tipo', 'webhooks') . '</label><br>';
        echo '<strong>' . __('Ticket') . '</strong>';
        echo '</div></div>';

        echo '<label class="form-label"><i class="ti ti-bolt"></i> ' . __('Evento', 'webhooks') . ' <span class="red">*</span></label>';
        $selected_events = $this->getSelectedEvents();
        Dropdown::showFromArray('ticket_events', PluginWebhooksTicketEvent::EVENTS, [
            'multiple' => true,
            'values'   => $selected_events,
            'width'    => '100%',
        ]);
        echo '<div class="form-text">'
            . __('Podés elegir varios eventos para el mismo webhook. El filtrado fino (estado, prioridad, categoría, etc.) se configura en la pestaña “Filtros”.', 'webhooks')
            . '</div>';
        echo '</div></div>'; // card-body, card

        echo '<div class="card mb-3">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-message-2"></i> '
            . __('Notas', 'webhooks') . '</h3></div>';
        echo '<div class="card-body">';
        echo '<label class="form-label">' . __('Comments') . '</label>';
        echo "<textarea name='comment' rows='2' class='form-control'>"
            . htmlspecialchars((string) ($this->fields['comment'] ?? ''))
            . '</textarea>';
        echo '</div></div>'; // card-body, card

        // Show only the block relevant to the selected trigger type.
        // Toggle visibility AND disabled state: hidden fields must not be
        // validated or submitted (a hidden required/constrained input silently
        // blocks the browser's form submit — "invalid control cannot be focused").
        echo "<script>
function webhooksToggleTrigger(v){
  var isTicket = (v === 'ticket');
  document.querySelectorAll('.wh-row-ticket').forEach(function(el){
    el.style.display = isTicket ? '' : 'none';
    el.querySelectorAll('input,select,textarea').forEach(function(f){ f.disabled = !isTicket; });
  });
  document.querySelectorAll('.wh-row-expiration').forEach(function(el){
    el.style.display = isTicket ? 'none' : '';
    el.querySelectorAll('input,select,textarea').forEach(function(f){ f.disabled = isTicket; });
  });
}
webhooksToggleTrigger(" . json_encode($trigger) . ");
</script>";

        $this->showFormButtons($options);
        return true;
    }

    // -----------------------------------------------------------------------
    // Tab: Payload
    // -----------------------------------------------------------------------

    public function showPayloadTab(): void
    {
        $id        = (int) $this->fields['id'];
        $is_ticket = $this->isTicketType();
        $default   = $is_ticket
            ? PluginWebhooksSender::DEFAULT_TICKET_TEMPLATE
            : PluginWebhooksSender::DEFAULT_TEMPLATE;
        $placeholders = $is_ticket
            ? PluginWebhooksSender::TICKET_PLACEHOLDERS
            : PluginWebhooksSender::PLACEHOLDERS;
        $template = (string) ($this->fields['payload_template'] ?? $default);
        $action   = Plugin::getWebDir('webhooks') . '/front/webhook.form.php';

        $sample_ctx = $is_ticket
            ? PluginWebhooksSender::buildTicketTestContext($this)
            : PluginWebhooksSender::buildTestContext($this);
        [$preview, $preview_err] = PluginWebhooksSender::render($template, $sample_ctx);
        $preview_json = $preview !== null
            ? json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';

        echo '<div class="card mb-3">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-braces"></i> '
            . __('Template de payload (JSON)', 'webhooks') . '</h3>';
        echo '<div class="card-subtitle text-muted">'
            . __('Usá {placeholders} para inyectar datos reales del ítem. Debe ser JSON válido después de la sustitución.', 'webhooks')
            . '</div></div>';
        echo '<div class="card-body">';

        echo "<form method='post' action='" . htmlspecialchars($action) . "'>";
        echo Html::hidden('id', ['value' => $id]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        echo '<div class="mb-3"><strong>' . __('Placeholders disponibles (click para copiar)', 'webhooks') . '</strong></div>';
        echo '<div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px;">';
        foreach ($placeholders as $key => $desc) {
            $chip = '{' . $key . '}';
            echo "<span title='" . htmlspecialchars($desc) . "' "
                . "onclick='navigator.clipboard.writeText(" . json_encode($chip) . "); this.style.background=\"#c8e6c9\"; setTimeout(()=>this.style.background=\"#e7f1ff\",600);' "
                . "style='cursor:pointer; padding:6px 12px; background:#e7f1ff; border:1px solid #9ec5fe; border-radius:16px; font-family:monospace; font-size:12px; user-select:all;'>"
                . htmlspecialchars($chip)
                . '</span>';
        }
        echo '</div>';

        echo '<textarea name="payload_template" rows="22" style="width:100%; font-family:monospace; font-size:12px; line-height:1.45;" spellcheck="false">'
            . htmlspecialchars($template) . '</textarea>';

        echo '<div class="mt-3 d-flex gap-2">';
        echo "<button type='submit' name='save_template' class='btn btn-primary'>"
            . '<i class="ti ti-device-floppy"></i> ' . __('Guardar template', 'webhooks') . '</button>';
        $reset_confirm = __('Esto sobrescribe el template actual. ¿Continuar?', 'webhooks');
        echo "<button type='submit' name='reset_template' class='btn btn-outline-secondary' "
            . "onclick='return confirm(" . htmlspecialchars(json_encode($reset_confirm), ENT_QUOTES) . ")'>"
            . '<i class="ti ti-restore"></i> ' . __('Restaurar template por defecto', 'webhooks') . '</button>';
        echo '</div>';

        Html::closeForm();
        echo '</div></div>';

        echo '<div class="card">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-eye"></i> '
            . __('Vista previa (con datos de ejemplo)', 'webhooks') . '</h3></div>';
        echo '<div class="card-body">';
        if ($preview_err !== null) {
            echo '<div class="alert alert-danger"><i class="ti ti-alert-triangle"></i> '
                . htmlspecialchars($preview_err) . '</div>';
        } else {
            echo '<pre style="background:#0f172a; color:#e2e8f0; padding:14px; border-radius:6px; max-height:400px; overflow:auto; font-size:12px;">'
                . htmlspecialchars($preview_json) . '</pre>';
        }
        echo '</div></div>';
    }

    // -----------------------------------------------------------------------
    // Scheduler status card (shown above the Upcoming list)
    // -----------------------------------------------------------------------

    private function showSchedulerCard(string $action): void
    {
        global $CFG_GLPI;

        $task = new CronTask();
        $found = $task->getFromDBbyName('PluginWebhooksCrontask', 'expirationcheck');

        echo '<div class="card mb-3">';
        echo '<div class="card-header">';
        echo '<h3 class="card-title"><i class="ti ti-clock-play"></i> '
            . __('Planificador', 'webhooks') . '</h3>';
        echo '<div class="card-subtitle text-muted">'
            . __('Indica si el cron diario está activo y cuándo se va a ejecutar la próxima vez.', 'webhooks')
            . '</div>';
        echo '</div>';
        echo '<div class="card-body">';

        if (!$found) {
            echo '<div class="alert alert-danger"><i class="ti ti-alert-triangle"></i> '
                . __('La tarea cron no está registrada. Reinstalá el plugin.', 'webhooks')
                . '</div>';
            echo '</div></div>';
            return;
        }

        $state     = (int) $task->fields['state'];
        $lastrun   = $task->fields['lastrun'] ?? null;
        $frequency = (int) ($task->fields['frequency'] ?? 0);
        $next_ts   = ($lastrun && $frequency > 0) ? strtotime((string) $lastrun) + $frequency : null;

        if ($state === CronTask::STATE_DISABLE) {
            $state_badge = '<span class="badge bg-danger"><i class="ti ti-player-pause"></i> '
                . __('Deshabilitado', 'webhooks') . '</span>';
        } elseif ($state === CronTask::STATE_RUNNING) {
            $state_badge = '<span class="badge bg-warning"><i class="ti ti-player-play"></i> '
                . __('Ejecutando', 'webhooks') . '</span>';
        } else {
            $state_badge = '<span class="badge bg-success"><i class="ti ti-check"></i> '
                . __('Activo', 'webhooks') . '</span>';
        }

        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-3"><small class="text-muted d-block">' . __('Estado', 'webhooks') . '</small>' . $state_badge . '</div>';
        echo '<div class="col-md-3"><small class="text-muted d-block">' . __('Frecuencia', 'webhooks') . '</small><strong>' . self::humanizeSeconds($frequency) . '</strong></div>';
        echo '<div class="col-md-3"><small class="text-muted d-block">' . __('Última corrida', 'webhooks') . '</small><strong>'
            . ($lastrun ? Html::convDateTime((string) $lastrun) : '<span class="text-muted">' . __('nunca', 'webhooks') . '</span>')
            . '</strong></div>';
        echo '<div class="col-md-3"><small class="text-muted d-block">' . __('Próxima corrida (estimada)', 'webhooks') . '</small><strong>'
            . ($next_ts
                ? Html::convDateTime(date('Y-m-d H:i:s', $next_ts))
                : '<span class="text-muted">' . __('pendiente primera corrida', 'webhooks') . '</span>')
            . '</strong></div>';
        echo '</div>';

        if ($state !== CronTask::STATE_WAITING && $state !== CronTask::STATE_RUNNING) {
            echo '<div class="alert alert-warning"><i class="ti ti-alert-circle"></i> '
                . __('La tarea cron está deshabilitada en GLPI. Los envíos programados NO se van a ejecutar hasta que se la reactive.', 'webhooks')
                . '</div>';
        }

        echo '<div class="d-flex flex-wrap gap-2">';
        echo "<form method='post' action='" . htmlspecialchars($action) . "' class='m-0'>";
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo Html::hidden('id', ['value' => (int) $this->fields['id']]);
        $confirm = __('¿Ejecutar el planificador sobre TODOS los webhooks activos ahora?', 'webhooks');
        echo '<button type="submit" name="run_scheduler" class="btn btn-outline-primary" '
            . "onclick=\"return confirm(" . json_encode($confirm) . ")\">"
            . '<i class="ti ti-player-play"></i> '
            . __('Ejecutar planificador ahora (todos los webhooks)', 'webhooks')
            . '</button>';
        Html::closeForm();

        $cron_url = ($CFG_GLPI['root_doc'] ?? '') . '/front/crontask.form.php?id=' . (int) $task->fields['id'];
        echo '<a href="' . htmlspecialchars($cron_url) . '" class="btn btn-outline-secondary">'
            . '<i class="ti ti-external-link"></i> '
            . __('Abrir en admin de Cron', 'webhooks')
            . '</a>';
        echo '</div>';

        echo '</div></div>';
    }

    private static function humanizeSeconds(int $seconds): string
    {
        if ($seconds <= 0) {
            return '—';
        }
        if ($seconds % DAY_TIMESTAMP === 0) {
            $n = (int) ($seconds / DAY_TIMESTAMP);
            return sprintf(_n('%d día', '%d días', $n, 'webhooks'), $n);
        }
        if ($seconds % HOUR_TIMESTAMP === 0) {
            $n = (int) ($seconds / HOUR_TIMESTAMP);
            return sprintf(_n('%d hora', '%d horas', $n, 'webhooks'), $n);
        }
        if ($seconds % MINUTE_TIMESTAMP === 0) {
            $n = (int) ($seconds / MINUTE_TIMESTAMP);
            return sprintf(_n('%d minuto', '%d minutos', $n, 'webhooks'), $n);
        }
        return $seconds . 's';
    }

    // -----------------------------------------------------------------------
    // Tab: Upcoming
    // -----------------------------------------------------------------------

    public function showUpcomingTab(): void
    {
        $id           = (int) $this->fields['id'];
        $anticipation = (int) ($this->fields['anticipation_days'] ?? 30);
        $action       = Plugin::getWebDir('webhooks') . '/front/webhook.form.php';

        $this->showSchedulerCard($action);

        $entries = PluginWebhooksCrontask::collectUpcomingForWebhook($this);

        $counts = ['overdue' => 0, 'pending' => 0, 'sent' => 0, 'future' => 0, 'failed' => 0];
        foreach ($entries as $e) {
            $counts[$e['status']]++;
            if (!empty($e['last_failed'])) {
                $counts['failed']++;
            }
        }
        $actionable = $counts['overdue'] + $counts['pending'];

        // Upcoming only shows items that still need action OR are future.
        // Successfully sent items live in the Sent log tab.
        $visible = array_values(array_filter(
            $entries,
            fn($e) => $e['status'] !== 'sent'
        ));

        echo '<div class="card">';
        echo '<div class="card-header">';
        echo '<h3 class="card-title"><i class="ti ti-calendar-stats"></i> '
            . __('Contratos e ítems detectados', 'webhooks') . '</h3>';
        echo '<div class="card-subtitle text-muted">'
            . sprintf(
                __('Alcance completo del webhook. Ventana: %d días antes del vencimiento. Los ítems ya vencidos también reciben un aviso la primera vez.', 'webhooks'),
                $anticipation
            )
            . '</div>';
        echo '</div>';

        echo '<div class="card-body">';

        echo '<div class="d-flex align-items-center gap-2 flex-wrap mb-3">';
        echo "<form method='post' action='" . htmlspecialchars($action) . "' class='m-0'>";
        echo Html::hidden('id', ['value' => $id]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        $disabled = $actionable === 0 ? 'disabled' : '';
        $confirm  = __('¿Enviar los vencidos y pendientes al URL del webhook ahora?', 'webhooks');
        echo "<button type='submit' name='send_pending' class='btn btn-primary' {$disabled} "
            . "onclick=\"return confirm(" . json_encode($confirm) . ")\">"
            . '<i class="ti ti-send"></i> '
            . sprintf(__('Enviar %d ahora', 'webhooks'), $actionable)
            . '</button>';
        Html::closeForm();

        echo '<span class="badge bg-danger" style="font-size:13px;"><i class="ti ti-alert-triangle"></i> '
            . sprintf(__('%d vencidos', 'webhooks'), $counts['overdue']) . '</span>';
        echo '<span class="badge bg-primary" style="font-size:13px;"><i class="ti ti-hourglass"></i> '
            . sprintf(__('%d pendientes', 'webhooks'), $counts['pending']) . '</span>';
        if ($counts['failed'] > 0) {
            echo '<span class="badge bg-warning" style="font-size:13px;"><i class="ti ti-refresh-alert"></i> '
                . sprintf(__('%d con intentos fallidos', 'webhooks'), $counts['failed']) . '</span>';
        }
        echo '<span class="badge bg-success" style="font-size:13px;"><i class="ti ti-check"></i> '
            . sprintf(__('%d enviados (ver Registro)', 'webhooks'), $counts['sent']) . '</span>';
        echo '<span class="badge bg-secondary" style="font-size:13px;"><i class="ti ti-clock"></i> '
            . sprintf(__('%d futuros', 'webhooks'), $counts['future']) . '</span>';
        echo '</div>';

        if ($visible === []) {
            if ($counts['sent'] > 0) {
                echo '<div class="text-center text-success p-4">'
                    . '<i class="ti ti-circle-check" style="font-size:32px;"></i><br>'
                    . sprintf(__('Los %d ítems se enviaron correctamente. Ver la pestaña "Registro de envíos" para el historial.', 'webhooks'), $counts['sent'])
                    . '</div>';
            } else {
                echo '<div class="text-center text-muted p-4">'
                    . '<i class="ti ti-database-off" style="font-size:32px;"></i><br>'
                    . __('No se detectaron ítems. Verificá que el webhook tenga tipos seleccionados y que el alcance de entidad coincida con tus datos.', 'webhooks')
                    . '</div>';
            }
            echo '</div></div>';
            return;
        }

        echo '<table class="table table-hover table-vcenter">';
        echo '<thead><tr>';
        echo '<th>' . __('Estado', 'webhooks') . '</th>';
        echo '<th>' . __('Tipo', 'webhooks') . '</th>';
        echo '<th>' . __('Nombre', 'webhooks') . '</th>';
        echo '<th>' . __('Entidad', 'webhooks') . '</th>';
        echo '<th>' . __('Vence', 'webhooks') . '</th>';
        echo '<th class="text-end">' . __('Días', 'webhooks') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($visible as $e) {
            $itemtype = $e['itemtype'];
            $row      = $e['row'];
            $dl       = (int) $row['days_left'];
            $status   = $e['status'];

            $row_style = '';
            switch ($status) {
                case 'overdue':
                    $status_cell = '<span class="badge bg-danger"><i class="ti ti-alert-triangle"></i> '
                        . __('Vencido', 'webhooks') . '</span>';
                    $row_style = 'background:#fff5f5;';
                    break;
                case 'pending':
                    $status_cell = '<span class="badge bg-primary"><i class="ti ti-hourglass"></i> '
                        . __('Pendiente', 'webhooks') . '</span>';
                    $row_style = 'background:#eff6ff;';
                    break;
                case 'future':
                default:
                    $status_cell = '<span class="badge bg-secondary"><i class="ti ti-clock"></i> '
                        . __('Futuro', 'webhooks') . '</span>';
                    $row_style = 'opacity:0.65;';
                    break;
            }

            if ($dl < 0 || $dl <= 3) {
                $days_badge = '<span class="badge bg-danger">' . $dl . '</span>';
            } elseif ($dl <= 7) {
                $days_badge = '<span class="badge bg-warning">' . $dl . '</span>';
            } elseif ($dl <= $anticipation) {
                $days_badge = '<span class="badge bg-info">' . $dl . '</span>';
            } else {
                $days_badge = '<span class="badge bg-secondary">' . $dl . '</span>';
            }

            $item_id   = (int) $row['id'];
            $item_link = '#';
            if (class_exists($itemtype)) {
                $item_link = $itemtype::getFormURLWithID($item_id);
            }

            $entity_name = '';
            $entity = new Entity();
            if ($entity->getFromDB((int) ($row['entities_id'] ?? 0))) {
                $entity_name = (string) $entity->fields['completename'];
            }

            $failure_note = '';
            if (!empty($e['last_failed']) && $e['last_attempt']) {
                $la   = $e['last_attempt'];
                $msg  = (string) ($la['error'] ?? '');
                if ($msg === '') {
                    $msg = 'HTTP ' . (int) ($la['http_status'] ?? 0);
                }
                $when = $la['sent_date'] ?? '';
                $failure_note = '<br><small class="text-danger"><i class="ti ti-refresh-alert"></i> '
                    . sprintf(
                        __('Último intento fallido: %s (%s) — va a reintentar', 'webhooks'),
                        htmlspecialchars(PluginWebhooksSender::excerpt($msg, 160)),
                        htmlspecialchars((string) $when)
                    )
                    . '</small>';
            }

            echo '<tr style="' . $row_style . '">';
            echo '<td>' . $status_cell . '</td>';
            echo '<td>' . htmlspecialchars(self::itemtypeLabel($itemtype)) . '</td>';
            echo '<td><a href="' . htmlspecialchars($item_link) . '">'
                . htmlspecialchars((string) $row['name']) . '</a>' . $failure_note . '</td>';
            echo '<td><small class="text-muted">' . htmlspecialchars($entity_name) . '</small></td>';
            echo '<td>' . htmlspecialchars((string) $row['expiration_date']) . '</td>';
            echo '<td class="text-end">' . $days_badge . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<div class="text-muted small mt-2"><i class="ti ti-info-circle"></i> '
            . __('Los "Futuros" pasan automáticamente a "Pendiente" al entrar en la ventana de anticipación. Los "Enviados" dejan de alertar hasta que el ítem se renueve (cambie la fecha de vencimiento).', 'webhooks')
            . '</div>';
        echo '</div></div>';
    }

    // -----------------------------------------------------------------------
    // Tab: Test & status
    // -----------------------------------------------------------------------

    public function showTestTab(): void
    {
        $id     = (int) $this->fields['id'];
        $action = Plugin::getWebDir('webhooks') . '/front/webhook.form.php';

        echo '<div class="card mb-3">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-send"></i> '
            . __('Enviar evento de prueba', 'webhooks') . '</h3></div>';
        echo '<div class="card-body">';
        echo '<p class="text-muted mb-3">'
            . ($this->isTicketType()
                ? __('Dispara un evento sintético ("Ticket creado") al URL configurado usando el template actual. No registra el envío en el log de eventos.', 'webhooks')
                : __('Dispara un evento sintético ("Contrato vence en 15 días") al URL configurado usando el template actual. No genera entrada de dedupe.', 'webhooks'))
            . '</p>';

        echo "<form method='post' action='" . htmlspecialchars($action) . "'>";
        echo Html::hidden('id', ['value' => $id]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
        echo '<button type="submit" name="test_send" class="btn btn-primary btn-lg">'
            . '<i class="ti ti-send"></i> ' . __('Enviar prueba ahora', 'webhooks') . '</button>';
        Html::closeForm();
        echo '</div></div>';

        echo '<div class="card">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-activity"></i> '
            . __('Estado', 'webhooks') . '</h3></div>';
        echo '<div class="card-body p-0">';
        echo '<table class="table table-hover mb-0">';
        echo '<thead><tr><th style="width:30%;"></th><th>' . __('Cuándo', 'webhooks')
            . '</th><th>' . __('HTTP', 'webhooks') . '</th><th>' . __('Detalle', 'webhooks') . '</th></tr></thead>';
        echo '<tbody>';
        $this->renderStatusRow(
            '<i class="ti ti-broadcast"></i> ' . __('Último envío real', 'webhooks'),
            $this->fields['last_sent_date'] ?? null,
            $this->fields['last_http_status'] ?? null,
            $this->fields['last_error'] ?? null
        );
        $this->renderStatusRow(
            '<i class="ti ti-flask"></i> ' . __('Última prueba', 'webhooks'),
            $this->fields['last_test_date'] ?? null,
            $this->fields['last_test_status'] ?? null,
            $this->fields['last_test_response'] ?? null
        );
        echo '</tbody></table>';
        echo '</div></div>';

        // "Último envío real" above is webhook-level (last HTTP status/response
        // regardless of which ticket caused it). For a ticket webhook, name the
        // actual ticket + event behind that last send.
        if ($this->isTicketType()) {
            $this->showLastTicketEventContext();
        }
    }

    private function showLastTicketEventContext(): void
    {
        global $DB;

        $row = null;
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_webhooks_events',
            'WHERE' => ['webhooks_id' => (int) $this->fields['id']],
            'ORDER' => ['event_date DESC'],
            'LIMIT' => 1,
        ]) as $r) {
            $row = $r;
        }

        if ($row === null) {
            echo '<div class="text-muted small mt-2"><i class="ti ti-info-circle"></i> '
                . __('Todavía no se registró ningún evento de ticket para este webhook.', 'webhooks')
                . '</div>';
            return;
        }

        $tid    = (int) $row['items_id'];
        $link   = Ticket::getFormURLWithID($tid);
        $status = (int) ($row['http_status'] ?? 0);
        $ok     = $status >= 200 && $status < 300;
        $badge  = $ok
            ? '<span class="badge bg-success">' . $status . '</span>'
            : '<span class="badge bg-danger">' . ($status > 0 ? $status : 'ERR') . '</span>';

        echo '<div class="text-muted small mt-2"><i class="ti ti-info-circle"></i> '
            . sprintf(
                __('El "Último envío real" de arriba fue disparado por: %1$s (%2$s) — %3$s. Ver la pestaña "Registro de eventos" para el historial completo.', 'webhooks'),
                '<a href="' . htmlspecialchars($link) . '">Ticket #' . $tid . '</a>',
                htmlspecialchars(PluginWebhooksTicketEvent::eventLabel((string) $row['event'])),
                $badge
            )
            . '</div>';
    }

    private function renderStatusRow(string $label, $when, $status, $detail): void
    {
        $when_cell = ($when === null || $when === '')
            ? '<span class="text-muted">—</span>'
            : Html::convDateTime((string) $when);

        $status_cell = '<span class="text-muted">—</span>';
        if ($status !== null && $status !== '') {
            $s = (int) $status;
            if ($s >= 200 && $s < 300) {
                $status_cell = '<span class="badge bg-success">' . $s . '</span>';
            } elseif ($s === 0) {
                $status_cell = '<span class="badge bg-dark">ERR</span>';
            } else {
                $status_cell = '<span class="badge bg-danger">' . $s . '</span>';
            }
        }

        $detail_cell = '<span class="text-muted">—</span>';
        if ($detail !== null && $detail !== '') {
            $detail_cell = '<pre style="margin:0; background:#f6f6f6; padding:6px 8px; font-size:11px; white-space:pre-wrap; max-height:160px; overflow:auto; border-radius:4px;">'
                . htmlspecialchars((string) $detail) . '</pre>';
        }

        echo '<tr>';
        echo '<td><strong>' . $label . '</strong></td>';
        echo '<td>' . $when_cell . '</td>';
        echo '<td>' . $status_cell . '</td>';
        echo '<td>' . $detail_cell . '</td>';
        echo '</tr>';
    }

    // -----------------------------------------------------------------------
    // Tab: Sent log
    // -----------------------------------------------------------------------

    public function showLogTab(): void
    {
        global $DB;

        $id = (int) $this->fields['id'];

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_webhooks_sent',
            'WHERE' => ['webhooks_id' => $id],
            'ORDER' => ['sent_date DESC'],
            'LIMIT' => 50,
        ]);

        echo '<div class="card">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-history"></i> '
            . __('Últimos 50 eventos', 'webhooks') . '</h3></div>';
        echo '<div class="card-body p-0">';
        echo '<table class="table table-hover table-vcenter mb-0">';
        echo '<thead><tr>';
        echo '<th>' . __('Enviado', 'webhooks') . '</th>';
        echo '<th>' . __('Tipo', 'webhooks') . '</th>';
        echo '<th>' . __('ID', 'webhooks') . '</th>';
        echo '<th>' . __('Fecha de vencimiento', 'webhooks') . '</th>';
        echo '<th>' . __('HTTP', 'webhooks') . '</th>';
        echo '<th>' . __('Detalle', 'webhooks') . '</th>';
        echo '</tr></thead><tbody>';

        $count = 0;
        foreach ($iter as $row) {
            $count++;
            $status = (int) ($row['http_status'] ?? 0);
            if ($status >= 200 && $status < 300) {
                $badge = '<span class="badge bg-success">' . $status . '</span>';
            } elseif ($status === 0) {
                $badge = '<span class="badge bg-dark">ERR</span>';
            } else {
                $badge = '<span class="badge bg-danger">' . $status . '</span>';
            }

            $detail = (string) ($row['error'] ?? '');
            if ($detail === '') {
                $detail = (string) ($row['response_excerpt'] ?? '');
            }
            if ($status >= 200 && $status < 300 && $detail === '') {
                $detail = 'OK';
            }

            $itemtype_label = self::itemtypeLabel((string) $row['itemtype']);
            $item_link = '#';
            if (class_exists((string) $row['itemtype']) && (int) $row['items_id'] > 0) {
                $item_link = (string) $row['itemtype']::getFormURLWithID((int) $row['items_id']);
            }

            echo '<tr>';
            echo '<td>' . Html::convDateTime((string) $row['sent_date']) . '</td>';
            echo '<td>' . htmlspecialchars($itemtype_label) . '</td>';
            echo '<td>';
            if ($item_link !== '#') {
                echo '<a href="' . htmlspecialchars($item_link) . '">#' . (int) $row['items_id'] . '</a>';
            } else {
                echo (int) $row['items_id'];
            }
            echo '</td>';
            echo '<td>' . htmlspecialchars((string) $row['expiration_date']) . '</td>';
            echo '<td>' . $badge . '</td>';
            echo '<td><small>' . htmlspecialchars(PluginWebhooksSender::excerpt($detail, 120)) . '</small></td>';
            echo '</tr>';
        }

        if ($count === 0) {
            echo '<tr><td colspan="6" class="text-center text-muted p-4">'
                . '<i class="ti ti-inbox"></i> ' . __('Todavía no se enviaron eventos.', 'webhooks')
                . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }

    // -----------------------------------------------------------------------
    // Tab: Filtros (ticket webhooks) — embedded native GLPI criteria builder
    // -----------------------------------------------------------------------

    public function getTicketCriteria(): array
    {
        if (empty($this->fields['ticket_criteria'])) {
            return [];
        }
        $decoded = json_decode((string) $this->fields['ticket_criteria'], true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Structurally cleans a posted criteria array (from GLPI's own search
     * builder) before it's stored as JSON: keeps only recognised keys per node
     * type (plain criterion / group / meta-criterion) and caps nesting depth.
     * The values themselves (field id, searchtype, value) are trusted as-is —
     * only a plugin_webhooks UPDATE right holder can reach this, and they are
     * replayed through the same read-only Search engine, never raw SQL.
     */
    public static function sanitizeCriteria($criteria, int $depth = 0): array
    {
        if (!is_array($criteria) || $depth > 5) {
            return [];
        }

        $out = [];
        foreach ($criteria as $row) {
            if (!is_array($row)) {
                continue;
            }

            $link = in_array(($row['link'] ?? 'AND'), ['AND', 'OR', 'AND NOT', 'OR NOT'], true)
                ? $row['link']
                : 'AND';

            if (isset($row['criteria']) && is_array($row['criteria'])) {
                // Criteria group.
                $sub = self::sanitizeCriteria($row['criteria'], $depth + 1);
                if ($sub !== []) {
                    $out[] = ['link' => $link, 'criteria' => $sub];
                }
                continue;
            }

            $field = $row['field'] ?? '';
            if ($field === '' || $field === null) {
                continue; // incomplete row (e.g. an "add rule" click left blank)
            }

            $clean = [
                'link'       => $link,
                'field'      => is_scalar($field) ? $field : (string) $field,
                'searchtype' => (string) ($row['searchtype'] ?? 'contains'),
                'value'      => is_scalar($row['value'] ?? null) ? $row['value'] : (string) ($row['value'] ?? ''),
            ];
            if (!empty($row['meta'])) {
                $clean['meta']     = true;
                $clean['itemtype'] = (string) ($row['itemtype'] ?? '');
            }
            $out[] = $clean;
        }
        return $out;
    }

    public function showFiltersTab(): void
    {
        $id       = (int) $this->fields['id'];
        $action   = Plugin::getWebDir('webhooks') . '/front/webhook.form.php';
        $criteria = $this->getTicketCriteria();

        echo '<div class="card mb-3">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-filter"></i> '
            . __('Filtro por criterios de Ticket', 'webhooks') . '</h3>';
        echo '<div class="card-subtitle text-muted">'
            . __('Un ticket dispara sólo si entra en el alcance de entidad del webhook Y coincide con estos criterios. Mismo motor y mismos campos que la búsqueda de la lista de Tickets (estado, prioridad, categoría, técnico, título, contenido, SLA, campos de plugins…). Sin criterios = todos los del alcance.', 'webhooks')
            . '</div></div>';
        echo '<div class="card-body">';

        echo "<form method='post' action='" . htmlspecialchars($action) . "'>";
        echo Html::hidden('id', ['value' => $id]);
        echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

        // Render GLPI's own multi-criteria builder (the exact widget used on the
        // Ticket list), but standalone: no own <form>, no search/bookmark/reset
        // buttons — those come from OUR form below. displayCriteria() reads each
        // row's current field/value from $_SESSION['glpisearch']['Ticket']
        // ['criteria'], so we stash the webhook's saved criteria into that slot
        // just for this render and restore whatever was there right after, to
        // avoid clobbering the user's own in-progress Ticket list search.
        //
        // mainform=false is required to avoid nesting a second <form> inside
        // ours, but GLPI's own twig ALSO gates the "remove rule" (-) button's
        // click handler behind mainform — with it false, that JS never binds,
        // so rows visually pile up (each looks "duplicated" because the old
        // one can never be removed). We ship the exact same handler ourselves
        // right after. The widget's own CSS for mainform=false is a narrow
        // "sub_criteria" inline box (meant to be nested INSIDE an already-
        // rendered search page); we override it to look like our own cards.
        echo '<style>
            .wh-criteria-builder .sub_criteria { width: 100%; display: block; border: 0; margin: 0; padding: 0; }
            .wh-criteria-builder .criteria-list { width: 100%; }
        </style>';
        echo '<div class="wh-criteria-builder">';
        $session_backup = $_SESSION['glpisearch']['Ticket']['criteria'] ?? null;
        $_SESSION['glpisearch']['Ticket']['criteria'] = $criteria;
        Search::showGenericSearch('Ticket', [
            'criteria'     => $criteria,
            'mainform'     => false,
            'showaction'   => false,
            'showbookmark' => false,
            'showreset'    => false,
            'showfolding'  => false,
        ]);
        if ($session_backup === null) {
            unset($_SESSION['glpisearch']['Ticket']['criteria']);
        } else {
            $_SESSION['glpisearch']['Ticket']['criteria'] = $session_backup;
        }
        echo '</div>';
        echo "<script>
        $(document).off('click', '.remove-search-criteria').on('click', '.remove-search-criteria', function() {
            var tooltip = bootstrap.Tooltip.getInstance(this);
            if (tooltip !== null) { tooltip.dispose(); }
            var rowID = $(this).data('rowid');
            $('#' + rowID).remove();
        });
        </script>";

        echo "<div class='mt-2'><button type='submit' name='save_filter' class='btn btn-primary'>"
            . '<i class="ti ti-device-floppy"></i> ' . __('Guardar filtro', 'webhooks') . '</button></div>';
        Html::closeForm();

        // Live feedback: how many tickets match the current saved filter.
        if ($criteria !== []) {
            $count = null;
            try {
                $data  = Search::getDatas('Ticket', [
                    'criteria'   => $criteria,
                    'is_deleted' => 0,
                    'start'      => 0,
                ]);
                $count = (int) ($data['data']['totalcount'] ?? 0);
            } catch (\Throwable $e) {
                $count = null;
            }
            if ($count !== null) {
                echo '<div class="alert alert-info mt-3 mb-0"><i class="ti ti-list-search"></i> '
                    . sprintf(__('Ahora mismo coinciden %d ticket(s) con este filtro (según tu alcance de visibilidad).', 'webhooks'), $count)
                    . '</div>';
            } else {
                echo '<div class="alert alert-warning mt-3 mb-0"><i class="ti ti-alert-triangle"></i> '
                    . __('No se pudo evaluar el filtro guardado. Revisá los criterios.', 'webhooks')
                    . '</div>';
            }
        }

        echo '</div></div>';
    }

    // -----------------------------------------------------------------------
    // Tab: Registro de eventos (ticket webhooks)
    // -----------------------------------------------------------------------

    public function showEventLogTab(): void
    {
        global $DB;

        $id = (int) $this->fields['id'];

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_webhooks_events',
            'WHERE' => ['webhooks_id' => $id],
            'ORDER' => ['event_date DESC'],
            'LIMIT' => 100,
        ]);

        echo '<div class="card">';
        echo '<div class="card-header"><h3 class="card-title"><i class="ti ti-history"></i> '
            . __('Últimos 100 eventos', 'webhooks') . '</h3></div>';
        echo '<div class="card-body p-0">';
        echo '<table class="table table-hover table-vcenter mb-0">';
        echo '<thead><tr>';
        echo '<th>' . __('Cuándo', 'webhooks') . '</th>';
        echo '<th>' . __('Evento', 'webhooks') . '</th>';
        echo '<th>' . __('Ticket', 'webhooks') . '</th>';
        echo '<th>' . __('HTTP', 'webhooks') . '</th>';
        echo '<th>' . __('Detalle', 'webhooks') . '</th>';
        echo '</tr></thead><tbody>';

        $count = 0;
        foreach ($iter as $row) {
            $count++;
            $status = (int) ($row['http_status'] ?? 0);
            if ($status >= 200 && $status < 300) {
                $badge = '<span class="badge bg-success">' . $status . '</span>';
            } elseif ($status === 0) {
                $badge = '<span class="badge bg-dark">ERR</span>';
            } else {
                $badge = '<span class="badge bg-danger">' . $status . '</span>';
            }

            $detail = (string) ($row['error'] ?? '');
            if ($detail === '') {
                $detail = (string) ($row['response_excerpt'] ?? '');
            }
            if ($status >= 200 && $status < 300 && $detail === '') {
                $detail = 'OK';
            }

            $tid  = (int) $row['items_id'];
            $link = Ticket::getFormURLWithID($tid);

            echo '<tr>';
            echo '<td>' . Html::convDateTime((string) $row['event_date']) . '</td>';
            echo '<td>' . htmlspecialchars(PluginWebhooksTicketEvent::eventLabel((string) $row['event'])) . '</td>';
            echo '<td><a href="' . htmlspecialchars($link) . '">#' . $tid . '</a></td>';
            echo '<td>' . $badge . '</td>';
            echo '<td><small>' . htmlspecialchars(PluginWebhooksSender::excerpt($detail, 120)) . '</small></td>';
            echo '</tr>';
        }

        if ($count === 0) {
            echo '<tr><td colspan="5" class="text-center text-muted p-4">'
                . '<i class="ti ti-inbox"></i> ' . __('Todavía no se registraron eventos.', 'webhooks')
                . '</td></tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    public static function itemtypeLabel(string $itemtype): string
    {
        switch ($itemtype) {
            case 'Contract':        return __('Contract');
            case 'SoftwareLicense': return __('Software license');
            case 'Certificate':     return __('Certificate');
            case 'Domain':          return __('Domain');
            case 'Infocom':         return __('Garantía (Infocom)', 'webhooks');
            default:                return $itemtype;
        }
    }

    public static function findActiveForItemtype(string $itemtype, int $entities_id): array
    {
        global $DB;

        $rows = [];
        $iter = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['is_active' => 1],
        ]);

        foreach ($iter as $row) {
            $decoded = json_decode((string) $row['itemtypes'], true);
            if (!is_array($decoded) || !in_array($itemtype, $decoded, true)) {
                continue;
            }
            if (!self::entityMatches((int) $row['entities_id'], (int) $row['is_recursive'], $entities_id)) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
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
}
