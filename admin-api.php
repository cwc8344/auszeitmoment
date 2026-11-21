<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function query(PDO $db, string $sql, array $params = []): PDOStatement
{
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return $statement;
}

function minutes(string $time): int
{
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
        throw new InvalidArgumentException('Uhrzeit als HH:MM eingeben.');
    }
    return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3);
}

function dayValue(string $day): DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
    if (!$date || $date->format('Y-m-d') !== $day) {
        throw new InvalidArgumentException('Ungültiges Datum.');
    }
    return $date;
}

function freeAt(PDO $db, string $day, string $time, int $duration, int $exclude): bool
{
    $weekday = (int) dayValue($day)->format('N') - 1;
    $start = minutes($time);
    $end = $start + $duration;
    $rules = query($db, "SELECT * FROM hours WHERE (kind='weekly' AND weekday=?) OR (kind='date' AND day=?)", [$weekday, $day])->fetchAll();
    $dateOpen = false;
    $weeklyOpen = false;
    $dateOverrides = false;
    foreach ($rules as $rule) {
        $from = minutes($rule['start_time']);
        $to = minutes($rule['end_time']);
        if (!$rule['is_open'] && $start < $to && $from < $end) {
            return false;
        }
        if ($rule['is_open'] && $rule['kind'] === 'date') {
            $dateOverrides = true;
            $dateOpen = $dateOpen || ($from <= $start && $end <= $to);
        } elseif ($rule['is_open']) {
            $weeklyOpen = $weeklyOpen || ($from <= $start && $end <= $to);
        }
    }
    if (!($dateOverrides ? $dateOpen : $weeklyOpen)) {
        return false;
    }
    $bookings = query($db, "SELECT start_time,duration FROM bookings WHERE day=? AND status='confirmed' AND id<>?", [$day, $exclude]);
    foreach ($bookings as $booking) {
        $other = minutes($booking['start_time']);
        if ($start < $other + $booking['duration'] + 15 && $other < $end + 15) {
            return false;
        }
    }
    return true;
}

function field(array $data, string $key, int $length, string $default = ''): string
{
    $value = $data[$key] ?? $default;
    if (!is_string($value) || strlen($value) > $length * 4 ||
        preg_match('/^.{0,' . $length . '}$/usD', $value) !== 1) {
        throw new InvalidArgumentException('Ungültige Eingabe: ' . $key);
    }
    return trim($value);
}

$configFile = dirname(__DIR__) . '/auszeitmoment-admin-config.php';
if (!is_file($configFile)) {
    respond(503, ['error' => 'Die Terminverwaltung wird eingerichtet.']);
}

try {
    $config = require $configFile;
    $method = $_SERVER['REQUEST_METHOD'];
    $route = $_GET['route'] ?? '';
    if (!in_array($method, ['GET', 'POST'], true)) {
        respond(405, ['error' => 'Methode nicht unterstützt.']);
    }
    session_name('az_admin');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Strict', 'path' => '/']);
    session_start();
    $authenticated = ($_SESSION['admin_until'] ?? 0) > time();
    if ($method === 'GET' && $route === 'me') {
        respond(200, ['authenticated' => $authenticated]);
    }
    $data = [];
    if ($method === 'POST') {
        if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $config['origin']) {
            respond(403, ['error' => 'Ungültige Herkunft.']);
        }
        $data = json_decode(file_get_contents('php://input', false, null, 0, 32769), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Ungültige Eingaben.');
        }
    }
    if ($method === 'POST' && $route === 'logout') {
        $_SESSION = [];
        session_destroy();
        respond(200, ['ok' => true]);
    }
    if (!$authenticated && !($method === 'POST' && $route === 'password-login')) {
        respond(401, ['error' => 'Bitte zuerst anmelden.']);
    }

    $db = new PDO($config['dsn'], $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    if ($route === 'password-login' && $method === 'POST') {
        $key = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
        $expiry = time() + 1800;
        query($db, 'DELETE FROM admin_login_limits WHERE expires_at <= ?', [time()]);
        query($db, 'INSERT INTO admin_login_limits VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE attempts=attempts+1', [$key, $expiry]);
        $attempts = query($db, 'SELECT attempts FROM admin_login_limits WHERE address_hash=?', [$key])->fetchColumn();
        if ($attempts > 5) {
            respond(429, ['error' => 'Zu viele Versuche. Bitte in 30 Minuten erneut versuchen.']);
        }
        $password = $data['password'] ?? null;
        $salt = $config['password_salt'] ?? '';
        $hash = $config['password_hash'] ?? '';
        if (!is_string($password) || strlen($password) > 128 ||
            preg_match('/^[a-f0-9]{32}$/D', $salt) !== 1 ||
            preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1 ||
            !hash_equals($hash, hash_pbkdf2('sha256', $password, hex2bin($salt), 600000, 64))) {
            respond(401, ['error' => 'Passwort nicht erkannt.']);
        }
        query($db, 'DELETE FROM admin_login_limits WHERE address_hash=?', [$key]);
        session_regenerate_id(true);
        $_SESSION['admin_until'] = time() + 28800;
        respond(200, ['ok' => true]);
    }

    if ($method === 'GET') {
        $lists = [
            'hours' => 'SELECT * FROM hours ORDER BY kind,weekday,day,start_time',
            'bookings' => 'SELECT * FROM bookings ORDER BY day DESC,start_time DESC,id DESC LIMIT 2000',
        ];
        if (isset($lists[$route])) {
            respond(200, ['items' => $db->query($lists[$route])->fetchAll()]);
        }
        respond(404, ['error' => 'Nicht gefunden.']);
    }

    // Serialize calendar writes across PHP workers.
    $lockName = hash('sha256', $config['dsn'] . $config['db_user']);
    if ((int) query($db, 'SELECT GET_LOCK(?,5)', [$lockName])->fetchColumn() !== 1) {
        respond(503, ['error' => 'Bitte erneut versuchen.']);
    }
    $id = 0;
    if ($route === 'hours') {
        $kind = $data['kind'] ?? '';
        $start = field($data, 'start_time', 5);
        $end = field($data, 'end_time', 5);
        $weekday = filter_var($data['weekday'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]);
        $day = $kind === 'date' ? dayValue(field($data, 'day', 10))->format('Y-m-d') : null;
        if (!in_array($kind, ['weekly', 'date'], true) || ($kind === 'weekly' && $weekday === false) || minutes($start) >= minutes($end)) {
            throw new InvalidArgumentException('Ungültige Arbeitszeit.');
        }
        query($db, 'INSERT INTO hours(kind,weekday,day,start_time,end_time,is_open,note) VALUES (?,?,?,?,?,?,?)',
            [$kind, $day === null ? $weekday : null, $day, $start, $end, (int) (bool) ($data['is_open'] ?? true), field($data, 'note', 120)]);
        $id = (int) $db->lastInsertId();
    } elseif (preg_match('~^hours/(\d+)/delete$~D', $route, $matches)) {
        query($db, 'DELETE FROM hours WHERE id=?', [(int) $matches[1]]);
    } elseif (preg_match('~^bookings(?:/(\d+))?$~D', $route, $matches)) {
        $id = (int) ($matches[1] ?? 0);
        $old = $id ? query($db, 'SELECT * FROM bookings WHERE id=?', [$id])->fetch() : [];
        if ($id && !$old) {
            respond(404, ['error' => 'Termin nicht gefunden.']);
        }
        $data += $old;
        $day = dayValue(field($data, 'day', 10))->format('Y-m-d');
        $start = field($data, 'start_time', 5);
        minutes($start);
        $duration = filter_var($data['duration'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 15, 'max_range' => 540]]);
        $status = $data['status'] ?? 'confirmed';
        $name = field($data, 'name', 120);
        if ($name === '' || $duration === false || !in_array($status, ['pending', 'confirmed', 'cancelled', 'completed'], true)) {
            throw new InvalidArgumentException('Ungültige Termindaten.');
        }
        if ($status === 'confirmed' && !freeAt($db, $day, $start, $duration, $id)) {
            respond(409, ['error' => 'Die Zeit liegt außerhalb der Arbeitszeiten oder ist belegt.']);
        }
        $params = [$name, field($data, 'email', 190), field($data, 'phone', 50), field($data, 'street', 200), field($data, 'city', 200),
            field($data, 'massage', 300), $duration, $day, $start, max(0, (int) ($data['price'] ?? 0)), field($data, 'notes', 2000), $status];
        if ($id) {
            query($db, 'UPDATE bookings SET name=?,email=?,phone=?,street=?,city=?,massage=?,duration=?,day=?,start_time=?,price=?,notes=?,status=? WHERE id=?', [...$params, $id]);
        } else {
            query($db, 'INSERT INTO bookings(name,email,phone,street,city,massage,duration,day,start_time,price,notes,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', $params);
            $id = (int) $db->lastInsertId();
        }
    } else {
        respond(404, ['error' => 'Nicht gefunden.']);
    }
    respond(200, ['ok' => true, 'id' => $id]);
} catch (InvalidArgumentException | JsonException $e) {
    respond(400, ['error' => $e instanceof JsonException ? 'Ungültige Eingaben.' : $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Auszeitmoment admin: ' . get_class($e));
    respond(500, ['error' => 'Die Terminverwaltung konnte die Anfrage nicht verarbeiten.']);
}
