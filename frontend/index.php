<?php
session_start();
header('Cache-Control: no-store, private');
header('Pragma: no-cache');

function esc($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function api($path, $method = 'GET', $body = null) {
    $ch = curl_init('http://gateway/api/' . $path);
    $headers = ['Content-Type: application/json'];
    if (!empty($_SESSION['token'])) {
        $headers[] = 'Authorization: Bearer ' . $_SESSION['token'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, json_decode($response ?: '', true), $err];
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

function csrf_input() {
    echo '<input type="hidden" name="csrf" value="' . esc($_SESSION['csrf']) . '">';
}

function field($name, $type = 'text', $value = '') {
    echo '<label>' . esc(ucfirst($name))
       . '<input required name="' . esc($name) . '" type="' . esc($type)
       . '" value="' . esc($value) . '"></label>';
}

$msg = '';
$newCredentials = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        die('Invalid form token');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        [$code, $data, $error] = api('auth/login', 'POST', [
            'username' => $_POST['username'] ?? '',
            'password' => $_POST['password'] ?? '',
        ]);
        if ($code === 200 && !empty($data['access_token'])) {
            session_regenerate_id(true);
            $_SESSION['token'] = $data['access_token'];
            $_SESSION['role'] = $data['role'];
            $_SESSION['username'] = $data['username'];
            $_SESSION['student_id'] = $data['student_id'];
            header('Location: /');
            exit;
        }
        $msg = 'Login failed: ' . ($data['detail'] ?? $error ?? 'Unknown error');
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: /');
        exit;
    } elseif ($action === 'change_password' && !empty($_SESSION['token'])) {
        [$code, $data] = api('auth/change-password', 'POST', [
            'current_password' => $_POST['current_password'] ?? '',
            'new_password' => $_POST['new_password'] ?? '',
        ]);
        $msg = $code === 200 ? 'Password changed successfully' :
            'Password change failed: ' . (is_array($data) ? ($data['detail'] ?? 'Unknown error') : 'Service unavailable');
    } elseif (($_SESSION['role'] ?? '') === 'admin') {
        $student = (int)($_POST['student_id'] ?? 0);
        $code = 400;
        $data = ['detail' => 'Unsupported action'];

        if ($action === 'create_student') {
            [$code, $data] = api('students/', 'POST', [
                'name' => trim($_POST['name'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'course' => trim($_POST['course'] ?? ''),
            ]);
        } elseif ($action === 'create_student_login') {
            $username = trim($_POST['username'] ?? '');
            [$studentCode, $studentRows] = api('students/');
            $studentExists = false;
            if ($studentCode === 200 && is_array($studentRows)) {
                foreach ($studentRows as $row) {
                    if ((int)($row['id'] ?? 0) === $student) {
                        $studentExists = true;
                        break;
                    }
                }
            }
            if (!$studentExists) {
                $data = ['detail' => 'Student ID not found (or Students service unavailable)'];
            } else {
                [$code, $data] = api('auth/users/student', 'POST', [
                    'student_id' => $student,
                    'username' => $username,
                ]);
                if ($code === 201 && is_array($data)) {
                    $newCredentials = [
                        'username' => $data['username'] ?? $username,
                        'password' => $data['temporary_password'] ?? null,
                    ];
                }
            }
        } elseif ($action === 'edit_student') {
            [$code, $data] = api('students/' . $student, 'PUT', [
                'name' => trim($_POST['name'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'course' => trim($_POST['course'] ?? ''),
            ]);
        } elseif ($action === 'delete_student') {
            [$code, $data] = api('students/' . $student, 'DELETE');
        } elseif ($action === 'add_attendance' || $action === 'edit_attendance') {
            $payload = [
                'student_id' => $student,
                'day' => $_POST['day'] ?? '',
                'status' => $_POST['status'] ?? 'Present',
            ];
            [$code, $data] = api(
                'attendance/' . ($action === 'edit_attendance' ? (int)($_POST['record_id'] ?? 0) : ''),
                $action === 'edit_attendance' ? 'PUT' : 'POST',
                $payload
            );
        } elseif ($action === 'add_marks' || $action === 'edit_marks') {
            $score = filter_var($_POST['score'] ?? '', FILTER_VALIDATE_FLOAT);
            if ($score === false || $score < 0 || $score > 100) {
                $data = ['detail' => 'Score must be between 0 and 100'];
            } else {
                $payload = [
                    'student_id' => $student,
                    'subject' => trim($_POST['subject'] ?? ''),
                    'score' => $score,
                ];
                [$code, $data] = api(
                    'marks/' . ($action === 'edit_marks' ? (int)($_POST['record_id'] ?? 0) : ''),
                    $action === 'edit_marks' ? 'PUT' : 'POST',
                    $payload
                );
            }
        } elseif ($action === 'delete_attendance' || $action === 'delete_marks') {
            $route = $action === 'delete_attendance' ? 'attendance' : 'marks';
            [$code, $data] = api($route . '/' . (int)($_POST['record_id'] ?? 0), 'DELETE');
        }

        if ($newCredentials !== null) {
            $msg = 'Student login created. Copy the temporary password below now.';
        } else {
            $detail = is_array($data) ? ($data['detail'] ?? null) : null;
            $msg = ($code >= 200 && $code < 300) ? 'Saved successfully' :
                'Request failed: ' . (is_string($detail) ? $detail : json_encode($data));
        }
    }
}

$logged = !empty($_SESSION['token']);
$admin = ($_SESSION['role'] ?? '') === 'admin';

function table_view($heading, $records, $columns, $kind = '', $accounts = []) {
    echo '<section><h2>' . esc($heading) . '</h2><div class="scroll"><table><thead><tr>';
    foreach ($columns as $col) {
        echo '<th>' . esc($col) . '</th>';
    }
    if ($kind === 'student') {
        echo '<th>Username</th><th>Password Status</th>';
    }
    if ($kind) {
        echo '<th>Actions</th>';
    }
    echo '</tr></thead><tbody>';

    foreach (($records ?: []) as $r) {
        echo '<tr>';
        foreach ($columns as $col) {
            $key = strtolower(str_replace(' ', '_', $col));
            echo '<td>' . esc($r[$key] ?? '') . '</td>';
        }
        if ($kind === 'student') {
            $account = $accounts[(int)$r['id']] ?? null;
            echo '<td>' . esc($account['username'] ?? 'No login') . '</td>';
            echo '<td>' . (!empty($account['has_saved_password']) ? 'Saved' : 'Not available') . '</td>';
        }
        if ($kind) {
            echo '<td><form method="post" onsubmit="return confirm(\'Delete this record?\')">';
            csrf_input();
            echo '<input type="hidden" name="action" value="delete_' . esc($kind) . '">';
            echo '<input type="hidden" name="record_id" value="' . esc($r['id']) . '">';
            if ($kind === 'student') {
                echo '<input type="hidden" name="student_id" value="' . esc($r['id']) . '">';
            }
            echo '<button class="danger">Delete</button></form></td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div></section>';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Student Management Microservices</title>
    <style>
        body{font-family:system-ui,Arial;background:#f3f6fb;color:#152238;margin:0}
        header{background:#132c53;color:white;padding:20px 5%}
        main{max-width:1120px;margin:25px auto;padding:0 20px}
        section{background:white;padding:20px;border-radius:12px;margin:18px 0;box-shadow:0 2px 8px #15223810}
        h1{margin:0}h2{margin-top:0}
        input,select{padding:10px;border:1px solid #cbd5e1;border-radius:6px;box-sizing:border-box;width:100%}
        label{display:block;margin:8px 0;font-size:14px;font-weight:600}
        button{background:#2563eb;color:white;border:0;border-radius:7px;padding:10px 15px;cursor:pointer}
        .danger{background:#b91c1c}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
        .scroll{overflow:auto}table{border-collapse:collapse;width:100%}
        td,th{padding:10px;text-align:left;border-bottom:1px solid #e2e8f0}th{background:#eff6ff}
        .note{padding:12px;background:#fff7da;border-radius:7px}
        .top{display:flex;justify-content:space-between;align-items:center;gap:15px}
        .inline{display:inline}.inline button{background:#475569}
        .credentials{border:2px solid #d97706;background:#fff7da;padding:18px;border-radius:9px;margin:18px 0}
        .credentials code{user-select:all;overflow-wrap:anywhere;font-size:1.1rem}
    </style>
</head>
<body>
<header>
    <div class="top">
        <div><h1>Student Management</h1><small>Microservices Demo • PHP + FastAPI + MySQL</small></div>
        <?php if ($logged): ?>
            <form method="post" class="inline">
                <?php csrf_input(); ?>
                <input type="hidden" name="action" value="logout">
                <button>Logout <?=esc($_SESSION['username'])?></button>
            </form>
        <?php endif; ?>
    </div>
</header>
<main>
    <?php if ($msg): ?><p class="note"><?=esc($msg)?></p><?php endif; ?>
    <?php if ($admin && $newCredentials !== null): ?>
        <div class="credentials">
            <strong>New student credentials — shown only on this response</strong>
            <p>Username: <code><?=esc($newCredentials['username'])?></code></p>
            <?php if (!empty($newCredentials['password'])): ?>
                <p>Temporary password: <code><?=esc($newCredentials['password'])?></code></p>
            <?php else: ?>
                <p>Password was not returned by the Auth service. Do not create a duplicate account.</p>
            <?php endif; ?>
            <p>Copy securely now. Refreshing the page will not recover this password from the form.</p>
        </div>
    <?php endif; ?>
    <?php if (!$logged): ?>
        <section style="max-width:420px;margin:55px auto">
            <h2>Sign in</h2>
            <form method="post">
                <?php csrf_input(); ?>
                <input type="hidden" name="action" value="login">
                <?php field('username'); field('password','password'); ?>
                <button>Login</button>
            </form>
            <p class="note">Demo only. See README for account names and .env passwords.</p>
        </section>
    <?php else: ?>
        <?php
        [$a, $students] = api('students/');
        [$b, $attendance] = api('attendance/');
        [$c, $marks] = api('marks/');
        if ($a !== 200 || $b !== 200 || $c !== 200) {
            echo '<p class="note">Some services are unavailable. Please check docker compose logs.</p>';
        }
        $students = is_array($students) ? $students : [];
        $attendance = is_array($attendance) ? $attendance : [];
        $marks = is_array($marks) ? $marks : [];
        $accounts = [];
        if ($admin) {
            [$credentialCode, $credentialData] = api('auth/users/students');
            if ($credentialCode === 200 && is_array($credentialData)) {
                foreach ($credentialData as $account) {
                    if (isset($account['student_id'])) {
                        $accounts[(int)$account['student_id']] = $account;
                    }
                }
            } else {
                echo '<p class="note">Student account information unavailable.</p>';
            }
        }
        ?>
        <h2><?=$admin ? 'Admin Dashboard' : 'Student Dashboard'?></h2>
        <div class="grid">
            <section><h2><?=count($students)?></h2>Students visible</section>
            <section><h2><?=count($attendance)?></h2>Attendance records</section>
            <section><h2><?=count($marks)?></h2>Mark records</section>
        </div>
        <?php if ($admin): ?>
            <section>
                <h2>Create student</h2>
                <form method="post" class="grid">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="action" value="create_student">
                    <?php field('name'); field('email','email'); field('course'); ?>
                    <button>Add student</button>
                </form>
            </section>
            <section>
                <h2>Create Student Login Account</h2>
                <form method="post" class="grid">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="action" value="create_student_login">
                    <label>Student ID<input type="number" name="student_id" min="1" required></label>
                    <label>Username<input type="text" name="username" minlength="3" maxlength="80" required></label>
                    <button type="submit">Create Login</button>
                </form>
                <p>Choose an existing Student ID from All students. An account can be created only once per student.</p>
            </section>
            <section>
                <h2>Edit student</h2>
                <form method="post" class="grid">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="action" value="edit_student">
                    <label>Student ID<input type="number" name="student_id" required min="1"></label>
                    <?php field('name'); field('email','email'); field('course'); ?>
                    <button>Update student</button>
                </form>
            </section>
            <section>
                <h2>Record attendance</h2>
                <form method="post" class="grid">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="action" value="add_attendance">
                    <label>Student ID<input type="number" name="student_id" required min="1"></label>
                    <label>Date<input type="date" name="day" required></label>
                    <label>Status<select name="status"><option>Present</option><option>Absent</option><option>Late</option></select></label>
                    <button>Save attendance</button>
                </form>
            </section>
            <section>
                <h2>Add marks</h2>
                <form method="post" class="grid">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="action" value="add_marks">
                    <label>Student ID<input type="number" name="student_id" required min="1"></label>
                    <?php field('subject'); ?>
                    <label>Score (0–100)<input type="number" name="score" min="0" max="100" step="0.01" required></label>
                    <button>Save marks</button>
                </form>
            </section>
        <?php else: ?>
            <section>
                <h2>Change Password</h2>
                <form method="post" class="grid">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="action" value="change_password">
                    <label>Current password<input type="password" name="current_password" required></label>
                    <label>New password (minimum 12 characters)<input type="password" name="new_password" minlength="12" required></label>
                    <button>Change Password</button>
                </form>
            </section>
        <?php endif; ?>
        <?php table_view($admin ? 'All students' : 'My profile', $students, ['ID','Name','Email','Course'], $admin ? 'student' : '', $accounts); ?>
        <?php table_view($admin ? 'All attendance' : 'My attendance', $attendance, ['ID','Student ID','Day','Status'], $admin ? 'attendance' : ''); ?>
        <?php table_view($admin ? 'All marks' : 'My marks', $marks, ['ID','Student ID','Subject','Score'], $admin ? 'marks' : ''); ?>
    <?php endif; ?>
</main>
</body>
</html>

