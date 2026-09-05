<?php
// SQLite test adapter: only translates MySQL syntax, without changing filters,
// joins, aggregates, parameter bindings or application request handlers.
class FixturePDO extends PDO
{
    public function __construct(bool $legacy = false)
    {
        $database = $GLOBALS['fixture_database'] ?? ':memory:';
        $existing = $database !== ':memory:' && file_exists($database);
        parent::__construct('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->sqliteCreateFunction('CURDATE', fn() => '2026-09-04', 0);
        $this->sqliteCreateFunction('CONCAT', fn(...$values) => implode('', $values));
        $this->sqliteCreateFunction('MONTH', fn($date) => (int)substr($date, 5, 2), 1);
        $this->sqliteCreateFunction('YEAR', fn($date) => (int)substr($date, 0, 4), 1);
        if ($existing) return;
        $this->exec("CREATE TABLE tours_catalog (id INTEGER PRIMARY KEY, name TEXT, public_name TEXT, sort_order INT DEFAULT 0, default_start_time TEXT, tour_type TEXT, prices TEXT, is_archived INT DEFAULT 0, duration TEXT, coordinates TEXT)");
        $this->exec("CREATE TABLE events (id INTEGER PRIMARY KEY, tour_date TEXT, time TEXT DEFAULT '10:00', tour_id INT, guide TEXT, notes TEXT DEFAULT '')");
        $this->exec("CREATE TABLE participants (id INTEGER PRIMARY KEY, event_id INT, client_name TEXT, phone TEXT, email TEXT, seats INT DEFAULT 1, " . ($legacy ? '' : 'places INT DEFAULT 1, ') . "price INT, source TEXT, status TEXT, notes TEXT, ticket_token TEXT)");
        $this->exec("CREATE TABLE expenses (id INTEGER PRIMARY KEY, event_id INT, amount DECIMAL(10,2), category TEXT, description TEXT, receipt_path TEXT)");
        $this->exec("CREATE TABLE guides (name TEXT, sort_order INT DEFAULT 0, allowed_tours TEXT, color TEXT)");
        $this->exec("CREATE TABLE booking_sources (id INTEGER PRIMARY KEY, name TEXT, sort_order INT DEFAULT 0)");
        $this->exec("CREATE TABLE booking_statuses (id INTEGER PRIMARY KEY, name TEXT, color TEXT, sort_order INT DEFAULT 0)");
        $this->exec("CREATE TABLE expense_categories (id INTEGER PRIMARY KEY, name TEXT, sort_order INT DEFAULT 0)");
        $this->exec("CREATE TABLE global_settings (setting_key TEXT, setting_value TEXT)");
        $this->exec("CREATE TABLE guide_timeoffs (id INTEGER PRIMARY KEY, guide_name TEXT, date_off TEXT, reason TEXT)");
        $this->exec("CREATE TABLE blocked_dates (id INTEGER PRIMARY KEY, block_date TEXT, tour_ids TEXT, reason TEXT, action_type TEXT, tours TEXT)");
        $this->exec("INSERT INTO tours_catalog (id, name, public_name, default_start_time, tour_type, prices) VALUES (1, 'Длинное название экскурсии в историческую усадьбу', 'Тестовый тур', '10:00', 'Групповая', '{\"1\":1000}'), (2, 'Второй тур', 'Второй тур', '11:00', 'Индивидуальная', '{\"1\":2000}')");
        $this->exec("INSERT INTO guides (name, allowed_tours) VALUES ('Гид А', 'all'), ('Гид Б', 'all')");
        $this->exec("INSERT INTO booking_sources (id,name) VALUES (1,'Сайт'), (2,'CRM')");
        $this->exec("INSERT INTO booking_statuses (name,color) VALUES ('Бронь','#aaaaaa'), ('Отмена','#bbbbbb')");
        $this->exec("INSERT INTO global_settings (setting_key,setting_value) VALUES ('admin_sync_token','fixture-token'), ('working_days','1,2,3,4,5,6,7')");
        $this->exec("INSERT INTO events (id,tour_date,tour_id,guide,notes) VALUES (1,'2026-09-05',1,'Гид А','ОченьДлинноеПримечаниеБезПробеловДляПроверкиПереносаНаМобильномЭкране'), (2,'2026-09-06',2,'Гид Б',''), (3,'2026-10-05',1,'Гид Б',''), (4,'2026-08-01',1,'Гид А',''), (5,'2026-09-07',1,'Гид А','Без туристов, с расходами')");
        $stmt = $this->prepare('INSERT INTO participants (event_id,client_name,phone,email,seats,' . ($legacy ? '' : 'places,') . 'price,source,status,notes) VALUES (' . implode(',', array_fill(0, $legacy ? 9 : 10, '?')) . ')');
        foreach ([[1,2,1,3000,'Бронь'], [1,1,4,1000,'Бронь'], [1,8,8,9000,'Отмена'], [2,null,3,2000,'Бронь'], [3,2,2,1500,'Бронь'], [4,4,4,6000,'Бронь']] as [$event,$places,$seats,$price,$status]) {
            $stmt->execute(array_merge([$event,'Тестовый турист с длинной фамилией','70000000000','test@example.invalid',$seats], $legacy ? [] : [$places], [$price,'Сайт',$status,'']));
        }
        $this->exec("INSERT INTO expenses (event_id,amount) VALUES (1,100.25),(1,200.50),(2,400),(3,500),(4,9000),(5,50),(999,777)");
    }

    private function translate(string $sql): string
    {
        if (preg_match('/^SHOW COLUMNS FROM ([a-z_]+)/i', $sql, $match)) {
            return "SELECT name AS Field FROM pragma_table_info('{$match[1]}')";
        }
        return str_replace([" SEPARATOR '||'", 'CURDATE() - INTERVAL 15 DAY', ' FOR UPDATE'], [", '||'", "date(CURDATE(), '-15 days')", ''], $sql);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return parent::query($this->translate($query), $fetchMode, ...$fetchModeArgs);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->translate($query), $options);
    }

    public function exec(string $statement): int|false
    {
        if (str_starts_with($statement, 'SET SESSION group_concat_max_len')) return 0;
        return parent::exec(str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $statement));
    }
}

$pdo = new FixturePDO($GLOBALS['legacy_schema'] ?? false);
$current_user_id = 1;
$current_user_role = $GLOBALS['fixture_role'] ?? 'admin';
$current_user_name = $GLOBALS['fixture_name'] ?? 'Тестовый администратор';
$_SESSION = ['user_id' => $current_user_id, 'user_name' => $current_user_name, 'user_role' => $current_user_role, 'form_token' => 'fixture-token'];
