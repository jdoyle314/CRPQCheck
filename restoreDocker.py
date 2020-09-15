import psutil
import sys
import os
import subprocess
import time

#dockerlist=[]
for line in open("/home/ubuntu/dockerList").readlines():
    words = line.split()
    mainString="sudo docker start --checkpoint '%s' '%s'" % (words[1], words[0])
    print mainString
    p=subprocess.Popen(mainString,shell=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    r=p.communicate()
    print "STDOUT of script"
    print r[0]
    print "STDERR of script"
    print r[1]