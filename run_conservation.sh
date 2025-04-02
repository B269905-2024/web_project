#!/bin/bash

# Input parameters
JOB_ID="$1"
CONSERVATION_ID="$2"
WINDOW_SIZE="${3:-4}"

# Database credentials from config.php
CONFIG_FILE="config.php"

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

# 4. Calculate Shannon Entropy using awk (no Python)
ALIGN_LENGTH=$(head -n 1 $ALIGN_FILE | awk '{print $2}')
NUM_SEQUENCES=$(grep -c "^CLUSTAL" $ALIGN_FILE)

# Create position-by-position counts file
grep -v "^CLUSTAL" $ALIGN_FILE | grep -v "^$" | grep -v "^\s" | awk '{print $1}' | \
awk -v len=$ALIGN_LENGTH '
BEGIN {
    for (i=1; i<=len; i++) counts[i] = ""
}
{
    seq = $1
    for (i=1; i<=len; i++) {
        aa = substr(seq, i, 1)
        if (aa != "-") {
            counts[i] = counts[i] aa
        }
    }
}
END {
    for (i=1; i<=len; i++) {
        print i, counts[i]
    }
}' > position_counts.tmp

# Calculate entropy for each position and store in database
while read -r line; do
    pos=$(echo "$line" | awk '{print $1}')
    column=$(echo "$line" | awk '{print $2}')
    
    if [ -z "$column" ]; then
        entropy=0
    else
        # Count amino acids using awk
        entropy=$(echo "$column" | \
        awk '{
            len = length($0)
            delete counts
            for (i=1; i<=len; i++) {
                aa = substr($0,i,1)
                counts[aa]++
            }
            entropy = 0
            for (a in counts) {
                p = counts[a]/len
                entropy -= p * log(p)/log(2)
            }
            print entropy
        }')
    fi
    
    $MYSQL_CMD "INSERT INTO conservation_results (conservation_id, position, entropy) VALUES ($CONSERVATION_ID, $pos, $entropy) ON DUPLICATE KEY UPDATE entropy = VALUES(entropy)"
done < position_counts.tmp

# Store aligned sequences in database
grep -v "^CLUSTAL" $ALIGN_FILE | grep -v "^$" | grep -v "^\s" | awk '{print $1,$2}' | \
while read -r ncbi_id sequence; do
    $MYSQL_CMD "INSERT INTO conservation_alignments (conservation_id, ncbi_id, sequence) VALUES ($CONSERVATION_ID, '$ncbi_id', '$sequence')"
done

# Calculate statistics using mysql
STATS=$($MYSQL_CMD "SELECT 
    AVG(entropy) as mean_entropy,
    MAX(entropy) as max_entropy,
    MIN(entropy) as min_entropy,
    (SELECT position FROM conservation_results WHERE conservation_id = $CONSERVATION_ID AND entropy = (SELECT MAX(entropy) FROM conservation_results WHERE conservation_id = $CONSERVATION_ID) as max_pos,
    (SELECT position FROM conservation_results WHERE conservation_id = $CONSERVATION_ID AND entropy = (SELECT MIN(entropy) FROM conservation_results WHERE conservation_id = $CONSERVATION_ID) as min_pos
FROM conservation_results WHERE conservation_id = $CONSERVATION_ID")

MEAN_ENTROPY=$(echo "$STATS" | awk '{print $1}')
MAX_ENTROPY=$(echo "$STATS" | awk '{print $2}')
MIN_ENTROPY=$(echo "$STATS" | awk '{print $3}')
MAX_POS=$(echo "$STATS" | awk '{print $4}')
MIN_POS=$(echo "$STATS" | awk '{print $5}')

# Get top 5 conserved and variable positions
TOP_CONSERVED=$($MYSQL_CMD "SELECT position, entropy FROM conservation_results WHERE conservation_id = $CONSERVATION_ID ORDER BY entropy ASC LIMIT 5")
TOP_VARIABLE=$($MYSQL_CMD "SELECT position, entropy FROM conservation_results WHERE conservation_id = $CONSERVATION_ID ORDER BY entropy DESC LIMIT 5")

# Generate report text
REPORT_TEXT+="\n=== Shannon Entropy Results ===\n"
REPORT_TEXT+="Alignment length: $ALIGN_LENGTH residues\n"
REPORT_TEXT+="Mean entropy: $MEAN_ENTROPY bits\n"
REPORT_TEXT+="Max entropy: $MAX_ENTROPY bits (position $MAX_POS)\n"
REPORT_TEXT+="Min entropy: $MIN_ENTROPY bits (position $MIN_POS)\n"

REPORT_TEXT+="\nTop 5 most conserved positions:\n"
echo "$TOP_CONSERVED" | while read -r pos entropy; do
    REPORT_TEXT+="Position $pos: $entropy bits\n"
done

REPORT_TEXT+="\nTop 5 most variable positions:\n"
echo "$TOP_VARIABLE" | while read -r pos entropy; do
    REPORT_TEXT+="Position $pos: $entropy bits\n"
done

REPORT_TEXT+="\nAnalysis completed successfully.\n"

# Store report in database (escape single quotes for MySQL)
ESCAPED_REPORT_TEXT=$(echo "$REPORT_TEXT" | sed "s/'/''/g")
$MYSQL_CMD "INSERT INTO conservation_reports
    (conservation_id, report_text, mean_entropy, max_entropy, min_entropy, max_position, min_position)
    VALUES ($CONSERVATION_ID, '$ESCAPED_REPORT_TEXT', $MEAN_ENTROPY, $MAX_ENTROPY, $MIN_ENTROPY, $MAX_POS, $MIN_POS)"

# Clean up temporary files
rm -f $FASTA_FILE $ALIGN_FILE position_counts.tmp

# Update status to completed
$MYSQL_CMD "UPDATE conservation_jobs SET status = 'completed' WHERE conservation_id = $CONSERVATION_ID"

exit 0
