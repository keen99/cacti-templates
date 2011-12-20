#!/bin/sh

### nginx stats are not very complex and dont have many pieces 
###associated with them...

#this is where we put it
port=6092
uri=/nginx_status
host=$1
url="http://$host:$port$uri"

rawout=$(curl -s "$url")

oIFS="$IFS"
IFS=$'\12'
for line in $(echo "$rawout")
do
 #gauges
 echo "$line"|grep -q "Active" &&  activegauge=$(echo "$line"|grep "Active" |awk '{print $3}')
 echo "$line"|grep -q "Reading" && readinggauge=$(echo "$line"|grep "Reading" |awk '{print $2}')
 echo "$line"|grep -q "Writing" && writinggauge=$(echo "$line"|grep "Writing" |awk '{print $4}')
 echo "$line"|grep -q "Waiting" && waitinggauge=$(echo "$line"|grep "Waiting" |awk '{print $6}')
 #counters
 echo "$line"|grep -q "^ [0-9]" && acceptedcounter=$(echo "$line"|grep "^ [0-9]"|awk '{print $1}')
 echo "$line"|grep -q "^ [0-9]" && handledcounter=$(echo "$line"|grep "^ [0-9]"|awk '{print $2}')
 echo "$line"|grep -q "^ [0-9]" && requestscounter=$(echo "$line"|grep "^ [0-9]"|awk '{print $3}')
done


#look, even MORE ugly
echo $(set|grep -e gauge -e counter|sed 's/=/:/')

exit
Active connections: 1 
server accepts handled requests
 60 60 150 
Reading: 0 Writing: 1 Waiting: 0 

active connections -- number of all open connections including 
connections to backends

server accepts handled requests -- nginx accepted 16630948 connections, 
handled 16630948 connections (no one was closed just it was accepted), 
and handles 31070465 requests (1.8 requests per connection)

reading -- nginx reads request header

writing -- nginx reads request body, processes request, or writes 
response to a client

waiting -- keep-alive connections, actually it is active - (reading + 
writing) 
