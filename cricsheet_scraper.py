import sys
import zipfile
import argparse
import requests
import tempfile
import json
from pathlib import Path

# cricsheets url as well as the local output folder made fo the json files
CRICSHEET_BASE = "https://cricsheet.org/downloads/"
DOWNLOAD_DIR = Path("cricsheet_data")

# the etl script so as to call for each json file
ETL_SCRIPT = Path("cricsheet-etl-pipeline.py")

# cricAPI as the fallback for the data source to be used in the instance that the cricsheet download happens to fail
CRICAPI_KEY = "8dfc8788-23cb-4b13-8d2c-4f77e5ebe72f"
CRICAPI_BASE = "https://api.cricapi.com/v1"

# the available competition download codes with their cricsheet zip filenames
COMPETITIONS = {
    "recently_added": "recently_added_2_json.zip",  # default - last 2 days of matches
    "bbl":            "bbl_json.zip",
    "ipl":            "ipl_json.zip",
    "cpl":            "cpl_json.zip",
    "psl":            "psl_json.zip",
    "t20s":           "t20s_json.zip",
    "t20s_female":    "t20s_female_json.zip",
    "odis":           "odis_json.zip",
    "odis_female":    "odis_female_json.zip",
    "tests":          "tests_json.zip",
    "ssh":            "ssh_json.zip",
    "all":            "all_json.zip",
}


def list_competitions():
    # printing all the available competition codes
    print("\nAvailable competition codes:")
    print(f"  {'CODE':<20} {'ZIP FILE'}")
    print(f"  {'-'*20} {'-'*30}")
    for code, filename in COMPETITIONS.items():
        print(f"  {code:<20} {filename}")
    print()


def download_zip(url, destination):
    # downloading the zip file from cricsheet with a browser style user agent
    print(f"  Downloading: {url}")
    headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"}
    response = requests.get(url, stream=True, timeout=60, headers=headers)
    response.raise_for_status()

    total = int(response.headers.get("content-length", 0))
    downloaded = 0
    zip_path = destination / "download.zip"

    with open(zip_path, "wb") as f:
        for chunk in response.iter_content(chunk_size=8192):
            f.write(chunk)
            downloaded += len(chunk)
            if total:
                pct = int(downloaded / total * 100)
                print(f"\r  Progress: {pct}%", end="", flush=True)

    print(f"\r  Downloaded: {downloaded / 1024:.1f} KB")
    return zip_path


def extract_json_files(zip_path, output_dir):
    # extracting the only json files from the zip out into a flat output folder
    output_dir.mkdir(parents=True, exist_ok=True)
    extracted = []

    with zipfile.ZipFile(zip_path, "r") as zf:
        json_files = [f for f in zf.namelist() if f.endswith(".json")]
        print(f"  Found {len(json_files)} JSON file(s) in zip.")

        for filename in json_files:
            target = output_dir / Path(filename).name
            # skipping the files that have already been extracted out
            if target.exists():
                continue
            zf.extract(filename, output_dir)
            # flattening any nested folder structure from the zip
            extracted_path = output_dir / filename
            if extracted_path != target and extracted_path.exists():
                extracted_path.rename(target)
            extracted.append(target)

    print(f"  New files extracted: {len(extracted)}")
    return extracted


def check_already_imported(json_path):
    # checks the import log table so that it can be observed whether this specific file has already been processed in order to avoid getting duplicates in the db
    # this returns true if its already imported so that it can be skipped
    try:
        import mysql.connector
        conn = mysql.connector.connect(
            host="localhost",
            user="etl",
            password="etlv1",
            database="cricket_explorer"
        )
        cursor = conn.cursor()
        cursor.execute(
            "SELECT COUNT(*) FROM IMPORT_LOG WHERE source_url = %s AND status = 'SUCCESS'",
            (json_path.name,)
        )
        count = cursor.fetchone()[0]
        conn.close()
        return count > 0
    except Exception:
        # If the DB isn't set up yet or connection fails, just process the file anyway
        return False


def run_etl_on_file(json_path):
    # this will call the etl pipeline script for a single json file
    # will then return as true if it has actually ran successfully
    import subprocess
    result = subprocess.run(
        [sys.executable, str(ETL_SCRIPT), str(json_path)],
        capture_output=True,
        text=True
    )
    if result.returncode != 0:
        print(f"    [ERROR] ETL failed for {json_path.name}: {result.stderr.strip()}")
        return False
    return True


def cricapi_get_recent_matches():
    # from here can fetch a list of recent matches from cricapi
    print("  Trying CricAPI fallback...")
    try:
        response = requests.get(
            f"{CRICAPI_BASE}/matches",
            params={"apikey": CRICAPI_KEY, "offset": 0},
            timeout=30
        )
        response.raise_for_status()
        data = response.json()
        if data.get("status") != "success":
            print(f"  [CricAPI] Unexpected response status: {data.get('status')}")
            return []
        matches = data.get("data", [])
        print(f"  [CricAPI] Found {len(matches)} recent matches.")
        return matches
    except Exception as e:
        print(f"  [CricAPI ERROR] {e}")
        return []


def cricapi_get_match_detail(match_id):
    #  fetching the full scorecard detail for a single match from CricAPI now
    try:
        response = requests.get(
            f"{CRICAPI_BASE}/match_info",
            params={"apikey": CRICAPI_KEY, "id": match_id},
            timeout=30
        )
        response.raise_for_status()
        data = response.json()
        if data.get("status") == "success":
            return data.get("data")
        return None
    except Exception as e:
        print(f"  [CricAPI ERROR] Could not fetch match {match_id}: {e}")
        return None


def save_cricapi_match_as_json(match, output_dir):
    # saving a cricAPI match as a json file in order for the etl pipeline to be able to process it effectively
    # the files from this are prefixed with cricapi_ so that they can be identified by source
    match_id = match.get("id")
    if not match_id:
        return None

    output_dir.mkdir(parents=True, exist_ok=True)
    target = output_dir / f"cricapi_{match_id}.json"

    # skips if its already saved
    if target.exists():
        return None

    detail = cricapi_get_match_detail(match_id)
    if not detail:
        return None

    with open(target, "w") as f:
        json.dump(detail, f, indent=2)

    return target


def run_cricapi_fallback(output_dir):
    # the full cricAPI fallback flow. this will fetch matches and the save as json files
    matches = cricapi_get_recent_matches()
    if not matches:
        print("  [CricAPI] No matches returned.")
        return []

    saved = []
    for match in matches:
        path = save_cricapi_match_as_json(match, output_dir)
        if path:
            saved.append(path)

    print(f"  [CricAPI] Saved {len(saved)} new match file(s).")
    return saved


def main():
    parser = argparse.ArgumentParser(description="Cricsheet scraper for Cricket Stats Explorer.")
    parser.add_argument("--competition", "-c", default="recently_added",
                        help="Competition to download (default: recently_added). Use --list to see options.")
    parser.add_argument("--list", "-l", action="store_true",
                        help="List available competition codes and exit.")
    parser.add_argument("--local", "-f", type=Path, default=None,
                        help="Use a local zip file or folder instead of downloading.")
    parser.add_argument("--dry-run", action="store_true",
                        help="Download and extract files but skip the ETL pipeline.")
    parser.add_argument("--output-dir", "-o", type=Path, default=DOWNLOAD_DIR,
                        help=f"Folder to store extracted JSON files (default: {DOWNLOAD_DIR})")
    args = parser.parse_args()

    if args.list:
        list_competitions()
        return

    # validate the competition code ensuring its compatible
    competition = args.competition.lower()
    if competition not in COMPETITIONS:
        print(f"[ERROR] Unknown competition code: '{competition}'")
        print("Run with --list to see available options.")
        sys.exit(1)

    url = CRICSHEET_BASE + COMPETITIONS[competition]

    print(f"\n{'='*60}")
    print(f"  Cricsheet Scraper")
    print(f"  Competition : {competition}")
    print(f"  Source URL  : {url}")
    print(f"  Output dir  : {args.output_dir}")
    print(f"{'='*60}\n")

    # getting the files either from a local path or by downloading
    if args.local:
        local_path = args.local
        if not local_path.exists():
            print(f"[ERROR] Local path not found: {local_path}")
            sys.exit(1)
        if local_path.is_dir():
            # using all json files already in the folder
            print(f"[1/3] Using local folder: {local_path}")
            new_files = list(local_path.glob("*.json"))
            print(f"  Found {len(new_files)} JSON file(s).")
        elif local_path.suffix == ".zip":
            # extracting from a local zip
            print(f"[1/3] Using local zip: {local_path}")
            print("\n[2/3] Extracting JSON files...")
            new_files = extract_json_files(local_path, args.output_dir)
        else:
            # single json file passed directly
            print(f"[1/3] Using single file: {local_path}")
            new_files = [local_path]
    else:
        print("[1/3] Downloading from Cricsheet...")
        try:
            with tempfile.TemporaryDirectory() as tmp:
                zip_path = download_zip(url, Path(tmp))
                print("\n[2/3] Extracting JSON files...")
                new_files = extract_json_files(zip_path, args.output_dir)
        except requests.exceptions.RequestException as e:
            # if cricsheet failed then should try cricAPI instead
            print(f"\n[ERROR] Cricsheet download failed: {e}")
            print("\n  Attempting CricAPI fallback...")
            new_files = run_cricapi_fallback(args.output_dir)
            if not new_files:
                print("\n  Both Cricsheet and CricAPI failed. Check your connection.")
                print(f"  You can also manually download the zip from:\n    {url}")
                print(f"  Then run: py cricsheet_scraper.py --local <path-to-zip>")
                sys.exit(1)

    if not new_files:
        print("\nNo new files to process. Everything is already up to date.")
        return

    # runs the etl on each new file
    if args.dry_run:
        print(f"\n[3/3] Dry run. Skipping ETL. {len(new_files)} file(s) ready in {args.output_dir}")
        return

    if not ETL_SCRIPT.exists():
        print(f"\n[WARNING] ETL script not found at '{ETL_SCRIPT}'.")
        print(f"  Files are in {args.output_dir} run the ETL manually.")
        return

    print(f"\n[3/3] Running ETL pipeline on {len(new_files)} file(s)...")
    success = 0
    failed = 0

    for i, json_file in enumerate(new_files, 1):
        # skips anything thats already recorded as imported in the database at this point
        if check_already_imported(json_file):
            print(f"  [{i}/{len(new_files)}] Skipping (already imported): {json_file.name}")
            success += 1
            continue

        print(f"  [{i}/{len(new_files)}] Processing: {json_file.name}")
        if run_etl_on_file(json_file):
            success += 1
        else:
            failed += 1

    print(f"\n{'='*60}")
    print(f"  Done! Processed {success} file(s) successfully.")
    if failed:
        print(f"  {failed} file(s) failed. Check errors above.")
    print(f"{'='*60}\n")


if __name__ == "__main__":
    main()
