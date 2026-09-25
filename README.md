Running the ETL pipeline by hand

1. Set up the environment once. From the repo root, run py -m venv venv, then venv\Scripts\activate, then pip install -r requirements.txt.
2. Set up the database once. Make sure MySQL is running on localhost. Run cricket-stats-schema.sql to create the cricket_explorer database and its tables. Then create a MySQL user etl with password etlv1 that has access to cricket_explorer. The ETL expects that login (DB_CONFIG, cricsheet-etl-pipeline.py:11), and the schema file doesn't create it.
3. Run it on one file: py cricsheet-etl-pipeline.py cricsheet_data\1082591.json. If you don't give a file, it uses the sample wi_201706.json.
4. Check the result. The output appears in the console and is also written to etl.log. The command exits with 0 on success and 1 on failure. Failures are also recorded in IMPORT_LOG (status = 'FAILED') and ETL_ERROR_LOG.

Running the scraper

1. Complete steps 1–2 above, and run everything from the repo root. The scraper finds cricsheet-etl-pipeline.py and cricsheet_data/ relative to the folder you're in.
2. Optionally, list the competition codes: py cricsheet_scraper.py --list
3. Run it: py cricsheet_scraper.py -c ipl. Without -c, it downloads matches from the last 2 days. Add --dry-run to download and extract without running the ETL.
4. To run the ETL on files you already have: py cricsheet_scraper.py -f cricsheet_data. It skips any file already marked SUCCESS in IMPORT_LOG.
5. Read the summary at the end. If any files failed, look in etl.log or ETL_ERROR_LOG to see why.


Each record's ID is a fingerprint calculated from the details that describe it, so the same thing always gets the same ID and we never store it twice.