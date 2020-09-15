import psutil
import sys
import os
import subprocess
import time



time.sleep(5)           #Timer to allow the checkpoint notification message to be seen by user
#Exit from all attached tmux sessions before checkpointing
p=subprocess.Popen("tmux -S /socket/tmux-1001/default ls -F '#S'",shell=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE) #hoping this is the default socket name
r=p.communicate()
print "STDOUT of script"
print r[0]
print "STDERR of script"
print r[1]
if r[0]:
        for line in r[0].split('\n'):
                if line:
                        p1=subprocess.Popen("tmux -S /socket/tmux-1001/default detach-client -s "+line.strip(),shell=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                        r1=p1.communicate()
                        print "STDOUT of script"
                        print r1[0]
                        print "STDERR of script"
                        print r1[1]
#                       r1=p1.communicate()



time.sleep(10)  
pidList = []
dockerList=open("/home/ubuntu/dockerList", "w")
count=0
for proc1 in psutil.process_iter():
        try:
                pinfo = proc1.as_dict(attrs=['pid','name', 'username'])
                if pinfo['username']!='root'and pinfo['username']!='ubuntu'and pinfo['username']!='messagebus' and pinfo['username']!='syslog' and pinfo['username']!='systemd-$
                        if pinfo['name']=='bash':
                                        # and os.getsid(int(pinfo['pid']))==int(pinfo['pid']):
                                print "active bash"
                                pidList.append(pinfo['pid'])
                        else:
                                print pinfo['pid'],pinfo['name'],pinfo['username']
                                pidList.append(pinfo['pid'])
                                mainString="sudo kill -19 %i" % (pinfo['pid'])
                                print mainString
                                p=subprocess.Popen(mainString,shell=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                                r=p.communicate()
                                print "STDOUT of script"
                                print r[0]
                                print "STDERR of script"
                                print r[1]
        except psutil.NoSuchProcess:
                pass
#I think it's important we checkpoint only stopped processes such that if a user starts one in between, we can still keep track #ijeoma
for proc in psutil.process_iter():
        try:
                pinfo = proc.as_dict(attrs=['pid','name', 'username'])
                if pinfo['username']!='root'and pinfo['username']!='ubuntu'and pinfo['username']!='messagebus' and pinfo['username']!='syslog' and pinfo['username']!='systemd-$
                        print pinfo['pid'],pinfo['name'],pinfo['username']
                        os.chdir(sys.argv[1])
                        parent_proc = psutil.Process(os.getsid(int(pinfo['pid'])))
                        if os.path.isdir(pinfo['name']) == False and (os.getsid(int(pinfo['pid']))==int(pinfo['pid'])): 
                                #or parent_proc.name()=='bash' ):
                                reps=0
                                os.makedirs(pinfo['name']+str(count))
                                os.chmod(pinfo['name']+str(count),0777)
                                mainString="sudo criu-ns dump -D '%s' -t %i --shell-job --leave-stopped --ext-unix-sk --skip-in-flight --tcp-established" % (pinfo['name']+str($
                                print mainString
                                p=subprocess.Popen(mainString,shell=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                                r=p.communicate()
                                print "STDOUT of script"
                                print r[0]
                                print "STDERR of script"
                                print r[1]
                                while(reps<3 and "Dumping FAILED" in r[1]):
                                    p=subprocess.Popen("sudo kill -CONT %i" % (pinfo['pid']),shell=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                                    r=p.communicate()
                                    print "STDOUT of script"
                                    print r[0]
                                    print "STDERR of script"
                                    print r[1]
                                    time.sleep(10)
                                    p=subprocess.Popen("sudo kill -19 %i" % (pinfo['pid']),shell=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                                    r=p.communicate()
                                    print "STDOUT of script"
                                    print r[0]
                                    print "STDERR of script"
                                    print r[1]
                                    mainString="sudo criu dump -D '%s' -t %i --shell-job --leave-stopped --ext-unix-sk --skip-in-flight --tcp-established" % (pinfo['name']+str$
                                    print mainString
                                    p=subprocess.Popen(mainString,shell=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                                    r=p.communicate()
                                    print "STDOUT of script"
                                    print r[0]
                                    print "STDERR of script"
                                    print r[1]
                                    reps=reps+1
                                count=count+1
                        else:
                                print "This is a childprocess and will not be dumped"
        except psutil.NoSuchProcess:
                pass
##Check for running docker containers to checkpoint
mainString = "sudo docker ps --format \"{{.Names}}\" > /home/ubuntu/docker_containers"
p=subprocess.Popen(mainString,shell=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
r=p.communicate()
print "STDOUT of script"
print r[0]
print "STDERR of script"
print r[1]
for line in open("/home/ubuntu/docker_containers").readlines():
        checkpoint_str = "checkpoint"+str(time.strftime("%Y%m%d%H%M%S"))
        mainString="sudo docker checkpoint create '%s' '%s'" % (str(line.strip()), checkpoint_str)
        print mainString
        p=subprocess.Popen(mainString,shell=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                                   r=p.communicate()
                                    print "STDOUT of script"
                                    print r[0]
                                    print "STDERR of script"
                                    print r[1]
                                    reps=reps+1
                                count=count+1
                        else:
                                print "This is a childprocess and will not be dumped"
        except psutil.NoSuchProcess:
                pass
##Check for running docker containers to checkpoint
mainString = "sudo docker ps --format \"{{.Names}}\" > /home/ubuntu/docker_containers"
p=subprocess.Popen(mainString,shell=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
r=p.communicate()
print "STDOUT of script"
print r[0]
print "STDERR of script"
print r[1]
for line in open("/home/ubuntu/docker_containers").readlines():
        checkpoint_str = "checkpoint"+str(time.strftime("%Y%m%d%H%M%S"))
        mainString="sudo docker checkpoint create '%s' '%s'" % (str(line.strip()), checkpoint_str)
        print mainString
        p=subprocess.Popen(mainString,shell=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        r=p.communicate()
        print "STDOUT of script"
        print r[0]
        print "STDERR of script"
        print r[1]
        dockerList.write(str(line.strip()) +"\t"+ checkpoint_str +"\n")
dockerList.close()

print "PIDs"
for p in pidList:
        print p

#print "Docker containers"
#for line in open("/home/ubuntu/dockerList").readlines():
#        print line
