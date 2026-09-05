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
  php.writeFile('/app/fixture.php', await readFile(new URL('fixture.php', import.meta.url)));
  php.writeFile('/app/auth.php', "<?php require_once __DIR__ . '/fixture.php';");
  php.writeFile('/app/db.php', "<?php require_once __DIR__ . '/fixture.php';");
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
          $data['notifications'] = $GLOBALS['notifications'] ?? [];
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
    [{}, [4,8,7500,1250.75,6249.25]],
    [{date_from:'2026-09-01',date_to:'2026-09-30'}, [3,6,6000,750.75,5249.25]],
    [{date_from:'2026-09-05',date_to:'2026-09-05'}, [1,3,4000,300.75,3699.25]],
    [{tour_filter:1}, [3,5,5500,850.75,4649.25]],
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
  checks++;
  const calendar = await page('schedule.php', {ym:'2026-09'});
  assert.equal(Number(calendar.data.events_raw.find(e => e.id === 1).seats_count), 3);
  checks++;
  const participants = await page('participants.php');
  assert.equal(participants.data.total_seats, 8);
  assert.ok(participants.html.includes('Количество расходится'));
  checks++;
  const analytics = await page('analytics.php', {date_from:'2026-09-01',date_to:'2026-09-30'});
  assert.equal(analytics.data.total_seats, 6);
  checks++;
  const feed = await page('calendar_feed.php', {token:'fixture-token'});
  assert.ok(feed.html.includes('историческую усадьбу [Гид А] (3 чел.)'));
  assert.ok(feed.html.includes('(2 чел.)'));
  checks++;
  const archive = JSON.parse((await page('index.php', {}, {ajax_load_past:1,offset:0})).html);
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
  const sameDaySetup = ["INSERT INTO events (id,tour_date,time,tour_id,guide) VALUES (10,'2026-08-02','10:00',1,'Гид А'),(11,'2026-08-02','10:00',1,'Гид А'),(12,'2026-08-02','10:00',1,'Гид А'),(13,'2026-08-02','10:00',1,'Гид А'),(14,'2026-08-02','10:00',1,'Гид А'),(15,'2026-08-02','10:00',1,'Гид А')"];
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
  assert.equal(newPublic.data.all_events.length,6); assert.equal(newPublic.data.all_events.at(-1).time,'10:00'); assert.ok(!newPublic.html.includes('tourSelect.addEventListener')); checks++;
  const openOverride = await page('widget.php',{},publicFields,false,{setup:["UPDATE global_settings SET setting_value='' WHERE setting_key='working_days'", "INSERT INTO blocked_dates (block_date,action_type,tours) VALUES ('2026-09-05','open','1')"]});
  assert.equal(openOverride.data.participants.length,7); checks++;
  const individual = await page('widget.php',{}, {...publicFields,tour_id:2,seats:3,booking_date:'2026-09-08'});
  assert.equal(individual.data.participants.at(-1).price,2000); assert.equal(individual.data.all_events.at(-1).time,'11:00'); checks++;
  const rollbackPublic = await page('widget.php',{}, {...publicFields,booking_date:'2026-09-08'},false,{setup:["CREATE TRIGGER fail_booking BEFORE INSERT ON participants BEGIN SELECT RAISE(ABORT,'test failure'); END"]});
  assert.equal(rollbackPublic.data.all_events.length,5); checks++;
  const duplicate = await page('widget.php',{},publicFields,false,{setup:["CREATE TABLE booking_requests (token VARCHAR(64) PRIMARY KEY, participant_id INT)",`INSERT INTO booking_requests VALUES ('${'a'.repeat(64)}',1)`]});
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
    assert.equal(data.dash_clients, legacy ? 10 : 8);
    checks++;
  }

  // Save the actual rendered page, with long fixture strings, for browser QA.
  const rendered = await page('index.php');
  if (process.env.CRM_TEST_HTML) {
    await writeFile(process.env.CRM_TEST_HTML, rendered.html);
    await writeFile(process.env.CRM_TEST_HTML.replace(/\.html$/, '-archive.html'), rendered.html.replace('<tbody id="eventsTableBody">', '<tbody id="eventsTableBody">' + archive.html));
  }
  console.log(`PASS: ${files.length} PHP files parsed; ${checks} regression checks (PHP 8.4 / SQLite fixture).`);
} finally {
  php.exit();
}
