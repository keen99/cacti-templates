#!/bin/sh
#for xml data query.  retrieve beanstalk stats, and probe for beanstalk instances.
#requires ../resource/script_queries/get_beanstalk_stats.xml 

# usage:  $0 $hostname index
# usage:  $0 $hostname query [ports,]
# usage:  $0 $hostname get $stat $port
# usage:  $0 $hostname buildxml $port

server=$1
##careful about beanstalk conflicts here
startport=11200
stopport=11400
increment=10
arg=$2


getports(){
  #gets a list of active beanstalk ports
  #start at $startport and work up until $stopport
  port=$startport
  while [ x != 1 ]
   do
    if [ $port = $stopport ]; then
#   echo "hit $port, stopping"
    break
    fi
  # echo "testing $port"
    #version not actually supported but does the job    
    printf "version\r\n" | nc $server $port > /dev/null
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

getstats() { #this guy gets stats data out of beanstalk, depending on what 
 	     #is asked for.  not spectacularly efficient, but that 
	     #limitation is from cacti's data query methods.
	     #only works with the output of "stats" right now.
  port=$2
  stat=$1
  # printf "stats\r\n" | nc $server $port | grep "$stat: " 
    statout=$(printf "stats\r\n" | nc $server $port | grep "$stat: " \
     |awk '{print $2}')
    echo ${statout}
}

buildxml() { #creates the "output" xml for cacti. not the whole thing
  port=$1
#                <get_hits>
#                        <name>get_hits</name>
#                        <direction>output</direction>
#                        <query_name>get_hits</query_name>
#                </get_hits>

## problems:  cacti only allows the fields to be so big...
## cacti has some characters it doesnt allow...

    stats=$(printf "stats\r\n" | nc $server $port | grep ": " \
     |awk '{print $1}'|sed 's/://g')
#    echo ${stats}
    for stat in $stats
    do
	echo "
                <$stat>
                        <name>$stat</name>
                        <direction>output</direction>
                        <query_name>$stat</query_name>
                </$stat>"	
    done
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
 buildxml) 
  #### these are our actual queries, and match up with 'output' items in the xml
  buildxml $3
 ;;
esac
