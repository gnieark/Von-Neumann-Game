(function () {
    const MESSAGE_PAGE_SIZE = 50;
    const DEFAULT_REFRESH_MS = 15000;

    const state = {
        "currentMessageFolder": "received",
        "currentSectorProbes": [],
        "receivedMessages": [],
        "receivedMessagePagination": null,
        "sentMessages": [],
        "sentMessagePagination": null,
    };

    let i18n = {};
    let refreshTimer = null;
    let loadInProgress = false;

    function withVng(callback) {
        if (window.VNG) {
            callback(window.VNG);
            return;
        }

        window.addEventListener("VNGReady", () => callback(window.VNG), {"once": true});
    }

    function tr(key, fallback) {
        return window.VNG.t(i18n, key, fallback);
    }

    function setText(id, value) {
        const node = document.getElementById(id);
        if (node) {
            node.textContent = value;
        }
    }

    function normalizeMessageFolder(folder) {
        return folder === "sent" ? "sent" : "received";
    }

    function messagesForFolder(folder) {
        return normalizeMessageFolder(folder) === "sent" ? state.sentMessages : state.receivedMessages;
    }

    function paginationForFolder(folder) {
        return normalizeMessageFolder(folder) === "sent" ? state.sentMessagePagination : state.receivedMessagePagination;
    }

    function messageEndpointForFolder(folder) {
        return normalizeMessageFolder(folder) === "sent" ? "/api/probe/messages/sent" : "/api/probe/messages";
    }

    function setMessagesForFolder(folder, messages, append) {
        if (normalizeMessageFolder(folder) === "sent") {
            state.sentMessages = append ? state.sentMessages.concat(messages) : messages;
            return;
        }

        state.receivedMessages = append ? state.receivedMessages.concat(messages) : messages;
    }

    function setPaginationForFolder(folder, pagination) {
        if (normalizeMessageFolder(folder) === "sent") {
            state.sentMessagePagination = pagination;
            return;
        }

        state.receivedMessagePagination = pagination;
    }

    function syncMessageWarning() {
        const hasUnreadMessages = state.receivedMessages.some((message) => message && message.status === "unread");
        window.VNG.setNavigationWarning("/messaging", hasUnreadMessages);
    }

    function sectorProbeRecipients() {
        return Array.isArray(state.currentSectorProbes)
            ? state.currentSectorProbes.filter((probe) => probe && probe.id)
            : [];
    }

    function renderMessageRecipients() {
        const select = document.getElementById("message-recipient");
        const submit = document.getElementById("message-submit");
        if (!select) {
            return;
        }

        const previousValue = select.value;
        const probes = sectorProbeRecipients();
        if (probes.length === 0) {
            select.innerHTML = "<option value=\"\">" + window.VNG.escapeHtml(tr("noMessageRecipients", "No other probe detected in the sector.")) + "</option>";
            select.disabled = true;
            if (submit) {
                submit.disabled = true;
                submit.setAttribute("aria-disabled", "true");
            }
            return;
        }

        select.innerHTML = probes.map((probe) => (
            "<option value=\"" + window.VNG.escapeHtml(probe.id) + "\">" + window.VNG.escapeHtml(probe.name || tr("unknownProbe", "Unknown probe")) + "</option>"
        )).join("");
        if (previousValue && probes.some((probe) => String(probe.id) === previousValue)) {
            select.value = previousValue;
        }
        select.disabled = false;
        if (submit) {
            submit.disabled = false;
            submit.setAttribute("aria-disabled", "false");
        }
    }

    function formatMessageDate(value) {
        if (!value) {
            return "-";
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        return new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
            "dateStyle": "short",
            "timeStyle": "short",
        }).format(date);
    }

    function syncMessageFolderTabs() {
        const activeFolder = normalizeMessageFolder(state.currentMessageFolder);
        document.querySelectorAll("[data-message-folder]").forEach((button) => {
            const isActive = normalizeMessageFolder(button.dataset.messageFolder) === activeFolder;
            button.classList.toggle("active", isActive);
            button.setAttribute("aria-selected", isActive ? "true" : "false");
            button.setAttribute("tabindex", isActive ? "0" : "-1");
        });

        const list = document.getElementById("messages-list");
        if (list) {
            list.setAttribute("aria-labelledby", activeFolder === "sent" ? "messages-sent-tab" : "messages-received-tab");
        }

        const empty = document.getElementById("messages-empty");
        if (empty) {
            empty.textContent = activeFolder === "sent"
                ? tr("noSentMessages", "No sent messages.")
                : tr("noReceivedMessages", "No received messages.");
        }
    }

    function renderMessages() {
        const activeFolder = normalizeMessageFolder(state.currentMessageFolder);
        const list = document.getElementById("messages-list");
        const empty = document.getElementById("messages-empty");
        const loadMore = document.getElementById("messages-load-more");
        syncMessageFolderTabs();
        if (!list) {
            syncMessageWarning();
            return;
        }

        const messages = Array.isArray(messagesForFolder(activeFolder)) ? messagesForFolder(activeFolder) : [];
        const pagination = paginationForFolder(activeFolder);
        if (empty) {
            empty.hidden = messages.length > 0;
        }
        if (loadMore) {
            loadMore.hidden = !(pagination && pagination.hasMore);
        }

        list.innerHTML = messages.map((message) => {
            const counterpart = activeFolder === "sent" ? (message.recipient || {}) : (message.sender || {});
            const sector = message.sector && message.sector.relative ? message.sector.relative : null;
            const statusIsUnread = message.status === "unread";
            const isUnread = activeFolder === "received" && statusIsUnread;
            const counterpartLabel = activeFolder === "sent" ? tr("messageTo", "To") : tr("messageFrom", "From");
            const statusBadge = activeFolder === "received"
                ? "<span class=\"probe-message-status\">" + window.VNG.escapeHtml(statusIsUnread ? tr("messageUnread", "Unread") : tr("messageRead", "Read")) + "</span>"
                : "";

            return "<article class=\"probe-message " + (isUnread ? "unread" : "read") + "\">"
                + "<div class=\"probe-message-header\">"
                    + "<div>"
                        + "<div class=\"probe-message-sender\">" + window.VNG.escapeHtml(counterpartLabel) + " : " + window.VNG.escapeHtml(counterpart.name || tr("unknownProbe", "Unknown probe")) + "</div>"
                        + "<div class=\"probe-message-meta\">"
                            + "<span>" + window.VNG.escapeHtml(formatMessageDate(message.createdAt)) + "</span>"
                            + "<span>" + window.VNG.escapeHtml(tr("messageSector", "Sector")) + " " + window.VNG.escapeHtml(window.VNG.coordinate(sector)) + "</span>"
                        + "</div>"
                    + "</div>"
                    + statusBadge
                + "</div>"
                + "<p class=\"probe-message-body\">" + window.VNG.escapeHtml(message.body || "") + "</p>"
                + "<div class=\"probe-message-actions\">"
                    + "<button class=\"probe-message-read-button\" data-message-read-id=\"" + window.VNG.escapeHtml(message.id) + "\" type=\"button\"" + (isUnread ? "" : " hidden") + ">"
                        + window.VNG.escapeHtml(tr("markMessageRead", "Mark read"))
                    + "</button>"
                + "</div>"
            + "</article>";
        }).join("");

        syncMessageWarning();
    }

    async function loadMessages(options) {
        const settings = {
            "folder": state.currentMessageFolder,
            "offset": 0,
            "append": false,
            "silent": false,
            ...(options || {}),
        };
        const messageFolder = normalizeMessageFolder(settings.folder);
        const query = new URLSearchParams({
            "limit": String(MESSAGE_PAGE_SIZE),
            "offset": String(settings.offset),
        });

        try {
            const data = await window.VNG.apiJson(messageEndpointForFolder(messageFolder) + "?" + query.toString(), {"method": "GET"});
            const messages = Array.isArray(data.messages) ? data.messages : [];
            setMessagesForFolder(messageFolder, messages, settings.append);
            setPaginationForFolder(messageFolder, data.pagination || null);
            if (messageFolder === normalizeMessageFolder(state.currentMessageFolder)) {
                renderMessages();
            } else {
                syncMessageWarning();
            }
            if (!settings.silent) {
                setText("message-status", "");
            }
        } catch (error) {
            if (!settings.append) {
                setMessagesForFolder(messageFolder, [], false);
                setPaginationForFolder(messageFolder, null);
                renderMessages();
            }
            setText("message-status", error.message || tr("requestDenied", "Request denied"));
        }
    }

    function activateMessageFolder(folder) {
        const nextFolder = normalizeMessageFolder(folder);
        state.currentMessageFolder = nextFolder;
        renderMessages();
        if (messagesForFolder(nextFolder).length === 0 && paginationForFolder(nextFolder) === null) {
            loadMessages({"folder": nextFolder, "silent": true});
        }
    }

    async function markMessageRead(messageId) {
        if (!messageId) {
            return;
        }

        setText("message-status", tr("orderSent", "Order transmitted..."));
        try {
            const response = await window.VNG.apiJson("/api/probe/messages/" + encodeURIComponent(messageId) + "/read", {
                "method": "PATCH",
            });
            const updated = response.message || null;
            if (updated) {
                state.receivedMessages = state.receivedMessages.map((message) => (
                    String(message.id) === String(updated.id) ? updated : message
                ));
            }
            if (normalizeMessageFolder(state.currentMessageFolder) === "received") {
                renderMessages();
            } else {
                syncMessageWarning();
            }
            setText("message-status", tr("messageMarkedRead", "Message marked as read."));
            window.VNG.syncNavigationWarnings();
        } catch (error) {
            setText("message-status", error.message || tr("requestDenied", "Request denied"));
        }
    }

    async function loadCurrentSector() {
        try {
            const data = await window.VNG.apiJson("/api/probe/sector", {"method": "GET"});
            state.currentSectorProbes = Array.isArray(data && data.sector && data.sector.probes) ? data.sector.probes : [];
        } catch (error) {
            state.currentSectorProbes = [];
        }
        renderMessageRecipients();
    }

    function scheduleRefresh() {
        if (refreshTimer !== null) {
            window.clearTimeout(refreshTimer);
        }
        refreshTimer = window.setTimeout(refreshMessagingPage, DEFAULT_REFRESH_MS);
    }

    async function refreshMessagingPage() {
        if (loadInProgress) {
            return;
        }
        loadInProgress = true;
        if (refreshTimer !== null) {
            window.clearTimeout(refreshTimer);
            refreshTimer = null;
        }

        await Promise.all([
            loadCurrentSector(),
            loadMessages({"folder": "received", "silent": true}),
            state.currentMessageFolder === "sent"
                ? loadMessages({"folder": "sent", "silent": true})
                : Promise.resolve(),
        ]);
        scheduleRefresh();
        loadInProgress = false;
    }

    function bindEvents() {
        document.querySelector("[data-refresh=\"messages\"]")?.addEventListener("click", refreshMessagingPage);
        document.querySelectorAll("[data-message-folder]").forEach((button) => {
            button.addEventListener("click", () => {
                activateMessageFolder(button.dataset.messageFolder || "received");
            });
        });
        document.getElementById("messages-load-more")?.addEventListener("click", () => {
            const folder = state.currentMessageFolder;
            const messageList = folder === "sent" ? state.sentMessages : state.receivedMessages;
            loadMessages({
                folder,
                "offset": Array.isArray(messageList) ? messageList.length : 0,
                "append": true,
            });
        });
        document.getElementById("messages-list")?.addEventListener("click", (event) => {
            const button = event.target.closest("[data-message-read-id]");
            if (button) {
                markMessageRead(button.dataset.messageReadId || "");
            }
        });
        document.getElementById("message-form")?.addEventListener("submit", async (event) => {
            event.preventDefault();
            const formNode = event.currentTarget;
            const form = new FormData(formNode);
            const recipientProbeId = Number.parseInt(String(form.get("recipientProbeId") || ""), 10);
            const bodyValue = String(form.get("body") || "").trim();
            if (!Number.isFinite(recipientProbeId) || recipientProbeId <= 0 || bodyValue === "") {
                setText("message-status", tr("requestDenied", "Request denied"));
                return;
            }

            setText("message-status", tr("orderSent", "Order transmitted..."));
            try {
                await window.VNG.apiJson("/api/probe/messages", {
                    "method": "POST",
                    "body": JSON.stringify({
                        recipientProbeId,
                        "body": bodyValue,
                    }),
                });
                formNode.reset();
                renderMessageRecipients();
                state.sentMessages = [];
                state.sentMessagePagination = null;
                setText("message-status", tr("messageSent", "Message transmitted."));
                if (state.currentMessageFolder === "sent") {
                    await loadMessages({"folder": "sent", "silent": true});
                }
                window.VNG.syncNavigationWarnings();
            } catch (error) {
                setText("message-status", error.message || tr("requestDenied", "Request denied"));
            }
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
        if (document.body.dataset.authenticated !== "1" || !document.getElementById("messages-list")) {
            return;
        }

        withVng(async () => {
            i18n = await window.VNG.loadI18n();
            bindEvents();
            refreshMessagingPage();
        });
    });
})();
