import io
import os
from pathlib import Path
import runpy
import signal
import subprocess
import sys
import tempfile
import types
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]


class SupervisionTest(unittest.TestCase):
    def health(self, status, supervisor_exit=0):
        with tempfile.TemporaryDirectory() as directory:
            commands = {
                'supervisorctl': 'printf "%s\\n" "$TEST_PROCESS_STATUS"; exit "$TEST_SUPERVISOR_EXIT"',
                'curl': 'exit 0',
            }
            for name, command in commands.items():
                path = Path(directory, name)
                path.write_text('#!/bin/sh\n' + command + '\n')
                path.chmod(0o755)
            environment = dict(os.environ, PATH=directory + ':' + os.environ['PATH'],
                               TEST_PROCESS_STATUS=status, TEST_SUPERVISOR_EXIT=str(supervisor_exit))
            return subprocess.run(['sh', str(ROOT / 'docker/healthcheck.sh')],
                                  env=environment, capture_output=True).returncode

    def test_health_requires_every_process_running(self):
        healthy = '\n'.join(name + ' RUNNING pid 1' for name in ('fail-fast', 'scheduler', 'web', 'worker'))
        self.assertEqual(self.health(healthy), 0)
        self.assertNotEqual(self.health(healthy.replace('worker RUNNING', 'worker FATAL')), 0)
        self.assertNotEqual(self.health('web RUNNING pid 1'), 0)
        self.assertNotEqual(self.health(healthy, supervisor_exit=1), 0)

    def test_entrypoint_refuses_sync_dispatch_and_missing_keys(self):
        cases = [('test-key', 'database', 'pub', 'sub', 0), ('test-key', 'sync', 'pub', 'sub', 1),
                 ('', 'database', 'pub', 'sub', 1), ('test-key', 'database', '', 'sub', 1),
                 ('test-key', 'database', 'pub', '', 1)]
        for key, connection, publisher, subscriber, expected in cases:
            environment = dict(os.environ, APP_KEY=key, QUEUE_CONNECTION=connection,
                               MERCURE_PUBLISHER_JWT_KEY=publisher, MERCURE_SUBSCRIBER_JWT_KEY=subscriber)
            result = subprocess.run(['sh', str(ROOT / 'docker/entrypoint.sh'), 'true'], env=environment, capture_output=True)
            self.assertEqual(result.returncode, expected)

    def test_fatal_event_is_acknowledged_before_stopping_pid_one(self):
        calls = []
        childutils = types.SimpleNamespace(listener=types.SimpleNamespace(
            wait=lambda: calls.append('wait'), ok=lambda: calls.append('ack')))
        supervisor = types.ModuleType('supervisor')
        supervisor.childutils = childutils
        with mock.patch.dict(sys.modules, {'supervisor': supervisor}), \
                mock.patch('os.getppid', return_value=123), \
                mock.patch('os.kill', side_effect=lambda *args: calls.append(args)), \
                mock.patch('sys.stdout', new=io.StringIO()):
            runpy.run_path(str(ROOT / 'docker/exit-on-fatal.py'), run_name='__main__')
        self.assertEqual(calls, ['wait', 'ack', (123, signal.SIGTERM)])


if __name__ == '__main__':
    unittest.main()
