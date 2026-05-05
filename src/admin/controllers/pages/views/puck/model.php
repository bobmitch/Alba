<?php

Use HoltBosse\Alba\Core\{CMS, Page};

// Visual page builder (Puck) view. URI: /admin/pages/puck/{page_id}

$segments = CMS::Instance()->uri_segments;
$page_id = (int) ($segments[2] ?? 0);

if (!$page_id) {
    CMS::Instance()->queue_message('Visual editor requires a saved page id', 'danger', $_ENV['uripath'] . '/admin/pages');
    exit(0);
}

$page = new Page();
if (!$page->load_from_id($page_id)) {
    CMS::Instance()->queue_message('Page not found: ' . $page_id, 'danger', $_ENV['uripath'] . '/admin/pages');
    exit(0);
}

CMS::Instance()->edit_page_id = $page->id;
