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

## Import logs

The ETL records every run in two database tables. `IMPORT_LOG` gets one row for each run, whether it passed or failed. `ETL_ERROR_LOG` gets one row for each failed run, with the details of what went wrong.

### IMPORT_LOG

| Column | What it holds |
|---|---|
| `log_id` | Row ID. `ETL_ERROR_LOG.log_id` points back to it. |
| `import_timestamp` | When the row was written, in the MySQL server's time. |
| `source_url` | The bare filename of the imported file, such as `1082591.json`. Despite its name, it never holds a URL. |
| `status` | `SUCCESS` or `FAILED`. |
| `is_duplicate` | `1` if the match was already in the database and this run replaced it. Always `0` on a `FAILED` row. |
| `notes` | Set only on duplicates. It names the match and teams, says when the file was last imported, and gives how many performance rows were replaced. |

How rows get written:

- **Success:** the `SUCCESS` row is inserted in the same transaction as the match data. It is committed with the match, or rolled back with it, so a `SUCCESS` row always means the match data is in the database.
- **Failure:** the transaction is rolled back, then a `FAILED` row is written on a separate connection.
- **Re-runs:** a file that has been imported more than once has more than one row, so a file that failed and later succeeded has a `FAILED` row followed by a `SUCCESS` row. The latest row is the current state.

What reads it:

- The scraper skips any file that has a `SUCCESS` row for its filename. To make it re-import a file, pass it to the ETL directly: `python cricsheet-etl-pipeline.py <file>`.
- The Sync button reports its imported and failed counts from the rows written since the sync started.

### ETL_ERROR_LOG

| Column | What it holds |
|---|---|
| `error_id` | Row ID. |
| `occurred_at` | When the failure was recorded. |
| `log_id` | The `FAILED` row in `IMPORT_LOG` for the same run. It has no foreign key, because the `etl` user isn't granted `REFERENCES`. |
| `source_file` | The bare filename, the same value as `IMPORT_LOG.source_url`. |
| `stage` | The part of the pipeline that was running when the error happened. See below. |
| `error_type` | The Python exception class, such as `FileNotFoundError` or `IntegrityError`. |
| `error_message` | The exception message. |
| `traceback` | The full Python traceback. `etl.log` doesn't include it, so this column is the only place to find it. |

`stage` is one of:

| Stage | What was running | Typical causes |
|---|---|---|
| `startup` | Setup before the file is opened | Only if errors with the file |
| `read_source` | Opening and parsing the JSON | Missing file, invalid JSON |
| `transform` | Building rows from the JSON | A file whose structure isn't Cricsheet's |
| `connect` | Opening the database connection | Normally never recorded, see the limitation below |
| `load` | Writing the match to the database | A constraint violation, a missing permission, or a schema change |

### Limitation: database outages aren't recorded

Both failure rows are written to the same database the import uses. If MySQL is down or refuses the `etl` login, no rows are written, and the failure only appears in `etl.log` as "Could not write the failure to the database". When a sync fails but neither table has a row for it, check `etl.log`.

### Useful queries

```sql
-- The most recent failures, with their errors
SELECT i.import_timestamp, e.source_file, e.stage, e.error_type, e.error_message
FROM ETL_ERROR_LOG e
LEFT JOIN IMPORT_LOG i ON i.log_id = e.log_id
ORDER BY e.occurred_at DESC
LIMIT 20;

-- Files whose latest run failed, and are still not imported
SELECT l.source_url, l.import_timestamp
FROM IMPORT_LOG l
WHERE l.log_id = (SELECT MAX(log_id) FROM IMPORT_LOG WHERE source_url = l.source_url)
  AND l.status = 'FAILED';

-- Re-imports that replaced an existing match
SELECT import_timestamp, source_url, notes
FROM IMPORT_LOG
WHERE is_duplicate = 1
ORDER BY import_timestamp DESC;
```

## How IDs work

Each record's ID is a fingerprint calculated from the details that describe it, so the same thing always gets the same ID and we never store it twice.
