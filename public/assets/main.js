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
