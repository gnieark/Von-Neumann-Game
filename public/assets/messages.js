import {coordinate, escapeHtml, formatText, setText} from './utils.js?v=20260606-i18n-external';

export const createMessageModule = ({state, api, labels}) => {
    const {t} = labels;
    const messagePageSize = 50;

    const normalizeMessageFolder = (folder) => (folder === 'sent' ? 'sent' : 'received');
    const messagesForFolder = (folder) => (
        normalizeMessageFolder(folder) === 'sent' ? state.sentMessages : state.receivedMessages
    );
    const paginationForFolder = (folder) => (
        normalizeMessageFolder(folder) === 'sent' ? state.sentMessagePagination : state.receivedMessagePagination
    );
    const messageEndpointForFolder = (folder) => (
        normalizeMessageFolder(folder) === 'sent' ? '/api/probe/messages/sent' : '/api/probe/messages'
    );

    const setMessagesForFolder = (folder, messages, append) => {
        if (normalizeMessageFolder(folder) === 'sent') {
            state.sentMessages = append ? state.sentMessages.concat(messages) : messages;
            return;
        }

        state.receivedMessages = append ? state.receivedMessages.concat(messages) : messages;
    };

    const setPaginationForFolder = (folder, pagination) => {
        if (normalizeMessageFolder(folder) === 'sent') {
            state.sentMessagePagination = pagination;
            return;
        }

        state.receivedMessagePagination = pagination;
    };

    const sectorProbeRecipients = () => (
        Array.isArray(state.currentSectorProbes) ? state.currentSectorProbes.filter((probe) => probe && probe.id) : []
    );

    function renderMessageRecipients() {
        const select = document.getElementById('message-recipient');
        const submit = document.getElementById('message-submit');
        if (!select) {
            return;
        }

        const previousValue = select.value;
        const probes = sectorProbeRecipients();
        if (probes.length === 0) {
            select.innerHTML = '<option value="">' + escapeHtml(t('noMessageRecipients', 'No other probe detected in the sector.')) + '</option>';
            select.disabled = true;
            if (submit) {
                submit.disabled = true;
                submit.setAttribute('aria-disabled', 'true');
            }
            return;
        }

        select.innerHTML = probes.map((probe) => (
            '<option value="' + escapeHtml(probe.id) + '">' + escapeHtml(probe.name || t('unknownProbe', 'Unknown probe')) + '</option>'
        )).join('');
        if (previousValue && probes.some((probe) => String(probe.id) === previousValue)) {
            select.value = previousValue;
        }
        select.disabled = false;
        if (submit) {
            submit.disabled = false;
            submit.setAttribute('aria-disabled', 'false');
        }
    }

    const formatMessageDate = (value) => {
        if (!value) {
            return '-';
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return String(value);
        }

        return new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
            dateStyle: 'short',
            timeStyle: 'short',
        }).format(date);
    };

    function syncMessageTab() {
        const tab = document.getElementById('messages-tab');
        if (!tab) {
            return;
        }

        const messages = Array.isArray(state.receivedMessages) ? state.receivedMessages : [];
        const hasUnreadMessages = messages.some((message) => message && message.status === 'unread');
        tab.classList.toggle('alerts-pending', hasUnreadMessages);
    }

    function syncMessageFolderTabs() {
        const activeFolder = normalizeMessageFolder(state.currentMessageFolder);
        document.querySelectorAll('[data-message-folder]').forEach((button) => {
            const isActive = normalizeMessageFolder(button.dataset.messageFolder) === activeFolder;
            button.classList.toggle('active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            button.setAttribute('tabindex', isActive ? '0' : '-1');
        });

        const list = document.getElementById('messages-list');
        if (list) {
            list.setAttribute('aria-labelledby', activeFolder === 'sent' ? 'messages-sent-tab' : 'messages-received-tab');
        }

        const empty = document.getElementById('messages-empty');
        if (empty) {
            empty.textContent = activeFolder === 'sent'
                ? t('noSentMessages', 'No sent messages.')
                : t('noReceivedMessages', 'No received messages.');
        }
    }

    function renderMessages() {
        const activeFolder = normalizeMessageFolder(state.currentMessageFolder);
        const list = document.getElementById('messages-list');
        const empty = document.getElementById('messages-empty');
        const loadMore = document.getElementById('messages-load-more');
        syncMessageFolderTabs();
        if (!list) {
            syncMessageTab();
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
            const counterpart = activeFolder === 'sent' ? (message.recipient || {}) : (message.sender || {});
            const sector = message.sector && message.sector.relative ? message.sector.relative : null;
            const statusIsUnread = message.status === 'unread';
            const isUnread = activeFolder === 'received' && statusIsUnread;
            const counterpartLabel = activeFolder === 'sent' ? t('messageTo', 'To') : t('messageFrom', 'From');
            const statusBadge = activeFolder === 'received'
                ? '<span class="probe-message-status">' + escapeHtml(statusIsUnread ? t('messageUnread', 'Unread') : t('messageRead', 'Read')) + '</span>'
                : '';

            return '<article class="probe-message ' + (isUnread ? 'unread' : 'read') + '">'
                + '<div class="probe-message-header">'
                    + '<div>'
                        + '<div class="probe-message-sender">' + escapeHtml(counterpartLabel) + ' : ' + escapeHtml(counterpart.name || t('unknownProbe', 'Unknown probe')) + '</div>'
                        + '<div class="probe-message-meta">'
                            + '<span>' + escapeHtml(formatMessageDate(message.createdAt)) + '</span>'
                            + '<span>' + escapeHtml(t('messageSector', 'Sector')) + ' ' + escapeHtml(coordinate(sector)) + '</span>'
                        + '</div>'
                    + '</div>'
                    + statusBadge
                + '</div>'
                + '<p class="probe-message-body">' + escapeHtml(message.body || '') + '</p>'
                + '<div class="probe-message-actions">'
                    + '<button class="probe-message-read-button" data-message-read-id="' + escapeHtml(message.id) + '" type="button"' + (isUnread ? '' : ' hidden') + '>'
                        + escapeHtml(t('markMessageRead', 'Mark read'))
                    + '</button>'
                + '</div>'
            + '</article>';
        }).join('');

        list.querySelectorAll('[data-message-read-id]').forEach((button) => {
            button.addEventListener('click', () => {
                markMessageRead(button.dataset.messageReadId || '');
            });
        });
        syncMessageTab();
    }

    async function loadMessages({folder = state.currentMessageFolder, offset = 0, append = false, silent = false} = {}) {
        const messageFolder = normalizeMessageFolder(folder);
        const query = new URLSearchParams({
            limit: String(messagePageSize),
            offset: String(offset),
        });

        try {
            const data = await api(messageEndpointForFolder(messageFolder) + '?' + query.toString());
            const messages = Array.isArray(data.messages) ? data.messages : [];
            setMessagesForFolder(messageFolder, messages, append);
            setPaginationForFolder(messageFolder, data.pagination || null);
            if (messageFolder === normalizeMessageFolder(state.currentMessageFolder)) {
                renderMessages();
            } else {
                syncMessageTab();
            }
            if (!silent) {
                setText('message-status', '');
            }
        } catch (error) {
            if (!append) {
                setMessagesForFolder(messageFolder, [], false);
                setPaginationForFolder(messageFolder, null);
                renderMessages();
            }
            setText('message-status', error.message);
        }
    }

    function activateMessageFolder(folder) {
        const nextFolder = normalizeMessageFolder(folder);
        state.currentMessageFolder = nextFolder;
        renderMessages();
        if (messagesForFolder(nextFolder).length === 0 && paginationForFolder(nextFolder) === null) {
            loadMessages({folder: nextFolder, silent: true});
        }
    }

    async function markMessageRead(messageId) {
        if (!messageId) {
            return;
        }

        setText('message-status', t('orderSent', 'Order transmitted...'));
        try {
            const response = await api('/api/probe/messages/' + encodeURIComponent(messageId) + '/read', {
                method: 'PATCH',
            });
            const updated = response.message || null;
            if (updated) {
                state.receivedMessages = state.receivedMessages.map((message) => (
                    String(message.id) === String(updated.id) ? updated : message
                ));
            }
            if (normalizeMessageFolder(state.currentMessageFolder) === 'received') {
                renderMessages();
            } else {
                syncMessageTab();
            }
            setText('message-status', t('messageMarkedRead', 'Message marked as read.'));
        } catch (error) {
            setText('message-status', error.message);
        }
    }

    return {
        messagePageSize,
        renderMessageRecipients,
        renderMessages,
        loadMessages,
        activateMessageFolder,
        markMessageRead,
    };
};
