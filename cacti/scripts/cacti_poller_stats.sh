#!/bin/sh

log=/var/www/cacti/log/cacti.log

polldata=$(tail -n10000 $log | grep "SYSTEM STATS: Time:"|tail -n1|awk -F"STATS:" '{print $2}')

echo $polldata
