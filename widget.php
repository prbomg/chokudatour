<?php
error_reporting(0);
ini_set('display_errors', 0);

require_once 'db.php';
require_once __DIR__ . '/participant_seats.php';
require_once 'telegram.php';
require_once __DIR__ . '/public_booking.php';
$booking_token = preg_match('/^[a-f0-9]{64}$/D', (string)($_POST['booking_token'] ?? '')) ? $_POST['booking_token'] : bin2hex(random_bytes(32));

$status_msg = ''; $status_class = ''; $form_submitted = false;
$preset_tour_id = (int)($_POST['tour_id'] ?? $_GET['tour_id'] ?? 0);

// --- ПОДТЯГИВАЕМ ЦЕНЫ ДЛЯ ИСТОЧНИКА "САЙТ" ---
$stmt_src = $pdo->prepare("SELECT id FROM booking_sources WHERE name = 'Сайт' LIMIT 1");
$stmt_src->execute();
$source_id = $stmt_src->fetchColumn() ?: -1;

// Вытаскиваем только активные туры (is_archived = 0) с их типом
$tours_raw = $pdo->query("SELECT id, name, public_name, tour_type, prices FROM tours_catalog WHERE is_archived = 0 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
$tours_data_js = [];
$tours = [];
foreach ($tours_raw as $t) {
    $prices = json_decode($t['prices'], true) ?: [];
    $price = isset($prices[$source_id]) ? $prices[$source_id] : (isset($prices[-1]) ? $prices[-1] : 0);
    $display_name = !empty($t['public_name']) ? $t['public_name'] : $t['name'];
    
    $t['display_name'] = $display_name;
    $t['tour_type'] = $t['tour_type'] ?? 'Индивидуальная';
    $tours[] = $t;
    
    $tours_data_js[$t['id']] = [
        'name' => $display_name,
        'price' => (int)$price,
        'type' => $t['tour_type']
    ];
}
// ----------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_booking'])) {
    try {
        $booking = createPublicBooking($pdo, $_POST, (int)$source_id);
        $form_submitted = true; $status_class = 'success';
        $status_msg = $booking['duplicate'] ? 'Эта заявка уже принята. Повторная запись не создана.' : 'Заявка успешно принята! Наш менеджер свяжется с вами для подтверждения.';
        if (!$booking['duplicate']) {
            $msg = "🌐 <b>НОВАЯ БРОНЬ С САЙТА!</b>\nТур: " . htmlspecialchars($booking['tour'], ENT_QUOTES)
                . "\nДата: " . $booking['date'] . "\nКлиент: " . htmlspecialchars($booking['name'], ENT_QUOTES) . " (" . $booking['seats'] . " чел.)"
                . "\nТелефон: " . htmlspecialchars($booking['phone'], ENT_QUOTES) . "\nСтоимость: " . $booking['price'] . " ₽\nГид: " . htmlspecialchars($booking['guide'], ENT_QUOTES);
            if (!empty($_POST['notes'])) $msg .= "\nПожелания: " . htmlspecialchars($_POST['notes'], ENT_QUOTES);
            try { sendTelegramMessage($msg); } catch (Throwable $e) { /* Booking is already committed. */ }
        }
    } catch (InvalidArgumentException $e) {
        $status_msg = $e->getMessage(); $status_class = 'error';
    } catch (Throwable $e) {
        $status_msg = 'Не удалось сохранить заявку. Повторите попытку.'; $status_class = 'error';
    }
}

$wd_val = $pdo->query("SELECT setting_value FROM global_settings WHERE setting_key = 'working_days'")->fetchColumn();
$working_days = $wd_val ? explode(',', $wd_val) : [];

// Получаем занятые экскурсии (с tour_id для проверки групповых)
$busy_events = $pdo->query("SELECT tour_date, guide, tour_id FROM events WHERE tour_date >= CURDATE()")->fetchAll(PDO::FETCH_ASSOC);

// Загружаем отгулы
$guide_timeoffs = $pdo->query("SELECT guide_name, date_off FROM guide_timeoffs WHERE date_off >= CURDATE()")->fetchAll(PDO::FETCH_ASSOC);

$guides_data = $pdo->query("SELECT name, allowed_tours FROM guides")->fetchAll(PDO::FETCH_ASSOC);
$js_guides = [];
foreach($guides_data as $g) {
    $js_guides[] = ['name' => $g['name'], 'tours' => $g['allowed_tours'] === 'all' ? 'all' : explode(',', $g['allowed_tours'])];
}

$rules_raw = $pdo->query("SELECT * FROM blocked_dates WHERE block_date >= CURDATE() ORDER BY id")->fetchAll();
$rules_map = [];
foreach ($rules_raw as $r) { $rules_map[$r['block_date']] = ['action' => $r['action_type'], 'tours' => $r['tours'] === 'all' ? 'all' : explode(',', $r['tours'])]; }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Бронирование</title>
    <style>
        :root { --primary: #4F46E5; --primary-hover: #4338CA; --bg: #FFFFFF; --border: #E5E7EB; --text: #111827; --text-muted: #6B7280; --disabled-bg: #F3F4F6; --disabled-text: #9CA3AF; }
        body { margin: 0; padding: 0; font-family: 'Segoe UI', Roboto, sans-serif; background: transparent; color: var(--text); }
        .widget-card { background: var(--bg); border: 1px solid var(--border); border-radius: 12px; padding: 16px; box-sizing: border-box; max-width: 550px; margin: 0 auto; }
        h3 { margin: 0 0 2px 0; font-size: 18px; text-align: center; font-weight: 700; }
        .subtitle { color: var(--text-muted); font-size: 12px; text-align: center; margin-bottom: 14px; }
        label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #374151; }
        input, select, textarea { width: 100%; padding: 8px 10px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 13px; box-sizing: border-box; outline: none; margin-bottom: 12px; font-family: inherit; }
        select { cursor: pointer; } input:focus, select:focus, textarea:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79,70,229,0.12); }
        
        .calendar-container { position: relative; border: 1px solid var(--border); border-radius: 8px; padding: 10px; margin-bottom: 12px; background: #FAFAFA; }
        .cal-overlay { position: absolute; top:0; left:0; width:100%; height:100%; background: rgba(255,255,255,0.85); backdrop-filter: blur(2px); z-index: 10; display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 600; color: var(--primary); text-align: center; border-radius: 8px; flex-direction: column; gap: 8px;}
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .calendar-header h4 { margin: 0; font-size: 14px; font-weight: 700; }
        .cal-nav-btn { background: #fff; border: 1px solid #D1D5DB; border-radius: 4px; padding: 4px 10px; cursor: pointer; font-size: 13px; font-weight: 600; }
        .cal-nav-btn:hover { background: #E5E7EB; }
        .cal-legend { display: flex; gap: 10px; font-size: 10px; color: var(--text-muted); margin-bottom: 8px; justify-content: center; }
        .leg-item { display: flex; align-items: center; gap: 4px; }
        .leg-dot { width: 6px; height: 6px; border-radius: 50%; }
        .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; text-align: center; }
        .cal-day-head { font-size: 10px; font-weight: 700; color: var(--text-muted); padding-bottom: 4px; }
        .cal-date-cell { aspect-ratio: 1; display: flex; align-items: center; justify-content: center; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1px solid #E5E7EB; background: #fff;}
        .cal-date-cell.free { color: #065F46; border-color: #A7F3D0; background: #ECFDF5; }
        .cal-date-cell.free:hover { background: #10B981; color: white; border-color: #10B981; }
        .cal-date-cell.disabled { background: var(--disabled-bg); color: var(--disabled-text); cursor: not-allowed; opacity: 0.6; text-decoration: line-through; border-color: transparent;}
        .cal-date-cell.selected { background: var(--primary) !important; color: white !important; border-color: var(--primary) !important; }
        .selected-badge { margin-top: 8px; padding: 6px 10px; background: #EEF2FF; border: 1px solid #C7D2FE; border-radius: 6px; font-size: 12px; font-weight: 600; color: var(--primary); text-align: center; }

        /* БЛОК С ЦЕНОЙ */
        .price-display { display: none; background: #EEF2FF; padding: 12px 16px; border-radius: 6px; margin-bottom: 12px; border: 1px dashed #C7D2FE; justify-content: space-between; align-items: center; }
        .price-label { font-size: 13px; font-weight: 600; color: var(--primary); }
        .price-value { font-size: 18px; font-weight: 800; color: var(--primary); }

        .row { display: flex; gap: 10px; } .row > div { flex: 1; }
        button.btn-submit { width: 100%; background: var(--primary); color: #fff; padding: 10px; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; transition: 0.2s;}
        button.btn-submit:hover { background: var(--primary-hover); }
        .msg { text-align: center; padding: 12px; border-radius: 6px; font-weight: 500; font-size: 13px; line-height: 1.4; }
        .success { background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; } .error { background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; margin-bottom: 12px;}
        @media (max-width: 480px) { .row { flex-direction: column; gap: 0; } .widget-card { padding: 12px; } }
    </style>
</head>
<body>

<div class="widget-card">
    <?php if ($form_submitted): ?>
        <div class="msg success">🎉 <?= htmlspecialchars($status_msg) ?></div>
        <button class="btn-submit" style="margin-top: 12px; background: #F3F4F6; color: #374151;" onclick="window.location.href='widget.php<?= $preset_tour_id ? '?tour_id='.$preset_tour_id : '' ?>'">Забронировать еще</button>
    <?php else: ?>
        <h3>Бронирование экскурсии</h3>
        <p class="subtitle">Выберите экскурсию, свободную дату и оставьте контакты</p>
        
        <?php if ($status_msg): ?><div class="msg error">❌ <?= htmlspecialchars($status_msg) ?></div><?php endif; ?>

        <form method="POST" action="widget.php<?= $preset_tour_id ? '?tour_id='.$preset_tour_id : '' ?>" id="bookingForm">
            <input type="hidden" name="create_booking" value="1">
            <input type="hidden" name="booking_token" value="<?= htmlspecialchars($booking_token, ENT_QUOTES) ?>">
            <input type="hidden" name="booking_date" id="booking_date_input" value="<?= htmlspecialchars($_POST['booking_date'] ?? '', ENT_QUOTES) ?>" required>

            <label>1. Выберите тур *</label>
            <select name="tour_id" id="tourSelect" required>
                <option value="" disabled <?= $preset_tour_id === 0 ? 'selected' : '' ?>>-- Выберите экскурсию --</option>
                <?php foreach ($tours as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $preset_tour_id === (int)$t['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($t['display_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>2. Выберите свободную дату *</label>
            <div class="calendar-container">
                <div class="cal-overlay" id="calOverlay" style="<?= $preset_tour_id > 0 ? 'display:none;' : '' ?>">
                    <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-bottom: 4px;"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    Для выбора даты укажите тур
                </div>
                <div class="calendar-header">
                    <button type="button" class="cal-nav-btn" id="prevMonthBtn">←</button>
                    <h4 id="monthYearTitle"></h4>
                    <button type="button" class="cal-nav-btn" id="nextMonthBtn">→</button>
                </div>
                <div class="cal-legend">
                    <div class="leg-item"><div class="leg-dot" style="background:#10B981;"></div> Свободно</div>
                    <div class="leg-item"><div class="leg-dot" style="background:#EF4444;"></div> Занято / Выходной</div>
                </div>
                <div class="cal-grid" id="calendarGrid"></div>
                <div class="selected-badge" id="selectedDateBadge" style="display:none;"></div>
            </div>

            <div class="row">
                <div><label>Имя *</label><input type="text" name="client_name" value="<?= htmlspecialchars($_POST['client_name'] ?? '', ENT_QUOTES) ?>" required placeholder="Иван Иванов"></div>
                <div><label>Телефон *</label><input type="tel" name="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES) ?>" required placeholder="+7 (999) 000-00-00"></div>
            </div>

            <div class="row">
                <div><label id="paxLabel">Количество человек *</label><input type="number" name="seats" id="paxInput" required min="1" value="<?= htmlspecialchars($_POST['seats'] ?? '1', ENT_QUOTES) ?>" oninput="calculatePrice()"></div>
                <div><label>E-mail</label><input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES) ?>" placeholder="ivan@mail.ru"></div>
            </div>

            <label>Пожелания</label>
            <textarea name="notes" rows="1" placeholder="Ваши вопросы..."><?= htmlspecialchars($_POST['notes'] ?? '', ENT_QUOTES) ?></textarea>

            <div id="priceDisplay" class="price-display">
                <span class="price-label" id="priceLabelTxt">Итого к оплате:</span>
                <span class="price-value"><span id="totalPriceVal">0</span> ₽</span>
            </div>

            <button type="submit" class="btn-submit">Отправить заявку</button>
        </form>
    <?php endif; ?>
</div>

<?php if (!$form_submitted): ?>
<script>
    const toursDataJs = <?= json_encode($tours_data_js, JSON_UNESCAPED_UNICODE) ?>;
    const workingDays = <?= json_encode($working_days) ?>;
    const rulesMap = <?= json_encode($rules_map) ?>;
    const allEvents = <?= json_encode($busy_events) ?>; 
    const allGuides = <?= json_encode($js_guides) ?>;
    const guideTimeoffs = <?= json_encode($guide_timeoffs) ?>;

    const monthNames = ["Январь", "Февраль", "Март", "Апрель", "Май", "Июнь", "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь"];
    const dayNames = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"];

    let currentDate = new Date(); let currentMonth = currentDate.getMonth(); let currentYear = currentDate.getFullYear();
    let selectedDateStr = <?= json_encode(validTourDate((string)($_POST['booking_date'] ?? '')) ? $_POST['booking_date'] : '') ?>;
    let selectedTourId = "<?= $preset_tour_id > 0 ? $preset_tour_id : '' ?>";

    const tourSelect = document.getElementById("tourSelect");
    const calOverlay = document.getElementById("calOverlay");
    const monthYearTitle = document.getElementById("monthYearTitle");
    const calendarGrid = document.getElementById("calendarGrid");
    const bookingDateInput = document.getElementById("booking_date_input");
    const selectedDateBadge = document.getElementById("selectedDateBadge");

    function calculatePrice() {
        const pDisp = document.getElementById('priceDisplay');
        const pVal = document.getElementById('totalPriceVal');
        const paxInput = document.getElementById('paxInput');
        
        if (selectedTourId && toursDataJs[selectedTourId]) {
            const tInfo = toursDataJs[selectedTourId];
            let total = 0;
            let pax = parseInt(paxInput.value) || 1;

            if (tInfo.type === 'Групповая') {
                total = tInfo.price * pax;
            } else {
                total = tInfo.price;
            }

            pVal.textContent = new Intl.NumberFormat('ru-RU').format(total);
            pDisp.style.display = 'flex';
        } else {
            pDisp.style.display = 'none';
        }
    }

    function updateFormFields() {
        if (!selectedTourId || !toursDataJs[selectedTourId]) return;
        const tInfo = toursDataJs[selectedTourId];
        const paxLabel = document.getElementById('paxLabel');
        const paxInput = document.getElementById('paxInput');
        const pLabel = document.getElementById('priceLabelTxt');

        if (tInfo.type === 'Групповая') {
            paxLabel.textContent = 'Количество человек *';
            paxInput.removeAttribute('max');
            pLabel.textContent = 'Итого к оплате:';
        } else {
            paxLabel.textContent = 'Человек (до 4) *';
            paxInput.setAttribute('max', '4');
            if(paxInput.value > 4) paxInput.value = 4;
            pLabel.textContent = 'Стоимость экскурсии:';
        }
        calculatePrice();
    }

    tourSelect.addEventListener('change', function() {
        selectedTourId = this.value; 
        updateFormFields();
        calOverlay.style.display = 'none';
        selectedDateStr = ""; bookingDateInput.value = ""; selectedDateBadge.style.display = "none";
        renderCalendar(currentMonth, currentYear);
    });

    function renderCalendar(month, year) {
        monthYearTitle.textContent = `${monthNames[month]} ${year}`; calendarGrid.innerHTML = "";
        dayNames.forEach(day => { const h = document.createElement("div"); h.className = "cal-day-head"; h.textContent = day; calendarGrid.appendChild(h); });

        const firstDay = new Date(year, month, 1);
        let startingDay = firstDay.getDay(); if (startingDay === 0) startingDay = 7; 
        const daysInMonth = new Date(year, month + 1, 0).getDate();

        for (let i = 1; i < startingDay; i++) { calendarGrid.appendChild(document.createElement("div")); }

        const todayObj = new Date(); todayObj.setHours(0,0,0,0);

        for (let day = 1; day <= daysInMonth; day++) {
            const cellDate = new Date(year, month, day); cellDate.setHours(0,0,0,0);
            const mStr = String(month + 1).padStart(2, '0'); const dStr = String(day).padStart(2, '0');
            const dateStr = `${year}-${mStr}-${dStr}`;

            const cell = document.createElement("div"); cell.className = "cal-date-cell"; cell.textContent = day;

            let dayOfWeek = cellDate.getDay(); if (dayOfWeek === 0) dayOfWeek = 7;
            
            let isAvailable = workingDays.includes(String(dayOfWeek)); 
            const rule = rulesMap[dateStr];
            if (rule) {
                const appliesToCurrentTour = (rule.tours === 'all' || rule.tours.includes(selectedTourId));
                if (appliesToCurrentTour) {
                    if (rule.action === 'close') isAvailable = false;
                    if (rule.action === 'open') isAvailable = true;
                }
            }
            if (cellDate <= todayObj) isAvailable = false; 

            // Проверка ресурса гидов
            if (isAvailable && selectedTourId) {
                const tInfo = toursDataJs[selectedTourId];
                
                const dayEvents = allEvents.filter(e => e.tour_date === dateStr);
                const sameTour = dayEvents.filter(e => String(e.tour_id) === selectedTourId);
                const joiningGroup = tInfo?.type === 'Групповая' && sameTour.length > 0;
                isAvailable = allGuides.some(g => {
                    const canDo = g.tours === 'all' || g.tours.includes(selectedTourId);
                    const isOff = guideTimeoffs.some(off => off.date_off === dateStr && off.guide_name === g.name);
                    if (!canDo || isOff) return false;
                    const busy = dayEvents.filter(e => e.guide === g.name);
                    // An existing group can only accept bookings through its
                    // assigned guide, with no conflicting departure that day.
                    return joiningGroup
                        ? busy.length === 1 && String(busy[0].tour_id) === selectedTourId
                        : busy.length === 0;
                });
            }

            if (!isAvailable) { cell.classList.add("disabled"); } else {
                cell.classList.add("free");
                if (dateStr === selectedDateStr) cell.classList.add("selected");
                cell.addEventListener("click", () => {
                    document.querySelectorAll(".cal-date-cell.selected").forEach(c => c.classList.remove("selected"));
                    cell.classList.add("selected"); selectedDateStr = dateStr; bookingDateInput.value = dateStr;
                    selectedDateBadge.style.display = "block"; selectedDateBadge.textContent = `Выбрана дата: ${dStr}.${mStr}.${year}`;
                });
            }
            calendarGrid.appendChild(cell);
        }
    }

    if (selectedDateStr) {
        const [y,m,d] = selectedDateStr.split('-').map(Number); currentMonth = m - 1; currentYear = y;
        selectedDateBadge.style.display = 'block'; selectedDateBadge.textContent = `Выбрана дата: ${String(d).padStart(2,'0')}.${String(m).padStart(2,'0')}.${y}`;
    }
    document.getElementById('bookingForm').addEventListener('submit', event => {
        if (!bookingDateInput.value) { event.preventDefault(); alert('Выберите свободную дату.'); return; }
        if (event.submitter) { event.submitter.disabled = true; event.submitter.textContent = 'Отправка…'; }
    });
    document.getElementById("prevMonthBtn").addEventListener("click", () => { currentMonth--; if (currentMonth < 0) { currentMonth = 11; currentYear--; } if(selectedTourId) renderCalendar(currentMonth, currentYear); });
    document.getElementById("nextMonthBtn").addEventListener("click", () => { currentMonth++; if (currentMonth > 11) { currentMonth = 0; currentYear++; } if(selectedTourId) renderCalendar(currentMonth, currentYear); });

    if (selectedTourId) {
        calOverlay.style.display = 'none';
        updateFormFields();
        renderCalendar(currentMonth, currentYear);
    } else {
        renderCalendar(currentMonth, currentYear);
    }
</script>
<?php endif; ?>

</body>
</html>
