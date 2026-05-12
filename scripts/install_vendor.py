#!/usr/bin/env python3
"""
install_vendor.py — Bootstrap required packages into scripts/vendor/
without needing pip, apt-get, or root access.

Usage: python3 install_vendor.py [vendor_dir]
Default vendor_dir: <script_dir>/vendor/

Downloads pyserial and paho-mqtt wheels from PyPI using only stdlib.
"""
import json
import os
import sys
import zipfile
import io

try:
    from urllib.request import urlopen
    from urllib.error import URLError
except ImportError:
    sys.exit("Python 3 required")

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
VENDOR_DIR = sys.argv[1] if len(sys.argv) > 1 else os.path.join(SCRIPT_DIR, "vendor")

PACKAGES = ["pyserial", "paho-mqtt"]
# Import names to verify after install
VERIFY_IMPORTS = {"pyserial": "serial", "paho-mqtt": "paho"}


def log(msg):
    print(f"[install_vendor] {msg}", flush=True)


def already_importable(module_name):
    """Check if module is importable from system or vendor path."""
    old_path = sys.path[:]
    try:
        if VENDOR_DIR not in sys.path:
            sys.path.insert(0, VENDOR_DIR)
        # Clear cached import if present
        if module_name in sys.modules:
            return True
        import importlib
        spec = importlib.util.find_spec(module_name)
        return spec is not None
    except Exception:
        return False
    finally:
        sys.path[:] = old_path


def get_wheel_url(package_name):
    """Fetch the latest pure-Python wheel URL from PyPI JSON API."""
    url = f"https://pypi.org/pypi/{package_name}/json"
    try:
        with urlopen(url, timeout=15) as resp:
            data = json.loads(resp.read())
    except URLError as e:
        raise RuntimeError(f"Could not fetch PyPI metadata for {package_name}: {e}")

    # Prefer py3-universal wheel, fall back to py2.py3
    urls = data.get("urls", [])
    for candidate in urls:
        fname = candidate.get("filename", "")
        if fname.endswith(".whl") and (
            "-py3-" in fname or "-py2.py3-" in fname or "-py3." in fname
        ):
            return candidate["url"]
    # Fallback: any wheel
    for candidate in urls:
        if candidate.get("filename", "").endswith(".whl"):
            return candidate["url"]
    raise RuntimeError(f"No suitable wheel found for {package_name}")


def download_and_extract(package_name, vendor_dir):
    """Download wheel from PyPI and extract into vendor_dir."""
    log(f"Fetching wheel URL for {package_name}...")
    wheel_url = get_wheel_url(package_name)
    log(f"Downloading {wheel_url.split('/')[-1]}...")
    try:
        with urlopen(wheel_url, timeout=60) as resp:
            wheel_data = resp.read()
    except URLError as e:
        raise RuntimeError(f"Download failed: {e}")

    os.makedirs(vendor_dir, exist_ok=True)
    with zipfile.ZipFile(io.BytesIO(wheel_data)) as zf:
        # Skip *.dist-info directories (metadata only, not needed at runtime)
        members = [m for m in zf.namelist() if ".dist-info/" not in m]
        zf.extractall(vendor_dir, members=members)
    log(f"{package_name}: extracted {len(members)} files to {vendor_dir}")


def main():
    os.makedirs(VENDOR_DIR, exist_ok=True)
    log(f"Vendor dir: {VENDOR_DIR}")

    # Add vendor to path for verification
    if VENDOR_DIR not in sys.path:
        sys.path.insert(0, VENDOR_DIR)

    for pkg in PACKAGES:
        mod = VERIFY_IMPORTS.get(pkg, pkg)
        if already_importable(mod):
            log(f"{pkg}: already available — skipping")
            continue
        try:
            download_and_extract(pkg, VENDOR_DIR)
            # Verify
            import importlib
            spec = importlib.util.find_spec(mod)
            if spec:
                log(f"{pkg}: installed and verified OK")
            else:
                log(f"WARN: {pkg}: extracted but module '{mod}' still not importable")
        except Exception as e:
            log(f"ERROR: {pkg}: {e}")

    log("Done.")


if __name__ == "__main__":
    main()
