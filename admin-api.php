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


function googleRequest(string $url, array $options): array
{
    $client = curl_init($url);
    curl_setopt_array($client, $options + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($client);
    if (!is_string($body) || curl_getinfo($client, CURLINFO_RESPONSE_CODE) !== 200) {
        throw new RuntimeException('Google did not return a successful response.');
    }
    $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid Google response.');
    }
    return $data;
}

function redirectLogin(string $status = ''): never
{
    header('Location: admin.html' . ($status === '' ? '' : '?auth=' . $status), true, 303);
    exit;
}

function googleLogin(string $route, array $config): never
{
    if (empty($config['google_client_id']) || empty($config['google_client_secret']) ||
        empty($config['google_admin_email'])) {
        redirectLogin('not_configured');
    }
    $redirect = $config['origin'] . '/admin-google-callback.php';
    if ($route === 'google-start') {
        $state = bin2hex(random_bytes(32));
        $verifier = bin2hex(random_bytes(32));
        $_SESSION['google_login'] = ['state' => $state, 'verifier' => $verifier, 'expires' => time() + 600];
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $params = [
            'client_id' => $config['google_client_id'], 'redirect_uri' => $redirect,
            'response_type' => 'code', 'scope' => 'openid email', 'state' => $state,
            'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
            'prompt' => 'select_account', 'login_hint' => $config['google_admin_email'],
        ];
        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params), true, 303);
        exit;
    }

    $login = $_SESSION['google_login'] ?? null;
    unset($_SESSION['google_login']);
    $state = $_GET['state'] ?? null;
    if (!$login || $login['expires'] < time() || !is_string($state) || !hash_equals($login['state'], $state)) {
        redirectLogin('invalid');
    }
    if (isset($_GET['error'])) {
        redirectLogin('cancelled');
    }
    $code = $_GET['code'] ?? null;
    if (!is_string($code) || $code === '' || strlen($code) > 4096) {
        redirectLogin('invalid');
    }
    try {
        $token = googleRequest('https://oauth2.googleapis.com/token', [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code, 'client_id' => $config['google_client_id'],
                'client_secret' => $config['google_client_secret'], 'redirect_uri' => $redirect,
                'grant_type' => 'authorization_code', 'code_verifier' => $login['verifier'],
            ]),
        ]);
        $accessToken = $token['access_token'] ?? '';
        if (!is_string($accessToken) || !preg_match('#^[a-zA-Z0-9._~+/-]+=*$#D', $accessToken)) {
            redirectLogin('invalid');
        }
        $account = googleRequest('https://openidconnect.googleapis.com/v1/userinfo', [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        ]);
    } catch (Throwable $e) {
        redirectLogin('error');
    }

    if (($account['email_verified'] ?? false) !== true ||
        !is_string($account['email'] ?? null) ||
        strcasecmp($account['email'], $config['google_admin_email']) !== 0) {
        redirectLogin('denied');
    }
    session_regenerate_id(true);
    $_SESSION['admin_until'] = time() + 28800;
    redirectLogin();
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
    session_set_cookie_params(['httponly' => true, 'secure' => true, 'samesite' => 'Lax', 'path' => '/']);
    session_start();
    if ($method === 'GET' && in_array($route, ['google-start', 'google-callback'], true)) {
        googleLogin($route, $config);
    }
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
    if (!$authenticated) {
        respond(401, ['error' => 'Bitte zuerst anmelden.']);
    }

    $db = new PDO($config['dsn'], $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

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
