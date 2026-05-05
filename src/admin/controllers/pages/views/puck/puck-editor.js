// Alba ↔ Puck visual page editor bootstrap.
//
// Loaded inline by /admin/pages/puck/{id}. Pulls React + Puck from esm.sh
// (no build step required). The widget→Puck bridge is data-driven: each
// Alba widget that ships a puck_mapping.json sidecar becomes a Puck
// component, with field types translated below in `albaFieldsToPuckFields`.

import React, { useEffect, useMemo, useState, useRef } from "react";
import { createRoot } from "react-dom/client";
import { Puck, DropZone } from "@puckeditor/core";

const h = React.createElement;
const cfg = window.albaPuck;

const setStatus = (msg) => {
    const el = document.getElementById("puck-status");
    if (el) el.textContent = msg;
};

async function api(path, opts = {}) {
    const res = await fetch(`${cfg.apiBase}/${path}`, {
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        ...opts,
    });
    if (!res.ok) {
        let msg = `${res.status} ${res.statusText}`;
        try {
            const body = await res.json();
            if (body && body.error) msg += ` — ${body.error}`;
        } catch {}
        throw new Error(msg);
    }
    return res.json();
}

// Translate an Alba puck_mapping field definition into a Puck field config.
// Phase 1 supports text/textarea/number/select. Extend here when wiring more
// widget types.
function albaFieldToPuckField(albaField) {
    const type = (albaField && albaField.type) || "text";
    const base = { label: albaField.label };
    if (type === "select" && Array.isArray(albaField.options)) {
        return { ...base, type: "select", options: albaField.options };
    }
    if (type === "number") {
        return { ...base, type: "number" };
    }
    if (type === "textarea") {
        return { ...base, type: "textarea" };
    }
    return { ...base, type: "text" };
}

function albaFieldsToPuckFields(fieldsObj) {
    const out = {};
    for (const [name, def] of Object.entries(fieldsObj || {})) {
        out[name] = albaFieldToPuckField(def);
    }
    return out;
}

// HTML widget renders trivially client-side (it's already HTML). For widgets
// that need server-side rendering we fall back to a fetch against
// /puck_api/render_inline and inject the returned markup.
function makeRenderForSchema(schema) {
    if (schema.widgetLocation === "html") {
        return function HtmlWidgetRender(props) {
            return h("div", {
                className: "alba-puck-html-widget",
                dangerouslySetInnerHTML: { __html: props.markup ?? "" },
            });
        };
    }
    return function ServerRenderedWidget(props) {
        const [html, setHtml] = useState("<em>Loading preview…</em>");
        const propsKey = JSON.stringify(props);
        useEffect(() => {
            let cancelled = false;
            api("render_inline", {
                method: "POST",
                body: JSON.stringify({
                    widgetLocation: schema.widgetLocation,
                    props,
                }),
            })
                .then((r) => {
                    if (!cancelled) setHtml(r.html || "");
                })
                .catch((err) => {
                    if (!cancelled)
                        setHtml(
                            `<div class="puck-render-error">Preview failed: ${err.message}</div>`,
                        );
                });
            return () => {
                cancelled = true;
            };
        }, [propsKey]);
        return h("div", {
            className: `alba-puck-widget alba-puck-widget-${schema.widgetLocation}`,
            dangerouslySetInnerHTML: { __html: html },
        });
    };
}

function buildConfig(schemas, zones) {
    const components = {};
    const categories = {};
    for (const schema of schemas) {
        components[schema.puckComponentName] = {
            label: schema.label,
            fields: albaFieldsToPuckFields(schema.fields),
            defaultProps: schema.defaultProps || {},
            render: makeRenderForSchema(schema),
        };
        const catName = schema.category || "Widgets";
        if (!categories[catName]) {
            categories[catName] = { components: [] };
        }
        categories[catName].components.push(schema.puckComponentName);
    }
    return {
        components,
        categories,
        root: {
            render: () =>
                h(
                    "div",
                    { className: "alba-puck-root" },
                    zones.map((zoneName) =>
                        h(
                            "section",
                            {
                                key: zoneName,
                                className: "alba-puck-zone",
                                style: {
                                    border: "1px dashed #aaa",
                                    margin: "0.5rem 0",
                                    padding: "0.5rem",
                                    borderRadius: "4px",
                                },
                            },
                            h(
                                "div",
                                {
                                    style: {
                                        fontSize: "0.75rem",
                                        textTransform: "uppercase",
                                        color: "#666",
                                        marginBottom: "0.25rem",
                                    },
                                },
                                zoneName,
                            ),
                            h(DropZone, { zone: zoneName }),
                        ),
                    ),
                ),
        },
    };
}

function emptyDataForZones(zones) {
    const data = { content: [], root: { props: {} }, zones: {} };
    for (const z of zones) data.zones[`root:${z}`] = [];
    return data;
}

function EditorApp({ initialData, schemas, zones, hasDraft }) {
    const config = useMemo(() => buildConfig(schemas, zones), [schemas, zones]);
    const data = initialData || emptyDataForZones(zones);
    const latestData = useRef(data);
    const [savingState, setSavingState] = useState(
        hasDraft ? "Loaded draft" : "New layout",
    );

    const persist = async (puckData) => {
        latestData.current = puckData;
        setSavingState("Saving draft…");
        try {
            await api(`save/${cfg.pageId}`, {
                method: "POST",
                body: JSON.stringify({ data: puckData }),
            });
            setSavingState(`Draft saved at ${new Date().toLocaleTimeString()}`);
        } catch (err) {
            setSavingState(`Save failed: ${err.message}`);
        }
    };

    // Debounced auto-save on change.
    const saveTimer = useRef(null);
    const handleChange = (puckData) => {
        latestData.current = puckData;
        if (saveTimer.current) clearTimeout(saveTimer.current);
        saveTimer.current = setTimeout(() => persist(puckData), 1500);
    };

    const handlePublish = async (puckData) => {
        latestData.current = puckData;
        setSavingState("Publishing…");
        try {
            const res = await api(`publish/${cfg.pageId}`, {
                method: "POST",
                body: JSON.stringify({ data: puckData }),
            });
            const positions = (res.publishedPositions || []).join(", ") || "(none)";
            const errors = res.errors && res.errors.length
                ? `\nWarnings:\n - ${res.errors.join("\n - ")}`
                : "";
            setSavingState(`Published positions: ${positions}`);
            alert(`Published.\nPositions: ${positions}${errors}`);
        } catch (err) {
            setSavingState(`Publish failed: ${err.message}`);
            alert(`Publish failed: ${err.message}`);
        }
    };

    const handleDiscard = async () => {
        if (!confirm("Discard the current draft? This cannot be undone.")) return;
        try {
            await api(`discard/${cfg.pageId}`, { method: "POST" });
            window.location.reload();
        } catch (err) {
            alert(`Discard failed: ${err.message}`);
        }
    };

    useEffect(() => {
        // Wire Puck status into the toolbar.
        setStatus(savingState);
    }, [savingState]);

    return h(
        Puck,
        {
            config,
            data,
            onChange: handleChange,
            onPublish: handlePublish,
            headerTitle: "Visual Editor",
            overrides: {
                headerActions: ({ children }) =>
                    h(
                        React.Fragment,
                        null,
                        h(
                            "button",
                            {
                                type: "button",
                                onClick: handleDiscard,
                                style: {
                                    marginRight: "0.5rem",
                                    padding: "0.4rem 0.75rem",
                                    background: "#eee",
                                    border: "1px solid #ccc",
                                    borderRadius: "4px",
                                    cursor: "pointer",
                                },
                            },
                            "Discard draft",
                        ),
                        children,
                    ),
            },
        },
    );
}

async function bootstrap() {
    const root = document.getElementById("puck-root");
    try {
        setStatus("Fetching schemas…");
        const [schemasRes, loadRes] = await Promise.all([
            api("schemas"),
            api(`load/${cfg.pageId}`),
        ]);
        setStatus("Mounting editor…");
        createRoot(root).render(
            h(EditorApp, {
                schemas: schemasRes.schemas || [],
                zones: loadRes.zones || [],
                initialData: loadRes.draft,
                hasDraft: !!loadRes.hasDraft,
            }),
        );
        setStatus(loadRes.hasDraft ? "Loaded draft" : "New layout");
    } catch (err) {
        root.innerHTML = `<div class="puck-render-error" style="margin:1rem">Editor failed to start: ${err.message}</div>`;
        setStatus(`Error: ${err.message}`);
        console.error(err);
    }
}

bootstrap();
