<?php
session_start();

if (!isset($_COOKIE['protein_search_session']) || empty($_GET['job_id']) || !is_numeric($_GET['job_id'])) {
    header("Location: home.php");
    exit();
}

$job_id = (int)$_GET['job_id'];

require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Verify user owns this job
    $stmt = $pdo->prepare("SELECT j.* FROM jobs j JOIN users u ON j.user_id = u.user_id WHERE j.job_id = ? AND u.session_id = ?");
    $stmt->execute([$job_id, $_COOKIE['protein_search_session']]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        header("Location: home.php");
        exit();
    }

    $stmt = $pdo->prepare("
        SELECT mr.*, s.ncbi_id 
        FROM motif_results mr
        JOIN sequences s ON mr.sequence_id = s.sequence_id
        WHERE mr.motif_id = (SELECT motif_id FROM motif_jobs WHERE job_id = ?)
    ");
    $stmt->execute([$job_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="motif_results_'.$job_id.'.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Sequence ID', 'Motif Name', 'Motif ID', 'Description', 'Start', 'End', 'Score', 'P-value']);
    
    foreach ($results as $row) {
        fputcsv($output, [
            $row['ncbi_id'],
            $row['motif_name'],
            $row['motif_id_code'],
            $row['description'],
            $row['start_pos'],
            $row['end_pos'],
            $row['score'],
            $row['p_value']
        ]);
    }
    
    fclose($output);
    exit();

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}
