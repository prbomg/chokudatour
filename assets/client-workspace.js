(() => {
    const $ = selector => document.querySelector(selector);
    const $$ = selector => [...document.querySelectorAll(selector)];
    $$('[data-open-dialog]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.openDialog)?.showModal()));
    $$('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog')?.close()));
    $$('dialog').forEach(dialog => dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); }));
    $('#copyPhone')?.addEventListener('click', async event => {
        try {
            await navigator.clipboard.writeText(event.currentTarget.dataset.phone || '');
            showToast('Телефон скопирован');
        } catch (_) { showToast('Не удалось скопировать телефон', 'error'); }
    });
    $$('[data-history-filter]').forEach(button => button.addEventListener('click', () => {
        const filter = button.dataset.historyFilter;
        $$('[data-history-filter]').forEach(item => item.classList.toggle('active', item === button));
        $$('[data-trip-state]').forEach(row => { row.hidden = filter !== 'all' && row.dataset.tripState !== filter; });
    }));
    function showToast(message, type = 'success') {
        const root = $('#toast-container');
        if (!root) return;
        const toast = document.createElement('div'); toast.className = `toast ${type}`; toast.textContent = message; root.append(toast);
        setTimeout(() => toast.remove(), 3400);
    }
    const messages = {saved:'Профиль клиента сохранён',tag_renamed:'Тег переименован у всех клиентов',tag_deleted:'Тег удалён из базы',client_merged:'Карточки клиентов объединены'};
    const config = window.clientPageConfig || {};
    if (config.reviewMerge) $('#mergeClientDialog')?.showModal();
    if (messages[config.message]) {
        showToast(messages[config.message], config.message === 'tag_deleted' ? 'error' : 'success');
        const url = new URL(window.location.href); url.searchParams.delete('msg'); history.replaceState({}, document.title, url);
    }
    if (config.error) showToast(config.error, 'error');
})();
