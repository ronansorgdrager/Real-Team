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
        $connection = @new mysqli('localhost', 'root', '', 'cricket_explorer');
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
        'players' => "SELECT 'Players' AS type, p.player_id AS id, p.player_name AS title, CONCAT('Player ID: ', p.player_id) AS subtitle, JSON_OBJECT('birthDate', p.birth_date, 'nationality', p.nationality, 'team', (SELECT t.team_name FROM PERFORMANCE perf_team INNER JOIN TEAMS t ON t.team_id = perf_team.team_id WHERE perf_team.player_id = p.player_id LIMIT 1), 'matches', COUNT(DISTINCT m.match_id), 'runs', COALESCE(SUM(perf.runs_scored), 0), 'ballsFaced', COALESCE(SUM(perf.balls_faced), 0), 'wickets', COALESCE(SUM(perf.wickets_taken), 0), 'catches', COALESCE(SUM(perf.catches), 0), 'stumpings', COALESCE(SUM(perf.stumpings), 0)) AS details FROM PLAYERS p LEFT JOIN PERFORMANCE perf ON perf.player_id = p.player_id LEFT JOIN INNINGS i ON i.innings_id = perf.innings_id LEFT JOIN MATCHES m ON m.match_id = i.match_id WHERE p.player_name LIKE ? GROUP BY p.player_id, p.player_name, p.birth_date, p.nationality",
        'teams' => "SELECT 'Teams' AS type, t.team_id AS id, t.team_name AS title, COALESCE(t.home_ground, 'No home ground') AS subtitle, JSON_OBJECT('homeGround', t.home_ground, 'players', COALESCE((SELECT GROUP_CONCAT(DISTINCT p.player_name ORDER BY p.player_name SEPARATOR '||') FROM PERFORMANCE perf INNER JOIN PLAYERS p ON p.player_id = perf.player_id WHERE perf.team_id = t.team_id), '')) AS details FROM TEAMS t WHERE CONCAT_WS(' ', t.team_name, t.home_ground) LIKE ?",
        'competitions' => "SELECT 'Competitions' AS type, c.comp_id AS id, c.comp_name AS title, CONCAT('Competition ID: ', c.comp_id) AS subtitle, JSON_OBJECT('seasons', COALESCE((SELECT GROUP_CONCAT(s.season_name ORDER BY s.season_name SEPARATOR '||') FROM SEASONS s WHERE s.comp_id = c.comp_id), '')) AS details FROM COMPETITIONS c WHERE c.comp_name LIKE ?",
        'seasons' => "SELECT 'Seasons' AS type, s.season_id AS id, s.season_name AS title, c.comp_name AS subtitle, JSON_OBJECT('competition', c.comp_name, 'matches', COALESCE((SELECT GROUP_CONCAT(CONCAT(m.match_date, ' - ', t1.team_name, ' vs ', t2.team_name) ORDER BY m.match_date SEPARATOR '||') FROM MATCHES m INNER JOIN TEAMS t1 ON t1.team_id = m.team1_id INNER JOIN TEAMS t2 ON t2.team_id = m.team2_id WHERE m.season_id = s.season_id), '')) AS details FROM SEASONS s INNER JOIN COMPETITIONS c ON c.comp_id = s.comp_id WHERE CONCAT_WS(' ', s.season_name, c.comp_name) LIKE ?",
        'matches' => "SELECT 'Matches' AS type, m.match_id AS id, CONCAT(t1.team_name, ' vs ', t2.team_name) AS title, CONCAT(m.match_date, ' | ', COALESCE(m.venue, 'Venue unknown'), ' | ', COALESCE(s.season_name, 'Season unknown')) AS subtitle, JSON_OBJECT('season', s.season_name, 'date', m.match_date, 'venue', m.venue, 'teamA', t1.team_name, 'teamB', t2.team_name, 'winningTeam', winner.team_name, 'winType', m.win_type, 'winMargin', m.win_margin, 'winMethod', m.win_method, 'matchType', m.match_type, 'tossWinner', toss.team_name, 'tossDecision', m.toss_decision) AS details FROM MATCHES m INNER JOIN TEAMS t1 ON t1.team_id = m.team1_id INNER JOIN TEAMS t2 ON t2.team_id = m.team2_id LEFT JOIN TEAMS winner ON winner.team_id = m.winning_team_id LEFT JOIN TEAMS toss ON toss.team_id = m.toss_winner_id LEFT JOIN SEASONS s ON s.season_id = m.season_id WHERE CONCAT_WS(' ', m.match_id, m.match_date, m.venue, t1.team_name, t2.team_name, s.season_name, CONCAT(t1.team_name, ' vs ', t2.team_name)) LIKE ?"
    ];

    $parts = [];
    $parameters = [];
    foreach ($types as $type) {
        $parts[] = $searchableQueries[$typeMap[$type]];
        $parameters[] = '%' . $query . '%';
    }
    $sql = 'SELECT * FROM (' . implode(' UNION ALL ', $parts) . ') AS search_results ORDER BY title LIMIT 50';
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
                ['player', 'team', 'competition', 'season', 'match'].forEach(property => {
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
                const rows = player.stats.map(stat =>
                    '<tr>' +
                        '<td>' + stat.format + '</td>' +
                        '<td>' + stat.matches + '</td>' +
                        '<td>' + stat.runs + '</td>' +
                        '<td>' + stat.average + '</td>' +
                        '<td>' + stat.strikeRate + '</td>' +
                    '</tr>'
                ).join('');
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
                            '<thead><tr><th>Format</th><th>Matches</th><th>Runs</th><th>Average</th><th>Strike Rate</th></tr></thead>' +
                            '<tbody>' + rows + '</tbody>' +
                        '</table>' +
                    '</div>' +
                '</div>';
            }
            if (element.id === 'team' && element.team) {
                const team = element.team;
                const players = team.players && team.players.length
                    ? '<ul>' + team.players.map(player => '<li>' + linkMarkup('Players', player) + '</li>').join('') + '</ul>'
                    : '<p>No players available for this team.</p>';
                return '<div>' +
                    '<p><strong>Team Name:</strong> ' + team.name + '</p>' +
                    '<p><strong>Country:</strong> ' + team.country + '</p>' +
                    '<p><strong>Captain:</strong> ' + team.captain + '</p>' +
                    '<p><strong>Home Ground:</strong> ' + team.homeGround + '</p>' +
                    '<h4>Players</h4>' +
                    players +
                    '</div>';
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
                return '<div>' +
                    '<h4>Players</h4>' + players +
                    '<h4>Teams</h4>' + teams +
                    '<h4>Competitions</h4>' + competitions +
                    '<h4>Seasons</h4>' + seasons +
                    '<h4>Matches</h4>' + matches +
                    '</div>';
            }
            
        }

        function renderStack() {
            stack.innerHTML = '';
            const visible = elements.filter(element => active.has(element.id));
            const bookmarkable = new Set(['player-stats', 'team', 'competition', 'season-overview', 'match-summary']);
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
                }
                if (element.id === 'import-export') {
                    bindImportExport(card);
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
            }
            return bookmark && bookmark.value ? bookmark : null;
        }

        function bookmarkListItem(type, value) {
            return '<li class="bookmark-list-item">' + linkMarkup(type, value) +
                '<button type="button" class="bookmark-remove" data-bookmark-type="' + escapeHtml(type.toLowerCase()) + '" data-bookmark-value="' + escapeHtml(value) + '">Remove</button></li>';
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
                ['player', 'team', 'competition', 'season', 'match'].forEach(property => {
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
                destination.player.name = result.title;
                destination.player.dateOfBirth = result.details.birthDate || '';
                destination.player.nationality = result.details.nationality || '';
                destination.player.team = result.details.team || '';
                const matches = Number(result.details.matches || 0);
                const runs = Number(result.details.runs || 0);
                const ballsFaced = Number(result.details.ballsFaced || 0);
                destination.player.stats = [{
                    format: 'All formats',
                    matches: matches,
                    runs: runs,
                    average: matches ? (runs / matches).toFixed(2) : '0.00',
                    strikeRate: ballsFaced ? ((runs / ballsFaced) * 100).toFixed(2) : '0.00'
                }];
            } else if (result.type === 'Teams') {
                destination.team.name = result.title;
                destination.team.homeGround = result.details.homeGround || '';
                destination.team.players = splitRelatedValues(result.details.players);
            } else if (result.type === 'Competitions') {
                destination.competition.name = result.title;
                destination.competition.seasons = splitRelatedValues(result.details.seasons);
            } else if (result.type === 'Seasons') {
                destination.season.name = result.title;
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