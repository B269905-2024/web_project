#!/bin/bash

# Input parameters
JOB_ID="$1"
CONSERVATION_ID="$2"
WINDOW_SIZE="${3:-4}"

# Database credentials from config.php
CONFIG_FILE="/path/to/your/config.php"

# Parse database config from PHP file
DB_HOST=$(grep "hostname" $CONFIG_FILE | cut -d'"' -f2)
DB_NAME=$(grep "database" $CONFIG_FILE | cut -d'"' -f2)
DB_USER=$(grep "username" $CONFIG_FILE | cut -d'"' -f2)
DB_PASS=$(grep "password" $CONFIG_FILE | cut -d'"' -f2)

# Connect to MySQL
MYSQL_CMD="mysql -h$DB_HOST -u$DB_USER -p$DB_PASS $DB_NAME -N -B -e"

# Update status to running
$MYSQL_CMD "UPDATE conservation_jobs SET status = 'running' WHERE conservation_id = $CONSERVATION_ID"

# Initialize report
REPORT_TEXT="Conservation Analysis Report\n===========================\n\n"
REPORT_TEXT+="Job ID: $JOB_ID\n"
REPORT_TEXT+="Conservation ID: $CONSERVATION_ID\n"
REPORT_TEXT+="Window size: $WINDOW_SIZE\n\n"

# 1. Get sequences from database and create temporary FASTA file
$MYSQL_CMD "SELECT ncbi_id, sequence FROM sequences WHERE job_id = $JOB_ID" > sequences.tmp

FASTA_FILE="sequences_${JOB_ID}.fasta"
while read -r line; do
    ncbi_id=$(echo "$line" | cut -f1)
    sequence=$(echo "$line" | cut -f2)
    echo ">$ncbi_id" >> $FASTA_FILE
    echo "$sequence" >> $FASTA_FILE
done < sequences.tmp
rm sequences.tmp

NUM_SEQUENCES=$(grep -c ">" $FASTA_FILE)
REPORT_TEXT+="Number of sequences: $NUM_SEQUENCES\n"

# 2. Run Clustal Omega alignment
ALIGN_FILE="alignment_${JOB_ID}.aln"
REPORT_TEXT+="\nRunning Clustal Omega alignment...\n"
clustalo -i $FASTA_FILE -o $ALIGN_FILE --force --threads=1 --outfmt=clustal 2>&1 | tee -a report.tmp
REPORT_TEXT+=$(cat report.tmp)
rm report.tmp

if [ ! -s "$ALIGN_FILE" ]; then
    REPORT_TEXT+="\nERROR: Clustal Omega alignment failed\n"
    $MYSQL_CMD "UPDATE conservation_jobs SET status = 'failed' WHERE conservation_id = $CONSERVATION_ID"
    $MYSQL_CMD "INSERT INTO conservation_reports (conservation_id, report_text) VALUES ($CONSERVATION_ID, '$REPORT_TEXT')"
    rm -f $FASTA_FILE $ALIGN_FILE
    exit 1
fi

REPORT_TEXT+="\nAlignment completed successfully.\n"

# 3. Run Plotcon analysis and store results
REPORT_TEXT+="\nRunning EMBOSS Plotcon (window size: $WINDOW_SIZE)...\n"
plotcon_output=$(plotcon -sequences $ALIGN_FILE -graph none -winsize $WINDOW_SIZE -stdout 2>&1)
REPORT_TEXT+="$plotcon_output\n"

# Parse plotcon output and store in database
echo "$plotcon_output" | awk '/^[0-9]/ {print $1,$2}' | while read pos score; do
    $MYSQL_CMD "INSERT INTO conservation_results (conservation_id, position, plotcon_score) VALUES ($CONSERVATION_ID, $pos, $score)"
done

# 4. Run Shannon Entropy analysis using Python
python3 - <<EOF
import sys
import numpy as np
from Bio import AlignIO
from collections import Counter
import MySQLdb

# Database connection
db = MySQLdb.connect(host="$DB_HOST", user="$DB_USER", passwd="$DB_PASS", db="$DB_NAME")
cursor = db.cursor()

# Read alignment
alignment = AlignIO.read("$ALIGN_FILE", "clustal")
num_sequences = len(alignment)
alignment_length = alignment.get_alignment_length()

# Calculate entropy for each position
entropy_results = []
for i in range(alignment_length):
    column = str(alignment[:, i]).replace('-', '')  # Ignore gaps
    if column:
        counts = Counter(column)
        total = len(column)
        entropy = -sum((count/total) * np.log2(count/total) for count in counts.values())
    else:
        entropy = 0  # All gaps = 0 entropy

    # Store in database
    cursor.execute("""
        INSERT INTO conservation_results (conservation_id, position, entropy)
        VALUES ($CONSERVATION_ID, %s, %s)
        ON DUPLICATE KEY UPDATE entropy = VALUES(entropy)
    """, (i+1, float(entropy)))
    entropy_results.append(entropy)

# Calculate statistics
mean_entropy = np.mean(entropy_results)
max_entropy = np.max(entropy_results)
min_entropy = np.min(entropy_results)
max_pos = np.argmax(entropy_results) + 1
min_pos = np.argmin(entropy_results) + 1

# Store alignments in database
for record in alignment:
    cursor.execute("""
        INSERT INTO conservation_alignments (conservation_id, ncbi_id, sequence)
        VALUES ($CONSERVATION_ID, %s, %s)
    """, (record.id, str(record.seq)))

# Generate report text
report_text = """$REPORT_TEXT"""

report_text += "\n=== Shannon Entropy Results ===\n"
report_text += f"Number of sequences: {num_sequences}\n"
report_text += f"Alignment length: {alignment_length} residues\n"
report_text += f"Mean entropy: {mean_entropy:.3f} bits\n"
report_text += f"Max entropy: {max_entropy:.3f} bits (position {max_pos})\n"
report_text += f"Min entropy: {min_entropy:.3f} bits (position {min_pos})\n"

# Top conserved/variable positions
sorted_positions = sorted(enumerate(entropy_results), key=lambda x: x[1])
report_text += "\nTop 5 most conserved positions:\n"
for pos, ent in sorted_positions[:5]:
    report_text += f"Position {pos+1}: {ent:.3f} bits\n"

report_text += "\nTop 5 most variable positions:\n"
for pos, ent in sorted(sorted_positions[-5:], key=lambda x: -x[1]):
    report_text += f"Position {pos+1}: {ent:.3f} bits\n"

report_text += "\nAnalysis completed successfully.\n"

# Store report in database
cursor.execute("""
    INSERT INTO conservation_reports
    (conservation_id, report_text, mean_entropy, max_entropy, min_entropy, max_position, min_position)
    VALUES ($CONSERVATION_ID, %s, %s, %s, %s, %s, %s)
""", (report_text, float(mean_entropy), float(max_entropy), float(min_entropy), max_pos, min_pos))

db.commit()
db.close()
EOF

# Clean up temporary files
rm -f $FASTA_FILE $ALIGN_FILE

# Update status to completed
$MYSQL_CMD "UPDATE conservation_jobs SET status = 'completed' WHERE conservation_id = $CONSERVATION_ID"

exit 0
