#!/bin/bash

# Input parameters
FASTA_FILE="$1"
RESULTS_DIR="$2"

# Check if input file exists
if [ ! -f "$FASTA_FILE" ]; then
    echo "FASTA file not found!"
    exit 1
fi

# Output file for motif search results
OUTPUT_FILE="$RESULTS_DIR/patmatmotifs_results.txt"

# Run EMBOSS patmatmotifs to scan sequences for motifs
patmatmotifs -sequence "$FASTA_FILE" -outfile "$OUTPUT_FILE" -full Y

# Check if patmatmotifs ran successfully
if [ ! -s "$OUTPUT_FILE" ]; then
    echo "ERROR: No motifs found or patmatmotifs failed to run correctly." > "$OUTPUT_FILE"
    exit 1
fi

# Success message
echo "Motif search completed. Results saved to $OUTPUT_FILE."
exit 0

s2713107@bioinfmsc8:~/public_html/coursework/coursework/proba6$ cat includes/db_connect.php
<?php
require_once __DIR__ . '/../login.php';

try {
    $pdo = new PDO("mysql:host=$hostname;dbname=$database", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

