(() => {
    const toggle = document.querySelector('#notificationPreviewToggle');
    const preview = document.querySelector('#notificationPreview');
    if (!toggle || !preview) return;

    const positionPreview = () => {
        const trigger = toggle.getBoundingClientRect();
        const padding = 8;
        const width = Math.min(360, window.innerWidth - padding * 2);
        const left = Math.max(padding, Math.min(trigger.right - width, window.innerWidth - width - padding));
        const top = trigger.bottom + 8;
        preview.style.left = `${left}px`;
        preview.style.top = `${top}px`;
        preview.style.width = `${width}px`;
        preview.style.maxHeight = `${Math.max(120, window.innerHeight - top - padding)}px`;
    };

    if (typeof preview.showPopover === 'function') {
        preview.addEventListener('toggle', (event) => {
            const isOpen = event.newState === 'open';
            toggle.setAttribute('aria-expanded', String(isOpen));
            if (isOpen) positionPreview();
        });
    } else {
        // Keep a functional fallback for browsers without the Popover API.
        preview.hidden = true;
        toggle.addEventListener('click', () => {
            const shouldOpen = preview.hidden;
            if (shouldOpen) positionPreview();
            preview.hidden = !shouldOpen;
            toggle.setAttribute('aria-expanded', String(shouldOpen));
        });
        document.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : event.target?.parentElement;
            if (target && !preview.contains(target) && !toggle.contains(target)) {
                preview.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                preview.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }
    window.addEventListener('resize', () => {
        if (preview.matches(':popover-open')) positionPreview();
    });
})();

document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document.querySelector('#notificationsCsrfToken')?.value ?? '';
    const countElement = document.querySelector('#notificationUnreadCount');
    const previewUnreadCount = document.querySelector('#notificationPreviewUnreadCount');
    const navigationLink = document.querySelector('#notificationPreviewToggle');
    const statusElement = document.querySelector('#notificationActionStatus');
    const markAllButtons = [...document.querySelectorAll('#markNotificationsRead, #markPreviewNotificationsRead')];
    const previewFilterButtons = [...document.querySelectorAll('[data-notification-filter]')];
    const previewItems = [...document.querySelectorAll('[data-notification-preview-id]')];
    const previewStatusElement = document.querySelector('#notificationPreviewStatus');
    const previewEmptyMessage = document.querySelector('#notificationPreviewEmpty');
    const previewNoUnreadMessage = document.querySelector('#notificationPreviewNoUnread');
    let previewFilter = 'all';
    let requestInProgress = false;

    const showStatus = (message, isError = false) => {
        [statusElement, previewStatusElement].forEach((element) => {
            if (!element) return;
            element.textContent = message;
            element.classList.toggle('is-error', isError);
        });
    };

    const refreshUnreadCount = async () => {
        const response = await fetch('notifications.php?action=count', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        });
        const data = await response.json();
        if (!response.ok || data.success !== true || !Number.isInteger(data.unread_count)) {
            throw new Error(data.message ?? `Notification count could not be loaded (HTTP ${response.status}).`);
        }

        const countLabel = data.unread_count > 0 ? String(data.unread_count) : '';
        countElement.textContent = countLabel;
        if (previewUnreadCount) previewUnreadCount.textContent = String(data.unread_count);
        markAllButtons.forEach((button) => { button.disabled = data.unread_count === 0; });
        navigationLink?.setAttribute(
            'aria-label',
            `Notifications${data.unread_count > 0 ? ` (${data.unread_count})` : ''}`
        );
    };

    const markAsRead = async (values) => {
        const response = await fetch('notifications.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
            },
            body: new URLSearchParams({ ...values, action: 'mark_read', csrf_token: csrfToken })
        });
        const data = await response.json();
        if (!response.ok || data.success !== true) {
            throw new Error(data.message ?? `Notification could not be updated (HTTP ${response.status}).`);
        }
        return data;
    };

    const updateUnreadItem = (item) => {
        item.classList.remove('is-unread');
        item.dataset.notificationUnread = 'false';
        document.querySelectorAll('[data-notification-preview-id]').forEach((previewItem) => {
            if (previewItem.dataset.notificationPreviewId === item.dataset.notificationId) {
                previewItem.classList.remove('is-unread');
                previewItem.dataset.notificationUnread = 'false';
            }
        });
        applyPreviewFilter(previewFilter);
        item.querySelector('.notification-unread-dot')?.removeAttribute('aria-label');
        item.querySelector('[data-mark-notification-read]')?.remove();

        const label = document.createElement('span');
        label.className = 'notification-read-label';
        label.textContent = 'Read';
        item.querySelector('.notification-content')?.append(label);
    };

    const applyPreviewFilter = (filter) => {
        previewFilter = filter;
        const hasUnread = previewItems.some((item) => item.dataset.notificationUnread === 'true');
        previewFilterButtons.forEach((button) => {
            const isActive = button.dataset.notificationFilter === filter;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', String(isActive));
        });
        previewItems.forEach((item) => {
            item.hidden = filter === 'unread' && item.dataset.notificationUnread !== 'true';
        });
        if (previewEmptyMessage) previewEmptyMessage.hidden = previewItems.length > 0 || filter === 'unread';
        if (previewNoUnreadMessage) previewNoUnreadMessage.hidden = filter !== 'unread' || hasUnread;
    };

    previewFilterButtons.forEach((button) => {
        button.addEventListener('click', () => {
            applyPreviewFilter(button.dataset.notificationFilter);
        });
    });
    applyPreviewFilter('all');

    const syncCount = async () => {
        try {
            await refreshUnreadCount();
            const unreadItemsRemain = document.querySelector('#notifications [data-notification-unread="true"]');
            if (!unreadItemsRemain) markAllButtons.forEach((button) => { button.disabled = true; });
        } catch (error) {
            showStatus(
                error instanceof Error ? error.message : 'Notification count could not be refreshed.',
                true
            );
        }
    };

    const handleMarkAll = async () => {
        if (requestInProgress) return;
        requestInProgress = true;
        markAllButtons.forEach((button) => { button.disabled = true; });
        try {
            await markAsRead({ mark_all: '1' });
            document.querySelectorAll('#notifications [data-notification-unread="true"]').forEach(updateUnreadItem);
            previewItems.forEach((item) => {
                item.classList.remove('is-unread');
                item.dataset.notificationUnread = 'false';
            });
            applyPreviewFilter('all');
            showStatus('All notifications marked as read.');
            await syncCount();
        } catch (error) {
            markAllButtons.forEach((button) => { button.disabled = false; });
            showStatus(
                error instanceof Error ? error.message : 'Notifications could not be updated.',
                true
            );
        } finally {
            requestInProgress = false;
        }
    };
    markAllButtons.forEach((button) => {
        button.addEventListener('click', handleMarkAll);
    });

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-mark-notification-read]');
        if (!button || requestInProgress) {
            return;
        }

        const item = button.closest('[data-notification-item]');
        if (!item) {
            return;
        }

        requestInProgress = true;
        button.disabled = true;
        try {
            await markAsRead({ notification_id: item.dataset.notificationId });
            updateUnreadItem(item);
            showStatus('Notification marked as read.');
            await syncCount();
        } catch (error) {
            button.disabled = false;
            showStatus(
                error instanceof Error ? error.message : 'Notification could not be updated.',
                true
            );
        } finally {
            requestInProgress = false;
        }
    });

    refreshUnreadCount().catch((error) => {
        showStatus(
            error instanceof Error ? error.message : 'Notification count could not be loaded.',
            true
        );
    });
});





