<?php
/**
 * TimeTrack Pro - Optimized Backend API
 * Uses SQLite + PHPMailer for maximum simplicity
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

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

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

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
            error_log("SQLite Error: " . $e->getMessage());
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
        
        // Create admin table
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
    
    public function saveEntry($data) {
        if (!$this->db) return false;
        
        $sql = "
        INSERT INTO time_entries (
            employee_name, work_date, project_name, fahrtbeginn, ankunft,
            arbeitsbeginn, pausebeginn, pauseende, arbeitsende, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                $data['employee_name'],
                $data['work_date'],
                $data['project_name'] ?? null,
                $data['fahrtbeginn'] ?? null,
                $data['ankunft'] ?? null,
                $data['arbeitsbeginn'] ?? null,
                $data['pausebeginn'] ?? null,
                $data['pauseende'] ?? null,
                $data['arbeitsende'] ?? null,
                $data['notes'] ?? null
            ]);
        } catch (PDOException $e) {
            error_log("Save Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function getEntries() {
        if (!$this->db) return [];
        
        $sql = "SELECT * FROM time_entries ORDER BY work_date DESC, employee_name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getStats() {
        if (!$this->db) return [];
        
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
}

// Simple Email with PHPMailer
function sendEmail($to, $subject, $body, $attachmentPath = null) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['SMTP_USER'] ?? '';
        $mail->Password = $_ENV['SMTP_PASS'] ?? '';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $_ENV['SMTP_PORT'] ?? 587;
        
        // Recipients
        $mail->setFrom($_ENV['SMTP_FROM'] ?? $_ENV['SMTP_USER'], 'TimeTrack Pro');
        $mail->addAddress($to);
        
        // Content
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        // Attachment
        if ($attachmentPath && file_exists($attachmentPath)) {
            $mail->addAttachment($attachmentPath);
        }
        
        $mail->send();
        return ['success' => true, 'message' => 'Email sent successfully'];
        
    } catch (Exception $e) {
        error_log("Email Error: " . $mail->ErrorInfo);
        return ['success' => false, 'message' => 'Email failed: ' . $mail->ErrorInfo];
    }
}

// Create CSV attachment
function createCSV($data) {
    $filename = 'Zeiterfassung_' . date('Y-m-d_H-i-s') . '.csv';
    $filepath = sys_get_temp_dir() . '/' . $filename;
    
    $fp = fopen($filepath, 'w');
    
    // Header
    fputcsv($fp, ['Zeiterfassung — TimeTrack Pro']);
    fputcsv($fp, []);
    fputcsv($fp, ['Mitarbeiter:', $data['employee_name']]);
    fputcsv($fp, ['Datum:', $data['work_date']]);
    fputcsv($fp, ['Baustelle:', $data['project_name'] ?? '—']);
    fputcsv($fp, ['Notizen:', $data['notes'] ?? '—']);
    fputcsv($fp, []);
    fputcsv($fp, ['Ereignis', 'Uhrzeit', 'Datum']);
    
    // Events
    $events = [
        'fahrtbeginn' => 'Fahrtbeginn',
        'ankunft' => 'Ankunft Baustelle',
        'arbeitsbeginn' => 'Arbeitsbeginn',
        'pausebeginn' => 'Pause Beginn',
        'pauseende' => 'Pause Ende',
        'arbeitsende' => 'Arbeitsende'
    ];
    
    foreach ($events as $key => $label) {
        if (!empty($data[$key])) {
            $datetime = new DateTime($data[$key]);
            fputcsv($fp, [
                $label,
                $datetime->format('H:i:s'),
                $datetime->format('d.m.Y')
            ]);
        }
    }
    
    // Calculations
    fputcsv($fp, []);
    fputcsv($fp, ['Berechnungen', 'Dauer (hh:mm)']);
    fputcsv($fp, ['Fahrtzeit', calculateDuration($data['fahrtbeginn'] ?? null, $data['ankunft'] ?? null)]);
    fputcsv($fp, ['Arbeitszeit (brutto)', calculateDuration($data['arbeitsbeginn'] ?? null, $data['arbeitsende'] ?? null)]);
    fputcsv($fp, ['Pausenzeit', calculateDuration($data['pausebeginn'] ?? null, $data['pauseende'] ?? null)]);
    fputcsv($fp, ['Arbeitszeit (netto)', calculateNetto($data)]);
    
    fclose($fp);
    return $filepath;
}

function calculateDuration($start, $end) {
    if (!$start || !$end) return '—';
    
    $start = new DateTime($start);
    $end = new DateTime($end);
    $diff = $end->diff($start);
    
    if ($diff->invert) return '—';
    return sprintf('%02d:%02d', $diff->h, $diff->i);
}

function calculateNetto($data) {
    if (!$data['arbeitsbeginn'] || !$data['arbeitsende']) return '—';
    
    $start = new DateTime($data['arbeitsbeginn']);
    $end = new DateTime($data['arbeitsende']);
    $workTime = $end->getTimestamp() - $start->getTimestamp();
    
    if ($data['pausebeginn'] && $data['pauseende']) {
        $pauseStart = new DateTime($data['pausebeginn']);
        $pauseEnd = new DateTime($data['pauseende']);
        $pauseTime = $pauseEnd->getTimestamp() - $pauseStart->getTimestamp();
        $workTime -= $pauseTime;
    }
    
    if ($workTime < 0) return '—';
    
    $hours = floor($workTime / 3600);
    $minutes = floor(($workTime % 3600) / 60);
    return sprintf('%02d:%02d', $hours, $minutes);
}

// Main API Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid data']);
        exit;
    }
    
    // Validate required fields
    if (empty($input['employee_name']) || empty($input['work_date'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Name and date required']);
        exit;
    }
    
    // Initialize database
    $db = new SimpleDB();
    
    // Save to database
    $saved = $db->saveEntry($input);
    
    if ($saved) {
        // Prepare email
        $to = $input['recipient_email'] ?? $_ENV['DEFAULT_EMAIL'] ?? $_ENV['SMTP_FROM'];
        $subject = "Zeiterfassung: {$input['employee_name']} — {$input['work_date']}";
        
        $body = "Hallo,\n\nim Anhang finden Sie die Zeiterfassung für:\n\n";
        $body .= "Mitarbeiter: {$input['employee_name']}\n";
        $body .= "Datum: {$input['work_date']}\n";
        $body .= "Baustelle: " . ($input['project_name'] ?? '—') . "\n\n";
        
        $body .= "Fahrtzeit: " . calculateDuration($input['fahrtbeginn'] ?? null, $input['ankunft'] ?? null) . "\n";
        $body .= "Arbeitszeit: " . calculateDuration($input['arbeitsbeginn'] ?? null, $input['arbeitsende'] ?? null) . "\n";
        $body .= "Pausenzeit: " . calculateDuration($input['pausebeginn'] ?? null, $input['pauseende'] ?? null) . "\n";
        $body .= "Netto-Arbeitszeit: " . calculateNetto($input) . "\n\n";
        
        $body .= "Die Daten wurden automatisch gespeichert.\n\n";
        $body .= "Viele Grüße\nTimeTrack Pro";
        
        // Create CSV attachment
        $csvPath = createCSV($input);
        
        // Send email
        $emailResult = sendEmail($to, $subject, $body, $csvPath);
        
        // Clean up temp file
        if (file_exists($csvPath)) {
            unlink($csvPath);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Time entry saved successfully',
            'email' => $emailResult
        ]);
        
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save entry']);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>
