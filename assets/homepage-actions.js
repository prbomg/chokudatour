'use strict';
(() => {
    const table = document.getElementById('eventsTableBody');
    if (!table) return;
    const labels = {tour_date:'Дата', time:'Время старта', tour_id:'Маршрут', guide:'Гид', notes:'Примечание', client_name:'Имя туриста', phone:'Телефон', email:'E-mail', seats:'Мест', price:'Сумма брони, ₽', source:'Источник', status:'Статус'};
    function element(tag, className, text) {
        const el = document.createElement(tag); if (className) el.className = className; if (text) el.textContent = text; return el;
    }
    function dialog(title, side = false) {
        const el = element('dialog', side ? 'workspace-panel' : 'add-event-dialog');
        const heading = element('div','add-dialog-heading');
        const h = element('h2','',title); h.id = side ? 'workspacePanelTitle' : 'workspaceActionTitle';
        el.setAttribute('aria-labelledby',h.id);
        const close = element('button','btn-icon','✕'); close.type = 'button'; close.setAttribute('aria-label','Закрыть'); close.onclick = () => el.close();
        heading.append(h,close); el.append(heading);
        el.addEventListener('close',()=>el.remove()); document.body.append(el); return el;
    }
    function errorBox(parent) { const box = element('p','workspace-error'); box.setAttribute('role','alert'); box.hidden=true; parent.append(box); return box; }
    function controls(source, target, prefix) {
        const grid = element('div','add-dialog-fields');
        for (const original of source) {
            if (!original.name || original.type === 'submit') continue;
            const input = original.cloneNode(true);
            input.removeAttribute('form'); input.removeAttribute('style'); input.removeAttribute('onchange'); input.removeAttribute('oninput');
            input.id = prefix + '_' + original.name; input.value = original.value;
            if (input.type === 'hidden') { target.append(input); continue; }
            input.className = 't-input';
            const label = element('label', ['notes','tour_id','guide','client_name','email'].includes(input.name) ? 'add-dialog-wide' : '', labels[input.name] || input.name);
            label.htmlFor=input.id; label.append(input); grid.append(label);
        }
        target.append(grid);
    }
    function buttons(form, container, saveLabel) {
        const row=element('div','add-dialog-actions');
        const cancel=element('button','btn-cancel','Отмена'); cancel.type='button'; cancel.onclick=()=>container.close();
        const save=element('button','workspace-add',saveLabel); save.type='submit'; row.append(cancel,save); form.append(row);
    }
    function eventUrl(id) {
        const url = new URL('event.php', location.href); url.searchParams.set('id',id); url.searchParams.set('return_to',window.homePageConfig.url); return url;
    }
    function edit(id) {
        const source = document.getElementById('formEditE_'+id); if (!source) return;
        const modal=dialog('Редактировать выезд');
        const form=element('form'); form.id='formEditE_modal'; form.method='POST'; form.action=source.action;
        controls([...source.elements],form,'editModal'); errorBox(form); buttons(form,modal,'Сохранить изменения'); modal.append(form);
        const summary=element('div','edit-booking-summary');
        const row=table.querySelector('.view_e_'+id);
        summary.append(element('h3','','Туристы и места'));
        for (const chip of row.querySelectorAll('.tourist-chip')) summary.append(chip.cloneNode(true));
        if (!row.querySelector('.tourist-chip')) summary.append(element('p','','Туристов пока нет'));
        summary.append(element('p','','Доход: '+row.querySelector('[data-label="Доход"]').textContent.trim()));
        form.insertBefore(summary,form.querySelector('.add-dialog-actions')); modal.showModal();
    }
    function repeat(id) {
        const source=document.getElementById('formEditE_'+id); const target=document.getElementById('ajaxAddEventForm');
        target.reset();
        for (const name of ['tour_id','time','guide']) target.elements.namedItem(name).value=source.elements.namedItem(name).value;
        target.elements.namedItem('time').dataset.manual='1';
        document.getElementById('addEventError').hidden=true;
        document.getElementById('addEventDialog').showModal(); target.elements.namedItem('tour_date').focus();
    }
    async function getEvent(id) {
        const response=await fetch(eventUrl(id));
        if (!response.ok || response.redirected) throw new Error('Не удалось открыть выезд. Обновите страницу и повторите попытку.');
        const doc=new DOMParser().parseFromString(await response.text(),'text/html');
        if (!doc.getElementById('formAddParticipant')) throw new Error('Сессия закончилась. Обновите страницу.');
        return doc;
    }
    async function addTourist(id) {
        const modal=dialog('Добавить туриста'); const message=element('p','','Загрузка формы…'); modal.append(message); modal.showModal();
        try {
            const doc=await getEvent(id); if (!modal.isConnected) return;
            message.remove();
            const form=element('form'); form.method='POST'; form.action=eventUrl(id).href;
            const original=doc.getElementById('formAddParticipant');
            controls([...original.elements],form,'quickTourist');
            const error=errorBox(form); buttons(form,modal,'Добавить туриста'); modal.append(form);
            form.addEventListener('submit',async event=>{
                event.preventDefault(); if (form.dataset.saving) return; form.dataset.saving='1'; error.hidden=true;
                const button=event.submitter; button.disabled=true;
                try {
                    const response=await fetch(form.action,{method:'POST',body:new FormData(form)});
                    if (!response.ok || !response.redirected || new URL(response.url).searchParams.get('msg')!=='participant_added') throw new Error('Не удалось добавить туриста. Проверьте данные и повторите попытку.');
                    location.assign(window.homePageConfig.url);
                } catch(e) { error.textContent=e.message; error.hidden=false; }
                finally { delete form.dataset.saving; button.disabled=false; }
            });
            form.querySelector('input:not([type=hidden])')?.focus();
        } catch(e) { message.textContent=e.message; }
    }
    async function panel(id) {
        const modal=dialog('Карточка выезда',true);
        const full=element('a','panel-full-link','Открыть полную карточку ↗'); full.href=eventUrl(id); modal.append(full);
        const body=element('div','panel-body','Загрузка…'); modal.append(body); modal.showModal();
        try {
            const doc=await getEvent(id); if (!modal.isConnected) return; body.replaceChildren();
            const row=table.querySelector('.view_e_'+id);
            body.append(element('h3','',row.querySelector('.link-tour').textContent));
            body.append(element('p','panel-meta',row.cells[0].innerText+' · '+row.cells[2].innerText));
            const note=row.querySelector('[data-note]'); if (note) body.append(element('p','panel-note',note.dataset.note));
            const overview=doc.querySelector('.dash-grid'); if (overview) body.append(overview.cloneNode(true));
            // Read the same rendered bookings/expenses as the full card. No
            // second endpoint or separate calculation of financial amounts.
            for (const [index,title] of ['Туристы','Расходы'].entries()) {
                const section=element('section','panel-section'); section.append(element('h3','',title));
                const sourceTable=doc.querySelectorAll('table')[index];
                const rows=index===0
                    ? sourceTable?.querySelectorAll('tbody tr[class^="view_p_"]') || []
                    : [...(sourceTable?.querySelectorAll('tbody tr') || [])].filter(row=>!row.classList.contains('add-form-row'));
                let count=0;
                for (const item of rows) {
                    if (!item.cells.length) continue;
                    const card=element('div','panel-record');
                    const headers=[...sourceTable.querySelectorAll('thead th')].map(th=>th.textContent.trim());
                    [...item.cells].slice(0,-1).forEach((cell,i)=>{
                        const line=element('div','panel-field'); line.append(element('span','',headers[i] || ''));
                        const link=cell.querySelector('a[href]');
                        if(link) { const a=element('a','',link.textContent.trim() || 'Открыть чек'); a.href=new URL(link.getAttribute('href'),eventUrl(id)); if (a.href.includes('client.php')) { const u=new URL(a.href); u.searchParams.set('return_to',window.homePageConfig.url); a.href=u; } line.append(a); }
                        else line.append(element('div','',cell.textContent.trim() || '—'));
                        card.append(line);
                    }); section.append(card); count++;
                }
                if (!count) section.append(element('p','','Пока нет записей')); body.append(section);
            }
        } catch(e) { body.textContent=e.message; }
    }
    window.homeWorkspace={edit};
    function enhance() {
        for (const row of table.querySelectorAll('tr[class*="view_e_"]')) {
            if (row.dataset.enhanced) continue; row.dataset.enhanced='1';
            const id=row.className.match(/view_e_(\d+)/)[1];
            const tourists=row.querySelector('[data-label="Туристы"]');
            const add=element('button','quick-tourist','+ Турист'); add.type='button'; add.onclick=()=>addTourist(id);
            const empty=tourists.querySelector('.btn-add-tourist'); if(empty) empty.remove(); tourists.append(add);
            const copy=element('button','btn-icon','⧉'); copy.type='button'; copy.title='Повторить выезд'; copy.setAttribute('aria-label','Повторить выезд'); copy.onclick=()=>repeat(id); row.querySelector('.action-cell').append(copy);
            const note=row.querySelector('.note-truncate');
            if(note) { note.removeAttribute('onclick'); const expand=element('button','note-expand','Развернуть'); expand.type='button'; expand.setAttribute('aria-expanded','false'); expand.onclick=()=>{const open=note.classList.toggle('note-expanded'); expand.textContent=open?'Свернуть':'Развернуть'; expand.setAttribute('aria-expanded',String(open));}; note.after(expand); }
        }
    }
    enhance(); new MutationObserver(enhance).observe(table,{childList:true});
    table.addEventListener('click',event=>{
        const link=event.target.closest('a.link-tour,a.btn-view');
        if(!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const url=new URL(link.href); if(!url.pathname.endsWith('/event.php')) return;
        event.preventDefault(); panel(url.searchParams.get('id'));
    });
    const filters=document.querySelector('form.filters');
    const chips=element('div','active-filter-chips'); chips.setAttribute('aria-label','Активные фильтры');
    for(const [key,value] of Object.entries(window.homePageConfig.filters)) {
        if(!['date_from','date_to','tour_filter','guide_filter'].includes(key)) continue;
        const field=filters.elements.namedItem(key);
        const title=field.tagName==='SELECT'?field.selectedOptions[0].textContent:value.split('-').reverse().join('.');
        const names={date_from:'С',date_to:'По',tour_filter:'Тур',guide_filter:'Гид'};
        const url=new URL(window.homePageConfig.url,location.href); url.searchParams.delete(key);
        const chip=element('a','filter-chip',names[key]+': '+title+' ×'); chip.href=url; chip.setAttribute('aria-label','Снять фильтр '+names[key]+': '+title); chips.append(chip);
    }
    filters.after(chips);
    const collapse=element('button','filter-toggle','Скрыть фильтры'); collapse.type='button'; collapse.setAttribute('aria-expanded','true');
    filters.id='workspaceFilters'; collapse.setAttribute('aria-controls',filters.id); filters.before(collapse);
    collapse.onclick=()=>{ filters.hidden=!filters.hidden; collapse.textContent=filters.hidden?'Показать фильтры':'Скрыть фильтры'; collapse.setAttribute('aria-expanded',String(!filters.hidden)); };
})();
