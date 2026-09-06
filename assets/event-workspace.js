(() => {
    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
    const openDialog = dialog => {
        if (dialog && !dialog.open) dialog.showModal();
    };
    const closeDialog = dialog => {
        if (dialog?.open) dialog.close();
    };

    $$('[data-open-dialog]').forEach(button => button.addEventListener('click', () => openDialog(document.getElementById(button.dataset.openDialog))));
    $$('[data-close-dialog]').forEach(button => button.addEventListener('click', () => closeDialog(button.closest('dialog'))));
    $$('dialog').forEach(dialog => dialog.addEventListener('click', event => {
        if (event.target === dialog) closeDialog(dialog);
    }));

    const participantDialog = $('#participantDialog');
    const participantForm = $('#participantForm');
    const action = $('#participantAction');
    const participantId = $('#participantId');
    const participantTitle = $('#participantDialogTitle');

    function prepareParticipant(data = null) {
        participantForm.reset();
        const isEdit = Boolean(data && Number(data.id) > 0);
        action.name = isEdit ? 'update_participant' : 'add_participant';
        participantId.value = isEdit ? data.id : '';
        participantTitle.textContent = isEdit ? 'Изменить бронирование' : 'Добавить туриста';
        if (data) {
            for (const [name, value] of Object.entries(data)) {
                const input = participantForm.elements.namedItem(name);
                if (input) input.value = value ?? '';
            }
        } else {
            participantForm.elements.namedItem('seats').value = 1;
            participantForm.elements.namedItem('price').value = 0;
            participantForm.elements.namedItem('source').value = 'CRM';
            participantForm.elements.namedItem('status').value = 'Бронь';
        }
        openDialog(participantDialog);
        requestAnimationFrame(() => participantForm.elements.namedItem('client_name').focus());
    }

    $('#addParticipantButton')?.addEventListener('click', () => prepareParticipant());
    $$('[data-edit-participant]').forEach(button => button.addEventListener('click', () => {
        try { prepareParticipant(JSON.parse(button.dataset.editParticipant)); } catch (_) { prepareParticipant(); }
    }));

    $$('[data-participant-filter]').forEach(button => button.addEventListener('click', () => {
        const filter = button.dataset.participantFilter;
        $$('[data-participant-filter]').forEach(item => item.classList.toggle('active', item === button));
        $$('.participant-row').forEach(row => {
            row.hidden = filter !== 'all' && row.dataset.bookingState !== filter;
        });
    }));
    $('[data-participant-filter="active"]')?.click();

    const whatsappDialog = $('#whatsappDialog');
    const whatsappText = $('#whatsappText');
    const whatsappLink = $('#whatsappLink');
    let whatsappPhone = '';
    function updateWhatsappLink() {
        whatsappLink.href = `https://wa.me/${whatsappPhone}?text=${encodeURIComponent(whatsappText.value)}`;
    }
    $$('.wa-btn').forEach(button => button.addEventListener('click', () => {
        whatsappPhone = button.dataset.phone || '';
        whatsappText.value = button.dataset.message || '';
        updateWhatsappLink();
        openDialog(whatsappDialog);
    }));
    whatsappText?.addEventListener('input', updateWhatsappLink);
    whatsappLink?.addEventListener('click', () => closeDialog(whatsappDialog));

    const receiptInput = $('#receiptInput');
    const receiptPreview = $('#receiptPreview');
    const receiptFileName = $('#receiptFileName');
    receiptInput?.addEventListener('change', () => {
        const file = receiptInput.files?.[0];
        receiptFileName.textContent = file ? `${file.name} · ${(file.size / 1024 / 1024).toFixed(1)} МБ` : 'JPG, PNG или WebP, до 8 МБ';
        receiptPreview.classList.toggle('visible', Boolean(file));
        if (file) receiptPreview.src = URL.createObjectURL(file);
        else receiptPreview.removeAttribute('src');
    });

    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        $('#toast-container').append(toast);
        setTimeout(() => toast.remove(), 3500);
    }

    const messages = {
        event_updated: 'Параметры выезда сохранены',
        participant_added: 'Турист добавлен',
        participant_updated: 'Бронирование обновлено',
        participant_deleted: 'Бронирование удалено',
        expense_added: 'Расход добавлен',
        expense_deleted: 'Расход удалён'
    };
    const config = window.eventPageConfig || {};
    if (messages[config.message]) {
        showToast(messages[config.message], config.message.includes('deleted') ? 'error' : 'success');
        const url = new URL(window.location.href);
        url.searchParams.delete('msg');
        window.history.replaceState({}, document.title, url);
    }
    if (config.error) {
        showToast(config.error, 'error');
        if (config.postedAction === 'participant') prepareParticipant(config.postedParticipant || null);
        if (config.postedAction === 'expense') openDialog($('#expenseDialog'));
        if (config.postedAction === 'event') openDialog($('#eventDialog'));
    }
})();
