
<?php
if (isset($_GET['action']) && $_GET['action'] === 'search') {
    header('Content-Type: application/json; charset=utf-8');

    $query = trim((string) ($_GET['q'] ?? ''));
    $requestedTypes = array_filter(array_map('trim', explode(',', (string) ($_GET['types'] ?? ''))));
    $typeMap = [
        'Players' => 'players',
        'Teams' => 'teams',
        'Competitions' => 'competitions',
        'Seasons' => 'seasons',
        'Matches' => 'matches'
    ];
    $types = array_values(array_intersect($requestedTypes, array_keys($typeMap)));
    if (!$types) {
        $types = array_keys($typeMap);
    }

    if ($query === '') {
        echo json_encode(['results' => []]);
        exit;
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    try {
        $connection = @new mysqli('localhost', 'etl', 'etlv1', 'cricket_explorer');
    } catch (Throwable $error) {
        $connection = null;
    }
    if (!$connection || $connection->connect_errno) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not connect to the cricket database.']);
        exit;
    }
    $connection->set_charset('utf8mb4');

    $searchableQueries = [
        'players' => "SELECT 'Players' AS type, p.player_id AS id, p.player_name AS title, CONCAT('Player ID: ', p.player_id) AS subtitle, JSON_OBJECT('birthDate', p.birth_date, 'nationality', p.nationality, 'team', (SELECT t.team_name FROM PERFORMANCE perf_team INNER JOIN TEAMS t ON t.team_id = perf_team.team_id WHERE perf_team.player_id = p.player_id GROUP BY t.team_id, t.team_name ORDER BY COUNT(*) DESC, t.team_name ASC LIMIT 1), 'matches', COUNT(DISTINCT m.match_id), 'runs', COALESCE(SUM(perf.runs_scored), 0), 'ballsFaced', COALESCE(SUM(perf.balls_faced), 0), 'wickets', COALESCE(SUM(perf.wickets_taken), 0), 'runsConceded', COALESCE(SUM(perf.runs_conceded), 0), 'catches', COALESCE(SUM(perf.catches), 0), 'stumpings', COALESCE(SUM(perf.stumpings), 0), 'runOuts', COALESCE(SUM(perf.run_outs), 0), 'maidens', COALESCE(SUM(perf.maidens), 0), 'outs', COALESCE(SUM(perf.is_out), 0), 'formats', COALESCE((SELECT JSON_ARRAYAGG(JSON_OBJECT('format', fmt.match_type, 'matches', fmt.matches, 'runs', fmt.runs, 'ballsFaced', fmt.balls_faced, 'outs', fmt.outs)) FROM (SELECT perf2.player_id AS player_id, COALESCE(m2.match_type, 'Unknown') AS match_type, COUNT(DISTINCT m2.match_id) AS matches, COALESCE(SUM(perf2.runs_scored), 0) AS runs, COALESCE(SUM(perf2.balls_faced), 0) AS balls_faced, COALESCE(SUM(perf2.is_out), 0) AS outs FROM PERFORMANCE perf2 INNER JOIN INNINGS i2 ON i2.innings_id = perf2.innings_id INNER JOIN MATCHES m2 ON m2.match_id = i2.match_id GROUP BY perf2.player_id, COALESCE(m2.match_type, 'Unknown')) AS fmt WHERE fmt.player_id = p.player_id), JSON_ARRAY()), 'bestBowling', COALESCE((SELECT CONCAT(best_perf.wickets_taken, '/', best_perf.runs_conceded) FROM PERFORMANCE best_perf WHERE best_perf.player_id = p.player_id ORDER BY best_perf.wickets_taken DESC, best_perf.runs_conceded ASC LIMIT 1), '0/0'), 'bestBatting', COALESCE((SELECT best_perf.runs_scored FROM PERFORMANCE best_perf WHERE best_perf.player_id = p.player_id ORDER BY best_perf.runs_scored DESC LIMIT 1), 0)) AS details FROM PLAYERS p LEFT JOIN PERFORMANCE perf ON perf.player_id = p.player_id LEFT JOIN INNINGS i ON i.innings_id = perf.innings_id LEFT JOIN MATCHES m ON m.match_id = i.match_id WHERE p.player_name LIKE ? GROUP BY p.player_id, p.player_name, p.birth_date, p.nationality",
        'teams' => "SELECT 'Teams' AS type, t.team_id AS id, t.team_name AS title, COALESCE(t.home_ground, (SELECT m3.venue FROM MATCHES m3 WHERE (m3.team1_id = t.team_id OR m3.team2_id = t.team_id) AND m3.venue IS NOT NULL AND m3.venue <> '' GROUP BY m3.venue ORDER BY COUNT(*) DESC, m3.venue ASC LIMIT 1), 'No home ground') AS subtitle, JSON_OBJECT('homeGround', COALESCE(t.home_ground, (SELECT m3.venue FROM MATCHES m3 WHERE (m3.team1_id = t.team_id OR m3.team2_id = t.team_id) AND m3.venue IS NOT NULL AND m3.venue <> '' GROUP BY m3.venue ORDER BY COUNT(*) DESC, m3.venue ASC LIMIT 1)), 'players', COALESCE((SELECT GROUP_CONCAT(DISTINCT p.player_name ORDER BY p.player_name SEPARATOR '||') FROM PERFORMANCE perf INNER JOIN PLAYERS p ON p.player_id = perf.player_id WHERE perf.team_id = t.team_id), ''), 'matches', COUNT(DISTINCT m.match_id), 'wins', COUNT(DISTINCT CASE WHEN m.winning_team_id = t.team_id THEN m.match_id END), 'draws', COUNT(DISTINCT CASE WHEN m.match_id IS NOT NULL AND m.winning_team_id IS NULL THEN m.match_id END), 'losses', COUNT(DISTINCT CASE WHEN m.match_id IS NOT NULL AND m.winning_team_id IS NOT NULL AND m.winning_team_id <> t.team_id THEN m.match_id END)) AS details FROM TEAMS t LEFT JOIN MATCHES m ON m.team1_id = t.team_id OR m.team2_id = t.team_id WHERE CONCAT_WS(' ', t.team_name, t.home_ground) LIKE ? GROUP BY t.team_id, t.team_name, t.home_ground",
        'competitions' => "SELECT 'Competitions' AS type, c.comp_id AS id, c.comp_name AS title, CONCAT('Competition ID: ', c.comp_id) AS subtitle, JSON_OBJECT('format', COALESCE((SELECT GROUP_CONCAT(DISTINCT m.match_type ORDER BY m.match_type SEPARATOR ', ') FROM MATCHES m INNER JOIN SEASONS s_fmt ON s_fmt.season_id = m.season_id WHERE s_fmt.comp_id = c.comp_id AND m.match_type IS NOT NULL), ''), 'currentSeason', COALESCE((SELECT s_cur.season_name FROM SEASONS s_cur INNER JOIN MATCHES m_cur ON m_cur.season_id = s_cur.season_id WHERE s_cur.comp_id = c.comp_id GROUP BY s_cur.season_id, s_cur.season_name ORDER BY MAX(m_cur.match_date) DESC LIMIT 1), ''), 'seasons', COALESCE((SELECT GROUP_CONCAT(s.season_name ORDER BY s.season_name SEPARATOR '||') FROM SEASONS s WHERE s.comp_id = c.comp_id), '')) AS details FROM COMPETITIONS c WHERE c.comp_name LIKE ?",
        'seasons' => "SELECT 'Seasons' AS type, s.season_id AS id, s.season_name AS title, c.comp_name AS subtitle, JSON_OBJECT('competition', c.comp_name, 'start', (SELECT MIN(m_s.match_date) FROM MATCHES m_s WHERE m_s.season_id = s.season_id), 'end', (SELECT MAX(m_e.match_date) FROM MATCHES m_e WHERE m_e.season_id = s.season_id), 'matches', COALESCE((SELECT GROUP_CONCAT(CONCAT(m.match_date, ' - ', t1.team_name, ' vs ', t2.team_name) ORDER BY m.match_date SEPARATOR '||') FROM MATCHES m INNER JOIN TEAMS t1 ON t1.team_id = m.team1_id INNER JOIN TEAMS t2 ON t2.team_id = m.team2_id WHERE m.season_id = s.season_id), '')) AS details FROM SEASONS s INNER JOIN COMPETITIONS c ON c.comp_id = s.comp_id WHERE CONCAT_WS(' ', s.season_name, c.comp_name) LIKE ?",
        'matches' => "SELECT 'Matches' AS type, m.match_id AS id, CONCAT(t1.team_name, ' vs ', t2.team_name) AS title, CONCAT(m.match_date, ' | ', COALESCE(m.venue, 'Venue unknown'), ' | ', COALESCE(s.season_name, 'Season unknown')) AS subtitle, JSON_OBJECT('season', s.season_name, 'date', m.match_date, 'venue', m.venue, 'teamA', t1.team_name, 'teamB', t2.team_name, 'winningTeam', winner.team_name, 'winType', m.win_type, 'winMargin', m.win_margin, 'winMethod', m.win_method, 'matchType', m.match_type, 'tossWinner', toss.team_name, 'tossDecision', m.toss_decision) AS details FROM MATCHES m INNER JOIN TEAMS t1 ON t1.team_id = m.team1_id INNER JOIN TEAMS t2 ON t2.team_id = m.team2_id LEFT JOIN TEAMS winner ON winner.team_id = m.winning_team_id LEFT JOIN TEAMS toss ON toss.team_id = m.toss_winner_id LEFT JOIN SEASONS s ON s.season_id = m.season_id WHERE CONCAT_WS(' ', m.match_id, m.match_date, m.venue, t1.team_name, t2.team_name, s.season_name, CONCAT(t1.team_name, ' vs ', t2.team_name)) LIKE ?"
    ];

    $parts = [];
    $parameters = [];
    foreach ($types as $type) {
        $parts[] = $searchableQueries[$typeMap[$type]];
        $parameters[] = '%' . $query . '%';
    }
    $resultLimit = $query === '%' ? 10000 : 50;
    $sql = 'SELECT * FROM (' . implode(' UNION ALL ', $parts) . ') AS search_results ORDER BY title LIMIT ' . $resultLimit;
    $statement = $connection->prepare($sql);
    if (!$statement) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not prepare the search query.']);
        $connection->close();
        exit;
    }
    $statement->bind_param(str_repeat('s', count($parameters)), ...$parameters);
    $statement->execute();
    $result = $statement->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$row) {
        $row['details'] = json_decode($row['details'], true) ?: [];
    }
    echo json_encode(['results' => $rows]);
    $statement->close();
    $connection->close();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wireframe</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: white;
        }
        #sidebar {
            width: 150px;
            background: #f0f0f0;
            color: black;
            padding: 20px;
            float: left;
            min-height: 100vh;
        }
        #sidebar h2 {
            margin-top: 0;
        }
        #sidebar p {
            margin: 0 0 20px;
        }
        .item-toggle {
            margin-bottom: 10px;
            padding: 10px;
            background: #e0e0e0;
            cursor: pointer;
        }
        .item-toggle.active {
            background: #d0d0d0;
        }
        .item-toggle span {
            display: block;
        }
        #main {
            margin-left: 190px;
            padding: 20px;
        }
        #main h1 {
            margin-top: 0;
        }
        #stack {
            margin-top: 20px;
        }
        .stack-item {
            position: relative;
            background: white;
            border: 1px solid #ccc;
            padding: 15px;
            margin-bottom: 10px;
        }
        .stack-item h3 {
            margin: 0 0 10px;
        }
        .stack-item .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .stack-item .bookmark-btn {
            background: none;
            border: 1px solid #0066cc;
            color: #0066cc;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .stack-item .bookmark-btn:hover {
            background: #f0f8ff;
        }
        .stack-item p {
            margin: 0;
        }
        .stack-item ul {
            margin: 10px 0 0;
            padding-left: 20px;
        }
        .stack-item ul li {
            margin-bottom: 6px;
        }
        .stack-item a {
            color: #0066cc;
            text-decoration: none;
        }
        .stack-item ul li a {
            color: #0066cc;
            text-decoration: none;
        }
        .stack-item ul li a:hover,
        .stack-item a:hover {
            text-decoration: underline;
        }
        .player-stats-card {
            display: grid;
            grid-template-columns: 150px 1fr;
            gap: 18px;
            padding: 16px 0 0;
            align-items: start;
        }
        .player-photo {
            width: 150px;
            min-height: 150px;
            background: #e8e8e8;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #777;
            font-size: 0.95rem;
            overflow: hidden;
        }
        .player-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .player-stats-content {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .player-stats-info {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px 40px;
            align-items: start;
        }
        .player-stats-info p {
            margin: 4px 0;
            font-size: 0.97rem;
        }
        .player-stats-info strong {
            display: inline-block;
            min-width: 110px;
        }
        .player-stats-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
        }
        .player-stats-table th,
        .player-stats-table td {
            border: 1px solid #ddd;
            padding: 10px 12px;
            text-align: left;
            font-size: 0.95rem;
        }
        .player-stats-table th {
            background: #f7f7f7;
            font-weight: 600;
        }
        .player-stats-table tbody tr:hover {
            background: #fbfbfb;
        }
        .search-box {
            display: flex;
            gap: 6px;
            margin-bottom: 12px;
        }
        .search-box input {
            flex: 1;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .search-box button {
            padding: 8px 14px;
            border: 1px solid #999;
            background: #f0f0f0;
            cursor: pointer;
            border-radius: 4px;
        }
        .search-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 12px;
        }
        .search-filters label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
        }
        .search-results {
            margin-top: 12px;
            padding: 10px;
            border: 1px solid #ccc;
            background: #f9f9f9;
            border-radius: 4px;
            min-height: 60px;
            color: #999;
            font-size: 0.95rem;
        }
        .search-result-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 8px 0;
            border-bottom: 1px solid #e5e5e5;
            color: #333;
        }
        .search-result-row:last-child {
            border-bottom: 0;
        }
        .search-result-details {
            min-width: 0;
        }
        .search-result-details small {
            color: #666;
        }
        .search-result-add {
            flex: 0 0 auto;
            padding: 6px 12px;
            border: 1px solid #0066cc;
            border-radius: 4px;
            background: white;
            color: #0066cc;
            cursor: pointer;
        }
        .search-result-add:hover {
            background: #f0f8ff;
        }
        .stack-item.added-result {
            border-color: #0066cc;
            box-shadow: 0 0 0 3px #e5f2ff;
        }
        .bookmark-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .bookmark-remove {
            flex: 0 0 auto;
            padding: 4px 9px;
            border: 1px solid #999;
            border-radius: 4px;
            background: white;
            color: #555;
            cursor: pointer;
        }
        .bookmark-remove:hover {
            border-color: #cc0000;
            color: #cc0000;
        }
        .comparison-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }
        .comparison-table th,
        .comparison-table td {
            border: 1px solid #ddd;
            padding: 10px 12px;
            text-align: left;
        }
        .comparison-table th {
            background: #f7f7f7;
        }
        .comparison-table .comparison-category {
            background: #eef5fb;
            color: #16466f;
            font-weight: 600;
        }
        .comparison-remove {
            margin-left: 8px;
            border: 1px solid #999;
            background: white;
            cursor: pointer;
        }
        .comparison-selectors {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 16px;
        }
        .comparison-selector {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .comparison-selector label {
            font-weight: 600;
        }
        .comparison-selector input {
            width: 100%;
            box-sizing: border-box;
            padding: 9px 10px;
            border: 1px solid #bbb;
            border-radius: 4px;
        }
        .comparison-selector select {
            width: 100%;
            min-height: 150px;
            padding: 6px;
            border: 1px solid #bbb;
            border-radius: 4px;
            background: white;
        }
        .comparison-table .comparison-better {
            color: #16803c;
            font-weight: 600;
        }
        .comparison-table .comparison-worse {
            color: #c62828;
            font-weight: 600;
        }
        @media (max-width: 700px) {
            .comparison-selectors {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <aside id="sidebar">
        <h2>Elements</h2>
        <p>Use the sidebar to show or hide elements in the explorer.</p>
        <div id="controls"></div>
    </aside>
    <main id="main">
        <h1>Cricket Stats Explorer</h1>
        <div id="stack"></div>
    </main>
    <script>
        const elements = [
            {
                id: 'search',
                title: 'Search',
                type: 'search',
                placeholder: 'Search players, teams, competitions, seasons, matches...',
                filters: ['Players', 'Teams',  'Competitions', 'Seasons', 'Matches']
            },
            {
                id: 'player-stats',
                title: 'Player Stats',
                description: 'stats',
                player: {
                    name: '',
                    dateOfBirth: '',
                    nationality: '',
                    team: '',
                    role: '',
                    battingStyle: '',
                    photo: '',
                    stats: [
                        { format: '', matches: '', runs: '', average: '', strikeRate: '' }
                    ]
                }
            },
            {
                id: 'player-comparison',
                title: 'Player Comparison',
                description: 'Compare up to two players.',
                comparison: {
                    players: []
                }
            },
            {
                id: 'team-comparison',
                title: 'Team Comparison',
                description: 'Compare up to two teams.',
                comparison: {
                    teams: []
                }
            },
            {
                id: 'team',
                title: 'Team Overview',
                description: 'team',
                team: {
                    name: '',
                    country: '',
                    captain: '',
                    homeGround: '',
                    players: []
                }
            },
            {
                id: 'competition',
                title: 'Competition Overview',
                description: 'competition',
                competition: {
                    name: '',
                    format: '',
                    currentSeason: '',
                    seasons: []
                }
            },
            {
                id: 'season-overview',
                title: 'Season Overview',
                description: 'season',
                season: {
                    name: '',
                    start: '',
                    end: '',
                    matches: []
                }
            },
            {
                id: 'match-summary',
                title: 'Match Summary',
                description: 'match',
                match: {
                    name: '',
                    season: '',
                    date: '',
                    venue: '',
                    teamA: '',
                    teamB: '',
                    winningTeam: '',
                    winType: '',
                    winMargin: '',
                    winMethod: '',
                    matchType: '',
                    tossWinner: '',
                    tossDecision: ''
                }
            },
            {
                id: 'bookmarks',
                title: 'Bookmarks',
                description: 'bookmarks',
                bookmarks: {
                    players: [],
                    comparisons: [],
                    teamComparisons: [],
                    teams: [],
                    competitions: [],
                    seasons: [],
                    matches: []
                }
            },
            {
                id: 'import-export',
                title: 'Import & Export',
                description: 'Import or export data.'
            }
        ];

        const STORAGE_KEY = 'cricket-explorer-active';
        const ELEMENTS_STORAGE_KEY = 'cricket-explorer-data';
        const BOOKMARKS_STORAGE_KEY = 'cricket-explorer-bookmarks';
        let active = new Set();
        
        // Load from localStorage or use defaults
        function loadActiveElements() {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                try {
                    active = new Set(JSON.parse(saved));
                } catch (e) {
                    active = new Set(elements.map(element => element.id));
                }
            } else {
                active = new Set(elements.map(element => element.id));
            }
        }
        
        // Save active state to localStorage
        function saveActiveElements() {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from(active)));
        }

        function loadElementData() {
            const saved = localStorage.getItem(ELEMENTS_STORAGE_KEY);
            if (!saved) {
                return;
            }
            try {
                const savedElements = JSON.parse(saved);
                elements.forEach(element => {
                    const savedElement = savedElements[element.id];
                    if (savedElement && savedElement.player) {
                        element.player = savedElement.player;
                    } else if (savedElement && savedElement.comparison) {
                        element.comparison = savedElement.comparison;
                    } else if (savedElement && savedElement.team) {
                        element.team = savedElement.team;
                    } else if (savedElement && savedElement.competition) {
                        element.competition = savedElement.competition;
                    } else if (savedElement && savedElement.season) {
                        element.season = savedElement.season;
                    } else if (savedElement && savedElement.match) {
                        element.match = savedElement.match;
                    }
                });
            } catch (error) {
                localStorage.removeItem(ELEMENTS_STORAGE_KEY);
            }
        }

        function saveElementData() {
            const savedElements = {};
            elements.forEach(element => {
                ['player', 'comparison', 'team', 'competition', 'season', 'match'].forEach(property => {
                    if (element[property]) {
                        savedElements[element.id] = { [property]: element[property] };
                    }
                });
            });
            localStorage.setItem(ELEMENTS_STORAGE_KEY, JSON.stringify(savedElements));
        }

        function loadBookmarks() {
            const saved = localStorage.getItem(BOOKMARKS_STORAGE_KEY);
            if (!saved) {
                return;
            }
            try {
                const savedBookmarks = JSON.parse(saved);
                Object.keys(elements.find(element => element.id === 'bookmarks').bookmarks).forEach(type => {
                    if (Array.isArray(savedBookmarks[type])) {
                        elements.find(element => element.id === 'bookmarks').bookmarks[type] = savedBookmarks[type];
                    }
                });
            } catch (error) {
                localStorage.removeItem(BOOKMARKS_STORAGE_KEY);
            }
        }

        function saveBookmarks() {
            const bookmarkElement = elements.find(element => element.id === 'bookmarks');
            localStorage.setItem(BOOKMARKS_STORAGE_KEY, JSON.stringify(bookmarkElement.bookmarks));
        }
        
        loadActiveElements();
        loadElementData();
        loadBookmarks();
        const controls = document.getElementById('controls');
        const stack = document.getElementById('stack');

        function renderControls() {
            controls.innerHTML = '';
            elements.forEach(element => {
                const item = document.createElement('div');
                item.className = 'item-toggle' + (active.has(element.id) ? ' active' : '');
                item.dataset.id = element.id;
                item.innerHTML = '<span>' + element.title + '</span>';
                item.addEventListener('click', () => {
                    if (active.has(element.id)) {
                        active.delete(element.id);
                    } else {
                        active.add(element.id);
                    }
                    saveActiveElements();
                    render();
                });
                controls.appendChild(item);
            });
        }

        function renderElementContent(element) {
            if (element.type === 'search') {
                const filters = element.filters
                    .map(filter => '<label><input type="checkbox" value="' + filter + '" checked /> ' + filter + '</label>')
                    .join('');
                return '<div class="search-box"><input type="text" placeholder="' + element.placeholder + '" /><button>Search</button></div><div class="search-filters">' + filters + '</div><div class="search-results">No search performed. Enter a query to see results.</div>';
            }
            if (element.id === 'player-stats' && element.player) {
                const player = element.player;
                const comparison = player.comparison;
                const formatRows = player.stats.map(stat => {
                    const format = stat.format || 'Batting';
                    return [
                        '<tr><th rowspan="4">' + format + '</th><th>Matches</th><td>' + stat.matches + '</td></tr>',
                        '<tr><th>Runs</th><td>' + stat.runs + '</td></tr>',
                        '<tr><th>Average</th><td>' + stat.average + '</td></tr>',
                        '<tr><th>Strike rate</th><td>' + stat.strikeRate + '</td></tr>'
                    ].join('');
                }).join('');
                const comparisonRows = comparison ? [
                    ['Fielding', 'Catches taken', comparison.catches],
                    ['', 'Stumpings', comparison.stumpings],
                    ['', 'Run outs', comparison.runOuts],
                    ['Bowling', 'Total wickets', comparison.wickets],
                    ['', 'Bowling average', comparison.bowlingAverage],
                    ['', 'Maidens', comparison.maidens],
                    ['', 'Best figures', comparison.bestBowling],
                    ['Batting', 'Total runs', comparison.runs],
                    ['', 'Batting average', comparison.battingAverage],
                    ['', 'Strike rate', comparison.strikeRate],
                    ['', 'Best figures', comparison.bestBatting]
                ].map(row => '<tr>' + (row[0] ? '<th class="comparison-category" rowspan="' + (row[0] === 'Fielding' ? 3 : 4) + '">' + row[0] + '</th>' : '') + '<th>' + row[1] + '</th><td>' + row[2] + '</td></tr>').join('') : '';
                const photo = player.photo
                    ? '<div class="player-photo"><img src="' + player.photo + '" alt="Player photo" /></div>'
                    : '<div class="player-photo">Photo</div>';
                return '<div class="player-stats-card">' +
                    photo +
                    '<div class="player-stats-content">' +
                        '<div class="player-stats-info">' +
                            '<p><strong>Name:</strong> ' + player.name + '</p>' +
                            '<p><strong>Date of Birth:</strong> ' + player.dateOfBirth + '</p>' +
                            '<p><strong>Nationality:</strong> ' + player.nationality + '</p>' +
                            '<p><strong>Team:</strong> ' + (player.team ? linkMarkup('Teams', player.team) : '') + '</p>' +
                            '<p><strong>Role:</strong> ' + player.role + '</p>' +
                            '<p><strong>Batting Style:</strong> ' + player.battingStyle + '</p>' +
                        '</div>' +
                        '<table class="player-stats-table">' +
                            '<thead><tr><th>Category</th><th>Statistic</th><th>Value</th></tr></thead>' +
                            '<tbody>' + formatRows + comparisonRows + '</tbody>' +
                        '</table>' +
                    '</div>' +
                '</div>';
            }
            if (element.id === 'player-comparison' && element.comparison) {
                const slots = [0, 1];
                const selectedPlayers = element.comparison.players || [];
                const selectors = slots.map(slot =>
                    '<div class="comparison-selector"><label for="comparison-player-' + slot + '">Player ' + (slot + 1) + '</label>' +
                    '<input id="comparison-player-filter-' + slot + '" class="comparison-player-filter" data-slot="' + slot + '" type="search" placeholder="Filter players..." />' +
                    '<select id="comparison-player-' + slot + '" class="comparison-player-select" data-slot="' + slot + '" size="6"><option value="">Choose a player...</option></select></div>'
                ).join('');
                const players = selectedPlayers.filter(Boolean);
                const selectorMarkup = '<div class="comparison-selectors">' + selectors + '</div>';
                if (!players.length) {
                    return selectorMarkup + '<p>Select two players to compare their statistics.</p>';
                }
                const playerHeaders = players.map((player, index) =>
                    '<th>' + escapeHtml(player.name || 'Player ' + (index + 1)) + '</th>'
                ).join('');
                const statsFor = player => (player.stats && player.stats.length)
                    ? player.stats
                    : [{ format: 'All formats', matches: player.matches, runs: player.runs, average: player.battingAverage, strikeRate: player.strikeRate }];
                const formatNames = [];
                players.forEach(player => statsFor(player).forEach(stat => {
                    const format = stat.format || 'All formats';
                    if (formatNames.indexOf(format) === -1) {
                        formatNames.push(format);
                    }
                }));
                const formatStat = (player, format) => statsFor(player).find(stat => (stat.format || 'All formats') === format) || {};
                const formatRows = formatNames.map(format => {
                    const metrics = [
                        ['Matches', 'matches', 'higher'],
                        ['Runs', 'runs', 'higher'],
                        ['Average', 'average', 'higher'],
                        ['Strike rate', 'strikeRate', 'higher']
                    ];
                    return metrics.map((metric, index) => {
                        const values = players.map(player => Number(formatStat(player, format)[metric[1]]));
                        const cells = players.map(player => {
                            const raw = formatStat(player, format)[metric[1]];
                            const numeric = Number(raw);
                            const cls = Number.isFinite(numeric) ? comparisonClassForValues(numeric, metric[2], values) : '';
                            return '<td class="' + cls + '">' + escapeHtml(raw === undefined || raw === null ? '' : String(raw)) + '</td>';
                        }).join('');
                        return '<tr>' +
                            (index === 0 ? '<th class="comparison-category" rowspan="' + metrics.length + '">' + escapeHtml(format) + '</th>' : '') +
                            '<th>' + metric[0] + '</th>' + cells + '</tr>';
                    }).join('');
                }).join('');
                const valueCells = (metric, direction) => players.map(player => '<td class="' + comparisonClass(player, metric, direction, players) + '">' + escapeHtml(String(player[metric])) + '</td>').join('');
                const rows = formatRows + [
                    ['Fielding', 'Catches taken', 'catches', 'higher'],
                    ['', 'Stumpings', 'stumpings', 'higher'],
                    ['', 'Run outs', 'runOuts', 'higher'],
                    ['Bowling', 'Total wickets', 'wickets', 'higher'],
                    ['', 'Bowling average', 'bowlingAverage', 'lower'],
                    ['', 'Maidens', 'maidens', 'higher'],
                    ['', 'Best figures', 'bestBowling', 'higher'],
                    ['Batting', 'Total runs', 'runs', 'higher'],
                    ['', 'Batting average', 'battingAverage', 'higher'],
                    ['', 'Strike rate', 'strikeRate', 'higher'],
                    ['', 'Best figures', 'bestBatting', 'higher']
                ].map(row => '<tr>' + (row[0] ? '<th class="comparison-category" rowspan="' + (row[0] === 'Fielding' ? 3 : 4) + '">' + row[0] + '</th>' : '') + '<th>' + row[1] + '</th>' + valueCells(row[2], row[3]) + '</tr>').join('');
                return selectorMarkup + '<table class="comparison-table"><thead><tr><th>Category</th><th>Statistic</th>' + playerHeaders + '</tr></thead><tbody>' + rows + '</tbody></table>';
            }
            if (element.id === 'team' && element.team) {
                const team = element.team;
                const players = team.players && team.players.length
                    ? '<ul>' + team.players.map(player => '<li>' + linkMarkup('Players', player) + '</li>').join('') + '</ul>'
                    : '<p>No players available for this team.</p>';
                const teamMetrics = team.metrics ? '<table class="comparison-table"><thead><tr><th>Statistic</th><th>Value</th></tr></thead><tbody>' +
                    [['Total matches', team.metrics.matches], ['Wins', team.metrics.wins], ['Losses', team.metrics.losses], ['Draws', team.metrics.draws], ['Win percentage', team.metrics.winPercentage + '%']].map(row => '<tr><th>' + row[0] + '</th><td>' + row[1] + '</td></tr>').join('') +
                    '</tbody></table>' : '';
                return '<div>' +
                    '<p><strong>Team Name:</strong> ' + team.name + '</p>' +
                    '<p><strong>Country:</strong> ' + team.country + '</p>' +
                    '<p><strong>Captain:</strong> ' + team.captain + '</p>' +
                    '<p><strong>Home Ground:</strong> ' + team.homeGround + '</p>' +
                    '<h4>Team Record</h4>' + teamMetrics +
                    '<h4>Players</h4>' +
                    players +
                    '</div>';
            }
            if (element.id === 'team-comparison' && element.comparison) {
                const selectedTeams = element.comparison.teams || [];
                const selectors = [0, 1].map(slot =>
                    '<div class="comparison-selector"><label for="comparison-team-' + slot + '">Team ' + (slot + 1) + '</label>' +
                    '<input id="comparison-team-filter-' + slot + '" class="team-comparison-filter" data-slot="' + slot + '" type="search" placeholder="Filter teams..." />' +
                    '<select id="comparison-team-' + slot + '" class="team-comparison-select" data-slot="' + slot + '" size="6"><option value="">Choose a team...</option></select></div>'
                ).join('');
                const selectorMarkup = '<div class="comparison-selectors">' + selectors + '</div>';
                const teams = selectedTeams.filter(Boolean);
                if (!teams.length) {
                    return selectorMarkup + '<p>Select two teams to compare their records.</p>';
                }
                const headers = teams.map(team => '<th>' + escapeHtml(team.name) + '</th>').join('');
                const cells = metric => teams.map(team => '<td class="' + comparisonClass(team, metric, 'higher', teams) + '">' + escapeHtml(String(team[metric])) + '</td>').join('');
                const rows = [
                    ['Total matches', 'matches'],
                    ['Wins', 'wins'],
                    ['Losses', 'losses'],
                    ['Draws', 'draws'],
                    ['Win percentage', 'winPercentage']
                ].map(row => '<tr><th>' + row[0] + '</th>' + cells(row[1]) + '</tr>').join('');
                return selectorMarkup + '<table class="comparison-table"><thead><tr><th>Statistic</th>' + headers + '</tr></thead><tbody>' + rows + '</tbody></table>';
            }
            if (element.id === 'competition' && element.competition) {
                const competition = element.competition;
                const seasons = competition.seasons && competition.seasons.length
                    ? '<ul>' + competition.seasons.map(season => '<li>' + linkMarkup('Seasons', season) + '</li>').join('') + '</ul>'
                    : '<p>No seasons available for this competition.</p>';
                return '<div>' +
                    '<p><strong>Competition Name:</strong> ' + competition.name + '</p>' +
                    '<p><strong>Format:</strong> ' + competition.format + '</p>' +
                    '<p><strong>Current Season:</strong> ' + competition.currentSeason + '</p>' +
                    '<h4>Seasons</h4>' +
                    seasons +
                    '</div>';
            }
            if (element.id === 'season-overview' && element.season) {
                const season = element.season;
                const matches = season.matches && season.matches.length
                    ? '<ul>' + season.matches.map(match => '<li>' + linkMarkup('Matches', match, match.split(' - ').pop()) + '</li>').join('') + '</ul>'
                    : '<p>No matches available for this season.</p>';
                return '<div>' +
                    '<p><strong>Season Name:</strong> ' + season.name + '</p>' +
                    '<p><strong>Start Date:</strong> ' + season.start + '</p>' +
                    '<p><strong>End Date:</strong> ' + season.end + '</p>' +
                    '<h4>Matches</h4>' +
                    matches +
                    '</div>';
            }
            if (element.id === 'match-summary' && element.match) {
                const match = element.match;
                return '<div>' +
                    '<p><strong>Match Name:</strong> ' + match.name + '</p>' +
                    '<p><strong>Season:</strong> ' + linkMarkup('Seasons', match.season) + '</p>' +
                    '<p><strong>Date:</strong> ' + match.date + '</p>' +
                    '<p><strong>Venue:</strong> ' + match.venue + '</p>' +
                    '<p><strong>Teams:</strong> ' + linkMarkup('Teams', match.teamA) + ' vs ' + linkMarkup('Teams', match.teamB) + '</p>' +
                    '<p><strong>Winning Team:</strong> ' + linkMarkup('Teams', match.winningTeam) + '</p>' +
                    '<p><strong>Win Type:</strong> ' + match.winType + '</p>' +
                    '<p><strong>Win Margin:</strong> ' + match.winMargin + '</p>' +
                    '<p><strong>Win Method:</strong> ' + match.winMethod + '</p>' +
                    '<p><strong>Match Type:</strong> ' + match.matchType + '</p>' +
                    '<p><strong>Toss Winner:</strong> ' + linkMarkup('Teams', match.tossWinner) + '</p>' +
                    '<p><strong>Toss Decision:</strong> ' + match.tossDecision + '</p>' +
                    '</div>';
            }
            if (element.id === 'import-export') {
                return '<div>' +
                    '<label for="import-file"><strong>Import file</strong></label>' +
                    '<input id="import-file" class="import-file" type="file" accept=".json" style="display:block; margin: 10px 0;" />' +
                    '<p><strong>Export data</strong></p>' +
                    '<button type="button" class="export-button" style="padding: 8px 14px; border: 1px solid #999; background: #f0f0f0; cursor: pointer; border-radius: 4px;">Export</button>' +
                    '<p class="import-status" aria-live="polite"></p>' +
                    '</div>';
            }
            if (element.id === 'bookmarks' && element.bookmarks) {
                const bookmarks = element.bookmarks;
                const players = bookmarks.players && bookmarks.players.length
                    ? '<ul>' + bookmarks.players.map(item => bookmarkListItem('Players', item)).join('') + '</ul>'
                    : '<p>No players bookmarked.</p>';
                const teams = bookmarks.teams && bookmarks.teams.length
                    ? '<ul>' + bookmarks.teams.map(item => bookmarkListItem('Teams', item)).join('') + '</ul>'
                    : '<p>No teams bookmarked.</p>';
                const competitions = bookmarks.competitions && bookmarks.competitions.length
                    ? '<ul>' + bookmarks.competitions.map(item => bookmarkListItem('Competitions', item)).join('') + '</ul>'
                    : '<p>No competitions bookmarked.</p>';
                const seasons = bookmarks.seasons && bookmarks.seasons.length
                    ? '<ul>' + bookmarks.seasons.map(item => bookmarkListItem('Seasons', item)).join('') + '</ul>'
                    : '<p>No seasons bookmarked.</p>';
                const matches = bookmarks.matches && bookmarks.matches.length
                    ? '<ul>' + bookmarks.matches.map(item => bookmarkListItem('Matches', item)).join('') + '</ul>'
                    : '<p>No matches bookmarked.</p>';
                const comparisons = bookmarks.comparisons && bookmarks.comparisons.length
                    ? '<ul>' + bookmarks.comparisons.map(item => bookmarkListItem('Player Comparisons', item)).join('') + '</ul>'
                    : '<p>No player comparisons bookmarked.</p>';
                const teamComparisons = bookmarks.teamComparisons && bookmarks.teamComparisons.length
                    ? '<ul>' + bookmarks.teamComparisons.map(item => bookmarkListItem('Team Comparisons', item)).join('') + '</ul>'
                    : '<p>No team comparisons bookmarked.</p>';
                return '<div>' +
                    '<h4>Players</h4>' + players +
                    '<h4>Player Comparisons</h4>' + comparisons +
                    '<h4>Teams</h4>' + teams +
                    '<h4>Team Comparisons</h4>' + teamComparisons +
                    '<h4>Competitions</h4>' + competitions +
                    '<h4>Seasons</h4>' + seasons +
                    '<h4>Matches</h4>' + matches +
                    '</div>';
            }
            
        }

        function renderStack() {
            stack.innerHTML = '';
            const visible = elements.filter(element => active.has(element.id));
            const bookmarkable = new Set(['player-stats', 'player-comparison', 'team', 'team-comparison', 'competition', 'season-overview', 'match-summary']);
            visible.forEach(element => {
                const card = document.createElement('div');
                card.className = 'stack-item';
                card.dataset.elementId = element.id;
                const header = '<div class="card-header"><h3>' + element.title + '</h3>' +
                    (bookmarkable.has(element.id) ? '<button class="bookmark-btn">★ Bookmark</button>' : '') +
                    '</div>';
                card.innerHTML = header + renderElementContent(element);
                stack.appendChild(card);
                if (element.type === 'search') {
                    bindSearch(card);
                }
                if (bookmarkable.has(element.id)) {
                    bindBookmarkButton(card, element);
                }
                if (element.id === 'bookmarks') {
                    bindRemoveBookmarkButtons(card);
                    bindLoadBookmarkButtons(card);
                }
                if (element.id === 'import-export') {
                    bindImportExport(card);
                }
                if (element.id === 'player-comparison') {
                    bindComparisonSelectors(card);
                }
                if (element.id === 'team-comparison') {
                    bindTeamComparisonSelectors(card);
                }
                bindElementLinks(card);
            });
            if (visible.length === 0) {
                stack.innerHTML = '<p>No elements selected. Toggle items from the sidebar.</p>';
            }
        }

        function bindBookmarkButton(card, element) {
            const button = card.querySelector('.bookmark-btn');
            button.addEventListener('click', () => {
                const bookmark = getBookmark(element);
                if (!bookmark) {
                    return;
                }
                const bookmarkElement = elements.find(item => item.id === 'bookmarks');
                const bookmarks = bookmarkElement.bookmarks[bookmark.type];
                if (!bookmarks.includes(bookmark.value)) {
                    bookmarks.push(bookmark.value);
                    saveBookmarks();
                }
                active.add('bookmarks');
                saveActiveElements();
                render();
                const bookmarksCard = stack.querySelector('[data-element-id="bookmarks"]');
                if (bookmarksCard) {
                    bookmarksCard.classList.add('added-result');
                    bookmarksCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    setTimeout(() => bookmarksCard.classList.remove('added-result'), 1800);
                }
            });
        }

        function getBookmark(element) {
            let bookmark;
            if (element.id === 'player-stats') {
                bookmark = { type: 'players', value: element.player.name };
            } else if (element.id === 'team') {
                bookmark = { type: 'teams', value: element.team.name };
            } else if (element.id === 'competition') {
                bookmark = { type: 'competitions', value: element.competition.name };
            } else if (element.id === 'season-overview') {
                bookmark = { type: 'seasons', value: element.season.name };
            } else if (element.id === 'match-summary') {
                bookmark = { type: 'matches', value: element.match.name };
            } else if (element.id === 'player-comparison') {
                const players = (element.comparison.players || []).filter(Boolean);
                if (players.length === 2) {
                    bookmark = { type: 'comparisons', value: JSON.stringify(players) };
                }
            } else if (element.id === 'team-comparison') {
                const teams = (element.comparison.teams || []).filter(Boolean);
                if (teams.length === 2) {
                    bookmark = { type: 'teamComparisons', value: JSON.stringify(teams) };
                }
            }
            return bookmark && bookmark.value ? bookmark : null;
        }

        function bookmarkListItem(type, value) {
            let label = value;
            let loadButton = '';
            let content = linkMarkup(type, value);
            if (type === 'Player Comparisons') {
                try {
                    const players = JSON.parse(value);
                    label = players.map(player => player.name).join(' vs ');
                    content = '<a href="#" class="bookmark-load" data-bookmark-value="' + escapeHtml(value) + '">' + escapeHtml(label) + '</a>';
                } catch (error) {
                    label = 'Invalid comparison';
                    content = escapeHtml(label);
                }
            } else if (type === 'Team Comparisons') {
                try {
                    const teams = JSON.parse(value);
                    label = teams.map(team => team.name).join(' vs ');
                    content = '<a href="#" class="team-bookmark-load" data-bookmark-value="' + escapeHtml(value) + '">' + escapeHtml(label) + '</a>';
                } catch (error) {
                    content = escapeHtml('Invalid team comparison');
                }
            }
            const bookmarkType = type === 'Team Comparisons' ? 'teamComparisons' : type === 'Player Comparisons' ? 'comparisons' : type.toLowerCase();
            return '<li class="bookmark-list-item">' + content + loadButton +
                '<button type="button" class="bookmark-remove" data-bookmark-type="' + escapeHtml(bookmarkType) + '" data-bookmark-value="' + escapeHtml(value) + '">Remove</button></li>';
        }

        function bindRemoveBookmarkButtons(card) {
            card.querySelectorAll('.bookmark-remove').forEach(button => {
                button.addEventListener('click', () => {
                    const bookmarkElement = elements.find(element => element.id === 'bookmarks');
                    const type = button.dataset.bookmarkType;
                    bookmarkElement.bookmarks[type] = bookmarkElement.bookmarks[type].filter(value => value !== button.dataset.bookmarkValue);
                    saveBookmarks();
                    render();
                });
            });
        }

        function bindLoadBookmarkButtons(card) {
            card.querySelectorAll('.bookmark-load').forEach(button => {
                button.addEventListener('click', event => {
                    event.preventDefault();
                    try {
                        const comparison = elements.find(element => element.id === 'player-comparison');
                        comparison.comparison.players = JSON.parse(button.dataset.bookmarkValue);
                        active.add('player-comparison');
                        saveElementData();
                        saveActiveElements();
                        render();
                        const comparisonCard = stack.querySelector('[data-element-id="player-comparison"]');
                        if (comparisonCard) {
                            comparisonCard.classList.add('added-result');
                            comparisonCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            setTimeout(() => comparisonCard.classList.remove('added-result'), 1800);
                        }
                    } catch (error) {
                        button.disabled = true;
                    }
                });
            });
            card.querySelectorAll('.team-bookmark-load').forEach(button => {
                button.addEventListener('click', event => {
                    event.preventDefault();
                    try {
                        const comparison = elements.find(element => element.id === 'team-comparison');
                        comparison.comparison.teams = JSON.parse(button.dataset.bookmarkValue);
                        active.add('team-comparison');
                        saveElementData();
                        saveActiveElements();
                        render();
                        const comparisonCard = stack.querySelector('[data-element-id="team-comparison"]');
                        if (comparisonCard) {
                            comparisonCard.classList.add('added-result');
                            comparisonCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            setTimeout(() => comparisonCard.classList.remove('added-result'), 1800);
                        }
                    } catch (error) {
                        button.removeAttribute('href');
                    }
                });
            });
        }

        function bindImportExport(card) {
            const fileInput = card.querySelector('.import-file');
            const exportButton = card.querySelector('.export-button');
            const status = card.querySelector('.import-status');

            exportButton.addEventListener('click', () => {
                const exportData = {
                    version: 1,
                    active: Array.from(active),
                    elements: getElementData(),
                    bookmarks: elements.find(element => element.id === 'bookmarks').bookmarks
                };
                const file = new Blob([JSON.stringify(exportData, null, 2)], { type: 'application/json' });
                const download = document.createElement('a');
                download.href = URL.createObjectURL(file);
                download.download = 'cricket-explorer-data.json';
                download.click();
                URL.revokeObjectURL(download.href);
                status.textContent = 'Data exported.';
            });

            fileInput.addEventListener('change', async () => {
                const file = fileInput.files[0];
                if (!file) {
                    return;
                }
                try {
                    const imported = JSON.parse(await file.text());
                    if (!imported || typeof imported !== 'object' || !Array.isArray(imported.active) || !imported.elements || !imported.bookmarks) {
                        throw new Error('Invalid export file.');
                    }
                    active = new Set(imported.active.filter(id => elements.some(element => element.id === id)));
                    applyElementData(imported.elements);
                    const bookmarkElement = elements.find(element => element.id === 'bookmarks');
                    Object.keys(bookmarkElement.bookmarks).forEach(type => {
                        bookmarkElement.bookmarks[type] = Array.isArray(imported.bookmarks[type])
                            ? imported.bookmarks[type].filter(value => typeof value === 'string')
                            : [];
                    });
                    saveElementData();
                    saveBookmarks();
                    saveActiveElements();
                    render();
                    status.textContent = 'Data imported.';
                } catch (error) {
                    status.textContent = error.message;
                } finally {
                    fileInput.value = '';
                }
            });
        }

        function getElementData() {
            const savedElements = {};
            elements.forEach(element => {
                    ['player', 'comparison', 'team', 'competition', 'season', 'match'].forEach(property => {
                    if (element[property]) {
                        savedElements[element.id] = { [property]: element[property] };
                    }
                });
            });
            return savedElements;
        }

        function applyElementData(savedElements) {
            elements.forEach(element => {
                const savedElement = savedElements[element.id];
                if (!savedElement || typeof savedElement !== 'object') {
                    return;
                }
                ['player', 'team', 'competition', 'season', 'match'].forEach(property => {
                    if (savedElement[property] && typeof savedElement[property] === 'object') {
                        element[property] = savedElement[property];
                    }
                });
            });
        }

        function bindSearch(card) {
            const input = card.querySelector('.search-box input');
            const button = card.querySelector('.search-box button');
            const results = card.querySelector('.search-results');
            const filters = card.querySelectorAll('.search-filters input');

            async function search() {
                const query = input.value.trim();
                const selectedFilters = Array.from(filters)
                    .filter(filter => filter.checked)
                    .map(filter => filter.value);
                if (!query) {
                    results.textContent = 'Enter a search term.';
                    return;
                }
                if (!selectedFilters.length) {
                    results.textContent = 'Select at least one result type.';
                    return;
                }
                results.textContent = 'Searching...';
                try {
                    const response = await fetch('application.php?action=search&q=' + encodeURIComponent(query) + '&types=' + encodeURIComponent(selectedFilters.join(',')));
                    const data = await response.json();
                    if (!response.ok || data.error) {
                        throw new Error(data.error || 'Search failed.');
                    }
                    results.innerHTML = data.results.length
                        ? data.results.map((result, index) => '<div class="search-result-row">' +
                            '<div class="search-result-details"><strong>' + escapeHtml(result.type) + ':</strong> ' + escapeHtml(result.title) + '<br><small>' + escapeHtml(result.subtitle || '') + '</small></div>' +
                            '<button type="button" class="search-result-add" data-result-index="' + index + '">Add</button>' +
                            '</div>').join('')
                        : '<p>No results found.</p>';
                    results.querySelectorAll('.search-result-add').forEach(addButton => {
                        addButton.addEventListener('click', () => {
                            const result = data.results[Number(addButton.dataset.resultIndex)];
                            addResultToElement(result);
                            addButton.textContent = 'Added';
                            addButton.disabled = true;
                        });
                    });
                } catch (error) {
                    results.textContent = error.message;
                }
            }

            button.addEventListener('click', search);
            input.addEventListener('keydown', event => {
                if (event.key === 'Enter') {
                    search();
                }
            });
        }

        function addResultToElement(result) {
            const destinationByType = {
                Players: 'player-stats',
                Teams: 'team',
                Competitions: 'competition',
                Seasons: 'season-overview',
                Matches: 'match-summary'
            };
            const destinationId = destinationByType[result.type];
            const destination = elements.find(element => element.id === destinationId);
            if (!destination) {
                return;
            }

            if (result.type === 'Players') {
                const playerData = playerDataFromResult(result);
                destination.player.name = result.title;
                destination.player.dateOfBirth = result.details.birthDate || '';
                destination.player.nationality = result.details.nationality || '';
                destination.player.team = result.details.team || '';
                destination.player.comparison = playerData;
                destination.player.stats = playerData.stats;
            } else if (result.type === 'Teams') {
                destination.team.name = result.title;
                destination.team.homeGround = result.details.homeGround || '';
                destination.team.players = splitRelatedValues(result.details.players);
                destination.team.metrics = teamDataFromResult(result);
            } else if (result.type === 'Competitions') {
                destination.competition.name = result.title;
                destination.competition.format = result.details.format || '';
                destination.competition.currentSeason = result.details.currentSeason || '';
                destination.competition.seasons = splitRelatedValues(result.details.seasons);
            } else if (result.type === 'Seasons') {
                destination.season.name = result.title;
                destination.season.start = result.details.start || '';
                destination.season.end = result.details.end || '';
                destination.season.matches = splitRelatedValues(result.details.matches);
            } else if (result.type === 'Matches') {
                destination.match.name = result.title;
                destination.match.season = result.details.season || '';
                destination.match.date = result.details.date || '';
                destination.match.venue = result.details.venue || '';
                destination.match.teamA = result.details.teamA || '';
                destination.match.teamB = result.details.teamB || '';
                destination.match.winningTeam = result.details.winningTeam || '';
                destination.match.winType = result.details.winType || '';
                destination.match.winMargin = result.details.winMargin ?? '';
                destination.match.winMethod = result.details.winMethod || '';
                destination.match.matchType = result.details.matchType || '';
                destination.match.tossWinner = result.details.tossWinner || '';
                destination.match.tossDecision = result.details.tossDecision || '';
            }

            active.add(destinationId);
            saveElementData();
            saveActiveElements();
            render();
            const destinationCard = stack.querySelector('[data-element-id="' + destinationId + '"]');
            if (destinationCard) {
                destinationCard.classList.add('added-result');
                destinationCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                setTimeout(() => destinationCard.classList.remove('added-result'), 1800);
            }
        }

        function splitRelatedValues(value) {
            return value ? String(value).split('||').filter(Boolean) : [];
        }

        // A batting average is runs per DISMISSAL, not runs per match - an unbeaten
        // innings does not end one. With no dismissal at all the average is
        // undefined rather than zero, which a scorecard shows as a dash.
        function battingAverageOf(runs, outs, innings) {
            if (outs > 0) {
                return (runs / outs).toFixed(2);
            }
            return innings > 0 ? '-' : '0.00';
        }

        function strikeRateOf(runs, ballsFaced) {
            return ballsFaced ? ((runs / ballsFaced) * 100).toFixed(2) : '0.00';
        }

        function playerDataFromResult(result) {
            const details = result.details || {};
            const matches = Number(details.matches || 0);
            const runs = Number(details.runs || 0);
            const ballsFaced = Number(details.ballsFaced || 0);
            const wickets = Number(details.wickets || 0);
            const runsConceded = Number(details.runsConceded || 0);
            const outs = Number(details.outs || 0);
            const battingAverage = battingAverageOf(runs, outs, matches);
            const strikeRate = strikeRateOf(runs, ballsFaced);
            // One row per format the player has actually appeared in, most-played
            // first. The career line stays on top so the card still reads as a
            // summary rather than a list.
            const formatRows = (Array.isArray(details.formats) ? details.formats : [])
                .map(entry => {
                    const formatMatches = Number(entry.matches || 0);
                    const formatRuns = Number(entry.runs || 0);
                    const formatBalls = Number(entry.ballsFaced || 0);
                    const formatOuts = Number(entry.outs || 0);
                    return {
                        format: entry.format || 'Unknown',
                        matches: formatMatches,
                        runs: formatRuns,
                        average: battingAverageOf(formatRuns, formatOuts, formatMatches),
                        strikeRate: strikeRateOf(formatRuns, formatBalls)
                    };
                })
                .sort((a, b) => b.matches - a.matches || a.format.localeCompare(b.format));
            const careerRow = {
                format: 'All formats',
                matches: matches,
                runs: runs,
                average: battingAverage,
                strikeRate: strikeRate
            };
            // A single format would just repeat the career line verbatim.
            const stats = formatRows.length > 1 ? [careerRow].concat(formatRows) : [careerRow];
            return {
                id: result.id,
                name: result.title,
                matches: matches,
                runs: runs,
                wickets: wickets,
                catches: Number(details.catches || 0),
                stumpings: Number(details.stumpings || 0),
                runOuts: Number(details.runOuts || 0),
                maidens: Number(details.maidens || 0),
                bestBowling: details.bestBowling || '0/0',
                bestBatting: Number(details.bestBatting || 0),
                battingAverage: battingAverage,
                bowlingAverage: wickets ? (runsConceded / wickets).toFixed(2) : '0.00',
                strikeRate: strikeRate,
                stats: stats
            };
        }

        function teamDataFromResult(result) {
            const details = result.details || {};
            const matches = Number(details.matches || 0);
            const wins = Number(details.wins || 0);
            const draws = Number(details.draws || 0);
            const losses = Number(details.losses || 0);
            return {
                id: result.id,
                name: result.title,
                matches: matches,
                wins: wins,
                losses: losses,
                draws: draws,
                winPercentage: matches ? ((wins / matches) * 100).toFixed(2) : '0.00'
            };
        }


        function comparisonMetricValue(player, metric) {
            if (metric === 'bestBowling') {
                const figures = String(player[metric] || '0/0').split('/').map(Number);
                return (figures[0] || 0) * 100000 - (figures[1] || 0);
            }
            const value = Number(player[metric]);
            return Number.isFinite(value) ? value : null;
        }

        function comparisonClass(player, metric, direction, players) {
            if (players.length < 2) {
                return '';
            }
            const value = comparisonMetricValue(player, metric);
            const values = players.map(item => comparisonMetricValue(item, metric));
            if (value === null || values.some(item => item === null) || values[0] === values[1]) {
                return '';
            }
            const target = direction === 'lower' ? Math.min(...values) : Math.max(...values);
            return value === target ? 'comparison-better' : 'comparison-worse';
        }

        function comparisonClassForValues(value, direction, values) {
            if (values.length < 2) {
                return '';
            }
            if (!Number.isFinite(value) || values.some(item => !Number.isFinite(item)) || values[0] === values[1]) {
                return '';
            }
            const target = direction === 'lower' ? Math.min(...values) : Math.max(...values);
            return value === target ? 'comparison-better' : 'comparison-worse';
        }
        function addResultToComparison(result, slot) {
            const comparison = elements.find(element => element.id === 'player-comparison');
            const players = comparison.comparison.players || [];
            if (result.type !== 'Players' || (players.some((player, index) => player && index !== slot && player.name === result.title))) {
                return;
            }
            comparison.comparison.players = [players[0] || null, players[1] || null];
            comparison.comparison.players[slot] = playerDataFromResult(result);
            active.add('player-comparison');
            saveElementData();
            saveActiveElements();
            render();
        }

        function bindComparisonSelectors(card) {
            const filters = card.querySelectorAll('.comparison-player-filter');
            const selects = card.querySelectorAll('.comparison-player-select');
            fetch('application.php?action=search&q=%25&types=Players')
                .then(response => response.json().then(data => ({ response, data })))
                .then(({ response, data }) => {
                    if (!response.ok || data.error) {
                        throw new Error(data.error || 'Player search failed.');
                    }
                    const players = data.results;
                    const renderOptions = (select, filter) => {
                        const query = filter.value.trim().toLowerCase();
                        const selectedPlayers = elements.find(element => element.id === 'player-comparison').comparison.players || [];
                        const selected = selectedPlayers[Number(select.dataset.slot)];
                        const options = players.filter(player => !query || player.title.toLowerCase().includes(query));
                        select.innerHTML = '<option value="">Choose a player...</option>' + options.map(player =>
                            '<option value="' + escapeHtml(player.id) + '"' + (selected && (selected.id === player.id || selected.name === player.title) ? ' selected' : '') + '>' + escapeHtml(player.title) + '</option>'
                        ).join('');
                    };
                    filters.forEach(filter => {
                        const select = card.querySelector('.comparison-player-select[data-slot="' + filter.dataset.slot + '"]');
                        filter.addEventListener('input', () => renderOptions(select, filter));
                        renderOptions(select, filter);
                    });
                    selects.forEach(select => {
                        select.addEventListener('change', () => {
                            const result = players.find(player => player.id === select.value);
                            if (result) {
                                addResultToComparison(result, Number(select.dataset.slot));
                            }
                        });
                    });
                })
                .catch(() => {
                    filters.forEach(filter => {
                        filter.placeholder = 'Player list unavailable';
                    });
                });
        }

        function addTeamResultToComparison(result, slot) {
            const comparison = elements.find(element => element.id === 'team-comparison');
            const teams = comparison.comparison.teams || [];
            if (result.type !== 'Teams' || teams.some((team, index) => team && index !== slot && team.name === result.title)) {
                return;
            }
            comparison.comparison.teams = [teams[0] || null, teams[1] || null];
            comparison.comparison.teams[slot] = teamDataFromResult(result);
            active.add('team-comparison');
            saveElementData();
            saveActiveElements();
            render();
        }

        function bindTeamComparisonSelectors(card) {
            const filters = card.querySelectorAll('.team-comparison-filter');
            const selects = card.querySelectorAll('.team-comparison-select');
            fetch('application.php?action=search&q=%25&types=Teams')
                .then(response => response.json().then(data => ({ response, data })))
                .then(({ response, data }) => {
                    if (!response.ok || data.error) {
                        throw new Error(data.error || 'Team search failed.');
                    }
                    const teams = data.results;
                    const renderOptions = (select, filter) => {
                        const query = filter.value.trim().toLowerCase();
                        const selectedTeams = elements.find(element => element.id === 'team-comparison').comparison.teams || [];
                        const selected = selectedTeams[Number(select.dataset.slot)];
                        const options = teams.filter(team => !query || team.title.toLowerCase().includes(query));
                        select.innerHTML = '<option value="">Choose a team...</option>' + options.map(team =>
                            '<option value="' + escapeHtml(team.id) + '"' + (selected && (selected.id === team.id || selected.name === team.title) ? ' selected' : '') + '>' + escapeHtml(team.title) + '</option>'
                        ).join('');
                    };
                    filters.forEach(filter => {
                        const select = card.querySelector('.team-comparison-select[data-slot="' + filter.dataset.slot + '"]');
                        filter.addEventListener('input', () => renderOptions(select, filter));
                        renderOptions(select, filter);
                    });
                    selects.forEach(select => {
                        select.addEventListener('change', () => {
                            const result = teams.find(team => team.id === select.value);
                            if (result) {
                                addTeamResultToComparison(result, Number(select.dataset.slot));
                            }
                        });
                    });
                })
                .catch(() => {
                    filters.forEach(filter => {
                        filter.placeholder = 'Team list unavailable';
                    });
                });
        }

        function linkMarkup(type, label, query = label) {
            return '<a href="#" data-search-type="' + escapeHtml(type) + '" data-search-query="' + escapeHtml(query) + '">' + escapeHtml(label) + '</a>';
        }

        function bindElementLinks(card) {
            card.querySelectorAll('[data-search-type]').forEach(link => {
                link.addEventListener('click', async event => {
                    event.preventDefault();
                    const type = link.dataset.searchType;
                    const query = link.dataset.searchQuery;
                    try {
                        const response = await fetch('application.php?action=search&q=' + encodeURIComponent(query) + '&types=' + encodeURIComponent(type));
                        const data = await response.json();
                        if (!response.ok || data.error || !data.results.length) {
                            return;
                        }
                        addResultToElement(data.results[0]);
                    } catch (error) {
                        // Related links should not navigate or display an error.
                    }
                });
            });
        }

        function escapeHtml(value) {
            return String(value).replace(/[&<>"']/g, character => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[character]));
        }

        function render() {
            renderControls();
            renderStack();
        }

        render();
    </script>
</body>
</html>