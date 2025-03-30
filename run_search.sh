#!/bin/bash

# Get parameters from command line
JOB_ID=$1
PROTEIN_FAMILY=$2
TAXONOMIC_GROUP=$3

# Construct query (properly escape quotes)
#QUERY="($PROTEIN_FAMILY) AND $TAXONOMIC_GROUP[Organism]"
QUERY="\"$PROTEIN_FAMILY\" AND \"$TAXONOMIC_GROUP\""
# Get database credentials from login.php
DB_CREDENTIALS=$(php -r 'require_once "login.php"; echo "$hostname\n$database\n$username\n$password\n";')
read -r HOSTNAME DATABASE USERNAME PASSWORD <<< "$DB_CREDENTIALS"

# Run the search and fetch sequences
SEQUENCES=$(/home/s2713107/edirect/esearch -db protein -query "$QUERY" | /home/s2713107/edirect/efetch -format fasta)

# Check if we got any sequences
if [ -z "$SEQUENCES" ]; then
    STATUS="failed"
else
    STATUS="completed"
fi

# Connect to MySQL and store results
mysql -h "$HOSTNAME" -u "$USERNAME" -p"$PASSWORD" "$DATABASE" <<EOF
UPDATE searches 
SET 
    status = '$STATUS', 
    sequences = $(mysql -h "$HOSTNAME" -u "$USERNAME" -p"$PASSWORD" "$DATABASE" -e "SELECT QUOTE('$SEQUENCES')" | tail -n 1),
    completed_at = NOW() 
WHERE job_id = '$JOB_ID';
EOF

echo "Search $STATUS for job $JOB_ID"
