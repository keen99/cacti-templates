#!/bin/sh
#for xml data query.  retrieve memcache stats, and probe for memcache instances.
#requires ../resource/script_queries/get_memcache_stats.xml 

# usage:  $0 $hostname index
# usage:  $0 $hostname query [ports,]
# usage:  $0 $hostname get $stat $port

server=$1
#beanstalk conflicts here!!!
startport=11211
stopport=11411
increment=10
arg=$2


getports(){
  #gets a list of active memcache ports
  #start at $startport and work up until $stopport
  port=$startport
  while [ x != 1 ]
   do
    if [ $port = $stopport ]; then
#   echo "hit $port, stopping"
    break
    fi
  # echo "testing $port"
    echo "version" | nc $server $port > /dev/null
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

getstats() { #this guy gets stats data out of memcache, depending on what 
 	     #is asked for.  not spectacularly efficient, but that 
	     #limitation is from cacti's data query methods.
	     #only works with the output of "stats" right now.
  port=$2
  stat=$1
   ## echo "stats" | nc $server $port | grep "STAT $stat " 
    statout=$(echo "stats" | nc $server $port | grep "STAT $stat " \
     |awk '{print $3}')
    echo ${statout}
}


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
