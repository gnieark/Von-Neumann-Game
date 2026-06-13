(function () {
    const POSTS_PER_CATEGORY = 6;
    const MESSAGES_PER_PAGE = 50;

    const state = {
        "categories": [],
        "postsByCategory": {},
        "currentPost": null,
        "currentFirstMessage": null,
        "currentMessages": [],
        "currentMessagePagination": null,
        "currentPlayer": null,
        "editingMessageId": null,
    };

    let i18n = {};

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

    function escaped(value) {
        return window.VNG.escapeHtml(value ?? "");
    }

    function escapedSelectorValue(value) {
        if (window.CSS && typeof window.CSS.escape === "function") {
            return window.CSS.escape(String(value));
        }

        return String(value).replace(/\\/g, "\\\\").replace(/"/g, "\\\"");
    }

    function setStatus(value) {
        const node = document.getElementById("forum-status");
        if (node) {
            node.textContent = value || "";
        }
    }

    function formatDate(value) {
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

    function authorName(author) {
        return author && (author.displayName || author.username)
            ? (author.displayName || author.username)
            : "-";
    }

    function messageCountLabel(count) {
        const number = Number(count || 0);
        return window.VNG.formatText(
            tr(number > 1 ? "forumMessageCountPlural" : "forumMessageCount", number > 1 ? "{count} messages" : "{count} message"),
            {"count": number}
        );
    }

    function categoryById(categoryId) {
        return state.categories.find((category) => String(category.id) === String(categoryId)) || null;
    }

    function canEditMessage(message) {
        const player = state.currentPlayer || {};
        return Boolean(
            message
            && player.id
            && (
                String(message.author && message.author.playerId) === String(player.id)
                || player.forumAdmin === true
                || player.forumModerator === true
            )
        );
    }

    function renderPostTile(post) {
        const author = authorName(post.author || {});
        const authorLabel = window.VNG.formatText(tr("forumAuthorPrefix", "by {author}"), {"author": author});
        const pinned = post.pinned
            ? "<span class=\"forum-pinned-badge\">" + escaped(tr("forumPinned", "Pinned")) + "</span>"
            : "";

        return "<button class=\"forum-post-tile\" data-forum-post-id=\"" + escaped(post.id) + "\" type=\"button\">"
            + pinned
            + "<h4>" + escaped(post.title || "") + "</h4>"
            + "<div class=\"forum-post-meta\">"
                + "<span>" + escaped(authorLabel) + "</span>"
                + "<span>" + escaped(messageCountLabel(post.messageCount)) + "</span>"
                + "<span>" + escaped(formatDate(post.lastMessageAt || post.updatedAt || post.createdAt)) + "</span>"
            + "</div>"
        + "</button>";
    }

    function renderForumMessage(message, isFirstMessage) {
        const author = window.VNG.formatText(tr("forumAuthorPrefix", "by {author}"), {"author": authorName(message.author || {})});
        const editedLabel = message.editedAt
            ? "<span>" + escaped(window.VNG.formatText(tr("forumEditedAt", "edited on {date}"), {"date": formatDate(message.editedAt)})) + "</span>"
            : "";
        const firstLabel = isFirstMessage
            ? "<span class=\"forum-initial-message-badge\">" + escaped(tr("forumInitialMessage", "Initial post")) + "</span>"
            : "";
        const actions = canEditMessage(message)
            ? "<div class=\"forum-message-actions\">"
                + "<button data-forum-edit-message=\"" + escaped(message.id) + "\" type=\"button\">" + escaped(tr("forumEditMessage", "Edit")) + "</button>"
            + "</div>"
            : "";
        const isEditing = String(state.editingMessageId || "") === String(message.id);
        const body = isEditing
            ? "<form class=\"forum-message-edit-form\" data-forum-edit-form=\"" + escaped(message.id) + "\">"
                + "<label>" + escaped(tr("forumPostBody", "Message"))
                    + "<textarea name=\"body\" rows=\"4\" maxlength=\"5000\" required>" + escaped(message.body || "") + "</textarea>"
                + "</label>"
                + "<div class=\"forum-message-edit-actions\">"
                    + "<button class=\"primary\" type=\"submit\">" + escaped(tr("forumSaveEdit", "Save")) + "</button>"
                    + "<button data-forum-cancel-edit type=\"button\">" + escaped(tr("forumCancelEdit", "Cancel")) + "</button>"
                + "</div>"
            + "</form>"
            : "<p>" + escaped(message.body || "") + "</p>";

        return "<article class=\"forum-message" + (isFirstMessage ? " forum-message-initial" : "") + "\">"
            + "<div class=\"forum-message-meta\">"
                + firstLabel
                + "<span>" + escaped(author) + "</span>"
                + "<span>" + escaped(formatDate(message.createdAt)) + "</span>"
                + editedLabel
            + "</div>"
            + body
            + (isEditing ? "" : actions)
        + "</article>";
    }

    function renderMessageList() {
        const firstMessage = state.currentFirstMessage ? renderForumMessage(state.currentFirstMessage, true) : "";
        const replies = state.currentMessages.length > 0
            ? state.currentMessages.map((message) => renderForumMessage(message, false)).join("")
            : "<p class=\"sector-context\">" + escaped(tr("forumNoReplies", "No reply in this thread yet.")) + "</p>";

        return firstMessage + replies;
    }

    function renderInlineThread(category) {
        const post = state.currentPost;
        if (!post) {
            return "";
        }

        const meta = [
            window.VNG.formatText(tr("forumAuthorPrefix", "by {author}"), {"author": authorName(post.author || {})}),
            messageCountLabel(post.messageCount),
            formatDate(post.lastMessageAt || post.updatedAt || post.createdAt),
        ].join(" · ");
        const loadMore = state.currentMessagePagination && state.currentMessagePagination.hasMore
            ? "<button data-forum-message-load-more type=\"button\">" + escaped(tr("forumOlderMessages", "Show older messages")) + "</button>"
            : "";

        return "<div class=\"forum-inline-thread\">"
            + "<div class=\"forum-thread-nav\">"
                + "<button data-forum-back-to-category type=\"button\">" + escaped(tr("forumBackToCategory", "Back")) + "</button>"
            + "</div>"
            + "<div class=\"forum-thread-heading\">"
                + "<div>"
                    + "<p class=\"eyebrow\">" + escaped(category.name || tr("tabForum", "Forum")) + "</p>"
                    + "<h3>" + escaped(post.title || "") + "</h3>"
                    + "<p class=\"forum-muted\">" + escaped(meta) + "</p>"
                + "</div>"
            + "</div>"
            + "<div class=\"forum-thread-messages\">"
                + renderMessageList()
            + "</div>"
            + loadMore
            + "<form class=\"forum-reply-form\" data-forum-reply-form>"
                + "<label>" + escaped(tr("forumReplyBody", "Reply"))
                    + "<textarea name=\"body\" rows=\"4\" maxlength=\"5000\" required></textarea>"
                + "</label>"
                + "<button class=\"primary\" type=\"submit\">" + escaped(tr("forumSendReply", "Reply")) + "</button>"
            + "</form>"
        + "</div>";
    }

    function renderCategory(category) {
        const bucket = state.postsByCategory[String(category.id)] || {"items": [], "pagination": null};
        const posts = Array.isArray(bucket.items) ? bucket.items : [];
        const pagination = bucket.pagination || null;
        const postHtml = posts.length > 0
            ? posts.map(renderPostTile).join("")
            : "<p class=\"sector-context\">" + escaped(tr("forumNoPosts", "No post in this category.")) + "</p>";
        const loadNewer = pagination && Number(pagination.offset || 0) > 0
            ? "<button class=\"forum-load-more\" data-forum-load-newer-category=\"" + escaped(category.id) + "\" type=\"button\">"
                + escaped(tr("forumNewerPosts", "Show newer posts"))
            + "</button>"
            : "";
        const loadMore = pagination && pagination.hasMore
            ? "<button class=\"forum-load-more\" data-forum-load-category=\"" + escaped(category.id) + "\" type=\"button\">"
                + escaped(tr("forumOlderPosts", "Show older posts"))
            + "</button>"
            : "";
        const description = category.description
            ? "<p class=\"forum-category-description\">" + escaped(category.description) + "</p>"
            : "";

        return "<section class=\"forum-category\" data-forum-category-id=\"" + escaped(category.id) + "\">"
            + "<div class=\"forum-category-heading\">"
                + "<div>"
                    + "<h3>" + escaped(category.name || "") + "</h3>"
                    + description
                + "</div>"
            + "</div>"
            + "<details class=\"forum-post-composer\">"
                + "<summary>" + escaped(tr("forumNewPost", "New post")) + "</summary>"
                + "<form class=\"forum-post-form\" data-forum-post-form=\"" + escaped(category.id) + "\">"
                    + "<label>" + escaped(tr("forumPostTitle", "Title"))
                        + "<input name=\"title\" maxlength=\"160\" required>"
                    + "</label>"
                    + "<label>" + escaped(tr("forumPostBody", "Message"))
                        + "<textarea name=\"body\" rows=\"4\" maxlength=\"5000\" required></textarea>"
                    + "</label>"
                    + "<button class=\"primary\" type=\"submit\">" + escaped(tr("forumCreatePost", "Publish")) + "</button>"
                + "</form>"
            + "</details>"
            + (state.currentPost && String(state.currentPost.categoryId) === String(category.id)
                ? renderInlineThread(category)
                : loadNewer
                    + "<div class=\"forum-post-grid\">"
                        + postHtml
                    + "</div>"
                    + loadMore)
        + "</section>";
    }

    function renderCategories() {
        const container = document.getElementById("forum-categories");
        const empty = document.getElementById("forum-empty");
        if (!container) {
            return;
        }

        if (empty) {
            empty.hidden = state.categories.length > 0;
        }
        container.innerHTML = state.categories.map(renderCategory).join("");
    }

    function renderThread() {
        const legacyThread = document.getElementById("forum-thread");
        if (legacyThread) {
            legacyThread.hidden = true;
        }
        renderCategories();
    }

    async function loadCategoryPosts(categoryId, offset) {
        const key = String(categoryId);
        const nextOffset = Math.max(0, Number(offset || 0));
        const query = new URLSearchParams({
            "categoryId": key,
            "limit": String(POSTS_PER_CATEGORY),
            "offset": String(nextOffset),
        });

        const data = await window.VNG.apiJson("/api/forum/posts?" + query.toString(), {"method": "GET"});
        const posts = Array.isArray(data.posts) ? data.posts : [];
        state.postsByCategory[key] = {
            "items": posts,
            "pagination": data.pagination || null,
        };
    }

    async function loadForum() {
        setStatus(tr("forumLoading", "Loading forum..."));
        const data = await window.VNG.apiJson("/api/forum/categories", {"method": "GET"});
        state.categories = Array.isArray(data.categories) ? data.categories : [];
        state.postsByCategory = {};
        renderCategories();
        await Promise.all(state.categories.map((category) => loadCategoryPosts(category.id, 0)));
        renderCategories();
        setStatus("");
        if (state.currentPost) {
            await openPost(state.currentPost.id, {"silent": true});
        }
    }

    async function openPost(postId, options) {
        const settings = {"append": false, "offset": 0, "silent": false, ...(options || {})};
        if (!settings.silent) {
            setStatus(tr("forumLoading", "Loading forum..."));
        }
        const query = new URLSearchParams({
            "limit": String(MESSAGES_PER_PAGE),
            "offset": String(settings.offset),
        });
        const data = await window.VNG.apiJson("/api/forum/posts/" + encodeURIComponent(postId) + "?" + query.toString(), {"method": "GET"});
        state.currentPost = data.post || null;
        state.currentFirstMessage = data.firstMessage || null;
        const nextMessages = Array.isArray(data.messages) ? data.messages : [];
        state.currentMessages = settings.append ? state.currentMessages.concat(nextMessages) : nextMessages;
        state.currentMessagePagination = data.pagination || null;
        if (!settings.append) {
            state.editingMessageId = null;
        }
        renderThread();
        setStatus("");
        if (!settings.silent) {
            document.querySelector("[data-forum-category-id=\"" + escapedSelectorValue(state.currentPost?.categoryId || "") + "\"]")
                ?.scrollIntoView({"block": "start", "behavior": "smooth"});
        }
    }

    async function createPost(form) {
        const categoryId = form.dataset.forumPostForm || "";
        const formData = new FormData(form);
        const title = String(formData.get("title") || "").trim();
        const body = String(formData.get("body") || "").trim();
        if (!title || !body) {
            return;
        }

        setStatus(tr("orderSent", "Order transmitted..."));
        const data = await window.VNG.apiJson("/api/forum/posts", {
            "method": "POST",
            "body": JSON.stringify({
                "categoryId": Number(categoryId),
                "title": title,
                "body": body,
            }),
        });
        form.reset();
        form.closest("details")?.removeAttribute("open");
        await loadCategoryPosts(categoryId, 0);
        renderCategories();
        setStatus(tr("forumPostCreated", "Post published."));
        if (data.post && data.post.id) {
            await openPost(data.post.id, {"silent": true});
        }
    }

    async function replyToPost(form) {
        if (!state.currentPost || !state.currentPost.id) {
            return;
        }

        const body = String(new FormData(form).get("body") || "").trim();
        if (!body) {
            return;
        }

        setStatus(tr("orderSent", "Order transmitted..."));
        await window.VNG.apiJson("/api/forum/posts/" + encodeURIComponent(state.currentPost.id) + "/messages", {
            "method": "POST",
            "body": JSON.stringify({"body": body}),
        });
        form.reset();
        await openPost(state.currentPost.id, {"silent": true});
        await loadCategoryPosts(state.currentPost.categoryId, 0);
        renderCategories();
        setStatus(tr("forumReplySent", "Reply published."));
    }

    async function updateMessage(form) {
        const messageId = form.dataset.forumEditForm || "";
        const body = String(new FormData(form).get("body") || "").trim();
        if (!messageId || !body) {
            return;
        }

        setStatus(tr("orderSent", "Order transmitted..."));
        const data = await window.VNG.apiJson("/api/forum/messages/" + encodeURIComponent(messageId), {
            "method": "PATCH",
            "body": JSON.stringify({"body": body}),
        });
        const updated = data.message || null;
        if (updated) {
            if (state.currentFirstMessage && String(state.currentFirstMessage.id) === String(updated.id)) {
                state.currentFirstMessage = updated;
            }
            state.currentMessages = state.currentMessages.map((message) => (
                String(message.id) === String(updated.id) ? updated : message
            ));
        }
        state.editingMessageId = null;
        renderThread();
        setStatus(tr("forumMessageEdited", "Message edited."));
    }

    function bindForumEvents() {
        document.querySelector("[data-refresh=\"forum\"]")?.addEventListener("click", () => {
            loadForum().catch((error) => setStatus(error.message || tr("requestDenied", "Request denied")));
        });
        document.getElementById("forum-categories")?.addEventListener("click", (event) => {
            const backButton = event.target.closest("[data-forum-back-to-category]");
            if (backButton) {
                const categoryId = state.currentPost ? state.currentPost.categoryId : null;
                state.currentPost = null;
                state.currentFirstMessage = null;
                state.currentMessages = [];
                state.currentMessagePagination = null;
                state.editingMessageId = null;
                renderCategories();
                if (categoryId !== null) {
                    document.querySelector("[data-forum-category-id=\"" + escapedSelectorValue(categoryId) + "\"]")
                        ?.scrollIntoView({"block": "start", "behavior": "smooth"});
                }
                return;
            }

            const messageLoadMore = event.target.closest("[data-forum-message-load-more]");
            if (messageLoadMore) {
                if (!state.currentPost) {
                    return;
                }
                openPost(state.currentPost.id, {
                    "append": true,
                    "offset": state.currentMessages.length,
                    "silent": true,
                }).catch((error) => setStatus(error.message || tr("requestDenied", "Request denied")));
                return;
            }

            const editButton = event.target.closest("[data-forum-edit-message]");
            if (editButton) {
                state.editingMessageId = editButton.dataset.forumEditMessage || "";
                renderThread();
                return;
            }

            if (event.target.closest("[data-forum-cancel-edit]")) {
                state.editingMessageId = null;
                renderThread();
                return;
            }

            const postTile = event.target.closest("[data-forum-post-id]");
            if (postTile) {
                openPost(postTile.dataset.forumPostId || "").catch((error) => setStatus(error.message || tr("requestDenied", "Request denied")));
                return;
            }

            const loadNewer = event.target.closest("[data-forum-load-newer-category]");
            if (loadNewer) {
                const categoryId = loadNewer.dataset.forumLoadNewerCategory || "";
                const pagination = (state.postsByCategory[String(categoryId)] || {}).pagination || {};
                const offset = Math.max(0, Number(pagination.offset || 0) - POSTS_PER_CATEGORY);
                loadCategoryPosts(categoryId, offset)
                    .then(renderCategories)
                    .catch((error) => setStatus(error.message || tr("requestDenied", "Request denied")));
                return;
            }

            const loadMore = event.target.closest("[data-forum-load-category]");
            if (loadMore) {
                const categoryId = loadMore.dataset.forumLoadCategory || "";
                const pagination = (state.postsByCategory[String(categoryId)] || {}).pagination || {};
                const offset = Number(pagination.offset || 0) + POSTS_PER_CATEGORY;
                loadCategoryPosts(categoryId, offset)
                    .then(renderCategories)
                    .catch((error) => setStatus(error.message || tr("requestDenied", "Request denied")));
            }
        });
        document.getElementById("forum-categories")?.addEventListener("submit", (event) => {
            const editForm = event.target.closest("[data-forum-edit-form]");
            if (editForm) {
                event.preventDefault();
                updateMessage(editForm).catch((error) => setStatus(error.message || tr("requestDenied", "Request denied")));
                return;
            }

            const replyForm = event.target.closest("[data-forum-reply-form]");
            if (replyForm) {
                event.preventDefault();
                replyToPost(replyForm).catch((error) => setStatus(error.message || tr("requestDenied", "Request denied")));
                return;
            }

            const form = event.target.closest("[data-forum-post-form]");
            if (!form) {
                return;
            }
            event.preventDefault();
            createPost(form).catch((error) => setStatus(error.message || tr("requestDenied", "Request denied")));
        });
        document.getElementById("forum-thread-close")?.addEventListener("click", () => {
            state.currentPost = null;
            state.currentFirstMessage = null;
            state.currentMessages = [];
            state.currentMessagePagination = null;
            renderThread();
        });
        document.getElementById("forum-thread-load-more")?.addEventListener("click", () => {
            if (!state.currentPost) {
                return;
            }
            openPost(state.currentPost.id, {
                "append": true,
                "offset": state.currentMessages.length,
            }).catch((error) => setStatus(error.message || tr("requestDenied", "Request denied")));
        });
        document.getElementById("forum-thread-messages")?.addEventListener("click", (event) => {
            const editButton = event.target.closest("[data-forum-edit-message]");
            if (editButton) {
                state.editingMessageId = editButton.dataset.forumEditMessage || "";
                renderThread();
                return;
            }

            if (event.target.closest("[data-forum-cancel-edit]")) {
                state.editingMessageId = null;
                renderThread();
            }
        });
        document.getElementById("forum-thread-messages")?.addEventListener("submit", (event) => {
            const form = event.target.closest("[data-forum-edit-form]");
            if (!form) {
                return;
            }
            event.preventDefault();
            updateMessage(form).catch((error) => setStatus(error.message || tr("requestDenied", "Request denied")));
        });
        document.getElementById("forum-reply-form")?.addEventListener("submit", (event) => {
            event.preventDefault();
            replyToPost(event.currentTarget).catch((error) => setStatus(error.message || tr("requestDenied", "Request denied")));
        });
    }

    withVng(async () => {
        if (document.body.dataset.authenticated !== "1" || !document.getElementById("forum-panel")) {
            return;
        }

        i18n = await window.VNG.loadI18n();
        const playerData = await window.VNG.apiJson("/api/me", {"method": "GET"}).catch(() => null);
        state.currentPlayer = playerData && playerData.player ? playerData.player : null;
        bindForumEvents();
        loadForum().catch((error) => setStatus(error.message || tr("requestDenied", "Request denied")));
    });
})();
