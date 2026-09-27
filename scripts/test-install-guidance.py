#!/usr/bin/env python3
"""Offline installer guidance tests. All install/network/service operations are mocked."""
from pathlib import Path
import os
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = (ROOT / "install.sh").read_text()
MARKER = "\ntrap 'installation_failed \"$?\" \"$LINENO\"' ERR\nselect_mode\n"
assert SCRIPT.count(MARKER) == 1
FUNCTIONS = SCRIPT.split(MARKER)[0].replace(
    '[[ $EUID -eq 0 ]] || die "请使用 root 运行，或使用 sudo。"', ':'
)

class GuidanceTests(unittest.TestCase):
    def shell(self, code, stdin=""):
        return subprocess.run(["bash", "-c", FUNCTIONS + "\n" + code],
                              input=stdin, text=True, capture_output=True, cwd="/tmp")
    def output(self, result):
        return result.stdout + result.stderr
    def test_read_only_help_as_unprivileged_user(self):
        with tempfile.TemporaryDirectory() as folder:
            os.chmod(folder, 0o755)
            target = Path(folder) / "install.sh"
            target.write_text(SCRIPT)
            os.chmod(target, 0o644)
            for flag, text in [("--guide", "安装前引导"), ("--help", "--guide")]:
                kwargs = {"user": "nobody"} if os.geteuid() == 0 else {}
                run = subprocess.run(["bash", str(target), flag], cwd="/tmp",
                                     capture_output=True, text=True, **kwargs)
                self.assertEqual(run.returncode, 0, self.output(run))
                self.assertIn(text, run.stdout)
    def test_every_menu_choice(self):
        for choice, mode in [("1","native"),("2","docker"),("3","gateway"),("4","status")]:
            run = self.shell('MODE=""; select_mode; printf "SELECTED:%s" "$MODE"', choice+"\n")
            self.assertEqual(run.returncode, 0, self.output(run))
            self.assertIn("SELECTED:"+mode, run.stdout)
        run = self.shell('MODE=""; select_mode; echo SHOULD_NOT_RUN', "0\n")
        self.assertEqual(run.returncode, 0)
        self.assertNotIn("SHOULD_NOT_RUN", run.stdout)
        run = self.shell('MODE=""; select_mode', "9\n")
        self.assertNotEqual(run.returncode, 0)
        self.assertIn("无效选择", self.output(run))
    def test_gateway_defaults_and_explicit_choice(self):
        fake = 'install_gateway(){ echo GATEWAY_CALLED; }; '
        for flags, stdin, expected in [
            ('WITH_GATEWAY=""; ASSUME_YES=0;', "\n", False),
            ('WITH_GATEWAY=""; ASSUME_YES=0;', "y\n", True),
            ('WITH_GATEWAY=""; ASSUME_YES=1;', "", False),
            ('WITH_GATEWAY=yes;', "", True),
            ('WITH_GATEWAY=no;', "", False),
        ]:
            run = self.shell(fake+flags+'maybe_install_gateway', stdin)
            self.assertEqual(run.returncode, 0, self.output(run))
            self.assertEqual("GATEWAY_CALLED" in run.stdout, expected)
    def test_yes_uses_default_not_always_true(self):
        run = self.shell('ASSUME_YES=1; if confirm q yes; then echo ACCEPT; fi; if confirm q no; then echo WRONG; fi')
        self.assertEqual(run.returncode, 0)
        self.assertIn("ACCEPT",run.stdout)
        self.assertNotIn("WRONG",run.stdout)
    def test_native_and_docker_stages_new_and_existing(self):
        mocked = ["detect_os","init_shared","stop_docker_if_needed","apt_install_native_deps",
                  "install_swoole","install_redis_runtime","quiesce_native_apps","link_native_shared",
                  "prepare_native_permissions","write_native_services","check_native_redis_port",
                  "configure_native_env","stop_native_if_needed","ensure_docker",
                  "prepare_docker_compose","ensure_docker_image","configure_docker_env","systemctl","docker"]
        stubs = "\n".join(name+"(){ :; }" for name in mocked)
        stubs += '\nfetch_release(){ printf /tmp/mock-app; }\nwait_http(){ echo HTTP_CHECKED; }'
        stubs += '\nnative_initialize_or_update(){ echo "DATA_BRANCH:$1"; }\ndocker_initialize(){ echo "DATA_BRANCH:$1"; }'
        for mode, stages, label in [("native",6,"独立版"),("docker",5,"Docker")]:
            for installed, branch in [(False,"no"),(True,"yes")]:
                code = stubs + '\nis_installed(){ return '+("0" if installed else "1")+'; }'
                code += '\nDOCKER_DIR=/tmp; MODE='+mode+'; install_'+mode
                run = self.shell(code)
                self.assertEqual(run.returncode, 0, self.output(run))
                for i in range(1,stages+1):
                    self.assertIn(label+" "+str(i)+"/"+str(stages),self.output(run))
                self.assertIn("DATA_BRANCH:"+branch,run.stdout)
                self.assertIn("HTTP_CHECKED",run.stdout)
    def test_gateway_arguments_and_stage_messages(self):
        # curl/bash/rm are shell functions here; no download, file write or child installer.
        code = 'curl(){ :; }; rm(){ :; }; bash(){ printf "CHILD:%s\\n" "$*"; }; '
        code += 'GATEWAY_BACKEND=https://panel.example.com; GATEWAY_PORT=3939; install_gateway'
        run = self.shell(code)
        self.assertEqual(run.returncode,0,self.output(run))
        self.assertIn('--backend https://panel.example.com --port 3939',run.stdout)
        for i in range(1,4): self.assertIn("DUI "+str(i)+"/3",self.output(run))
        self.assertIn("重写",self.output(run))
    def test_failure_identifies_stage_and_does_not_print_command(self):
        run = self.shell('trap \'installation_failed "$?" "$LINENO"\' ERR; stage "测试阶段"; false')
        self.assertEqual(run.returncode,1)
        self.assertIn("测试阶段",run.stderr)
        self.assertIn("失败，退出码 1",run.stderr)

if __name__ == "__main__":
    unittest.main()

