// Browser fixture only. Database, authentication and outbound messages are
// replaced in an isolated PHP filesystem before any application code runs.
import http from 'node:http';
import {readFile,readdir} from 'node:fs/promises';
import {PHP,loadPHPRuntime} from '@php-wasm/universal';
import {getPHPLoaderModule} from '@php-wasm/node-8-4';
const root = new URL('../',import.meta.url);
const php = new PHP(await loadPHPRuntime(await getPHPLoaderModule()));
php.mkdir('/app');
const allowed = ['index.php','event.php','client.php','participants.php','widget.php'];
for (const file of (await readdir(root)).filter(f=>f.endsWith('.php'))) php.writeFile('/app/'+file,await readFile(new URL(file,root)));
php.writeFile('/app/fixture.php',await readFile(new URL('fixture.php',import.meta.url)));
for (const file of ['auth.php','db.php']) php.writeFile('/app/'+file,"<?php require_once __DIR__ . '/fixture.php';");
php.writeFile('/app/telegram.php','<?php function sendTelegramMessage($message) { return true; }');
let queue = Promise.resolve();
const server = http.createServer((req,res) => {
  queue = queue.then(async () => {
    const url = new URL(req.url,'http://127.0.0.1:8765');
    if (['/assets/homepage.js','/assets/style.css','/assets/app.js'].includes(url.pathname)) {
      res.setHeader('Content-Type',url.pathname.endsWith('.js')?'text/javascript':'text/css');
      res.end(await readFile(new URL(url.pathname.slice(1),root))); return;
    }
    const file = url.pathname === '/' ? 'index.php' : url.pathname.slice(1);
    if (!allowed.includes(file)) { res.writeHead(404);res.end();return; }
    const chunks = []; for await (const chunk of req) chunks.push(chunk);
    const post = {};
    if (req.method === 'POST') {
      const request = new Request(url,{method:'POST',headers:req.headers,body:Buffer.concat(chunks)});
      for (const [key,value] of await request.formData()) if (typeof value === 'string') post[key] = value;
    }
    php.writeFile('/app/request.json',JSON.stringify({get:Object.fromEntries(url.searchParams),post,method:req.method,file}));
    const response = await php.run({code:`<?php
      chdir('/app'); $input=json_decode(file_get_contents('request.json'),true);
      $_GET=$input['get']; $_POST=$input['post'];
      $_SERVER['REQUEST_METHOD']=$input['method']; $_SERVER['PHP_SELF']='/'.$input['file'];
      $_SERVER['QUERY_STRING']=http_build_query($_GET); $_SERVER['HTTP_HOST']='127.0.0.1:8765';
      $GLOBALS['fixture_database']='/browser.sqlite';
      $GLOBALS['fixture_role']=$_GET['test_role'] ?? 'admin';
      $GLOBALS['fixture_name']=$GLOBALS['fixture_role']==='guide' ? 'Гид А' : 'Тестовый администратор';
      require_once 'fixture.php';
      if (!file_exists('/seeded')) {
        for($i=10;$i<22;$i++) {
          $pdo->prepare("INSERT INTO events(id,tour_date,time,tour_id,guide,notes) VALUES(?,?,'10:00',1,'Гид А','Примечание прошедшего выезда')")->execute([$i,'2026-08-'.($i)]);
          $pdo->prepare("INSERT INTO participants(event_id,client_name,phone,seats,places,price,status,source,notes) VALUES(?,'Анна :: семья || друзья','70000000000',2,2,1000,'Бронь','CRM','')")->execute([$i]);
        }
        file_put_contents('/seeded','1');
      }
      include $input['file'];`});
    res.statusCode=response.httpStatusCode;
    for (const [key,value] of Object.entries(response.headers)) res.setHeader(key,value);
    res.end(Buffer.from(response.bytes));
  }).catch(error => { console.error(error.message); if (!res.headersSent) res.statusCode=500; res.end('Fixture error'); });
});
server.listen(8765,'127.0.0.1',()=>console.log('Fixture ready: http://127.0.0.1:8765/index.php'));
process.on('SIGINT',()=>{server.close();php.exit();process.exit(0);});
