#!/bin/bash

# Configuration
HOST_ADDR="127.0.0.1"
HOST_PORT="8080"
TOKEN="changeme"
CKEY="new_user"
DISCORD="new_discord"

# cURL command to insert a new record and capture the response
response=$(curl -s -w "\nHTTP_STATUS:%{http_code}" -X POST "http://${HOST_ADDR}:${HOST_PORT}/verified" \
    -d "method=insert&ckey=${CKEY}&discord=${DISCORD}&token=${TOKEN}" \
    -H "Content-Type: application/x-www-form-urlencoded")

# Extract the body and the status code
body=$(echo "$response" | sed -e 's/HTTP_STATUS\:.*//g')
status=$(echo "$response" | tr -d '\n' | sed -e 's/.*HTTP_STATUS://')

# Output the response
echo "Response Body: $body"
echo "HTTP Status Code: $status"
