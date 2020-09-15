import sys
import os
import subprocess
os.chdir(sys.argv[1])
error=0
for root, dirs, files in os.walk(sys.argv[1]):
        for dir in dirs:
                if dir!= 'lost+found':
                        print dir
                        mainString="sudo criu-ns restore -d -D '%s' --shell-job" % (dir)
                        print mainString
                        p=subprocess.Popen(mainString,shell=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
                        r=p.communicate()
                        print "STDOUT of script"
                        print r[0]
                        print "STDERR of script"
                        print r[1]
                        if "Error" in r[0] or "ERROR" in r[0] or "error" in r[0] or "Error" in r[1] or "ERROR" in r[1] or "error" in r[1]:
                                print "Error detected"
                                error=1
f = open('/home/ubuntu/criumount/restoreStatus', 'a')
f.write(str(error))
f.close()