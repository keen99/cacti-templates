#!/bin/sh
#for xml data query.  retrieve varnish stats, and probe for varnish instances.
#requires ../resource/script_queries/get_varnish_stats.xml 

# usage:  $0 $hostname index
# usage:  $0 $hostname query [ports,]
# usage:  $0 $hostname get $stat $port

server=$1
startport=6082
stopport=6085
increment=1
arg=$2



getports(){
  #gets a list of active varnish ports
  #start at $startport and work up until $stopport
  port=$startport
  while [ x != 1 ]
   do
    if [ $port = $stopport ]; then
#   echo "hit $port, stopping"
    break
    fi
  # echo "testing $port"
    echo "banner" | nc $server $port > /dev/null
    status=$?
    if [ $status != 0 ] ; then
     echo -n  #do nothing
  #  echo "failed, $status, on $port"
  #  break
    else
     ports="$ports $port"
    fi
    port=`expr $port + $increment`
  done
}

getstats() { #this guy gets stats data out of varnish, depending on what 
 	     #is asked for.  not spectacularly efficient, but that 
	     #limitation is from cacti's data query methods.
	     #only works with the output of "stats" right now.
  port=$2
  stat=$1

	## varnish isn't as simple as memcache was - stats don't have sane names

## docu http://varnish-cache.org/wiki/StatsExplained

	## here are all the possible stats we might want, and their short names.
	case $stat in 
		conn_accepted)
			realstat="Client connections accepted"
			#effectively, "total sessions"
		;;
		reqs_recvd)
			realstat="Client requests received"
			#effectively, "total requests"
		;;
		#these don't show in stats until they exist..
		cache_hits)
			realstat="Cache hits$"
		;;
		cache_misses)
			realstat="Cache misses"
		;;
		cache_hits_pass)
			realstat="Cache hits for pass"
		;;
		backend_conn_succ)
			realstat="Backend conn. success"
		;;
		backend_conn_fail)
			realstat="Backend conn. failures"
		;;
		backend_conn_reuses)
			realstat="Backend conn. reuses"
		;;
		backend_conn_was)
			realstat="Backend conn. was closed"
		;;
		backend_conn_recyc)
			realstat="Backend conn. recycles"
		;;
		fetch_with_length)
			realstat="Fetch with Length"
		;;
		fetch_chuncked)
			realstat="Fetch chunked"
		;;
		## this is effectively "busy workers"
		n_struct_vbe_conn)
			realstat="N struct vbe_conn"
		;;
		n_struct_sess_mem)
			realstat="N struct sess_mem"
		;;
		n_struct_objectcore)
			realstat="N struct objectcore"
		;;
		n_struct_objecthead)
			realstat="N struct objecthead"
		;;
		n_struct_smf)
			realstat="N struct smf"
		;;
		n_large_free_smf)
			realstat="N large free smf"
		;;
		## this -is- the up-to-this-concurrent connections.
		## would be nice to have a busy workers..:(
		n_wkr_thrds)
			realstat="N worker threads$"
		;;
		n_wkr_thrds_not)
			realstat="N worker threads not created"
		;;
		n_wkr_thrds_created)
			realstat="N worker threads created"
		;;
		n_wkr_thrds_limited)
			realstat="N worker threads limited"
		;;
		n_qd_work_requests)
			realstat="N queued work requests"
		;;
		n_od_work_requests)
			realstat="N overflowed work requests"
		;;
		n_drp_work_requests)
			realstat="N dropped work requests"
		;;
		n_backends)
			realstat="N backends"
		;;
		obs_sent_with_write)
			realstat="Objects sent with write"
		;;
		total_sessions)
			realstat="Total Sessions" 
		;;
		total_requests)
			realstat="Total Requests"
		;;
		total_pass)
			realstat="Total pass"
		;;
		total_fetch)
			realstat="Total fetch"
		;;
		total_header_bytes)
			realstat="Total header bytes"
		;;
		total_body_bytes)
			realstat="Total body bytes"
		;;
		session_closed)
			realstat="Session Closed"
		;;
		session_herd)
			realstat="Session herd"
		;;
		shm_records)
			realstat="SHM records"
		;;
		shm_writes)
			realstat="SHM writes"
		;;
		shm_mtx_contention)
			realstat="SHM MTX contention"
		;;
		alloc_reqs)
			realstat="allocator requests"
		;;
		bytes_free)
			realstat="bytes free"
		;;
		sms_alloc_reqs)
			realstat="SMS allocator requests"
		;;
		sms_bytes_alloc)
			realstat="SMS bytes allocated"
		;;
		sms_bytes_freed)
			realstat="SMS bytes freed"
		;;
		backend_reqs)
			realstat="Backend requests made"
		;;
		vcl_total)
			realstat="N vcl total"
		;;
		vcl_avail)
			realstat="N vcl available"
		;;
		active_purges)
			realstat="N total active purges"
		;;
		new_purges)
			realstat="N new purges added"
		;;
		uptime)
			realstat="Client uptime"
		;;
	esac

#### this causes output to var/log/messages on the varnish box EVERY 
#### TIME. wtf

   ## echo "stats" | nc $server $port | grep "$realstat" 
    statout=$(echo "stats" | nc $server $port | grep "$realstat" \
     |awk '{print $1}')
     #lets make sure we got a response. this doesnt probably catch 
     #everything but it's better.  if we didnt get an answer, it's 
     #because the stat wasnt in place and so it's zero.
     if [ "x${statout}" = "x" ]; then
	echo 0
     else 
    	echo ${statout}
     fi

#    if [ ${statout} -ge 0 ]; then
#     echo ${statout}
#    else
#     echo 0
#    fi
} #end get_stats


#### this structure is written around the way the script queries request 
#### things...dont muck with it! :)

case "$arg" in 
 index)   
  #echo "index!"
  getports
  for port in $ports 
   do
    echo $port
  done
 ;;
 query)
  case "$3" in 
   ports)  #matches the 'input' section in the .xml
    getports
    for port in $ports 
     do
      echo $port:$port
    done
   ;;
  esac
 ;;
 get) 
  #### these are our actual queries, and match up with 'output' items in the xml
  getstats $3 $4
 ;;
esac
