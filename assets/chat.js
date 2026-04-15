import * as Turbo from '@hotwired/turbo';

const initChatPages = () => {
    document.querySelectorAll('[data-chat-root="true"]').forEach((root) => {
        if (root.dataset.chatInlineManaged === 'true') {
            syncConversationListState(root);
            return;
        }

        if (root.dataset.chatInitialized === 'true') {
            syncConversationListState(root);
            return;
        }

        root.dataset.chatInitialized = 'true';

        const chatMessages = root.querySelector('[data-chat-messages="true"]');
        const chatForm = root.querySelector('[data-chat-form="true"]');
        const conversationList = root.querySelector('[data-conversation-list="true"]');
        const mercureUrl = root.dataset.chatMercureUrl;
        const mercureWithCredentials = root.dataset.chatMercureCredentials === 'true';
        const pollUrl = root.dataset.chatPollUrl;
        const cleanups = [];

        const registerCleanup = (cleanup) => {
            cleanups.push(cleanup);
        };

        root.addEventListener('turbo:before-cache', () => {
            while (cleanups.length > 0) {
                const cleanup = cleanups.pop();
                try {
                    cleanup?.();
                } catch (_error) {
                }
            }

            delete root.dataset.chatInitialized;
        }, {once: true});

        const scrollToBottom = () => {
            if (!chatMessages) {
                return;
            }

            chatMessages.scrollTop = chatMessages.scrollHeight;
        };

        const setLastIdFromDom = () => {
            if (!chatMessages) {
                return;
            }

            const all = chatMessages.querySelectorAll('.js-chat-message[data-message-id]');
            const last = all.length ? Number(all[all.length - 1].dataset.messageId || 0) : 0;
            chatMessages.dataset.lastMessageId = String(last);
        };

        let markReadTimeout = null;
        const queueMarkRead = () => {
            const seenUrl = root.dataset.chatSeenUrl;
            const seenToken = root.dataset.chatSeenToken;
            if (!seenUrl || !seenToken) {
                return;
            }

            if (markReadTimeout) {
                window.clearTimeout(markReadTimeout);
            }

            markReadTimeout = window.setTimeout(async () => {
                markReadTimeout = null;

                try {
                    await fetch(seenUrl, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                        body: new URLSearchParams({_token: seenToken}),
                        credentials: 'same-origin',
                    });
                } catch (_error) {
                }
            }, 250);
        };

        if (chatMessages) {
            setLastIdFromDom();
            scrollToBottom();

            const chatObserver = new MutationObserver(() => {
                setLastIdFromDom();
                scrollToBottom();
                queueMarkRead();
            });

            chatObserver.observe(chatMessages, {childList: true});
            registerCleanup(() => chatObserver.disconnect());
        }

        if (conversationList) {
            const listObserver = new MutationObserver(() => {
                syncConversationListState(root);
            });

            listObserver.observe(conversationList, {childList: true, subtree: true});
            registerCleanup(() => listObserver.disconnect());
        }

        if (chatForm && chatMessages) {
            chatForm.addEventListener('submit', async (event) => {
                event.preventDefault();

                const input = chatForm.querySelector('input[name$="[content]"]');
                const submit = chatForm.querySelector('button[type="submit"]');
                if (!input || !submit || !input.value.trim()) {
                    return;
                }

                submit.disabled = true;
                try {
                    const response = await fetch(chatForm.action, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                        body: new FormData(chatForm),
                        credentials: 'same-origin',
                    });

                    const payload = await response.json().catch(() => null);
                    if (!response.ok || !payload || !payload.ok || !payload.html) {
                        return;
                    }

                    input.value = '';
                } finally {
                    submit.disabled = false;
                }
            });
        }

        if (mercureUrl) {
            const eventSource = new EventSource(mercureUrl, {
                withCredentials: mercureWithCredentials,
            });

            eventSource.onmessage = (event) => {
                if (!event.data) {
                    return;
                }

                Turbo.renderStreamMessage(event.data);
            };

            registerCleanup(() => eventSource.close());
        }

        if (pollUrl && chatMessages) {
            let polling = false;
            const getLastId = () => Number(chatMessages.dataset.lastMessageId || 0);

            const poll = async () => {
                if (polling) {
                    return;
                }

                polling = true;
                try {
                    const response = await fetch(`${pollUrl}?sinceId=${encodeURIComponent(getLastId())}`, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                        credentials: 'same-origin',
                    });

                    const payload = await response.json().catch(() => null);
                    if (!response.ok || !payload || !payload.ok || !payload.html) {
                        return;
                    }

                    appendMissingMessages(chatMessages, payload.html);
                } finally {
                    polling = false;
                }
            };

            poll();
            const pollTimer = window.setInterval(poll, 2000);
            registerCleanup(() => window.clearInterval(pollTimer));
        }

        syncConversationListState(root);
    });
};

const appendMissingMessages = (chatMessages, html) => {
    if (!html) {
        return;
    }

    const escapeSelector = (value) => {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(String(value));
        }

        return String(value);
    };

    const template = document.createElement('template');
    template.innerHTML = html.trim();

    Array.from(template.content.children).forEach((child) => {
        const messageId = child.getAttribute('data-message-id');
        if (messageId && chatMessages.querySelector(`[data-message-id="${escapeSelector(messageId)}"]`)) {
            return;
        }

        chatMessages.appendChild(child);
    });
};

const syncConversationListState = (root) => {
    const conversationList = root.querySelector('[data-conversation-list="true"]');
    if (!conversationList) {
        return;
    }

    const selectedConversationId = conversationList.dataset.selectedConversationId || '';

    conversationList.querySelectorAll('[data-conversation-id]').forEach((row) => {
        const link = row.querySelector('[data-chat-conversation-link="true"]');
        if (!link) {
            return;
        }

        const isActive = selectedConversationId !== '' && row.dataset.conversationId === selectedConversationId;
        const activeClasses = (link.dataset.chatActiveClass || '').split(' ').filter(Boolean);
        const inactiveClasses = (link.dataset.chatInactiveClass || '').split(' ').filter(Boolean);

        link.classList.remove(...activeClasses, ...inactiveClasses);
        link.classList.add(...(isActive ? activeClasses : inactiveClasses));
    });

    const emptyState = conversationList.querySelector('[data-conversation-empty-state="true"]');
    if (emptyState) {
        emptyState.classList.toggle('hidden', conversationList.querySelectorAll('[data-conversation-id]').length > 0);
    }
};

document.addEventListener('DOMContentLoaded', initChatPages);
document.addEventListener('turbo:load', initChatPages);
