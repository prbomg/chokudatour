<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($client_name) ?> — Карточка клиента</title>
    <link rel="stylesheet" href="assets/client-workspace.css?v=<?= @filemtime(__DIR__ . '/assets/client-workspace.css') ?: 1 ?>">
</head>
<body>
<svg class="icon-sprite" aria-hidden="true"><symbol id="i-copy" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></symbol><symbol id="i-settings" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1A2 2 0 1 1 4.4 17l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1A2 2 0 1 1 7 4.4l.1.1a1.7 1.7 0 0 0 1.8.3h.1a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1A2 2 0 1 1 19.6 7l-.1.1a1.7 1.7 0 0 0-.3 1.8v.1a1.7 1.7 0 0 0 1.5 1h.3a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></symbol><symbol id="i-merge" viewBox="0 0 24 24"><path d="M8 3H5a2 2 0 0 0-2 2v3M16 3h3a2 2 0 0 1 2 2v3M8 21H5a2 2 0 0 1-2-2v-3M16 21h3a2 2 0 0 0 2-2v-3"/><path d="M8 8l4 4 4-4M12 12v7"/></symbol><symbol id="i-close" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></symbol></svg>
<div id="toast-container" aria-live="polite"></div>
<div class="container">
    <?php $orders_url = 'index.php'; include 'navbar.php'; ?>
    <main class="profile-page">
        <a href="<?= isset($_GET['return_to']) ? htmlspecialchars($return_url, ENT_QUOTES) : 'clients.php' ?>" class="back-link"><?= isset($_GET['return_to']) ? ($return_is_event ? '← Вернуться к выезду' : '← Вернуться к базе клиентов') : '← Вернуться к базе клиентов' ?></a>
        <?php if ($page_error): ?><div class="page-alert" role="alert"><?= htmlspecialchars($page_error) ?></div><?php endif; ?>

        <section class="profile-hero">
            <div class="profile-main"><span class="eyebrow">Карточка клиента</span><h1><?= htmlspecialchars($client_name) ?></h1><div class="profile-contacts"><a href="tel:<?= htmlspecialchars(normalizePhone($phone), ENT_QUOTES) ?>"><?= htmlspecialchars(displayPhone($phone)) ?></a><?php if ($client_email): ?><a href="mailto:<?= htmlspecialchars($client_email, ENT_QUOTES) ?>"><?= htmlspecialchars($client_email) ?></a><?php endif; ?></div>
                <div class="contact-actions"><a class="btn btn-primary" href="tel:<?= htmlspecialchars(normalizePhone($phone), ENT_QUOTES) ?>">Позвонить</a><a class="btn btn-whatsapp" href="https://wa.me/<?= htmlspecialchars($clean_phone, ENT_QUOTES) ?>" target="_blank">WhatsApp</a><a class="btn btn-telegram" href="https://t.me/+<?= htmlspecialchars($clean_phone, ENT_QUOTES) ?>" target="_blank">Telegram</a><?php if ($client_email): ?><a class="btn btn-quiet" href="mailto:<?= htmlspecialchars($client_email, ENT_QUOTES) ?>">Написать</a><?php endif; ?><button type="button" class="icon-button" id="copyPhone" data-phone="<?= htmlspecialchars(normalizePhone($phone), ENT_QUOTES) ?>" title="Скопировать телефон" aria-label="Скопировать телефон"><svg><use href="#i-copy"/></svg></button></div>
            </div>
            <div class="profile-metrics"><div><span>Активные поездки</span><strong><?= $active_trips ?></strong></div><div><span>Забронировано мест</span><strong><?= $total_seats ?></strong></div><div class="accent"><span>Стоимость бронирований</span><strong><?= clientMoney($booking_value) ?></strong></div></div>
        </section>

        <?php if (!empty($profile['global_note'])): ?><section class="persistent-note"><span>Постоянная заметка</span><p><?= nl2br(htmlspecialchars($profile['global_note'])) ?></p></section><?php endif; ?>

        <div class="profile-grid">
            <section class="profile-card profile-settings">
                <header><div><span class="eyebrow">Сегментация</span><h2>Профиль клиента</h2></div><div class="profile-header-actions"><?php if ($merge_candidates): ?><button type="button" class="icon-button" data-open-dialog="mergeClientDialog" title="Объединить дубли" aria-label="Объединить дубли клиентов"><svg><use href="#i-merge"/></svg></button><?php endif; ?><button type="button" class="icon-button" data-open-dialog="tagManagerDialog" title="Управление общими тегами" aria-label="Управление общими тегами"><svg><use href="#i-settings"/></svg></button></div></header>
                <form method="POST" class="profile-form"><?= formTokenInput() ?><input type="hidden" name="update_profile" value="1">
                    <fieldset><legend>Теги клиента</legend><div class="tag-selector"><?php foreach ($all_existing_tags as $tag): ?><label class="tag-choice" style="<?= clientTagStyle($tag) ?>"><input type="checkbox" name="tags[]" value="<?= htmlspecialchars($tag, ENT_QUOTES) ?>" <?= in_array($tag, $current_tags, true) ? 'checked' : '' ?>><span><?= htmlspecialchars($tag) ?></span></label><?php endforeach; ?></div></fieldset>
                    <label class="field">Новый тег<input name="custom_tag" maxlength="50" placeholder="Например: Из Москвы"></label>
                    <label class="field">Постоянная заметка<textarea name="global_note" rows="6" maxlength="5000" placeholder="Предпочтения, особенности общения, важные условия…"><?= htmlspecialchars($profile['global_note']) ?></textarea><small>Заметка отображается в верхней части карточки.</small></label>
                    <button class="btn btn-primary btn-wide" type="submit">Сохранить профиль</button>
                </form>
            </section>

            <section class="profile-card history-card">
                <header><div><span class="eyebrow">Активность</span><h2>История поездок</h2><p><?= clientCountPhrase(count($history), ['запись','записи','записей']) ?>, включая отменённые</p></div><div class="history-filter" aria-label="Фильтр истории"><button type="button" class="active" data-history-filter="all">Все</button><button type="button" data-history-filter="active">Активные</button><button type="button" data-history-filter="cancelled">Отменённые</button></div></header>
                <div class="trip-list"><div class="trip-head" aria-hidden="true"><span>Дата и экскурсия</span><span>Бронирование</span><span>Статус</span><span></span></div>
                    <?php foreach ($history as $trip):
                        $cancelled = ($trip['status'] ?? '') === 'Отмена';
                        $eventUrl = 'event.php?id=' . (int)$trip['event_id'];
                    ?><article class="trip-row<?= $cancelled ? ' is-cancelled' : '' ?>" data-trip-state="<?= $cancelled ? 'cancelled' : 'active' ?>"><div class="trip-title"><span><?= date('d.m.Y', strtotime($trip['tour_date'])) ?> · <?= htmlspecialchars($trip['time'] ?: 'время не указано') ?></span><a href="<?= $eventUrl ?>"><?= htmlspecialchars($trip['public_name'] ?: $trip['tour_name']) ?></a><?php if (!empty($trip['notes'])): ?><p><?= htmlspecialchars($trip['notes']) ?></p><?php endif; ?></div><div class="trip-booking"><strong><?= clientCountPhrase(participantSeats($trip), ['место','места','мест']) ?></strong><span><?= clientMoney($trip['price'] ?? 0) ?> · <?= htmlspecialchars($trip['source'] ?? '') ?></span></div><div><span class="status" data-status="<?= htmlspecialchars($trip['status'] ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($trip['status'] ?? '') ?></span></div><a class="trip-open" href="<?= $eventUrl ?>" aria-label="Открыть выезд">→</a></article><?php endforeach; ?>
                </div>
            </section>
        </div>
    </main>
</div>

<dialog class="app-dialog" id="tagManagerDialog"><div class="dialog-content"><header><div><span class="eyebrow">Общий справочник</span><h2>Управление тегами</h2><p>Изменения применяются ко всем клиентам.</p></div><button type="button" class="dialog-close" data-close-dialog aria-label="Закрыть"><svg><use href="#i-close"/></svg></button></header>
    <form method="POST" class="tag-manager-form"><?= formTokenInput() ?><input type="hidden" name="rename_tag" value="1"><label class="field">Переименовать тег<select name="old_tag"><?php foreach ($all_existing_tags as $tag): ?><option value="<?= htmlspecialchars($tag, ENT_QUOTES) ?>"><?= htmlspecialchars($tag) ?></option><?php endforeach; ?></select></label><label class="field">Новое название<input name="new_tag" required maxlength="50"></label><button class="btn btn-primary" type="submit">Переименовать у всех</button></form>
    <form method="POST" class="tag-manager-form tag-delete-form" onsubmit="return confirm('Удалить выбранный тег у всех клиентов?')"><?= formTokenInput() ?><input type="hidden" name="delete_tag" value="1"><label class="field">Удалить тег<select name="delete_tag_name"><?php foreach ($all_existing_tags as $tag): ?><option value="<?= htmlspecialchars($tag, ENT_QUOTES) ?>"><?= htmlspecialchars($tag) ?></option><?php endforeach; ?></select></label><button class="btn btn-danger" type="submit">Удалить у всех</button></form>
</div></dialog>

<?php if ($merge_candidates): ?><dialog class="app-dialog" id="mergeClientDialog"><div class="dialog-content"><header><div><span class="eyebrow">Очистка базы</span><h2>Объединить карточки</h2><p>Все бронирования, теги и заметка выбранного дубля перейдут в текущую карточку <?= htmlspecialchars($phone) ?>.</p></div><button type="button" class="dialog-close" data-close-dialog aria-label="Закрыть"><svg><use href="#i-close"/></svg></button></header>
    <form method="POST" class="merge-client-form" onsubmit="return confirm('Объединить выбранную карточку с текущей? Отменить это действие автоматически нельзя.')"><?= formTokenInput() ?><input type="hidden" name="merge_client" value="1"><label class="field">Карточка-дубль<select name="source_phone" required><option value="">Выберите клиента</option><?php foreach ($merge_candidates as $candidate): ?><option value="<?= htmlspecialchars($candidate['phone'], ENT_QUOTES) ?>" <?= (string)($_GET['merge_phone'] ?? '') === (string)$candidate['phone'] ? 'selected' : '' ?>><?= htmlspecialchars(($candidate['client_name'] ?: 'Без имени') . ' · ' . displayPhone($candidate['phone']) . ' · ' . clientCountPhrase((int)$candidate['bookings'], ['бронь','брони','броней'])) ?></option><?php endforeach; ?></select><small>Текущая карточка останется основной.</small></label><button class="btn btn-primary" type="submit">Объединить карточки</button></form>
</div></dialog><?php endif; ?>

<script>window.clientPageConfig=<?= json_encode(['message'=>$_GET['msg'] ?? '', 'error'=>$page_error, 'reviewMerge'=>isset($_GET['merge_phone'])], JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script><script src="assets/client-workspace.js?v=<?= @filemtime(__DIR__ . '/assets/client-workspace.js') ?: 1 ?>"></script>
</body></html>
