function createElem(type, attributes) {
    var elem = document.createElement(type);
    for (var i in attributes || {}) {
        elem.setAttribute(i, attributes[i]);
    }
    return elem;
}

function cookieValue(name) {
    return document.cookie
        .split("; ")
        .find((row) => row.startsWith(name + "="))
        ?.split("=")
        .slice(1)
        .join("=") || "";
}

function safeDecode(value) {
    try {
        return decodeURIComponent(value || "");
    } catch (error) {
        return value || "";
    }
}

function labels() {
    const isFrench = document.documentElement.lang === "fr";
    return {
        account: isFrench ? "Compte" : "Account",
        apiKeyCopyFallback: isFrench ? "Sélectionne la clef et copie-la manuellement." : "Select the key and copy it manually.",
        apiKeyCopied: isFrench ? "Clef API copiée." : "API key copied.",
        apiKeyGenerating: isFrench ? "Génération de la clef API..." : "Generating API key...",
        apiKeyHelp: isFrench
            ? "Cette clef peut être utilisée comme Bearer token sur les endpoints API. Elle est affichée une seule fois."
            : "This key can be used as a Bearer token on API endpoints. It is shown only once.",
        apiKeyReady: isFrench ? "Clef API prête. Elle est affichée une seule fois." : "API key ready. It is shown only once.",
        apiKeyTitle: isFrench ? "Clef d'API" : "API key",
        close: isFrench ? "Fermer" : "Close",
        copyApiKey: isFrench ? "Copier la clef" : "Copy key",
        logout: isFrench ? "Déconnexion" : "Log out",
        requestDenied: isFrench ? "Requête refusée" : "Request denied",
        retrieveApiKey: isFrench ? "Récupérer une clef d'API" : "Retrieve an API key",
    };
}

function sessionToken() {
    return safeDecode(cookieValue("vn_session"));
}

async function apiJson(path, options) {
    const token = sessionToken();
    const response = await fetch(path, {
        ...options,
        headers: {
            "Authorization": token ? "Bearer " + token : "",
            "Content-Type": "application/json",
            ...(options && options.headers ? options.headers : {}),
        },
    });
    const text = await response.text();
    const data = text ? JSON.parse(text) : null;
    if (!response.ok) {
        const message = data && data.error && data.error.message
            ? data.error.message
            : labels().requestDenied;
        throw new Error(message);
    }

    return data;
}

function escapeHtml(value) {
    return String(value ?? "")
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll("\"", "&quot;")
        .replaceAll("'", "&#039;");
}

let i18nPromise = null;

function loadI18n() {
    if (window.VNG_I18N && typeof window.VNG_I18N === "object") {
        return Promise.resolve(window.VNG_I18N);
    }
    if (i18nPromise) {
        return i18nPromise;
    }

    const preload = document.querySelector("link[rel=\"preload\"][as=\"fetch\"]");
    const url = preload?.getAttribute("href") || "/i18n?lang=" + encodeURIComponent(document.documentElement.lang || "en");
    i18nPromise = fetch(url, {"headers": {"Accept": "application/json"}})
        .then((response) => response.ok ? response.json() : {})
        .then((messages) => {
            window.VNG_I18N = messages && typeof messages === "object" ? messages : {};
            return window.VNG_I18N;
        })
        .catch(() => ({}));

    return i18nPromise;
}

function t(messages, key, fallback) {
    return messages && Object.prototype.hasOwnProperty.call(messages, key)
        ? messages[key]
        : fallback;
}

function formatText(template, values) {
    return Object.entries(values || {}).reduce(
        (text, [key, value]) => text.replaceAll("{" + key + "}", String(value)),
        String(template || "")
    );
}

function validRelativeCoordinates(target) {
    return target
        && Number.isFinite(Number(target.x))
        && Number.isFinite(Number(target.y))
        && Number.isFinite(Number(target.z))
        && (Number(target.x) + Number(target.y) + Number(target.z)) % 2 === 0;
}

function coordinate(value) {
    return value ? value.x + ":" + value.y + ":" + value.z : "-";
}

function numberValue(value, suffix) {
    const number = Number(value);
    if (!Number.isFinite(number)) {
        return "-";
    }

    return number.toFixed(2).replace(/\.?0+$/, "") + (suffix || "");
}

function duration(seconds, translate) {
    const number = Number(seconds);
    const text = typeof translate === "function" ? translate : ((key, fallback) => fallback);
    if (!Number.isFinite(number) || number < 0) {
        return "-";
    }
    if (number < 60) {
        return Math.round(number) + " " + text("secondsShort", "s");
    }

    const hours = Math.floor(number / 3600);
    const minutes = Math.floor((number % 3600) / 60);
    const remainingSeconds = Math.round(number % 60);
    return [
        hours > 0 ? hours + " h" : "",
        minutes > 0 ? minutes + " min" : "",
        hours === 0 ? remainingSeconds + " " + text("secondsShort", "s") : "",
    ].filter(Boolean).join(" ");
}

function detailList(items) {
    return "<dl>" + items.map((item) => (
        "<div><dt>" + escapeHtml(item.label) + "</dt><dd>"
        + (Object.prototype.hasOwnProperty.call(item, "htmlValue") ? String(item.htmlValue ?? "") : escapeHtml(item.value))
        + "</dd></div>"
    )).join("") + "</dl>";
}

function metricHtml(metric) {
    const valueClass = metric.valueClass ? " class=\"" + escapeHtml(metric.valueClass) + "\"" : "";
    const valueId = metric.valueId ? " id=\"" + escapeHtml(metric.valueId) + "\"" : "";
    const extraAttributes = metric.valueAttributes ? " " + metric.valueAttributes : "";
    const metricName = metric.name ? " data-metric=\"" + escapeHtml(metric.name) + "\"" : "";
    const content = "<span>" + escapeHtml(metric.label) + "</span><b" + valueId + valueClass + extraAttributes + ">"
        + escapeHtml(String(metric.value ?? "-")) + "</b>";

    if (!metric.detail) {
        return "<div class=\"metric\"" + metricName + ">" + content + "</div>";
    }

    return "<button class=\"metric interactive-metric\" type=\"button\" aria-expanded=\"false\"" + metricName + ">"
        + content
        + "<span class=\"metric-detail\" role=\"status\">" + metric.detail + "</span>"
        + "</button>";
}

function bindMetricDetails(root) {
    (root || document).querySelectorAll(".interactive-metric").forEach((metricNode) => {
        if (metricNode.dataset.metricDetailsBound === "1") {
            return;
        }
        metricNode.dataset.metricDetailsBound = "1";
        metricNode.addEventListener("click", () => {
            const expanded = metricNode.getAttribute("aria-expanded") === "true";
            document.querySelectorAll(".interactive-metric[aria-expanded=\"true\"]").forEach((openNode) => {
                if (openNode !== metricNode) {
                    openNode.setAttribute("aria-expanded", "false");
                }
            });
            metricNode.setAttribute("aria-expanded", expanded ? "false" : "true");
        });
    });
}

function renderMetrics(container, metrics) {
    if (!container) {
        return;
    }

    container.innerHTML = metrics.map(metricHtml).join("");
    bindMetricDetails(container);
}

function collectRefreshTimes(value, observedAt, times) {
    if (!value || typeof value !== "object") {
        return times;
    }

    Object.entries(value).forEach(([key, item]) => {
        const normalizedKey = key.toLowerCase();
        if (typeof item === "number" && (
            normalizedKey.endsWith("secondsremaining")
            || normalizedKey.endsWith("remainingseconds")
            || normalizedKey === "refreshafterseconds"
            || normalizedKey === "nextrefreshseconds"
        )) {
            times.push(observedAt + Math.max(0, item) * 1000);
            return;
        }

        if (typeof item === "string" && (
            normalizedKey.endsWith("endsat")
            || normalizedKey.endsWith("endat")
            || normalizedKey.endsWith("dueat")
            || normalizedKey.endsWith("runat")
            || normalizedKey.endsWith("arrivalat")
            || normalizedKey === "estimatedcompletionat"
            || normalizedKey === "taskestimatedendtime"
        )) {
            const timestamp = Date.parse(item);
            if (Number.isFinite(timestamp)) {
                times.push(timestamp);
            }
        }

        if (item && typeof item === "object") {
            collectRefreshTimes(item, observedAt, times);
        }
    });

    return times;
}

function nextRefreshDelay(payload, defaultDelayMs, minimumDelayMs, cushionMs) {
    const observedAt = Date.now();
    const defaultDelay = Number.isFinite(Number(defaultDelayMs)) ? Number(defaultDelayMs) : 15000;
    const minimumDelay = Number.isFinite(Number(minimumDelayMs)) ? Number(minimumDelayMs) : 500;
    const cushion = Number.isFinite(Number(cushionMs)) ? Number(cushionMs) : 500;
    const futureTimes = collectRefreshTimes(payload, observedAt, [])
        .filter((timestamp) => Number.isFinite(timestamp) && timestamp >= observedAt)
        .sort((a, b) => a - b);

    if (futureTimes.length === 0) {
        return defaultDelay;
    }

    return Math.max(minimumDelay, Math.min(defaultDelay, futureTimes[0] - observedAt + cushion));
}

window.VNG = {
    ...(window.VNG || {}),
    apiJson,
    bindMetricDetails,
    coordinate,
    detailList,
    duration,
    escapeHtml,
    formatText,
    labels,
    loadI18n,
    metricHtml,
    nextRefreshDelay,
    numberValue,
    renderMetrics,
    t,
    validRelativeCoordinates,
};
window.dispatchEvent(new CustomEvent("VNGReady"));

function showDialog(dialog) {
    if (!dialog) {
        return;
    }
    dialog.hidden = false;
    if (typeof dialog.showModal === "function" && !dialog.open) {
        dialog.showModal();
    }
}

function closeDialog(dialog) {
    if (!dialog) {
        return;
    }
    if (typeof dialog.close === "function" && dialog.open) {
        dialog.close();
    }
    dialog.hidden = true;
}

function bindAccountMenus() {
    const closeAccountMenus = () => {
        document.querySelectorAll(".account-menu-button[aria-expanded=\"true\"]").forEach((button) => {
            button.setAttribute("aria-expanded", "false");
            const panel = button.closest(".account-menu")?.querySelector(".account-menu-panel");
            if (panel) {
                panel.hidden = true;
            }
        });
    };

    document.querySelectorAll(".account-menu-button").forEach((button) => {
        if (button.dataset.accountMenuBound === "1") {
            return;
        }
        button.dataset.accountMenuBound = "1";
        button.addEventListener("click", (event) => {
            event.stopPropagation();
            const panel = button.closest(".account-menu")?.querySelector(".account-menu-panel");
            const willOpen = button.getAttribute("aria-expanded") !== "true";
            closeAccountMenus();
            button.setAttribute("aria-expanded", willOpen ? "true" : "false");
            if (panel) {
                panel.hidden = !willOpen;
            }
        });
    });

    if (document.body.dataset.accountMenuDocumentBound !== "1") {
        document.body.dataset.accountMenuDocumentBound = "1";
        document.addEventListener("click", (event) => {
            if (!event.target.closest(".account-menu")) {
                closeAccountMenus();
            }
        });
    }

    return closeAccountMenus;
}

function ensureApiKeyDialog(copyLabel) {
    let dialog = document.getElementById("api-key-dialog");
    if (dialog) {
        return dialog;
    }

    const text = labels();
    dialog = createElem("dialog", {
        "id": "api-key-dialog",
        "class": "api-key-dialog",
        "hidden": "",
    });

    const closeButton = createElem("button", {
        "class": "dialog-close icon-button",
        "type": "button",
        "aria-label": text.close,
    });
    closeButton.textContent = "×";
    closeButton.addEventListener("click", () => closeDialog(dialog));

    const eyebrow = createElem("p", {"class": "eyebrow"});
    eyebrow.textContent = "API";
    const title = createElem("h2");
    title.textContent = text.apiKeyTitle;
    const help = createElem("p");
    help.textContent = text.apiKeyHelp;
    const value = createElem("textarea", {
        "id": "api-key-value",
        "readonly": "",
        "rows": "3",
    });
    const actions = createElem("div", {"class": "api-key-actions"});
    const copy = createElem("button", {
        "id": "copy-api-key",
        "type": "button",
    });
    copy.textContent = copyLabel || text.copyApiKey;
    const close = createElem("button", {"type": "button"});
    close.textContent = text.close;
    close.addEventListener("click", () => closeDialog(dialog));
    const status = createElem("p", {
        "id": "api-key-status",
        "class": "action-status",
    });

    actions.appendChild(copy);
    actions.appendChild(close);
    dialog.appendChild(closeButton);
    dialog.appendChild(eyebrow);
    dialog.appendChild(title);
    dialog.appendChild(help);
    dialog.appendChild(value);
    dialog.appendChild(actions);
    dialog.appendChild(status);
    document.body.appendChild(dialog);

    copy.addEventListener("click", async () => {
        if (!value.value) {
            return;
        }
        try {
            await navigator.clipboard.writeText(value.value);
            status.textContent = text.apiKeyCopied;
        } catch (error) {
            value.focus();
            value.select();
            status.textContent = text.apiKeyCopyFallback;
        }
    });

    dialog.addEventListener("close", () => {
        dialog.hidden = true;
    });

    return dialog;
}

function createSessionBar() {
    const text = labels();
    const sessionbar = createElem("div", {"class": "sessionbar"});
    const accountmenu = createElem("div", {"class": "account-menu"});
    const accountmenubouton = createElem("button", {
        "class": "account-menu-button",
        "type": "button",
        "aria-expanded": "false",
    });
    const spanOperator = createElem("span", {"class": "operator"});
    spanOperator.textContent = text.account;
    const spanFleche = createElem("span", {"aria-hidden": "true"});
    spanFleche.textContent = "▾";
    const panel = createElem("div", {
        "class": "account-menu-panel",
        "hidden": "",
    });
    const apiKeyButton = createElem("button", {
        "class": "account-menu-item",
        "data-api-key-action": "",
        "type": "button",
    });
    apiKeyButton.textContent = text.retrieveApiKey;
    const logoutForm = createElem("form", {
        "class": "logout-form",
        "method": "post",
        "action": "/logout",
    });
    const logoutButton = createElem("button", {"type": "submit"});
    logoutButton.textContent = text.logout;

    accountmenubouton.appendChild(spanOperator);
    accountmenubouton.appendChild(spanFleche);
    panel.appendChild(apiKeyButton);
    accountmenu.appendChild(accountmenubouton);
    accountmenu.appendChild(panel);
    logoutForm.appendChild(logoutButton);
    sessionbar.appendChild(accountmenu);
    sessionbar.appendChild(logoutForm);

    return {
        apiKeyButton,
        sessionbar,
        spanOperator,
    };
}

function bindApiKeyButton(button, closeAccountMenus) {
    button.addEventListener("click", async () => {
        const text = labels();
        closeAccountMenus();
        const dialog = ensureApiKeyDialog(text.copyApiKey);
        const value = document.getElementById("api-key-value");
        const status = document.getElementById("api-key-status");

        if (value) {
            value.value = "";
        }
        if (status) {
            status.textContent = text.apiKeyGenerating;
        }
        showDialog(dialog);

        try {
            const data = await apiJson("/api/me/api-key", {
                "method": "POST",
                "body": JSON.stringify({}),
            });
            if (value) {
                value.value = data.apiKey && data.apiKey.token ? data.apiKey.token : "";
                value.focus();
                value.select();
            }
            if (status) {
                status.textContent = text.apiKeyReady;
            }
        } catch (error) {
            if (status) {
                status.textContent = error.message;
            }
        }
    });
}

async function loadCurrentPlayer(spanOperator) {
    try {
        const data = await apiJson("/api/me", {"method": "GET"});
        const player = data && data.player ? data.player : {};
        spanOperator.textContent = player.displayName || player.username || labels().account;
    } catch (error) {
        spanOperator.textContent = labels().account;
    }
}

document.addEventListener("DOMContentLoaded", () => {
    if (document.body.dataset.authenticated !== "1") {
        bindAccountMenus();
        return;
    }

    const header = document.querySelector("header.topbar");
    if (!header || header.querySelector(".sessionbar")) {
        bindAccountMenus();
        return;
    }

    const {apiKeyButton, sessionbar, spanOperator} = createSessionBar();
    header.appendChild(sessionbar);
    const closeAccountMenus = bindAccountMenus();
    bindApiKeyButton(apiKeyButton, closeAccountMenus);
    loadCurrentPlayer(spanOperator);
});
