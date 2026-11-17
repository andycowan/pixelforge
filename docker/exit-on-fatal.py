import os
import signal

from supervisor import childutils

# An exhausted critical process must restart the container, not leave web-only service.
childutils.listener.wait()
childutils.listener.ok()
os.kill(os.getppid(), signal.SIGTERM)
