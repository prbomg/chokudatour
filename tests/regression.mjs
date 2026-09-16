import assert from 'node:assert/strict';
import { readFile, readdir, writeFile } from 'node:fs/promises';
import { PHP, loadPHPRuntime } from '@php-wasm/universal';
import { getPHPLoaderModule } from '@php-wasm/node-8-4';

// All requests run in an isolated, in-memory filesystem. Real authentication,
// database configuration and Telegram are replaced before any page executes.
const root = new URL('../', import.meta.url);
const php = new PHP(await loadPHPRuntime(await getPHPLoaderModule()));
php.mkdir('/app');
let checks = 0;
try {
  const files = (await readdir(root)).filter(name => name.endsWith('.php'));
  for (const name of files) {
    php.writeFile(`/app/${name}`, await readFile(new URL(name, root)));
  }
  const lint = await php.run({ code: `<?php
    foreach (glob('/app/*.php') as $file) { token_get_all(file_get_contents($file), TOKEN_PARSE); }
    echo 'OK';` });
  assert.equal(lint.text, 'OK', 'PHP syntax check');
  checks++;
  const sanitized = await php.run({code:`<?php require '/app/content_security.php'; echo safeRichHtml('<p onclick="bad()">Текст <strong>жирный</strong><script>alert(1)</script><a href="javascript:bad()">ссылка</a></p>');`});
  assert.ok(sanitized.text.includes('<strong>жирный</strong>'));
  assert.ok(!sanitized.text.includes('onclick'));
  assert.ok(!sanitized.text.includes('<script'));
  assert.ok(!sanitized.text.includes('javascript:'));
  checks++;
  const migrationRun = await php.run({code:`<?php
    require '/app/migrations.php';
    $database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $first = runDatabaseMigrations($database);
    $second = runDatabaseMigrations($database);
    echo json_encode([
      'first'=>$first,
      'second'=>$second,
      'version'=>databaseSchemaVersion($database),
      'recorded'=>(int)$database->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn(),
      'client_tags'=>(string)$database->query("SELECT setting_value FROM global_settings WHERE setting_key='client_tags'")->fetchColumn(),
      'tables'=>(int)$database->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('events','participants','payments','activity_log')")->fetchColumn(),
    ], JSON_UNESCAPED_UNICODE);`});
  const migrationResult = JSON.parse(migrationRun.text);
  assert.deepEqual(migrationResult.first, [1,2,3,4,5]);
  assert.deepEqual(migrationResult.second, []);
  assert.equal(migrationResult.version, 5);
  assert.equal(migrationResult.recorded, 5);
  assert.equal(migrationResult.tables, 4);
  assert.ok(migrationResult.client_tags.includes('VIP'));
  checks++;
  const homepageActions = await readFile(new URL('../assets/homepage-actions.js', import.meta.url), 'utf8');
  assert.ok(homepageActions.includes("getElementById('participantForm')"));
  assert.ok(!homepageActions.includes("getElementById('formAddParticipant')"));
  checks++;
  php.writeFile('/app/fixture.php', await readFile(new URL('fixture.php', import.meta.url)));
  php.writeFile('/app/auth.php', "<?php require_once __DIR__ . '/fixture.php';");
  php.writeFile('/app/db.php', "<?php require_once __DIR__ . '/fixture.php'; require_once __DIR__ . '/migrations.php'; runDatabaseMigrations($pdo);");
  php.writeFile('/app/telegram.php', `<?php function sendTelegramMessage($message) { $GLOBALS['notifications'][] = ['message'=>$message, 'events'=>(int)$GLOBALS['pdo']->query('SELECT COUNT(*) FROM events')->fetchColumn()]; if (!empty($GLOBALS['notification_error'])) throw new RuntimeException('Offline'); return true; }`);

  async function page(name, get = {}, post = {}, legacy = false, options = {}) {
    php.writeFile('/app/request.json', JSON.stringify({get, post: Object.keys(post).length ? {csrf_token:"fixture-token",booking_token:"a".repeat(64),...post} : post, legacy, options}));
    const response = await php.run({code: `<?php
      chdir('/app');
      $input = json_decode(file_get_contents('request.json'), true);
      $_GET = $input['get']; $_POST = $input['post'];
      $_SERVER['REQUEST_METHOD'] = $_POST ? 'POST' : 'GET';
      $_SERVER['PHP_SELF'] = '/${name}';
      $_SERVER['QUERY_STRING'] = http_build_query($_GET);
      $_SERVER['HTTP_HOST'] = 'localhost';
      $GLOBALS['legacy_schema'] = $input['legacy'];
      $GLOBALS['fixture_role'] = $input['options']['role'] ?? 'admin';
      $GLOBALS['fixture_name'] = $input['options']['name'] ?? 'Тестовый администратор';
      $GLOBALS['notification_error'] = $input['options']['notificationError'] ?? false;
      require_once 'fixture.php';
      require_once 'migrations.php';
      runDatabaseMigrations($pdo);
      foreach ($input['options']['setup'] ?? [] as $sql) $pdo->exec($sql);
      register_shutdown_function(function() {
        $data = ['error' => error_get_last()];
        foreach (['dash_tours','dash_clients','dash_income','dash_expenses','dash_profit','total_seats','total_expenses','events','events_raw'] as $key) {
          $data[$key] = $GLOBALS[$key] ?? null;
        }
        if (isset($GLOBALS['pdo'])) {
          $data['participants'] = $GLOBALS['pdo']->query('SELECT * FROM participants ORDER BY id')->fetchAll();
          $data['all_events'] = $GLOBALS['pdo']->query('SELECT * FROM events ORDER BY id')->fetchAll();
          $data['expenses'] = $GLOBALS['pdo']->query('SELECT * FROM expenses ORDER BY id')->fetchAll();
          $data['blocked_dates'] = $GLOBALS['pdo']->query('SELECT * FROM blocked_dates ORDER BY block_date')->fetchAll();
          $data['guide_timeoffs'] = $GLOBALS['pdo']->query('SELECT * FROM guide_timeoffs ORDER BY date_off')->fetchAll();
          $data['tours_catalog'] = $GLOBALS['pdo']->query('SELECT * FROM tours_catalog ORDER BY id')->fetchAll();
          $data['guides'] = $GLOBALS['pdo']->query('SELECT * FROM guides ORDER BY id')->fetchAll();
          $data['booking_sources'] = $GLOBALS['pdo']->query('SELECT * FROM booking_sources ORDER BY id')->fetchAll();
          $data['users'] = $GLOBALS['pdo']->query('SELECT * FROM users ORDER BY id')->fetchAll();
          $data['activity_log'] = $GLOBALS['pdo']->query('SELECT * FROM activity_log ORDER BY id')->fetchAll();
          $data['payments'] = $GLOBALS['pdo']->query('SELECT * FROM payments ORDER BY id')->fetchAll();
          $data['notifications'] = $GLOBALS['notifications'] ?? [];
          if ($GLOBALS['pdo']->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='client_profiles'")->fetchColumn()) {
            $data['client_profiles'] = $GLOBALS['pdo']->query('SELECT * FROM client_profiles ORDER BY phone')->fetchAll();
          }
          if ($GLOBALS['pdo']->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='tour_modules'")->fetchColumn()) {
            $data['tour_modules'] = $GLOBALS['pdo']->query('SELECT * FROM tour_modules ORDER BY id')->fetchAll();
          }
        }
        file_put_contents('/app/result.json', json_encode($data));
      });
      include '${name}';`});
    const data = JSON.parse(php.readFileAsText('/app/result.json'));
    assert.equal(response.exitCode, 0, `${name}: ${response.text.slice(-1800)}`);
    if (data.error && [1,4,16,64,256,4096].includes(data.error.type)) {
      assert.fail(`${name}: ${data.error.message}`);
    }
    return {data, html: response.text, status: response.httpStatusCode, headers: response.headers};
  }
  const cases = [
    [{}, [5,12,13500,10250.75,3249.25]],
    [{date_from:'2026-09-01',date_to:'2026-09-30'}, [3,6,6000,750.75,5249.25]],
    [{date_from:'2026-09-05',date_to:'2026-09-05'}, [1,3,4000,300.75,3699.25]],
    [{tour_filter:1}, [4,9,11500,9850.75,1649.25]],
    [{guide_filter:'Гид Б'}, [2,5,3500,900,2600]],
    [{date_from:'2026-09-01',date_to:'2026-09-30',tour_filter:1,guide_filter:'Гид А'}, [2,3,4000,350.75,3649.25]],
    [{date_from:'2026-09-07',date_to:'2026-09-07'}, [1,0,0,50,-50]],
    [{tour_filter:999}, [0,0,0,0,0]],
    [{date_from:'2026-08-01',date_to:'2026-08-01'}, [1,4,6000,9000,-3000]],
  ];
  for (const [filter, expected] of cases) {
    const {data} = await page('index.php', filter);
    assert.deepEqual(['dash_tours','dash_clients','dash_income','dash_expenses','dash_profit'].map(k => Number(data[k])), expected, JSON.stringify(filter));
    // Reading must preserve both conflicting historical values.
    assert.deepEqual(data.participants.slice(0,2).map(p => [p.places,p.seats]), [[2,1],[1,4]]);
    checks++;
  }
  const event = await page('event.php', {id:1});
  assert.equal(event.data.total_seats, 3);
  assert.ok(event.html.includes('Проверьте количество'));
  assert.ok(event.html.includes('Особенности выезда'));
  assert.ok(event.html.includes('Оплата на месте'));
  assert.ok(event.html.includes('Расчёты с туристами'));
  assert.ok(event.html.includes('id="paymentDialog"'));
  assert.ok(event.html.includes('class="payment-head"'));
  assert.match(event.html, /assets\/event-workspace\.css\?v=\d+/);
  assert.match(event.html, /assets\/event-workspace\.js\?v=\d+/);
  checks++;
  const completedEvent = await page('event.php', {id:4}, {complete_event:1});
  assert.ok(completedEvent.data.all_events.find(row => Number(row.id) === 4).completed_at);
  assert.equal(completedEvent.data.activity_log.at(-1).summary, 'Выезд отмечен проведённым');
  checks++;
  const reopenedEvent = await page('event.php', {id:4}, {reopen_event:1}, false, {setup:["UPDATE events SET completed_at='2026-08-02 12:00:00', completed_by='Тест' WHERE id=4"]});
  assert.equal(reopenedEvent.data.all_events.find(row => Number(row.id) === 4).completed_at, null);
  assert.equal(reopenedEvent.data.activity_log.at(-1).summary, 'Выезд возвращён в работу');
  checks++;
  const eventReturn = 'event.php?id=1&return_to=' + encodeURIComponent('index.php?tour_filter=1');
  const clientFromEvent = await page('client.php', {phone:'70000000000',return_to:eventReturn});
  assert.ok(clientFromEvent.html.includes('Вернуться к выезду'));
  assert.ok(clientFromEvent.html.includes('event.php?id=1&amp;return_to=index.php%3Ftour_filter%3D1'));
  assert.ok(clientFromEvent.html.includes('href="index.php" class="nav-link'));
  assert.ok(clientFromEvent.html.includes('data-history-filter="cancelled"'));
  assert.ok(clientFromEvent.html.includes('data-trip-state="active"'));
  assert.ok(clientFromEvent.html.includes('tel:+70000000000'));
  assert.ok(clientFromEvent.html.includes('+7 000 000-00-00'));
  checks++;
  const clientList = await page('clients.php');
  assert.ok(clientList.html.includes('База клиентов'));
  assert.ok(clientList.html.includes('4 поездки'));
  assert.ok(clientList.html.includes('12 мест'));
  assert.ok(clientList.html.includes('13 500 ₽'));
  assert.ok(clientList.html.includes('return_to=clients.php'));
  checks++;
  const normalizedClientList = await page('clients.php', {}, {}, false, {setup:["UPDATE participants SET phone='+7 (000) 000-00-00' WHERE id=1"]});
  assert.equal(normalizedClientList.data.participants[0].phone, '+7 (000) 000-00-00');
  assert.ok(normalizedClientList.html.includes('Возможные дубли'));
  assert.ok(normalizedClientList.html.includes('Один номер в разных форматах'));
  assert.ok(normalizedClientList.html.includes('Одинаковый e-mail'));
  assert.ok(normalizedClientList.html.includes('merge_phone='));
  checks++;
  const duplicateSignals = await page('clients.php', {}, {}, false, {setup:[
    "UPDATE participants SET phone='+7 (000) 000-00-00',client_name='Формат Номера',email='format-a@example.invalid' WHERE id=1",
    "UPDATE participants SET phone='70000000000',client_name='Формат Номера',email='format-b@example.invalid' WHERE id=2",
    "UPDATE participants SET phone='79991110000',client_name='Алексей Иванов',email='shared@example.invalid' WHERE id=3",
    "UPDATE participants SET phone='78882220000',client_name='Алексей Иваноф',email='shared@example.invalid' WHERE id=4",
    "UPDATE participants SET phone='75551112222',client_name='Мария Петрова',email='maria-a@example.invalid' WHERE id=5",
    "UPDATE participants SET phone='74441113333',client_name='Мария Петрова',email='maria-b@example.invalid' WHERE id=6"
  ]});
  for (const reason of ['Один номер в разных форматах','Одинаковый e-mail']) assert.ok(duplicateSignals.html.includes(reason), reason);
  for (const removedReason of ['Похожее имя и последние цифры телефона','Одинаковое имя при разных контактах']) assert.ok(!duplicateSignals.html.includes(removedReason), removedReason);
  assert.match(duplicateSignals.html, /class="duplicate-count">2<\/span>/);
  assert.ok(duplicateSignals.html.includes('только по телефону или e-mail'));
  checks++;
  const reviewedDuplicate = await page('client.php', {phone:'70000000000',merge_phone:'+7 (000) 000-00-00'}, {}, false, {setup:["UPDATE participants SET phone='+7 (000) 000-00-00' WHERE id=1"]});
  assert.ok(reviewedDuplicate.html.includes('"reviewMerge":true'));
  assert.ok(reviewedDuplicate.html.includes('value="+7 (000) 000-00-00" selected'));
  const manuallyMergedFormat = await page('client.php', {phone:'70000000000'}, {merge_client:1,source_phone:'+7 (000) 000-00-00'}, false, {setup:["UPDATE participants SET phone='+7 (000) 000-00-00' WHERE id=1"]});
  assert.equal(manuallyMergedFormat.data.participants[0].phone, '70000000000');
  checks++;
  const filteredClientList = await page('clients.php', {search:'Тестовый',tour_id:1});
  assert.ok(filteredClientList.html.includes('Тестовый турист'));
  assert.ok(filteredClientList.html.includes('return_to=clients.php%3Fsearch%3D'));
  assert.ok(filteredClientList.html.includes('%26tour_id%3D1'));
  checks++;
  const exactTagFilter = await page('clients.php', {tag:'VIP'}, {}, false, {setup:["INSERT INTO client_profiles VALUES ('70000000000','VIP2','')"]});
  assert.ok(exactTagFilter.html.includes('Клиенты не найдены'));
  checks++;
  const savedClient = await page('client.php', {phone:'70000000000'}, {update_profile:1,tags:['VIP'],custom_tag:'Из Москвы',global_note:'Сидит впереди'});
  assert.equal(savedClient.data.client_profiles[0].tags, 'VIP,Из Москвы');
  assert.equal(savedClient.data.client_profiles[0].global_note, 'Сидит впереди');
  assert.ok(savedClient.headers.location[0].includes('msg=saved'));
  checks++;
  const mergedClient = await page('client.php', {phone:'70000000000'}, {merge_client:1,source_phone:'79991234567'}, false, {setup:[
    "UPDATE participants SET phone='79991234567',client_name='Дубль туриста' WHERE id=2",
    "INSERT INTO client_profiles VALUES ('70000000000','VIP','Основная заметка')",
    "INSERT INTO client_profiles VALUES ('79991234567','Семья с детьми','Заметка дубля')"
  ]});
  assert.equal(mergedClient.data.participants.find(p => Number(p.id) === 2).phone, '70000000000');
  assert.equal(mergedClient.data.client_profiles.length, 1);
  assert.equal(mergedClient.data.client_profiles[0].tags, 'VIP,Семья с детьми');
  assert.ok(mergedClient.data.client_profiles[0].global_note.includes('Заметка дубля'));
  assert.equal(mergedClient.data.activity_log.at(-1).entity_type, 'client');
  checks++;
  const clientCsrf = await page('client.php', {phone:'70000000000'}, {update_profile:1,tags:['VIP'],global_note:'Не сохранять',csrf_token:''});
  assert.equal(clientCsrf.status, 403);
  assert.equal(clientCsrf.data.client_profiles.length, 0);
  checks++;
  const invalidEventUpdate = await page('event.php', {id:1}, {update_event_details:1,tour_date:'2026-02-30',time:'14:00',tour_id:1,guide:'Гид А',notes:'x'});
  assert.equal(invalidEventUpdate.status, 422);
  assert.equal(invalidEventUpdate.data.all_events[0].tour_date, '2026-09-05');
  assert.ok(invalidEventUpdate.html.includes('корректную дату'));
  checks++;
  const invalidEventParticipant = await page('event.php', {id:1}, {add_participant:1,client_name:'Без телефона',phone:'abc',email:'',seats:1,price:0,source:'CRM',status:'Бронь',notes:''});
  assert.equal(invalidEventParticipant.status, 422);
  assert.equal(invalidEventParticipant.data.participants.length, 6);
  assert.ok(invalidEventParticipant.html.includes('корректный телефон'));
  checks++;
  const decimalExpense = await page('event.php', {id:1}, {add_expense:1,amount:'12.34',category:'Прочее',description:'Копейки'});
  assert.equal(Number(decimalExpense.data.expenses.at(-1).amount), 12.34);
  assert.equal(decimalExpense.data.activity_log.at(-1).action, 'create');
  checks++;
  const addedPayment = await page('event.php', {id:1}, {add_payment:1,participant_id:1,operation:'payment',amount:'1250,50',method:'card',paid_at:'2026-09-05',note:'Предоплата'});
  assert.equal(addedPayment.data.payments.length, 1);
  assert.equal(Number(addedPayment.data.payments[0].amount), 1250.5);
  assert.equal(addedPayment.data.payments[0].method, 'card');
  assert.equal(addedPayment.data.activity_log.at(-1).entity_type, 'payment');
  checks++;
  const renderedPayment = await page('event.php', {id:1}, {}, false, {setup:["INSERT INTO payments (event_id,participant_id,operation,amount,method,paid_at,note) VALUES (1,1,'payment',1250.50,'card','2026-09-05','Длинный комментарий')"]});
  assert.ok(renderedPayment.html.includes('class="payment-person"'));
  assert.ok(renderedPayment.html.includes('class="payment-side"'));
  checks++;
  const invalidPayment = await page('event.php', {id:1}, {add_payment:1,participant_id:1,operation:'payment',amount:'-10',method:'cash',paid_at:'2026-09-05',note:''});
  assert.equal(invalidPayment.status, 422);
  assert.equal(invalidPayment.data.payments.length, 0);
  assert.ok(invalidPayment.html.includes('положительную сумму'));
  checks++;
  const deletedPayment = await page('event.php', {id:1}, {delete_payment:1}, false, {setup:["INSERT INTO payments (id,event_id,participant_id,operation,amount,method,paid_at) VALUES (1,1,1,'payment',500,'cash','2026-09-05')"]});
  assert.equal(deletedPayment.data.payments.length, 0);
  assert.equal(deletedPayment.data.activity_log.at(-1).action, 'delete');
  assert.equal(JSON.parse(deletedPayment.data.activity_log.at(-1).snapshot).payment.amount, 500);
  checks++;
  const homepageDecimalExpense = await page('index.php', {}, {add_expense:1,event_id:1,amount:'19,95',category:'Прочее',description:'Копейки с главной'});
  assert.equal(Number(homepageDecimalExpense.data.expenses.at(-1).amount), 19.95);
  checks++;
  const invalidParticipantEdit = await page('participants.php', {}, {update_participant:1,participant_id:1,client_name:'Тест',phone:'abc',email:'',seats:1,price:0,source:'CRM',status:'Бронь',notes:''});
  assert.equal(invalidParticipantEdit.status, 422);
  assert.equal(invalidParticipantEdit.data.participants[0].client_name, 'Тестовый турист с длинной фамилией');
  assert.ok(invalidParticipantEdit.html.includes('корректный телефон'));
  checks++;
  const normalizedParticipant = await page('event.php', {id:1}, {add_participant:1,client_name:'Телефон',phone:'+7 (999) 123-45-67',email:'',seats:1,price:0,source:'CRM',status:'Оплата на месте',notes:''});
  assert.equal(normalizedParticipant.data.participants.at(-1).phone, '+79991234567');
  assert.ok(normalizedParticipant.headers.location[0].includes('participant_added'));
  assert.equal(normalizedParticipant.data.participants.at(-1).status, 'Оплата на месте');
  checks++;
  const calendar = await page('schedule.php', {ym:'2026-09'});
  assert.equal(Number(calendar.data.events_raw.find(e => e.id === 1).seats_count), 3);
  assert.ok(calendar.html.includes('role=\'button\' tabindex=\'0\''));
  assert.ok(calendar.html.includes('name="csrf_token"'));
  checks++;
  const scheduledEvent = await page('schedule.php', {ym:'2026-09'}, {add_single_event:1,tour_id:1,tour_date:'2026-09-20',time:'09:30',guide:'Гид А'});
  assert.equal(scheduledEvent.data.all_events.length, 6);
  assert.equal(scheduledEvent.data.all_events.at(-1).time, '09:30');
  checks++;
  const invalidScheduledEvent = await page('schedule.php', {ym:'2026-09'}, {add_single_event:1,tour_id:1,tour_date:'2026-02-30',time:'09:30',guide:'Гид А'});
  assert.equal(invalidScheduledEvent.data.all_events.length, 5);
  checks++;
  const scheduleCsrf = await page('schedule.php', {ym:'2026-09'}, {add_single_event:1,tour_id:1,tour_date:'2026-09-20',time:'10:00',csrf_token:''});
  assert.equal(scheduleCsrf.status, 403);
  assert.equal(scheduleCsrf.data.all_events.length, 5);
  checks++;
  const safeScheduleGet = await page('schedule.php', {ym:'2026-09',del_rule:'2026-09-20'}, {}, false, {setup:["INSERT INTO blocked_dates (block_date,reason,action_type,tours) VALUES ('2026-09-20','Тест','close','all')"]});
  assert.equal(safeScheduleGet.data.blocked_dates.length, 1);
  checks++;
  const participants = await page('participants.php');
  assert.equal(participants.data.total_seats, 8);
  assert.ok(participants.html.includes('Количество расходится'));
  checks++;
  const tourCatalog = await page('tours.php');
  assert.ok(tourCatalog.html.includes('Продукты и программы'));
  assert.ok(tourCatalog.html.includes('name="csrf_token"'));
  checks++;
  const archivedRoute = await page('route.php', {id:1}, {}, false, {setup:['UPDATE tours_catalog SET is_archived=1 WHERE id=1']});
  assert.equal(archivedRoute.status,404);
  assert.ok(archivedRoute.html.includes('больше не доступен'));
  checks++;
  const validTicket = await page('ticket.php', {token:'b'.repeat(32)}, {}, false, {setup:[`UPDATE participants SET ticket_token='${'b'.repeat(32)}' WHERE id=1`]});
  assert.ok(validTicket.html.includes('Тестовый тур'));
  checks++;
  const cancelledTicket = await page('ticket.php', {token:'c'.repeat(32)}, {}, false, {setup:[`UPDATE participants SET ticket_token='${'c'.repeat(32)}' WHERE id=3`]});
  assert.equal(cancelledTicket.status,404);
  assert.ok(cancelledTicket.html.includes('ссылка недействительна'));
  checks++;
  const eventWithTicket = await page('event.php', {id:1}, {}, false, {setup:[`UPDATE participants SET ticket_token='${'d'.repeat(32)}' WHERE id=1`]});
  assert.ok(eventWithTicket.html.includes(`ticket.php?token=${'d'.repeat(32)}`));
  checks++;
  const safeTourGet = await page('tours.php', {archive_tour:1});
  assert.equal(Number(safeTourGet.data.tours_catalog[0].is_archived), 0);
  checks++;
  const tourCsrf = await page('tours.php', {}, {archive_tour:1,csrf_token:''});
  assert.equal(tourCsrf.status, 403);
  assert.equal(Number(tourCsrf.data.tours_catalog[0].is_archived), 0);
  checks++;
  const tourBuilder = await page('tour_builder.php', {id:1});
  assert.ok(tourBuilder.html.includes('Конструктор маршрута'));
  assert.ok(tourBuilder.html.includes('name="csrf_token"'));
  assert.ok(tourBuilder.html.includes('tour-builder-workspace.css'));
  assert.ok(tourBuilder.html.includes('name="max_group_size"'));
  checks++;
  const builderCsrf = await page('tour_builder.php', {id:1}, {save_module_ajax:1,module_id:0,title:'Новый этап',timing:'10:00',content:'Описание',csrf_token:''});
  assert.equal(builderCsrf.status, 403);
  assert.equal((builderCsrf.data.tour_modules ?? []).length, 0);
  checks++;
  const analytics = await page('analytics.php', {date_from:'2026-09-01',date_to:'2026-09-30'});
  assert.equal(analytics.data.total_seats, 6);
  assert.ok(analytics.html.includes('assets/analytics-workspace.css'));
  assert.ok(analytics.html.includes('Забронировано мест'));
  assert.ok(analytics.html.includes('01.09.2026 — 30.09.2026'));
  assert.ok(analytics.html.includes('Финансовый отчёт'));
  assert.ok(analytics.html.includes('Фактический доход'));
  assert.ok(analytics.html.includes('Осталось собрать'));
  assert.ok(analytics.html.includes('Стоимость бронирований'));
  checks++;
  const normalizedAnalytics = await page('analytics.php', {date_from:'2026-09-30',date_to:'2026-09-01',stat_year:'9999'});
  assert.equal(normalizedAnalytics.data.total_seats, 6);
  assert.ok(normalizedAnalytics.html.includes('01.09.2026 — 30.09.2026'));
  checks++;
  const settings = await page('settings.php');
  assert.ok(settings.html.includes('Управление сервисом'));
  assert.ok(settings.html.includes('assets/settings-workspace.css'));
  assert.ok(settings.html.includes('name="csrf_token"'));
  assert.ok(settings.html.includes('type="password"'));
  checks++;
  const deletedParticipant = await page('event.php', {id:1}, {del_participant:1});
  assert.equal(deletedParticipant.data.participants.some(p => Number(p.id) === 1), false);
  assert.equal(deletedParticipant.data.activity_log.at(-1).entity_type, 'participant');
  assert.ok(JSON.parse(deletedParticipant.data.activity_log.at(-1).snapshot).participant.client_name.includes('Тестовый турист'));
  checks++;
  const deletedEvent = await page('index.php', {}, {delete_event:1});
  assert.equal(deletedEvent.data.all_events.some(e => Number(e.id) === 1), false);
  const eventDeletion = deletedEvent.data.activity_log.at(-1);
  assert.equal(eventDeletion.entity_type, 'event');
  assert.equal(JSON.parse(eventDeletion.snapshot).participants.length, 3);
  assert.equal(JSON.parse(eventDeletion.snapshot).expenses.length, 2);
  assert.deepEqual(JSON.parse(eventDeletion.snapshot).payments, []);
  checks++;
  const history = await page('history.php');
  assert.ok(history.html.includes('История изменений'));
  assert.ok(history.html.includes('Последние 200 действий'));
  assert.ok(history.html.includes('assets/history-workspace.css'));
  assert.ok(history.html.includes('class="container"'));
  assert.ok(history.html.includes('class="eyebrow"'));
  checks++;
  const restoreSnapshot = JSON.stringify({participant:{id:1,event_id:1,client_name:'Восстановленный турист',phone:'70000000001',email:'',seats:1,places:1,price:1200,source:'CRM',status:'Бронь',notes:'',ticket_token:'restore-token'}}).replaceAll("'", "''");
  const restoredParticipant = await page('history.php', {}, {restore_activity:1}, false, {setup:[
    'DELETE FROM participants WHERE id=1',
    `INSERT INTO activity_log (id,user_name,action,entity_type,entity_id,summary,snapshot) VALUES (1,'Администратор','delete','participant',1,'Удалено бронирование','${restoreSnapshot}')`
  ]});
  assert.equal(restoredParticipant.data.participants.find(p => Number(p.id) === 1).client_name, 'Восстановленный турист');
  assert.ok(restoredParticipant.data.activity_log.find(row => Number(row.id) === 1).restored_at);
  assert.equal(restoredParticipant.data.activity_log.at(-1).action, 'restore');
  checks++;
  const paymentRestoreSnapshot = JSON.stringify({payment:{id:7,event_id:1,participant_id:1,operation:'payment',amount:700,method:'cash',paid_at:'2026-09-05',note:'',created_by:'Администратор',created_at:'2026-09-05 12:00:00',voided_at:null,voided_by:null}}).replaceAll("'", "''");
  const restoredPayment = await page('history.php', {}, {restore_activity:2}, false, {setup:[
    `INSERT INTO activity_log (id,user_name,action,entity_type,entity_id,summary,snapshot) VALUES (2,'Администратор','delete','payment',7,'Удалён платёж','${paymentRestoreSnapshot}')`
  ]});
  assert.equal(restoredPayment.data.payments.find(row => Number(row.id) === 7).amount, 700);
  assert.ok(restoredPayment.data.activity_log.find(row => Number(row.id) === 2).restored_at);
  checks++;
  const safeSettingsGet = await page('settings.php', {del_source:1});
  assert.equal(safeSettingsGet.data.booking_sources.length, 2);
  checks++;
  const settingsCsrf = await page('settings.php', {}, {del_source:1,csrf_token:''});
  assert.equal(settingsCsrf.status, 403);
  assert.equal(settingsCsrf.data.booking_sources.length, 2);
  checks++;
  const feed = await page('calendar_feed.php', {token:'fixture-token'});
  const unfoldedFeed = feed.html.replace(/\r\n /g,'');
  assert.ok(unfoldedFeed.includes('историческую усадьбу [Гид А] (3 чел.)'));
  assert.ok(unfoldedFeed.includes('(2 чел.)'));
  assert.ok(unfoldedFeed.includes('UID:event-5@chokudatour.ru'), 'calendar includes departures without bookings');
  assert.ok(unfoldedFeed.includes('DTSTART;TZID=Europe/Moscow:20260905T100000'));
  checks++;
  const escapedFeed = await page('calendar_feed.php', {token:'fixture-token'}, {}, false, {setup:["UPDATE events SET notes='Строка 1" + "\n" + "SUMMARY:Подмена' WHERE id=1"]});
  assert.ok(escapedFeed.html.replace(/\r\n /g,'').includes('Строка 1\\nSUMMARY:Подмена'));
  checks++;
  const archive = JSON.parse((await page('index.php', {}, {ajax_load_past:1,offset:0}, false, {setup:["UPDATE events SET completed_at='2026-08-02 12:00:00', completed_by='Тест' WHERE id=4"]})).html);
  assert.equal(archive.status, 'success');
  assert.equal(archive.count, 1);
  assert.ok(archive.html.includes('4 чел.'));
  checks++;
  const pastParticipants = JSON.parse((await page('participants.php', {}, {ajax_load_past_participants:1,offset:0})).html);
  assert.equal(pastParticipants.status, 'success');
  assert.equal(pastParticipants.count, 1);
  checks++;

  const fields = {client_name:'Новая бронь',phone:'70000000001',email:'new@example.invalid',seats:5,price:2500,source:'CRM',status:'Бронь',notes:'Тест'};
  const details = {tour_date:'2026-09-08',time:'14:35',tour_id:1,guide:'Гид А',notes:'Проверка'};
  const newEvent = await page('index.php', {}, {ajax_add_event:1,...details});
  assert.equal(JSON.parse(newEvent.html).status, 'success');
  assert.equal(newEvent.data.all_events.at(-1).time, '14:35');
  assert.equal(newEvent.data.notifications[0].events, 6);
  assert.ok(newEvent.data.notifications[0].message.includes('14:35'));
  checks++;
  const defaultTime = await page('index.php', {}, {ajax_add_event:1,...details,time:''});
  assert.equal(defaultTime.data.all_events.at(-1).time, '10:00'); checks++;
  const notificationFailure = await page('index.php', {}, {ajax_add_event:1,...details}, false, {notificationError:true});
  assert.equal(JSON.parse(notificationFailure.html).status, 'success');
  assert.equal(notificationFailure.data.all_events.length, 6); checks++;
  for (const invalid of [{time:'25:70'},{tour_date:'2026-02-30'},{tour_id:999},{guide:'Чужой'},{csrf_token:''}]) {
    const result = await page('index.php', {}, {ajax_add_event:1,...details,...invalid});
    assert.equal(JSON.parse(result.html).status, 'error');
    assert.equal(result.data.all_events.length, 5);
    assert.equal(result.data.notifications.length, 0); checks++;
  }
  const edit = await page('index.php', {tour_filter:1,guide_filter:'Гид А',sort:'guide',dir:'desc'}, {update_event:1,event_id:1,...details});
  assert.equal(edit.data.all_events[0].time, '14:35');
  assert.equal(edit.headers.location[0], 'index.php?tour_filter=1&guide_filter=%D0%93%D0%B8%D0%B4%20%D0%90&sort=guide&dir=desc'); checks++;
  const evilReturn = await page('index.php', {}, {update_event:1,event_id:1,...details,return_to:'https://example.invalid/'});
  assert.equal(evilReturn.headers.location[0], 'index.php'); checks++;
  const filteredHistory = JSON.parse((await page('index.php', {}, {ajax_load_past:1,offset:0,tour_filter:2})).html);
  assert.equal(filteredHistory.count, 0); checks++;
  const sameDaySetup = ["UPDATE events SET completed_at='2026-08-02 12:00:00' WHERE id=4", "INSERT INTO events (id,tour_date,time,tour_id,guide,completed_at) VALUES (10,'2026-08-02','10:00',1,'Гид А','2026-08-02 12:00:00'),(11,'2026-08-02','10:00',1,'Гид А','2026-08-02 12:00:00'),(12,'2026-08-02','10:00',1,'Гид А','2026-08-02 12:00:00'),(13,'2026-08-02','10:00',1,'Гид А','2026-08-02 12:00:00'),(14,'2026-08-02','10:00',1,'Гид А','2026-08-02 12:00:00'),(15,'2026-08-02','10:00',1,'Гид А','2026-08-02 12:00:00')"];
  const ids = [];
  for (const offset of [0,5]) {
    const response = JSON.parse((await page('index.php', {}, {ajax_load_past:1,offset,tour_filter:1,guide_filter:'Гид А'},false,{setup:sameDaySetup})).html);
    ids.push(...[...response.html.matchAll(/class='view_e_(\d+)/g)].map(m=>Number(m[1])));
  }
  assert.equal(ids.length,7); assert.equal(new Set(ids).size,7); checks++;
  for (const sort of ['tour_date','tour_name','guide']) {
    for (const dir of ['asc','desc']) {
      const sorted = await page('index.php', {sort,dir});
      const values = sorted.data.events.map(e=>e[sort]);
      const expected = [...values].sort(); if (dir === 'desc') expected.reverse();
      assert.deepEqual(values,expected); checks++;
    }
  }
  const specialName = await page('index.php', {}, {}, false, {setup:["UPDATE participants SET client_name='Анна :: Борис || Семья <тест>' WHERE id=1"]});
  assert.ok(specialName.html.includes('Анна :: Борис || Семья &lt;тест&gt;'));
  assert.ok(specialName.html.includes('return_to=')); checks++;
  const invalidDates = await page('index.php', {date_from:'2026-09-30',date_to:'2026-09-01'});
  assert.equal(invalidDates.data.dash_tours,0); assert.ok(invalidDates.html.includes('Дата начала должна')); checks++;
  const unassigned = await page('index.php', {guide_filter:'Не назначен'}, {}, false, {setup:["UPDATE events SET guide='Не назначен (Нет свободных)' WHERE id=1"]});
  assert.equal(unassigned.data.dash_tours,1); checks++;
  assert.equal(event.data.total_expenses,300.75); checks++;
  assert.ok(event.html.includes('05.09.2026'));
  assert.ok(!event.html.includes('Жду вас завтра'));
  assert.ok(event.html.includes('name="csrf_token"')); checks++;
  for (const [name,get,post] of [
    ['event.php',{id:2},{add_participant:1,...fields}],
    ['participants.php',{}, {update_participant:1,participant_id:4,...fields}],
    ['participants.php',{}, {del_participant:4,participant_id:1}],
    ['index.php',{}, {add_expense:1,event_id:2,amount:100}],
  ]) {
    const denied = await page(name,get,post,false,{role:'guide',name:'Гид А'});
    assert.equal(denied.status,403); assert.equal(denied.data.participants.length,6); assert.equal(denied.data.expenses.length,7); checks++;
  }
  for (const [name,get] of [['index.php',{delete_event:1}],['event.php',{id:1,del_participant:1,del_expense:1}],['participants.php',{del_participant:1}]]) {
    const untouched = await page(name,get);
    assert.equal(untouched.data.participants.length,6); assert.equal(untouched.data.expenses.length,7); checks++;
  }
  const deleted = await page('index.php', {tour_filter:1}, {delete_event:1});
  assert.equal(deleted.data.all_events.length,4); assert.equal(deleted.data.participants.length,3); assert.equal(deleted.data.expenses.length,5); checks++;
  for (const [name,post] of [
    ['index.php',{ajax_load_past:1,delete_event:1}],
    ['participants.php',{ajax_load_past_participants:1,del_participant:1}],
  ]) {
    const denied = await page(name,{}, {...post,csrf_token:''});
    assert.equal(denied.status,403); assert.equal(denied.data.participants.length,6); assert.equal(denied.data.all_events.length,5); checks++;
  }
  const publicFields = {...fields,create_booking:1,tour_id:1,booking_date:'2026-09-05'};
  const fullGroup = await page('widget.php',{}, {...publicFields,seats:1},false,{setup:["UPDATE tours_catalog SET max_group_size=3 WHERE id=1"]});
  assert.equal(fullGroup.data.participants.length,6);
  assert.ok(fullGroup.html.includes('недостаточно свободных мест'));
  checks++;
  const manualOverCapacity = await page('event.php',{id:1},{add_participant:1,...fields,seats:1},false,{setup:["UPDATE tours_catalog SET max_group_size=3 WHERE id=1"]});
  assert.equal(manualOverCapacity.status,422);
  assert.equal(manualOverCapacity.data.participants.length,6);
  assert.ok(manualOverCapacity.html.includes('Недостаточно свободных мест'));
  checks++;
  for (const invalid of [{tour_id:999},{booking_date:'2026-09-04'},{booking_date:'2026-02-30'},{seats:0},{seats:1.5},{email:'bad email'},{phone:'abc'},{booking_token:''},{tour_id:2,seats:5}]) {
    const refused = await page('widget.php',{}, {...publicFields,...invalid});
    assert.equal(refused.data.participants.length,6); assert.equal(refused.data.all_events.length,5); assert.equal(refused.data.notifications.length,0); checks++;
  }
  for (const setup of [
    ["INSERT INTO blocked_dates (block_date,action_type,tours) VALUES ('2026-09-05','close','1')"],
    ["INSERT INTO guide_timeoffs (guide_name,date_off) VALUES ('Гид А','2026-09-05')"],
    ["UPDATE guides SET allowed_tours='2'"],
    ["UPDATE tours_catalog SET is_archived=1 WHERE id=1"],
    ["UPDATE global_settings SET setting_value='' WHERE setting_key='working_days'"],
    ["CREATE TRIGGER fail_booking BEFORE INSERT ON participants BEGIN SELECT RAISE(ABORT,'test failure'); END"],
  ]) {
    const refused = await page('widget.php',{},publicFields,false,{setup});
    assert.equal(refused.data.participants.length,6); assert.equal(refused.data.all_events.length,5); checks++;
  }
  const newPublic = await page('widget.php',{}, {...publicFields,booking_date:'2026-09-08'});
  assert.equal(newPublic.data.all_events.length,6); assert.equal(newPublic.data.all_events.at(-1).time,'10:00'); assert.match(newPublic.data.participants.at(-1).ticket_token,/^[a-f0-9]{32}$/); assert.ok(!newPublic.html.includes('tourSelect.addEventListener')); checks++;
  const openOverride = await page('widget.php',{},publicFields,false,{setup:["UPDATE global_settings SET setting_value='' WHERE setting_key='working_days'", "INSERT INTO blocked_dates (block_date,action_type,tours) VALUES ('2026-09-05','open','1')"]});
  assert.equal(openOverride.data.participants.length,7); checks++;
  const individual = await page('widget.php',{}, {...publicFields,tour_id:2,seats:3,booking_date:'2026-09-08'});
  assert.equal(individual.data.participants.at(-1).price,2000); assert.equal(individual.data.all_events.at(-1).time,'11:00'); checks++;
  const rollbackPublic = await page('widget.php',{}, {...publicFields,booking_date:'2026-09-08'},false,{setup:["CREATE TRIGGER fail_booking BEFORE INSERT ON participants BEGIN SELECT RAISE(ABORT,'test failure'); END"]});
  assert.equal(rollbackPublic.data.all_events.length,5); checks++;
  const duplicate = await page('widget.php',{},publicFields,false,{setup:[`INSERT INTO booking_requests VALUES ('${'a'.repeat(64)}',1)`]});
  assert.equal(duplicate.data.participants.length,6); assert.equal(duplicate.data.notifications.length,0); assert.ok(duplicate.html.includes('уже принята')); checks++;
  for (const legacy of [false,true]) {
    for (const [name,get,post,isNew] of [
      ['event.php',{id:1},{...fields,add_participant:1},true],
      ['event.php',{id:1},{...fields,update_participant:1,participant_id:1},false],
      ['participants.php',{}, {...fields,update_participant:1,participant_id:1},false],
      ['widget.php',{}, {...fields,create_booking:1,tour_id:1,booking_date:'2026-09-05'},true],
    ]) {
      const {data} = await page(name,get,post,legacy);
      const booking = isNew ? data.participants.at(-1) : data.participants[0];
      assert.equal(booking.seats, 5, `${name}: seats`);
      if (!legacy) assert.equal(booking.places, 5, `${name}: places`);
      assert.equal(booking.price, name === 'widget.php' ? 5000 : 2500, `${name}: price binding`);
      assert.equal(booking.client_name, fields.client_name, `${name}: name binding`);
      checks++;
    }
    const {data} = await page('index.php', {}, {}, legacy);
    assert.equal(data.dash_clients, legacy ? 14 : 12);
    checks++;
  }

  // Save the actual rendered page, with long fixture strings, for browser QA.
  const rendered = await page('index.php');
  assert.match(rendered.html, /assets\/homepage-actions\.js\?v=\d+/);
  assert.match(rendered.html, /assets\/homepage\.js\?v=\d+/);
  checks++;
  if (process.env.CRM_TEST_HTML) {
    await writeFile(process.env.CRM_TEST_HTML, rendered.html);
    await writeFile(process.env.CRM_TEST_HTML.replace(/\.html$/, '-archive.html'), rendered.html.replace('<tbody id="eventsTableBody">', '<tbody id="eventsTableBody">' + archive.html));
  }
  console.log(`PASS: ${files.length} PHP files parsed; ${checks} regression checks (PHP 8.4 / SQLite fixture).`);
} finally {
  php.exit();
}
