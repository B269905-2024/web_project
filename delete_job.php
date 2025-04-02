<?php
session_start();

if (!isset($_COOKIE['protein_search_session']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['job_id'])) {
    header("Location: home.php");
    exit();
}

$job_id = (int)$_POST['job_id'];

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare("SELECT j.job_id FROM jobs j JOIN users u ON j.user_id = u.user_id WHERE j.job_id = ? AND u.session_id = ?");
    $stmt->execute([$job_id, $_COOKIE['protein_search_session']]);
    $job = $stmt->fetch();
    
    if ($job) {
        $stmt = $pdo->prepare("DELETE FROM sequences WHERE job_id = ?");
        $stmt->execute([$job_id]);
        
        $stmt = $pdo->prepare("DELETE FROM jobs WHERE job_id = ?");
        $stmt->execute([$job_id]);
    }
    
} catch (PDOException $e) {
    // Log error if needed
}

header("Location: past.php");
exit();
?>
