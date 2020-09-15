#!/usr/bin/expect
set ipaddress [lindex $argv 0]
set all_jobs [lrange $argv 1 end]
set jobs [split $all_jobs " "]
log_file /tmp/expect.txt
eval spawn ssh -t -i /dithen/dithen_certs/id_rsa_root ubuntu@$ipaddress -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no
#use correct prompt
set prompt ":|#|\\\$"
expect ":~$"
sleep 1
set timeout 1800

send "cd /home/ubuntu/criumount \r"
expect ":~/criumount"
sleep 1
foreach job $jobs {
        send "sudo criu-ns restore -d -D '$job' --shell-job --tcp-established --ext-unix-sk --skip-in-flight \r"
expect {
          "Restoring FAILED"  {
                        send "sudo criu-ns restore -d -D '$job' --shell-job --tcp-established --ext-unix-sk --skip-in-flight \r"
                        sleep 1
                }
          ":~/criumount"      {
                        sleep 1
                        continue
                }

}
}
send "sudo python /bin/resumeProcess.py \r"
send "exit \r"
expect eof
