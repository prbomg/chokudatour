'use strict';
const {tourTimes} = window.homePageConfig;
    function updateDefaultTime() {
        const tId = document.getElementById('add_tour_id').value;
        const timeInp = document.getElementById('add_time');
        if (!timeInp.value) delete timeInp.dataset.manual;
        if (tourTimes[tId] && !timeInp.dataset.manual) {
            timeInp.value = tourTimes[tId];
        }
    }

    // Показ Toast уведомления
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        const icon = type === 'success' 
            ? `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`
            : `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>`;
            
        toast.innerHTML = icon;
        const text = document.createElement('span'); text.textContent = message; toast.appendChild(text);
        container.appendChild(toast);
        
        setTimeout(() => toast.classList.add('show'), 10);
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 400);
        }, 3000);
    }

    // Закрытие модалок по фону
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('mousedown', function(e) {
            if (e.target === this) this.style.display = 'none';
        });
    });

    function showNoteModal(text) {
        document.getElementById('noteModalText').textContent = text;
        document.getElementById('noteModal').style.display = 'flex';
    }

    function toggleEditE(id) {
        document.querySelectorAll('.view_e_' + id).forEach(el => el.style.display = 'none');
        document.querySelectorAll('.edit_e_' + id).forEach(el => el.style.display = '');
    }
    function cancelEditE(id) {
        document.getElementById('formEditE_' + id)?.reset();
        document.querySelectorAll('.edit_e_' + id).forEach(el => el.style.display = 'none');
        document.querySelectorAll('.view_e_' + id).forEach(el => el.style.display = '');
    }

    function openExpenseModal(eventId, tourName) {
        document.getElementById('expenseEventId').value = eventId;
        document.getElementById('expenseTourName').textContent = tourName;
        document.getElementById('expenseModal').style.display = 'flex';
    }


(() => {
    const config = window.homePageConfig;
    const key = 'chokuda.home.' + config.user + ':' + config.url;
    const button = document.getElementById('loadPastBtn');
    let pastCount = 0;
    let loading = false;
    let exhausted = false;
    let restoring = true;
    let resetting = false;
    const getState = () => { try { return JSON.parse(sessionStorage.getItem(key) || 'null'); } catch { return null; } };
    const previous = getState();
    function saveState() {
        if (restoring || resetting) return;
        const rows = [...document.querySelectorAll('[class*="view_e_"], .g-card')];
        const anchor = rows.find(el => el.getBoundingClientRect().bottom > 0 && el.getBoundingClientRect().height > 0);
        try { sessionStorage.setItem(key, JSON.stringify({pastCount, scrollY: window.scrollY, anchor: anchor?.id || anchor?.className.match(/view_e_\d+/)?.[0], top: anchor?.getBoundingClientRect().top})); } catch {}
    }
    document.querySelector('.pill-reset')?.addEventListener('click', () => {
        resetting = true;
        try { sessionStorage.removeItem('chokuda.home.' + config.user + ':index.php'); } catch {}
    });
    window.addEventListener('pagehide', saveState);
    document.addEventListener('click', event => { if (event.target.closest('a[href]')) saveState(); });
    document.addEventListener('submit', saveState, true);

    async function loadPast(silent = false) {
        if (!button || loading || exhausted) return false;
        loading = true; button.disabled = true; button.textContent = 'Загрузка…';
        const scrollBefore = window.scrollY;
        const anchor = document.querySelector('.past-event-row, tr[class*="view_e_"], .g-card');
        const anchorTop = anchor?.getBoundingClientRect().top;
        try {
            const body = new FormData();
            body.set('ajax_load_past', '1'); body.set('offset', String(pastCount));
            for (const [key,value] of Object.entries(config.filters)) body.set(key,value);
            const response = await fetch('index.php', {method:'POST', body, headers:{'X-Requested-With':'XMLHttpRequest'}});
            const data = await response.json();
            if (!response.ok || data.status !== 'success') throw new Error(data.message || 'Не удалось загрузить историю.');
            const addRow = document.getElementById('add_event_row');
            if (addRow) addRow.insertAdjacentHTML('afterend', data.html);
            document.getElementById('guideCardsContainer')?.insertAdjacentHTML('afterbegin', data.html);
            if (data.forms) document.getElementById('ajaxFormsContainer')?.insertAdjacentHTML('beforeend', data.forms);
            pastCount += data.count;
            exhausted = data.count < 5;
            const label = document.getElementById('pastHistoryLabel');
            if (label) label.hidden = pastCount === 0;
            if (!silent && scrollBefore > 0 && anchor && anchorTop < window.innerHeight) {
                window.scrollTo(0, window.scrollY + anchor.getBoundingClientRect().top - anchorTop);
            }
            if (!silent) showToast(data.count ? 'Прошедшие экскурсии загружены' : 'Больше прошедших экскурсий нет');
            return true;
        } catch (error) {
            showToast('Не удалось загрузить историю. Повторите попытку.', 'error');
            return false;
        } finally {
            loading = false; button.disabled = exhausted;
            button.textContent = exhausted ? 'Все прошедшие экскурсии загружены' : '⬆ Прошедшие туры';
            saveState();
        }
    }
    button?.addEventListener('click', () => loadPast());

    // Delegate so forms inserted with archived rows receive the same behavior.
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (form.id !== 'ajaxAddEventForm' && !form.id.startsWith('formEditE_')) return;
        event.preventDefault();
        if (form.dataset.saving) return;
        form.dataset.saving = '1';
        const submit = event.submitter;
        if (submit) submit.disabled = true;
        try {
            const response = await fetch(form.action, {method:'POST', body:new FormData(form), headers:{'X-Requested-With':'XMLHttpRequest'}});
            if (response.redirected) {
                saveState(); window.location.assign(response.url); return;
            }
            const data = await response.json();
            if (!response.ok || data.status !== 'success') throw new Error(data.message || 'Не удалось сохранить экскурсию.');
            try {
                sessionStorage.setItem('toast_msg', data.notification_failed ? 'Экскурсия сохранена, но уведомление Telegram не доставлено.' : 'Экскурсия сохранена');
                sessionStorage.setItem('toast_type', data.notification_failed ? 'error' : 'success');
            } catch {}
            saveState(); window.location.assign(config.url);
        } catch (error) {
            showToast(error.message || 'Ошибка соединения. Проверьте данные перед повтором.', 'error');
        } finally {
            delete form.dataset.saving; if (submit) submit.disabled = false;
        }
    });
    try {
        const message = sessionStorage.getItem('toast_msg');
        if (message) showToast(message, sessionStorage.getItem('toast_type') || 'success');
        sessionStorage.removeItem('toast_msg'); sessionStorage.removeItem('toast_type');
    } catch {}
    (async () => {
        const target = Math.max(0, Number(previous?.pastCount) || 0);
        while (button && pastCount < target && !exhausted) { if (!await loadPast(true)) break; }
        if (previous) {
            requestAnimationFrame(() => {
                const anchor = previous.anchor && (document.getElementById(previous.anchor) || [...document.querySelectorAll('[class*="view_e_"]')].find(el => el.classList.contains(previous.anchor)));
                window.scrollTo(0, anchor && anchor.getBoundingClientRect().height ? window.scrollY + anchor.getBoundingClientRect().top - previous.top : previous.scrollY || 0);
            });
        }
        restoring = false;
    })();
})();
