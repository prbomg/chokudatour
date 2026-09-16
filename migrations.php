<?php

function migrationDriver(PDO $pdo): string { return (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME); }

function migrationTableExists(PDO $pdo, string $table): bool
{
    if (migrationDriver($pdo) === 'sqlite') {
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?"); $stmt->execute([$table]); return (bool)$stmt->fetchColumn();
    }
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'); $stmt->execute([$table]); return (bool)$stmt->fetchColumn();
}

function migrationColumnExists(PDO $pdo, string $table, string $column): bool
{
    if (!migrationTableExists($pdo,$table)) return false;
    if (migrationDriver($pdo) === 'sqlite') {
        foreach ($pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC) as $row) if (($row['name']??'') === $column) return true;
        return false;
    }
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?'); $stmt->execute([$table,$column]); return (bool)$stmt->fetchColumn();
}

function migrationAddColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!migrationColumnExists($pdo,$table,$column)) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
}

function migrationCreate(PDO $pdo, string $table, string $mysql, string $sqlite): void
{
    if (!migrationTableExists($pdo,$table)) $pdo->exec(migrationDriver($pdo)==='sqlite' ? $sqlite : $mysql);
}

function applicationMigrations(): array
{
    return [
        1 => ['name'=>'Базовые таблицы сервиса','up'=>function(PDO $pdo): void {
            migrationCreate($pdo,'tours_catalog',"CREATE TABLE tours_catalog (id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(255) NOT NULL,public_name VARCHAR(255),duration VARCHAR(100),coordinates VARCHAR(255),sort_order INT DEFAULT 0)","CREATE TABLE tours_catalog (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,public_name TEXT,duration TEXT,coordinates TEXT,sort_order INT DEFAULT 0)");
            migrationCreate($pdo,'guides',"CREATE TABLE guides (id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(255) NOT NULL,sort_order INT DEFAULT 0,allowed_tours TEXT,color VARCHAR(50))","CREATE TABLE guides (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,sort_order INT DEFAULT 0,allowed_tours TEXT,color TEXT)");
            migrationCreate($pdo,'events',"CREATE TABLE events (id INT AUTO_INCREMENT PRIMARY KEY,tour_date DATE NOT NULL,time VARCHAR(50) DEFAULT '',tour_id INT NOT NULL,guide VARCHAR(255),notes TEXT)","CREATE TABLE events (id INTEGER PRIMARY KEY AUTOINCREMENT,tour_date TEXT NOT NULL,time TEXT DEFAULT '',tour_id INT NOT NULL,guide TEXT,notes TEXT)");
            migrationCreate($pdo,'participants',"CREATE TABLE participants (id INT AUTO_INCREMENT PRIMARY KEY,event_id INT NOT NULL,client_name VARCHAR(255) NOT NULL,phone VARCHAR(50) DEFAULT '',email VARCHAR(100) DEFAULT '',seats INT DEFAULT 1,places INT DEFAULT 1,price INT DEFAULT 0,source VARCHAR(255),status VARCHAR(100),notes TEXT,ticket_token VARCHAR(64))","CREATE TABLE participants (id INTEGER PRIMARY KEY AUTOINCREMENT,event_id INT NOT NULL,client_name TEXT NOT NULL,phone TEXT DEFAULT '',email TEXT DEFAULT '',seats INT DEFAULT 1,places INT DEFAULT 1,price INT DEFAULT 0,source TEXT,status TEXT,notes TEXT,ticket_token TEXT)");
            migrationCreate($pdo,'blocked_dates',"CREATE TABLE blocked_dates (id INT AUTO_INCREMENT PRIMARY KEY,block_date DATE NOT NULL,tour_ids VARCHAR(255),reason VARCHAR(255))","CREATE TABLE blocked_dates (id INTEGER PRIMARY KEY AUTOINCREMENT,block_date TEXT NOT NULL,tour_ids TEXT,reason TEXT)");
            migrationCreate($pdo,'users',"CREATE TABLE users (id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,email VARCHAR(100) UNIQUE NOT NULL,password VARCHAR(255) NOT NULL,role ENUM('admin','guide') NOT NULL DEFAULT 'guide')","CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,email TEXT UNIQUE NOT NULL,password TEXT NOT NULL,role TEXT NOT NULL DEFAULT 'guide')");
            migrationCreate($pdo,'expenses',"CREATE TABLE expenses (id INT AUTO_INCREMENT PRIMARY KEY,event_id INT NOT NULL,category VARCHAR(100) DEFAULT 'Прочее',amount DECIMAL(10,2) NOT NULL,description TEXT)","CREATE TABLE expenses (id INTEGER PRIMARY KEY AUTOINCREMENT,event_id INT NOT NULL,category TEXT DEFAULT 'Прочее',amount DECIMAL(10,2) NOT NULL,description TEXT)");
        }],
        2 => ['name'=>'Справочники и служебные таблицы','up'=>function(PDO $pdo): void {
            migrationCreate($pdo,'global_settings',"CREATE TABLE global_settings (setting_key VARCHAR(50) PRIMARY KEY,setting_value TEXT)","CREATE TABLE global_settings (setting_key TEXT PRIMARY KEY,setting_value TEXT)");
            migrationCreate($pdo,'statuses',"CREATE TABLE statuses (id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,sort_order INT DEFAULT 0)","CREATE TABLE statuses (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,sort_order INT DEFAULT 0)");
            migrationCreate($pdo,'booking_sources',"CREATE TABLE booking_sources (id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(255) NOT NULL,sort_order INT DEFAULT 999)","CREATE TABLE booking_sources (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,sort_order INT DEFAULT 999)");
            migrationCreate($pdo,'expense_categories',"CREATE TABLE expense_categories (id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,sort_order INT DEFAULT 0)","CREATE TABLE expense_categories (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,sort_order INT DEFAULT 0)");
            migrationCreate($pdo,'client_profiles',"CREATE TABLE client_profiles (phone VARCHAR(50) PRIMARY KEY,tags VARCHAR(255) DEFAULT '',global_note TEXT DEFAULT '')","CREATE TABLE client_profiles (phone TEXT PRIMARY KEY,tags TEXT DEFAULT '',global_note TEXT DEFAULT '')");
            migrationCreate($pdo,'tour_modules',"CREATE TABLE tour_modules (id INT AUTO_INCREMENT PRIMARY KEY,tour_id INT NOT NULL,title VARCHAR(255) NOT NULL,timing VARCHAR(255),content TEXT,image_path VARCHAR(255),sort_order INT DEFAULT 999)","CREATE TABLE tour_modules (id INTEGER PRIMARY KEY AUTOINCREMENT,tour_id INT NOT NULL,title TEXT NOT NULL,timing TEXT,content TEXT,image_path TEXT,sort_order INT DEFAULT 999)");
            migrationCreate($pdo,'guide_timeoffs',"CREATE TABLE guide_timeoffs (id INT AUTO_INCREMENT PRIMARY KEY,guide_name VARCHAR(255) NOT NULL,date_off DATE NOT NULL,reason VARCHAR(255))","CREATE TABLE guide_timeoffs (id INTEGER PRIMARY KEY AUTOINCREMENT,guide_name TEXT NOT NULL,date_off TEXT NOT NULL,reason TEXT)");
            migrationCreate($pdo,'booking_requests',"CREATE TABLE booking_requests (token VARCHAR(64) PRIMARY KEY,participant_id INT NOT NULL)","CREATE TABLE booking_requests (token TEXT PRIMARY KEY,participant_id INT NOT NULL)");
            migrationCreate($pdo,'login_attempts',"CREATE TABLE login_attempts (id INT AUTO_INCREMENT PRIMARY KEY,attempt_key CHAR(64) NOT NULL,attempted_at DATETIME NOT NULL,INDEX attempt_key_idx (attempt_key))","CREATE TABLE login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT,attempt_key TEXT NOT NULL,attempted_at TEXT NOT NULL)");
            migrationCreate($pdo,'activity_log',"CREATE TABLE activity_log (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,user_name VARCHAR(100) NOT NULL DEFAULT '',action VARCHAR(30) NOT NULL,entity_type VARCHAR(30) NOT NULL,entity_id INT,summary VARCHAR(255) NOT NULL DEFAULT '',snapshot LONGTEXT,restored_at DATETIME,restored_by VARCHAR(100),created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)","CREATE TABLE activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INT,user_name TEXT NOT NULL DEFAULT '',action TEXT NOT NULL,entity_type TEXT NOT NULL,entity_id INT,summary TEXT NOT NULL DEFAULT '',snapshot TEXT,restored_at TEXT,restored_by TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
            migrationCreate($pdo,'payments',"CREATE TABLE payments (id INT AUTO_INCREMENT PRIMARY KEY,event_id INT NOT NULL,participant_id INT NOT NULL,operation VARCHAR(20) NOT NULL DEFAULT 'payment',amount DECIMAL(10,2) NOT NULL,method VARCHAR(30) NOT NULL DEFAULT 'cash',paid_at DATE NOT NULL,note VARCHAR(500) DEFAULT '',created_by VARCHAR(100) DEFAULT '',created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,voided_at DATETIME,voided_by VARCHAR(100))","CREATE TABLE payments (id INTEGER PRIMARY KEY AUTOINCREMENT,event_id INT NOT NULL,participant_id INT NOT NULL,operation TEXT NOT NULL DEFAULT 'payment',amount DECIMAL(10,2) NOT NULL,method TEXT NOT NULL DEFAULT 'cash',paid_at TEXT NOT NULL,note TEXT DEFAULT '',created_by TEXT DEFAULT '',created_at TEXT DEFAULT CURRENT_TIMESTAMP,voided_at TEXT,voided_by TEXT)");
        }],
        3 => ['name'=>'Колонки бронирований и маршрутов','up'=>function(PDO $pdo): void {
            foreach (['phone'=>"VARCHAR(50) DEFAULT ''",'email'=>"VARCHAR(100) DEFAULT ''",'notes'=>'TEXT','ticket_token'=>'VARCHAR(64)','price'=>'INT DEFAULT 0'] as $c=>$d) migrationAddColumn($pdo,'participants',$c,$d);
            $hadSeats = migrationColumnExists($pdo, 'participants', 'seats');
            $hadPlaces = migrationColumnExists($pdo, 'participants', 'places');
            migrationAddColumn($pdo,'participants','seats','INT DEFAULT 1');
            migrationAddColumn($pdo,'participants','places','INT DEFAULT 1');
            if (!$hadSeats && $hadPlaces) $pdo->exec('UPDATE participants SET seats=places');
            if (!$hadPlaces) $pdo->exec('UPDATE participants SET places=seats');
            foreach (['included_text'=>'TEXT','not_included_text'=>'TEXT','faq_text'=>'TEXT','food_options'=>'TEXT','program'=>'TEXT','main_image'=>'VARCHAR(255)','prices'=>'TEXT','description'=>'TEXT','default_start_time'=>"VARCHAR(50) DEFAULT '10:00'",'is_archived'=>'TINYINT DEFAULT 0','difficulty'=>"VARCHAR(255) DEFAULT 'Легкая'",'tour_type'=>"VARCHAR(50) DEFAULT 'Индивидуальная'",'images'=>'TEXT','max_group_size'=>'INT DEFAULT 0','sort_order'=>'INT DEFAULT 0'] as $c=>$d) migrationAddColumn($pdo,'tours_catalog',$c,$d);
            foreach (['time'=>"VARCHAR(50) DEFAULT ''",'completed_at'=>'DATETIME','completed_by'=>'VARCHAR(100)'] as $c=>$d) migrationAddColumn($pdo,'events',$c,$d);
        }],
        4 => ['name'=>'Колонки пользователей, финансов и расписания','up'=>function(PDO $pdo): void {
            foreach (['reset_token'=>'VARCHAR(255)','reset_expires'=>'DATETIME','remember_token'=>'VARCHAR(255)'] as $c=>$d) migrationAddColumn($pdo,'users',$c,$d);
            foreach (['receipt_path'=>'VARCHAR(255)','category'=>"VARCHAR(100) DEFAULT 'Прочее'",'description'=>'TEXT'] as $c=>$d) migrationAddColumn($pdo,'expenses',$c,$d);
            foreach (['sync_token'=>'VARCHAR(64)','phone'=>"VARCHAR(50) DEFAULT ''",'sort_order'=>'INT DEFAULT 0'] as $c=>$d) migrationAddColumn($pdo,'guides',$c,$d);
            foreach (['statuses','booking_sources','expense_categories'] as $table) migrationAddColumn($pdo,$table,'sort_order','INT DEFAULT 0');
            migrationAddColumn($pdo,'blocked_dates','action_type',"VARCHAR(10) NOT NULL DEFAULT 'close'");
            migrationAddColumn($pdo,'blocked_dates','tours',"VARCHAR(255) DEFAULT 'all'");
        }],
        5 => ['name'=>'Начальные справочники и токены','up'=>function(PDO $pdo): void {
            if (!(int)$pdo->query('SELECT COUNT(*) FROM statuses')->fetchColumn()) $pdo->exec("INSERT INTO statuses(name,sort_order) VALUES ('Бронь',1),('Предоплата',2),('Оплачено',3),('Оплата на месте',4),('Отмена',5)");
            $stmt=$pdo->prepare('SELECT COUNT(*) FROM statuses WHERE name=?'); $stmt->execute(['Оплата на месте']); if (!$stmt->fetchColumn()) $pdo->prepare('INSERT INTO statuses(name,sort_order) VALUES (?,?)')->execute(['Оплата на месте',4]);
            if (!(int)$pdo->query('SELECT COUNT(*) FROM booking_sources')->fetchColumn()) $pdo->exec("INSERT INTO booking_sources(name,sort_order) VALUES ('Прямые',1),('Трипстер',2),('Спутник 8',3),('CRM',4),('Сайт',5)");
            if (!(int)$pdo->query('SELECT COUNT(*) FROM expense_categories')->fetchColumn()) $pdo->exec("INSERT INTO expense_categories(name,sort_order) VALUES ('Аренда транспорта',1),('Бензин',2),('Билеты в музей',3),('Обед',4),('Зарплата гида',5),('Другое',6)");
            $stmt=$pdo->prepare('SELECT COUNT(*) FROM global_settings WHERE setting_key=?'); $stmt->execute(['working_days']); if (!$stmt->fetchColumn()) $pdo->prepare('INSERT INTO global_settings(setting_key,setting_value) VALUES (?,?)')->execute(['working_days','2,3,4,5,6,7']);
            $stmt=$pdo->prepare('SELECT COUNT(*) FROM global_settings WHERE setting_key=?'); $stmt->execute(['client_tags']); if (!$stmt->fetchColumn()) $pdo->prepare('INSERT INTO global_settings(setting_key,setting_value) VALUES (?,?)')->execute(['client_tags','VIP,Лояльный,Семья с детьми,Сложный клиент,Черный список']);
            $ids=$pdo->query("SELECT id FROM participants WHERE ticket_token IS NULL OR ticket_token='' ")->fetchAll(PDO::FETCH_COLUMN); $update=$pdo->prepare('UPDATE participants SET ticket_token=? WHERE id=?'); foreach($ids as $id) $update->execute([bin2hex(random_bytes(16)),(int)$id]);
        }],
    ];
}

function runDatabaseMigrations(PDO $pdo): array
{
    migrationCreate($pdo,'schema_migrations',"CREATE TABLE schema_migrations (version INT PRIMARY KEY,name VARCHAR(255) NOT NULL,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)","CREATE TABLE schema_migrations (version INTEGER PRIMARY KEY,name TEXT NOT NULL,applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    if (!databasePendingMigrations($pdo)) return [];
    $lock=false;
    if (migrationDriver($pdo)==='mysql') { $lock=(bool)$pdo->query("SELECT GET_LOCK('chokudatour_schema_migrations',30)")->fetchColumn(); if(!$lock) throw new RuntimeException('Не удалось получить блокировку миграций.'); }
    try {
        $applied=array_map('intval',$pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)); $done=[];
        foreach(applicationMigrations() as $version=>$migration) {
            if(in_array($version,$applied,true)) continue;
            try { $migration['up']($pdo); $pdo->prepare('INSERT INTO schema_migrations(version,name) VALUES (?,?)')->execute([$version,$migration['name']]); $done[]=$version; }
            catch(Throwable $e) { throw new RuntimeException("Ошибка миграции {$version} ({$migration['name']}): {$e->getMessage()}",0,$e); }
        }
        return $done;
    } finally { if($lock) $pdo->query("SELECT RELEASE_LOCK('chokudatour_schema_migrations')"); }
}

function databaseSchemaVersion(PDO $pdo): int { return migrationTableExists($pdo,'schema_migrations') ? (int)$pdo->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn() : 0; }

function databasePendingMigrations(PDO $pdo): array
{
    $applied = migrationTableExists($pdo, 'schema_migrations')
        ? array_map('intval', $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN))
        : [];
    return array_values(array_diff(array_map('intval', array_keys(applicationMigrations())), $applied));
}
