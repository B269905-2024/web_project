<?php
require_once 'login.php';

if (!isset($_GET['type']) || !isset($_GET['id']) || !isset($_GET['file'])) {
    die("Invalid download request.");
}

$type = $_GET['type'];
$id = (int)$_GET['id'];
$file = $_GET['file'];

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    if ($type === 'conservation') {
        $stmt = $pdo->prepare("SELECT * FROM conservation_analysis WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch();
        
        if (!$data) {
            die("Analysis not found.");
        }
        
        switch ($file) {
            case 'entropy_json':
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="shannon_entropy.json"');
                echo $data['entropy_json'];
                break;
                
            case 'entropy_csv':
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="shannon_entropy.csv"');
                echo $data['entropy_csv'];
                break;
                
            case 'entropy_png':
                header('Content-Type: image/png');
                header('Content-Disposition: attachment; filename="shannon_entropy.png"');
                echo $data['entropy_png'];
                break;
                
            case 'plotcon':
                header('Content-Type: image/png');
                header('Content-Disposition: attachment; filename="plotcon.png"');
                echo $data['plotcon'];
                break;
                
            case 'alignment':
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="alignment.txt"');
                echo $data['alignment'];
                break;
                
            case 'report':
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="analysis_report.txt"');
                echo $data['report'];
                break;
                
            default:
                die("Invalid file type requested.");
        }
    } else {
        die("Invalid download type.");
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
