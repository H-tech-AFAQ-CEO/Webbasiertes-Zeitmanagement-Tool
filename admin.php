<?php
/**
 * TimeTrack Pro - Optimized Admin Panel
 * Uses SQLite + PHPMailer
 * Access via /admin or admin.php
 */

session_start();

// Load environment variables
function loadEnv($file = '.env') {
    if (!file_exists($file)) return false;
    
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, '"\'');
        $_ENV[$key] = $value;
    }
    return true;
}

loadEnv();

// Simple SQLite Database
class SimpleDB {
    private $db;
    private $dbFile = 'timetrack.db';
    
    public function __construct() {
        try {
            $this->db = new PDO('sqlite:' . $this->dbFile);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->initTables();
        } catch (PDOException $e) {
            $this->db = null;
        }
    }
    
    private function initTables() {
        $sql = "
        CREATE TABLE IF NOT EXISTS time_entries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            employee_name TEXT NOT NULL,
            work_date TEXT NOT NULL,
            project_name TEXT,
            fahrtbeginn TEXT,
            ankunft TEXT,
            arbeitsbeginn TEXT,
            pausebeginn TEXT,
            pauseende TEXT,
            arbeitsende TEXT,
            notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        
        $this->db->exec($sql);
        
        $sql = "
        CREATE TABLE IF NOT EXISTS admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT DEFAULT 'admin'
        )";
        
        $this->db->exec($sql);
        
        // Insert default admin if not exists
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM admin_users WHERE username = 'admin'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $password = $_ENV['ADMIN_PASSWORD'] ?? 'admin123';
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("INSERT INTO admin_users (username, password_hash, role) VALUES (?, ?, ?)");
            $stmt->execute(['admin', $hash, 'admin']);
        }
    }
    
    public function verifyLogin($username, $password) {
        if (!$this->db) return false;
        
        $stmt = $this->db->prepare("SELECT password_hash, role FROM admin_users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        return $user && password_verify($password, $user['password_hash']) ? $user : false;
    }
    
    public function getEntries($limit = null, $offset = 0) {
        if (!$this->db) return [];
        
        $sql = "SELECT * FROM time_entries ORDER BY work_date DESC, employee_name ASC";
        if ($limit) {
            $sql .= " LIMIT ? OFFSET ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$limit, $offset]);
        } else {
            $stmt = $this->db->prepare($sql);
            $stmt->execute();
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getStats() {
        if (!$this->db) return ['total_entries' => 0, 'employees' => 0, 'days' => 0];
        
        $stats = [];
        
        $stmt = $this->db->prepare("SELECT COUNT(*) as total FROM time_entries");
        $stmt->execute();
        $stats['total_entries'] = $stmt->fetchColumn();
        
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT employee_name) as employees FROM time_entries");
        $stmt->execute();
        $stats['employees'] = $stmt->fetchColumn();
        
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT work_date) as days FROM time_entries");
        $stmt->execute();
        $stats['days'] = $stmt->fetchColumn();
        
        return $stats;
    }
    
    public function exportCSV() {
        if (!$this->db) return '';
        
        $entries = $this->getEntries();
        
        $output = fopen('php://output', 'w');
        
        fputcsv($output, [
            'Mitarbeiter', 'Datum', 'Projekt', 'Fahrtbeginn', 'Ankunft',
            'Arbeitsbeginn', 'Pause Beginn', 'Pause Ende', 'Arbeitsende',
            'Notizen', 'Erstellt am'
        ]);
        
        foreach ($entries as $entry) {
            fputcsv($output, [
                $entry['employee_name'],
                $entry['work_date'],
                $entry['project_name'] ?? '',
                $entry['fahrtbeginn'] ? date('H:i', strtotime($entry['fahrtbeginn'])) : '',
                $entry['ankunft'] ? date('H:i', strtotime($entry['ankunft'])) : '',
                $entry['arbeitsbeginn'] ? date('H:i', strtotime($entry['arbeitsbeginn'])) : '',
                $entry['pausebeginn'] ? date('H:i', strtotime($entry['pausebeginn'])) : '',
                $entry['pauseende'] ? date('H:i', strtotime($entry['pauseende'])) : '',
                $entry['arbeitsende'] ? date('H:i', strtotime($entry['arbeitsende'])) : '',
                $entry['notes'] ?? '',
                $entry['created_at']
            ]);
        }
        
        fclose($output);
    }
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $db = new SimpleDB();
    $user = $db->verifyLogin($username, $password);
    
    if ($user) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        $_SESSION['admin_role'] = $user['role'];
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Check if logged in
$isLoggedIn = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

// Get data if logged in
$entries = [];
$stats = ['total_entries' => 0, 'employees' => 0, 'days' => 0];
if ($isLoggedIn) {
    $db = new SimpleDB();
    $entries = $db->getEntries(50); // Limit to 50 for performance
    $stats = $db->getStats();
}

// Export functionality
if (isset($_GET['export']) && $isLoggedIn) {
    $db = new SimpleDB();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="timetrack_export_' . date('Y-m-d') . '.csv"');
    $db->exportCSV();
    exit;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TimeTrack Pro - Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: #333;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            backdrop-filter: blur(10px);
        }
        
        .logo {
            font-size: 28px;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .login-form {
            background: white;
            padding: 50px;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            max-width: 420px;
            width: 100%;
            backdrop-filter: blur(10px);
        }
        
        .login-form h2 {
            text-align: center;
            margin-bottom: 30px;
            color: #333;
            font-size: 32px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }
        
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 15px;
            border: 2px solid #e1e5e9;
            border-radius: 10px;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        input[type="text"]:focus, input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            width: 100%;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .btn-secondary {
            background: #6b7280;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
            backdrop-filter: blur(10px);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e1e5e9;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.5px;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            text-align: center;
            backdrop-filter: blur(10px);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-value {
            font-size: 42px;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #666;
        }
        
        .db-info {
            background: linear-gradient(135deg, #e3f2fd, #f3e5f5);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #1565c0;
            border-left: 4px solid #667eea;
        }
        
        .user-info {
            background: rgba(255,255,255,0.1);
            padding: 8px 15px;
            border-radius: 20px;
            color: white;
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            .header {
                flex-direction: column;
                gap: 15px;
            }
            
            .login-form {
                padding: 30px;
            }
            
            .table-container {
                overflow-x: auto;
            }
            
            table {
                min-width: 600px;
            }
            
            .stats {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <?php if (!$isLoggedIn): ?>
    <div class="login-container">
        <div class="login-form">
            <h2>🔐 Admin Login</h2>
            <p style="text-align: center; margin-bottom: 30px; color: #666;">TimeTrack Pro Administration</p>
            
            <form method="post">
                <div class="form-group">
                    <label for="username">Benutzername</label>
                    <input type="text" id="username" name="username" required autocomplete="username">
                </div>
                
                <div class="form-group">
                    <label for="password">Passwort</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                
                <button type="submit" name="login" class="btn">Anmelden</button>
            </form>
        </div>
    </div>
    
    <?php else: ?>
    <div class="container">
        <div class="header">
            <div class="logo">⏰ TimeTrack Pro Admin</div>
            <div style="display: flex; align-items: center; gap: 15px;">
                <div class="user-info">
                    👤 <?= htmlspecialchars($_SESSION['admin_username']) ?>
                </div>
                <a href="?export=1" class="btn" style="width: auto;">📊 CSV Export</a>
                <a href="?logout=1" class="btn btn-secondary" style="width: auto;">🚪 Abmelden</a>
            </div>
        </div>
        
        <div class="db-info">
            💡 Verwendet SQLite-Datenbank (timetrack.db) - keine MySQL-Installation erforderlich
        </div>
        
        <div class="stats">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['total_entries'] ?></div>
                <div class="stat-label">Gesamteinträge</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['employees'] ?></div>
                <div class="stat-label">Mitarbeiter</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['days'] ?></div>
                <div class="stat-label">Arbeitstage</div>
            </div>
        </div>
        
        <div class="table-container">
            <?php if (empty($entries)): ?>
            <div class="empty-state">
                <h3>📝 Keine Einträge gefunden</h3>
                <p>Es wurden noch keine Zeitdaten erfasst.</p>
            </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Mitarbeiter</th>
                        <th>Datum</th>
                        <th>Projekt</th>
                        <th>Fahrtbeginn</th>
                        <th>Ankunft</th>
                        <th>Arbeitsbeginn</th>
                        <th>Pause</th>
                        <th>Arbeitsende</th>
                        <th>Notizen</th>
                        <th>Erstellt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($entry['employee_name']) ?></strong></td>
                        <td><?= date('d.m.Y', strtotime($entry['work_date'])) ?></td>
                        <td><?= htmlspecialchars($entry['project_name'] ?? '-') ?></td>
                        <td><?= $entry['fahrtbeginn'] ? date('H:i', strtotime($entry['fahrtbeginn'])) : '-' ?></td>
                        <td><?= $entry['ankunft'] ? date('H:i', strtotime($entry['ankunft'])) : '-' ?></td>
                        <td><?= $entry['arbeitsbeginn'] ? date('H:i', strtotime($entry['arbeitsbeginn'])) : '-' ?></td>
                        <td>
                            <?php if ($entry['pausebeginn'] && $entry['pauseende']): ?>
                                <?= date('H:i', strtotime($entry['pausebeginn'])) ?> - <?= date('H:i', strtotime($entry['pauseende'])) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td><?= $entry['arbeitsende'] ? date('H:i', strtotime($entry['arbeitsende'])) : '-' ?></td>
                        <td><?= htmlspecialchars($entry['notes'] ?? '-') ?></td>
                        <td><?= date('d.m.Y H:i', strtotime($entry['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>
