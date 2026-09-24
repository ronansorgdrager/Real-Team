import sys
import time
import zipfile
import argparse
import requests
import json
import logging
from datetime import datetime
from pathlib import Path

# cricsheets url as well as the local output folder made for the json files
CRICSHEET_BASE = "https://cricsheet.org/downloads/"
DOWNLOAD_DIR = Path("cricsheet_data")

# this is where all of the downloaded competition zips get cached. they go here so that an unchanged zip doesnt end up getting refetched on every single run
CACHE_DIR = Path("cricsheet_cache")

# the etl script so as to call for each json file
ETL_SCRIPT = Path("cricsheet-etl-pipeline.py")

# cricAPI as the fallback for the data source to be used in the instance that the cricsheet download happens to fail
CRICAPI_KEY = "8dfc8788-23cb-4b13-8d2c-4f77e5ebe72f"
CRICAPI_BASE = "https://api.cricapi.com/v1"

# the available competition download codes with their cricsheet zip filenames
COMPETITIONS = {
    "recently_added": "recently_added_2_json.zip",  # the default which is the last 2 days of matches
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

# showcases just how many times it is that a failed cricsheet download gets retried. also showcasing how long to wait between the attempts before just giving up and falling back to cricAPI as the backup
DOWNLOAD_RETRIES = 3
DOWNLOAD_RETRY_DELAY_SECONDS = 5


# for the logging everything that does get printed does so as it goes to the console wherein it is also written to scraper.log so that a run triggered from the sites sync button will still leave behind a record after the fact
logging.basicConfig(
    filename="scraper.log",
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
)


def log(message, level="info"):
    # this prints to the console as well as giving the message to the scraper log with a timestamp for accurate reportings sake
    print(message)
    clean = message.strip()
    if not clean:
        return
    getattr(logging, level)(clean)


def list_competitions():
    # printing all of the available competition codes for filtering
    print("\nAvailable competition codes:")
    print(f"  {'CODE':<20} {'ZIP FILE'}")
    print(f"  {'-'*20} {'-'*30}")
    for code, filename in COMPETITIONS.items():
        print(f"  {code:<20} {filename}")
    print()


def get_remote_last_modified(url):
    # a light  request to check on when the file on cricsheets end last changed without actually having to download it outright
    try:
        response = requests.head(url, timeout=20, allow_redirects=True)
        response.raise_for_status()
        return response.headers.get("Last-Modified")
    except requests.exceptions.RequestException:
        return None


def load_cache_metadata(competition):
    # serving here as a small side file that operates through rememberign when this competition zip was last downloaded
    meta_path = CACHE_DIR / f"{competition}.meta.json"
    if not meta_path.exists():
        return {}
    try:
        with open(meta_path, "r", encoding="utf-8") as f:
            return json.load(f)
    except (OSError, json.JSONDecodeError):
        return {}


def save_cache_metadata(competition, last_modified):
    # this being what writes the timestamp so that the next run can simply just skip downloading the same material again if nothing has actually changed
    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    meta_path = CACHE_DIR / f"{competition}.meta.json"
    with open(meta_path, "w", encoding="utf-8") as f:
        json.dump({"last_modified": last_modified, "cached_at": datetime.now().isoformat()}, f)


def download_zip(url, destination_path):
    # downloading the zip file from cricsheet to acquire the needed data
    log(f"  Downloading: {url}")
    headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"}
    # here pretending to be a normal browser just so that the download is less likely to get blocked. still ethical as this is not disruptive scraping, only a method of access
    response = requests.get(url, stream=True, timeout=60, headers=headers)
    response.raise_for_status()

    total = int(response.headers.get("content-length", 0))
    downloaded = 0

    with open(destination_path, "wb") as f:
        for chunk in response.iter_content(chunk_size=8192):
            f.write(chunk)
            downloaded += len(chunk)
            if total:
                pct = int(downloaded / total * 100)
                print(f"\r  Progress: {pct}%", end="", flush=True)

    print()
    log(f"  Downloaded: {downloaded / 1024:.1f} KB")
    return response.headers.get("Last-Modified")


def download_zip_with_retries(url, destination_path, retries=DOWNLOAD_RETRIES, delay=DOWNLOAD_RETRY_DELAY_SECONDS):
    # ideally a network hiccup wont just immediately rule out the primary data source. since it is the case that cricapi is a more limited fallback. therefore a plain fail on the request will instead result in a couple of retries first as seen here
    last_error = None
    for attempt in range(1, retries + 1):
        try:
            return download_zip(url, destination_path)
        except requests.exceptions.RequestException as e:
            last_error = e
            log(f"  [WARNING] Download attempt {attempt}/{retries} has failed: {e}", level="warning")
            if attempt < retries:
                log(f"  Retrying in {delay}s...")
                time.sleep(delay)
    raise last_error


def get_zip_for_competition(competition, url):
    # here returning a path to a usable competition zip. only downloading fresh if it happens that the cached copy is missing or that cricsheets own copy has changed since
    CACHE_DIR.mkdir(parents=True, exist_ok=True)
    cached_zip = CACHE_DIR / COMPETITIONS[competition]
    metadata = load_cache_metadata(competition)
    cached_last_modified = metadata.get("last_modified")

    if cached_zip.exists() and cached_last_modified:
        remote_last_modified = get_remote_last_modified(url)
        if remote_last_modified and remote_last_modified == cached_last_modified:
            log(f"  Cached copy is up to date ({cached_last_modified}). Skipping the download.")
            # there is no point downloading again repeatedly if cricsheet hasnt actually changed the zip at all
            return cached_zip
        if remote_last_modified is None:
            log("  [WARNING] Could not confirm whether the cached copy is actually current. Therefore downloading fresh just to be safe.", level="warning")

    last_modified = download_zip_with_retries(url, cached_zip)
    if last_modified:
        save_cache_metadata(competition, last_modified)
    return cached_zip


def extract_json_files(zip_path, output_dir):
    # now extracting the only json files from the zip right out into a flat output folder for the rest of the pipeline to use
    output_dir.mkdir(parents=True, exist_ok=True)
    extracted = []

    try:
        with zipfile.ZipFile(zip_path, "r") as zf:
            json_files = [f for f in zf.namelist() if f.endswith(".json")]
            log(f"  Found {len(json_files)} JSON file(s) in zip.")
            # ensuring that the program will only grab the json match files and that it will ignore anything else thats in the zip

            for filename in json_files:
                target = output_dir / Path(filename).name
                # skipping the files that have already been extracted out to avoid any unecessary redos
                if target.exists():
                    continue
                zf.extract(filename, output_dir)
                # flattening any potentially nested folder structure from the zip for cleaner access
                extracted_path = output_dir / filename
                if extracted_path != target and extracted_path.exists():
                    extracted_path.rename(target)
                extracted.append(target)
    except zipfile.BadZipFile:
        # this exist to ensure that a corrupted download wont just crash the whole run
        log(f"  [ERROR] '{zip_path}' is not a valid zip file. It is likely a corrupted or an incomplete download).", level="error")
        # if it is a bad/corrupt zip then it will stop cleanly instead of just crashing the whole entire script which would be terribly disruptive
        log("  Try running again and if this keeps happening, then try to delete the cached copy in cricsheet_cache/ before retrying.", level="error")
        return []
    except OSError as e:
        log(f"  [ERROR] Could not read '{zip_path}': {e}", level="error")
        return []

    log(f"  New files have been extracted: {len(extracted)}")
    return extracted


def filter_by_season(json_files, seasons):
    
    # if there is no season that was specifically selected for than this will ensure that it just hands every file straight back
    if not seasons:
        return json_files

    if isinstance(seasons, str):
        wanted = {seasons.strip()}
    else:
        wanted = {str(s).strip() for s in seasons if str(s).strip()}

    matching = []
    skipped_unreadable = 0

    for path in json_files:
        try:
            with open(path, "r", encoding="utf-8") as f:
                data = json.load(f)
        except (OSError, json.JSONDecodeError) as e:
            log(f"  [WARNING] Could not read {path.name} to check its season: {e}", level="warning")
            skipped_unreadable += 1
            continue

        file_season = str(data.get("info", {}).get("season", "")).strip()
        if file_season in wanted:
            matching.append(path)

    season_label = ", ".join(sorted(wanted))
    log(f"  Season filter '{season_label}': {len(matching)} of {len(json_files)} file(s) matched"
        + (f" ({skipped_unreadable} unreadable, skipped)" if skipped_unreadable else "") + ".")
    return matching


def check_already_imported(json_path):
    # checks the import log table so that it can be observed whether this specific file has already been processed in order to avoid getting duplicates in the database this returns true if its already imported so that it can be skipped
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
        # serving here to ask the database if its the case that this exact filename has already imported cleanly before
            "SELECT COUNT(*) FROM IMPORT_LOG WHERE source_url = %s AND status = 'SUCCESS'",
            (json_path.name,)
        )
        count = cursor.fetchone()[0]
        conn.close()
        return count > 0
    except Exception:
        # If the database isnt set up yet or the connection fails, can just process the file anyway
        return False


def run_etl_on_file(json_path):
    # this will call the etl pipeline script for a single json file and from there will then return as true if it has actually ran successfully
    import subprocess
    result = subprocess.run(
        [sys.executable, str(ETL_SCRIPT), str(json_path)],
        # here is running the etl script as its own process for just this one match file in particular
        capture_output=True,
        text=True
    )
    if result.returncode != 0:
        log(f"    [ERROR] ETL failed for {json_path.name}: {result.stderr.strip()}", level="error")
        return False
    return True


def cricapi_get_recent_matches():
    # from here can fetch a list of the recent matches from cricapi if the backup is needed
    log("  Trying the CricAPI fallback...")
    try:
        response = requests.get(
            f"{CRICAPI_BASE}/matches",
            params={"apikey": CRICAPI_KEY, "offset": 0},
            timeout=30
        )
        response.raise_for_status()
        data = response.json()
        if data.get("status") != "success":
            log(f"  [CricAPI] Unexpected response status: {data.get('status')}", level="warning")
            return []
        matches = data.get("data", [])
        log(f"  [CricAPI] Found {len(matches)} recent matches.")
        return matches
    except Exception as e:
        log(f"  [CricAPI ERROR] {e}", level="error")
        return []


def cricapi_get_match_detail(match_id):
    #  fetching the full scorecard detail for a single match from cricAPI now for accurate information
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
        log(f"  [CricAPI ERROR] Could not fetch the match {match_id}: {e}", level="error")
        return None


def save_cricapi_match_as_json(match, output_dir):
    # saving a cricAPI match as a json file in order for the etl pipeline to be able to process it effectively the files from this are prefixed with cricapi_ so that they can be identified by source
    match_id = match.get("id")
    if not match_id:
        return None

    output_dir.mkdir(parents=True, exist_ok=True)
    target = output_dir / f"cricapi_{match_id}.json"

    # skips if its already saved as part of the process
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
        log("  [CricAPI] No matches were returned")
        return []

    saved = []
    for match in matches:
        path = save_cricapi_match_as_json(match, output_dir)
        if path:
            saved.append(path)

    log(f"  [CricAPI] Saved {len(saved)} new match file(s).")
    return saved


def confirm_full_download():
    # the 'all' option will download everything that cricsheet has, however this is of course both large and slow, therefore will only prompt for confirmation when a person is actually sitting at the terminal
    if not sys.stdin.isatty():
        log("  [WARNING] '--competition all' does download Cricsheet's entire dataset. "
            "Running non interactively, so continuing without confirmation.", level="warning")
        return True

    print("\n  [WARNING] '--competition all' downloads every single match that Cricsheet has, across each and every")
    print("  competition that it tracks. This is a large download and will thus take a while to complete.")
    answer = input("  Continue? [y/N]: ").strip().lower()
    return answer == "y"


def main():
    parser = argparse.ArgumentParser(description="Cricsheet scraper for Cricket Stats Explorer.")
    parser.add_argument("--competition", "-c", default="recently_added",
                        help="Competition to download (default: recently_added). Use --list to see options.")
    parser.add_argument("--season", "-s", default=None,
                        help="Season(s) to filter to within the chosen competition, comma-separated for more "
                             "than one (e.g. '2024' or '2023,2024'). Matches each match file's own 'season' "
                             "field. If omitted, all seasons in the competition are processed.")
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
        print("Run with --list to see the available options.")
        sys.exit(1)

    seasons = [s.strip() for s in args.season.split(",")] if args.season else None
    # at this stage can turn a comma list of something like "2023,2024" into a proper python list for better processing

    url = CRICSHEET_BASE + COMPETITIONS[competition]

    log(f"\n{'='*60}")
    log(f"  Cricsheet Scraper")
    log(f"  Competition : {competition}")
    if seasons:
        log(f"  Season(s)   : {', '.join(seasons)}")
    log(f"  Source URL  : {url}")
    log(f"  Output dir  : {args.output_dir}")
    log(f"{'='*60}\n")

    if competition == "all" and not args.local:
        if not confirm_full_download():
            log("  Cancelled.")
            return

    # getting the files either from a local path or by downloading
    if args.local:
        local_path = args.local
        if not local_path.exists():
            print(f"[ERROR] Local path was not found: {local_path}")
            sys.exit(1)
        if local_path.is_dir():
            # using all json files that are already in the folder
            log(f"[1/3] Using local folder: {local_path}")
            new_files = list(local_path.glob("*.json"))
            log(f"  Found {len(new_files)} JSON file(s).")
        elif local_path.suffix == ".zip":
            # extracting then from a local zip
            log(f"[1/3] Using local zip: {local_path}")
            log("\n[2/3] Extracting JSON files...")
            new_files = extract_json_files(local_path, args.output_dir)
        else:
            # single json file that gets passed directly
            log(f"[1/3] Using single file: {local_path}")
            new_files = [local_path]
    else:
        log("[1/3] Fetching from Cricsheet (using cache if unchanged)...")
        try:
            zip_path = get_zip_for_competition(competition, url)
            log("\n[2/3] Extracting JSON files...")
            new_files = extract_json_files(zip_path, args.output_dir)
        except requests.exceptions.RequestException as e:
            # if cricsheet failed even after retries then should try cricAPI instead to ensure that one option will work out
            log(f"\n[ERROR] Cricsheet download failed after {DOWNLOAD_RETRIES} attempt(s): {e}", level="error")
            log("\n  Attempting CricAPI fallback...")
            # for the plan B wherein if cricsheet is down, can instead try pulling the recent matches from the backup cricapi site instead
            new_files = run_cricapi_fallback(args.output_dir)
            if not new_files:
                log("\n  Both Cricsheet and CricAPI failed. Check your connection.", level="error")
                log(f"  You can also manually download the zip from:\n    {url}")
                log(f"  Then run: py cricsheet_scraper.py --local <path-to-zip>")
                sys.exit(1)

    # here narrowing the files down to just the requested seasons if it is the case that any were given
    if seasons:
        log(f"\n[2b/3] Filtering to season(s) {', '.join(seasons)}...")
        new_files = filter_by_season(new_files, seasons)

    if not new_files:
        log("\nNo new files to process. Everything is already up to date.")
        return

    # runs the etl on each new file
    if args.dry_run:
        log(f"\n[3/3] Dry run. Skipping ETL. {len(new_files)} file(s) ready in {args.output_dir}")
        # can then stop here on purpose for a dry test since it is just useful for testing the download without needing to touch the database
        return

    if not ETL_SCRIPT.exists():
        log(f"\n[WARNING] ETL script not found at '{ETL_SCRIPT}'.", level="warning")
        log(f"  Files are in {args.output_dir} run the ETL manually.")
        return

    log(f"\n[3/3] Running ETL pipeline on {len(new_files)} file(s)...")
    success = 0
    failed = 0
    # here keeping a simple tally going so that the final summary is able to say how it went

    for i, json_file in enumerate(new_files, 1):
        # skips anything thats already recorded as imported in the database at this point
        if check_already_imported(json_file):
            log(f"  [{i}/{len(new_files)}] Skipping (already imported): {json_file.name}")
            # this will already be in the database from a previous run so dont need to import it twice
            success += 1
            continue

        log(f"  [{i}/{len(new_files)}] Processing: {json_file.name}")
        if run_etl_on_file(json_file):
            success += 1
        else:
            failed += 1

    log(f"\n{'='*60}")
    log(f"  Done! Processed {success} file(s) successfully.")
    if failed:
        log(f"  {failed} file(s) failed. Check the errors above.", level="warning")
    log(f"{'='*60}\n")


if __name__ == "__main__":
    main()
