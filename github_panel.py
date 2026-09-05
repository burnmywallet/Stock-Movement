#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
===============================================================
 GitHub Panel - Stock Movement / Logistox
===============================================================

واجهة رسومية لإدارة مشروع Git/GitHub.

المميزات:
- GitHub Login عبر GitHub CLI
- عرض حساب GitHub
- فحص SSH
- Git Status
- Pull / Fetch / Push
- Commit
- Branch Management
- Git Log
- Git Diff
- GitHub Repository Information
- Repository List
- Create Repository
- Clone Repository
- Create Release
- Issues
- Security Scan
- Git History Scan
- Doctor
- Terminal Console
- اختيار مجلد المشروع
- دعم Linux / Windows / macOS

مهم:
لا يتم تخزين GitHub PAT داخل هذا الملف.
يعتمد البرنامج على:
    gh auth login
و
    SSH Git authentication

===============================================================
"""

import os
import sys
import json
import shlex
import shutil
import subprocess
import threading
import webbrowser
import platform
from pathlib import Path
from datetime import datetime

import tkinter as tk
from tkinter import ttk, messagebox, simpledialog, filedialog


# ===============================================================
# Configuration
# ===============================================================

APP_NAME = "GitHub Panel - Stock Movement"
APP_VERSION = "1.0.0"

SCRIPT_DIR = Path(__file__).resolve().parent

DEFAULT_PROJECT_PATH = SCRIPT_DIR

CONFIG_FILE = SCRIPT_DIR / "github_panel_config.json"

DEFAULT_CONFIG = {
    "project_path": str(DEFAULT_PROJECT_PATH),
    "branch": "main",
    "repository": "burnmywallet/Stock-Movement",
    "git_protocol": "ssh"
}


# ===============================================================
# Colors / UI
# ===============================================================

BG = "#111827"
PANEL = "#1f2937"
PANEL2 = "#273449"
TEXT = "#f3f4f6"
MUTED = "#9ca3af"
ACCENT = "#2563eb"
SUCCESS = "#16a34a"
WARNING = "#d97706"
DANGER = "#dc2626"
BORDER = "#374151"


# ===============================================================
# Helpers
# ===============================================================

def load_config():
    if not CONFIG_FILE.exists():
        save_config(DEFAULT_CONFIG)
        return DEFAULT_CONFIG.copy()

    try:
        with CONFIG_FILE.open("r", encoding="utf-8") as f:
            data = json.load(f)

        config = DEFAULT_CONFIG.copy()
        config.update(data)
        return config

    except Exception:
        return DEFAULT_CONFIG.copy()


def save_config(config):
    try:
        with CONFIG_FILE.open("w", encoding="utf-8") as f:
            json.dump(
                config,
                f,
                ensure_ascii=False,
                indent=4
            )
    except Exception:
        pass


def command_exists(command):
    return shutil.which(command) is not None


def get_python_command():
    return sys.executable


def run_command(command, cwd=None, timeout=120):
    try:
        result = subprocess.run(
            command,
            cwd=str(cwd) if cwd else None,
            capture_output=True,
            text=True,
            timeout=timeout,
            shell=False
        )

        output = ""

        if result.stdout:
            output += result.stdout

        if result.stderr:
            if output:
                output += "\n"
            output += result.stderr

        return result.returncode, output.strip()

    except subprocess.TimeoutExpired:
        return 124, "Command timed out."

    except FileNotFoundError:
        return 127, f"Command not found: {command[0]}"

    except Exception as e:
        return 1, str(e)


def open_terminal(command, cwd=None):
    system = platform.system()

    try:
        if system == "Linux":
            terminals = [
                ["x-terminal-emulator", "-e"],
                ["gnome-terminal", "--"],
                ["konsole", "-e"],
                ["xfce4-terminal", "-e"]
            ]

            for terminal in terminals:
                if command_exists(terminal[0]):
                    subprocess.Popen(
                        terminal + [command],
                        cwd=str(cwd) if cwd else None
                    )
                    return True

        elif system == "Windows":
            subprocess.Popen(
                ["cmd.exe", "/K", command],
                cwd=str(cwd) if cwd else None
            )
            return True

        elif system == "Darwin":
            subprocess.Popen(
                ["open", "-a", "Terminal"],
            )
            return True

    except Exception:
        pass

    return False


# ===============================================================
# Main Application
# ===============================================================

class GitHubPanel(tk.Tk):

    def __init__(self):
        super().__init__()

        self.config_data = load_config()

        self.project_path = Path(
            self.config_data.get(
                "project_path",
                DEFAULT_PROJECT_PATH
            )
        )

        self.current_process = None

        self.title(APP_NAME)
        self.geometry("1400x850")
        self.minsize(1100, 700)

        self.configure(bg=BG)

        self.setup_style()
        self.build_ui()

        self.after(300, self.refresh_all)


    # ===========================================================
    # Style
    # ===========================================================

    def setup_style(self):

        style = ttk.Style(self)

        try:
            style.theme_use("clam")
        except Exception:
            pass

        style.configure(
            "TFrame",
            background=BG
        )

        style.configure(
            "Panel.TFrame",
            background=PANEL
        )

        style.configure(
            "TLabel",
            background=BG,
            foreground=TEXT,
            font=("Arial", 10)
        )

        style.configure(
            "Title.TLabel",
            background=BG,
            foreground=TEXT,
            font=("Arial", 20, "bold")
        )

        style.configure(
            "Subtitle.TLabel",
            background=BG,
            foreground=MUTED,
            font=("Arial", 9)
        )

        style.configure(
            "TButton",
            background=PANEL2,
            foreground=TEXT,
            padding=(12, 8),
            borderwidth=0,
            font=("Arial", 9, "bold")
        )

        style.map(
            "TButton",
            background=[
                ("active", ACCENT)
            ]
        )

        style.configure(
            "Accent.TButton",
            background=ACCENT,
            foreground="white"
        )

        style.configure(
            "Danger.TButton",
            background=DANGER,
            foreground="white"
        )

        style.configure(
            "Success.TButton",
            background=SUCCESS,
            foreground="white"
        )

        style.configure(
            "Treeview",
            background=PANEL,
            foreground=TEXT,
            fieldbackground=PANEL,
            borderwidth=0,
            rowheight=28
        )

        style.configure(
            "Treeview.Heading",
            background=PANEL2,
            foreground=TEXT,
            font=("Arial", 9, "bold")
        )


    # ===========================================================
    # UI
    # ===========================================================

    def build_ui(self):

        # Header
        header = ttk.Frame(self)
        header.pack(
            fill="x",
            padx=20,
            pady=(18, 8)
        )

        ttk.Label(
            header,
            text="GitHub Panel",
            style="Title.TLabel"
        ).pack(side="left")

        ttk.Label(
            header,
            text=f"Stock Movement / Logistox  |  v{APP_VERSION}",
            style="Subtitle.TLabel"
        ).pack(
            side="left",
            padx=15,
            pady=(7, 0)
        )

        ttk.Button(
            header,
            text="GitHub Login",
            style="Accent.TButton",
            command=self.github_login
        ).pack(side="right")

        # Status bar
        self.status_frame = tk.Frame(
            self,
            bg=PANEL,
            highlightbackground=BORDER,
            highlightthickness=1
        )

        self.status_frame.pack(
            fill="x",
            padx=20,
            pady=8
        )

        self.account_label = self.create_status_item(
            self.status_frame,
            "GitHub",
            "Checking..."
        )

        self.repo_label = self.create_status_item(
            self.status_frame,
            "Repository",
            "Checking..."
        )

        self.branch_label = self.create_status_item(
            self.status_frame,
            "Branch",
            "Checking..."
        )

        self.ssh_label = self.create_status_item(
            self.status_frame,
            "SSH",
            "Checking..."
        )

        self.project_label = self.create_status_item(
            self.status_frame,
            "Project",
            str(self.project_path)
        )

        # Main area
        main = ttk.Frame(self)
        main.pack(
            fill="both",
            expand=True,
            padx=20,
            pady=8
        )

        # Left navigation
        left = tk.Frame(
            main,
            bg=PANEL,
            width=230
        )

        left.pack(
            side="left",
            fill="y",
            padx=(0, 10)
        )

        left.pack_propagate(False)

        self.create_section_button(
            left,
            "Project",
            [
                ("Status", self.git_status),
                ("Pull", self.git_pull),
                ("Fetch", self.git_fetch),
                ("Push", self.git_push),
                ("Commit", self.git_commit),
                ("Git Log", self.git_log),
                ("Git Diff", self.git_diff)
            ]
        )

        self.create_section_button(
            left,
            "Branches",
            [
                ("List Branches", self.branch_list),
                ("Create Branch", self.branch_create),
                ("Switch Branch", self.branch_switch),
                ("Delete Branch", self.branch_delete),
                ("Push Branch", self.branch_push)
            ]
        )

        self.create_section_button(
            left,
            "GitHub",
            [
                ("Account", self.github_account),
                ("Repositories", self.github_repositories),
                ("Repository Info", self.github_repo_info),
                ("Create Repository", self.github_repo_create),
                ("Clone Repository", self.github_clone),
                ("Create Release", self.github_release)
            ]
        )

        self.create_section_button(
            left,
            "Security",
            [
                ("Security Scan", self.security_scan),
                ("History Scan", self.history_scan),
                ("Doctor", self.doctor)
            ]
        )

        self.create_section_button(
            left,
            "Settings",
            [
                ("Project Folder", self.choose_project),
                ("Configuration", self.show_config),
                ("Open GitHub", self.open_github),
                ("Open Terminal", self.open_project_terminal)
            ]
        )

        # Right content
        right = ttk.Frame(main)
        right.pack(
            side="left",
            fill="both",
            expand=True
        )

        console_header = ttk.Frame(right)
        console_header.pack(
            fill="x",
            pady=(0, 6)
        )

        ttk.Label(
            console_header,
            text="Console",
            font=("Arial", 12, "bold")
        ).pack(side="left")

        ttk.Button(
            console_header,
            text="Clear",
            command=self.clear_console
        ).pack(side="right")

        self.console = tk.Text(
            right,
            bg="#0b1120",
            fg=TEXT,
            insertbackground=TEXT,
            selectbackground=ACCENT,
            font=("DejaVu Sans Mono", 10),
            wrap="word",
            relief="flat",
            padx=12,
            pady=12
        )

        self.console.pack(
            fill="both",
            expand=True
        )

        # Bottom
        bottom = tk.Frame(
            self,
            bg=PANEL,
            height=35
        )

        bottom.pack(
            fill="x",
            padx=20,
            pady=(5, 15)
        )

        bottom.pack_propagate(False)

        self.footer_label = tk.Label(
            bottom,
            text="Ready",
            bg=PANEL,
            fg=MUTED,
            anchor="w",
            font=("Arial", 9)
        )

        self.footer_label.pack(
            fill="both",
            padx=10
        )


    # ===========================================================
    # UI Helpers
    # ===========================================================

    def create_status_item(self, parent, title, value):

        frame = tk.Frame(
            parent,
            bg=PANEL
        )

        frame.pack(
            side="left",
            padx=15,
            pady=10
        )

        tk.Label(
            frame,
            text=title,
            bg=PANEL,
            fg=MUTED,
            font=("Arial", 8)
        ).pack(anchor="w")

        label = tk.Label(
            frame,
            text=value,
            bg=PANEL,
            fg=TEXT,
            font=("Arial", 9, "bold")
        )

        label.pack(anchor="w")

        return label


    def create_section_button(self, parent, title, buttons):

        tk.Label(
            parent,
            text=title,
            bg=PANEL,
            fg=MUTED,
            font=("Arial", 8, "bold")
        ).pack(
            anchor="w",
            padx=12,
            pady=(14, 5)
        )

        for text, command in buttons:

            tk.Button(
                parent,
                text=text,
                command=command,
                bg=PANEL2,
                fg=TEXT,
                activebackground=ACCENT,
                activeforeground="white",
                relief="flat",
                bd=0,
                anchor="w",
                padx=14,
                pady=7,
                cursor="hand2"
            ).pack(
                fill="x",
                padx=8,
                pady=2
            )


    def log(self, text, clear=False):

        def write():

            if clear:
                self.console.delete("1.0", "end")

            timestamp = datetime.now().strftime("%H:%M:%S")

            self.console.insert(
                "end",
                f"[{timestamp}] {text}\n"
            )

            self.console.see("end")

        self.after(0, write)


    def set_footer(self, text):

        self.after(
            0,
            lambda: self.footer_label.config(text=text)
        )


    def clear_console(self):
        self.console.delete("1.0", "end")


    # ===========================================================
    # Generic command
    # ===========================================================

    def execute(
        self,
        command,
        title=None,
        cwd=None,
        timeout=120,
        callback=None
    ):

        if cwd is None:
            cwd = self.project_path

        def worker():

            if title:
                self.log("=" * 70)
                self.log(title)
                self.log("=" * 70)

            self.log("$ " + " ".join(
                shlex.quote(str(x))
                for x in command
            ))

            self.set_footer("Running...")

            code, output = run_command(
                command,
                cwd=cwd,
                timeout=timeout
            )

            if output:
                self.log(output)

            self.log(
                f"Exit code: {code}"
            )

            if code == 0:
                self.set_footer("Completed successfully")
            else:
                self.set_footer(
                    f"Command failed: {code}"
                )

            if callback:
                self.after(
                    0,
                    lambda: callback(code, output)
                )

        thread = threading.Thread(
            target=worker,
            daemon=True
        )

        thread.start()


    # ===========================================================
    # Refresh
    # ===========================================================

    def refresh_all(self):

        self.refresh_account()
        self.refresh_repo()
        self.refresh_branch()
        self.refresh_ssh()

        self.set_footer(
            f"Project: {self.project_path}"
        )


    def refresh_account(self):

        if not command_exists("gh"):
            self.account_label.config(
                text="gh not installed",
                fg="#f87171"
            )
            return

        code, output = run_command(
            ["gh", "api", "user", "--jq", ".login"],
            timeout=20
        )

        if code == 0 and output:
            self.account_label.config(
                text=output.strip(),
                fg="#4ade80"
            )
        else:
            self.account_label.config(
                text="Not logged in",
                fg="#f87171"
            )


    def refresh_repo(self):

        if not self.project_path.exists():
            self.repo_label.config(
                text="Folder missing",
                fg="#f87171"
            )
            return

        code, output = run_command(
            ["git", "remote", "get-url", "origin"],
            cwd=self.project_path
        )

        if code == 0:
            self.repo_label.config(
                text=output.strip(),
                fg=TEXT
            )
        else:
            self.repo_label.config(
                text="No remote",
                fg="#fbbf24"
            )


    def refresh_branch(self):

        code, output = run_command(
            ["git", "branch", "--show-current"],
            cwd=self.project_path
        )

        if code == 0:
            self.branch_label.config(
                text=output.strip(),
                fg=TEXT
            )


    def refresh_ssh(self):

        if not command_exists("ssh"):
            self.ssh_label.config(
                text="SSH missing",
                fg="#f87171"
            )
            return

        code, output = run_command(
            [
                "ssh",
                "-T",
                "-o",
                "BatchMode=yes",
                "-o",
                "ConnectTimeout=8",
                "git@github.com"
            ],
            timeout=15
        )

        if "successfully authenticated" in output.lower():
            self.ssh_label.config(
                text="Connected",
                fg="#4ade80"
            )
        else:
            self.ssh_label.config(
                text="Check",
                fg="#fbbf24"
            )


    # ===========================================================
    # Git
    # ===========================================================

    def git_status(self):

        self.execute(
            ["git", "status", "--short", "--branch"],
            "Git Status",
            callback=lambda *_: self.refresh_all()
        )


    def git_pull(self):

        if not self.confirm(
            "Pull",
            "هل تريد تنفيذ git pull؟"
        ):
            return

        self.execute(
            ["git", "pull", "--ff-only"],
            "Git Pull",
            timeout=180,
            callback=lambda *_: self.refresh_all()
        )


    def git_fetch(self):

        self.execute(
            ["git", "fetch", "--all", "--prune"],
            "Git Fetch",
            timeout=180
        )


    def git_push(self):

        if not self.confirm(
            "Push",
            "هل تريد رفع التغييرات إلى GitHub؟"
        ):
            return

        self.execute(
            ["git", "push"],
            "Git Push",
            timeout=180,
            callback=lambda *_: self.refresh_all()
        )


    def git_commit(self):

        message = simpledialog.askstring(
            "Commit",
            "اكتب رسالة الـ Commit:"
        )

        if not message:
            return

        def do_commit():

            self.execute(
                ["git", "add", "-A"],
                "Git Add",
                callback=lambda code, _: (
                    self.execute(
                        ["git", "commit", "-m", message],
                        "Git Commit",
                        callback=lambda *_: self.refresh_all()
                    )
                    if code == 0
                    else None
                )
            )

        do_commit()


    def git_log(self):

        self.execute(
            [
                "git",
                "log",
                "--oneline",
                "--decorate",
                "--graph",
                "-30"
            ],
            "Git Log"
        )


    def git_diff(self):

        self.execute(
            ["git", "diff", "--stat"],
            "Git Diff"
        )

        self.execute(
            ["git", "diff", "--"],
            "Git Diff Details"
        )


    # ===========================================================
    # Branches
    # ===========================================================

    def branch_list(self):

        self.execute(
            [
                "git",
                "branch",
                "-a",
                "-vv"
            ],
            "Branches"
        )


    def branch_create(self):

        name = simpledialog.askstring(
            "Create Branch",
            "اسم الـBranch:"
        )

        if not name:
            return

        self.execute(
            ["git", "switch", "-c", name],
            "Create Branch",
            callback=lambda *_: self.refresh_all()
        )


    def branch_switch(self):

        name = simpledialog.askstring(
            "Switch Branch",
            "اسم الـBranch:"
        )

        if not name:
            return

        self.execute(
            ["git", "switch", name],
            "Switch Branch",
            callback=lambda *_: self.refresh_all()
        )


    def branch_delete(self):

        name = simpledialog.askstring(
            "Delete Branch",
            "اسم الـBranch الذي تريد حذفه:"
        )

        if not name:
            return

        if not self.confirm(
            "Delete Branch",
            f"هل أنت متأكد من حذف Branch:\n{name}؟"
        ):
            return

        self.execute(
            ["git", "branch", "-d", name],
            "Delete Branch"
        )


    def branch_push(self):

        name = simpledialog.askstring(
            "Push Branch",
            "اسم الـBranch:"
        )

        if not name:
            return

        self.execute(
            ["git", "push", "-u", "origin", name],
            "Push Branch",
            timeout=180
        )


    # ===========================================================
    # GitHub
    # ===========================================================

    def github_login(self):

        if not command_exists("gh"):
            messagebox.showerror(
                "GitHub CLI",
                "GitHub CLI (gh) غير مثبت.\n\n"
                "على Kali/Debian:\n"
                "sudo apt install gh"
            )
            return

        self.log("Starting GitHub authentication...")

        self.execute(
            [
                "gh",
                "auth",
                "login",
                "--web",
                "--git-protocol",
                "ssh"
            ],
            "GitHub Login",
            timeout=600,
            callback=lambda *_: self.refresh_all()
        )


    def github_account(self):

        self.execute(
            [
                "gh",
                "api",
                "user",
                "--jq",
                ".login + \" | \" + .name"
            ],
            "GitHub Account"
        )


    def github_repositories(self):

        self.execute(
            [
                "gh",
                "repo",
                "list",
                "--limit",
                "100",
                "--json",
                "name,nameWithOwner,isPrivate,updatedAt",
                "--template",
                "{{range .}}{{.nameWithOwner}} | private={{.isPrivate}} | updated={{.updatedAt}}\n{{end}}"
            ],
            "GitHub Repositories",
            timeout=60
        )


    def github_repo_info(self):

        repo = simpledialog.askstring(
            "Repository",
            "Repository:\nمثال: burnmywallet/Stock-Movement",
            initialvalue=self.config_data.get(
                "repository",
                ""
            )
        )

        if not repo:
            return

        self.execute(
            [
                "gh",
                "repo",
                "view",
                repo
            ],
            "Repository Information"
        )


    def github_repo_create(self):

        name = simpledialog.askstring(
            "Create Repository",
            "اسم Repository الجديد:"
        )

        if not name:
            return

        private = messagebox.askyesno(
            "Repository Visibility",
            "هل تريد Repository Private؟"
        )

        visibility = "--private" if private else "--public"

        if not self.confirm(
            "Create Repository",
            f"إنشاء:\n{name}\n\n"
            f"Visibility: {'Private' if private else 'Public'}"
        ):
            return

        self.execute(
            [
                "gh",
                "repo",
                "create",
                name,
                visibility
            ],
            "Create GitHub Repository",
            timeout=120
        )


    def github_clone(self):

        url = simpledialog.askstring(
            "Clone Repository",
            "Repository URL أو GitHub repository:"
        )

        if not url:
            return

        destination = filedialog.askdirectory(
            title="اختار مكان Clone"
        )

        if not destination:
            return

        self.execute(
            [
                "git",
                "clone",
                url
            ],
            "Clone Repository",
            cwd=Path(destination),
            timeout=300
        )


    def github_release(self):

        tag = simpledialog.askstring(
            "Release",
            "Tag:\nمثال: v5.0.1"
        )

        if not tag:
            return

        title = simpledialog.askstring(
            "Release",
            "Release Title:",
            initialvalue=tag
        )

        if not title:
            title = tag

        notes = simpledialog.askstring(
            "Release",
            "Release Notes:"
        )

        if notes is None:
            notes = ""

        if not self.confirm(
            "Create Release",
            f"Tag: {tag}\n"
            f"Title: {title}\n\n"
            "هل تريد إنشاء Release؟"
        ):
            return

        self.execute(
            [
                "gh",
                "release",
                "create",
                tag,
                "--title",
                title,
                "--notes",
                notes
            ],
            "Create Release",
            timeout=180
        )


    # ===========================================================
    # Security
    # ===========================================================

    def security_scan(self):

        self.log("=" * 70)
        self.log("SECURITY SCAN")
        self.log("=" * 70)

        dangerous_names = [
            ".env",
            ".env.local",
            ".env.production",
            "id_rsa",
            "id_ed25519",
            ".pem",
            ".key",
            ".p12",
            ".pfx",
            "credentials",
            "secret"
        ]

        found = []

        try:

            for root, dirs, files in os.walk(self.project_path):

                dirs[:] = [
                    d for d in dirs
                    if d not in {
                        ".git",
                        "node_modules",
                        "vendor",
                        "__pycache__"
                    }
                ]

                for filename in files:

                    lower = filename.lower()

                    for pattern in dangerous_names:

                        if pattern in lower:

                            path = Path(root) / filename
                            found.append(path)
                            break

        except Exception as e:
            self.log(f"Scan error: {e}")

        if found:

            self.log(
                f"Potential sensitive files found: {len(found)}"
            )

            for item in found:
                self.log(
                    f"  [!] {item}"
                )

        else:
            self.log(
                "No obvious sensitive filenames detected."
            )

        # Search current files for common credential patterns
        self.log("")
        self.log("Checking for obvious credential patterns...")

        extensions = {
            ".php",
            ".js",
            ".py",
            ".json",
            ".env",
            ".yml",
            ".yaml",
            ".ini",
            ".conf",
            ".config"
        }

        patterns = [
            "github_pat_",
            "ghp_",
            "AKIA",
            "BEGIN OPENSSH PRIVATE KEY",
            "BEGIN RSA PRIVATE KEY",
            "password=",
            "password:",
            "api_key=",
            "api-key="
        ]

        matches = []

        try:

            for root, dirs, files in os.walk(self.project_path):

                dirs[:] = [
                    d for d in dirs
                    if d not in {
                        ".git",
                        "node_modules",
                        "vendor",
                        "__pycache__"
                    }
                ]

                for filename in files:

                    path = Path(root) / filename

                    if path.suffix.lower() not in extensions:
                        continue

                    try:
                        if path.stat().st_size > 2 * 1024 * 1024:
                            continue

                        content = path.read_text(
                            encoding="utf-8",
                            errors="ignore"
                        )

                        for pattern in patterns:

                            if pattern.lower() in content.lower():

                                matches.append(
                                    (path, pattern)
                                )

                    except Exception:
                        continue

        except Exception as e:
            self.log(f"Pattern scan error: {e}")

        if matches:

            self.log(
                f"Potential credential patterns: {len(matches)}"
            )

            for path, pattern in matches:
                self.log(
                    f"  [!] {path} -> {pattern}"
                )

        else:

            self.log(
                "No obvious credential patterns detected."
            )


    def history_scan(self):

        self.execute(
            [
                "git",
                "log",
                "--all",
                "--name-only",
                "--pretty=format:"
            ],
            "Git History File Scan",
            callback=self.history_scan_callback
        )


    def history_scan_callback(self, code, output):

        if code != 0:
            return

        sensitive = [
            ".env",
            ".pem",
            ".key",
            "id_rsa",
            "id_ed25519",
            "credentials",
            "secret"
        ]

        matches = []

        for line in output.splitlines():

            filename = line.strip().lower()

            if not filename:
                continue

            for item in sensitive:

                if item in filename:

                    matches.append(line.strip())
                    break

        if matches:

            self.log("")
            self.log(
                "WARNING: Sensitive-looking files exist in Git history:"
            )

            for item in sorted(set(matches)):
                self.log(
                    f"  [!] {item}"
                )

            self.log("")
            self.log(
                "Removing a file from the current working tree "
                "does NOT remove it from Git history."
            )

        else:

            self.log(
                "No obvious sensitive filenames found in Git history."
            )


    # ===========================================================
    # Doctor
    # ===========================================================

    def doctor(self):

        self.log("=" * 70)
        self.log("SYSTEM DOCTOR")
        self.log("=" * 70)

        checks = [
            ("Python", [sys.executable, "--version"]),
            ("Git", ["git", "--version"]),
            ("GitHub CLI", ["gh", "--version"]),
            ("SSH", ["ssh", "-V"])
        ]

        for name, command in checks:

            if not command_exists(command[0]):

                self.log(
                    f"[FAIL] {name}: not installed"
                )

                continue

            code, output = run_command(
                command,
                timeout=20
            )

            if code == 0 or output:

                first_line = output.splitlines()[0] \
                    if output else "OK"

                self.log(
                    f"[OK] {name}: {first_line}"
                )

            else:

                self.log(
                    f"[FAIL] {name}"
                )

        # Project
        self.log("")

        if self.project_path.exists():

            self.log(
                f"[OK] Project folder: {self.project_path}"
            )

        else:

            self.log(
                f"[FAIL] Project folder missing: {self.project_path}"
            )

        # Git
        if (self.project_path / ".git").exists():

            self.log(
                "[OK] Git repository detected"
            )

        else:

            self.log(
                "[FAIL] Git repository not detected"
            )

        # GitHub authentication
        if command_exists("gh"):

            code, output = run_command(
                ["gh", "auth", "status"],
                timeout=30
            )

            if code == 0:

                self.log(
                    "[OK] GitHub authentication"
                )

            else:

                self.log(
                    "[WARNING] GitHub authentication needs attention"
                )

                if output:
                    self.log(output)

        # SSH
        if command_exists("ssh"):

            code, output = run_command(
                [
                    "ssh",
                    "-T",
                    "-o",
                    "BatchMode=yes",
                    "-o",
                    "ConnectTimeout=8",
                    "git@github.com"
                ],
                timeout=15
            )

            if "successfully authenticated" in output.lower():

                self.log(
                    "[OK] GitHub SSH authentication"
                )

            else:

                self.log(
                    "[WARNING] GitHub SSH authentication not confirmed"
                )


    # ===========================================================
    # Settings
    # ===========================================================

    def choose_project(self):

        selected = filedialog.askdirectory(
            title="اختار مجلد المشروع"
        )

        if not selected:
            return

        path = Path(selected)

        self.project_path = path

        self.config_data["project_path"] = str(path)

        save_config(self.config_data)

        self.project_label.config(
            text=str(path)
        )

        self.log(
            f"Project changed to: {path}"
        )

        self.refresh_all()


    def show_config(self):

        config_text = json.dumps(
            self.config_data,
            ensure_ascii=False,
            indent=4
        )

        self.log("=" * 70)
        self.log("CONFIGURATION")
        self.log("=" * 70)
        self.log(config_text)


    def open_github(self):

        repo = self.config_data.get(
            "repository",
            "burnmywallet/Stock-Movement"
        )

        if "/" in repo:

            url = f"https://github.com/{repo}"

        else:

            url = "https://github.com"

        webbrowser.open(url)


    def open_project_terminal(self):

        success = open_terminal(
            "bash" if platform.system() != "Windows"
            else "cmd.exe",
            self.project_path
        )

        if not success:

            messagebox.showinfo(
                "Terminal",
                f"افتح Terminal يدويًا في:\n\n"
                f"{self.project_path}"
            )


    # ===========================================================
    # Confirmation
    # ===========================================================

    def confirm(self, title, message):

        return messagebox.askyesno(
            title,
            message,
            parent=self
        )


# ===============================================================
# Main
# ===============================================================

def main():

    # Check Tkinter
    try:
        import tkinter
        _ = tkinter
    except Exception:
        print(
            "Tkinter is not installed.\n"
            "On Debian/Kali install:\n"
            "sudo apt install python3-tk"
        )
        sys.exit(1)

    app = GitHubPanel()

    app.protocol(
        "WM_DELETE_WINDOW",
        app.destroy
    )

    app.mainloop()


if __name__ == "__main__":
    main()