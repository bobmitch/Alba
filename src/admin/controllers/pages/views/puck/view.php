<?php

Use HoltBosse\Alba\Core\File;
Use HoltBosse\Form\Input;

// $page is provided by model.php
?>
<style>
    /* Reserve full admin content area for the editor — Puck draws its own
       chrome (left sidebar, canvas, right inspector) and needs the height. */
    #puck-root {
        position: relative;
        width: 100%;
        height: calc(100vh - 140px);
        min-height: 600px;
        border: 1px solid #ddd;
        border-radius: 4px;
        overflow: hidden;
        background: #fff;
    }
    #puck-root .puck-loading {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
        color: #888;
        font-style: italic;
    }
    .puck-toolbar {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 1rem;
    }
    .puck-toolbar .puck-status {
        margin-left: auto;
        color: #666;
        font-size: 0.9rem;
    }
    .puck-render-error {
        background: #fee;
        border: 1px solid #c00;
        padding: 0.5rem;
        color: #900;
        font-family: monospace;
        font-size: 0.85rem;
    }
</style>

<h1 class='title is-2'>
    Visual Editor &mdash; <?php echo Input::stringHtmlSafe($page->title); ?>
</h1>

<div class="puck-toolbar">
    <a href="<?php echo $_ENV['uripath']; ?>/admin/pages/edit/<?php echo (int) $page->id; ?>"
       class="button is-light">
        &larr; Back to classic editor
    </a>
    <a href="<?php echo $page->get_url(); ?>" target="_blank" class="button is-light">
        View live page
    </a>
    <span class="puck-status" id="puck-status">Loading editor&hellip;</span>
</div>

<div id="puck-root">
    <div class="puck-loading">Loading visual editor&hellip;</div>
</div>

<!-- Puck editor styles served from esm.sh -->
<link rel="stylesheet" href="https://esm.sh/@measured/puck@0.21.0/dist/index.css" />

<script type="importmap">
{
  "imports": {
    "react": "https://esm.sh/react@18.3.1?dev",
    "react-dom": "https://esm.sh/react-dom@18.3.1?dev",
    "react-dom/client": "https://esm.sh/react-dom@18.3.1/client?dev",
    "react/jsx-runtime": "https://esm.sh/react@18.3.1/jsx-runtime?dev",
    "@measured/puck": "https://esm.sh/@measured/puck@0.21.0?deps=react@18.3.1,react-dom@18.3.1&external=react,react-dom"
  }
}
</script>

<script>
    window.albaPuck = {
        pageId: <?php echo (int) $page->id; ?>,
        apiBase: <?php echo json_encode($_ENV['uripath'] . '/admin/pages/puck_api'); ?>,
        backUrl: <?php echo json_encode($_ENV['uripath'] . '/admin/pages/edit/' . (int) $page->id); ?>
    };
</script>

<script type="module">
<?php echo File::getContents(__DIR__ . '/puck-editor.js'); ?>
</script>
