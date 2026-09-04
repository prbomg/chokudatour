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
  php.writeFile('/app/telegram.php', '<?php function sendTelegramMessage($message) {}');

  async function page(name, get = {}, post = {}, legacy = false) {
    php.writeFile('/app/request.json', JSON.stringify({get, post, legacy}));
    const response = await php.run({code: `<?php
      chdir('/app');
      $input = json_decode(file_get_contents('request.json'), true);
      $_GET = $input['get']; $_POST = $input['post'];
      $_SERVER['REQUEST_METHOD'] = $_POST ? 'POST' : 'GET';
      $_SERVER['PHP_SELF'] = '/${name}';
      $_SERVER['QUERY_STRING'] = http_build_query($_GET);
      $_SERVER['HTTP_HOST'] = 'localhost';
      $GLOBALS['legacy_schema'] = $input['legacy'];
      register_shutdown_function(function() {
        $data = ['error' => error_get_last()];
        foreach (['dash_tours','dash_clients','dash_income','dash_expenses','dash_profit','total_seats','events','events_raw'] as $key) {
          $data[$key] = $GLOBALS[$key] ?? null;
        }
        if (isset($GLOBALS['pdo'])) {
          $data['participants'] = $GLOBALS['pdo']->query('SELECT * FROM participants ORDER BY id')->fetchAll();
        }
        file_put_contents('/app/result.json', json_encode($data));
      });
      include '${name}';`});
    const data = JSON.parse(php.readFileAsText('/app/result.json'));
    assert.equal(response.exitCode, 0, `${name}: ${response.text.slice(-1800)}`);
    if (data.error && [1,4,16,64,256,4096].includes(data.error.type)) {
      assert.fail(`${name}: ${data.error.message}`);
    }
    return {data, html: response.text};
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
