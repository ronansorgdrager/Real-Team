# Cricket Explorer

## Setting up a new machine

Do these steps once per machine. All commands are run from the repo root.

### 1. Install the tools

- **Git**, then clone the repo: `git clone https://github.com/ronansorgdrager/Real-Team`
- **Python 3.10 or newer**, from python.org. This installs the `py` launcher, which the commands below use. The pinned package versions need 3.10 or newer.
- **MySQL Server**, running on localhost (port 3306), plus MySQL Workbench or the `mysql` command line tool.
- **PHP 7.4 or newer** with the `mysqli` extension turned on. In `php.ini`, make sure the line `extension=mysqli` doesn't start with `;`.

### 2. Set up Python

```
py -m venv venv
venv\Scripts\activate
pip install -r requirements.txt
```

The folder has to be called `venv` and sit in the repo root, because the Sync button in the web app looks for `venv\Scripts\python.exe` (see "How the Sync button works" below).

The venv is git-ignored, so cloning the repo doesn't give you one. Everyone has to create their own with the commands above. If you skip this step, the Sync button won't warn you. It quietly falls back to the system Python, which doesn't have the packages, and the sync fails.

### 3. Set up the database

1. Run `cricket-stats-schema.sql`. It creates the `cricket_explorer` database and its tables.
2. Create the login that the ETL, the scraper and the web app all use. The schema file doesn't create it:

   ```sql
   CREATE USER 'etl'@'localhost' IDENTIFIED BY 'etlv1';
   GRANT ALL PRIVILEGES ON cricket_explorer.* TO 'etl'@'localhost';
   ```

   The login is hardcoded in `cricsheet-etl-pipeline.py` (`DB_CONFIG`), `cricsheet_scraper.py` and `application.php`. If you use different details, change all three.

You don't need `migrate-scorecard.sql` on a new machine. It only upgrades databases built from an older version of the schema.

### 4. Load some data

With the venv activated, run `python cricsheet-etl-pipeline.py` to load the sample match in `wi_201706.json`. For real data, use the scraper (see "Running the scraper" below), or the Sync button in the web app.

### 5. Run the web app

```
php -S localhost:8000
```

Then open http://localhost:8000/application.php in a browser.

## How the Sync button works

The Sync card in the web app pulls new matches from Cricsheet into the database.

1. Pick a competition (and a season, if you want one), enter the admin key `RDRP`, and press Sync.
2. `application.php` runs `cricsheet_scraper.py` in the background. It uses the Python in `venv\Scripts\python.exe`, so it gets the packages installed in step 2. If there's no venv, it falls back to the system `py` launcher, which only works if the packages are also installed there.
3. The scraper downloads and unzips the match files into `cricsheet_data/`, then runs the ETL on each one.
4. When it finishes, the page shows how many files were imported and how many failed. The request waits for the whole sync, so a large competition can take a while.

If a sync fails, check `etl.log` in the repo root, or the `ETL_ERROR_LOG` table.

## Running the ETL pipeline by hand

1. Complete the setup above, and activate the venv with `venv\Scripts\activate`.
2. Run it on one file: `python cricsheet-etl-pipeline.py cricsheet_data\1082591.json`. If you don't give a file, it uses the sample `wi_201706.json`.
3. Check the result. The output appears in the console and is also written to `etl.log`. The command exits with 0 on success and 1 on failure. Failures are also recorded in `IMPORT_LOG` (status = 'FAILED') and `ETL_ERROR_LOG`.

## Running the scraper

1. Complete the setup above, activate the venv, and run everything from the repo root. The scraper finds `cricsheet-etl-pipeline.py` and `cricsheet_data/` relative to the folder you're in.
2. Optionally, list the competition codes: `python cricsheet_scraper.py --list`
3. Run it: `python cricsheet_scraper.py -c ipl`. Without `-c`, it downloads matches from the last 2 days. Add `--dry-run` to download and extract without running the ETL.
4. To run the ETL on files you already have: `python cricsheet_scraper.py -f cricsheet_data`. It skips any file already marked SUCCESS in `IMPORT_LOG`.
5. Read the summary at the end. If any files failed, look in `etl.log` or `ETL_ERROR_LOG` to see why.

## How IDs work

Each record's ID is a fingerprint calculated from the details that describe it, so the same thing always gets the same ID and we never store it twice.
