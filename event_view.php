<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($event['public_name'] ?: $event['tour_name']) ?> — <?= $date_formatted ?></title>
    <link rel="stylesheet" href="assets/event-workspace.css">
</head>
<body>
<svg class="icon-sprite" aria-hidden="true">
    <symbol id="i-edit" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></symbol>
    <symbol id="i-message" viewBox="0 0 24 24"><path d="M21 11.5a8.5 8.5 0 0 1-9 8.5 9 9 0 0 1-3.8-.9L3 21l1.8-5.2A9 9 0 1 1 21 11.5Z"/></symbol>
    <symbol id="i-phone" viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8 10a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c1 .3 1.9.6 2.9.7a2 2 0 0 1 1.7 2Z"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="i-close" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></symbol>
</svg>

<div id="toast-container" aria-live="polite"></div>
<div class="container">
    <?php include 'navbar.php'; ?>

    <main>
        <a href="<?= htmlspecialchars($return_url, ENT_QUOTES) ?>" class="back-link">← К выездам</a>

        <?php if ($page_error !== ''): ?>
            <div class="page-alert" role="alert"><?= htmlspecialchars($page_error) ?></div>
        <?php endif; ?>

        <section class="event-hero">
            <div class="event-heading">
                <div>
                    <div class="eyebrow">Карточка выезда</div>
                    <h1><?= htmlspecialchars($event['public_name'] ?: $event['tour_name']) ?></h1>
                </div>
                <?php if ($current_user_role === 'admin'): ?>
                    <button type="button" class="btn btn-secondary" data-open-dialog="eventDialog"><svg><use href="#i-edit"/></svg>Изменить</button>
                <?php endif; ?>
            </div>

            <div class="event-facts">
                <div class="fact"><span>Дата и время</span><strong><?= $date_formatted ?>, <?= htmlspecialchars($event[$time_col] ?: 'время не указано') ?></strong></div>
                <div class="fact"><span>Гид</span><strong><?= htmlspecialchars($event[$guide_col] ?: 'Не назначен') ?></strong></div>
                <?php if (!empty($event['duration'])): ?><div class="fact"><span>Длительность</span><strong><?= htmlspecialchars($event['duration']) ?></strong></div><?php endif; ?>
                <?php if (!empty($event['coordinates'])): ?><div class="fact fact-wide"><span>Место встречи</span><a class="meeting-link" href="https://www.google.com/maps/search/?api=1&amp;query=<?= rawurlencode($event['coordinates']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($event['coordinates']) ?> ↗</a></div><?php endif; ?>
            </div>

            <?php if (!empty($event['notes'])): ?>
                <div class="event-note"><span>Особенности выезда</span><p><?= nl2br(htmlspecialchars($event['notes'])) ?></p></div>
            <?php endif; ?>

            <?php if ($current_user_role === 'admin'): ?>
                <a class="route-link" href="tour_builder.php?id=<?= (int)$event['tour_id'] ?>">Открыть маршрут и программу →</a>
            <?php endif; ?>
        </section>

        <section class="metrics<?= $current_user_role === 'admin' ? '' : ' metrics-guide' ?>" aria-label="Сводка по выезду">
            <div class="metric"><span>Активные брони</span><strong><?= $active_bookings ?></strong></div>
            <div class="metric"><span>Забронировано мест</span><strong><?= $total_seats ?></strong></div>
            <?php if ($current_user_role === 'admin'): ?>
                <div class="metric"><span>Стоимость бронирований</span><strong><?= eventMoney($total_income) ?></strong></div>
                <div class="metric metric-result"><span>Плановый результат</span><strong><?= eventMoney($profit) ?></strong><small>Расходы: <?= eventMoney($total_expenses) ?></small></div>
            <?php endif; ?>
        </section>

        <section class="workspace-section" id="participants">
            <header class="section-header">
                <div><div class="eyebrow">Состав группы</div><h2>Участники</h2><p><?= eventCountPhrase($active_bookings, ['активная бронь','активные брони','активных броней']) ?> · <?= eventCountPhrase($total_seats, ['место','места','мест']) ?><?php if ($cancelled_bookings): ?> · <?= eventCountPhrase($cancelled_bookings, ['отменена','отменены','отменено']) ?><?php endif; ?></p></div>
                <button type="button" class="btn btn-primary" id="addParticipantButton"><svg><use href="#i-plus"/></svg>Добавить туриста</button>
            </header>

            <?php if ($cancelled_bookings): ?>
                <div class="segment-control" aria-label="Фильтр бронирований">
                    <button type="button" class="active" data-participant-filter="active">Активные</button>
                    <button type="button" data-participant-filter="all">Все</button>
                    <button type="button" data-participant-filter="cancelled">Отменённые</button>
                </div>
            <?php endif; ?>

            <div class="participant-list" role="list">
                <div class="list-head" aria-hidden="true"><span>Турист</span><span>Бронирование</span><span>Статус</span><span>Примечание</span><span></span></div>
                <?php foreach ($participants as $participant):
                    $participantId = (int)$participant['id'];
                    $participantName = $participant['name'] ?? $participant['client_name'] ?? '';
                    $seats = participantSeats($participant);
                    $cancelled = ($participant['status'] ?? '') === 'Отмена';
                    $cleanPhone = preg_replace('/[^0-9]/', '', $participant['phone'] ?? '');
                    if (str_starts_with($cleanPhone, '8') && strlen($cleanPhone) === 11) $cleanPhone = '7' . substr($cleanPhone, 1);
                    $firstName = trim(explode(' ', trim($participantName))[0] ?? $participantName);
                    $message = 'Здравствуйте, ' . $firstName . '! Жду вас ' . date('d.m.Y', strtotime($event[$date_col])) . ' в ' . ($event[$time_col] ?: 'назначенное время') . ' на экскурсию.';
                    if (!empty($event['coordinates'])) $message .= ' Место встречи: ' . $event['coordinates'];
                    $editData = ['id'=>$participantId,'client_name'=>$participantName,'phone'=>$participant['phone'] ?? '','email'=>$participant['email'] ?? '','seats'=>$seats,'price'=>(int)($participant['price'] ?? 0),'source'=>$participant['source'] ?? 'CRM','status'=>$participant['status'] ?? 'Бронь','notes'=>$participant['notes'] ?? ''];
                    $clientReturn = '&return_to=' . rawurlencode($event_return_url);
                ?>
                    <article class="participant-row<?= $cancelled ? ' is-cancelled' : '' ?>" data-booking-state="<?= $cancelled ? 'cancelled' : 'active' ?>" role="listitem">
                        <div class="participant-person">
                            <?php if ($current_user_role === 'admin'): ?><a class="participant-name" href="client.php?phone=<?= rawurlencode($participant['phone'] ?? '') ?><?= htmlspecialchars($clientReturn, ENT_QUOTES) ?>"><?= htmlspecialchars($participantName) ?></a><?php else: ?><strong class="participant-name"><?= htmlspecialchars($participantName) ?></strong><?php endif; ?>
                            <div class="participant-contacts">
                                <a href="tel:<?= htmlspecialchars($participant['phone'] ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($participant['phone'] ?? '') ?></a>
                                <?php if (!empty($participant['email'])): ?><a href="mailto:<?= htmlspecialchars($participant['email'], ENT_QUOTES) ?>"><?= htmlspecialchars($participant['email']) ?></a><?php endif; ?>
                            </div>
                        </div>
                        <div class="booking-summary"><strong><?= $seats ?> <?= $seats === 1 ? 'место' : 'мест' ?></strong><span><?= eventMoney($participant['price'] ?? 0) ?> · <?= htmlspecialchars($participant['source'] ?? '') ?></span>
                            <?php if (participantSeatsConflict($participant)): ?><button type="button" class="data-warning" data-edit-participant='<?= htmlspecialchars(json_encode($editData, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>Проверьте количество мест — значения расходятся</button><?php endif; ?>
                        </div>
                        <div><span class="status" data-status="<?= htmlspecialchars($participant['status'] ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($participant['status'] ?? '') ?></span></div>
                        <div class="participant-note"><?php if (!empty($participant['notes'])): ?><details><summary><?= htmlspecialchars($participant['notes']) ?></summary><p><?= nl2br(htmlspecialchars($participant['notes'])) ?></p></details><?php else: ?><span class="muted">Нет примечания</span><?php endif; ?></div>
                        <div class="row-actions">
                            <a href="tel:<?= htmlspecialchars($participant['phone'] ?? '', ENT_QUOTES) ?>" class="icon-btn" title="Позвонить" aria-label="Позвонить <?= htmlspecialchars($participantName, ENT_QUOTES) ?>"><svg><use href="#i-phone"/></svg></a>
                            <button type="button" class="icon-btn wa-btn" title="WhatsApp" aria-label="Написать в WhatsApp" data-phone="<?= htmlspecialchars($cleanPhone, ENT_QUOTES) ?>" data-message="<?= htmlspecialchars($message, ENT_QUOTES) ?>"><svg><use href="#i-message"/></svg></button>
                            <button type="button" class="icon-btn" title="Редактировать" aria-label="Редактировать бронирование" data-edit-participant='<?= htmlspecialchars(json_encode($editData, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'><svg><use href="#i-edit"/></svg></button>
                            <?php if ($current_user_role === 'admin'): ?><?= deleteControl($event_return_url, 'del_participant', $participantId, 'Удалить туриста?') ?><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$participants): ?><div class="empty-state">В этом выезде пока нет участников.</div><?php endif; ?>
            </div>
        </section>

        <section class="workspace-section" id="expenses">
            <header class="section-header">
                <div><div class="eyebrow">Финансы выезда</div><h2>Расходы</h2><p><?= eventCountPhrase(count($expenses), ['запись','записи','записей']) ?> · всего <?= eventMoney($total_expenses) ?></p></div>
                <button type="button" class="btn btn-secondary" data-open-dialog="expenseDialog"><svg><use href="#i-plus"/></svg>Добавить расход</button>
            </header>
            <div class="expense-list">
                <div class="expense-head" aria-hidden="true"><span>Категория</span><span>Описание</span><span>Чек</span><span>Сумма</span><span></span></div>
                <?php foreach ($expenses as $expense): ?>
                    <article class="expense-row">
                        <strong><?= htmlspecialchars($expense['category'] ?: 'Прочее') ?></strong>
                        <span class="expense-description"><?= !empty($expense['description']) ? htmlspecialchars($expense['description']) : 'Без описания' ?></span>
                        <div><?php if (!empty($expense['receipt_path'])): ?><a class="receipt-link" href="<?= htmlspecialchars($expense['receipt_path'], ENT_QUOTES) ?>" target="_blank"><img src="<?= htmlspecialchars($expense['receipt_path'], ENT_QUOTES) ?>" alt="Чек по расходу" loading="lazy"><span>Открыть чек</span></a><?php else: ?><span class="muted">Без чека</span><?php endif; ?></div>
                        <strong class="expense-amount"><?= eventMoney($expense['amount'] ?? 0) ?></strong>
                        <div class="row-actions"><?= deleteControl($event_return_url, 'del_expense', (int)$expense['id'], 'Удалить расход?') ?></div>
                    </article>
                <?php endforeach; ?>
                <?php if (!$expenses): ?><div class="empty-state">Расходы ещё не добавлены.</div><?php endif; ?>
            </div>
        </section>
    </main>
</div>

<?php if ($current_user_role === 'admin'): ?>
<dialog class="app-dialog" id="eventDialog">
    <form method="POST" class="dialog-form"><?= formTokenInput() ?><input type="hidden" name="update_event_details" value="1">
        <header><div><span class="eyebrow">Параметры выезда</span><h2>Изменить выезд</h2></div><button type="button" class="dialog-close" data-close-dialog aria-label="Закрыть"><svg><use href="#i-close"/></svg></button></header>
        <div class="form-grid">
            <label class="field field-wide">Маршрут<select name="tour_id" required><?php foreach ($tours_list as $tour): ?><option value="<?= (int)$tour['id'] ?>" <?= (int)$tour['id'] === (int)$event['tour_id'] ? 'selected' : '' ?>><?= htmlspecialchars($tour['public_name'] ?: $tour['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field">Дата<input type="date" name="tour_date" value="<?= htmlspecialchars($event[$date_col], ENT_QUOTES) ?>" required></label>
            <label class="field">Время<input type="time" name="time" value="<?= htmlspecialchars($event[$time_col] ?? '', ENT_QUOTES) ?>"></label>
            <label class="field field-wide">Гид<select name="guide"><option value="Не назначен">Не назначен</option><?php foreach ($guides as $guide): ?><option value="<?= htmlspecialchars($guide, ENT_QUOTES) ?>" <?= ($event[$guide_col] ?? '') === $guide ? 'selected' : '' ?>><?= htmlspecialchars($guide) ?></option><?php endforeach; ?></select></label>
            <label class="field field-wide">Примечание<textarea name="notes" rows="4" placeholder="Пожелания и особенности поездки"><?= htmlspecialchars($event['notes'] ?? '') ?></textarea></label>
        </div>
        <footer><button type="button" class="btn btn-quiet" data-close-dialog>Отмена</button><button class="btn btn-primary" type="submit">Сохранить изменения</button></footer>
    </form>
</dialog>
<?php endif; ?>

<dialog class="app-dialog" id="participantDialog">
    <form method="POST" class="dialog-form" id="participantForm"><?= formTokenInput() ?><input type="hidden" name="add_participant" value="1" id="participantAction"><input type="hidden" name="participant_id" id="participantId">
        <header><div><span class="eyebrow">Бронирование</span><h2 id="participantDialogTitle">Добавить туриста</h2></div><button type="button" class="dialog-close" data-close-dialog aria-label="Закрыть"><svg><use href="#i-close"/></svg></button></header>
        <div class="form-grid">
            <label class="field field-wide">Имя и фамилия<input name="client_name" required autocomplete="name"></label>
            <label class="field">Телефон<input name="phone" required inputmode="tel" autocomplete="tel"></label>
            <label class="field">E-mail<input type="email" name="email" autocomplete="email"></label>
            <label class="field">Количество мест<input type="number" name="seats" min="1" step="1" value="1" required></label>
            <label class="field">Сумма бронирования, ₽<input type="number" name="price" min="0" step="1" value="0" required></label>
            <label class="field">Источник<select name="source"><?php foreach ($sources_list as $source): ?><option value="<?= htmlspecialchars($source, ENT_QUOTES) ?>" <?= $source === 'CRM' ? 'selected' : '' ?>><?= htmlspecialchars($source) ?></option><?php endforeach; ?></select></label>
            <label class="field">Статус<select name="status"><?php foreach (eventStatuses() as $status): ?><option value="<?= htmlspecialchars($status, ENT_QUOTES) ?>"><?= htmlspecialchars($status) ?></option><?php endforeach; ?></select></label>
            <label class="field field-wide">Примечание<textarea name="notes" rows="3" placeholder="Пожелания, детали оплаты или связи"></textarea></label>
        </div>
        <footer><button type="button" class="btn btn-quiet" data-close-dialog>Отмена</button><button class="btn btn-primary" type="submit">Сохранить</button></footer>
    </form>
</dialog>

<dialog class="app-dialog" id="expenseDialog">
    <form method="POST" enctype="multipart/form-data" class="dialog-form"><?= formTokenInput() ?><input type="hidden" name="add_expense" value="1">
        <header><div><span class="eyebrow">Финансы выезда</span><h2>Добавить расход</h2></div><button type="button" class="dialog-close" data-close-dialog aria-label="Закрыть"><svg><use href="#i-close"/></svg></button></header>
        <div class="form-grid">
            <label class="field">Сумма, ₽<input type="number" name="amount" min="0.01" step="0.01" required placeholder="0,00"></label>
            <label class="field">Категория<select name="category" required><?php foreach ($expense_cats as $category): ?><option value="<?= htmlspecialchars($category, ENT_QUOTES) ?>"><?= htmlspecialchars($category) ?></option><?php endforeach; ?><?php if (!in_array('Прочее', $expense_cats, true)): ?><option value="Прочее">Прочее</option><?php endif; ?></select></label>
            <label class="field field-wide">Описание<input name="description" placeholder="Обед, бензин, билеты…"></label>
            <label class="field field-wide upload-field">Фото чека<input type="file" name="receipt" id="receiptInput" accept="image/jpeg,image/png,image/webp"><span id="receiptFileName">JPG, PNG или WebP, до 8 МБ</span><img id="receiptPreview" alt="Предпросмотр чека"></label>
        </div>
        <footer><button type="button" class="btn btn-quiet" data-close-dialog>Отмена</button><button class="btn btn-primary" type="submit">Добавить расход</button></footer>
    </form>
</dialog>

<dialog class="app-dialog app-dialog-small" id="whatsappDialog">
    <form method="dialog" class="dialog-form">
        <header><div><span class="eyebrow">Сообщение туристу</span><h2>WhatsApp</h2></div><button type="button" class="dialog-close" data-close-dialog aria-label="Закрыть"><svg><use href="#i-close"/></svg></button></header>
        <label class="field">Текст сообщения<textarea id="whatsappText" rows="7"></textarea></label>
        <footer><button type="button" class="btn btn-quiet" data-close-dialog>Отмена</button><a class="btn btn-whatsapp" id="whatsappLink" target="_blank">Открыть WhatsApp</a></footer>
    </form>
</dialog>

<?php
$posted_action = isset($_POST['add_participant']) || isset($_POST['update_participant']) ? 'participant' : (isset($_POST['add_expense']) ? 'expense' : (isset($_POST['update_event_details']) ? 'event' : ''));
$posted_participant = $posted_action === 'participant' ? [
    'id'=>(int)($_POST['participant_id'] ?? 0), 'client_name'=>$_POST['client_name'] ?? '', 'phone'=>$_POST['phone'] ?? '',
    'email'=>$_POST['email'] ?? '', 'seats'=>$_POST['seats'] ?? 1, 'price'=>$_POST['price'] ?? 0,
    'source'=>$_POST['source'] ?? 'CRM', 'status'=>$_POST['status'] ?? 'Бронь', 'notes'=>$_POST['notes'] ?? ''
] : null;
?>
<script>window.eventPageConfig = <?= json_encode(['message'=>$_GET['msg'] ?? '', 'error'=>$page_error, 'postedAction'=>$posted_action, 'postedParticipant'=>$posted_participant], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="assets/event-workspace.js"></script>
</body>
</html>
