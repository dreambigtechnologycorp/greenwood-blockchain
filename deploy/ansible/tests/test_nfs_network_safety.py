"""Prevent a network-retention candidate from hiding address or route changes."""
import importlib.util
from pathlib import Path
import unittest

SCRIPT = Path(__file__).resolve().parents[3] / 'scripts/prepare-nfs-network-safety.py'
spec = importlib.util.spec_from_file_location('nfs_safety', SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class NfsNetworkSafetyTests(unittest.TestCase):
    baseline = '[Match]\nName=eth0\n[Network]\nDHCP=ipv4\nDNS=192.168.72.1\n'

    def test_retention_only_is_accepted(self):
        module.retention_only(self.baseline, self.baseline+'KeepConfiguration=true\n')

    def test_existing_retention_is_accepted(self):
        text = self.baseline+'KeepConfiguration=true\n'
        module.retention_only(text, text)

    def test_address_dns_or_dhcp_change_is_rejected(self):
        for changed in (self.baseline.replace('ipv4','no'),
                        self.baseline.replace('192.168.72.1','8.8.8.8'),
                        self.baseline+'Address=192.168.72.200/24\n'):
            with self.subTest(changed=changed), self.assertRaises(ValueError):
                module.retention_only(self.baseline, changed+'KeepConfiguration=true\n')

    def test_missing_or_disabled_retention_is_rejected(self):
        for suffix in ('','KeepConfiguration=false\n'):
            with self.subTest(suffix=suffix), self.assertRaises(ValueError):
                module.retention_only(self.baseline, self.baseline+suffix)

    def test_interface_cannot_escape_staging_root(self):
        for value in ('../eth0','eth0;reboot','/etc/passwd','eth0 x'):
            with self.subTest(value=value), self.assertRaises(ValueError):
                module.validate_identifier(value)
