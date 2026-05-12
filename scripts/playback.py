"""
playback.py — MPV-based video playback for SLED Santa Mailbox.
Idle loop (looping background video) + one-shot event clip playback.
"""
from __future__ import annotations

import os
import signal
import subprocess
import time


class Player:
    """
    Simple MPV wrapper.

    video_dir: root directory for all clips (absolute path).
    Clip names are relative to video_dir; absolute paths are passed through.
    """

    def __init__(self, video_dir: str) -> None:
        self.video_dir = video_dir
        self.idle_proc: "subprocess.Popen | None" = None

    def _clip_path(self, name: str) -> str:
        if os.path.isabs(name):
            return name
        return os.path.join(self.video_dir, name)

    # ------------------------------------------------------------------
    # Idle loop
    # ------------------------------------------------------------------

    def start_idle(self, idle_name: str = "idle.mp4") -> None:
        """Start the idle loop if not already running."""
        if self.idle_proc and self.idle_proc.poll() is None:
            return
        path = self._clip_path(idle_name)
        if not os.path.isfile(path):
            print(f"[Player] Idle video not found: {path}", flush=True)
            return
        try:
            self.idle_proc = subprocess.Popen(
                ["mpv", "--fs", "--no-osd-bar", "--loop=inf", "--really-quiet", path],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
        except FileNotFoundError:
            print("[Player] mpv not found — video playback disabled.", flush=True)

    def stop_idle(self) -> None:
        """Stop the idle loop if running."""
        proc = self.idle_proc
        if not proc:
            return
        if proc.poll() is not None:
            self.idle_proc = None
            return
        try:
            proc.send_signal(signal.SIGINT)
            time.sleep(0.5)
            if proc.poll() is None:
                proc.kill()
        finally:
            self.idle_proc = None

    # ------------------------------------------------------------------
    # One-shot clip
    # ------------------------------------------------------------------

    def play_once(self, clip_name: str, timeout: int = 65) -> int:
        """
        Play a single clip once in fullscreen.
        Returns MPV exit code (0 = success).
        """
        path = self._clip_path(clip_name)
        if not os.path.isfile(path):
            print(f"[Player] Clip not found: {path}", flush=True)
            return 1
        try:
            proc = subprocess.Popen(
                ["mpv", "--fs", "--no-osd-bar", "--ontop", "--really-quiet", path],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
            )
        except FileNotFoundError:
            print("[Player] mpv not found — skipping clip playback.", flush=True)
            return 1
        try:
            return proc.wait(timeout=timeout)
        except subprocess.TimeoutExpired:
            proc.send_signal(signal.SIGINT)
            time.sleep(0.5)
            if proc.poll() is None:
                proc.kill()
            return 1
