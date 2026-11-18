import os
import signal

from supervisor import childutils

childutils.listener.wait()
childutils.listener.ok()
os.kill(os.getppid(), signal.SIGTERM)
