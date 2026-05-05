<?php

Use HoltBosse\Alba\Core\{CMS, Page, Widget, JSON, Template};
Use HoltBosse\DB\DB;

// JSON API for the Puck visual page editor.
// All routes return JSON and terminate via die(). They are mounted under
// /admin/pages/puck_api/<action>[/<id>].

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$segments = CMS::Instance()->uri_segments;
$action = $segments[2] ?? '';

function puck_json_response(mixed $payload, int $status = 200): never {
    http_response_code($status);
    while (ob_get_level() > 1) {
        ob_end_clean();
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    die();
}

function puck_read_json_body(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') {
        return [];
    }
    try {
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (\JsonException $e) {
        puck_json_response(['ok' => false, 'error' => 'Invalid JSON body: ' . $e->getMessage()], 400);
    }
}

/**
 * Discover all widgets that have a puck_mapping.json sidecar describing how to
 * expose the widget as a Puck component. Phase 1 ships HTML only.
 */
function puck_get_widget_schemas(): array {
    $schemas = [];
    foreach (Widget::getWidgetNames() as $widgetName) {
        $widgetPath = Widget::getWidgetPath($widgetName);
        if (!$widgetPath) {
            continue;
        }
        $mappingFile = $widgetPath . '/puck_mapping.json';
        if (!file_exists($mappingFile)) {
            continue;
        }
        $mapping = JSON::load_obj_from_file($mappingFile);
        if (!$mapping) {
            continue;
        }
        // Look up the widget_type id (DB row) for this widget location so the
        // publish step can later create real widgets table rows of the right type.
        $widget_config = JSON::load_obj_from_file($widgetPath . '/widget_config.json');
        $location = $widget_config->location ?? strtolower($widgetName);
        $widget_type = DB::fetch('SELECT * FROM widget_types WHERE location=?', [$location]);
        $schemas[] = [
            'widgetName' => $widgetName,
            'widgetLocation' => $location,
            'widgetTypeId' => $widget_type ? (int) $widget_type->id : null,
            'puckComponentName' => $mapping->puckComponentName ?? $widgetName,
            'label' => $mapping->label ?? ($widget_config->title ?? $widgetName),
            'category' => $mapping->category ?? 'Widgets',
            'fields' => $mapping->fields ?? new \stdClass(),
            'defaultProps' => $mapping->defaultProps ?? new \stdClass(),
        ];
    }
    return $schemas;
}

/**
 * Build the list of zone names (template positions) for a given page so the
 * Puck UI can render one DropZone per position.
 */
function puck_get_zones_for_page(Page $page): array {
    $template = $page->template;
    if (!$template) {
        $template = Template::get_default_template();
    }
    $positionsFile = Template::getTemplatePath($template->folder) . '/positions.json';
    $positions = [];
    if (file_exists($positionsFile)) {
        $positionsObj = JSON::load_obj_from_file($positionsFile);
        if ($positionsObj && isset($positionsObj->positions)) {
            $positions = $positionsObj->positions;
        }
    }
    return $positions;
}

/**
 * Render a widget's HTML by instantiating the matching Widget subclass.
 * Used by the Puck preview iframe so what you see is the real PHP output.
 */
function puck_render_widget_inline(string $widgetLocation, array $optionsKv): string {
    $widget_type = DB::fetch('SELECT * FROM widget_types WHERE location=?', [$widgetLocation]);
    if (!$widget_type) {
        return '<div class="puck-render-error">Unknown widget type: ' . htmlspecialchars($widgetLocation) . '</div>';
    }
    $widget_class_name = Widget::getWidgetClass($widget_type->location);
    if (!$widget_class_name || !class_exists($widget_class_name)) {
        return '<div class="puck-render-error">Widget class missing for: ' . htmlspecialchars($widgetLocation) . '</div>';
    }
    /** @var Widget $widget */
    $widget = new $widget_class_name();
    $widget->type_id = (int) $widget_type->id;
    $widget->type = $widget_type;
    // Build a minimal options structure mimicking what load() would produce.
    $widget->options = [];
    foreach ($optionsKv as $k => $v) {
        $widget->options[] = (object) ['name' => $k, 'value' => $v];
    }
    $widget->objectOptions = (object) $optionsKv;
    ob_start();
    try {
        $widget->render();
    } catch (\Throwable $e) {
        ob_end_clean();
        return '<div class="puck-render-error">Render error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    return (string) ob_get_clean();
}

switch ($action) {
    case 'schemas': {
        puck_json_response([
            'ok' => true,
            'schemas' => puck_get_widget_schemas(),
        ]);
    }

    case 'load': {
        $page_id = (int) ($segments[3] ?? 0);
        if (!$page_id) {
            puck_json_response(['ok' => false, 'error' => 'Missing page id'], 400);
        }
        $page = new Page();
        if (!$page->load_from_id($page_id)) {
            puck_json_response(['ok' => false, 'error' => 'Page not found'], 404);
        }
        $draft = null;
        if ($page->draft_data) {
            try {
                $draft = json_decode($page->draft_data, false, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $draft = null;
            }
        }
        puck_json_response([
            'ok' => true,
            'page' => [
                'id' => $page->id,
                'title' => $page->title,
                'alias' => $page->alias,
                'state' => $page->state,
                'template' => $page->template?->title,
            ],
            'zones' => puck_get_zones_for_page($page),
            'draft' => $draft,
            'hasDraft' => $page->draft_data !== null && $page->draft_data !== '',
        ]);
    }

    case 'save': {
        $page_id = (int) ($segments[3] ?? 0);
        if (!$page_id) {
            puck_json_response(['ok' => false, 'error' => 'Missing page id'], 400);
        }
        $page = new Page();
        if (!$page->load_from_id($page_id)) {
            puck_json_response(['ok' => false, 'error' => 'Page not found'], 404);
        }
        $body = puck_read_json_body();
        if (!isset($body['data'])) {
            puck_json_response(['ok' => false, 'error' => 'Missing draft data'], 400);
        }
        $json = json_encode($body['data'], JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            puck_json_response(['ok' => false, 'error' => 'Could not serialise draft'], 400);
        }
        $page->save_draft_data($json);
        puck_json_response(['ok' => true, 'savedAt' => date('c')]);
    }

    case 'discard': {
        $page_id = (int) ($segments[3] ?? 0);
        if (!$page_id) {
            puck_json_response(['ok' => false, 'error' => 'Missing page id'], 400);
        }
        $page = new Page();
        if (!$page->load_from_id($page_id)) {
            puck_json_response(['ok' => false, 'error' => 'Page not found'], 404);
        }
        $page->clear_draft_data();
        puck_json_response(['ok' => true]);
    }

    case 'publish': {
        // Convert the saved Puck draft into real widgets + page_widget_overrides
        // entries so the live page renders the layout.
        $page_id = (int) ($segments[3] ?? 0);
        if (!$page_id) {
            puck_json_response(['ok' => false, 'error' => 'Missing page id'], 400);
        }
        $page = new Page();
        if (!$page->load_from_id($page_id)) {
            puck_json_response(['ok' => false, 'error' => 'Page not found'], 404);
        }
        $body = puck_read_json_body();
        // Allow caller to pass the draft directly (e.g. publish without saving),
        // otherwise fall back to the persisted draft.
        $draft = $body['data'] ?? null;
        if ($draft === null) {
            if (!$page->draft_data) {
                puck_json_response(['ok' => false, 'error' => 'No draft to publish'], 400);
            }
            try {
                $draft = json_decode($page->draft_data, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                puck_json_response(['ok' => false, 'error' => 'Stored draft is corrupt'], 500);
            }
        }

        // Map Puck componentName -> widget_type id + location.
        $schemas = puck_get_widget_schemas();
        $componentLookup = [];
        foreach ($schemas as $schema) {
            $componentLookup[$schema['puckComponentName']] = $schema;
        }

        $zones = $draft['zones'] ?? [];
        // Puck v0.18+ puts the root zone in $draft['content'] and named zones
        // keyed as "root:<zoneName>". We accept both shapes for forward-compat.
        if (isset($draft['content']) && is_array($draft['content']) && !isset($zones['root:default'])) {
            $zones['root:default'] = $draft['content'];
        }

        $publishedPositions = [];
        $createdWidgetIds = [];
        $errors = [];

        foreach ($zones as $zoneKey => $items) {
            // zoneKey = "root:<positionName>" — strip the "root:" prefix.
            $positionName = preg_replace('/^root:/', '', (string) $zoneKey);
            if ($positionName === '' || $positionName === 'default') {
                continue;
            }
            $widgetIds = [];
            foreach ((array) $items as $item) {
                $componentName = $item['type'] ?? null;
                $props = $item['props'] ?? [];
                if (!$componentName || !isset($componentLookup[$componentName])) {
                    $errors[] = "Skipped unknown component '{$componentName}' in zone {$positionName}";
                    continue;
                }
                $schema = $componentLookup[$componentName];
                if (!$schema['widgetTypeId']) {
                    $errors[] = "Skipped '{$componentName}' — no widget_type row for location '{$schema['widgetLocation']}'";
                    continue;
                }
                // Strip Puck-internal props.
                $widgetOptions = [];
                foreach ($props as $k => $v) {
                    if ($k === 'id' || $k === 'editMode') continue;
                    $widgetOptions[] = ['name' => $k, 'value' => $v];
                }
                $title = sprintf('Puck: %s @ %s', $schema['label'], $positionName);
                $domain = $_SESSION['current_domain'] ?? 0;
                $insertOk = DB::exec(
                    "INSERT INTO widgets (state, type, title, note, options, position_control, global_position, page_list, domain, ordering)
                     VALUES (1, ?, ?, 'Generated by Puck visual editor', ?, 0, ?, ?, ?, 0)",
                    [
                        $schema['widgetTypeId'],
                        $title,
                        json_encode($widgetOptions),
                        $positionName,
                        (string) $page_id,
                        $domain,
                    ]
                );
                if ($insertOk) {
                    $widgetIds[] = (int) DB::getLastInsertedId();
                }
            }
            $createdWidgetIds = array_merge($createdWidgetIds, $widgetIds);
            $csv = implode(',', $widgetIds);
            DB::exec(
                "INSERT INTO page_widget_overrides (page_id, position, widgets) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE widgets=?",
                [$page_id, $positionName, $csv, $csv]
            );
            $publishedPositions[] = $positionName;
        }

        // Persist the draft as the new "published" snapshot too — keeps the
        // Puck editor showing the same layout after publish.
        $page->save_draft_data(json_encode($draft, JSON_UNESCAPED_SLASHES));

        puck_json_response([
            'ok' => true,
            'publishedPositions' => $publishedPositions,
            'createdWidgetIds' => $createdWidgetIds,
            'errors' => $errors,
        ]);
    }

    case 'render_widget': {
        // Render an existing widget by its id (uses normal load + internal_render).
        $widget_id = (int) ($segments[3] ?? 0);
        if (!$widget_id) {
            puck_json_response(['ok' => false, 'error' => 'Missing widget id'], 400);
        }
        $widget = DB::fetch('SELECT * FROM widgets WHERE id=? AND state>=0', [$widget_id]);
        if (!$widget) {
            puck_json_response(['ok' => false, 'error' => 'Widget not found'], 404);
        }
        $type_info = Widget::get_widget_type($widget->type);
        $widget_class_name = Widget::getWidgetClass($type_info->location);
        if (!$widget_class_name) {
            puck_json_response(['ok' => false, 'error' => 'Widget class not registered'], 500);
        }
        /** @var Widget $instance */
        $instance = new $widget_class_name();
        $instance->load($widget_id);
        ob_start();
        $instance->internal_render();
        $html = (string) ob_get_clean();
        puck_json_response(['ok' => true, 'html' => $html]);
    }

    case 'render_inline': {
        // Render a widget by location + options (used for live preview of
        // unsaved Puck blocks).
        $body = puck_read_json_body();
        $location = $body['widgetLocation'] ?? '';
        $props = $body['props'] ?? [];
        if (!is_string($location) || $location === '') {
            puck_json_response(['ok' => false, 'error' => 'Missing widgetLocation'], 400);
        }
        $html = puck_render_widget_inline($location, is_array($props) ? $props : []);
        puck_json_response(['ok' => true, 'html' => $html]);
    }

    default:
        puck_json_response(['ok' => false, 'error' => "Unknown puck_api action '{$action}'"], 404);
}
