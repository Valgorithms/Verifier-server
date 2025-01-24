#!/bin/bash

# Configuration
HOST_ADDR="127.0.0.1"
HOST_PORT="8080"

# cURL command to get verified list and capture the response
response=$(curl -s -w "\nHTTP_STATUS:%{http_code}" -X GET "http://${HOST_ADDR}:${HOST_PORT}/verified" \
    -H "Content-Type: application/json")

# Extract the body and the status code
body=$(echo "$response" | sed -e 's/HTTP_STATUS\:.*//g')
status=$(echo "$response" | tr -d '\n' | sed -e 's/.*HTTP_STATUS://')

# Output the response
echo "Response Body: $body"
echo "HTTP Status Code: $status"
